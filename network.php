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

$id = (int)($_GET['id'] ?? 0);
$journal = q("SELECT j.*, ri.nama_rumpun, p.name as publisher_name 
              FROM journals j 
              LEFT JOIN rumpunilmu ri ON j.subject = ri.rumpunilmu_id
              LEFT JOIN publishers p ON j.publisher = p.name
              WHERE j.id=?", [$id])->fetch();
if (!$journal) die("Journal not found.");

// Get basic stats for the journal
$stats = q("
  SELECT 
    COUNT(DISTINCT a.id) as author_count,
    COUNT(DISTINCT s.id) as subject_count,
    COUNT(DISTINCT r.id) as record_count
  FROM oai_records r
  LEFT JOIN record_authors ra ON r.id = ra.record_id
  LEFT JOIN authors a ON ra.author_id = a.id
  LEFT JOIN record_subjects rs ON r.id = rs.record_id
  LEFT JOIN subjects s ON rs.subject_id = s.id
  WHERE r.journal_id = ? AND r.status = 'active'
", [$id])->fetch();
?>
<!doctype html>
<html lang="id" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Network Visualization - <?=h($journal['name'])?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #2c3e50;
      --secondary-color: #3498db;
      --accent-color: #e74c3c;
    }
    
    #viz { 
      height: 70vh; 
      border: 1px solid var(--bs-border-color); 
      border-radius: 12px; 
      overflow: hidden;
      background-color: var(--bs-body-bg);
    }
    
    .legend { 
      font-size: 12px; 
      color: var(--bs-secondary-color); 
    }
    
    .node-info {
      position: absolute;
      top: 10px;
      right: 10px;
      background: rgba(255, 255, 255, 0.9);
      border-radius: 8px;
      padding: 10px 15px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      max-width: 300px;
      z-index: 1000;
      display: none;
    }
    
    [data-bs-theme="dark"] .node-info {
      background: rgba(40, 40, 40, 0.9);
    }
    
    .stat-card {
      border-left: 4px solid;
      border-radius: 8px;
    }
    
    .stat-card.authors { border-left-color: #3498db; }
    .stat-card.subjects { border-left-color: #2ecc71; }
    .stat-card.records { border-left-color: #9b59b6; }
    
    .network-controls {
      background-color: var(--bs-light);
      border-radius: 10px;
      padding: 15px;
    }
    
    [data-bs-theme="dark"] .network-controls {
      background-color: #2d2d2d;
    }
    
    .footer {
      background-color: var(--primary-color);
      color: white;
    }
    
    [data-bs-theme="dark"] {
      --primary-color: #ecf0f1;
      --secondary-color: #3498db;
      background-color: #1a1a1a;
      color: #ecf0f1;
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
    
    .tooltip-info {
      cursor: help;
      border-bottom: 1px dashed var(--bs-secondary);
    }
    
    .viz-controls {
      position: absolute;
      bottom: 15px;
      right: 15px;
      z-index: 100;
      display: flex;
      gap: 5px;
    }
    
    .viz-controls button {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: white;
      border: 1px solid #dee2e6;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    [data-bs-theme="dark"] .viz-controls button {
      background: #2d2d2d;
      border-color: #404040;
    }
    
    .legend-box {
      display: inline-block;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      margin-right: 5px;
    }
    
    .network-legend {
      background: rgba(255, 255, 255, 0.9);
      border-radius: 8px;
      padding: 10px;
      font-size: 12px;
      max-width: 250px;
    }
    
    [data-bs-theme="dark"] .network-legend {
      background: rgba(40, 40, 40, 0.9);
    }
    
    .scrollable-table {
      max-height: 300px;
      overflow-y: auto;
    }
  </style>
</head>
<body>

<!-- Header -->
<?php include 'header.php';?>

<main class="container my-5">
  <!-- Header Network -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
          <h1 class="h2 fw-bold mb-2">
            <i class="bi bi-diagram-3 text-primary me-2"></i>
            Network Analysis: <?=h($journal['name'])?>
          </h1>
          <div class="text-muted mb-2">
            <i class="bi bi-link-45deg me-1"></i>
            <a href="<?=h($journal['oai_base_url'])?>" target="_blank" class="text-decoration-none">
              <?=h($journal['oai_base_url'])?>
            </a>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">
              <i class="bi bi-people me-1"></i> <?=h($stats['author_count'] ?? 0)?> Authors
            </span>
            <span class="badge bg-success bg-opacity-10 text-success border border-success">
              <i class="bi bi-tags me-1"></i> <?=h($stats['subject_count'] ?? 0)?> Subjects
            </span>
            <span class="badge bg-info bg-opacity-10 text-info border border-info">
              <i class="bi bi-file-text me-1"></i> <?=h($stats['record_count'] ?? 0)?> Records
            </span>
          </div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-secondary" href="journal.php?id=<?=h($id)?>">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Detail
          </a>
          <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-download me-1"></i> Export Data
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="api.php?op=export_network_json&id=<?=h($id)?>&type=author">
                <i class="bi bi-people me-2"></i> Author Network (JSON)
              </a></li>
              <li><a class="dropdown-item" href="api.php?op=export_network_json&id=<?=h($id)?>&type=subject">
                <i class="bi bi-tags me-2"></i> Subject Network (JSON)
              </a></li>
              <li><a class="dropdown-item" href="api.php?op=export_network_csv&id=<?=h($id)?>&type=author">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Author Network (CSV)
              </a></li>
              <li><a class="dropdown-item" href="api.php?op=export_network_csv&id=<?=h($id)?>&type=subject">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Subject Network (CSV)
              </a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card stat-card authors h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Author Network</h6>
              <h3 class="mb-0 fw-bold"><?=h($stats['author_count'] ?? 0)?> Authors</h3>
              <small class="text-muted">Analisis kolaborasi antar penulis</small>
            </div>
            <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-people text-primary fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-4">
      <div class="card stat-card subjects h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Subject Network</h6>
              <h3 class="mb-0 fw-bold"><?=h($stats['subject_count'] ?? 0)?> Subjects</h3>
              <small class="text-muted">Analisis hubungan antar topik</small>
            </div>
            <div class="bg-success bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-tags text-success fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-4">
      <div class="card stat-card records h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Basis Analisis</h6>
              <h3 class="mb-0 fw-bold"><?=h($stats['record_count'] ?? 0)?> Records</h3>
              <small class="text-muted">Publikasi aktif sebagai data dasar</small>
            </div>
            <div class="bg-info bg-opacity-10 p-3 rounded-circle">
              <i class="bi bi-database text-info fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

<!-- Controls Panel -->
<div class="row mb-4">
    <div class="col-12">
        <div class="network-controls shadow-sm">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-diagram-3 me-1"></i> Visualization Mode
                    </label>
                    <select id="mode" class="form-select">
                        <option value="subject" selected>Subject Network (Subject–Subject)</option>
                        <option value="author">Co-author Network (Author–Author)</option>
                        <option value="author_subject">Author–Subject Network</option>
                        <option value="bubble">Topic Bubble Chart</option>
                        <option value="timeline">Timeline Network Evolution</option>
                        <option value="yearly_network">Yearly Network Comparison</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-filter me-1"></i> Minimum Weight
                    </label>
                    <select id="minWeight" class="form-select">
                        <option value="1" selected>Minimum weight: 1</option>
                        <option value="2">Minimum weight: 2</option>
                        <option value="3">Minimum weight: 3</option>
                        <option value="5">Minimum weight: 5</option>
                        <option value="10">Minimum weight: 10</option>
                    </select>
                    <small class="text-muted">Tingkatkan untuk graf yang lebih sederhana</small>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-calendar me-1"></i> Start Year
                    </label>
                    <select id="startYear" class="form-select">
                        <option value="">All Years</option>
                        <!-- Will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-calendar me-1"></i> End Year
                    </label>
                    <select id="endYear" class="form-select">
                        <option value="">All Years</option>
                        <!-- Will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-sliders me-1"></i> Layout & Options
                    </label>
                    <select id="layout" class="form-select">
                        <option value="force" selected>Force-Directed</option>
                        <option value="radial">Radial Layout</option>
                        <option value="grid">Grid Layout</option>
                        <option value="circle">Circular Layout</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" onclick="loadGraph()">
                        <i class="bi bi-play-circle me-1"></i> Generate Visualization
                    </button>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        <div class="fw-semibold">Legend:</div>
                        <div class="d-flex align-items-center me-3">
                            <span class="legend-box" style="background: linear-gradient(to right, #1f77b4, #f2c300);"></span>
                            <small>Subject (warna berdasarkan tahun)</small>
                        </div>
                        <div class="d-flex align-items-center me-3">
                            <span class="legend-box" style="background: var(--bs-info);"></span>
                            <small>Author</small>
                        </div>
                        <div class="d-flex align-items-center me-3">
                            <span class="legend-box" style="background: var(--bs-success);"></span>
                            <small>Active in Year</small>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="legend-box" style="background: var(--bs-primary);"></span>
                            <small>Edge (ketebalan = weight)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Timeline Controls (hidden by default) -->
<div id="timelineControls" class="row mb-3" style="display: none;">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <label class="me-2 mb-0 fw-semibold">Timeline:</label>
                            <input type="range" class="form-range" id="timelineSlider" min="0" max="100" value="0">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="d-flex align-items-center">
                            <span class="me-2 fw-semibold">Year:</span>
                            <span id="currentYear" class="badge bg-primary">-</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cumulativeMode" checked>
                            <label class="form-check-label" for="cumulativeMode">Cumulative</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="animateTimeline" checked>
                            <label class="form-check-label" for="animateTimeline">Animate</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-outline-primary w-100" onclick="playTimeline()">
                            <i class="bi bi-play-fill"></i> Play
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

  <!-- Info Panel -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-light">
          <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Interpretasi Visualisasi</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <h6 class="fw-semibold"><i class="bi bi-node-plus text-primary me-2"></i>Subject Network</h6>
              <p class="small text-muted">
                Menampilkan hubungan antar subject berdasarkan kemunculan bersama dalam publikasi.
                Node size = frekuensi subject, warna = tahun publikasi terbaru (biru tua → kuning terang).
              </p>
            </div>
            <div class="col-md-4">
              <h6 class="fw-semibold"><i class="bi bi-people text-success me-2"></i>Co-author Network</h6>
              <p class="small text-muted">
                Memvisualisasikan kolaborasi antar penulis. Node = author, edge = kolaborasi menulis.
                Ukuran node mencerminkan produktivitas (jumlah publikasi).
              </p>
            </div>
            <div class="col-md-4">
              <h6 class="fw-semibold"><i class="bi bi-bubble-chart text-info me-2"></i>Topic Bubble Chart</h6>
              <p class="small text-muted">
                Bubble chart menampilkan frekuensi subject. Ukuran bubble = frekuensi kemunculan.
                Membantu identifikasi topic dominan dan niche areas.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Visualization Area -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="position-relative">
        <div id="viz">
          <div id="vizMsg" class="position-absolute top-50 start-50 translate-middle text-center text-muted">
            <i class="bi bi-diagram-3 fs-1 mb-3 d-block"></i>
            <h5>Select visualization mode and click "Generate Visualization"</h5>
            <p class="small">Visualization will appear here</p>
          </div>
        </div>
        <div id="nodeInfo" class="node-info"></div>
        <div class="viz-controls">
          <button onclick="zoomIn()" title="Zoom In">
            <i class="bi bi-zoom-in"></i>
          </button>
          <button onclick="zoomOut()" title="Zoom Out">
            <i class="bi bi-zoom-out"></i>
          </button>
          <button onclick="resetView()" title="Reset View">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
          <button onclick="toggleLabels()" title="Toggle Labels">
            <i class="bi bi-fonts"></i>
          </button>
          <button onclick="downloadSVG()" title="Download as SVG">
            <i class="bi bi-download"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Statistics Panel -->
  <div class="row">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Network Statistics</h5>
        </div>
        <div class="card-body">
          <div id="networkStats" class="text-center py-5">
            <p class="text-muted">Network statistics will appear here after visualization is generated.</p>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header bg-success text-white">
          <h5 class="mb-0"><i class="bi bi-table me-2"></i>Top Entities</h5>
        </div>
        <div class="card-body">
          <div id="topEntities" class="scrollable-table">
            <p class="text-muted">Top authors/subjects will appear here after visualization is generated.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Footer -->
<footer class="footer py-5 mt-5">
    <div class="container">
        <div class="row">
            <!-- Kolom 1: Tentang -->
            <div class="col-lg-4 mb-4">
                <h5 class="fw-bold mb-3">JournalHub</h5>
                <p class="mb-3">
                    Sistem manajemen dan analisis metadata jurnal akademik berbasis OAI-PMH. 
                    Mengintegrasikan harvesting, analisis, dan visualisasi data dalam satu platform.
                </p>
            </div>
            
            <!-- Kolom 2: Link Cepat -->
            <div class="col-lg-4 mb-4">
                <h5 class="fw-bold mb-3">Link Cepat</h5>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="index.php" class="text-white text-decoration-none">Dashboard</a></li>
                    <li class="mb-2"><a href="harvest.php" class="text-white text-decoration-none">Harvest Management</a></li>
                    <li class="mb-2"><a href="global_insight.php" class="text-white text-decoration-none">Global Insight</a></li>
                </ul>
            </div>
            
            <!-- Kolom 3: Kontak -->
            <div class="col-lg-4 mb-4">
                <h5 class="fw-bold mb-3">Kontak & Dukungan</h5>
                <ul class="list-unstyled">
                    <li class="mb-2">
                        <i class="bi bi-envelope me-2"></i>
                        <a href="mailto:support@journalhub.com" class="text-white text-decoration-none">support@journalhub.com</a>
                    </li>
                </ul>
                <div class="mt-4">
                    <p class="small mb-2">© 2024 JournalHub. All rights reserved.</p>
                    <p class="small text-muted">Version 2.0.0</p>
                </div>
            </div>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
<script>
const journalId = <?= (int)$id ?>;
let currentSimulation = null;
let currentZoom = null;
let labelsVisible = true;
let timelineData = null;
let timelineAnimation = null;
let availableYears = [];

// Theme Toggle dan inisialisasi
document.addEventListener('DOMContentLoaded', function() {
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = themeToggle.querySelector('.theme-icon');
    
    const savedTheme = localStorage.getItem('bs-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    let currentTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    setTheme(currentTheme);
    
    themeToggle.addEventListener('click', function() {
        currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(currentTheme);
        localStorage.setItem('bs-theme', currentTheme);
    });
    
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
    
    // Load available years
    loadAvailableYears();
    
    // Event listeners for timeline controls
    const timelineSlider = document.getElementById('timelineSlider');
    const cumulativeMode = document.getElementById('cumulativeMode');
    
    if (timelineSlider) {
        timelineSlider.addEventListener('input', function() {
            updateCurrentYear(this.value);
        });
    }
    
    if (cumulativeMode) {
        cumulativeMode.addEventListener('change', function() {
            if (document.getElementById('mode').value === 'timeline') {
                const timelineSlider = document.getElementById('timelineSlider');
                if (timelineSlider) {
                    updateCurrentYear(timelineSlider.value);
                }
            }
        });
    }
});

async function loadAvailableYears() {
    try {
        const response = await fetch(`api.php?op=available_years&id=${journalId}`);
        const data = await response.json();
        
        if (data.years && data.years.length > 0) {
            availableYears = data.years.sort((a, b) => a - b);
            
            const startYearSelect = document.getElementById('startYear');
            const endYearSelect = document.getElementById('endYear');
            
            if (startYearSelect && endYearSelect) {
                // Clear existing options except first
                while (startYearSelect.options.length > 1) startYearSelect.remove(1);
                while (endYearSelect.options.length > 1) endYearSelect.remove(1);
                
                availableYears.forEach(year => {
                    const option1 = new Option(year, year);
                    const option2 = new Option(year, year);
                    startYearSelect.add(option1);
                    endYearSelect.add(option2);
                });
                
                // Set default end year to latest
                if (availableYears.length > 0) {
                    endYearSelect.value = availableYears[availableYears.length - 1];
                }
            }
        }
    } catch (error) {
        console.error('Error loading years:', error);
    }
}

function setMsg(msg){
    const vizMsg = document.getElementById('vizMsg');
    if (!vizMsg) return;
    
    if (msg) {
        vizMsg.innerHTML = `<div class="alert alert-info">${msg}</div>`;
        vizMsg.style.display = 'block';
    } else {
        vizMsg.style.display = 'none';
    }
}

function clearViz(){
    const viz = document.getElementById('viz');
    if (viz) {
        viz.querySelectorAll('svg').forEach(s => s.remove());
    }
    const nodeInfo = document.getElementById('nodeInfo');
    if (nodeInfo) {
        nodeInfo.style.display = 'none';
    }
    setMsg('');
}

async function fetchJSON(url){
    setMsg('Loading data...');
    try {
        const r = await fetch(url);
        const txt = await r.text();
        if (!r.ok) {
            console.error("API error:", r.status, txt.substring(0, 800));
            throw new Error("API HTTP " + r.status);
        }
        const data = JSON.parse(txt);
        setMsg('');
        return data;
    } catch(e){
        setMsg('Error loading data: ' + e.message);
        throw e;
    }
}

function updateNetworkStats(nodes, links) {
    const statsDiv = document.getElementById('networkStats');
    if (!statsDiv) return;
    
    if (!nodes || nodes.length === 0) {
        statsDiv.innerHTML = '<p class="text-muted">No data available</p>';
        return;
    }
    
    const nodeCount = nodes.length;
    const linkCount = links ? links.length : 0;
    const avgDegree = linkCount > 0 ? (2 * linkCount / nodeCount).toFixed(2) : 0;
    
    // Calculate density
    const maxLinks = nodeCount * (nodeCount - 1) / 2;
    const density = maxLinks > 0 ? (linkCount / maxLinks).toFixed(4) : 0;
    
    // Find most connected node
    const nodeDegrees = {};
    if (links) {
        links.forEach(link => {
            nodeDegrees[link.source] = (nodeDegrees[link.source] || 0) + 1;
            nodeDegrees[link.target] = (nodeDegrees[link.target] || 0) + 1;
        });
    }
    
    let maxDegree = 0;
    let maxDegreeNode = null;
    Object.entries(nodeDegrees).forEach(([nodeId, degree]) => {
        if (degree > maxDegree) {
            maxDegree = degree;
            maxDegreeNode = nodes.find(n => n.id === nodeId);
        }
    });
    
    statsDiv.innerHTML = `
        <div class="row text-center">
            <div class="col-4">
                <div class="display-6 fw-bold">${nodeCount}</div>
                <small class="text-muted">Nodes</small>
            </div>
            <div class="col-4">
                <div class="display-6 fw-bold">${linkCount}</div>
                <small class="text-muted">Edges</small>
            </div>
            <div class="col-4">
                <div class="display-6 fw-bold">${avgDegree}</div>
                <small class="text-muted">Avg Degree</small>
            </div>
            <div class="col-12 mt-3">
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted d-block">Network Density</small>
                        <strong>${density}</strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Max Degree</small>
                        <strong>${maxDegree}${maxDegreeNode ? ` (${maxDegreeNode.label || maxDegreeNode.name})` : ''}</strong>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function updateTopEntities(nodes, type) {
    const entitiesDiv = document.getElementById('topEntities');
    if (!entitiesDiv) return;
    
    if (!nodes || nodes.length === 0) {
        entitiesDiv.innerHTML = '<p class="text-muted">No data available</p>';
        return;
    }
    
    // Sort by degree/frequency
    const sortedNodes = [...nodes].sort((a, b) => 
        (b.degree || b.freq || b.papers || 0) - (a.degree || a.freq || a.papers || 0)
    ).slice(0, 10);
    
    const entityType = type === 'author' ? 'Authors' : 'Subjects';
    
    let html = `
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>#</th>
                    <th>${entityType}</th>
                    <th class="text-end">Degree</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    sortedNodes.forEach((node, index) => {
        const label = node.label || node.name || `Node ${node.id}`;
        const degree = node.degree || node.freq || node.papers || 0;
        
        html += `
            <tr>
                <td>${index + 1}</td>
                <td>
                    <div class="fw-semibold">${label.length > 30 ? label.substring(0, 30) + '...' : label}</div>
                    ${node.last_year ? `<small class="text-muted">Last year: ${node.last_year}</small>` : ''}
                </td>
                <td class="text-end">
                    <span class="badge bg-${type === 'author' ? 'info' : 'success'}">${degree}</span>
                </td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
        <small class="text-muted">Showing top 10 ${entityType.toLowerCase()} by degree/frequency</small>
    `;
    
    entitiesDiv.innerHTML = html;
}

function yearColorScale(nodes){
    const years = nodes.map(d => +d.last_year).filter(v => Number.isFinite(v));
    if (years.length === 0) return () => "#1f77b4";

    const minY = Math.min(...years);
    const maxY = Math.max(...years);
    const scale = d3.scaleLinear().domain([minY, maxY]).range([0, 1]).clamp(true);

    return (d) => d3.interpolateRgb("#1f77b4", "#f2c300")(scale(+d.last_year || minY));
}

// ========== TIMELINE NETWORK FUNCTIONS ==========
function drawTimelineNetwork(data) {
    const el = document.getElementById('viz');
    if (!el) return;
    
    const width = el.clientWidth;
    const height = el.clientHeight;
    
    // Clear previous
    clearViz();
    
    const svg = d3.select(el).append("svg").attr("width", width).attr("height", height);
    const g = svg.append("g");
    
    // Setup zoom
    currentZoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            g.attr("transform", event.transform);
        });
    
    svg.call(currentZoom);
    
    if (!data || !data.years || data.years.length === 0) {
        setMsg('No timeline data available.');
        return;
    }
    
    timelineData = data;
    
    // Show timeline controls
    const timelineControls = document.getElementById('timelineControls');
    if (timelineControls) {
        timelineControls.style.display = 'block';
    }
    
    const timelineSlider = document.getElementById('timelineSlider');
    if (timelineSlider) {
        timelineSlider.max = data.years.length - 1;
        timelineSlider.value = data.years.length - 1;
    }
    
    // Update current year display
    updateCurrentYear(data.years.length - 1);
    
    // Draw initial timeline (latest year)
    drawTimelineFrame(data.years.length - 1, true);
}

function drawTimelineFrame(yearIndex, cumulative = true) {
    if (!timelineData || !timelineData.years[yearIndex]) return;
    
    const el = document.getElementById('viz');
    if (!el) return;
    
    const width = el.clientWidth;
    const height = el.clientHeight;
    
    // Clear previous visualization
    d3.select('#viz svg').selectAll('*').remove();
    
    const svg = d3.select('#viz svg');
    const g = svg.append("g");
    
    const currentYear = timelineData.years[yearIndex];
    const yearData = timelineData.data[yearIndex];
    
    // Update current year display
    const currentYearElement = document.getElementById('currentYear');
    if (currentYearElement) {
        currentYearElement.textContent = currentYear;
    }
    
    if (!yearData || (!yearData.nodes && !cumulative)) {
        setMsg(`No data available for ${currentYear}`);
        return;
    }
    
    // For cumulative mode, accumulate nodes and edges up to current year
    let nodes = [];
    let links = [];
    
    if (cumulative) {
        // Collect all nodes and links up to current year
        for (let i = 0; i <= yearIndex; i++) {
            const yearlyData = timelineData.data[i];
            if (yearlyData && yearlyData.nodes) {
                // Merge nodes
                yearlyData.nodes.forEach(node => {
                    if (!nodes.find(n => n.id === node.id)) {
                        nodes.push({
                            ...node,
                            firstAppearance: timelineData.years[i],
                            lastActive: timelineData.years[i]
                        });
                    } else {
                        const existingNode = nodes.find(n => n.id === node.id);
                        existingNode.lastActive = timelineData.years[i];
                        // Update degree/freq
                        existingNode.degree = (existingNode.degree || 0) + (node.degree || node.freq || 0);
                    }
                });
                
                // Merge links
                if (yearlyData.links) {
                    links = [...links, ...yearlyData.links];
                }
            }
        }
    } else {
        // Non-cumulative - only show data for specific year
        nodes = yearData.nodes || [];
        links = yearData.links || [];
    }
    
    if (nodes.length === 0) {
        setMsg(`No nodes found for ${currentYear}${cumulative ? ' (cumulative)' : ''}`);
        return;
    }
    
    // Update stats
    updateNetworkStats(nodes, links);
    updateTopEntities(nodes, 'subject');
    
    // Create links
    const link = g.append("g")
        .attr("stroke", "var(--bs-primary)")
        .attr("stroke-opacity", 0.3)
        .selectAll("line")
        .data(links)
        .join("line")
        .attr("stroke-width", d => Math.max(1, Math.sqrt(+d.weight || 1)))
        .attr("opacity", d => {
            // Fade older connections in cumulative mode
            if (cumulative && d.year && d.year < currentYear) {
                return 0.2;
            }
            return 0.6;
        });

    // Create nodes with year-based coloring
    const node = g.append("g")
        .selectAll("circle")
        .data(nodes)
        .join("circle")
        .attr("r", d => {
            const deg = +d.degree || +d.freq || 1;
            return Math.max(5, Math.min(20, 4 + Math.sqrt(deg)));
        })
        .attr("fill", d => {
            // Color based on first appearance or activity year
            const firstYear = d.firstAppearance || currentYear;
            const lastYear = d.lastActive || currentYear;
            const activityRange = availableYears.length > 0 ? 
                (lastYear - availableYears[0]) / (availableYears[availableYears.length - 1] - availableYears[0]) : 0.5;
            
            // Green for active in current year, blue gradient for older
            if (lastYear === currentYear) {
                return "var(--bs-success)";
            } else {
                return d3.interpolateRgb("#1f77b4", "#f2c300")(activityRange);
            }
        })
        .attr("fill-opacity", d => {
            // Highlight nodes active in current year
            const lastYear = d.lastActive || 0;
            return lastYear === currentYear ? 0.9 : 0.6;
        })
        .attr("stroke", "currentColor")
        .attr("stroke-opacity", 0.2)
        .attr("stroke-width", d => {
            const lastYear = d.lastActive || 0;
            return lastYear === currentYear ? 2.5 : 1.5;
        })
        .style("cursor", "pointer")
        .on("mouseover", function(event, d) {
            d3.select(this)
                .attr("stroke-opacity", 0.6)
                .attr("stroke-width", 3);
            
            showTimelineNodeInfo(d, currentYear);
        })
        .on("mouseout", function(event, d) {
            const lastYear = d.lastActive || 0;
            d3.select(this)
                .attr("stroke-opacity", 0.2)
                .attr("stroke-width", lastYear === currentYear ? 2.5 : 1.5);
            
            hideNodeInfo();
        })
        .call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

    // Create labels
    const label = g.append("g")
        .selectAll("text")
        .data(nodes)
        .join("text")
        .text(d => {
            const label = d.label || d.name || d.id;
            return label.length > 20 ? label.substring(0, 20) + "..." : label;
        })
        .attr("font-size", 11)
        .attr("fill", "currentColor")
        .attr("opacity", d => {
            const lastYear = d.lastActive || 0;
            return lastYear === currentYear ? 0.9 : 0.6;
        })
        .attr("dx", 12)
        .attr("dy", 3)
        .style("pointer-events", "none")
        .style("display", labelsVisible ? "block" : "none");

    // Tooltips
    node.append("title").text(d => {
        const t = d.label || d.name || d.id;
        let extra = '';
        if (d.degree || d.freq) {
            extra += `\nDegree: ${d.degree || d.freq}`;
        }
        if (d.firstAppearance) {
            extra += `\nFirst appearance: ${d.firstAppearance}`;
        }
        if (d.lastActive) {
            extra += `\nLast active: ${d.lastActive}`;
        }
        if (d.weight) {
            extra += `\nEdge weight: ${d.weight}`;
        }
        return `${t} (Active in ${currentYear})${extra}`;
    });

    // Force simulation
    currentSimulation = d3.forceSimulation(nodes)
        .force("link", d3.forceLink(links).id(d => String(d.id)).distance(60).strength(0.5))
        .force("charge", d3.forceManyBody().strength(-150))
        .force("center", d3.forceCenter(width / 2, height / 2))
        .force("collision", d3.forceCollide().radius(d => {
            const deg = +d.degree || +d.freq || 1;
            return Math.max(8, Math.min(25, 5 + Math.sqrt(deg)));
        }));

    currentSimulation.on("tick", () => {
        link
            .attr("x1", d => d.source.x).attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x).attr("y2", d => d.target.y);
        node.attr("cx", d => d.x).attr("cy", d => d.y);
        label.attr("x", d => d.x).attr("y", d => d.y);
    });

    function dragstarted(event, d) {
        if (!event.active) currentSimulation.alphaTarget(0.3).restart();
        d.fx = d.x;
        d.fy = d.y;
    }
    
    function dragged(event, d) {
        d.fx = event.x;
        d.fy = event.y;
    }
    
    function dragended(event, d) {
        if (!event.active) currentSimulation.alphaTarget(0);
        d.fx = null;
        d.fy = null;
    }
    
    setMsg(`Timeline: ${currentYear} (${cumulative ? 'Cumulative' : 'Year-specific'}) - ${nodes.length} nodes, ${links.length} edges`);
}

function showTimelineNodeInfo(node, currentYear) {
    const infoDiv = document.getElementById('nodeInfo');
    if (!infoDiv) return;
    
    const label = node.label || node.name || `Node ${node.id}`;
    const degree = node.degree || node.freq || 0;
    const firstAppearance = node.firstAppearance || 'Unknown';
    const lastActive = node.lastActive || 'Unknown';
    
    const isActiveCurrentYear = lastActive === currentYear;
    
    infoDiv.innerHTML = `
        <h6 class="fw-bold mb-2">${label}</h6>
        <div class="small">
            <div><strong>Current Status:</strong> 
                <span class="badge bg-${isActiveCurrentYear ? 'success' : 'secondary'}">
                    ${isActiveCurrentYear ? 'Active in ' + currentYear : 'Not Active'}
                </span>
            </div>
            <div><strong>Degree/Frequency:</strong> ${degree}</div>
            <div><strong>First Appearance:</strong> ${firstAppearance}</div>
            <div><strong>Last Active:</strong> ${lastActive}</div>
            <div><strong>Years Active:</strong> ${node.yearsActive || 'N/A'}</div>
        </div>
    `;
    infoDiv.style.display = 'block';
}

function updateCurrentYear(sliderValue) {
    const timelineSlider = document.getElementById('timelineSlider');
    if (!timelineData || !timelineData.years[sliderValue]) return;
    
    const currentYear = timelineData.years[sliderValue];
    const currentYearElement = document.getElementById('currentYear');
    if (currentYearElement) {
        currentYearElement.textContent = currentYear;
    }
    
    // Get cumulative mode setting
    const cumulativeMode = document.getElementById('cumulativeMode');
    const isCumulative = cumulativeMode ? cumulativeMode.checked : true;
    
    // Redraw the timeline frame
    drawTimelineFrame(parseInt(sliderValue), isCumulative);
}

function playTimeline() {
    if (timelineAnimation) {
        clearInterval(timelineAnimation);
        timelineAnimation = null;
        return;
    }
    
    const timelineSlider = document.getElementById('timelineSlider');
    const animateCheckbox = document.getElementById('animateTimeline');
    
    if (!animateCheckbox || !animateCheckbox.checked || !timelineSlider) {
        return;
    }
    
    let currentIndex = 0;
    const maxIndex = parseInt(timelineSlider.max);
    
    timelineAnimation = setInterval(() => {
        if (currentIndex > maxIndex) {
            clearInterval(timelineAnimation);
            timelineAnimation = null;
            return;
        }
        
        timelineSlider.value = currentIndex;
        updateCurrentYear(currentIndex);
        currentIndex++;
    }, 1000); // 1 second per year
}

// ========== REGULAR NETWORK FUNCTIONS ==========
function drawForceGraph(data, options = {}){
    const el = document.getElementById('viz');
    if (!el) return;
    
    const width = el.clientWidth;
    const height = el.clientHeight;

    // Clear previous
    clearViz();

    const svg = d3.select(el).append("svg").attr("width", width).attr("height", height);
    const g = svg.append("g");
    
    // Setup zoom
    currentZoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            g.attr("transform", event.transform);
        });
    
    svg.call(currentZoom);

    const nodes = (data.nodes || []).map(d => Object.assign({}, d));
    const links = (data.links || []).map(d => Object.assign({}, d));

    if (nodes.length === 0) {
        setMsg('No nodes to visualize.');
        return;
    }

    updateNetworkStats(nodes, links);
    updateTopEntities(nodes, options.type || 'subject');

    const colorByYear = yearColorScale(nodes);

    // Create links
    const link = g.append("g")
        .attr("stroke", "var(--bs-primary)")
        .attr("stroke-opacity", 0.3)
        .selectAll("line")
        .data(links)
        .join("line")
        .attr("stroke-width", d => Math.max(1, Math.sqrt(+d.weight || 1)));

    // Create nodes
    const node = g.append("g")
        .selectAll("circle")
        .data(nodes)
        .join("circle")
        .attr("r", d => {
            const deg = +d.degree || +d.papers || +d.freq || 1;
            return Math.max(5, Math.min(20, 4 + Math.sqrt(deg)));
        })
        .attr("fill", d => {
            if (d.group === 'subject' || options.type === 'subject') return colorByYear(d);
            if (d.group === 'author' || options.type === 'author') return "var(--bs-info)";
            return "var(--bs-primary)";
        })
        .attr("fill-opacity", 0.9)
        .attr("stroke", "currentColor")
        .attr("stroke-opacity", 0.2)
        .attr("stroke-width", 1.5)
        .style("cursor", "pointer")
        .on("mouseover", function(event, d) {
            d3.select(this)
                .attr("stroke-opacity", 0.6)
                .attr("stroke-width", 2.5);
            
            showNodeInfo(d);
        })
        .on("mouseout", function(event, d) {
            d3.select(this)
                .attr("stroke-opacity", 0.2)
                .attr("stroke-width", 1.5);
            
            hideNodeInfo();
        })
        .call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

    // Create labels
    const label = g.append("g")
        .selectAll("text")
        .data(nodes)
        .join("text")
        .text(d => {
            const label = d.label || d.name || d.id;
            return label.length > 20 ? label.substring(0, 20) + "..." : label;
        })
        .attr("font-size", 11)
        .attr("fill", "currentColor")
        .attr("opacity", 0.85)
        .attr("dx", 12)
        .attr("dy", 3)
        .style("pointer-events", "none")
        .style("display", labelsVisible ? "block" : "none");

    // Tooltips
    node.append("title").text(d => {
        const t = d.label || d.name || d.id;
        let extra = '';
        if (d.degree || d.freq || d.papers) {
            extra += `\nDegree: ${d.degree || d.freq || d.papers}`;
        }
        if (d.last_year) {
            extra += `\nLast year: ${d.last_year}`;
        }
        if (d.weight) {
            extra += `\nEdge weight: ${d.weight}`;
        }
        return t + extra;
    });

    // Force simulation
    currentSimulation = d3.forceSimulation(nodes)
        .force("link", d3.forceLink(links).id(d => String(d.id)).distance(60).strength(0.5))
        .force("charge", d3.forceManyBody().strength(-150))
        .force("center", d3.forceCenter(width / 2, height / 2))
        .force("collision", d3.forceCollide().radius(d => {
            const deg = +d.degree || +d.papers || +d.freq || 1;
            return Math.max(8, Math.min(25, 5 + Math.sqrt(deg)));
        }));

    currentSimulation.on("tick", () => {
        link
            .attr("x1", d => d.source.x).attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x).attr("y2", d => d.target.y);
        node.attr("cx", d => d.x).attr("cy", d => d.y);
        label.attr("x", d => d.x).attr("y", d => d.y);
    });

    function dragstarted(event, d) {
        if (!event.active) currentSimulation.alphaTarget(0.3).restart();
        d.fx = d.x;
        d.fy = d.y;
    }
    
    function dragged(event, d) {
        d.fx = event.x;
        d.fy = event.y;
    }
    
    function dragended(event, d) {
        if (!event.active) currentSimulation.alphaTarget(0);
        d.fx = null;
        d.fy = null;
    }
}

function drawBubbleChart(data){
    const el = document.getElementById('viz');
    if (!el) return;
    
    const width = el.clientWidth;
    const height = el.clientHeight;

    // Clear previous
    clearViz();

    const svg = d3.select(el).append("svg").attr("width", width).attr("height", height);

    const nodes = (data.nodes || []).map(d => ({
        id: String(d.id),
        label: d.label,
        freq: +d.freq || 1,
        last_year: d.last_year ? +d.last_year : null
    }));

    if (nodes.length === 0) {
        setMsg('No subjects for bubble chart.');
        return;
    }

    updateTopEntities(nodes, 'subject');

    const color = yearColorScale(nodes);
    const pack = d3.pack().size([width, height]).padding(4);

    const root = d3.hierarchy({children: nodes}).sum(d => d.freq);
    const leaves = pack(root).leaves();

    const g = svg.append("g");

    const bubble = g.selectAll("g")
        .data(leaves)
        .join("g")
        .attr("transform", d => `translate(${d.x},${d.y})`)
        .style("cursor", "pointer")
        .on("mouseover", function(event, d) {
            d3.select(this).select("circle")
                .attr("stroke-opacity", 0.6)
                .attr("stroke-width", 2.5);
            
            showNodeInfo(d.data);
        })
        .on("mouseout", function(event, d) {
            d3.select(this).select("circle")
                .attr("stroke-opacity", 0.2)
                .attr("stroke-width", 1);
            
            hideNodeInfo();
        });

    bubble.append("circle")
        .attr("r", d => d.r)
        .attr("fill", d => color(d.data))
        .attr("fill-opacity", 0.85)
        .attr("stroke", "currentColor")
        .attr("stroke-opacity", 0.2)
        .attr("stroke-width", 1);

    bubble.append("title")
        .text(d => `${d.data.label}\nFrequency: ${d.data.freq}\nLast year: ${d.data.last_year || '-'}`);

    bubble.filter(d => d.r > 22)
        .append("text")
        .attr("text-anchor", "middle")
        .attr("dominant-baseline", "middle")
        .attr("font-size", d => Math.min(14, d.r / 3))
        .attr("fill", "currentColor")
        .attr("opacity", 0.9)
        .text(d => {
            const label = d.data.label;
            return label.length > (d.r / 5) ? label.slice(0, d.r / 5) + "…" : label;
        });

    // Network stats for bubble chart
    updateNetworkStats(nodes, []);
}

function showNodeInfo(node) {
    const infoDiv = document.getElementById('nodeInfo');
    if (!infoDiv) return;
    
    const label = node.label || node.name || `Node ${node.id}`;
    const degree = node.degree || node.freq || node.papers || 0;
    const lastYear = node.last_year || 'N/A';
    
    infoDiv.innerHTML = `
        <h6 class="fw-bold mb-2">${label}</h6>
        <div class="small">
            <div><strong>Degree/Frequency:</strong> ${degree}</div>
            ${node.last_year ? `<div><strong>Last Publication Year:</strong> ${lastYear}</div>` : ''}
            ${node.group ? `<div><strong>Type:</strong> ${node.group}</div>` : ''}
            ${node.weight ? `<div><strong>Edge Weight:</strong> ${node.weight}</div>` : ''}
        </div>
    `;
    infoDiv.style.display = 'block';
}

function hideNodeInfo() {
    const infoDiv = document.getElementById('nodeInfo');
    if (infoDiv) {
        infoDiv.style.display = 'none';
    }
}

// ========== CONTROL FUNCTIONS ==========
function zoomIn() {
    if (currentZoom) {
        const svg = d3.select('#viz svg');
        if (svg.size() > 0) {
            svg.transition().duration(300).call(currentZoom.scaleBy, 1.3);
        }
    }
}

function zoomOut() {
    if (currentZoom) {
        const svg = d3.select('#viz svg');
        if (svg.size() > 0) {
            svg.transition().duration(300).call(currentZoom.scaleBy, 0.7);
        }
    }
}

function resetView() {
    if (currentZoom) {
        const svg = d3.select('#viz svg');
        if (svg.size() > 0) {
            svg.transition().duration(300).call(currentZoom.transform, d3.zoomIdentity);
        }
    }
}

function toggleLabels() {
    labelsVisible = !labelsVisible;
    const svg = d3.select('#viz svg');
    if (svg.size() > 0) {
        svg.selectAll('text')
            .style('display', labelsVisible ? 'block' : 'none');
    }
}

function downloadSVG() {
    const svg = document.querySelector('#viz svg');
    if (!svg) {
        alert('No visualization to download');
        return;
    }
    
    const serializer = new XMLSerializer();
    let source = serializer.serializeToString(svg);
    
    // Add namespace
    if(!source.match(/^<svg[^>]+xmlns="http\:\/\/www\.w3\.org\/2000\/svg"/)){
        source = source.replace(/^<svg/, '<svg xmlns="http://www.w3.org/2000/svg"');
    }
    
    source = '<?xml version="1.0" standalone="no"?>\r\n' + source;
    
    const url = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(source);
    const link = document.createElement("a");
    link.href = url;
    link.download = `network_${journalId}_${new Date().toISOString().split('T')[0]}.svg`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ========== MAIN LOAD FUNCTION ==========
async function loadGraph() {
    clearViz();
    
    // Hide timeline controls for non-timeline modes
    const mode = document.getElementById('mode');
    if (!mode) return;
    
    const currentMode = mode.value;
    const timelineControls = document.getElementById('timelineControls');
    
    if (timelineControls) {
        if (currentMode !== 'timeline' && currentMode !== 'yearly_network') {
            timelineControls.style.display = 'none';
        } else {
            timelineControls.style.display = 'block';
        }
    }
    
    if (currentSimulation) {
        currentSimulation.stop();
        currentSimulation = null;
    }
    
    // Stop any running animation
    if (timelineAnimation) {
        clearInterval(timelineAnimation);
        timelineAnimation = null;
    }

    const minW = document.getElementById('minWeight').value;
    const startYear = document.getElementById('startYear').value;
    const endYear = document.getElementById('endYear').value;

    try {
        if (currentMode === 'timeline') {
            // Fetch timeline data
            const data = await fetchJSON(`api.php?op=timeline_network&id=${journalId}&start_year=${startYear}&end_year=${endYear}`);
            
            if (!data || !data.years || data.years.length === 0) {
                setMsg('No timeline data available for the selected years.');
                return;
            }
            
            drawTimelineNetwork(data);
            return;
        }
        
        if (currentMode === 'yearly_network') {
            // Similar to timeline but shows yearly snapshots side by side
            const data = await fetchJSON(`api.php?op=yearly_networks&id=${journalId}&start_year=${startYear}&end_year=${endYear}`);
            
            if (!data || !data.years || data.years.length === 0) {
                setMsg('No yearly network data available.');
                return;
            }
            
            // You need to implement drawYearlyNetworks function
            setMsg('Yearly network visualization is under development.');
            return;
        }

        if (currentMode === 'author') {
            const data = await fetchJSON(`api.php?op=author_network&id=${journalId}&min_weight=${minW}`);
            const nodes = (data.nodes || []).map(d => ({
                id: String(d.id), 
                label: d.name, 
                group: 'author', 
                degree: +d.papers,
                papers: +d.papers
            }));
            const links = (data.links || []).map(d => ({
                source: String(d.author_a), 
                target: String(d.author_b), 
                weight: +d.weight
            }));
            
            if (nodes.length === 0 || links.length === 0) {
                setMsg('Graph is empty. Try lowering the minimum weight to 1.');
            }
            
            drawForceGraph({nodes, links}, {type: 'author'});
            return;
        }

        if (currentMode === 'subject') {
            const data = await fetchJSON(`api.php?op=subject_network&id=${journalId}&min_weight=${minW}`);
            const nodes = (data.nodes || []).map(d => ({
                id: String(d.id),
                label: d.label,
                group: 'subject',
                degree: +d.freq,
                freq: +d.freq,
                last_year: d.last_year ? +d.last_year : null
            }));
            const links = (data.links || []).map(d => ({
                source: String(d.subject_a), 
                target: String(d.subject_b), 
                weight: +d.weight
            }));
            
            if (nodes.length === 0) { 
                setMsg('No subjects found.'); 
                return; 
            }
            
            if (links.length === 0) {
                setMsg('No edges found with this minimum weight. Try minimum weight = 1.');
            }
            
            drawForceGraph({nodes, links}, {type: 'subject'});
            return;
        }

        if (currentMode === 'author_subject') {
            const data = await fetchJSON(`api.php?op=author_subject_network&id=${journalId}&min_weight=${minW}`);
            if (!data.nodes || data.nodes.length === 0) { 
                setMsg('Author-subject data is empty.'); 
                return; 
            }
            drawForceGraph(data, {type: 'mixed'});
            return;
        }

        if (currentMode === 'bubble') {
            const data = await fetchJSON(`api.php?op=subject_bubbles&id=${journalId}&limit=150`);
            drawBubbleChart(data);
            return;
        }

    } catch (e) {
        setMsg('Failed to load: ' + (e.message || e));
        console.error(e);
    }
}

// Initial load
setTimeout(() => {
    loadGraph();
}, 500);
</script>

</body>
</html>