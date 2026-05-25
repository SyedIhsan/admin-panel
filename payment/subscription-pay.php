<?php
// /payment/subscription-pay.php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

$ROOT = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

require_once $ROOT . '/api/db_router.php';

// Demo mode: show a stub subscription checkout page
if (defined('DEMO_MODE') && DEMO_MODE) {
    $demoId = 'DEMO-SUB-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    header('Location: /demo-gateway.php?order_id=' . urlencode($demoId) . '&type=subscription');
    exit;
}

$token = trim((string)($_GET['t'] ?? ''));

if ($token === '') {
  http_response_code(400);
  die("Error: Missing access token.");
}

$conn = getBillingConn();
if (!$conn) {
  http_response_code(500);
  die("Error: Database connection failed.");
}

// 1. Validate Token
$tokenHash = hash('sha256', $token);
$stmt = $conn->prepare("
  SELECT t.subscription_id, t.action_type, t.expires_at, t.used_at,
         s.product_id, s.product_category_id, s.customer_name, s.customer_email,
         s.remaining_month_price, s.retention_price
  FROM Subscription_Action_Tokens t
  JOIN Subscriptions s ON t.subscription_id = s.id
  WHERE t.token_hash = ? AND t.used_at IS NULL
  LIMIT 1
");

if (!$stmt) {
  http_response_code(500);
  die("Error: Failed to prepare validation statement.");
}

$stmt->bind_param("s", $tokenHash);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
  http_response_code(403);
  die("Error: Invalid or already used token.");
}

if (strtotime($res['expires_at']) < time()) {
  http_response_code(410);
  die("Error: This payment link has expired.");
}

// 2. Prepare Payment Redirect
$subId = $res['subscription_id'];
$mode  = strtolower(trim((string)$res['action_type']));
$pid   = $res['product_id'];
$catId = trim((string)($res['product_category_id'] ?? ''));

// Legacy safety: old tokens may still say "renewal".
// If subscription has remaining_month_price, treat it as installment.
// Otherwise treat it as retention/continuation.
if ($mode === 'renewal') {
  $remainingAmount = isset($res['remaining_month_price']) ? (float)$res['remaining_month_price'] : 0.0;
  $mode = ($remainingAmount > 0) ? 'installment' : 'retention';
}

if (!in_array($mode, ['installment', 'retention'], true)) {
  http_response_code(400);
  die("Error: Invalid payment action.");
}

// Redirect to payment UI with mode and subscription context
$redirectUrl = "/payment/payment.php?product=" . urlencode((string)$pid) .
               "&mode=" . urlencode((string)$mode) .
               "&sid=" . urlencode((string)$subId) .
               ($catId !== '' ? "&category=" . urlencode($catId) : "") .
               "&stoken=" . urlencode($token);

header("Location: $redirectUrl");
exit;
