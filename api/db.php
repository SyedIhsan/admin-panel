<?php
// db.php — demo-safe, reads credentials from demo/db-config.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$_demo_cfg_path = realpath(__DIR__ . '/../demo/db-config.php');
if (!$_demo_cfg_path || !file_exists($_demo_cfg_path)) {
    error_log('Demo DB config missing. Copy demo/db-config.example.php to demo/db-config.php and fill in credentials.');
    $conn = null;
    return;
}

$_demo_cfg = require $_demo_cfg_path;

$conn = null;
if (!class_exists('mysqli')) {
    error_log('PHP Error: mysqli extension is not enabled.');
    die('Internal Server Error: Database driver missing.');
}
try {
    $conn = new mysqli(
        $_demo_cfg['host'] ?? 'localhost',
        $_demo_cfg['user'] ?? '',
        $_demo_cfg['pass'] ?? '',
        $_demo_cfg['name'] ?? '',
        (int)($_demo_cfg['port'] ?? 3306)
    );
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset($_demo_cfg['charset'] ?? 'utf8mb4');
} catch (Exception $e) {
    error_log('DB Connection Error: ' . $e->getMessage());
    $conn = null;
}
