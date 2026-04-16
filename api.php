<?php
/*
 * File: api.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:54:14 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */


declare(strict_types=1);

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

function limit_sql(int $limit, int $min, int $max): string {
    $limit = clamp_int($limit, $min, $max);
    return (string)$limit;
}

/* ---------------------------
 * Router
 * ------------------------- */

$op = strtolower(str_param('op', ''));
$id = int_param('id', 0);

// Operations that don't require journal ID
$opsWithoutId = ['available_years'];
if (!in_array($op, $opsWithoutId) && $op !== '') {
    require_journal_id($id);
}

switch ($op) {

    /* =========================
     * NETWORK DATA (for network.php)
     * ======================= */
    
case 'network_data': {
    $mode = str_param('mode', 'subject');
    $minWeight = clamp_int(int_param('min_weight', 1), 1, 999999);
    $maxItems = clamp_int(int_param('max_items', 200), 10, 500);
    
    if ($mode === 'author') {
        // Get top authors by publication count
        $topAuthors = q("
            SELECT a.id, a.name, COUNT(*) AS weight
            FROM record_authors ra
            JOIN oai_records r ON r.id = ra.record_id
            JOIN authors a ON a.id = ra.author_id
            WHERE r.journal_id = ? AND r.status = 'active'
            GROUP BY a.id, a.name
            ORDER BY weight DESC
            LIMIT ?
        ", [$id, $maxItems])->fetchAll();
        
        $authorIds = [];
        foreach ($topAuthors as $author) {
            $authorIds[] = $author['id'];
        }
        
        if (empty($authorIds)) {
            json_out(['nodes' => [], 'links' => []]);
            break;
        }
        
        // Get edges only between top authors
        $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
        $edges = q("
            SELECT author_a, author_b, weight
            FROM coauthor_edges
            WHERE journal_id = ? 
                AND weight >= ?
                AND author_a IN ($placeholders)
                AND author_b IN ($placeholders)
            ORDER BY weight DESC
            LIMIT ?
        ", array_merge([$id, $minWeight], $authorIds, $authorIds, [$maxItems * 2]))->fetchAll();
        
        // Format nodes
        $formattedNodes = [];
        foreach ($topAuthors as $node) {
            $formattedNodes[] = [
                'id' => (int)$node['id'],
                'name' => $node['name'],
                'type' => 'author',
                'size' => (int)$node['weight']
            ];
        }
        
        // Format links - ensure source and target exist in nodes
        $nodeIdSet = array_flip(array_column($formattedNodes, 'id'));
        $formattedLinks = [];
        foreach ($edges as $edge) {
            $source = (int)$edge['author_a'];
            $target = (int)$edge['author_b'];
            // Only add edge if both nodes exist in our node list
            if (isset($nodeIdSet[$source]) && isset($nodeIdSet[$target])) {
                $formattedLinks[] = [
                    'source' => $source,
                    'target' => $target,
                    'weight' => (int)$edge['weight']
                ];
            }
        }
        
        json_out(['nodes' => $formattedNodes, 'links' => $formattedLinks]);
        
    } else {
        // Get top subjects by frequency
        $topSubjects = q("
            SELECT s.id, s.label, COUNT(*) AS freq
            FROM record_subjects rs
            JOIN oai_records r ON r.id = rs.record_id
            JOIN subjects s ON s.id = rs.subject_id
            WHERE r.journal_id = ? AND r.status = 'active'
            GROUP BY s.id, s.label
            ORDER BY freq DESC
            LIMIT ?
        ", [$id, $maxItems])->fetchAll();
        
        $subjectIds = [];
        foreach ($topSubjects as $subject) {
            $subjectIds[] = $subject['id'];
        }
        
        if (empty($subjectIds)) {
            json_out(['nodes' => [], 'links' => []]);
            break;
        }
        
        // Get edges only between top subjects
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $edges = q("
            SELECT subject_a, subject_b, weight
            FROM subject_edges
            WHERE journal_id = ? 
                AND weight >= ?
                AND subject_a IN ($placeholders)
                AND subject_b IN ($placeholders)
            ORDER BY weight DESC
            LIMIT ?
        ", array_merge([$id, $minWeight], $subjectIds, $subjectIds, [$maxItems * 2]))->fetchAll();
        
        // Format nodes
        $formattedNodes = [];
        foreach ($topSubjects as $node) {
            $formattedNodes[] = [
                'id' => (int)$node['id'],
                'name' => $node['label'],
                'type' => 'subject',
                'size' => (int)$node['freq']
            ];
        }
        
        // Format links - ensure source and target exist in nodes
        $nodeIdSet = array_flip(array_column($formattedNodes, 'id'));
        $formattedLinks = [];
        foreach ($edges as $edge) {
            $source = (int)$edge['subject_a'];
            $target = (int)$edge['subject_b'];
            // Only add edge if both nodes exist in our node list
            if (isset($nodeIdSet[$source]) && isset($nodeIdSet[$target])) {
                $formattedLinks[] = [
                    'source' => $source,
                    'target' => $target,
                    'weight' => (int)$edge['weight']
                ];
            }
        }
        
        json_out(['nodes' => $formattedNodes, 'links' => $formattedLinks]);
    }
    break;
}
    
    case 'available_years': {
        $journalId = int_param('id', 0);
        if ($journalId <= 0) {
            json_out(['error' => true, 'message' => 'Parameter id diperlukan'], 400);
        }
        $years = q("
            SELECT DISTINCT pub_year 
            FROM oai_records 
            WHERE journal_id = ? AND pub_year IS NOT NULL AND status = 'active'
            ORDER BY pub_year ASC
        ", [$journalId])->fetchAll(PDO::FETCH_COLUMN);
        
        json_out(['years' => $years]);
        break;
    }
    
    case 'network_stats': {
        $mode = str_param('mode', 'subject');
        
        if ($mode === 'author') {
            $stats = q("
                SELECT 
                    COUNT(DISTINCT author_a, author_b) as edge_count,
                    AVG(weight) as avg_weight,
                    MAX(weight) as max_weight,
                    (SELECT COUNT(DISTINCT author_id) FROM record_authors ra JOIN oai_records r ON r.id = ra.record_id WHERE r.journal_id = ? AND r.status = 'active') as node_count
                FROM coauthor_edges
                WHERE journal_id = ?
            ", [$id, $id])->fetch();
        } else {
            $stats = q("
                SELECT 
                    COUNT(*) as edge_count,
                    AVG(weight) as avg_weight,
                    MAX(weight) as max_weight,
                    (SELECT COUNT(DISTINCT subject_id) FROM record_subjects rs JOIN oai_records r ON r.id = rs.record_id WHERE r.journal_id = ? AND r.status = 'active') as node_count
                FROM subject_edges
                WHERE journal_id = ?
            ", [$id, $id])->fetch();
        }
        
        json_out([
            'node_count' => (int)($stats['node_count'] ?? 0),
            'edge_count' => (int)($stats['edge_count'] ?? 0),
            'avg_weight' => round($stats['avg_weight'] ?? 0, 2),
            'max_weight' => (int)($stats['max_weight'] ?? 0)
        ]);
        break;
    }
    
    case 'top_entities': {
        $mode = str_param('mode', 'subject');
        $limit = clamp_int(int_param('limit', 10), 5, 50);
        
        if ($mode === 'author') {
            $top = q("
                SELECT a.id, a.name, COUNT(*) as count
                FROM record_authors ra
                JOIN oai_records r ON r.id = ra.record_id
                JOIN authors a ON a.id = ra.author_id
                WHERE r.journal_id = ? AND r.status = 'active'
                GROUP BY a.id, a.name
                ORDER BY count DESC
                LIMIT ?
            ", [$id, $limit])->fetchAll();
        } else {
            $top = q("
                SELECT s.id, s.label, COUNT(*) as count
                FROM record_subjects rs
                JOIN oai_records r ON r.id = rs.record_id
                JOIN subjects s ON s.id = rs.subject_id
                WHERE r.journal_id = ? AND r.status = 'active'
                GROUP BY s.id, s.label
                ORDER BY count DESC
                LIMIT ?
            ", [$id, $limit])->fetchAll();
        }
        
        json_out(['entities' => $top]);
        break;
    }

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
     * NETWORKS (Legacy)
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
        json_out(['error' => true, 'message' => 'Invalid operation: ' . $op], 400);
}