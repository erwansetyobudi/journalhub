<?php
/*
 * File: add_subject.php
 * Created on Thu Apr 16 2026
 * Last Updated: Thu Apr 16 2026 1:54:45 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */


require_once __DIR__ . '/db.php';

if (isset($_POST['label'])) {
  $label = trim($_POST['label']);

  if ($label !== '') {
    q("INSERT INTO subjects (label) VALUES (?)", [$label]);
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
  } else {
    echo json_encode(['success' => false]);
  }
} else {
  echo json_encode(['success' => false]);
}
