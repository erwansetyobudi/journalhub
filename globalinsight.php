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

// Mulai session untuk debugging jika perlu
session_start();

// ========== ERROR HANDLING ==========
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database functions
require_once __DIR__ . '/db.php';

// ========== CACHE CONFIGURATION ==========
define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_TTL', 300); // 5 menit cache
define('ENABLE_CACHE', true);

// Buat direktori cache jika belum ada
if (!file_exists(CACHE_DIR) && ENABLE_CACHE) {
    mkdir(CACHE_DIR, 0755, true);
}

// ========== CACHE FUNCTIONS ==========
function getCached($key, $callback, $ttl = CACHE_TTL) {
    if (!ENABLE_CACHE) {
        return $callback();
    }
    
    $cacheFile = CACHE_DIR . md5($key) . '.cache';
    
    // Cek cache valid
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $data = file_get_contents($cacheFile);
        if ($data !== false) {
            return unserialize($data);
        }
    }
    
    // Ambil data baru
    $data = $callback();
    
    // Simpan ke cache
    try {
        file_put_contents($cacheFile, serialize($data));
    } catch (Exception $e) {
        // Ignore cache errors
    }
    
    return $data;
}

function clearCache($key = null) {
    if ($key) {
        $cacheFile = CACHE_DIR . md5($key) . '.cache';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    } else {
        $files = glob(CACHE_DIR . '*.cache');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

// ========== HELPER FUNCTIONS ==========
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    
    // Clean any output before this
    if (ob_get_level()) {
        ob_clean();
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logError($message) {
    $logFile = __DIR__ . '/error.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// ========== API ENDPOINTS ==========
if (isset($_GET['api'])) {
    try {
        switch ($_GET['api']) {
            case 'network':
                $type = $_GET['type'] ?? 'coauthors';
                $journal_id = $_GET['journal_id'] ?? null;
                $publisher = $_GET['publisher'] ?? null;
                $rumpunilmu = $_GET['rumpunilmu'] ?? null;
                $year = $_GET['year'] ?? null;
                $limit = $_GET['limit'] ?? 50;
                $data = getNetworkData($type, $journal_id, $publisher, $rumpunilmu, $year, (int)$limit);
                jsonResponse($data);
                break;
                
            case 'top-journals':
                $data = getTopJournals();
                jsonResponse($data);
                break;
                
            case 'top-authors':
                $data = getTopAuthors();
                jsonResponse($data);
                break;
                
            case 'top-subjects':
                $data = getTopSubjects();
                jsonResponse($data);
                break;
                
            case 'stats':
                $data = getBasicStats();
                jsonResponse($data);
                break;
                
            case 'productivity':
                $data = getProductivityTrends();
                jsonResponse($data);
                break;
                
            case 'journal-stats':
                $data = getJournalStats();
                jsonResponse($data);
                break;
                
            case 'author-stats':
                $data = getAuthorStats();
                jsonResponse($data);
                break;
                
            case 'metadata-stats':
                $data = getMetadataStats();
                jsonResponse($data);
                break;
                
            case 'filter-options':
                $data = getFilterOptions();
                jsonResponse($data);
                break;
                
            case 'clear-cache':
                clearCache();
                jsonResponse(['success' => true, 'message' => 'Cache cleared']);
                break;
                
            default:
                jsonResponse(['error' => 'Invalid API endpoint'], 404);
        }
    } catch (Exception $e) {
        logError("API Error: " . $e->getMessage());
        jsonResponse([
            'error' => 'Internal server error',
            'message' => $e->getMessage()
        ], 500);
    }
}

// ========== DATA FUNCTIONS ==========
function getFilterOptions() {
    return getCached('filter_options', function() {
        try {
            // Dapatkan opsi publisher
            $publishers = q("
                SELECT DISTINCT publisher as name 
                FROM journals 
                WHERE publisher IS NOT NULL 
                AND publisher != '' 
                ORDER BY publisher
            ")->fetchAll();
            
            // Dapatkan opsi rumpun ilmu (jika tabel ada)
            $rumpunIlmu = [];
            try {
                $rumpunIlmu = q("
                    SELECT rumpunilmu_id as id, nama_rumpun as name 
                    FROM rumpunilmu 
                    ORDER BY nama_rumpun
                ")->fetchAll();
            } catch (Exception $e) {
                // Tabel mungkin tidak ada, abaikan
            }
            
            // Dapatkan opsi tahun
            $years = q("
                SELECT DISTINCT pub_year as year 
                FROM oai_records 
                WHERE pub_year IS NOT NULL 
                ORDER BY pub_year DESC
            ")->fetchAll();
            
            // Dapatkan opsi jurnal
            $journals = q("
                SELECT id, name 
                FROM journals 
                WHERE enabled = 1 
                ORDER BY name
            ")->fetchAll();
            
            return [
                'publishers' => $publishers ?: [],
                'rumpunIlmu' => $rumpunIlmu ?: [],
                'years' => $years ?: [],
                'journals' => $journals ?: []
            ];
            
        } catch (Exception $e) {
            logError("getFilterOptions error: " . $e->getMessage());
            return [
                'publishers' => [],
                'rumpunIlmu' => [],
                'years' => [],
                'journals' => []
            ];
        }
    }, 600);
}

function getBasicStats() {
    return getCached('basic_stats', function() {
        try {
            $stmt = q("
                SELECT 
                    (SELECT COUNT(*) FROM journals) as total_journals,
                    (SELECT COUNT(*) FROM journals WHERE enabled = 1) as active_journals,
                    (SELECT COUNT(*) FROM oai_records) as total_records,
                    (SELECT COUNT(*) FROM oai_records WHERE status = 'active') as active_records,
                    (SELECT COUNT(DISTINCT author_id) FROM record_authors) as total_authors,
                    (SELECT COUNT(DISTINCT publisher) FROM journals WHERE publisher IS NOT NULL AND publisher != '') as total_publishers,
                    (SELECT COUNT(DISTINCT id) FROM subjects) as total_subjects,
                    (SELECT COUNT(*) FROM oai_records WHERE doi_best IS NOT NULL AND doi_best != '' AND status = 'active') as doi_records,
                    (SELECT COUNT(*) FROM harvest_runs WHERE status = 'ok') as total_harvests,
                    (SELECT COUNT(DISTINCT language_best) FROM oai_records WHERE language_best IS NOT NULL) as languages_count
            ");
            $result = $stmt->fetch();
            return $result ?: [
                'total_journals' => 0,
                'active_journals' => 0,
                'total_records' => 0,
                'active_records' => 0,
                'total_authors' => 0,
                'total_publishers' => 0,
                'total_subjects' => 0,
                'doi_records' => 0,
                'total_harvests' => 0,
                'languages_count' => 0
            ];
        } catch (Exception $e) {
            logError("getBasicStats error: " . $e->getMessage());
            return [
                'total_journals' => 0,
                'active_journals' => 0,
                'total_records' => 0,
                'active_records' => 0,
                'total_authors' => 0,
                'total_publishers' => 0,
                'total_subjects' => 0,
                'doi_records' => 0,
                'total_harvests' => 0,
                'languages_count' => 0
            ];
        }
    }, 60);
}

function getProductivityTrends() {
    return getCached('productivity_trends', function() {
        try {
            // Tren publikasi tahunan
            $annual = q("
                SELECT 
                    pub_year as year,
                    COUNT(*) as publications,
                    COUNT(DISTINCT journal_id) as journals_count
                FROM oai_records 
                WHERE pub_year IS NOT NULL 
                GROUP BY pub_year 
                ORDER BY pub_year
            ")->fetchAll();
            
            // Tren publikasi bulanan (tahun terakhir)
            $monthly = q("
                SELECT 
                    DATE_FORMAT(pub_date, '%Y-%m') as month,
                    COUNT(*) as publications
                FROM oai_records 
                WHERE pub_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(pub_date, '%Y-%m')
                ORDER BY month
            ")->fetchAll();
            
            return [
                'annual' => $annual ?: [],
                'monthly' => $monthly ?: [],
                'status' => 'success'
            ];
        } catch (Exception $e) {
            logError("getProductivityTrends error: " . $e->getMessage());
            return [
                'annual' => [],
                'monthly' => [],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }, 300);
}

function getTopJournals() {
    return getCached('top_journals', function() {
        try {
            return q("
                SELECT 
                    j.id,
                    j.name,
                    j.publisher,
                    COUNT(r.id) as record_count,
                    COUNT(DISTINCT ra.author_id) as authors_count,
                    MIN(r.pub_date) as earliest_date,
                    MAX(r.pub_date) as latest_date,
                    COUNT(CASE WHEN r.doi_best IS NOT NULL THEN 1 END) as doi_count
                FROM journals j
                LEFT JOIN oai_records r ON j.id = r.journal_id
                LEFT JOIN record_authors ra ON r.id = ra.record_id
                GROUP BY j.id
                ORDER BY record_count DESC
                LIMIT 10
            ")->fetchAll();
        } catch (Exception $e) {
            logError("getTopJournals error: " . $e->getMessage());
            return [];
        }
    }, 300);
}

function getTopAuthors() {
    return getCached('top_authors', function() {
        try {
            return q("
                SELECT 
                    a.id,
                    a.name,
                    COUNT(ra.record_id) as paper_count,
                    COUNT(DISTINCT r.journal_id) as journals_count,
                    MIN(r.pub_date) as first_publication,
                    MAX(r.pub_date) as last_publication
                FROM authors a
                JOIN record_authors ra ON a.id = ra.author_id
                JOIN oai_records r ON ra.record_id = r.id
                GROUP BY a.id
                ORDER BY paper_count DESC
                LIMIT 15
            ")->fetchAll();
        } catch (Exception $e) {
            logError("getTopAuthors error: " . $e->getMessage());
            return [];
        }
    }, 300);
}

function getTopSubjects() {
    return getCached('top_subjects', function() {
        try {
            return q("
                SELECT 
                    s.id,
                    s.label,
                    COUNT(rs.record_id) as occurrence_count,
                    COUNT(DISTINCT r.journal_id) as journals_count,
                    COUNT(DISTINCT ra.author_id) as authors_count
                FROM subjects s
                JOIN record_subjects rs ON s.id = rs.subject_id
                JOIN oai_records r ON rs.record_id = r.id
                LEFT JOIN record_authors ra ON r.id = ra.record_id
                GROUP BY s.id
                ORDER BY occurrence_count DESC
                LIMIT 15
            ")->fetchAll();
        } catch (Exception $e) {
            logError("getTopSubjects error: " . $e->getMessage());
            return [];
        }
    }, 300);
}

function getJournalStats() {
    return getCached('journal_stats', function() {
        try {
            return q("
                SELECT 
                    publisher,
                    COUNT(*) as journal_count,
                    SUM(record_count) as total_records,
                    AVG(record_count) as avg_records
                FROM (
                    SELECT 
                        j.publisher,
                        COUNT(r.id) as record_count
                    FROM journals j
                    LEFT JOIN oai_records r ON j.id = r.journal_id
                    GROUP BY j.id, j.publisher
                ) as journal_stats
                WHERE publisher IS NOT NULL
                GROUP BY publisher
                ORDER BY journal_count DESC
                LIMIT 10
            ")->fetchAll();
        } catch (Exception $e) {
            logError("getJournalStats error: " . $e->getMessage());
            return [];
        }
    }, 300);
}

function getAuthorStats() {
    return getCached('author_stats', function() {
        try {
            // Distribusi produktivitas penulis
            $productivity = q("
                SELECT 
                    paper_count_range,
                    COUNT(*) as authors_count
                FROM (
                    SELECT 
                        a.id,
                        CASE 
                            WHEN COUNT(ra.record_id) = 1 THEN '1'
                            WHEN COUNT(ra.record_id) BETWEEN 2 AND 5 THEN '2-5'
                            WHEN COUNT(ra.record_id) BETWEEN 6 AND 10 THEN '6-10'
                            WHEN COUNT(ra.record_id) BETWEEN 11 AND 20 THEN '11-20'
                            ELSE '21+'
                        END as paper_count_range
                    FROM authors a
                    LEFT JOIN record_authors ra ON a.id = ra.author_id
                    GROUP BY a.id
                ) as author_stats
                GROUP BY paper_count_range
                ORDER BY paper_count_range
            ")->fetchAll();
            
            // Kolaborasi (rata-rata co-author per paper)
            $collaboration = q("
                SELECT 
                    AVG(author_count) as avg_authors_per_paper,
                    MAX(author_count) as max_authors_per_paper,
                    COUNT(DISTINCT CASE WHEN author_count > 1 THEN r.id END) as multi_author_papers
                FROM (
                    SELECT 
                        r.id,
                        COUNT(ra.author_id) as author_count
                    FROM oai_records r
                    LEFT JOIN record_authors ra ON r.id = ra.record_id
                    GROUP BY r.id
                ) as paper_stats
            ")->fetch();
            
            return [
                'productivity_distribution' => $productivity ?: [],
                'collaboration_stats' => $collaboration ?: [],
                'status' => 'success'
            ];
        } catch (Exception $e) {
            logError("getAuthorStats error: " . $e->getMessage());
            return [
                'productivity_distribution' => [],
                'collaboration_stats' => [],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }, 300);
}

function getMetadataStats() {
    return getCached('metadata_stats', function() {
        try {
            return q("
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN doi_best IS NOT NULL AND doi_best != '' THEN 1 END) as with_doi,
                    COUNT(CASE WHEN language_best IS NOT NULL THEN 1 END) as with_language,
                    COUNT(CASE WHEN publisher_best IS NOT NULL THEN 1 END) as with_publisher,
                    COUNT(DISTINCT language_best) as unique_languages,
                    AVG(LENGTH(title)) as avg_title_length
                FROM oai_records 
                WHERE status = 'active'
            ")->fetch();
        } catch (Exception $e) {
            logError("getMetadataStats error: " . $e->getMessage());
            return [
                'total_records' => 0,
                'with_doi' => 0,
                'with_language' => 0,
                'with_publisher' => 0,
                'unique_languages' => 0,
                'avg_title_length' => 0
            ];
        }
    }, 300);
}

function getNetworkData($type = 'coauthors', $journal_id = null, $publisher = null, $rumpunilmu = null, $year = null, $limit = 50) {
    $cacheKey = "network_{$type}_" . ($journal_id ?: 'global') . "_" . ($publisher ?: 'all') . "_" . ($rumpunilmu ?: 'all') . "_" . ($year ?: 'all') . "_$limit";
    
    return getCached($cacheKey, function() use ($type, $journal_id, $publisher, $rumpunilmu, $year, $limit) {
        try {
            switch ($type) {
                case 'coauthors':
                case 'author':
                    return getCoauthorNetwork($limit, $journal_id, $publisher, $rumpunilmu, $year);
                    
                case 'subjects':
                case 'subject':
                    return getSubjectNetwork($limit, $journal_id, $publisher, $rumpunilmu, $year);
                    
                case 'author_subject':
                case 'author-subject':
                    return getAuthorSubjectNetwork($limit, $journal_id, $publisher, $rumpunilmu, $year);
                    
                case 'bubble':
                    return getBubbleChartData($limit, $journal_id, $publisher, $rumpunilmu, $year);
                    
                case 'timeline':
                    return getTimelineNetworkData($limit, $journal_id, $publisher, $rumpunilmu);
                    
                case 'yearly_network':
                    return getYearlyNetworkComparison($journal_id, $publisher, $rumpunilmu);
                    
                default:
                    return getCoauthorNetwork($limit, $journal_id, $publisher, $rumpunilmu, $year);
            }
        } catch (Exception $e) {
            logError("getNetworkData error: " . $e->getMessage());
            return [
                'nodes' => [],
                'links' => [],
                'type' => $type,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }, 600);
}

function getCoauthorNetwork($limit = 100, $journal_id = null, $publisher = null, $rumpunilmu = null, $year = null) {
    try {
        // Build base query dengan filter
        $authorQuery = "
            SELECT 
                a.id as node_id,
                a.name as label,
                'author' as type,
                COUNT(ra.record_id) as size,
                COUNT(DISTINCT r.journal_id) as journals_count
            FROM authors a
            JOIN record_authors ra ON a.id = ra.author_id
            JOIN oai_records r ON ra.record_id = r.id
            JOIN journals j ON r.journal_id = j.id
        ";
        
        $whereConditions = [];
        $authorParams = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $authorParams[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $authorParams[] = $publisher;
        }
        
        if ($year) {
            $whereConditions[] = "r.pub_year = ?";
            $authorParams[] = $year;
        }
        
        // Filter rumpun ilmu (jika ada relasi dengan journals)
        if ($rumpunilmu) {
            // Asumsi ada field subject di tabel journals yang merujuk ke rumpun ilmu
            // Sesuaikan dengan struktur database Anda
            $whereConditions[] = "j.subject = ?";
            $authorParams[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $authorQuery .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        // LIMIT tidak bisa menggunakan placeholder, jadi kita concat langsung
        $authorQuery .= " GROUP BY a.id ORDER BY size DESC LIMIT " . intval($limit);
        
        $authors = q($authorQuery, $authorParams)->fetchAll();
        
        if (empty($authors)) {
            return [
                'nodes' => [],
                'links' => [],
                'type' => 'coauthorship',
                'status' => 'success',
                'message' => 'No author data found'
            ];
        }
        
        // Ambil edges coauthorship
        $authorIds = array_column($authors, 'node_id');
        if (count($authorIds) === 0) {
            return [
                'nodes' => [],
                'links' => [],
                'type' => 'coauthorship',
                'status' => 'success'
            ];
        }
        
        $placeholders = str_repeat('?,', count($authorIds) - 1) . '?';
        
        $edgeQuery = "
            SELECT 
                author_a as source,
                author_b as target,
                weight
            FROM coauthor_edges ce
            JOIN oai_records r ON ce.journal_id = r.journal_id
            JOIN journals j ON r.journal_id = j.id
            WHERE author_a IN ($placeholders) 
            AND author_b IN ($placeholders)
        ";
        
        $edgeParams = array_merge($authorIds, $authorIds);
        
        $edgeConditions = [];
        if ($journal_id) {
            $edgeConditions[] = "r.journal_id = ?";
            $edgeParams[] = $journal_id;
        }
        
        if ($publisher) {
            $edgeConditions[] = "j.publisher = ?";
            $edgeParams[] = $publisher;
        }
        
        if ($year) {
            $edgeConditions[] = "r.pub_year = ?";
            $edgeParams[] = $year;
        }
        
        if ($rumpunilmu) {
            $edgeConditions[] = "j.subject = ?";
            $edgeParams[] = $rumpunilmu;
        }
        
        if (!empty($edgeConditions)) {
            $edgeQuery .= " AND " . implode(" AND ", $edgeConditions);
        }
        
        $edges = q($edgeQuery, $edgeParams)->fetchAll();
        
        // Format untuk D3.js
        $nodes = [];
        foreach ($authors as $author) {
            $nodes[] = [
                'id' => 'author_' . $author['node_id'],
                'name' => $author['label'],
                'group' => 1,
                'value' => (int)$author['size'],
                'type' => 'author',
                'details' => [
                    'papers' => $author['size'],
                    'journals' => $author['journals_count']
                ]
            ];
        }
        
        $links = [];
        foreach ($edges as $edge) {
            $links[] = [
                'source' => 'author_' . $edge['source'],
                'target' => 'author_' . $edge['target'],
                'value' => (int)$edge['weight']
            ];
        }
        
        return [
            'nodes' => $nodes,
            'links' => $links,
            'type' => 'coauthorship',
            'total_authors' => count($authors),
            'total_connections' => count($edges),
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getCoauthorNetwork error: " . $e->getMessage());
        return [
            'nodes' => [],
            'links' => [],
            'type' => 'coauthorship',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getSubjectNetwork($limit = 100, $journal_id = null, $publisher = null, $rumpunilmu = null, $year = null) {
    try {
        // Query untuk mendapatkan subject dengan filter
        $subjectQuery = "
            SELECT 
                s.id as node_id,
                s.label,
                'subject' as type,
                COUNT(rs.record_id) as size,
                COUNT(DISTINCT r.journal_id) as journals_count
            FROM subjects s
            JOIN record_subjects rs ON s.id = rs.subject_id
            JOIN oai_records r ON rs.record_id = r.id
            JOIN journals j ON r.journal_id = j.id
        ";
        
        $whereConditions = [];
        $subjectParams = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $subjectParams[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $subjectParams[] = $publisher;
        }
        
        if ($year) {
            $whereConditions[] = "r.pub_year = ?";
            $subjectParams[] = $year;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $subjectParams[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $subjectQuery .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $subjectQuery .= " GROUP BY s.id ORDER BY size DESC LIMIT " . (int)$limit;
        
        $subjects = q($subjectQuery, $subjectParams)->fetchAll();
        
        if (empty($subjects)) {
            return [
                'nodes' => [],
                'links' => [],
                'type' => 'subject_cooccurrence',
                'status' => 'success',
                'message' => 'No subject data found'
            ];
        }
        
        // Format nodes
        $nodes = [];
        foreach ($subjects as $subject) {
            $nodes[] = [
                'id' => 'subject_' . $subject['node_id'],
                'name' => $subject['label'],
                'group' => 2,
                'value' => (int)$subject['size'],
                'type' => 'subject',
                'details' => [
                    'occurrences' => $subject['size'],
                    'journals' => $subject['journals_count']
                ]
            ];
        }
        
        // Untuk edges, kita bisa query dari subject_edges table
        $subjectIds = array_column($subjects, 'node_id');
        $links = [];
        
        if (count($subjectIds) > 1) {
            $placeholders = str_repeat('?,', count($subjectIds) - 1) . '?';
            
            $edgeQuery = "
                SELECT 
                    subject_a as source,
                    subject_b as target,
                    weight
                FROM subject_edges se
                JOIN oai_records r ON se.journal_id = r.journal_id
                JOIN journals j ON r.journal_id = j.id
                WHERE subject_a IN ($placeholders) 
                AND subject_b IN ($placeholders)
            ";
            
            $edgeParams = array_merge($subjectIds, $subjectIds);
            
            $edgeConditions = [];
            if ($journal_id) {
                $edgeConditions[] = "r.journal_id = ?";
                $edgeParams[] = $journal_id;
            }
            
            if ($publisher) {
                $edgeConditions[] = "j.publisher = ?";
                $edgeParams[] = $publisher;
            }
            
            if ($year) {
                $edgeConditions[] = "r.pub_year = ?";
                $edgeParams[] = $year;
            }
            
            if ($rumpunilmu) {
                $edgeConditions[] = "j.subject = ?";
                $edgeParams[] = $rumpunilmu;
            }
            
            if (!empty($edgeConditions)) {
                $edgeQuery .= " AND " . implode(" AND ", $edgeConditions);
            }
            
            $edges = q($edgeQuery, $edgeParams)->fetchAll();
            
            foreach ($edges as $edge) {
                $links[] = [
                    'source' => 'subject_' . $edge['source'],
                    'target' => 'subject_' . $edge['target'],
                    'value' => (int)$edge['weight']
                ];
            }
        }
        
        return [
            'nodes' => $nodes,
            'links' => $links,
            'type' => 'subject_cooccurrence',
            'total_subjects' => count($subjects),
            'total_connections' => count($links),
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getSubjectNetwork error: " . $e->getMessage());
        return [
            'nodes' => [],
            'links' => [],
            'type' => 'subject_cooccurrence',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getAuthorSubjectNetwork($limit = 50, $journal_id = null, $publisher = null, $rumpunilmu = null, $year = null) {
    try {
        // Ambil data author
        $authorQuery = "
            SELECT 
                a.id as node_id,
                a.name as label,
                'author' as type,
                COUNT(DISTINCT ra.record_id) as size
            FROM authors a
            JOIN record_authors ra ON a.id = ra.author_id
            JOIN oai_records r ON ra.record_id = r.id
            JOIN journals j ON r.journal_id = j.id
        ";
        
        $whereConditions = [];
        $params = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $params[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $params[] = $publisher;
        }
        
        if ($year) {
            $whereConditions[] = "r.pub_year = ?";
            $params[] = $year;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $params[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $authorQuery .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $authorQuery .= " GROUP BY a.id ORDER BY size DESC LIMIT " . intval($limit/2);
        
        $authors = q($authorQuery, $params)->fetchAll();
        
        // Ambil data subject
        $subjectQuery = "
            SELECT 
                s.id as node_id,
                s.label,
                'subject' as type,
                COUNT(DISTINCT rs.record_id) as size
            FROM subjects s
            JOIN record_subjects rs ON s.id = rs.subject_id
            JOIN oai_records r ON rs.record_id = r.id
            JOIN journals j ON r.journal_id = j.id
        ";
        
        $whereConditions = [];
        $params = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $params[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $params[] = $publisher;
        }
        
        if ($year) {
            $whereConditions[] = "r.pub_year = ?";
            $params[] = $year;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $params[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $subjectQuery .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $subjectQuery .= " GROUP BY s.id ORDER BY size DESC LIMIT " . intval($limit/2);
        
        $subjects = q($subjectQuery, $params)->fetchAll();
        
        if (empty($authors) && empty($subjects)) {
            return [
                'nodes' => [],
                'links' => [],
                'type' => 'author_subject_bipartite',
                'status' => 'success',
                'message' => 'No data found'
            ];
        }
        
        // Format nodes
        $nodes = [];
        foreach ($authors as $author) {
            $nodes[] = [
                'id' => 'author_' . $author['node_id'],
                'name' => $author['label'],
                'group' => 1,
                'value' => (int)$author['size'],
                'type' => 'author',
                'details' => ['papers' => $author['size']]
            ];
        }
        
        foreach ($subjects as $subject) {
            $nodes[] = [
                'id' => 'subject_' . $subject['node_id'],
                'name' => $subject['label'],
                'group' => 2,
                'value' => (int)$subject['size'],
                'type' => 'subject',
                'details' => ['occurrences' => $subject['size']]
            ];
        }
        
        // Ambil edges author-subject
        $links = [];
        if (!empty($authors) && !empty($subjects)) {
            $authorIds = array_column($authors, 'node_id');
            $subjectIds = array_column($subjects, 'node_id');
            
            if (!empty($authorIds) && !empty($subjectIds)) {
                $authorPlaceholders = str_repeat('?,', count($authorIds) - 1) . '?';
                $subjectPlaceholders = str_repeat('?,', count($subjectIds) - 1) . '?';
                
                $edgeQuery = "
                    SELECT 
                        ase.author_id as source,
                        ase.subject_id as target,
                        ase.weight
                    FROM author_subject_edges ase
                    JOIN oai_records r ON ase.journal_id = r.journal_id
                    JOIN journals j ON r.journal_id = j.id
                    WHERE ase.author_id IN ($authorPlaceholders) 
                    AND ase.subject_id IN ($subjectPlaceholders)
                ";
                
                $edgeParams = array_merge($authorIds, $subjectIds);
                
                $edgeConditions = [];
                if ($journal_id) {
                    $edgeConditions[] = "r.journal_id = ?";
                    $edgeParams[] = $journal_id;
                }
                
                if ($publisher) {
                    $edgeConditions[] = "j.publisher = ?";
                    $edgeParams[] = $publisher;
                }
                
                if ($year) {
                    $edgeConditions[] = "r.pub_year = ?";
                    $edgeParams[] = $year;
                }
                
                if ($rumpunilmu) {
                    $edgeConditions[] = "j.subject = ?";
                    $edgeParams[] = $rumpunilmu;
                }
                
                if (!empty($edgeConditions)) {
                    $edgeQuery .= " AND " . implode(" AND ", $edgeConditions);
                }
                
                $edges = q($edgeQuery, $edgeParams)->fetchAll();
                
                foreach ($edges as $edge) {
                    $links[] = [
                        'source' => 'author_' . $edge['source'],
                        'target' => 'subject_' . $edge['target'],
                        'value' => (int)$edge['weight']
                    ];
                }
            }
        }
        
        return [
            'nodes' => $nodes,
            'links' => $links,
            'type' => 'author_subject_bipartite',
            'total_authors' => count($authors),
            'total_subjects' => count($subjects),
            'total_connections' => count($links),
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getAuthorSubjectNetwork error: " . $e->getMessage());
        return [
            'nodes' => [],
            'links' => [],
            'type' => 'author_subject_bipartite',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getBubbleChartData($limit = 50, $journal_id = null, $publisher = null, $rumpunilmu = null, $year = null) {
    try {
        $query = "
            SELECT 
                s.label as name,
                COUNT(rs.record_id) as value,
                COUNT(DISTINCT r.journal_id) as journal_count,
                COUNT(DISTINCT ra.author_id) as author_count
            FROM subjects s
            JOIN record_subjects rs ON s.id = rs.subject_id
            JOIN oai_records r ON rs.record_id = r.id
            JOIN journals j ON r.journal_id = j.id
        ";
        
        $whereConditions = [];
        $params = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $params[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $params[] = $publisher;
        }
        
        if ($year) {
            $whereConditions[] = "r.pub_year = ?";
            $params[] = $year;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $params[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $query .= " GROUP BY s.id ORDER BY value DESC LIMIT " . intval($limit);
        
        $data = q($query, $params)->fetchAll();
        
        return [
            'bubbles' => $data ?: [],
            'type' => 'bubble_chart',
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getBubbleChartData error: " . $e->getMessage());
        return [
            'bubbles' => [],
            'type' => 'bubble_chart',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getTimelineNetworkData($limit = 30, $journal_id = null, $publisher = null, $rumpunilmu = null) {
    try {
        $query = "
            SELECT 
                r.pub_year as year,
                COUNT(DISTINCT ra.author_id) as authors_count,
                COUNT(DISTINCT rs.subject_id) as subjects_count,
                COUNT(DISTINCT r.id) as publications_count,
                COUNT(DISTINCT CASE WHEN r.doi_best IS NOT NULL THEN r.id END) as doi_count
            FROM oai_records r
            JOIN journals j ON r.journal_id = j.id
            LEFT JOIN record_authors ra ON r.id = ra.record_id
            LEFT JOIN record_subjects rs ON r.id = rs.record_id
            WHERE r.pub_year IS NOT NULL
        ";
        
        $whereConditions = [];
        $params = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $params[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $params[] = $publisher;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $params[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $query .= " AND " . implode(" AND ", $whereConditions);
        }
        
        $query .= " GROUP BY r.pub_year ORDER BY r.pub_year";
        
        $timelineData = q($query, $params)->fetchAll();
        
        return [
            'timeline' => $timelineData ?: [],
            'type' => 'timeline_evolution',
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getTimelineNetworkData error: " . $e->getMessage());
        return [
            'timeline' => [],
            'type' => 'timeline_evolution',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getYearlyNetworkComparison($journal_id = null, $publisher = null, $rumpunilmu = null) {
    try {
        // Dapatkan data coauthor network per tahun
        $query = "
            SELECT 
                r.pub_year as year,
                COUNT(DISTINCT ce.author_a) as unique_authors,
                COUNT(ce.author_a) as total_coauthorships,
                AVG(ce.weight) as avg_collaboration_strength,
                MAX(ce.weight) as max_collaboration_strength
            FROM coauthor_edges ce
            JOIN oai_records r ON ce.journal_id = r.journal_id
            JOIN journals j ON r.journal_id = j.id
            WHERE r.pub_year IS NOT NULL
        ";
        
        $whereConditions = [];
        $params = [];
        
        if ($journal_id) {
            $whereConditions[] = "r.journal_id = ?";
            $params[] = $journal_id;
        }
        
        if ($publisher) {
            $whereConditions[] = "j.publisher = ?";
            $params[] = $publisher;
        }
        
        if ($rumpunilmu) {
            $whereConditions[] = "j.subject = ?";
            $params[] = $rumpunilmu;
        }
        
        if (!empty($whereConditions)) {
            $query .= " AND " . implode(" AND ", $whereConditions);
        }
        
        $query .= " GROUP BY r.pub_year ORDER BY r.pub_year";
        
        $yearlyData = q($query, $params)->fetchAll();
        
        return [
            'yearly_comparison' => $yearlyData ?: [],
            'type' => 'yearly_network_comparison',
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("getYearlyNetworkComparison error: " . $e->getMessage());
        return [
            'yearly_comparison' => [],
            'type' => 'yearly_network_comparison',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// ========== MAIN DATA FETCHING ==========
try {
    $basicStats = getBasicStats();
    $doiPercentage = isset($basicStats['active_records']) && $basicStats['active_records'] > 0 
        ? round(($basicStats['doi_records'] / $basicStats['active_records'] * 100), 1) 
        : 0;
} catch (Exception $e) {
    $basicStats = [
        'total_journals' => 0,
        'active_journals' => 0,
        'total_records' => 0,
        'active_records' => 0,
        'total_authors' => 0,
        'total_publishers' => 0,
        'total_subjects' => 0,
        'doi_records' => 0,
        'total_harvests' => 0,
        'languages_count' => 0
    ];
    $doiPercentage = 0;
    logError("Main data fetching error: " . $e->getMessage());
}

// ========== HTML OUTPUT ==========
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Insight - Bibliometric Analysis</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- D3.js -->
    <script src="https://d3js.org/d3.v7.min.js"></script>
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --info-color: #1abc9c;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            padding-top: 20px;
        }
        
        .stat-card {
            border-radius: 10px;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .network-viz-container {
            height: 500px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
            position: relative;
            overflow: hidden;
        }
        
        #networkViz {
            width: 100%;
            height: 100%;
        }
        
        .node {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .node:hover {
            stroke: #000;
            stroke-width: 2px;
        }
        
        .node-author {
            fill: #3498db;
        }
        
        .node-subject {
            fill: #2ecc71;
        }
        
        .link {
            stroke: #999;
            stroke-opacity: 0.6;
        }
        
        .loading-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        
        .table-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .tooltip-network {
            position: absolute;
            padding: 10px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #ddd;
            border-radius: 5px;
            pointer-events: none;
            font-size: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 100;
        }
        
        .viz-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
            background: rgba(255, 255, 255, 0.9);
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .error-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .bubble-node {
            stroke: #fff;
            stroke-width: 2px;
        }
        
        .timeline-path {
            fill: none;
            stroke: steelblue;
            stroke-width: 2px;
        }
    </style>
</head>
<body>
    <!-- Error Display -->
    <div id="errorAlert" class="error-alert alert alert-danger alert-dismissible fade show" style="display: none;">
        <button type="button" class="btn-close" onclick="hideError()"></button>
        <strong>Error:</strong> <span id="errorMessage"></span>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                    <i class="bi bi-journal-text text-white"></i>
                </div>
                <span class="fw-bold fs-4 text-primary">JournalHub</span>
            </a>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Dashboard</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="globalinsight.php">Global Insight</a>
                    </li>
                    <li class="nav-item">
                        <button id="themeToggle" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-moon-stars theme-icon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container mb-5">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-2">
                            <i class="bi bi-globe-americas text-primary me-2"></i>
                            Global Bibliometric Insight
                        </h1>
                        <p class="text-muted mb-0">
                            Comprehensive bibliometric analysis dashboard
                        </p>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" id="clearCacheBtn" title="Clear Cache">
                            <i class="bi bi-arrow-clockwise"></i> Clear Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="filter-section">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Publisher</label>
                            <select class="form-select" id="filterPublisher">
                                <option value="">All Publishers</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Rumpun Ilmu</label>
                            <select class="form-select" id="filterRumpunIlmu">
                                <option value="">All Rumpun Ilmu</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year</label>
                            <select class="form-select" id="filterYear">
                                <option value="">All Years</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Journal</label>
                            <select class="form-select" id="filterJournal">
                                <option value="">All Journals</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card stat-card border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted mb-1">Journals</h6>
                                <h3 class="mb-0"><?= number_format($basicStats['total_journals']) ?></h3>
                                <small class="text-success">
                                    <?= number_format($basicStats['active_journals']) ?> active
                                </small>
                            </div>
                            <div class="text-primary">
                                <i class="bi bi-journals" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stat-card border-start border-success border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted mb-1">Records</h6>
                                <h3 class="mb-0"><?= number_format($basicStats['total_records']) ?></h3>
                                <small class="text-success">
                                    <?= number_format($basicStats['active_records']) ?> active
                                </small>
                            </div>
                            <div class="text-success">
                                <i class="bi bi-file-text" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stat-card border-start border-info border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted mb-1">Authors</h6>
                                <h3 class="mb-0"><?= number_format($basicStats['total_authors']) ?></h3>
                                <small class="text-muted">
                                    Unique authors
                                </small>
                            </div>
                            <div class="text-info">
                                <i class="bi bi-people" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stat-card border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted mb-1">DOI Coverage</h6>
                                <h3 class="mb-0"><?= $doiPercentage ?>%</h3>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $doiPercentage ?>%"></div>
                                </div>
                            </div>
                            <div class="text-warning">
                                <i class="bi bi-upc-scan" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Network Visualization -->
        <div class="card mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">
                        <i class="bi bi-diagram-3 text-primary me-2"></i>
                        Network Analysis
                    </h5>
                </div>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm w-auto" id="networkType">
                        <option value="subject" selected>Subject Network (Subject–Subject)</option>
                        <option value="author">Co-author Network (Author–Author)</option>
                        <option value="author_subject">Author–Subject Network</option>
                        <option value="bubble">Topic Bubble Chart</option>
                        <option value="timeline">Timeline Network Evolution</option>
                        <option value="yearly_network">Yearly Network Comparison</option>
                    </select>
                    <select class="form-select form-select-sm w-auto" id="networkLimit">
                        <option value="30">30 nodes</option>
                        <option value="50" selected>50 nodes</option>
                        <option value="100">100 nodes</option>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" id="refreshNetwork">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body p-0 position-relative">
                <div class="network-viz-container">
                    <div class="loading-placeholder" id="networkLoading">
                        <div class="text-center">
                            <div class="spinner-border text-primary mb-3"></div>
                            <p>Loading network visualization...</p>
                        </div>
                    </div>
                    <svg id="networkViz"></svg>
                    <div id="networkTooltip" class="tooltip-network" style="display: none;"></div>
                    <div class="viz-controls">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary" id="zoomIn">
                                <i class="bi bi-zoom-in"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="zoomOut">
                                <i class="bi bi-zoom-out"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="resetView">
                                <i class="bi bi-fullscreen"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="p-3 bg-light border-top">
                    <div class="row align-items-center">
                        <div class="col">
                            <small class="text-muted">
                                <span id="nodeCount">0</span> nodes, 
                                <span id="linkCount">0</span> connections
                            </small>
                        </div>
                        <div class="col-auto">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="showLabels" checked>
                                <label class="form-check-label" for="showLabels">Show Labels</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Data Sections -->
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-trophy text-primary me-2"></i>
                            Top Authors
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Author</th>
                                        <th class="text-end">Papers</th>
                                    </tr>
                                </thead>
                                <tbody id="topAuthorsTable">
                                    <tr><td colspan="3" class="text-center py-4">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-journals text-primary me-2"></i>
                            Top Journals
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Journal</th>
                                        <th class="text-end">Records</th>
                                    </tr>
                                </thead>
                                <tbody id="topJournalsTable">
                                    <tr><td colspan="3" class="text-center py-4">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include 'footer.php';?>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Global variables
    let networkSimulation = null;
    let currentNetworkType = 'subject';
    let currentLimit = 50;
    let currentFilters = {
        publisher: '',
        rumpunilmu: '',
        year: '',
        journal: ''
    };

    // ========== THEME MANAGEMENT ==========
    function initTheme() {
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('bs-theme') || 'light';
        
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        updateThemeIcon(savedTheme);
        
        themeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('bs-theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }
    
    function updateThemeIcon(theme) {
        const icon = document.querySelector('#themeToggle i');
        if (theme === 'dark') {
            icon.classList.remove('bi-moon-stars');
            icon.classList.add('bi-sun');
        } else {
            icon.classList.remove('bi-sun');
            icon.classList.add('bi-moon-stars');
        }
    }

    // ========== ERROR HANDLING ==========
    function showError(message) {
        const errorAlert = document.getElementById('errorAlert');
        const errorMessage = document.getElementById('errorMessage');
        errorMessage.textContent = message;
        errorAlert.style.display = 'block';
        
        // Auto-hide after 10 seconds
        setTimeout(hideError, 10000);
    }
    
    function hideError() {
        document.getElementById('errorAlert').style.display = 'none';
    }

    // ========== FILTER MANAGEMENT ==========
    async function loadFilterOptions() {
        try {
            const data = await loadData('filter-options');
            
            if (data.publishers) {
                const select = document.getElementById('filterPublisher');
                data.publishers.forEach(p => {
                    const option = document.createElement('option');
                    option.value = p.name;
                    option.textContent = p.name;
                    select.appendChild(option);
                });
            }
            
            if (data.rumpunIlmu) {
                const select = document.getElementById('filterRumpunIlmu');
                data.rumpunIlmu.forEach(r => {
                    const option = document.createElement('option');
                    option.value = r.id;
                    option.textContent = r.name;
                    select.appendChild(option);
                });
            }
            
            if (data.years) {
                const select = document.getElementById('filterYear');
                data.years.forEach(y => {
                    const option = document.createElement('option');
                    option.value = y.year;
                    option.textContent = y.year;
                    select.appendChild(option);
                });
            }
            
            if (data.journals) {
                const select = document.getElementById('filterJournal');
                data.journals.forEach(j => {
                    const option = document.createElement('option');
                    option.value = j.id;
                    option.textContent = j.name;
                    select.appendChild(option);
                });
            }
            
        } catch (error) {
            console.error('Error loading filter options:', error);
        }
    }
    
    function updateFilters() {
        currentFilters = {
            publisher: document.getElementById('filterPublisher').value,
            rumpunilmu: document.getElementById('filterRumpunIlmu').value,
            year: document.getElementById('filterYear').value,
            journal: document.getElementById('filterJournal').value
        };
        
        loadNetwork();
    }

    // ========== D3 NETWORK VISUALIZATION ==========
    function initNetworkVisualization() {
        const svg = d3.select('#networkViz');
        svg.selectAll('*').remove();
        
        const width = svg.node().getBoundingClientRect().width;
        const height = svg.node().getBoundingClientRect().height;
        
        // Create container for zoomable content
        const container = svg.append('g');
        
        // Set up zoom behavior
        const zoom = d3.zoom()
            .scaleExtent([0.1, 4])
            .on('zoom', (event) => {
                container.attr('transform', event.transform);
            });
        
        svg.call(zoom);
        
        // Zoom controls
        document.getElementById('zoomIn').addEventListener('click', () => {
            svg.transition().call(zoom.scaleBy, 1.2);
        });
        
        document.getElementById('zoomOut').addEventListener('click', () => {
            svg.transition().call(zoom.scaleBy, 0.8);
        });
        
        document.getElementById('resetView').addEventListener('click', () => {
            svg.transition()
                .duration(750)
                .call(zoom.transform, d3.zoomIdentity);
        });
        
        return { svg, container, width, height };
    }

    function renderNetwork(data) {
        if (!data || data.status === 'error' || ((!data.nodes || data.nodes.length === 0) && (!data.bubbles || data.bubbles.length === 0) && (!data.timeline || data.timeline.length === 0) && (!data.yearly_comparison || data.yearly_comparison.length === 0))) {
            const svg = d3.select('#networkViz');
            svg.selectAll('*').remove();
            
            const width = svg.node().getBoundingClientRect().width;
            const height = svg.node().getBoundingClientRect().height;
            
            svg.append('text')
                .attr('x', width / 2)
                .attr('y', height / 2)
                .attr('text-anchor', 'middle')
                .attr('dominant-baseline', 'middle')
                .text(data && data.message ? data.message : 'No network data available');
            
            document.getElementById('nodeCount').textContent = '0';
            document.getElementById('linkCount').textContent = '0';
            
            if (data && data.status === 'error') {
                showError(data.message || 'Failed to load network data');
            }
            
            return;
        }
        
        // Berdasarkan tipe visualisasi
        const type = data.type || currentNetworkType;
        
        if (type === 'bubble_chart') {
            renderBubbleChart(data);
        } else if (type === 'timeline_evolution') {
            renderTimelineChart(data);
        } else if (type === 'yearly_network_comparison') {
            renderYearlyComparisonChart(data);
        } else {
            renderForceDirectedNetwork(data);
        }
    }

    function renderForceDirectedNetwork(data) {
        const { svg, container, width, height } = initNetworkVisualization();
        
        // Update counts display
        document.getElementById('nodeCount').textContent = data.nodes ? data.nodes.length : 0;
        document.getElementById('linkCount').textContent = data.links ? data.links.length : 0;
        
        // Stop previous simulation
        if (networkSimulation) {
            networkSimulation.stop();
        }
        
        // Create force simulation
        const simulation = d3.forceSimulation(data.nodes)
            .force('link', d3.forceLink(data.links)
                .id(d => d.id)
                .distance(d => 150 / (d.value || 1))
                .strength(d => (d.value || 1) * 0.05))
            .force('charge', d3.forceManyBody()
                .strength(-200))
            .force('center', d3.forceCenter(width / 2, height / 2))
            .force('collision', d3.forceCollide()
                .radius(d => Math.sqrt(d.value || 1) * 3 + 15));
        
        networkSimulation = simulation;
        
        // Create links
        const link = container.append('g')
            .selectAll('line')
            .data(data.links)
            .enter()
            .append('line')
            .attr('class', 'link')
            .attr('stroke-width', d => Math.sqrt(d.value || 1));
        
        // Create nodes
        const node = container.append('g')
            .selectAll('circle')
            .data(data.nodes)
            .enter()
            .append('circle')
            .attr('class', d => `node node-${d.type}`)
            .attr('r', d => Math.sqrt(d.value || 1) * 3 + 10)
            .call(d3.drag()
                .on('start', dragstarted)
                .on('drag', dragged)
                .on('end', dragended));
        
        // Create labels
        const label = container.append('g')
            .selectAll('text')
            .data(data.nodes)
            .enter()
            .append('text')
            .attr('class', 'node-label')
            .attr('dx', 12)
            .attr('dy', '.35em')
            .style('font-size', '10px')
            .style('pointer-events', 'none')
            .style('display', document.getElementById('showLabels').checked ? 'block' : 'none')
            .text(d => {
                const name = d.name || 'Unknown';
                return name.length > 20 ? name.substring(0, 20) + '...' : name;
            });
        
        // Tooltip
        const tooltip = d3.select('#networkTooltip');
        
        node.on('mouseover', function(event, d) {
            tooltip.style('display', 'block')
                .html(`
                    <strong>${d.name || 'Unknown'}</strong><br>
                    Type: ${d.type || 'unknown'}<br>
                    Value: ${d.value || 0}
                    ${d.details ? `<br>Papers: ${d.details.papers || d.value}` : ''}
                `);
        })
        .on('mousemove', function(event) {
            tooltip.style('left', (event.pageX + 10) + 'px')
                .style('top', (event.pageY - 10) + 'px');
        })
        .on('mouseout', function() {
            tooltip.style('display', 'none');
        });
        
        // Update positions on simulation tick
        simulation.on('tick', () => {
            link
                .attr('x1', d => d.source.x)
                .attr('y1', d => d.source.y)
                .attr('x2', d => d.target.x)
                .attr('y2', d => d.target.y);
            
            node
                .attr('cx', d => d.x)
                .attr('cy', d => d.y);
            
            label
                .attr('x', d => d.x)
                .attr('y', d => d.y);
        });
        
        // Drag functions
        function dragstarted(event, d) {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            d.fx = d.x;
            d.fy = d.y;
        }
        
        function dragged(event, d) {
            d.fx = event.x;
            d.fy = event.y;
        }
        
        function dragended(event, d) {
            if (!event.active) simulation.alphaTarget(0);
            d.fx = null;
            d.fy = null;
        }
        
        // Show/hide labels control
        document.getElementById('showLabels').addEventListener('change', (e) => {
            label.style('display', e.target.checked ? 'block' : 'none');
        });
    }

    function renderBubbleChart(data) {
        const { svg, container, width, height } = initNetworkVisualization();
        
        const bubbles = data.bubbles || [];
        document.getElementById('nodeCount').textContent = bubbles.length;
        document.getElementById('linkCount').textContent = '0';
        
        // Skala untuk radius
        const maxValue = d3.max(bubbles, d => d.value) || 1;
        const radiusScale = d3.scaleSqrt()
            .domain([0, maxValue])
            .range([5, 60]);
        
        // Skala untuk warna
        const colorScale = d3.scaleOrdinal(d3.schemeCategory10);
        
        // Pack layout
        const pack = d3.pack()
            .size([width, height])
            .padding(3);
        
        const root = d3.hierarchy({children: bubbles})
            .sum(d => d.value);
        
        const nodes = pack(root).children;
        
        // Draw bubbles
        const bubble = container.selectAll('g')
            .data(nodes)
            .enter()
            .append('g')
            .attr('transform', d => `translate(${d.x},${d.y})`);
        
        bubble.append('circle')
            .attr('class', 'bubble-node')
            .attr('r', d => d.r)
            .attr('fill', (d, i) => colorScale(i));
        
        bubble.append('text')
            .attr('text-anchor', 'middle')
            .attr('dy', '.3em')
            .style('font-size', '10px')
            .style('pointer-events', 'none')
            .style('fill', '#fff')
            .text(d => {
                const name = d.data.name || 'Unknown';
                return name.length > 15 ? name.substring(0, 15) + '...' : name;
            });
        
        // Tooltip
        const tooltip = d3.select('#networkTooltip');
        
        bubble.on('mouseover', function(event, d) {
            tooltip.style('display', 'block')
                .html(`
                    <strong>${d.data.name || 'Unknown'}</strong><br>
                    Occurrences: ${d.data.value}<br>
                    Journals: ${d.data.journal_count || 0}<br>
                    Authors: ${d.data.author_count || 0}
                `);
        })
        .on('mousemove', function(event) {
            tooltip.style('left', (event.pageX + 10) + 'px')
                .style('top', (event.pageY - 10) + 'px');
        })
        .on('mouseout', function() {
            tooltip.style('display', 'none');
        });
    }

    function renderTimelineChart(data) {
        const { svg, container, width, height } = initNetworkVisualization();
        
        const timeline = data.timeline || [];
        document.getElementById('nodeCount').textContent = timeline.length;
        document.getElementById('linkCount').textContent = '0';
        
        if (timeline.length === 0) return;
        
        // Margins
        const margin = { top: 20, right: 30, bottom: 30, left: 60 };
        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;
        
        // Skala
        const xScale = d3.scaleLinear()
            .domain(d3.extent(timeline, d => d.year))
            .range([0, innerWidth]);
        
        const yScale = d3.scaleLinear()
            .domain([0, d3.max(timeline, d => Math.max(d.authors_count, d.publications_count, d.subjects_count))])
            .range([innerHeight, 0]);
        
        // Garis
        const line = d3.line()
            .x(d => xScale(d.year))
            .y(d => yScale(d.authors_count));
        
        // Buat grup untuk chart
        const chart = container.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);
        
        // Grid lines
        chart.append('g')
            .attr('class', 'grid')
            .call(d3.axisLeft(yScale)
                .tickSize(-innerWidth)
                .tickFormat(''));
        
        // Axes
        chart.append('g')
            .attr('transform', `translate(0,${innerHeight})`)
            .call(d3.axisBottom(xScale).tickFormat(d3.format('d')));
        
        chart.append('g')
            .call(d3.axisLeft(yScale));
        
        // Line untuk authors
        chart.append('path')
            .datum(timeline)
            .attr('class', 'timeline-path')
            .attr('d', line);
        
        // Points
        chart.selectAll('.point')
            .data(timeline)
            .enter()
            .append('circle')
            .attr('class', 'node')
            .attr('cx', d => xScale(d.year))
            .attr('cy', d => yScale(d.authors_count))
            .attr('r', 5)
            .attr('fill', '#3498db');
        
        // Tooltip
        const tooltip = d3.select('#networkTooltip');
        
        chart.selectAll('circle')
            .on('mouseover', function(event, d) {
                tooltip.style('display', 'block')
                    .html(`
                        <strong>Year: ${d.year}</strong><br>
                        Authors: ${d.authors_count}<br>
                        Publications: ${d.publications_count}<br>
                        Subjects: ${d.subjects_count}<br>
                        DOI Coverage: ${d.doi_count}
                    `);
            })
            .on('mousemove', function(event) {
                tooltip.style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 10) + 'px');
            })
            .on('mouseout', function() {
                tooltip.style('display', 'none');
            });
    }

    function renderYearlyComparisonChart(data) {
        const { svg, container, width, height } = initNetworkVisualization();
        
        const yearlyData = data.yearly_comparison || [];
        document.getElementById('nodeCount').textContent = yearlyData.length;
        document.getElementById('linkCount').textContent = '0';
        
        if (yearlyData.length === 0) return;
        
        // Bar chart untuk perbandingan tahunan
        const margin = { top: 20, right: 30, bottom: 30, left: 60 };
        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;
        
        // Skala
        const xScale = d3.scaleBand()
            .domain(yearlyData.map(d => d.year))
            .range([0, innerWidth])
            .padding(0.1);
        
        const yScale = d3.scaleLinear()
            .domain([0, d3.max(yearlyData, d => d.unique_authors)])
            .range([innerHeight, 0]);
        
        // Buat grup untuk chart
        const chart = container.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);
        
        // Bars
        chart.selectAll('.bar')
            .data(yearlyData)
            .enter()
            .append('rect')
            .attr('class', 'node')
            .attr('x', d => xScale(d.year))
            .attr('y', d => yScale(d.unique_authors))
            .attr('width', xScale.bandwidth())
            .attr('height', d => innerHeight - yScale(d.unique_authors))
            .attr('fill', '#2ecc71');
        
        // Axes
        chart.append('g')
            .attr('transform', `translate(0,${innerHeight})`)
            .call(d3.axisBottom(xScale).tickFormat(d3.format('d')));
        
        chart.append('g')
            .call(d3.axisLeft(yScale));
        
        // Tooltip
        const tooltip = d3.select('#networkTooltip');
        
        chart.selectAll('rect')
            .on('mouseover', function(event, d) {
                tooltip.style('display', 'block')
                    .html(`
                        <strong>Year: ${d.year}</strong><br>
                        Unique Authors: ${d.unique_authors}<br>
                        Total Collaborations: ${d.total_coauthorships}<br>
                        Avg Strength: ${d.avg_collaboration_strength?.toFixed(2) || 0}<br>
                        Max Strength: ${d.max_collaboration_strength || 0}
                    `);
            })
            .on('mousemove', function(event) {
                tooltip.style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 10) + 'px');
            })
            .on('mouseout', function() {
                tooltip.style('display', 'none');
            });
    }

    // ========== DATA LOADING ==========
    async function loadData(endpoint, params = {}) {
        try {
            // Tambahkan filter ke params
            if (endpoint === 'network') {
                if (currentFilters.publisher) params.publisher = currentFilters.publisher;
                if (currentFilters.rumpunilmu) params.rumpunilmu = currentFilters.rumpunilmu;
                if (currentFilters.year) params.year = currentFilters.year;
                if (currentFilters.journal) params.journal = currentFilters.journal;
            }
            
            const urlParams = new URLSearchParams(params);
            const url = `?api=${endpoint}&${urlParams.toString()}&_=${Date.now()}`;
            
            console.log('Loading data from:', url);
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response');
            }
            
            const data = await response.json();
            
            // Check for error in response
            if (data && data.status === 'error') {
                throw new Error(data.message || 'API returned error');
            }
            
            return data;
            
        } catch (error) {
            console.error('Error loading data:', error);
            showError(`Failed to load data: ${error.message}`);
            return { status: 'error', message: error.message };
        }
    }

    async function loadNetwork() {
        const loadingEl = document.getElementById('networkLoading');
        const vizEl = document.getElementById('networkViz');
        
        loadingEl.style.display = 'flex';
        
        currentNetworkType = document.getElementById('networkType').value;
        currentLimit = parseInt(document.getElementById('networkLimit').value);
        
        const data = await loadData('network', {
            type: currentNetworkType,
            limit: currentLimit
        });
        
        loadingEl.style.display = 'none';
        renderNetwork(data);
    }

    async function loadTopAuthors() {
        const data = await loadData('top-authors');
        if (!data || data.status === 'error') return;
        
        const table = document.getElementById('topAuthorsTable');
        if (!table) return;
        
        table.innerHTML = '';
        
        if (data.length === 0) {
            table.innerHTML = '<tr><td colspan="3" class="text-center py-4">No author data found</td></tr>';
            return;
        }
        
        data.slice(0, 10).forEach((author, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="text-muted">${index + 1}</td>
                <td>
                    <div style="font-size: 0.85rem;">${author.name || 'Unknown'}</div>
                </td>
                <td class="text-end">
                    <span class="badge bg-success rounded-pill">${author.paper_count || 0}</span>
                </td>
            `;
            table.appendChild(row);
        });
    }

    async function loadTopJournals() {
        const data = await loadData('top-journals');
        if (!data || data.status === 'error') return;
        
        const table = document.getElementById('topJournalsTable');
        if (!table) return;
        
        table.innerHTML = '';
        
        if (data.length === 0) {
            table.innerHTML = '<tr><td colspan="3" class="text-center py-4">No journal data found</td></tr>';
            return;
        }
        
        data.slice(0, 10).forEach((journal, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="text-muted">${index + 1}</td>
                <td>
                    <div style="font-size: 0.85rem;">${journal.name || 'Unknown'}</div>
                    <small class="text-muted">${journal.publisher || 'Unknown publisher'}</small>
                </td>
                <td class="text-end">
                    <span class="badge bg-primary rounded-pill">${journal.record_count || 0}</span>
                </td>
            `;
            table.appendChild(row);
        });
    }

    async function clearCache() {
        try {
            const response = await fetch('?api=clear-cache&_=' + Date.now());
            const data = await response.json();
            
            if (data.success) {
                showError('Cache cleared successfully');
                // Reload all data
                setTimeout(() => {
                    loadFilterOptions();
                    loadNetwork();
                    loadTopAuthors();
                    loadTopJournals();
                }, 1000);
            } else {
                throw new Error(data.message || 'Failed to clear cache');
            }
        } catch (error) {
            showError('Error clearing cache: ' + error.message);
        }
    }

    // ========== INITIALIZATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize theme
        initTheme();
        
        // Load filter options
        loadFilterOptions();
        
        // Setup filter event listeners
        document.getElementById('filterPublisher').addEventListener('change', updateFilters);
        document.getElementById('filterRumpunIlmu').addEventListener('change', updateFilters);
        document.getElementById('filterYear').addEventListener('change', updateFilters);
        document.getElementById('filterJournal').addEventListener('change', updateFilters);
        
        // Load initial data
        setTimeout(() => {
            loadNetwork();
            loadTopAuthors();
            loadTopJournals();
        }, 100);
        
        // Event listeners
        document.getElementById('refreshNetwork').addEventListener('click', loadNetwork);
        document.getElementById('clearCacheBtn').addEventListener('click', clearCache);
        
        // Network controls
        document.getElementById('networkType').addEventListener('change', loadNetwork);
        document.getElementById('networkLimit').addEventListener('change', loadNetwork);
        
        // Auto-refresh every 2 minutes
        setInterval(() => {
            loadNetwork();
            loadTopAuthors();
            loadTopJournals();
        }, 120000);
    });
    </script>
</body>
</html>