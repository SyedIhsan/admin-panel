<?php
declare(strict_types=1);

/**
 * Demo database configuration.
 *
 * Copy this file to db-config.php and fill in your credentials.
 * db-config.php is gitignored — never commit real creds.
 *
 * For InfinityFree free hosting:
 *   DB_HOST is shown on the MySQL Databases page in cPanel, e.g. sql203.infinityfree.com
 *   DB_NAME is auto-prefixed like "if0_12345678_demo"
 *   DB_USER is auto-generated like "if0_12345678"
 *   DB_PASS is what you set when creating the database
 *
 * For local XAMPP / Laragon:
 *   DB_HOST = 'localhost' or '127.0.0.1'
 *   DB_USER = 'root'
 *   DB_PASS = ''
 *   DB_NAME = whatever you create
 */

return [
    'host'    => 'localhost',
    'name'    => 'sdc_demo',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
    'port'    => 3306,
];
