<?php
declare(strict_types=1);

/**
 * process-campaign-send.php
 * Handles batch sending for a campaign.
 * Supports send_mode: pending | failed | pending_and_failed
 */

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/layout.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/ses-config.php';

$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// ── 1. POST validation ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/email/campaign-monitoring.php');
    exit;
}

try {
    csrf_validate();
} catch (Exception $e) {
    header('Location: /admin/email/campaign-monitoring.php?error=csrf');
    exit;
}

$campaignId = (int)($_POST['campaign_id'] ?? 0);
$batchSize  = min(100, max(1, (int)($_POST['batch_size'] ?? 25)));
$sendMode   = trim((string)($_POST['send_mode'] ?? 'pending'));

$allowedModes = ['pending', 'failed', 'pending_and_failed'];
if (!in_array($sendMode, $allowedModes, true)) {
    $sendMode = 'pending';
}

if ($campaignId <= 0) {
    header('Location: /admin/email/campaign-monitoring.php?error=invalid_id');
    exit;
}

// ── 2. Load campaign ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $campaignId);
$stmt->execute();
$campaign = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$campaign) {
    header('Location: /admin/email/campaign-monitoring.php?error=not_found');
    exit;
}

if ($campaign['status'] === 'cancelled') {
    header("Location: /admin/email/campaign-details.php?id={$campaignId}&error=cancelled");
    exit;
}

// ── 3. Content check (before locking recipients) ──────────────────────────────
if (!campaign_campaign_content_ready($campaign)) {
    header("Location: /admin/email/campaign-details.php?id={$campaignId}&error=content_not_ready");
    exit;
}

// ── 4. Ensure batch schema exists ─────────────────────────────────────────────
campaign_ensure_send_batch_schema($conn);

// ── 5. Determine WHERE clause — never touch sent/delivered/bounced/spam/complained/unsubscribed
$statusWhere = match ($sendMode) {
    'failed'             => "delivery_status = 'failed'",
    'pending_and_failed' => "delivery_status IN ('pending','queued','failed')",
    default              => "delivery_status IN ('pending','queued')",
};

// ── 6. Find & lock recipients (transaction + FOR UPDATE) ──────────────────────
$conn->begin_transaction();

$recSql = "SELECT id, recipient_email, recipient_name, recipient_phone, tracking_token
           FROM `email_campaign_recipients`
           WHERE campaign_id = ?
           AND {$statusWhere}
           LIMIT ?
           FOR UPDATE";

$stmt = $conn->prepare($recSql);
$stmt->bind_param('ii', $campaignId, $batchSize);
$stmt->execute();
$res = $stmt->get_result();
$recipients = [];
while ($row = $res->fetch_assoc()) {
    $recipients[] = $row;
}
$stmt->close();

if (empty($recipients)) {
    $conn->commit();
    header("Location: /admin/email/campaign-details.php?id={$campaignId}&notice=no_pending");
    exit;
}

// Lock to 'queued' to prevent double-processing across concurrent requests
$recipientIds = array_column($recipients, 'id');
$placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
$lockStmt = $conn->prepare(
    "UPDATE `email_campaign_recipients` SET delivery_status = 'queued' WHERE id IN ({$placeholders})"
);
$lockStmt->bind_param(str_repeat('i', count($recipientIds)), ...$recipientIds);
$lockStmt->execute();
$lockStmt->close();

$conn->commit();

// ── 7. Set campaign to 'sending' ──────────────────────────────────────────────
$conn->query(
    "UPDATE `email_campaigns` SET status = 'sending'
     WHERE id = {$campaignId} AND status NOT IN ('sending','cancelled')"
);

// ── 8. Create batch history record ────────────────────────────────────────────
$batchInfo = campaign_create_send_batch(
    $conn,
    $campaignId,
    $sendMode,
    campaign_safe_admin_label(),
    $batchSize
);
$batchDbId = $batchInfo['id'];
$batchUid  = $batchInfo['uid'];

