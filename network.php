<?php
/*
 * File: network.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:54:28 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */


declare(strict_types=1);
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
// FIXED: menggunakan rumpunilmu_id, bukan subject
$journal = q("SELECT j.*, ri.nama_rumpun, p.name as publisher_name 
              FROM journals j 
              LEFT JOIN rumpunilmu ri ON j.rumpunilmu_id = ri.rumpunilmu_id
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
      position: relative;
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
    
    .node circle {
      cursor: pointer;
      transition: r 0.2s;
    }
    
    .node circle:hover {
      stroke-width: 3px !important;
    }
    
    .link {
      stroke-opacity: 0.6;
    }
    
    .label {
      font-size: 10px;
      pointer-events: none;
      text-shadow: 1px 1px 0 white;
    }
    
    [data-bs-theme="dark"] .label {
      fill: #ecf0f1;
      text-shadow: 1px 1px 0 #2d2d2d;
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
          </div>
          
          <div class="col-md-3">
            <label class="form-label fw-semibold">
              <i class="bi bi-sliders me-1"></i> Layout
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
              <i class="bi bi-play-circle me-1"></i> Generate
            </button>
          </div>
        </div>
        
        <div class="row mt-3">
          <div class="col-12">
            <div class="d-flex flex-wrap gap-3 align-items-center">
              <div class="fw-semibold">Legend:</div>
              <div class="d-flex align-items-center me-3">
                <span class="legend-box" style="background: #1f77b4;"></span>
                <small>Node (semakin besar = semakin terhubung)</small>
              </div>
              <div class="d-flex align-items-center">
                <span class="legend-box" style="background: #ccc;"></span>
                <small>Edge (semakin tebal = semakin kuat koneksi)</small>
              </div>
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
            <h5>Select visualization mode and click "Generate"</h5>
            <p class="small">Network visualization will appear here</p>
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
<?php include 'footer.php';?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
<script>
const journalId = <?= (int)$id ?>;
let currentSimulation = null;
let currentZoom = null;
let labelsVisible = true;

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
});

