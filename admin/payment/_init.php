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

// login/auth (admins) - elearning DB
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";

// payment DB
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db.php";
if (!isset($conn) || !$conn) {
  http_response_code(500);
  exit("Payment DB unavailable.");
}
$conn->query("SET time_zone = '+08:00'");

$PAY_STORAGE_DIR = rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/payment/storage";
if (!is_dir($PAY_STORAGE_DIR)) @mkdir($PAY_STORAGE_DIR, 0755, true);

$PAY_POSTERS_DIR = $PAY_STORAGE_DIR . "/posters";
if (!is_dir($PAY_POSTERS_DIR)) @mkdir($PAY_POSTERS_DIR, 0755, true);

// Environment-based Data Filtering (Constants defined in bootstrap.php)
$ENV_PAY_WHERE = defined('ENV_PAY_WHERE') ? ENV_PAY_WHERE : "1=1";