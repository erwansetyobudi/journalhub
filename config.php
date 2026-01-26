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

return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'cekjurnal',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
  ],
  'ttl' => [
    'daily' => 86400,
    'weekly' => 604800,
    'manual' => 0,
  ],
];
