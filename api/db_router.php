<?php
// api/db_router.php — demo mode: single DB for both billing and e-learning

declare(strict_types=1);

$legacyDbFile = __DIR__ . '/db.php';

if (!file_exists($legacyDbFile)) {
    http_response_code(500);
    exit("Missing DB file: {$legacyDbFile}");
}

require_once $legacyDbFile;

// In demo, both connections point to the same single DB
$conn_legacy    = $conn;
$conn_elearning = $conn;

if (!function_exists('normalizeOrderKey')) {
    function normalizeOrderKey(string $order_id): string {
        $order_id = trim($order_id);
        $pos = stripos($order_id, '-oid-');
        return ($pos !== false) ? substr($order_id, 0, $pos) : $order_id;
    }
}

if (!function_exists('isElearningOrder')) {
    function isElearningOrder(string $order_id): bool {
        $key = strtolower(normalizeOrderKey($order_id));
        if (preg_match('/^(beg|int|adv)-\d+$/i', $key)) return true;
        if ($key === 'e-learning') return true;
        return false;
    }
}

if (!function_exists('getDbConnByOrderId')) {
    function getDbConnByOrderId(string $order_id): mysqli {
        global $conn;
        return $conn;
    }
}

if (!function_exists('getBillingConn')) {
    function getBillingConn(): mysqli {
        global $conn;
        return $conn;
    }
}

if (!function_exists('getElearningConn')) {
    function getElearningConn(): mysqli {
        global $conn;
        return $conn;
    }
}
