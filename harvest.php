<?php
/*
 * File: harvest.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:53:29 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */


declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_oai.php';

$args = $argv;
array_shift($args);

if (!$args) {
    echo "Usage:\n";
    echo "  php harvest.php all [--force]\n";
    echo "  php harvest.php <journal_id> [--force]\n";
    exit(1);
}

$target = $args[0];
$force = in_array('--force', $args, true);

echo "[" . date('Y-m-d H:i:s') . "] Starting harvest...\n";

if ($target === 'all') {
    $ids = q("SELECT id, name FROM journals WHERE enabled=1")->fetchAll();
    foreach ($ids as $row) {
        $id = (int)$row['id'];
        echo "Harvesting: {$row['name']} (ID: $id)\n";
        try {
            $res = harvest_journal($id, $force, 0);
            if (!empty($res['skipped'])) {
                echo "  SKIP (TTL)\n";
            } else {
                echo "  OK: inserted={$res['inserted']}, updated={$res['updated']}, active={$res['active']}\n";
            }
        } catch (Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }
    }
    exit;
}

$id = (int)$target;
try {
    $journal = q("SELECT name FROM journals WHERE id=?", [$id])->fetch();
    $name = $journal['name'] ?? "Journal $id";
    echo "Harvesting: $name (ID: $id)\n";
    $res = harvest_journal($id, $force, 0);
    if (!empty($res['skipped'])) {
        echo "  SKIP (TTL)\n";
    } else {
        echo "  OK: inserted={$res['inserted']}, updated={$res['updated']}, active={$res['active']}\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(2);
}

echo "[" . date('Y-m-d H:i:s') . "] Finished.\n";