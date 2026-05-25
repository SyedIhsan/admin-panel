<?php
declare(strict_types=1);

/**
 * Demo data reset script.
 *
 * Drops all tables and re-imports schema.sql + seed.sql.
 * Run manually from CLI, or trigger via cron-job.org hitting a token-protected URL.
 *
 * CLI usage:
 *   php demo/reset.php
 *
 * Web usage (token-protected, for cron-job.org):
 *   https://yourdemo.infinityfreeapp.com/demo/reset.php?token=YOUR_RESET_TOKEN
 *
 * SAFETY: only runs if DEMO_MODE is enabled in bootstrap.
 */

require_once __DIR__ . '/../admin/bootstrap.php';

if (!defined('DEMO_MODE') || DEMO_MODE !== true) {
    http_response_code(403);
    exit('Reset is only allowed in DEMO_MODE.');
}

// Token check for web access (skip if CLI)
$expectedToken = getenv('DEMO_RESET_TOKEN') ?: 'change-me-in-production';
if (PHP_SAPI !== 'cli') {
    $providedToken = $_GET['token'] ?? '';
    if (!hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        exit('Invalid or missing reset token.');
    }
    header('Content-Type: text/plain');
}

$cfg = require __DIR__ . '/db-config.php';
$mysqli = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port'] ?? 3306);
if ($mysqli->connect_errno) {
    exit("DB connection failed: {$mysqli->connect_error}\n");
}
$mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');

echo "[reset] Starting demo data reset at " . date('c') . PHP_EOL;

// 1. Drop all existing tables
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$res = $mysqli->query("SHOW TABLES");
$dropped = 0;
while ($row = $res->fetch_array(MYSQLI_NUM)) {
    $tbl = $row[0];
    if ($mysqli->query("DROP TABLE IF EXISTS `{$tbl}`")) {
        $dropped++;
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
echo "[reset] Dropped {$dropped} table(s).\n";

// 2. Import schema
$schemaPath = __DIR__ . '/schema.sql';
$seedPath   = __DIR__ . '/seed.sql';

foreach ([$schemaPath => 'schema', $seedPath => 'seed'] as $path => $label) {
    if (!is_readable($path)) {
        exit("[reset] FATAL: cannot read {$label} at {$path}\n");
    }
    $sql = file_get_contents($path);
    if ($mysqli->multi_query($sql)) {
        do {
            if ($r = $mysqli->store_result()) $r->free();
        } while ($mysqli->more_results() && $mysqli->next_result());
        echo "[reset] Imported {$label} OK.\n";
    } else {
        exit("[reset] FATAL: {$label} import failed: {$mysqli->error}\n");
    }
}

// 3. Clear demo mail outbox
$outbox = __DIR__ . '/mail-outbox';
if (is_dir($outbox)) {
    $cleared = 0;
    foreach (glob($outbox . '/*.html') ?: [] as $f) {
        @unlink($f);
        $cleared++;
    }
    echo "[reset] Cleared {$cleared} mail-outbox file(s).\n";
}

echo "[reset] Done at " . date('c') . PHP_EOL;
