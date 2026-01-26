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
require_once __DIR__ . '/db.php';

function oai_url(string $base, array $params): string {
  $q = http_build_query($params);
  return (strpos($base, '?') === false) ? ($base . '?' . $q) : ($base . '&' . $q);
}

function http_get(string $url, int $timeout = 30, int $retries = 2): string {
  static $cookieFile = null;
  if ($cookieFile === null) $cookieFile = sys_get_temp_dir() . '/oai_cookie_' . md5(__FILE__) . '.txt';

  $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
  $lastErr = '';

  for ($i=0; $i <= $retries; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_USERAGENT      => $ua,
      CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
      ],
      CURLOPT_REFERER        => preg_replace('~\?.*$~', '', $url),
      CURLOPT_COOKIEJAR      => $cookieFile,
      CURLOPT_COOKIEFILE     => $cookieFile,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
      CURLOPT_ENCODING       => '',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body !== false && $code >= 200 && $code < 300) return $body;

    if ($code === 403) {
      $base = preg_replace('~\?.*$~', '', $url);
      $warm = $base . (strpos($base,'?')===false ? '?' : '&') . 'verb=Identify';
      $wch = curl_init($warm);
      curl_setopt_array($wch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
      ]);
      curl_exec($wch);
      curl_close($wch);
    }

    $lastErr = $err ?: "HTTP $code";
    usleep(350000);
  }

  throw new RuntimeException("Gagal fetch URL: $url | Error: $lastErr");
}

function is_oai_endpoint(string $base): bool {
  try {
    $xmlStr = http_get(oai_url($base, ['verb' => 'Identify']), 20, 1);
    $xml = @simplexml_load_string($xmlStr);
    return $xml && (isset($xml->Identify) || isset($xml->request));
  } catch (\Throwable $e) {
    return false;
  }
}

function detect_oai_base(string $journalUrl): string {
  $u = trim($journalUrl);
  if ($u === '') throw new RuntimeException("URL jurnal utama kosong.");
  $u = rtrim($u, '/');

  if (preg_match('~/oai$~i', $u) && is_oai_endpoint($u)) return $u;

  $candidates = [];

  if (preg_match('~^(https?://[^/]+)(/index\.php/[^/]+)~i', $u, $m)) {
    $candidates[] = $m[1] . $m[2] . '/oai';
  }

  $candidates[] = $u . '/oai';

  if (preg_match('~^(https?://[^/]+)~i', $u, $m)) {
    $candidates[] = $m[1] . '/oai';
  }

  $candidates = array_values(array_unique($candidates));
  foreach ($candidates as $cand) {
    if (is_oai_endpoint($cand)) return $cand;
  }
  throw new RuntimeException("Auto-detect gagal. Isi URL OAI Base manual.");
}

function normalize_key(string $s, int $maxLen = 255): string {
  $s = trim($s);
  $s = mb_strtolower($s);
  $s = preg_replace('~\s+~u', ' ', $s);
  $s = preg_replace('~[^\p{L}\p{N}\s\-\._,;:/()]~u', '', $s);
  if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
  return $s;
}

function title_key(?string $title): ?string {
  $t = trim((string)$title);
  if ($t === '') return null;
  $k = normalize_key($t, 450);
  return $k === '' ? null : $k;
}

function normalize_date(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;

  if (preg_match('~^(\d{4}-\d{2}-\d{2})~', $s, $m)) return $m[1];
  if (preg_match('~^(\d{4}-\d{2})$~', $s, $m)) return $m[1] . '-01';
  if (preg_match('~^(\d{4})$~', $s, $m)) return $m[1] . '-01-01';

  $ts = strtotime($s);
  return $ts !== false ? date('Y-m-d', $ts) : null;
}

function dc_list(\SimpleXMLElement $dc, string $field): array {
  $out = [];
  $vals = $dc->children('http://purl.org/dc/elements/1.1/')->{$field};
  if (!$vals) return $out;
  foreach ($vals as $v) {
    $t = trim((string)$v);
    if ($t !== '') $out[] = $t;
  }
  return $out;
}

