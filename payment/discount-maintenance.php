<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

// CLI detect (lebih robust)
$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || defined('STDIN'));

if (!$isCli) {
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Accel-Expires: 0');
  header('Surrogate-Control: no-store');
  header('X-Robots-Tag: noindex, nofollow', true);

  // anti-proxy-cache trick
  header('Vary: *');
  header('X-Maintenance-Run: ' . date('c')); // ikut Asia/KL
}

// Ensure storage folder awal (supaya log boleh tulis walaupun auth fail)
$storageDir = __DIR__ . "/storage";
if (!is_dir($storageDir)) {
  @mkdir($storageDir, 0755, true);
}
$logFile = $storageDir . "/discount-cron-run.log";
$log = function (string $msg) use ($logFile) {
  @file_put_contents($logFile, date("Y-m-d H:i:s") . " " . $msg . "\n", FILE_APPEND);
};

// DB
require_once dirname(__DIR__) . "/api/db.php";
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (!$isCli) http_response_code(500);
  $log("ERROR DB not connected");
  exit("DB not connected\n");
}
$conn->query("SET time_zone = '+08:00'");

// Load .env dari folder /payment (getenv() tak baca .env)
$envFile = __DIR__ . "/.env";
if (is_file($envFile) && is_readable($envFile)) {
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === "" || strpos($line, "#") === 0) continue;
    if (strpos($line, "=") === false) continue;

    [$k, $v] = explode("=", $line, 2);
    $k = trim($k);
    $v = trim($v);
    $v = rtrim($v, "\r\n");
    $v = trim($v, "\"'");

    if ($k !== "") {
      putenv($k . "=" . $v);
      $_ENV[$k] = $v;
      $_SERVER[$k] = $v;
    }
  }
}

// Ambil CRON_KEY dari mana-mana tempat yang available
$expect = (string)(getenv("CRON_KEY") ?: "");
if ($expect === "") $expect = (string)($_SERVER["CRON_KEY"] ?? "");
if ($expect === "") $expect = (string)($_ENV["CRON_KEY"] ?? "");

// Protect endpoint
$key = $isCli
  ? $expect // ✅ CLI: auto guna key dari .env
  : (string)($_GET["key"] ?? "");

if ($expect === "") {
  if (!$isCli) http_response_code(500);
  $log("ERROR CRON_KEY not set via=" . ($isCli ? "cli" : "web"));
  exit("CRON_KEY not set\n");
}

if (!hash_equals($expect, $key)) {
  if (!$isCli) http_response_code(403);
  $log("ERROR forbidden via=" . ($isCli ? "cli" : "web"));
  exit("forbidden\n");
}

// 1) Expired -> inactive (strict-mode friendly)
$conn->query("
  UPDATE Discount_Codes
  SET status='inactive'
  WHERE status='active'
    AND valid_until IS NOT NULL
    AND valid_until < NOW()
");
$affExpired = (int)$conn->affected_rows;

// 2) Max reached -> inactive
$conn->query("
  UPDATE Discount_Codes dc
  JOIN (
    SELECT discount_code_id, COUNT(*) AS paid_count
    FROM Discount_Redemptions
    WHERE status='paid'
    GROUP BY discount_code_id
  ) r ON r.discount_code_id = dc.id
  SET dc.status='inactive'
  WHERE dc.status='active'
    AND dc.max_redemptions IS NOT NULL
    AND r.paid_count >= dc.max_redemptions
");
$affMax = (int)$conn->affected_rows;

$log("OK exp={$affExpired} max={$affMax} via=" . ($isCli ? "cli" : "web"));

echo "ok exp={$affExpired} max={$affMax} run=" . date('c') . "\n";