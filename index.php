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
require_once __DIR__ . '/lib_oai.php';

$action = strtolower(trim($_GET['action'] ?? ''));

// Fungsi untuk redirect ke halaman utama
function redirect_home() { header("Location: index.php"); exit; }

// Ambil data rumpun ilmu untuk dropdown
$rumpunIlmuList = q("SELECT rumpunilmu_id, nama_rumpun FROM rumpunilmu ORDER BY nama_rumpun")->fetchAll();
$rumpunIlmuMap = [];
foreach ($rumpunIlmuList as $ri) {
    $rumpunIlmuMap[$ri['rumpunilmu_id']] = $ri['nama_rumpun'];
}

// ========== PENCARIAN ==========
$searchQuery = trim($_GET['search'] ?? '');
$searchResults = [];
$isSearching = false;

if (!empty($searchQuery)) {
    $isSearching = true;
    $searchTerm = '%' . $searchQuery . '%';
    
    // Query pencarian jurnal
    $searchResults = q("
        SELECT 
            j.*, 
            ri.nama_rumpun,
            p.name as publisher_name 
        FROM journals j 
        LEFT JOIN rumpunilmu ri ON j.subject = ri.rumpunilmu_id
        LEFT JOIN publishers p ON j.publisher = p.name
        WHERE 
            j.name LIKE ? OR 
            j.publisher LIKE ? OR 
            ri.nama_rumpun LIKE ? OR
            j.oai_base_url LIKE ? OR
            j.journal_url LIKE ?
        ORDER BY j.created_at DESC
        LIMIT 50
    ", [
        $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm
    ])->fetchAll();
}

// ========== ACTION HANDLERS ==========
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil input dari form
    $name = trim($_POST['name'] ?? 'Untitled Journal');
    $journalUrl = trim($_POST['journal_url'] ?? '');
    $oaiBase = trim($_POST['oai_base_url'] ?? '');
    $prefix = trim($_POST['metadata_prefix'] ?? 'oai_dc');
    $set = trim($_POST['set_spec'] ?? '');
    $freq = trim($_POST['harvest_freq'] ?? 'daily');
    $rumpunilmu_id = null;
    $rumpunilmu_custom = trim($_POST['rumpunilmu_custom'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');

    // Deteksi OAI Base jika tidak ada input
    if ($oaiBase === '' && $journalUrl !== '') $oaiBase = detect_oai_base($journalUrl);
    if ($oaiBase === '') die("OAI Base wajib diisi atau isi URL jurnal untuk auto-detect.");

    if (!in_array($freq, ['daily','weekly','manual'], true)) $freq = 'daily';

    // Jika ada publisher, insert ke tabel publishers
    if ($publisher !== '') {
        q("INSERT IGNORE INTO publishers (name) VALUES (?)", [$publisher]);
    }

    // Handle rumpun ilmu: pilih dari dropdown atau input custom
    if (!empty($rumpunilmu_custom)) {
        // Cek apakah rumpun ilmu custom sudah ada
        $existingRumpun = q("SELECT rumpunilmu_id FROM rumpunilmu WHERE nama_rumpun = ?", [$rumpunilmu_custom])->fetch();
        
        if ($existingRumpun) {
            $rumpunilmu_id = $existingRumpun['rumpunilmu_id'];
        } else {
            // Insert rumpun ilmu baru
            q("INSERT INTO rumpunilmu (nama_rumpun) VALUES (?)", [$rumpunilmu_custom]);
            $rumpunilmu_id = q("SELECT LAST_INSERT_ID()")->fetchColumn();
        }
    } else if (isset($_POST['rumpunilmu_id']) && $_POST['rumpunilmu_id'] !== '') {
        $rumpunilmu_id = (int)$_POST['rumpunilmu_id'];
    }

    // Insert jurnal
    q("INSERT INTO journals (name, journal_url, oai_base_url, metadata_prefix, set_spec, harvest_freq, subject, publisher) 
        VALUES (?,?,?,?,?,?,?,?)", [
        $name, 
        $journalUrl ?: null, 
        $oaiBase, 
        $prefix ?: 'oai_dc', 
        $set ?: null, 
        $freq, 
        $rumpunilmu_id,
        $publisher ?: null
    ]);
    redirect_home();
}

if ($action === 'toggle' && isset($_GET['id'])) {
    q("UPDATE journals SET enabled = IF(enabled=1,0,1) WHERE id=?", [(int)$_GET['id']]);
    redirect_home();
}