// ── 9. Process batch ──────────────────────────────────────────────────────────
$sentCount    = 0;
$failedCount  = 0;
$skippedCount = 0;
$lastError    = '';

foreach ($recipients as $rec) {
    $rId   = (int)$rec['id'];
    $email = (string)$rec['recipient_email'];
    $name  = (string)($rec['recipient_name'] ?: 'Customer');
    $token = (string)($rec['tracking_token'] ?: campaign_get_or_create_tracking_token($conn, $rId));

    $fullHtml = campaign_build_campaign_email_html($campaign, $rec, true);
    $subject  = campaign_replace_tokens((string)($campaign['subject'] ?: 'Update from Demo Company'), $rec, $campaign);

    // Safety: no localhost tracking URLs in outbound mail
    if (strpos($fullHtml, 'localhost') !== false) {
        $errMsg = 'Tracking URL contains localhost — public URL required.';
        error_log("CAMPAIGN SEND ABORTED [{$rId}]: {$errMsg}");
        campaign_mark_recipient_status($conn, $rId, 'failed', null, $errMsg);
        $failedCount++;
        $lastError = $errMsg;
        continue;
    }

    $sendResult = campaign_send_email_via_existing_sender($email, $name, $subject, $fullHtml);

    if ($sendResult['ok']) {
        campaign_mark_recipient_status($conn, $rId, 'sent', $sendResult['message_id']);
        $sentCount++;
    } else {
        $errMsg = $sendResult['error'] ?: 'Unknown delivery error';
        campaign_mark_recipient_status($conn, $rId, 'failed', null, $errMsg);
        $failedCount++;
        $lastError = $errMsg;
    }
}

$attemptedCount = $sentCount + $failedCount;

// ── 10. Recalculate campaign metrics ──────────────────────────────────────────
campaign_recalculate_metrics($conn, $campaignId);

// ── 11. Update campaign status based on remaining pending recipients ───────────
$pendingQueued = campaign_count_remaining_for_mode($conn, $campaignId, 'pending');
$remainingForMode = campaign_count_remaining_for_mode($conn, $campaignId, $sendMode);

$metricsRes  = $conn->query("SELECT failed_count FROM `email_campaigns` WHERE id = {$campaignId}");
$totalFailed = $metricsRes ? (int)$metricsRes->fetch_row()[0] : 0;

if ($pendingQueued === 0) {
    $finalStatus = $totalFailed > 0 ? 'partial_failed' : 'sent';
    $conn->query(
        "UPDATE `email_campaigns`
         SET status = '{$finalStatus}', sent_at = IF(sent_at IS NULL, NOW(), sent_at)
         WHERE id = {$campaignId}"
    );
}
// If pending still exist, leave status as 'sending' (already set above)

// ── 12. Determine batch final status ─────────────────────────────────────────
if ($attemptedCount === 0) {
    $batchFinalStatus = 'completed';
} elseif ($failedCount === 0) {
    $batchFinalStatus = 'completed';
} elseif ($sentCount > 0) {
    $batchFinalStatus = 'partial_failed';
} else {
    $batchFinalStatus = 'failed';
}

campaign_update_send_batch($conn, $batchDbId, [
    'attempted_count' => $attemptedCount,
    'sent_count'      => $sentCount,
    'failed_count'    => $failedCount,
    'skipped_count'   => $skippedCount,
    'remaining_count' => $remainingForMode,
    'status'          => $batchFinalStatus,
    'error_message'   => $lastError !== '' ? $lastError : null,
]);

// ── 13. Redirect with summary ─────────────────────────────────────────────────
$query = http_build_query([
    'id'              => $campaignId,
    'batch_sent'      => $sentCount,
    'batch_failed'    => $failedCount,
    'batch_attempted' => $attemptedCount,
    'batch_skipped'   => $skippedCount,
    'batch_mode'      => $sendMode,
    'batch_uid'       => $batchUid,
    'remaining'       => $remainingForMode,
    'last_err'        => $lastError,
]);

header("Location: /admin/email/campaign-details.php?{$query}");
exit;
