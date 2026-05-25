<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

$uri = $_SERVER['REQUEST_URI'] ?? '';
if (str_starts_with($uri, '/admin/')) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private, s-maxage=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Accel-Expires: 0');
}

// Reuse existing auth and db logic with absolute paths
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db.php";

if (!isset($conn) || !$conn) {
  http_response_code(500);
  exit("Database unavailable.");
}
$conn->query("SET time_zone = '+08:00'");

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