if ($action === 'delete' && isset($_GET['id'])) {
    q("DELETE FROM journals WHERE id=?", [(int)$_GET['id']]);
    redirect_home();
}

if ($action === 'harvest' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $force = (($_GET['force'] ?? '') === '1');
    try { harvest_journal($id, $force, 0); } catch (Throwable $e) {}
    redirect_home();
}

// Query journals (default - 10 jurnal terbaru)
if (!$isSearching) {
    $journals = q("SELECT 
                    j.*, 
                    ri.nama_rumpun,
                    p.name as publisher_name 
                   FROM journals j 
                   LEFT JOIN rumpunilmu ri ON j.subject = ri.rumpunilmu_id
                   LEFT JOIN publishers p ON j.publisher = p.name
                   ORDER BY j.created_at DESC LIMIT 10")->fetchAll();
} else {
    $journals = $searchResults;
}

// Query untuk counter dengan JOIN yang benar
$counter = [
    'journal' => q("SELECT COUNT(*) FROM journals")->fetchColumn(),
    'publisher' => q("SELECT COUNT(DISTINCT publisher) FROM journals WHERE publisher IS NOT NULL AND publisher != ''")->fetchColumn(),
    'rumpunilmu' => q("SELECT COUNT(DISTINCT subject) FROM journals WHERE subject IS NOT NULL")->fetchColumn(),
    'author' => q("SELECT COUNT(*) FROM authors")->fetchColumn(),
    'keyword' => q("SELECT COUNT(DISTINCT subject_id) FROM record_subjects")->fetchColumn(),
    'record' => q("SELECT COUNT(*) FROM oai_records")->fetchColumn(),
];

// Ambil ringkasan statistik jurnal
$rows = [];
foreach ($journals as $j) {
    $jid = (int)$j['id'];

    $sum = q("
        SELECT
            COUNT(*) AS total,
            SUM(status='active') AS active,
            SUM(status='deleted') AS deleted,
            SUM(doi_best IS NOT NULL AND doi_best <> '' AND status='active') AS doi_present,
            MIN(pub_date) AS pub_earliest,
            MAX(pub_date) AS pub_latest
        FROM oai_records
        WHERE journal_id=? 
    ", [$jid])->fetch();

    $doiCov = ((int)$sum['active'] > 0) ? ((int)$sum['doi_present'] / (int)$sum['active'] * 100.0) : 0.0;

    $rows[] = ['j'=>$j, 'sum'=>$sum, 'doiCov'=>$doiCov];
}
?>

<!doctype html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Journal Data Harvester & Visualization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .card-counter {
            transition: transform 0.2s;
        }
        
        .card-counter:hover {
            transform: translateY(-5px);
        }
        
        .search-box {
            border-radius: 25px;
            padding: 15px 25px;
        }
        
        .hero-section {
            background: linear-gradient(rgba(13, 110, 253, 0.9), rgba(13, 110, 253, 0.8));
            background-size: cover;
            background-position: center;
            color: white;
            padding: 4rem 0;
            border-radius: 15px;
            margin-bottom: 3rem;
        }
        
        .journal-table tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }
        
        /* Dark mode fixes */
        [data-bs-theme="dark"] {
            background-color: #212529;
            color: #f8f9fa;
        }
        
        [data-bs-theme="dark"] .card {
            background-color: #2d2d2d;
            border-color: #404040;
        }
        
        [data-bs-theme="dark"] .table {
            --bs-table-bg: #2d2d2d;
            --bs-table-color: #f8f9fa;
            --bs-table-border-color: #404040;
        }
        
        [data-bs-theme="dark"] .navbar {
            background-color: #343a40 !important;
        }
        
        [data-bs-theme="dark"] .modal-content {
            background-color: #343a40;
            color: #f8f9fa;
        }
        
        .theme-icon {
            transition: transform 0.3s ease;
        }
        
        .search-results-info {
            background-color: #e7f1ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        [data-bs-theme="dark"] .search-results-info {
            background-color: #2c3e50;
        }
        
        .btn-toggle {
            border: 1px solid #dee2e6;
        }
        
        [data-bs-theme="dark"] .btn-toggle {
            border-color: #495057;
        }
    </style>
</head>
<body>

<!-- Header -->
<?php include 'header.php';?>

<!-- Kotak Pencarian -->
<section class="container my-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="text-center mb-4">
                <h1 class="display-6 fw-bold mb-3">Temukan Jurnal dan Publikasi</h1>
                <p class="text-muted">Cari berdasarkan nama jurnal, penerbit, atau rumpun ilmu</p>
            </div>
            
            <form method="GET" action="index.php" class="search-box shadow-lg bg-light">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0">
                        <i class="bi bi-search text-primary"></i>
                    </span>
                    <input type="text" class="form-control border-0" name="search" id="searchInput" 
                           placeholder="Cari jurnal, penerbit, atau rumpun ilmu..." 
                           value="<?= h($searchQuery) ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Cari
                    </button>
                </div>
                <div class="mt-2 text-muted small">
                    <i class="bi bi-info-circle"></i> Contoh: "Informatika", "Elsevier", "Ilmu Komputer"
                </div>
            </form>
            
            <?php if ($isSearching): ?>
            <div class="search-results-info mt-4">
                <h5 class="mb-2">
                    <i class="bi bi-search me-2"></i>
                    Hasil Pencarian untuk "<?= h($searchQuery) ?>"
                </h5>
                <p class="mb-0">
                    Ditemukan <strong><?= count($searchResults) ?></strong> jurnal yang sesuai.
                    <a href="index.php" class="ms-2 text-decoration-none">
                        <i class="bi bi-x-circle"></i> Hapus pencarian
                    </a>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>



<!-- Section Jurnal -->
<section class="container my-5">
    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
            <h3 class="h5 mb-0">
                <i class="bi bi-clock-history me-2"></i>
                <?php if ($isSearching): ?>
                    Hasil Pencarian
                <?php else: ?>
                    10 Jurnal Terbaru Ditambahkan
                <?php endif; ?>
            </h3>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addJournalModal">
                <i class="bi bi-plus-circle me-1"></i> Tambah Jurnal untuk dipanen
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($rows)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x display-1 text-muted"></i>
                    <h4 class="mt-3">Tidak ada data jurnal</h4>
                    <p class="text-muted">
                        <?php if ($isSearching): ?>
                            Tidak ditemukan jurnal yang sesuai dengan pencarian Anda.
                        <?php else: ?>
                            Mulai dengan menambahkan jurnal baru.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover journal-table">
                        <thead class="table-light">
                            <tr>
                                <th>Nama Jurnal</th>
                                <th>Penerbit</th>
                                <th>Rumpun Ilmu</th>
                                <th>Total Artikel</th>
                                <th>Status</th>
                                <th>Terakhir Update</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $j = $r['j']; $s = $r['sum'];
                                $statusColor = ((int)$j['enabled']===1) ? 'success' : 'secondary';
                                $lastHarvestStatus = $j['last_harvest_status'] ?: 'Belum dipanen';
                                $namaRumpun = $j['nama_rumpun'] ?: ($rumpunIlmuMap[$j['subject']] ?? 'Tidak ditentukan');
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($j['name']) ?></div>
                                        <small class="text-muted"><?= h($j['oai_base_url']) ?></small>
                                    </td>
                                    <td><?= h($j['publisher'] ?: '-') ?></td>
                                    <td><?= h($namaRumpun) ?></td>
                                    <td>
                                        <span class="badge bg-info"><?= h($s['total'] ?? 0) ?></span>
                                        <small class="text-muted d-block">Aktif: <?= h($s['active'] ?? 0) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $statusColor ?>"><?= ((int)$j['enabled']===1?'Aktif':'Nonaktif') ?></span>
                                        <div class="small"><?= $lastHarvestStatus ?></div>
                                    </td>
                                    <td>
                                        <div class="small"><?= h($j['last_harvest_at'] ?: '-') ?></div>
                                        <div class="small text-muted">Freq: <?= h($j['harvest_freq']) ?></div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a class="btn btn-outline-primary" href="journal.php?id=<?= h($j['id']) ?>" title="Detail">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a class="btn btn-outline-success" href="?action=harvest&id=<?= h($j['id']) ?>" title="Harvest">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <a class="btn btn-outline-warning" href="?action=toggle&id=<?= h($j['id']) ?>" title="Toggle Status">
                                                <i class="bi bi-power"></i>
                                            </a>
                                            <a class="btn btn-outline-danger" href="?action=delete&id=<?= h($j['id']) ?>" onclick="return confirm('Hapus jurnal dan semua datanya?')" title="Hapus">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Section Counter -->
<section class="container my-5">
    <h2 class="text-center mb-4 fw-bold">Statistik Data</h2>
    <div class="row g-4">
        <!-- Jumlah Jurnal -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-primary text-white">
                <i class="bi bi-journals counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['journal'] ?? 0) ?></div>
                <div class="counter-label">Jurnal</div>
            </div>
        </div>
        
        <!-- Jumlah Penerbit -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-info text-white">
                <i class="bi bi-building counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['publisher'] ?? 0) ?></div>
                <div class="counter-label">Penerbit</div>
            </div>
        </div>
        
        <!-- Jumlah Rumpun Ilmu -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-success text-white">
                <i class="bi bi-diagram-3 counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['rumpunilmu'] ?? 0) ?></div>
                <div class="counter-label">Rumpun Ilmu</div>
            </div>
        </div>
        
        <!-- Jumlah Author -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-warning text-dark">
                <i class="bi bi-people counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['author'] ?? 0) ?></div>
                <div class="counter-label">Author</div>
            </div>
        </div>
        
        <!-- Jumlah Keyword -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-danger text-white">
                <i class="bi bi-tags counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['keyword'] ?? 0) ?></div>
                <div class="counter-label">Keyword</div>
            </div>
        </div>
        
        <!-- Jumlah Record -->
        <div class="col-md-4 col-lg-2">
            <div class="card card-counter text-center p-4 shadow border-0 bg-secondary text-white">
                <i class="bi bi-database counter-icon fs-1"></i>
                <div class="counter-value mt-3 fs-2 fw-bold"><?= h($counter['record'] ?? 0) ?></div>
                <div class="counter-label">Record</div>
            </div>
        </div>
    </div>
</section>

<!-- Section Tentang Aplikasi -->
<section id="about" class="container my-5 py-5">
    <div class="row align-items-center">
        <div class="col-lg-6 mb-4 mb-lg-0">
            <h2 class="fw-bold mb-4">Tentang JournalHub</h2>
            <p class="lead mb-4">
                JournalHub adalah sistem canggih untuk memanen, mengelola, dan menganalisis metadata jurnal akademik 
                berbasis protokol OAI-PMH (Open Archives Initiative Protocol for Metadata Harvesting).
            </p>
            <div class="mb-4">
                <h5 class="fw-semibold"><i class="bi bi-check-circle-fill text-success me-2"></i>Fitur Utama</h5>
                <ul class="list-unstyled">
                    <li><i class="bi bi-check me-2"></i>Harvesting metadata dari berbagai platform OJS</li>
                    <li><i class="bi bi-check me-2"></i>Analisis bibliometrik dan visualisasi jaringan</li>
                    <li><i class="bi bi-check me-2"></i>Manajemen data terstruktur dengan MySQL</li>
                    <li><i class="bi bi-check me-2"></i>Pencarian canggih berdasarkan berbagai kriteria</li>
                    <li><i class="bi bi-check me-2"></i>Statistik real-time dan dashboard interaktif</li>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="rounded-4 overflow-hidden shadow-lg">
                <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" 
                     alt="Dashboard Analytics" class="img-fluid">
            </div>
        </div>
    </div>
</section>

<?php include 'footer.php';?>

<!-- Modal Form Tambah Jurnal -->
<div class="modal fade" id="addJournalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Jurnal Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="?action=add" id="addJournalForm">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Nama Jurnal <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">URL Jurnal Utama</label>
                            <input type="url" class="form-control" name="journal_url" 
                                   placeholder="https://example.com/index.php/jurnal">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">URL OAI Base (opsional)</label>
                            <input type="url" class="form-control" name="oai_base_url" 
                                   placeholder="https://example.com/index.php/jurnal/oai">
                        </div>
                        
                        <!-- Rumpun Ilmu dengan opsi dropdown dan input bebas -->
                        <div class="col-md-6">
                            <label class="form-label">Rumpun Ilmu</label>
                            <div class="input-group">
                                <select class="form-select" id="rumpunilmu_select" name="rumpunilmu_id">
                                    <option value="">Pilih dari daftar...</option>
                                    <?php foreach ($rumpunIlmuList as $ri): ?>
                                        <option value="<?= h($ri['rumpunilmu_id']) ?>"><?= h($ri['nama_rumpun']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="custom">-- Atau ketik manual --</option>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" id="toggleRumpunMode">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                            <input type="text" class="form-control mt-2 d-none" id="rumpunilmu_custom" 
                                   name="rumpunilmu_custom" placeholder="Ketik rumpun ilmu baru...">
                            <div class="form-text">
                                Pilih dari dropdown atau ketik manual
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Penerbit</label>
                            <input type="text" class="form-control" name="publisher">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Metadata Prefix</label>
                            <select class="form-select" name="metadata_prefix">
                                <option value="oai_dc" selected>oai_dc</option>
                                <option value="oai_marc">oai_marc</option>
                                <option value="mods">mods</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Set Spec (opsional)</label>
                            <input type="text" class="form-control" name="set_spec">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Frekuensi Harvest</label>
                            <select class="form-select" name="harvest_freq">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" form="addJournalForm" class="btn btn-primary">Tambah Jurnal</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Toggle Theme dengan perbaikan
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('.theme-icon');
        
        // Cek tema yang disimpan atau gunakan preferensi sistem
        const savedTheme = localStorage.getItem('bs-theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        let currentTheme = savedTheme || (prefersDark ? 'dark' : 'light');
        setTheme(currentTheme);
        
        // Event listener untuk toggle
        themeToggle.addEventListener('click', function() {
            currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(currentTheme);
            localStorage.setItem('bs-theme', currentTheme);
        });
        
        function setTheme(theme) {
            document.documentElement.setAttribute('data-bs-theme', theme);
            
            // Update icon
            if (theme === 'dark') {
                themeIcon.classList.remove('bi-moon-stars');
                themeIcon.classList.add('bi-sun');
            } else {
                themeIcon.classList.remove('bi-sun');
                themeIcon.classList.add('bi-moon-stars');
            }
        }
        
        // Live search dengan debounce
        const searchInput = document.getElementById('searchInput');
        let searchTimeout;
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    if (searchInput.value.trim().length > 2 || searchInput.value.trim().length === 0) {
                        searchInput.closest('form').submit();
                    }
                }, 500);
            });
        }
        
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                if (this.getAttribute('href') !== '#') {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        window.scrollTo({
                            top: targetElement.offsetTop - 80,
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });
        
        // ========== RUMPUN ILMU CUSTOM INPUT ==========
        const rumpunSelect = document.getElementById('rumpunilmu_select');
        const rumpunCustom = document.getElementById('rumpunilmu_custom');
        const toggleRumpunBtn = document.getElementById('toggleRumpunMode');
        let isCustomMode = false;
        
        // Toggle antara dropdown dan input manual
        function toggleRumpunMode() {
            isCustomMode = !isCustomMode;
            
            if (isCustomMode) {
                // Mode input manual
                rumpunSelect.classList.add('d-none');
                rumpunCustom.classList.remove('d-none');
                toggleRumpunBtn.innerHTML = '<i class="bi bi-list"></i>';
                toggleRumpunBtn.title = "Pilih dari dropdown";
                rumpunSelect.value = 'custom';
                rumpunCustom.focus();
            } else {
                // Mode dropdown
                rumpunSelect.classList.remove('d-none');
                rumpunCustom.classList.add('d-none');
                toggleRumpunBtn.innerHTML = '<i class="bi bi-pencil"></i>';
                toggleRumpunBtn.title = "Ketik manual";
                rumpunCustom.value = '';
            }
        }
        
        // Event listener untuk toggle button
        if (toggleRumpunBtn) {
            toggleRumpunBtn.addEventListener('click', toggleRumpunMode);
        }
        
        // Ketika user memilih "custom" dari dropdown
        if (rumpunSelect) {
            rumpunSelect.addEventListener('change', function() {
                if (this.value === 'custom') {
                    toggleRumpunMode();
                }
            });
        }
        
        // Validasi form sebelum submit
        const addJournalForm = document.getElementById('addJournalForm');
        if (addJournalForm) {
            addJournalForm.addEventListener('submit', function(e) {
                // Jika di mode custom dan input kosong, beri peringatan
                if (isCustomMode && rumpunCustom.value.trim() === '') {
                    e.preventDefault();
                    alert('Silakan isi rumpun ilmu atau pilih dari dropdown');
                    rumpunCustom.focus();
                    return false;
                }
                
                // Jika memilih dari dropdown, pastikan dropdown dipilih
                if (!isCustomMode && rumpunSelect.value === '' && !confirm('Rumpun ilmu tidak dipilih. Lanjutkan tanpa rumpun ilmu?')) {
                    e.preventDefault();
                    return false;
                }
            });
        }
        
        // Reset form saat modal ditutup
        const addJournalModal = document.getElementById('addJournalModal');
        if (addJournalModal) {
            addJournalModal.addEventListener('hidden.bs.modal', function() {
                // Reset ke mode dropdown
                if (isCustomMode) {
                    toggleRumpunMode();
                }
                rumpunSelect.value = '';
                rumpunCustom.value = '';
                addJournalForm.reset();
            });
        }
    });
</script>
</body>
</html>