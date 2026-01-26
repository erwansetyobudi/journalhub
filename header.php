<!-- JournalHub
 
Copyright (C) 2026Erwan Setyo Budi (erwans818@gmail.com)
 
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.
 
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA02110-1301USA -->


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