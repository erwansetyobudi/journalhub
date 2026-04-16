<?php
/*
 * File: db.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * Journal Hub: Aplikasi Harvesting Metadata Jurnal Akademik Berbasis OAI-PMH
 * License: The GNU General Public License, Version 3 (GPL-3.0)
 */

/**
 * Database connection helper
 */

declare(strict_types=1);

$dbConfig = null;
$pdo = null;

function db(): PDO {
    global $pdo, $dbConfig;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    if ($dbConfig === null) {
        $dbConfig = require __DIR__ . '/config.php';
        $dbConfig = $dbConfig['db'];
    }
    
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['name'],
        $dbConfig['charset']
    );
    
    try {
        $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
    
    return $pdo;
}

function q(string $sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// FIXED: fungsi h() sekarang bisa menerima berbagai tipe data
function h($str): string {
    if ($str === null) {
        return '';
    }
    if (is_array($str) || is_object($str)) {
        return '';
    }
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}