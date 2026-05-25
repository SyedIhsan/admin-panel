<?php
// admin/elearning/db.php — demo-safe, single DB (same as billing DB)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$_demo_cfg_path = realpath(__DIR__ . '/../../demo/db-config.php');
if (!$_demo_cfg_path || !file_exists($_demo_cfg_path)) {
    error_log('Demo DB config missing. Copy demo/db-config.example.php to demo/db-config.php.');
    http_response_code(500);
    exit('DB config missing.');
}

$_demo_cfg = require $_demo_cfg_path;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(
        $_demo_cfg['host'] ?? 'localhost',
        $_demo_cfg['user'] ?? '',
        $_demo_cfg['pass'] ?? '',
        $_demo_cfg['name'] ?? '',
        (int)($_demo_cfg['port'] ?? 3306)
    );
    $conn->set_charset($_demo_cfg['charset'] ?? 'utf8mb4');
    $conn_elearning = $conn;
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('DB connection failed (e-Learning).');
}

if (!defined('IS_LOCALHOST')) {
    define('IS_LOCALHOST', in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8000']));
}

// Demo: show all records — no production filter needed
if (!defined('EL_EMAIL_FILTER')) define('EL_EMAIL_FILTER', '1=1');
if (!defined('EL_OP_EMAIL_FILTER')) define('EL_OP_EMAIL_FILTER', '1=1');
if (!defined('ENV_PAY_WHERE')) define('ENV_PAY_WHERE', '1=1');
