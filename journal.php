<?php
/*
 * File: journal.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * License: The GNU General Public License, Version 3 (GPL-3.0)
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);

$journal = q("SELECT j.*, ri.nama_rumpun, p.name as publisher_name 
              FROM journals j 
              LEFT JOIN rumpunilmu ri ON j.rumpunilmu_id = ri.rumpunilmu_id
              LEFT JOIN publishers p ON j.publisher = p.name
              WHERE j.id=?", [$id])->fetch();
if (!$journal) die("Journal not found.");

$sum = q("
  SELECT
    COUNT(*) AS total,
    SUM(status='active') AS active,
    SUM(status='deleted') AS deleted,
    SUM(doi_best IS NOT NULL AND doi_best <> '' AND status='active') AS doi_present,
    MIN(pub_date) AS pub_earliest,
    MAX(pub_date) AS pub_latest
  FROM oai_records WHERE journal_id=?
", [$id])->fetch();

$doiCov = ((int)$sum['active'] > 0) ? ((int)$sum['doi_present'] / (int)$sum['active'] * 100.0) : 0.0;

$byYear = q("
  SELECT pub_year AS y,
         COUNT(*) AS total,
         SUM(status='active') AS active,
         SUM(status='deleted') AS deleted
  FROM oai_records
  WHERE journal_id=? AND pub_year IS NOT NULL
  GROUP BY pub_year
  ORDER BY pub_year DESC
", [$id])->fetchAll();

$byMonth = q("
  SELECT pub_month AS ym,
         COUNT(*) AS total,
         SUM(status='active') AS active,
         SUM(status='deleted') AS deleted
  FROM oai_records
  WHERE journal_id=? AND pub_month IS NOT NULL
  GROUP BY pub_month
  ORDER BY pub_month DESC
  LIMIT 36
", [$id])->fetchAll();

$topAuthors = q("
  SELECT a.id, a.name, COUNT(*) AS papers
  FROM record_authors ra
  JOIN authors a ON a.id=ra.author_id
  JOIN oai_records r ON r.id=ra.record_id
  WHERE r.journal_id=? AND r.status='active'
  GROUP BY a.id, a.name
  ORDER BY papers DESC, a.name ASC
  LIMIT 50
", [$id])->fetchAll();

$topSubjects = q("
  SELECT s.id, s.label, COUNT(*) AS freq
  FROM record_subjects rs
  JOIN subjects s ON s.id=rs.subject_id
  JOIN oai_records r ON r.id=rs.record_id
  WHERE r.journal_id=? AND r.status='active'
  GROUP BY s.id, s.label
  ORDER BY freq DESC, s.label ASC
  LIMIT 50
", [$id])->fetchAll();

$doiDistribution = q("
  SELECT
    CASE
      WHEN doi_best IS NULL OR doi_best='' THEN 'Tidak ada DOI'
      ELSE 'Memiliki DOI'
    END AS bucket,
    COUNT(*) AS c
  FROM oai_records
  WHERE journal_id=? AND status='active'
  GROUP BY bucket
", [$id])->fetchAll();

$recentRecords = q("
  SELECT r.id, 
         r.oai_identifier,
         r.title,
         r.title_key,
         r.pub_date,
         r.doi_best,
         r.status,
         r.last_seen_at,
         (SELECT GROUP_CONCAT(a.name SEPARATOR '; ') 
          FROM record_authors ra2 
          JOIN authors a ON a.id = ra2.author_id 
          WHERE ra2.record_id = r.id) as authors_list
  FROM oai_records r
  WHERE r.journal_id=? 
  ORDER BY r.last_seen_at DESC, r.id DESC
  LIMIT 100
", [$id])->fetchAll();

$harvestHistory = q("
  SELECT * FROM harvest_runs 
  WHERE journal_id=? 
  ORDER BY started_at DESC 
  LIMIT 10
", [$id])->fetchAll();
?>
<!doctype html>
<html lang="id" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Detail Jurnal - <?=h($journal['name'])?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #2c3e50;
      --secondary-color: #3498db;
      --accent-color: #e74c3c;
    }
    
    .card-counter {
      border: none;
      border-radius: 12px;
      transition: transform 0.3s, box-shadow 0.3s;
      color: white;
    }
    
    .card-counter:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    
    .counter-icon {
      font-size: 2.5rem;
      opacity: 0.8;
    }
    
    .counter-value {
      font-size: 2.5rem;
      font-weight: bold;
    }
    
    .counter-label {
      font-size: 0.9rem;
      opacity: 0.9;
    }
    
    .journal-table tr:hover {
      background-color: rgba(52, 152, 219, 0.1);
    }
    
    [data-bs-theme="dark"] {
      --primary-color: #ecf0f1;
      --secondary-color: #3498db;
      background-color: #1a1a1a;
      color: #ecf0f1;
    }
    
    [data-bs-theme="dark"] .card {
      background-color: #2d2d2d;
      border-color: #404040;
    }
    
    [data-bs-theme="dark"] .table {
      --bs-table-bg: #2d2d2d;
      --bs-table-color: #ecf0f1;
      --bs-table-border-color: #404040;
    }
    
    .table-container {
      max-height: 400px;
      overflow-y: auto;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    [data-bs-theme="dark"] .table-container {
      border-color: #404040;
    }
    
    .stat-card {
      border-left: 4px solid;
      border-radius: 8px;
    }
    
    .stat-card.total { border-left-color: #3498db; }
    .stat-card.active { border-left-color: #2ecc71; }
    .stat-card.deleted { border-left-color: #e74c3c; }
    .stat-card.doi { border-left-color: #9b59b6; }
    
    .badge-pill {
      border-radius: 20px;
      padding: 5px 12px;
    }
    
    .nav-link.active {
      border-bottom: 3px solid var(--secondary-color);
      font-weight: 600;
    }
    
    .theme-icon {
      transition: transform 0.3s;
    }
    
    [data-bs-theme="dark"] .theme-icon {
      transform: rotate(180deg);
    }
    
    .scrollable-table {
      display: block;
      max-height: 500px;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    
    .scrollable-table table {
      min-width: 100%;
    }
    
    .scrollable-table thead {
      position: sticky;
      top: 0;
      background-color: #f8f9fa;
      z-index: 10;
    }
    
    [data-bs-theme="dark"] .scrollable-table thead {
      background-color: #2d2d2d;
    }
    
    .truncate-text {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    
    .text-ellipsis {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 200px;
    }
  </style>
</head>
<body>

<?php include 'header.php';?>

<main class="container my-5">
  <div class="row mb-4">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
          <h1 class="h2 fw-bold mb-2"><?=h($journal['name'])?></h1>
          <div class="text-muted mb-2">
            <i class="bi bi-link-45deg me-1"></i>
            <a href="<?=h($journal['oai_base_url'])?>" target="_blank" class="text-decoration-none">
              <?=h($journal['oai_base_url'])?>
            </a>
          </div>
          <div class="d-flex flex-wrap gap-3">
            <?php if ($journal['publisher']): ?>
            <span class="badge bg-info bg-opacity-10 text-info border border-info">
              <i class="bi bi-building me-1"></i> <?=h($journal['publisher'])?>
            </span>
            <?php endif; ?>
            
            <?php if ($journal['nama_rumpun']): ?>
            <span class="badge bg-success bg-opacity-10 text-success border border-success">
              <i class="bi bi-diagram-3 me-1"></i> <?=h($journal['nama_rumpun'])?>
            </span>
            <?php endif; ?>
            
            <span class="badge bg-<?=((int)$journal['enabled']===1?'success':'secondary')?> bg-opacity-10 text-<?=((int)$journal['enabled']===1?'success':'secondary')?> border border-<?=((int)$journal['enabled']===1?'success':'secondary')?>">
              <i class="bi bi-power me-1"></i> <?=((int)$journal['enabled']===1?'Aktif':'Nonaktif')?>
            </span>
            
            <?php if ($journal['journal_url']): ?>
            <a href="<?=h($journal['journal_url'])?>" target="_blank" class="badge bg-primary text-decoration-none">
              <i class="bi bi-globe me-1"></i> Website Jurnal
            </a>
            <?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-secondary" href="index.php">
            <i class="bi bi-arrow-left me-1"></i> Kembali
          </a>
          <a class="btn btn-primary" href="network.php?id=<?=h($id)?>">
            <i class="bi bi-diagram-3 me-1"></i> Network Graph
          </a>
          <div class="dropdown">
            <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-download me-1"></i> Export
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="api.php?op=export_csv&id=<?=h($id)?>">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> CSV
              </a></li>
              <li><a class="dropdown-item" href="api.php?op=export_json&id=<?=h($id)?>">
                <i class="bi bi-filetype-json me-2"></i> JSON
              </a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-light">
          <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informasi Jurnal</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-3">
              <small class="text-muted d-block">Metadata Prefix</small>
              <strong><?=h($journal['metadata_prefix'])?></strong>
            </div>
            <div class="col-md-3">
              <small class="text-muted d-block">Set Spec</small>
              <strong><?=h($journal['set_spec'] ?: '-')?></strong>
            </div>
            <div class="col-md-3">
              <small class="text-muted d-block">Frekuensi Harvest</small>
              <strong><?=h($journal['harvest_freq'])?></strong>
            </div>
            <div class="col-md-3">
              <small class="text-muted d-block">Terakhir Harvest</small>
              <strong><?=h($journal['last_harvest_at'] ?: 'Belum pernah')?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card stat-card total h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Total Record</h6>
              <h2 class="mb-0 fw-bold"><?=h($sum['total'])?></h2>
            </div>
            <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-database text-primary fs-4"></i>
            </div>
          </div>
          <div class="mt-3">
            <span class="badge bg-success bg-opacity-10 text-success"><?=h($sum['active'])?> Aktif</span>
            <span class="badge bg-danger bg-opacity-10 text-danger"><?=h($sum['deleted'])?> Dihapus</span>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="card stat-card active h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Record Aktif</h6>
              <h2 class="mb-0 fw-bold"><?=h($sum['active'])?></h2>
              <small class="text-muted"><?=number_format(($sum['active']/$sum['total']*100), 1)?>% dari total</small>
            </div>
            <div class="bg-success bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-check-circle text-success fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="card stat-card doi h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">DOI Coverage</h6>
              <h2 class="mb-0 fw-bold"><?=number_format($doiCov,1)?>%</h2>
              <small class="text-muted"><?=h($sum['doi_present'])?> dari <?=h($sum['active'])?> record aktif</small>
            </div>
            <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-upc-scan text-warning fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="card stat-card border-left h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Rentang Publikasi</h6>
              <h4 class="mb-0 fw-bold"><?=h($sum['pub_earliest'] ?: '-')?></h4>
              <div class="text-muted small">sampai</div>
              <h4 class="mb-0 fw-bold"><?=h($sum['pub_latest'] ?: '-')?></h4>
            </div>
            <div class="bg-info bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-calendar-range text-info fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($doiDistribution)): ?>
  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-upc-scan me-2"></i>Distribusi DOI</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <?php 
            $totalDoi = 0;
            foreach ($doiDistribution as $d) {
              $totalDoi += $d['c'];
            }
            foreach ($doiDistribution as $d): 
              $percentage = ($d['c'] / $totalDoi) * 100;
              $color = $d['bucket'] === 'Memiliki DOI' ? 'success' : 'secondary';
            ?>
            <div class="col-md-6 mb-3">
              <div class="d-flex align-items-center">
                <div class="flex-shrink-0">
                  <div class="bg-<?=$color?> bg-opacity-10 p-3 rounded-circle me-3">
                    <i class="bi bi-<?=$d['bucket'] === 'Memiliki DOI' ? 'check-circle' : 'x-circle'?> text-<?=$color?> fs-4"></i>
                  </div>
                </div>
                <div class="flex-grow-1">
                  <h6 class="mb-1"><?=$d['bucket']?></h6>
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold fs-4"><?=h($d['c'])?></span>
                    <span class="badge bg-<?=$color?>"><?=number_format($percentage, 1)?>%</span>
                  </div>
                  <div class="progress mt-2" style="height: 8px;">
                    <div class="progress-bar bg-<?=$color?>" role="progressbar" style="width: <?=$percentage?>%"></div>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($harvestHistory)): ?>
  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-secondary text-white">
          <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>History Harvest (10 terakhir)</h5>
        </div>
        <div class="card-body">
          <div class="scrollable-table">
            <table class="table table-hover mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>Waktu</th>
                  <th>Status</th>
                  <th class="text-end">Dilihat</th>
                  <th class="text-end">Insert</th>
                  <th class="text-end">Update</th>
                  <th class="text-end">Aktif</th>
                  <th>Durasi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($harvestHistory as $harvest): 
                  $startTime = new DateTime($harvest['started_at']);
                  $endTime = $harvest['finished_at'] ? new DateTime($harvest['finished_at']) : null;
                  $duration = $endTime ? $endTime->diff($startTime)->format('%H:%I:%S') : 'Berjalan';
                ?>
                <tr>
                  <td>
                    <small class="text-muted"><?=date('d/m/Y', strtotime($harvest['started_at']))?></small><br>
                    <strong><?=date('H:i:s', strtotime($harvest['started_at']))?></strong>
                  </td>
                  <td>
                    <span class="badge bg-<?=$harvest['status'] === 'ok' ? 'success' : ($harvest['status'] === 'error' ? 'danger' : 'warning')?>">
                      <?=h($harvest['status'])?>
                    </span>
                  </td>
                  <td class="text-end fw-bold"><?=h($harvest['total_seen_all'])?></td>
                  <td class="text-end text-success fw-bold">+<?=h($harvest['total_inserted'])?></td>
                  <td class="text-end text-primary fw-bold">↻<?=h($harvest['total_updated'])?></td>
                  <td class="text-end"><?=h($harvest['active_count'])?></td>
                  <td><code><?=$duration?></code></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card shadow border-0">
        <div class="card-header bg-primary text-white py-3">
          <h5 class="mb-0"><i class="bi bi-calendar-month me-2"></i>Publikasi per Bulan (36 bulan terakhir)</h5>
        </div>
        <div class="card-body p-0">
          <div class="scrollable-table">
            <table class="table table-hover journal-table mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>Bulan</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Aktif</th>
                  <th class="text-end">Dihapus</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($byMonth as $m): ?>
                <tr>
                  <td><span class="badge bg-info bg-opacity-10 text-info"><?=h($m['ym'])?></span></td>
                  <td class="text-end fw-bold"><?=h($m['total'])?></td>
                  <td class="text-end"><span class="badge bg-success bg-opacity-10 text-success"><?=h($m['active'])?></span></td>
                  <td class="text-end"><span class="badge bg-danger bg-opacity-10 text-danger"><?=h($m['deleted'])?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow border-0">
        <div class="card-header bg-primary text-white py-3">
          <h5 class="mb-0"><i class="bi bi-calendar-year me-2"></i>Publikasi per Tahun</h5>
        </div>
        <div class="card-body p-0">
          <div class="scrollable-table">
            <table class="table table-hover journal-table mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>Tahun</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Aktif</th>
                  <th class="text-end">Dihapus</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($byYear as $y): ?>
                <tr>
                  <td><span class="badge bg-warning bg-opacity-10 text-primary"><?=h($y['y'])?></span></td>
                  <td class="text-end fw-bold"><?=h($y['total'])?></td>
                  <td class="text-end"><span class="badge bg-success bg-opacity-10 text-success"><?=h($y['active'])?></span></td>
                  <td class="text-end"><span class="badge bg-danger bg-opacity-10 text-danger"><?=h($y['deleted'])?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow border-0">
        <div class="card-header bg-success text-white py-3">
          <h5 class="mb-0"><i class="bi bi-people me-2"></i>Top Authors (50 teratas)</h5>
        </div>
        <div class="card-body p-0">
          <div class="scrollable-table">
            <table class="table table-hover journal-table mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>#</th>
                  <th>Author</th>
                  <th class="text-end">Jumlah Artikel</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topAuthors as $index => $a): ?>
                <tr>
                  <td class="text-muted"><?=$index + 1?></td>
                  <td>
                    <div class="fw-semibold"><?=h($a['name'])?></div>
                    <small class="text-muted">ID: <?=h($a['id'])?></small>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-success rounded-pill px-3 py-2 fw-bold"><?=h($a['papers'])?></span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow border-0">
        <div class="card-header bg-info text-white py-3">
          <h5 class="mb-0"><i class="bi bi-tags me-2"></i>Top Subjects (50 teratas)</h5>
        </div>
        <div class="card-body p-0">
          <div class="scrollable-table">
            <table class="table table-hover journal-table mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>#</th>
                  <th>Subject</th>
                  <th class="text-end">Frekuensi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topSubjects as $index => $s): ?>
                <tr>
                  <td class="text-muted"><?=$index + 1?></td>
                  <td>
                    <div class="fw-semibold"><?=h($s['label'])?></div>
                    <small class="text-muted">ID: <?=h($s['id'])?></small>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-info rounded-pill px-3 py-2 fw-bold"><?=h($s['freq'])?></span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow border-0">
        <div class="card-header bg-secondary text-white py-3">
          <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Records (100 terbaru)</h5>
        </div>
        <div class="card-body p-0">
          <div class="scrollable-table">
            <table class="table table-hover journal-table mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>ID</th>
                  <th>Title</th>
                  <th>Authors</th>
                  <th>Date</th>
                  <th>DOI</th>
                  <th>Status</th>
                  <th>Terakhir Dilihat</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentRecords as $r): 
                  $lastSeen = new DateTime($r['last_seen_at'] ?? date('Y-m-d H:i:s'));
                  $title = $r['title'] ?? $r['title_key'] ?? '-';
                  $identifier = $r['oai_identifier'] ?? '-';
                  $doi = $r['doi_best'] ?? null;
                  $pubDate = $r['pub_date'] ?? '-';
                  $status = $r['status'] ?? 'active';
                ?>
                <tr>
                  <td><code class="small"><?=h($r['id'])?></code></td>
                  <td>
                    <div class="fw-semibold truncate-text" title="<?=h($title)?>">
                      <?=h($title)?>
                    </div>
                    <small class="text-muted text-ellipsis" title="<?=h($identifier)?>">
                      <?=h($identifier)?>
                    </small>
                  </td>
                  <td>
                    <?php if (!empty($r['authors_list'])): ?>
                      <small class="truncate-text"><?=h($r['authors_list'])?></small>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td><?=h($pubDate)?></td>
                  <td>
                    <?php if (!empty($doi)): ?>
                      <span class="badge bg-success bg-opacity-10 text-success" title="<?=h($doi)?>">
                        <i class="bi bi-upc-scan me-1"></i> DOI
                      </span>
                    <?php else: ?>
                      <span class="badge bg-secondary bg-opacity-10 text-secondary">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-<?=$status === 'active' ? 'success' : 'danger'?>">
                      <?=h($status)?>
                    </span>
                  </td>
                  <td>
                    <small class="text-muted"><?=$lastSeen->format('d/m/Y H:i:s')?></small>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include 'footer.php';?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            const themeIcon = themeToggle.querySelector('.theme-icon');
            const savedTheme = localStorage.getItem('bs-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            let currentTheme = savedTheme || (prefersDark ? 'dark' : 'light');
            
            function setTheme(theme) {
                document.documentElement.setAttribute('data-bs-theme', theme);
                if (theme === 'dark') {
                    themeIcon.classList.remove('bi-moon-stars');
                    themeIcon.classList.add('bi-sun');
                } else {
                    themeIcon.classList.remove('bi-sun');
                    themeIcon.classList.add('bi-moon-stars');
                }
            }
            setTheme(currentTheme);
            
            themeToggle.addEventListener('click', function() {
                currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
                setTheme(currentTheme);
                localStorage.setItem('bs-theme', currentTheme);
            });
        }
        
        const tables = document.querySelectorAll('.scrollable-table');
        tables.forEach(table => {
            table.addEventListener('scroll', function() {
                const thead = this.querySelector('thead');
                if (thead) {
                    thead.style.transform = `translateY(${this.scrollTop}px)`;
                }
            });
        });
    });
</script>
</body>
</html>