<?php
// /payment/process-renewal.php
// SenangPay server-to-server callback handler for subscription renewals.
// - Verifies transaction via SenangPay API
// - Updates Subscriptions table with new expiry date
// - Creates Subscription_Billing_History entry
// - Sends renewal confirmation email

declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

$ROOT = realpath(__DIR__ . '/..') ?: __DIR__;

// Load env first
$envLoader = $ROOT . '/api/env.php';
if (file_exists($envLoader)) require_once $envLoader;
if (function_exists('loadEnv')) loadEnv($ROOT . '/payment/.env');

// Load brevo (uses sendBrevo() function from ses-config.php)
require_once $ROOT . '/api/ses-config.php';

// Load DB router
$dbRouter = $ROOT . '/api/db_router.php';
if (!file_exists($dbRouter)) {
  http_response_code(500);
  exit('Missing db_router.php');
}
require_once $dbRouter;

// Demo mode: SenangPay callbacks are not active
if (defined('DEMO_MODE') && DEMO_MODE) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'note' => '[DEMO] Renewal callback stub — no real SenangPay verification.']);
    exit;
}

// e-Learning URLs
$ELEARNING_LOGIN_URL  = 'https://demo.local/e-Learning/#/signin';
$ELEARNING_FORGOT_URL = 'https://demo.local/e-Learning/#/forgot-password';

// ------------------------------------------------------------
// SenangPay configs — loaded from env
// ------------------------------------------------------------
$SENANGPAY_CONFIGS = array_values(array_filter([
  [
    'name'       => 'live',
    'api_base'   => 'https://app.senangpay.my/apiv1',
    'merchant_id'=> getenv('SENANGPAY_LIVE_MERCHANT_ID') ?: '',
    'secret_key' => getenv('SENANGPAY_LIVE_SECRET_KEY')  ?: '',
    'hash_modes' => ['sha256_hmac', 'md5'],
  ],
  [
    'name'       => 'sandbox',
    'api_base'   => 'https://sandbox.senangpay.my/apiv1',
    'merchant_id'=> getenv('SENANGPAY_SANDBOX_MERCHANT_ID') ?: '',
    'secret_key' => getenv('SENANGPAY_SANDBOX_SECRET_KEY')  ?: '',
    'hash_modes' => ['sha256_hmac', 'md5'],
  ],
], fn($c) => !empty($c['merchant_id']) && !empty($c['secret_key'])));

if (count($SENANGPAY_CONFIGS) === 0) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  exit('Missing SenangPay env keys.');
}

// ============================================================
// HELPERS
// ============================================================

function param(string $key, string $default = ''): string {
  return (string)($_POST[$key] ?? $_GET[$key] ?? $default);
}

function respond(int $code, string $text): void {
  $isCallback = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');

  if ($isCallback && $code === 200) {
    $text = 'OK';
  }

  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $text;
  exit;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function safeLower(string $s): string {
  return strtolower(trim($s));
}

function log_line(string $file, string $line): void {
  @file_put_contents($file, date('c') . ' ' . $line . "\n", FILE_APPEND);
}

function httpGetJson(string $url, int $timeout = 12): array {
  $ch = curl_init($url);

  $caCandidates = [
    ini_get('curl.cainfo'),
    ini_get('openssl.cafile'),
    __DIR__ . '/../api/cacert.pem',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
  ];

  $caFile = '';
  foreach ($caCandidates as $c) {
    if (is_string($c) && $c !== '' && file_exists($c)) { $caFile = $c; break; }
  }

  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ];

  if ($caFile !== '') {
    $opts[CURLOPT_CAINFO] = $caFile;
  }

  curl_setopt_array($ch, $opts);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) return ['_ok' => false, '_http' => $code, '_err' => $err ?: 'curl_error'];
  $json = json_decode($body, true);
  if (!is_array($json)) return ['_ok' => false, '_http' => $code, '_err' => 'Invalid JSON'];
  $json['_ok']   = ($code >= 200 && $code < 300);
  $json['_http'] = $code;
  return $json;
}

