<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/retry-send-helper.php';

if (!function_exists('getBillingConn')) {
  http_response_code(500);
  exit('Billing DB connector is unavailable.');
}

$conn = getBillingConn();
if (!($conn instanceof mysqli)) {
  http_response_code(500);
  exit('Main email database is unavailable.');
}

csrf_validate();

$logId = (int)($_POST['log_id'] ?? 0);
if ($logId <= 0) {
  header('Location: /admin/email/email-logs.php?error=invalid_log', true, 303);
  exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

$conn->begin_transaction();

try {
  $stmt = $conn->prepare("
    SELECT *
    FROM `email_logs`
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  if (!$stmt) {
    throw new RuntimeException('Failed to prepare email log lookup.');
  }

  $stmt->bind_param('i', $logId);
  $stmt->execute();
  $res = $stmt->get_result();
  $log = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$log) {
    throw new RuntimeException('Email log was not found.');
  }

  $status = strtolower(trim((string)($log['status'] ?? '')));
  if (in_array($status, ['sent', 'success', 'delivered', 'skipped_duplicate'], true)) {
    $conn->commit();
    header('Location: /admin/email/email-logs.php?notice=already_sent', true, 303);
    exit;
  }

  $retryCount = (int)($log['retry_count'] ?? 0);
  $maxRetry   = max(1, (int)($log['max_retry'] ?? 5));

  if ($retryCount >= $maxRetry) {
    throw new RuntimeException('Retry limit has been reached for this email.');
  }

  $dedupeKey = trim((string)($log['dedupe_key'] ?? ''));
  if ($dedupeKey !== '') {
    $stmt = $conn->prepare("
      SELECT id
      FROM `email_logs`
      WHERE dedupe_key = ?
        AND id <> ?
        AND LOWER(TRIM(status)) IN ('sent', 'success', 'delivered')
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException('Failed to prepare duplicate check.');
    }

    $stmt->bind_param('si', $dedupeKey, $logId);
    $stmt->execute();
    $dupRes = $stmt->get_result();
    $dup = $dupRes ? $dupRes->fetch_assoc() : null;
    $stmt->close();

    if ($dup) {
      $stmt = $conn->prepare("
        UPDATE `email_logs`
        SET status = 'skipped_duplicate',
            error_message = 'A successful email with the same dedupe key already exists.',
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ");
      $stmt->bind_param('i', $logId);
      $stmt->execute();
      $stmt->close();

      $conn->commit();
      header('Location: /admin/email/email-logs.php?notice=duplicate_skipped', true, 303);
      exit;
    }
  }

  $payload = json_decode((string)($log['payload_json'] ?? ''), true);
  if (!is_array($payload)) {
    throw new RuntimeException('This email log does not contain a valid payload_json value.');
  }

  $paymentReady = false;

  $paymentRowId = (int)($log['order_id'] ?? 0);

  if ($paymentRowId > 0) {
    $stmt = $conn->prepare("
      SELECT verified, status, transaction_ref
      FROM `Payment`
      WHERE id = ?
      LIMIT 1
    ");

    if (!$stmt) {
      throw new RuntimeException('Failed to prepare payment verification query.');
    }

    $stmt->bind_param('i', $paymentRowId);
    $stmt->execute();
    $paymentRes = $stmt->get_result();
    $paymentRow = $paymentRes ? $paymentRes->fetch_assoc() : null;
    $stmt->close();

    if ($paymentRow) {
      $verified = (int)($paymentRow['verified'] ?? 0);
      $paymentStatus = strtolower(trim((string)($paymentRow['status'] ?? '')));
      $txnRef = trim((string)($paymentRow['transaction_ref'] ?? ''));

      $paymentReady =
        $verified === 1
        && $txnRef !== ''
        && in_array($paymentStatus, ['completed', 'paid'], true);
    }
  }

  if (!$paymentReady) {
    $stmt = $conn->prepare("
      UPDATE `email_logs`
      SET status = 'blocked_pending_payment',
          retry_count = retry_count + 1,
          last_retry_at = NOW(),
          next_retry_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
          error_message = 'Email was not sent because the payment is not confirmed yet.',
          updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->bind_param('i', $logId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header('Location: /admin/email/email-logs.php?notice=still_pending', true, 303);
    exit;
  }

  $stmt = $conn->prepare("
    UPDATE `email_logs`
    SET status = 'retrying',
        retry_count = retry_count + 1,
        last_retry_at = NOW(),
        locked_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $logId);
  $stmt->execute();
  $stmt->close();

  $conn->commit();

  /*
   |------------------------------------------------------------------
   | Call your existing email sender here
   |------------------------------------------------------------------
   | IMPORTANT:
   | Do not create a second mail implementation here.
   | Reuse the same internal sender used by your normal paid-order flow.
   */
  $sendResult = send_email_from_log_payload($payload);

  $conn->begin_transaction();

  if (!empty($sendResult['ok'])) {
    $providerMessageId = (string)($sendResult['message_id'] ?? '');

    $stmt = $conn->prepare("
      UPDATE `email_logs`
      SET status = 'sent',
          provider_message_id = ?,
          error_message = NULL,
          sent_at = NOW(),
          next_retry_at = NULL,
          locked_at = NULL,
          updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->bind_param('si', $providerMessageId, $logId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header('Location: /admin/email/email-logs.php?notice=retry_sent', true, 303);
    exit;
  }

  $errorMessage = trim((string)($sendResult['error'] ?? 'Retry send failed.'));

  $stmt = $conn->prepare("
    UPDATE `email_logs`
    SET status = 'failed',
        error_message = ?,
        next_retry_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
        locked_at = NULL,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('si', $errorMessage, $logId);
  $stmt->execute();
  $stmt->close();

  $conn->commit();
  header('Location: /admin/email/email-logs.php?notice=retry_failed', true, 303);
  exit;

} catch (Throwable $e) {
  try {
    $conn->rollback();
  } catch (Throwable $ignore) {
  }

  error_log('retry-email failed: ' . $e->getMessage());
  header('Location: /admin/email/email-logs.php?error=retry_exception', true, 303);
  exit;
}