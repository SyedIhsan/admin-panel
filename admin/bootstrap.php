<?php
declare(strict_types=1);

// PATCH 1: Demo mode flag
define('DEMO_MODE', true);
define('DEMO_VERSION', '1.0');
define('ENVIRONMENT', 'demo');

// PATCH 2: Always show all records in demo (no prod/test split)
if (!defined('ENV_PAY_WHERE')) define('ENV_PAY_WHERE', '1=1');

if (!defined('IS_LOCALHOST')) {
    define('IS_LOCALHOST', in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8000']));
}

// PATCH 4: DB connection from demo/db-config.php (via db_router which loads db.php)
require_once __DIR__ . '/../api/db_router.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    exit("DB unavailable. Copy demo/db-config.example.php to demo/db-config.php and configure credentials.");
}

$conn->query("SET time_zone = '+08:00'");

$secure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

session_set_cookie_params([
    "path"     => "/admin",
    "secure"   => $secure,
    "httponly" => true,
    "samesite" => "Lax",
]);

session_start();

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function redirect(string $path): never {
    header("Location: " . $path);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

function csrf_validate(): void {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") return;

    $t = (string)($_POST["csrf"] ?? "");

    if ($t === "" && !empty($_SERVER["HTTP_X_CSRF_TOKEN"])) {
        $t = (string)$_SERVER["HTTP_X_CSRF_TOKEN"];
    }

    if ($t === "" && (($_SERVER["CONTENT_TYPE"] ?? "") !== "") && str_contains($_SERVER["CONTENT_TYPE"], "application/json")) {
        $raw = file_get_contents("php://input");
        $in = json_decode($raw, true);
        if (is_array($in) && !empty($in["csrf"])) $t = (string)$in["csrf"];
    }

    if ($t === "" || empty($_SESSION["csrf"]) || !hash_equals((string)$_SESSION["csrf"], $t)) {
        http_response_code(403);
        exit("CSRF blocked.");
    }
}

// PATCH 3: Simplified is_admin — no MFA, no verification expiry
function is_admin(): bool {
    return !empty($_SESSION["admin_id"]);
}

function is_admin_identity(): bool {
    return !empty($_SESSION["admin_id"]);
}

function get_current_db(): string {
    global $conn;
    if (!isset($conn) || !$conn) return '';
    $res = $conn->query("SELECT DATABASE() AS db");
    if (!$res) return '';
    $row = $res->fetch_assoc();
    return (string)($row['db'] ?? '');
}