function pick_best_url_and_doi(array $identifiers): array {
  $url = '';
  $doi = '';
  foreach ($identifiers as $s) {
    $s = trim((string)$s);
    if ($s === '') continue;
    if ($url === '' && preg_match('~^https?://~i', $s)) $url = $s;
    if ($doi === '' && preg_match('~^10\.\d{4,9}/.+~', $s)) $doi = $s;
    if ($doi === '' && preg_match('~doi\.org/(10\.\d{4,9}/.+)$~i', $s, $m)) $doi = $m[1];
  }
  return [$url ?: null, $doi ?: null];
}

function should_harvest(array $journal, bool $force): bool {
  if ($force) return true;
  if ((int)$journal['enabled'] !== 1) return false;

  $cfg = require __DIR__ . '/config.php';
  $ttlMap = $cfg['ttl'];
  $freq = $journal['harvest_freq'] ?? 'daily';
  $ttl = (int)($ttlMap[$freq] ?? 86400);

  if ($ttl <= 0) return true;
  if (empty($journal['last_harvest_at'])) return true;

  $last = strtotime($journal['last_harvest_at']);
  if ($last === false) return true;
  return (time() - $last) >= $ttl;
}

function upsert_author(string $name): int {
  $key = normalize_key($name, 255);
  if ($key === '') return 0;
  q("INSERT INTO authors (name, name_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name)", [$name, $key]);
  $row = q("SELECT id FROM authors WHERE name_key=?", [$key])->fetch();
  return (int)$row['id'];
}

function upsert_subject(string $label): int {
  $key = normalize_key($label, 255);
  if ($key === '') return 0;
  q("INSERT INTO subjects (label, label_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE label=VALUES(label)", [$label, $key]);
  $row = q("SELECT id FROM subjects WHERE label_key=?", [$key])->fetch();
  return (int)$row['id'];
}

