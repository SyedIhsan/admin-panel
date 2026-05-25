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
    exit;
}

// Load db config early — token may live here (env var is the override)
$cfg = require __DIR__ . '/db-config.php';

// Token check for web access (CLI bypasses this)
if (PHP_SAPI !== 'cli') {
    // Priority: env.prod.php > DEMO_RESET_TOKEN env var > db-config reset_token key
    $envFile = __DIR__ . '/env.prod.php';
    if (file_exists($envFile)) {
        $env = require $envFile;
        $expectedToken = (string)($env['DEMO_RESET_TOKEN'] ?? '');
    } else {
        $expectedToken = getenv('DEMO_RESET_TOKEN') ?: ($cfg['reset_token'] ?? '');
    }
    $providedToken = (string)($_GET['token'] ?? '');

    if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
}

// ── DB connection ──────────────────────────────────────────────────────────────
$mysqli = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port'] ?? 3306);
if ($mysqli->connect_errno) {
    http_response_code(500);
    exit("[reset] DB connection failed: {$mysqli->connect_error}\n");
}
$mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');

$startedAt = date('c');

// ── 1. Drop all existing tables and views ─────────────────────────────────────
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$res = $mysqli->query("SHOW FULL TABLES");
$dropped = 0;
while ($row = $res->fetch_array(MYSQLI_NUM)) {
    $tbl  = $row[0];
    $type = strtoupper($row[1] ?? 'BASE TABLE');
    $ddl  = $type === 'VIEW' ? "DROP VIEW IF EXISTS `{$tbl}`" : "DROP TABLE IF EXISTS `{$tbl}`";
    if ($mysqli->query($ddl)) {
        $dropped++;
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

// ── 2. Import schema + seed ────────────────────────────────────────────────────
$schemaPath = __DIR__ . '/schema.sql';
$seedPath   = __DIR__ . '/seed.sql';

foreach ([$schemaPath => 'schema', $seedPath => 'seed'] as $path => $label) {
    if (!is_readable($path)) {
        http_response_code(500);
        exit("[reset] FATAL: cannot read {$label} at {$path}\n");
    }
    $sql = file_get_contents($path);
    if ($mysqli->multi_query($sql)) {
        do {
            if ($r = $mysqli->store_result()) $r->free();
        } while ($mysqli->more_results() && $mysqli->next_result());
    } else {
        http_response_code(500);
        exit("[reset] FATAL: {$label} import failed: {$mysqli->error}\n");
    }
}

// ── 3. Clear demo mail outbox ──────────────────────────────────────────────────
$outbox = __DIR__ . '/mail-outbox';
if (is_dir($outbox)) {
    foreach (glob($outbox . '/*.html') ?: [] as $f) {
        @unlink($f);
    }
}

// ── 4. Log the reset ──────────────────────────────────────────────────────────
$logFile  = __DIR__ . '/reset.log';
$source   = PHP_SAPI === 'cli' ? 'cli' : 'web ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$logEntry = date('c') . " | reset OK | dropped={$dropped} | via={$source}\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// ── 5. Done ────────────────────────────────────────────────────────────────────
$finishedAt = date('c');
echo "Reset complete: {$finishedAt}\n";
