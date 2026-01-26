<?php
/**
 * JournalHub
 *
 * Copyright (C) 2026  Erwan Setyo Budi (erwans818@gmail.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

declare(strict_types=1);

/**
 * api.php — JSON endpoints untuk Network & Export
 * Fokus: output JSON bersih, error jadi JSON, LIMIT aman (tanpa binding).
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/db.php';

/* ---------------------------
 * Helpers
 * ------------------------- */

function json_out($data, int $code = 200): void {
  while (ob_get_level() > 0) { ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  exit;
}

set_exception_handler(function(Throwable $e){
  json_out([
    'error' => true,
    'message' => $e->getMessage(),
    'type' => get_class($e),
  ], 500);
});

function int_param(string $key, int $default = 0): int {
  if (!isset($_GET[$key])) return $default;
  return (int)$_GET[$key];
}

function str_param(string $key, string $default = ''): string {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function clamp_int(int $v, int $min, int $max): int {
  if ($v < $min) return $min;
  if ($v > $max) return $max;
  return $v;
}

function require_journal_id(int $id): void {
  if ($id <= 0) json_out(['error' => true, 'message' => 'Parameter id wajib dan harus > 0'], 400);
}

/**
 * Untuk LIMIT: jangan bind parameter. Cast int + clamp, lalu langsung concat.
 * Ini aman karena hanya integer.
 */
function limit_sql(int $limit, int $min, int $max): string {
  $limit = clamp_int($limit, $min, $max);
  return (string)$limit;
}

/* ---------------------------
 * Router
 * ------------------------- */

$op = strtolower(str_param('op', ''));
$id = int_param('id', 0);

/**
 * ops yang tidak butuh id bisa ditambah di sini bila diperlukan.
 * Saat ini semua op perlu id.
 */
if ($op !== '') require_journal_id($id);

switch ($op) {

  /* =========================
   * EXPORT
   * ======================= */

  case 'export_csv': {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="oai_records_journal_'.$id.'.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, [
      'oai_identifier','status','datestamp','set_spec','title','pub_date','pub_month','pub_year',
      'url_best','doi_best','publisher_best','language_best',
      'dc_title_json','dc_creator_json','dc_subject_json','dc_description_json','dc_publisher_json','dc_contributor_json',
      'dc_date_json','dc_type_json','dc_format_json','dc_identifier_json','dc_source_json','dc_language_json',
      'dc_relation_json','dc_coverage_json','dc_rights_json'
    ]);

    $st = q("
      SELECT oai_identifier,status,datestamp,set_spec,title,pub_date,pub_month,pub_year,
             url_best,doi_best,publisher_best,language_best,
             dc_title_json,dc_creator_json,dc_subject_json,dc_description_json,dc_publisher_json,dc_contributor_json,
             dc_date_json,dc_type_json,dc_format_json,dc_identifier_json,dc_source_json,dc_language_json,
             dc_relation_json,dc_coverage_json,dc_rights_json
      FROM oai_records
      WHERE journal_id=?
      ORDER BY pub_date IS NULL, pub_date, oai_identifier
    ", [$id]);

    while ($row = $st->fetch()) fputcsv($out, $row);
    fclose($out);
    exit;
  }

  case 'export_json': {
    $j = q("SELECT * FROM journals WHERE id=?", [$id])->fetch();
    $rows = q("
      SELECT * FROM oai_records
      WHERE journal_id=?
      ORDER BY pub_date IS NULL, pub_date, oai_identifier
    ", [$id])->fetchAll();

    json_out(['journal' => $j, 'records' => $rows]);
  }

  /* =========================
   * NETWORKS
   * ======================= */

  case 'author_network': {
    $minWeight = clamp_int(int_param('min_weight', 1), 1, 999999);
    $maxEdges  = (int)limit_sql(int_param('max_edges', 300), 1, 2000);

    $nodes = q("
      SELECT a.id, a.name, COUNT(*) AS papers
      FROM record_authors ra
      JOIN oai_records r ON r.id=ra.record_id
      JOIN authors a ON a.id=ra.author_id
      WHERE r.journal_id=? AND r.status='active'
      GROUP BY a.id, a.name
      ORDER BY papers DESC
      LIMIT 400
    ", [$id])->fetchAll();

    $sqlEdges = "
      SELECT e.author_a, e.author_b, e.weight
      FROM coauthor_edges e
      WHERE e.journal_id=? AND e.weight >= ?
      ORDER BY e.weight DESC
      LIMIT $maxEdges
    ";
    $edges = q($sqlEdges, [$id, $minWeight])->fetchAll();

    json_out(['nodes' => $nodes, 'links' => $edges]);
  }

  case 'author_subject_network': {
    $minWeight = clamp_int(int_param('min_weight', 2), 1, 999999);
    $maxLinks  = (int)limit_sql(int_param('max_links', 600), 1, 6000);

    $authors = q("
      SELECT a.id, CONCAT('a:',a.id) AS nid, a.name AS label, COUNT(*) AS degree
      FROM record_authors ra
      JOIN oai_records r ON r.id=ra.record_id
      JOIN authors a ON a.id=ra.author_id
      WHERE r.journal_id=? AND r.status='active'
      GROUP BY a.id, a.name
      ORDER BY degree DESC
      LIMIT 250
    ", [$id])->fetchAll();

    $subjects = q("
      SELECT s.id, CONCAT('s:',s.id) AS nid, s.label AS label, COUNT(*) AS degree
      FROM record_subjects rs
      JOIN oai_records r ON r.id=rs.record_id
      JOIN subjects s ON s.id=rs.subject_id
      WHERE r.journal_id=? AND r.status='active'
      GROUP BY s.id, s.label
      ORDER BY degree DESC
      LIMIT 250
    ", [$id])->fetchAll();

    $sqlLinks = "
      SELECT CONCAT('a:',author_id) AS source,
             CONCAT('s:',subject_id) AS target,
             weight
      FROM author_subject_edges
      WHERE journal_id=? AND weight >= ?
      ORDER BY weight DESC
      LIMIT $maxLinks
    ";
    $links = q($sqlLinks, [$id, $minWeight])->fetchAll();

    $nodes = [];
    foreach ($authors as $a) {
      $nodes[] = [
        'id' => $a['nid'],
        'label' => $a['label'],
        'group' => 'author',
        'degree' => (int)$a['degree']
      ];
    }
    foreach ($subjects as $s) {
      $nodes[] = [
        'id' => $s['nid'],
        'label' => $s['label'],
        'group' => 'subject',
        'degree' => (int)$s['degree']
      ];
    }

    json_out(['nodes' => $nodes, 'links' => $links]);
  }

case 'subject_network': {
  $minWeight = clamp_int(int_param('min_weight', 2), 1, 999999);
  $maxEdges  = (int)limit_sql(int_param('max_edges', 400), 1, 3000);

  $nodes = q("
    SELECT
      s.id,
      s.label,
      COUNT(*) AS freq,
      MAX(r.pub_year) AS last_year
    FROM record_subjects rs
    JOIN oai_records r ON r.id=rs.record_id
    JOIN subjects s ON s.id=rs.subject_id
    WHERE r.journal_id=? AND r.status='active'
    GROUP BY s.id, s.label
    ORDER BY freq DESC
    LIMIT 400
  ", [$id])->fetchAll();

  $sqlEdges = "
    SELECT subject_a, subject_b, weight
    FROM subject_edges
    WHERE journal_id=? AND weight >= ?
    ORDER BY weight DESC
    LIMIT $maxEdges
  ";
  $edges = q($sqlEdges, [$id, $minWeight])->fetchAll();

  json_out(['nodes' => $nodes, 'links' => $edges]);
}

case 'subject_bubbles': {
  $limit = (int)limit_sql(int_param('limit', 150), 10, 600);

  $rows = q("
    SELECT
      s.id,
      s.label,
      COUNT(*) AS freq,
      MAX(r.pub_year) AS last_year
    FROM record_subjects rs
    JOIN oai_records r ON r.id=rs.record_id
    JOIN subjects s ON s.id=rs.subject_id
    WHERE r.journal_id=? AND r.status='active'
    GROUP BY s.id, s.label
    ORDER BY freq DESC
    LIMIT $limit
  ", [$id])->fetchAll();

  json_out(['nodes' => $rows]);
}


// Tambahkan case baru di switch statement di api.php:

case 'available_years':
    $id = (int)($_GET['id'] ?? 0);
    $years = q("SELECT DISTINCT pub_year FROM oai_records WHERE journal_id=? AND pub_year IS NOT NULL ORDER BY pub_year", [$id])->fetchAll(PDO::FETCH_COLUMN);
    header('Content-Type: application/json');
    echo json_encode(['years' => $years]);
    break;

case 'timeline_network':
    $id = (int)($_GET['id'] ?? 0);
    $startYear = $_GET['start_year'] ?? '';
    $endYear = $_GET['end_year'] ?? '';
    
    $yearCondition = '';
    if ($startYear && $endYear) {
        $yearCondition = "AND pub_year BETWEEN ? AND ?";
        $params = [$id, $startYear, $endYear];
    } else {
        $params = [$id];
    }
    
    // Get distinct years
    $yearsQuery = "SELECT DISTINCT pub_year FROM oai_records 
                   WHERE journal_id=? AND pub_year IS NOT NULL 
                   $yearCondition ORDER BY pub_year";
    $years = q($yearsQuery, $params)->fetchAll(PDO::FETCH_COLUMN);
    
    $result = ['years' => $years, 'data' => []];
    
    // Get network data for each year
    foreach ($years as $year) {
        // Get subjects active in this year
        $subjects = q("
            SELECT s.id, s.label, COUNT(*) as freq
            FROM subjects s
            JOIN record_subjects rs ON s.id = rs.subject_id
            JOIN oai_records r ON rs.record_id = r.id
            WHERE r.journal_id = ? AND r.pub_year = ? AND r.status = 'active'
            GROUP BY s.id, s.label
            ORDER BY freq DESC
        ", [$id, $year])->fetchAll();
        
        // Get co-occurrence edges for this year
        $edges = q("
            SELECT 
                s1.id as subject_a,
                s2.id as subject_b,
                COUNT(*) as weight
            FROM record_subjects rs1
            JOIN record_subjects rs2 ON rs1.record_id = rs2.record_id AND rs1.subject_id < rs2.subject_id
            JOIN subjects s1 ON rs1.subject_id = s1.id
            JOIN subjects s2 ON rs2.subject_id = s2.id
            JOIN oai_records r ON rs1.record_id = r.id
            WHERE r.journal_id = ? AND r.pub_year = ? AND r.status = 'active'
            GROUP BY s1.id, s2.id
            HAVING COUNT(*) >= 1
        ", [$id, $year])->fetchAll();
        
        $result['data'][] = [
            'nodes' => $subjects,
            'links' => $edges
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    break;

case 'yearly_networks':
    $id = (int)($_GET['id'] ?? 0);
    $startYear = $_GET['start_year'] ?? '';
    $endYear = $_GET['end_year'] ?? '';
    
    // Similar to timeline but with more detailed yearly data
    // Implement as needed
    break;

  /* =========================
   * TIME SERIES
   * ======================= */

  case 'time_series_month': {
    $rows = q("
      SELECT pub_month AS ym,
             COUNT(*) AS total,
             SUM(status='active') AS active,
             SUM(status='deleted') AS deleted
      FROM oai_records
      WHERE journal_id=? AND pub_month IS NOT NULL
      GROUP BY pub_month
      ORDER BY pub_month ASC
    ", [$id])->fetchAll();

    json_out(['series' => $rows]);
  }

  /* =========================
   * DEFAULT
   * ======================= */

  default:
    json_out(['error' => true, 'message' => 'Invalid op'], 400);
}