function inc_coauthor_edge(int $journalId, int $a, int $b): void {
  if ($a === $b) return;
  if ($a > $b) { $t = $a; $a = $b; $b = $t; }
  q("INSERT INTO coauthor_edges (journal_id, author_a, author_b, weight) VALUES (?,?,?,1)
     ON DUPLICATE KEY UPDATE weight = weight + 1", [$journalId, $a, $b]);
}

function inc_subject_edge(int $journalId, int $a, int $b): void {
  if ($a === $b) return;
  if ($a > $b) { $t = $a; $a = $b; $b = $t; }
  q("INSERT INTO subject_edges (journal_id, subject_a, subject_b, weight) VALUES (?,?,?,1)
     ON DUPLICATE KEY UPDATE weight = weight + 1", [$journalId, $a, $b]);
}

function inc_author_subject_edge(int $journalId, int $authorId, int $subjectId): void {
  q("INSERT INTO author_subject_edges (journal_id, author_id, subject_id, weight) VALUES (?,?,?,1)
     ON DUPLICATE KEY UPDATE weight = weight + 1", [$journalId, $authorId, $subjectId]);
}

/**
 * HARVEST: simpan record + semua field dc sebagai JSON + raw xml.
 * Dedup:
 *  - oai_identifier unik (wajib)
 *  - title_key unik per jurnal (sesuai permintaan: judul sama tidak dihitung/tampil)
 * Record deleted tetap disimpan (tanpa metadata).
 */
function harvest_journal(int $journalId, bool $force = false, int $maxUnique = 0): array {
  $journal = q("SELECT * FROM journals WHERE id=?", [$journalId])->fetch();
  if (!$journal) throw new RuntimeException("Journal id $journalId tidak ditemukan.");

  if (!should_harvest($journal, $force)) {
    return ['skipped' => true, 'message' => 'Skip: masih dalam TTL cache.'];
  }

  q("INSERT INTO harvest_runs (journal_id,status) VALUES (?, 'running')", [$journalId]);
  $runId = (int)db()->lastInsertId();

  // reset edges for this journal-run (optional strategy: rebuild each harvest)
  // Untuk sederhana & konsisten, kita rebuild edges dari record yang diproses di harvest ini.
  // Jika ingin incremental, kita bisa beda strategi.
  q("DELETE FROM coauthor_edges WHERE journal_id=?", [$journalId]);
  q("DELETE FROM subject_edges WHERE journal_id=?", [$journalId]);
  q("DELETE FROM author_subject_edges WHERE journal_id=?", [$journalId]);

  $totalSeenAll = 0;
  $inserted = 0;
  $updated = 0;
  $skippedDupTitle = 0;
  $activeCount = 0;
  $deletedCount = 0;
  $doiPresent = 0;

  $pubEarliest = null;
  $pubLatest = null;

  $uniqueAuthors = [];
  $uniqueSubjects = [];

  $base = $journal['oai_base_url'];
  $prefix = $journal['metadata_prefix'] ?: 'oai_dc';
  $set = $journal['set_spec'] ?? '';

  $token = '';
  $batchNo = 0;

  try {
    while (true) {
      $batchNo++;

      $params = ($token !== '')
        ? ['verb' => 'ListRecords', 'resumptionToken' => $token]
        : array_filter(['verb' => 'ListRecords', 'metadataPrefix' => $prefix, 'set' => ($set !== '' ? $set : null)]);

      $xmlStr = http_get(oai_url($base, $params), 40, 2);

      libxml_use_internal_errors(true);
      $xml = simplexml_load_string($xmlStr);
      if (!$xml) {
        $errs = array_map(fn($e)=>trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        throw new RuntimeException("XML parse error batch #$batchNo: " . implode(" | ", $errs));
      }

      if (isset($xml->error)) {
        $code = (string)$xml->error['code'];
        $msg  = trim((string)$xml->error);
        throw new RuntimeException("OAI Error: $code - $msg");
      }

      $list = $xml->ListRecords;
      if (!$list) break;

      foreach ($list->record as $r) {
        $totalSeenAll++;

        $header = $r->header;
        $status = (string)($header['status'] ?? '');
        $isDeleted = (strtolower($status) === 'deleted');

        $identifier = trim((string)$header->identifier);
        if ($identifier === '') continue;

        $datestamp  = trim((string)$header->datestamp);

        $setSpecs = [];
        if (isset($header->setSpec)) {
          foreach ($header->setSpec as $ss) {
            $t = trim((string)$ss);
            if ($t !== '') $setSpecs[] = $t;
          }
        }
        $setSpecStr = implode('; ', $setSpecs);

        $rawXml = $r->asXML() ?: null;

        // DC fields as arrays
        $dcArr = [
          'title' => [], 'creator' => [], 'subject' => [], 'description' => [],
          'publisher' => [], 'contributor' => [], 'date' => [], 'type' => [],
          'format' => [], 'identifier' => [], 'source' => [], 'language' => [],
          'relation' => [], 'coverage' => [], 'rights' => [],
        ];

        if (!$isDeleted && isset($r->metadata)) {
          $dc = $r->metadata->children('http://www.openarchives.org/OAI/2.0/oai_dc/')->dc ?? null;
          if ($dc) {
            foreach (array_keys($dcArr) as $f) {
              $dcArr[$f] = dc_list($dc, $f);
            }
          }
        }

        $title = $dcArr['title'][0] ?? '';
        $tKey = title_key($title);

        // pub_date: prefer dc:date[0], fallback datestamp
        $pubDate = normalize_date($dcArr['date'][0] ?? '') ?? normalize_date($datestamp);
        $pubYear = $pubDate ? (int)substr($pubDate, 0, 4) : null;
        $pubMonth = $pubDate ? substr($pubDate, 0, 7) : null;

        [$urlBest, $doiBest] = pick_best_url_and_doi($dcArr['identifier']);
        $publisherBest = $dcArr['publisher'][0] ?? null;
        $languageBest = $dcArr['language'][0] ?? null;

        // Upsert record
        $exists = q("SELECT id FROM oai_records WHERE journal_id=? AND oai_identifier=?", [$journalId, $identifier])->fetch();
        $isUpdate = (bool)$exists;

        try {
          q("
            INSERT INTO oai_records
              (journal_id, oai_identifier, status, datestamp, set_spec,
               title, title_key, pub_date, pub_year, pub_month,
               dc_title_json, dc_creator_json, dc_subject_json, dc_description_json, dc_publisher_json, dc_contributor_json,
               dc_date_json, dc_type_json, dc_format_json, dc_identifier_json, dc_source_json, dc_language_json,
               dc_relation_json, dc_coverage_json, dc_rights_json,
               url_best, doi_best, publisher_best, language_best,
               raw_record_xml, last_seen_at, last_harvest_run_id)
            VALUES
              (?, ?, ?, ?, ?,
               ?, ?, ?, ?, ?,
               ?, ?, ?, ?, ?, ?,
               ?, ?, ?, ?, ?, ?,
               ?, ?, ?,
               ?, ?, ?, ?,
               ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
              status=VALUES(status),
              datestamp=VALUES(datestamp),
              set_spec=VALUES(set_spec),
              title=VALUES(title),
              title_key=VALUES(title_key),
              pub_date=VALUES(pub_date),
              pub_year=VALUES(pub_year),
              pub_month=VALUES(pub_month),

              dc_title_json=VALUES(dc_title_json),
              dc_creator_json=VALUES(dc_creator_json),
              dc_subject_json=VALUES(dc_subject_json),
              dc_description_json=VALUES(dc_description_json),
              dc_publisher_json=VALUES(dc_publisher_json),
              dc_contributor_json=VALUES(dc_contributor_json),
              dc_date_json=VALUES(dc_date_json),
              dc_type_json=VALUES(dc_type_json),
              dc_format_json=VALUES(dc_format_json),
              dc_identifier_json=VALUES(dc_identifier_json),
              dc_source_json=VALUES(dc_source_json),
              dc_language_json=VALUES(dc_language_json),
              dc_relation_json=VALUES(dc_relation_json),
              dc_coverage_json=VALUES(dc_coverage_json),
              dc_rights_json=VALUES(dc_rights_json),

              url_best=VALUES(url_best),
              doi_best=VALUES(doi_best),
              publisher_best=VALUES(publisher_best),
              language_best=VALUES(language_best),
              raw_record_xml=VALUES(raw_record_xml),
              last_seen_at=NOW(),
              last_harvest_run_id=VALUES(last_harvest_run_id)
          ", [
            $journalId, $identifier, $isDeleted ? 'deleted' : 'active', $datestamp, $setSpecStr ?: null,
            $title ?: null, $tKey,
            $pubDate, $pubYear, $pubMonth,
            json_encode($dcArr['title'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['creator'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['subject'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['description'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['publisher'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['contributor'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),

            json_encode($dcArr['date'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['type'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['format'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['identifier'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['source'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['language'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),

            json_encode($dcArr['relation'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['coverage'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($dcArr['rights'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),

            $urlBest, $doiBest, $publisherBest, $languageBest,
            $rawXml, $runId
          ]);

        } catch (PDOException $pe) {
          // duplikat judul (uk_journal_title) => skip (sesuai permintaan)
          if (str_contains($pe->getMessage(), 'uk_journal_title')) {
            $skippedDupTitle++;
            continue;
          }
          throw $pe;
        }

        $row = q("SELECT id FROM oai_records WHERE journal_id=? AND oai_identifier=?", [$journalId, $identifier])->fetch();
        $recordId = (int)$row['id'];

        if ($isUpdate) $updated++; else $inserted++;

        if ($isDeleted) $deletedCount++; else $activeCount++;
        if (!$isDeleted && $doiBest) $doiPresent++;

        if ($pubDate) {
          if ($pubEarliest === null || $pubDate < $pubEarliest) $pubEarliest = $pubDate;
          if ($pubLatest   === null || $pubDate > $pubLatest)   $pubLatest   = $pubDate;
        }

        // Rebuild relations (clear & insert for this record)
        q("DELETE FROM record_authors WHERE record_id=?", [$recordId]);
        q("DELETE FROM record_subjects WHERE record_id=?", [$recordId]);

        // Authors
        $authorIds = [];
        $authors = $dcArr['creator'];
        $order = 0;
        foreach ($authors as $aName) {
          $order++;
          $aid = upsert_author($aName);
          if ($aid <= 0) continue;
          $uniqueAuthors[$aid] = true;
          $authorIds[] = $aid;
          q("INSERT IGNORE INTO record_authors (record_id, author_id, author_order) VALUES (?,?,?)", [$recordId, $aid, $order]);
        }

        // Subjects
        $subjectIds = [];
        $subjects = $dcArr['subject'];
        foreach ($subjects as $sLabel) {
          $sid = upsert_subject($sLabel);
          if ($sid <= 0) continue;
          $uniqueSubjects[$sid] = true;
          $subjectIds[] = $sid;
          q("INSERT IGNORE INTO record_subjects (record_id, subject_id) VALUES (?,?)", [$recordId, $sid]);
        }

        // coauthor edges (pairwise)
        $nA = count($authorIds);
        for ($i=0; $i<$nA; $i++) {
          for ($j=$i+1; $j<$nA; $j++) {
            inc_coauthor_edge($journalId, $authorIds[$i], $authorIds[$j]);
          }
        }

        // subject edges (pairwise)
        $nS = count($subjectIds);
        for ($i=0; $i<$nS; $i++) {
          for ($j=$i+1; $j<$nS; $j++) {
            inc_subject_edge($journalId, $subjectIds[$i], $subjectIds[$j]);
          }
        }

        // author-subject edges
        foreach ($authorIds as $aid) {
          foreach ($subjectIds as $sid) {
            inc_author_subject_edge($journalId, $aid, $sid);
          }
        }

        if ($maxUnique > 0 && ($inserted + $updated) >= $maxUnique) {
          $token = '';
          break;
        }
      }

      $token = trim((string)($list->resumptionToken ?? ''));
      if ($token === '') break;
    }

    q("
      UPDATE harvest_runs
      SET finished_at=NOW(), status='ok',
          total_seen_all=?, total_inserted=?, total_updated=?, total_skipped_dup_title=?,
          active_count=?, deleted_count=?,
          pub_earliest=?, pub_latest=?,
          doi_present=?, unique_authors=?, unique_subjects=?
      WHERE id=?
    ", [
      $totalSeenAll, $inserted, $updated, $skippedDupTitle,
      $activeCount, $deletedCount,
      $pubEarliest, $pubLatest,
      $doiPresent, count($uniqueAuthors), count($uniqueSubjects),
      $runId
    ]);

    q("UPDATE journals SET last_harvest_at=NOW(), last_harvest_status='ok', last_harvest_message=NULL WHERE id=?", [$journalId]);

    return [
      'skipped' => false,
      'run_id' => $runId,
      'seen' => $totalSeenAll,
      'inserted' => $inserted,
      'updated' => $updated,
      'skipped_dup_title' => $skippedDupTitle,
      'active' => $activeCount,
      'deleted' => $deletedCount,
      'pub_earliest' => $pubEarliest,
      'pub_latest' => $pubLatest,
      'doi_present' => $doiPresent,
      'unique_authors' => count($uniqueAuthors),
      'unique_subjects' => count($uniqueSubjects),
    ];

  } catch (Throwable $e) {
    q("UPDATE harvest_runs SET finished_at=NOW(), status='error', message=? WHERE id=?", [$e->getMessage(), $runId]);
    q("UPDATE journals SET last_harvest_at=NOW(), last_harvest_status='error', last_harvest_message=? WHERE id=?", [$e->getMessage(), $journalId]);
    throw $e;
  }
}