function computeTxnHash(string $mid, string $sk, string $txnRef, string $mode): string {
  $mid = trim($mid); $sk = trim($sk); $txnRef = trim($txnRef);
  $payload = $mid . $sk . $txnRef;
  if ($mode === 'md5')         return md5($payload);
  if ($mode === 'sha256')      return hash('sha256', $payload);
  if ($mode === 'sha256_hmac') return hash_hmac('sha256', $payload, $sk);
  return md5($payload);
}

function verifySecureHash(string $secretKey, string $statusId, string $orderId, string $txnId, string $msg, string $hashRecv): bool {
  $secretKey = trim($secretKey);
  $hashRecv  = strtolower(trim($hashRecv));

  $raw  = $secretKey . $statusId . $orderId . $txnId . $msg;

  $sha  = hash('sha256', $raw);
  $hmac = hash_hmac('sha256', $raw, $secretKey);
  $md5  = md5($raw);

  return hash_equals(strtolower($sha),  $hashRecv)
      || hash_equals(strtolower($hmac), $hashRecv)
      || hash_equals(strtolower($md5),  $hashRecv);
}

function verifyTxn(string $apiBase, string $mid, string $sk, string $txnRef, string $mode): array {
  $hash = computeTxnHash($mid, $sk, $txnRef, $mode);
  $url  = rtrim($apiBase, '/') . '/query_transaction_status'
        . '?merchant_id=' . rawurlencode($mid)
        . '&transaction_reference=' . rawurlencode($txnRef)
        . '&hash=' . rawurlencode($hash);
  return httpGetJson($url);
}

function pickValidVerification(array $configs, string $txnRef, string $expectedOrderId): array {
  $expectedKey = function_exists('normalizeOrderKey') ? normalizeOrderKey($expectedOrderId) : $expectedOrderId;

  foreach ($configs as $cfg) {
    foreach ((array)$cfg['hash_modes'] as $mode) {
      $v = verifyTxn($cfg['api_base'], $cfg['merchant_id'], $cfg['secret_key'], $txnRef, $mode);
      if (empty($v['_ok'])) continue;
      if ((string)($v['status'] ?? '0') !== '1') continue;

      $got = (string)($v['order_id'] ?? '');
      if ($got !== '') {
        $gotKey = function_exists('normalizeOrderKey') ? normalizeOrderKey($got) : $got;
        if ($gotKey !== $expectedKey) continue;
      }

      return ['ok' => true, 'verify' => $v, 'used' => $cfg, 'mode' => $mode];
    }
  }
  return ['ok' => false];
}