async function loadGraph() {
    const mode = document.getElementById('mode').value;
    const minWeight = parseInt(document.getElementById('minWeight').value);
    const layout = document.getElementById('layout').value;
    
    // Show loading indicator
    const vizDiv = document.getElementById('viz');
    vizDiv.innerHTML = '<div class="d-flex justify-content-center align-items-center h-100"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    try {
        let url = `api.php?op=network_data&id=${journalId}&mode=${mode}&minWeight=${minWeight}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            vizDiv.innerHTML = `<div class="text-center text-danger p-5">Error: ${data.error}</div>`;
            return;
        }
        
        if (!data.nodes || data.nodes.length === 0) {
            vizDiv.innerHTML = '<div class="text-center text-muted p-5">No data available for the selected criteria.</div>';
            return;
        }
        
        renderGraph(data, layout, mode);
        updateStatistics(data);
        updateTopEntities(data, mode);
        
    } catch (error) {
        console.error('Error loading graph:', error);
        vizDiv.innerHTML = `<div class="text-center text-danger p-5">Error loading network data: ${error.message}</div>`;
    }
}

function renderGraph(data, layout, mode) {
    const vizDiv = document.getElementById('viz');
    vizDiv.innerHTML = '';
    
    const width = vizDiv.clientWidth;
    const height = vizDiv.clientHeight;
    
    const svg = d3.select("#viz")
        .append("svg")
        .attr("width", width)
        .attr("height", height)
        .append("g");
    
    // Add zoom behavior
    const zoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            svg.attr("transform", event.transform);
        });
    
    d3.select("#viz svg").call(zoom);
    
    // Calculate node sizes based on degree
    const nodeDegrees = new Map();
    data.links.forEach(link => {
        nodeDegrees.set(link.source, (nodeDegrees.get(link.source) || 0) + 1);
        nodeDegrees.set(link.target, (nodeDegrees.get(link.target) || 0) + 1);
    });
    
    const maxDegree = Math.max(...Array.from(nodeDegrees.values()), 1);
    
    data.nodes.forEach(node => {
        const degree = nodeDegrees.get(node.id) || 1;
        node.size = 5 + (degree / maxDegree) * 20;
    });
    
    // Create force simulation
    const simulation = d3.forceSimulation(data.nodes)
        .force("link", d3.forceLink(data.links).id(d => d.id).distance(100))
        .force("charge", d3.forceManyBody().strength(-300))
        .force("center", d3.forceCenter(width / 2, height / 2));
    
    if (layout === 'radial') {
        simulation.force("radial", d3.forceRadial(Math.min(width, height) / 3, width / 2, height / 2));
    } else if (layout === 'grid') {
        const cols = Math.ceil(Math.sqrt(data.nodes.length));
        data.nodes.forEach((node, i) => {
            node.fx = (i % cols) * (width / cols) + 50;
            node.fy = Math.floor(i / cols) * (height / cols) + 50;
        });
        simulation.force("link", null);
    } else if (layout === 'circle') {
        const radius = Math.min(width, height) / 3;
        data.nodes.forEach((node, i) => {
            const angle = (i / data.nodes.length) * Math.PI * 2;
            node.fx = width / 2 + radius * Math.cos(angle);
            node.fy = height / 2 + radius * Math.sin(angle);
        });
        simulation.force("link", null);
    }
    
    // Draw links
    const link = svg.append("g")
        .selectAll("line")
        .data(data.links)
        .enter()
        .append("line")
        .attr("stroke", "#999")
        .attr("stroke-opacity", 0.6)
        .attr("stroke-width", d => Math.sqrt(d.weight) * 1.5);
    
    // Draw nodes
    const node = svg.append("g")
        .selectAll("g")
        .data(data.nodes)
        .enter()
        .append("g")
        .attr("class", "node")
        .call(drag(simulation));
    
    node.append("circle")
        .attr("r", d => d.size)
        .attr("fill", mode === 'author' ? "#3498db" : "#2ecc71")
        .attr("stroke", "#fff")
        .attr("stroke-width", 2)
        .on("click", (event, d) => showNodeInfo(d, mode))
        .on("mouseenter", (event, d) => {
            d3.select(event.currentTarget).attr("stroke-width", 4);
        })
        .on("mouseleave", (event, d) => {
            d3.select(event.currentTarget).attr("stroke-width", 2);
        });
    
    // Add labels
    const labelGroup = svg.append("g").attr("class", "labels");
    const labels = labelGroup.selectAll("text")
        .data(data.nodes)
        .enter()
        .append("text")
        .attr("class", "label")
        .attr("dx", 12)
        .attr("dy", 4)
        .text(d => d.name.length > 25 ? d.name.substring(0, 22) + "..." : d.name)
        .style("font-size", "10px")
        .style("fill", getComputedStyle(document.documentElement).getPropertyValue('--bs-body-color'));
    
    // Update positions on tick
    simulation.on("tick", () => {
        link
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y);
        
        node
            .attr("transform", d => `translate(${d.x},${d.y})`);
        
        labels
            .attr("x", d => d.x)
            .attr("y", d => d.y);
    });
    
    currentSimulation = simulation;
    
    // Store zoom for controls
    currentZoom = zoom;
    d3.select("#viz svg").call(zoom);
}

function drag(simulation) {
    function dragstarted(event) {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        event.subject.fx = event.subject.x;
        event.subject.fy = event.subject.y;
    }
    
    function dragged(event) {
        event.subject.fx = event.x;
        event.subject.fy = event.y;
    }
    
    function dragended(event) {
        if (!event.active) simulation.alphaTarget(0);
        event.subject.fx = null;
        event.subject.fy = null;
    }
    
    return d3.drag()
        .on("start", dragstarted)
        .on("drag", dragged)
        .on("end", dragended);
}

function showNodeInfo(node, mode) {
    const infoDiv = document.getElementById('nodeInfo');
    infoDiv.style.display = 'block';
    infoDiv.innerHTML = `
        <strong>${mode === 'author' ? 'Author' : 'Subject'}</strong><br>
        <strong>${escapeHtml(node.name)}</strong><br>
        <hr class="my-1">
        <small>ID: ${node.id}</small><br>
        <small>Connections: ${nodeDegrees?.get(node.id) || 0}</small>
    `;
    
    setTimeout(() => {
        infoDiv.style.display = 'none';
    }, 3000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateStatistics(data) {
    const statsDiv = document.getElementById('networkStats');
    const nodeCount = data.nodes.length;
    const linkCount = data.links.length;
    const avgDegree = (linkCount * 2 / nodeCount).toFixed(2);
    
    statsDiv.innerHTML = `
        <div class="list-group list-group-flush">
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span>Nodes</span>
                <span class="badge bg-primary rounded-pill">${nodeCount}</span>
            </div>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span>Edges</span>
                <span class="badge bg-primary rounded-pill">${linkCount}</span>
            </div>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span>Average Degree</span>
                <span class="badge bg-primary rounded-pill">${avgDegree}</span>
            </div>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span>Network Density</span>
                <span class="badge bg-primary rounded-pill">${((linkCount * 2) / (nodeCount * (nodeCount - 1)) * 100).toFixed(2)}%</span>
            </div>
        </div>
    `;
}

function updateTopEntities(data, mode) {
    const entitiesDiv = document.getElementById('topEntities');
    
    // Calculate degree for each node
    const degree = new Map();
    data.links.forEach(link => {
        degree.set(link.source, (degree.get(link.source) || 0) + 1);
        degree.set(link.target, (degree.get(link.target) || 0) + 1);
    });
    
    const nodesWithDegree = data.nodes.map(node => ({
        ...node,
        degree: degree.get(node.id) || 0
    }));
    
    const topNodes = nodesWithDegree
        .sort((a, b) => b.degree - a.degree)
        .slice(0, 10);
    
    entitiesDiv.innerHTML = `
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>${mode === 'author' ? 'Author Name' : 'Subject Label'}</th>
                    <th class="text-end">Connections</th>
                </tr>
            </thead>
            <tbody>
                ${topNodes.map((node, i) => `
                    <tr>
                        <td>${i + 1}</td>
                        <td><small>${escapeHtml(node.name)}</small></td>
                        <td class="text-end"><span class="badge bg-secondary">${node.degree}</span></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

function zoomIn() {
    const svg = d3.select("#viz svg");
    const transform = d3.zoomTransform(svg.node());
    svg.transition().call(currentZoom.scaleBy, 1.2);
}

function zoomOut() {
    const svg = d3.select("#viz svg");
    svg.transition().call(currentZoom.scaleBy, 0.8);
}

function resetView() {
    const svg = d3.select("#viz svg");
    svg.transition().call(currentZoom.transform, d3.zoomIdentity);
}

function toggleLabels() {
    labelsVisible = !labelsVisible;
    d3.selectAll(".label").style("opacity", labelsVisible ? 1 : 0);
}

// Handle window resize
window.addEventListener('resize', () => {
    if (currentSimulation) {
        const vizDiv = document.getElementById('viz');
        const width = vizDiv.clientWidth;
        const height = vizDiv.clientHeight;
        
        d3.select("#viz svg")
            .attr("width", width)
            .attr("height", height);
        
        currentSimulation.force("center", d3.forceCenter(width / 2, height / 2));
        currentSimulation.alpha(0.3).restart();
    }
});
</script>
</body>
</html>