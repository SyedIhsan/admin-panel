<?php
declare(strict_types=1);

// Environment Detection
if (!defined('IS_LOCALHOST')) {
  define('IS_LOCALHOST', in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8000']));
}

require_once __DIR__ . '/../bootstrap.php';

/** @var mysqli $conn */
$conn = $conn ?? (function_exists('getBillingConn') ? getBillingConn() : null);

if (!$conn) {
  http_response_code(500);
  exit("Database unavailable.");
}

$conn->query("SET time_zone = '+08:00'");

// Ensure admin is authenticated
if (!is_admin()) {
  header("Location: /admin/login.php?next=" . urlencode($_SERVER['REQUEST_URI']));
  exit;
}

/**
 * Helper to escape HTML
 */
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
    }
}

/**
 * Format date readable
 */
if (!function_exists('fmtDate')) {
  function fmtDate(string $v): string {
        $s = trim((string)$v);
        if ($s === "") return "";
        $ts = strtotime($s);
        if ($ts === false) return $s;
        return date("d M Y, H:i", $ts);
    }
}
