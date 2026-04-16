<?php
/*
 * File: header.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:56:01 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */

?>
<header class="sticky-top bg-white shadow-sm">
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <!-- Logo dan Nama Aplikasi -->
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                    <i class="bi bi-journal-text text-white"></i>
                </div>
                <span class="fw-bold fs-4 text-primary">JournalHub</span>
            </a>
            
            <!-- Menu Navigasi -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
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
</header>