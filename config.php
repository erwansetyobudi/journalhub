<?php
/*
 * File: config.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:08:55 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */


if (php_sapi_name() === 'cli-server') {
    // untuk development server built-in PHP
    if ($_SERVER['SCRIPT_FILENAME'] === __FILE__) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access Forbidden');
    }
} else {
    // untuk server production
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access Forbidden');
    }
}

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'cekjurnalkosongan',
        'user' => 'root',
        'pass' => 'root',
        'charset' => 'utf8mb4',
    ],
    'ttl' => [
        'daily' => 86400,   // 24 jam
        'weekly' => 604800, // 7 hari
        'manual' => 0       // tidak ada TTL
    ],
    'oai' => [
        'timeout' => 60,
        'retries' => 3,
        'user_agent' => 'OAI-Harvester/2.0 (Compatible; PHP)'
    ]
];