<?php
declare(strict_types=1);

/**
 * BOOTSTRAP DEMO PATCH — REFERENCE ONLY
 * ======================================
 * This file is NOT to be included directly. It's a reference showing
 * what changes Claude Code should apply to the real bootstrap.php during Phase 2.
 *
 * Apply these patches to your actual bootstrap.php, do not replace it wholesale.
 */

// ============================================================================
// PATCH 1: Add at the very top of bootstrap.php, right after declare(strict_types=1);
// ============================================================================

define('DEMO_MODE', true);
define('DEMO_VERSION', '1.0');

// Optional: simulate any environment-specific branching by always picking the dev path
define('ENVIRONMENT', 'demo');

// ============================================================================
// PATCH 2: Replace ENV_PAY_WHERE setup with a pass-through filter
// Original logic was: localhost = test data only, production = exclude test data.
// Demo mode = show everything.
// ============================================================================

define('ENV_PAY_WHERE', '1=1');

// ============================================================================
// PATCH 3: Skip MFA in is_admin() (in auth.php, not bootstrap.php)
// Replace any MFA verification check with:
// ============================================================================

/*
function is_admin(): bool {
    if (defined('DEMO_MODE') && DEMO_MODE) {
        // Demo: only check that they logged in. Skip MFA, skip device trust.
        return !empty($_SESSION['admin_id']);
    }
    // ... original production logic unchanged below ...
}
*/

// ============================================================================
// PATCH 4: Update DB connection loading to read from demo/db-config.php
// Replace any hardcoded DB credentials in api/db.php (and elearning/db.php) with:
// ============================================================================

/*
$cfgPath = __DIR__ . '/../demo/db-config.php';
if (!is_readable($cfgPath)) {
    die('Demo not configured. Copy demo/db-config.example.php to demo/db-config.php and fill it in.');
}
$cfg = require $cfgPath;

$conn = new mysqli(
    $cfg['host'],
    $cfg['user'],
    $cfg['pass'],
    $cfg['name'],
    $cfg['port'] ?? 3306
);
if ($conn->connect_errno) {
    die('DB connection failed: ' . $conn->connect_error);
}
$conn->set_charset($cfg['charset'] ?? 'utf8mb4');
*/

// ============================================================================
// PATCH 5: Collapse db_router.php — both accessors return the same conn
// ============================================================================

/*
function getBillingConn(): mysqli {
    global $conn;
    return $conn;
}

function getElearningConn(): mysqli {
    global $conn;  // demo: same DB
    return $conn;
}

function getDbConnByOrderId(string $orderId): mysqli {
    global $conn;
    return $conn;
}

function isElearningOrder(string $orderId): bool {
    return false;  // demo simplification
}
*/

// ============================================================================
// PATCH 6: Guard any POST handler that sends real email or money
// Wrap the destructive part of each action handler:
// ============================================================================

/*
if (defined('DEMO_MODE') && DEMO_MODE) {
    $_SESSION['flash_success'] = '[DEMO] Action simulated — nothing was actually sent / charged.';
    redirect($returnUrl);
    exit;
}
// ... original action logic continues here for production ...
*/