function columnExists(mysqli $conn, string $table, string $col): bool {
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

function tableExists(mysqli $conn, string $table): bool {
  $t = mysqli_real_escape_string($conn, $table);
  $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
  return $r && mysqli_num_rows($r) > 0;
}

function sendBrevoEmail(string $email, string $name, string $subject, string $html): bool {
  return sendBrevo($email, $name, $subject, $html);
}

// ============================================================
// RENEWAL BUSINESS UPDATE SECTION
// ============================================================

function markSubscriptionRenewal(mysqli $conn, array $subscriptionRow, string $orderId, string $txnRef, string $renewalAmount): bool {
  $LOG = __DIR__ . '/sp_callback.log';

  if (!tableExists($conn, 'Subscriptions') || !tableExists($conn, 'Subscription_Billing_History')) {
    log_line($LOG, "RENEWAL: Subscriptions or Subscription_Billing_History table missing");
    return false;
  }

  $subId = (int)($subscriptionRow['id'] ?? 0);
  if ($subId <= 0) {
    log_line($LOG, "RENEWAL: Invalid subscription_id");
    return false;
  }

  $durationValue = (int)($subscriptionRow['duration_value'] ?? 0);
  $durationUnit  = trim((string)($subscriptionRow['duration_unit'] ?? 'month'));
  $currentExpiry = trim((string)($subscriptionRow['expiry_date'] ?? ''));

  if ($durationValue <= 0 || $currentExpiry === '') {
    log_line($LOG, "RENEWAL: Missing duration or expiry_date. duration_value={$durationValue}, expiry_date={$currentExpiry}");
    return false;
  }

  try {
    $expiryTs = strtotime($currentExpiry);
    if ($expiryTs === false) {
      log_line($LOG, "RENEWAL: Invalid expiry_date format: {$currentExpiry}");
      return false;
    }
  } catch (Exception $e) {
    log_line($LOG, "RENEWAL: Exception parsing expiry_date: " . $e->getMessage());
    return false;
  }

  $intervalStr = $durationValue . ' ' . $durationUnit;
  $newExpiryTs = strtotime("+{$intervalStr}", $expiryTs);
  if ($newExpiryTs === false) {
    log_line($LOG, "RENEWAL: Failed to calculate new expiry with interval: {$intervalStr}");
    return false;
  }

  $newExpiryDate = date('Y-m-d H:i:s', $newExpiryTs);
  $nextRenewalDate = date('Y-m-d H:i:s', $newExpiryTs);
  $now = date('Y-m-d H:i:s');

  $txnEsc = mysqli_real_escape_string($conn, $txnRef);
  $amountEsc = mysqli_real_escape_string($conn, (string)$renewalAmount);
  $orderIdEsc = mysqli_real_escape_string($conn, $orderId);

  // Update the Subscriptions table with new expiry and renewal info
  $renewalOk = mysqli_query($conn, "
    UPDATE `Subscriptions`
    SET
      expiry_date = '{$newExpiryDate}',
      next_renewal_date = '{$nextRenewalDate}',
      renewal_count = renewal_count + 1,
      last_paid_at = '{$now}'
    WHERE id = {$subId}
    LIMIT 1
  ");

  if (!$renewalOk) {
    log_line($LOG, "RENEWAL: Failed to update Subscriptions: " . mysqli_error($conn));
    return false;
  }

  // Check for duplicate renewal audit record to prevent idempotency issues
  $checkSql = "SELECT id FROM `Subscription_Billing_History` WHERE subscription_id = {$subId} AND (order_id = '{$orderIdEsc}' OR payment_id = (SELECT id FROM Payment WHERE transaction_ref = '{$txnEsc}' LIMIT 1))";
  if (columnExists($conn, 'Subscription_Billing_History', 'transaction_ref')) {
    $checkSql .= " OR transaction_ref = '{$txnEsc}'";
  }
  $checkSql .= " LIMIT 1";

  $dupAudit = mysqli_query($conn, $checkSql);

  if ($dupAudit && mysqli_num_rows($dupAudit) > 0) {
    log_line($LOG, "RENEWAL: Duplicate audit record found for subscription_id={$subId}, order_id={$orderId} - skipping audit insert");
    return true;
  }

  $auditCols = "subscription_id, billing_type, amount, paid_at, order_id, created_at";
  $auditValues = "{$subId}, 'retention_legacy', {$amountEsc}, '{$now}', '{$orderIdEsc}', '{$now}'";
  
  if (columnExists($conn, 'Subscription_Billing_History', 'transaction_ref')) {
    $auditCols .= ", transaction_ref";
    $auditValues .= ", '{$txnEsc}'";
  }
  
  if (columnExists($conn, 'Subscription_Billing_History', 'payment_id')) {
    $auditCols .= ", payment_id";
    $auditValues .= ", (SELECT id FROM Payment WHERE transaction_ref = '{$txnEsc}' LIMIT 1)";
  }

  $insertOk = mysqli_query($conn, "
    INSERT INTO `Subscription_Billing_History` ({$auditCols})
    VALUES ({$auditValues})
  ");

  if (!$insertOk) {
    log_line($LOG, "RENEWAL: Failed to insert Subscription_Billing_History: " . mysqli_error($conn));
    return false;
  }

  log_line($LOG, "RENEWAL: Updated subscription_id={$subId}, new_expiry={$newExpiryDate}, renewal_amount={$renewalAmount}");
  return true;
}

function applyRenewalDiscount(array $orderRow): array {
  $discountType = trim((string)($orderRow['renewal_discount_type_snapshot'] ?? ''));
  $discountValue = (float)($orderRow['renewal_discount_value_snapshot'] ?? 0);

  return [
    'discount_type' => $discountType,
    'discount_value' => $discountValue,
  ];
}

function sendRenewalEmail(mysqli $conn, array $subscriptionRow, string $orderId, string $txnRef, string $renewalAmount): void {
  $LOG = __DIR__ . '/sp_callback.log';

  $email = safeLower((string)($subscriptionRow['customer_email'] ?? ''));
  $name  = trim((string)($subscriptionRow['customer_name'] ?? 'Customer'));
  $productName = trim((string)($subscriptionRow['product_name_snapshot'] ?? ''));
  $variantName = trim((string)($subscriptionRow['variant_name_snapshot'] ?? ''));
  $expiryDate = trim((string)($subscriptionRow['expiry_date'] ?? ''));

  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    log_line($LOG, "RENEWAL_EMAIL: Invalid email address: {$email}");
    return;
  }

  $fullProduct = $productName;
  if ($variantName !== '') {
    $fullProduct .= ' (' . $variantName . ')';
  }

  $subject = 'Subscription Renewed - ' . ($productName ?: 'Demo Company');

  $expiryFormatted = '';
  try {
    $expiryTs = strtotime($expiryDate);
    if ($expiryTs !== false) {
      $expiryFormatted = date('d M Y', $expiryTs);
    }
  } catch (Exception $e) {
    $expiryFormatted = $expiryDate;
  }

  require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/mail/layout.php";

  $bodyHtml = "
    <h2>Subscription Renewed Successfully</h2>
    <p>Hi " . mail_h(mail_first_name($name)) . ",</p>
    <p>Thank you! Your subscription has been renewed successfully.</p>
    <div style='margin: 25px 0; padding: 20px; background: rgba(255,255,255,.05); border-left: 4px solid #f59e0b; border-radius: 12px; border: 1px solid rgba(255,255,255,.08)'>
      <p style='margin: 5px 0;'><strong>Product:</strong> " . mail_h($fullProduct) . "</p>
      <p style='margin: 5px 0;'><strong>Amount Paid:</strong> RM " . mail_h($renewalAmount) . "</p>
      <p style='margin: 5px 0;'><strong>Order ID:</strong> " . mail_h($orderId) . "</p>
      <p style='margin: 5px 0;'><strong>Transaction Ref:</strong> " . mail_h($txnRef) . "</p>
      <p style='margin: 5px 0;'><strong>New Expiry Date:</strong> " . mail_h($expiryFormatted) . "</p>
    </div>
    <p>Your subscription is now active until " . mail_h($expiryFormatted) . ". You will receive a reminder before the next renewal.</p>
  ";

  $html = buildMailLayout([
    'subject'   => $subject,
    'body_html' => $bodyHtml,
    'badge_text'=> 'Subscription Renewed'
  ]);

  $ok = sendBrevoEmail($email, $name, $subject, $html);  log_line($LOG, "RENEWAL_EMAIL: sent={$ok} to={$email}");
}

// ============================================================
// MAIN HANDLER
// ============================================================

function handleRenewal(mysqli $conn, string $order_id, string $txn_ref, string $price): void {
  $LOG = __DIR__ . '/sp_callback.log';

  log_line($LOG, "--- handleRenewal START order={$order_id} txn={$txn_ref} price={$price}");

  if (!tableExists($conn, 'Subscriptions')) {
    log_line($LOG, "Subscriptions table not found");
    respond(404, 'Subscriptions table not found');
  }

  $txnEsc   = mysqli_real_escape_string($conn, $txn_ref);
  $orderEsc = mysqli_real_escape_string($conn, $order_id);

  // ── STEP 1: Idempotency check ──────────────────────────────
  // Check if this transaction_ref was already processed
  log_line($LOG, "STEP1 idempotency check");
  $dup = mysqli_query($conn, "SELECT id, verified, status FROM `Payment` WHERE `transaction_ref`='{$txnEsc}' LIMIT 1");
  if ($dup && mysqli_num_rows($dup) > 0) {
    $dupRow = mysqli_fetch_assoc($dup);
    if ((int)($dupRow['verified'] ?? 0) === 1 && $dupRow['status'] === 'completed') {
      log_line($LOG, "DUPLICATE txn={$txn_ref} already completed - skipping");
      respond(200, 'OK: already processed.');
    }
  }

  // ── STEP 2: Find the existing Payment row ──────────────────
  // This is the original payment that initiated the subscription
  log_line($LOG, "STEP2 finding Payment row for order={$order_id}");

  $stripped = $order_id;
  if (preg_match('/^(.+)-oid-\d+$/i', $order_id, $m)) $stripped = $m[1];
  $strippedEsc = mysqli_real_escape_string($conn, $stripped);
  $likeEsc     = mysqli_real_escape_string($conn, $stripped . '%');

  // Find Payment by transaction_id, codeid, or order_id patterns
  $paymentRow = mysqli_query($conn, "
    SELECT id, subscription_id, subscription_mode, price, email, name, phone, verified, status
    FROM `Payment`
    WHERE transaction_id = '{$orderEsc}'
       OR codeid         = '{$orderEsc}'
       OR transaction_id = '{$strippedEsc}'
       OR codeid         = '{$strippedEsc}'
       OR transaction_id LIKE '{$likeEsc}'
    ORDER BY
      CASE
        WHEN transaction_id = '{$orderEsc}'    THEN 1
        WHEN codeid         = '{$orderEsc}'    THEN 2
        WHEN transaction_id = '{$strippedEsc}' THEN 3
        WHEN codeid         = '{$strippedEsc}' THEN 4
        ELSE 5
      END
    LIMIT 1
  ");

  if (!$paymentRow || mysqli_num_rows($paymentRow) === 0) {
    log_line($LOG, "STEP2 Payment not found for order={$order_id}");
    respond(404, 'Payment record not found');
  }

  $payData = mysqli_fetch_assoc($paymentRow);
  $paymentId = (int)($payData['id'] ?? 0);
  $subscriptionIdFromPayment = (int)($payData['subscription_id'] ?? 0);

  log_line($LOG, "STEP2 found Payment id={$paymentId}, subscription_id={$subscriptionIdFromPayment}");

  // ── STEP 3: Find the existing Subscription row ─────────────
  log_line($LOG, "STEP3 finding Subscription row");

  // Use subscription_id from Payment first
  $subscriptionRow = null;
  $subId = 0;

  if ($subscriptionIdFromPayment > 0) {
    $subQuery = mysqli_query($conn, "
      SELECT id, subscription_no, customer_name, customer_email, product_name_snapshot, variant_name_snapshot,
             duration_value, duration_unit, expiry_date, remaining_month_price, status, renewal_count
      FROM `Subscriptions`
      WHERE id = {$subscriptionIdFromPayment}
      LIMIT 1
    ");

    if ($subQuery && mysqli_num_rows($subQuery) > 0) {
      $subscriptionRow = mysqli_fetch_assoc($subQuery);
      $subId = (int)($subscriptionRow['id'] ?? 0);
      log_line($LOG, "STEP3 found Subscription id={$subId} by Payment.subscription_id");
    }
  }

  // Fallback: search by subscription_no if not found by subscription_id
  if ($subId === 0) {
    log_line($LOG, "STEP3 subscription_id not found, falling back to subscription_no search");
    $cols = "id, subscription_no, customer_name, customer_email, product_name_snapshot, variant_name_snapshot, duration_value, duration_unit, expiry_date, remaining_month_price, status, renewal_count";
    $subQuery = mysqli_query($conn, "
      SELECT {$cols}
      FROM `Subscriptions`
      WHERE subscription_no = '{$orderEsc}'
         OR subscription_no = '{$strippedEsc}'
         OR subscription_no LIKE '{$likeEsc}'
      ORDER BY created_at DESC
      LIMIT 1
    ");

    if ($subQuery && mysqli_num_rows($subQuery) > 0) {
      $subscriptionRow = mysqli_fetch_assoc($subQuery);
      $subId = (int)($subscriptionRow['id'] ?? 0);
      log_line($LOG, "STEP3 found Subscription id={$subId} by subscription_no fallback");
    }
  }

  if ($subId === 0 || !$subscriptionRow) {
    log_line($LOG, "STEP3 Subscription not found for order={$order_id}");
    respond(404, 'Subscription not found');
  }

  // ── STEP 3: Find Orders row for discount snapshot ──────────
  log_line($LOG, "STEP3 finding Orders row for discount snapshot");

  $orderRow = null;
  if (tableExists($conn, 'Orders')) {
    $ordersQuery = mysqli_query($conn, "
      SELECT id, renewal_discount_type_snapshot, renewal_discount_value_snapshot
      FROM `Orders`
      WHERE order_id = '{$orderEsc}'
         OR order_id = '{$strippedEsc}'
         OR order_id LIKE '{$likeEsc}'
      ORDER BY id DESC
      LIMIT 1
    ");

    if ($ordersQuery && mysqli_num_rows($ordersQuery) > 0) {
      $orderRow = mysqli_fetch_assoc($ordersQuery);
      log_line($LOG, "STEP3 found Orders row with potential renewal discount");
    }
  }

  // ── STEP 4: Calculate renewal amount with discount ─────────
  $renewalAmount = (float)($subscriptionRow['remaining_month_price'] ?? 0);

  if ($orderRow) {
    $discount = applyRenewalDiscount($orderRow);
    if ($discount['discount_type'] !== '' && $discount['discount_value'] > 0) {
      $originalAmount = $renewalAmount;
      if (strcasecmp($discount['discount_type'], 'percentage') === 0) {
        $renewalAmount = $renewalAmount * (1 - $discount['discount_value'] / 100);
      } elseif (strcasecmp($discount['discount_type'], 'fixed') === 0) {
        $renewalAmount = max(0, $renewalAmount - $discount['discount_value']);
      }
      $renewalAmount = number_format($renewalAmount, 2, '.', '');
      log_line($LOG, "STEP4 renewal discount applied: {$discount['discount_type']}({$discount['discount_value']}) = {$originalAmount} -> {$renewalAmount}");
    }
  }

  log_line($LOG, "STEP4 renewal_amount={$renewalAmount}");

  // ── STEP 5: UPDATE existing Payment row (idempotency marker) 
  log_line($LOG, "STEP5 updating existing Payment row id={$paymentId}");

  $now = date('Y-m-d H:i:s');
  $ok = mysqli_query($conn, "
    UPDATE `Payment`
    SET
      transaction_ref = '{$txnEsc}',
      verified = 1,
      status = 'completed'
    WHERE id = {$paymentId}
    LIMIT 1
  ");

  if (!$ok) {
    log_line($LOG, "STEP5 UPDATE Payment failed: " . mysqli_error($conn));
    respond(500, 'DB error');
  }

  $affected = mysqli_affected_rows($conn);
  log_line($LOG, "STEP5 Payment updated: rows_affected={$affected}");

  // ── STEP 6: RENEWAL BUSINESS UPDATE ────────────────────────
  log_line($LOG, "STEP6 RENEWAL BUSINESS UPDATE SECTION");

  $renewalOk = markSubscriptionRenewal($conn, $subscriptionRow, $order_id, $txn_ref, (string)$renewalAmount);
  if (!$renewalOk) {
    log_line($LOG, "STEP6 markSubscriptionRenewal failed");
    respond(500, 'Failed to update subscription');
  }

  log_line($LOG, "STEP6 subscription renewed successfully");

  // ── STEP 7: Update Orders table ────────────────────────────
  if (tableExists($conn, 'Orders')) {
    $oidEsc = mysqli_real_escape_string($conn, $order_id);
    mysqli_query($conn, "
      UPDATE `Orders`
      SET status='paid', transaction_ref='{$txnEsc}'
      WHERE order_id='{$oidEsc}'
      LIMIT 1
    ");
    log_line($LOG, "STEP7 Orders status updated to 'paid'");
  }

  // ── STEP 8: Send renewal confirmation email ────────────────
  log_line($LOG, "STEP8 sending renewal confirmation email");
  sendRenewalEmail($conn, $subscriptionRow, $order_id, $txn_ref, (string)$renewalAmount);

  respond(200, 'OK');
}

// ============================================================
// ENTRY POINT
// ============================================================

log_line(__DIR__ . '/sp_callback.log', 'RENEWAL HIT ' . json_encode($_REQUEST));

$status   = param('status', param('status_id', ''));
$order_id = param('order_id', '');
$txn_ref  = param('transaction_id', param('transaction_reference', ''));
$msg      = param('msg', param('txn_msg', ''));
$hashRecv = param('hash', param('hashed_value', ''));
$p = param('price', param('amount', '0'));
$price = is_numeric($p) ? number_format((float)$p, 2, '.', '') : '0.00';

if ($order_id === '' || $txn_ref === '') {
  respond(400, 'Missing order_id / transaction_id.');
}

if ($status !== '' && $status !== '1') {
  respond(200, 'IGNORED: status != 1.');
}

if (!function_exists('getBillingConn')) {
  respond(500, 'getBillingConn() not found in db_router.php.');
}

$conn = getBillingConn();
if (!$conn || !($conn instanceof mysqli)) {
  respond(500, 'Billing DB connection failed.');
}
$conn->set_charset('utf8mb4');

// ── SenangPay verification ────────────────────────────────────
$trustedByHash = false;

if ($hashRecv !== '' && $status !== '' && $order_id !== '' && $txn_ref !== '') {
  foreach ($SENANGPAY_CONFIGS as $cfg) {
    $sk = (string)($cfg['secret_key'] ?? '');
    if ($sk === '') continue;

    if (verifySecureHash($sk, $status, $order_id, $txn_ref, $msg, $hashRecv)) {
      $trustedByHash = true;
      log_line(__DIR__ . '/sp_verify.log', "SECURE_HASH_OK env=" . ($cfg['name'] ?? '') . " order={$order_id} txn={$txn_ref}");
      break;
    }
  }
}

if (!$trustedByHash) {
  $pick = pickValidVerification($SENANGPAY_CONFIGS, $txn_ref, $order_id);
  if (empty($pick['ok'])) {
    log_line(__DIR__ . '/sp_verify.log', "VERIFY_FAIL order={$order_id} txn={$txn_ref}");
    respond(500, 'SenangPay verification failed.');
  }

  log_line(__DIR__ . '/sp_verify.log', 'VERIFY_OK env=' . ($pick['used']['name'] ?? '') . ' mode=' . ($pick['mode'] ?? ''));
  $verify = (array)$pick['verify'];

  if ((string)($verify['status'] ?? '0') !== '1') {
    respond(200, 'IGNORED: payment not successful per SenangPay.');
  }
}

handleRenewal($conn, $order_id, $txn_ref, $price);
