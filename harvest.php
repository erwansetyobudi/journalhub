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

if ($target === 'all') {
  $ids = q("SELECT id FROM journals WHERE enabled=1")->fetchAll();
  foreach ($ids as $row) {
    $id = (int)$row['id'];
    try {
      $res = harvest_journal($id, $force, 0);
      if (!empty($res['skipped'])) {
        echo "Journal $id: SKIP (TTL)\n";
      } else {
        echo "Journal $id: OK inserted={$res['inserted']} updated={$res['updated']} active={$res['active']} deleted={$res['deleted']} authors={$res['unique_authors']} subjects={$res['unique_subjects']}\n";
      }
    } catch (Throwable $e) {
      echo "Journal $id: ERROR " . $e->getMessage() . "\n";
    }
  }
  exit;
}

$id = (int)$target;
try {
  $res = harvest_journal($id, $force, 0);
  if (!empty($res['skipped'])) echo "Journal $id: SKIP (TTL)\n";
  else echo "Journal $id: OK inserted={$res['inserted']} updated={$res['updated']}\n";
} catch (Throwable $e) {
  echo "Journal $id: ERROR " . $e->getMessage() . "\n";
  exit(2);
}
