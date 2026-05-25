<?php
declare(strict_types=1);

/**
 * campaign-queue-runner.php
 * Admin-only POST handler for queue job lifecycle actions.
 * Actions: create_queue | run_once | pause_queue | resume_queue | cancel_queue
 */

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

function queue_runner_redirect(string $url): never
{
    if (headers_sent()) {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo '<p style="font-family:sans-serif">Redirecting… <a href="' . $safe . '">' . $safe . '</a></p>';
        echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
    } else {
        header('Location: ' . $url, true, 303);
    }
    exit;
}

$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// Ensure queue schema exists (idempotent)
campaign_ensure_queue_schema($conn);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    queue_runner_redirect('/admin/email/campaign-monitoring.php');
}

// CSRF guard
try {
    csrf_validate();
} catch (Exception $e) {
    queue_runner_redirect('/admin/email/campaign-monitoring.php?error=csrf');
}

$action     = trim((string)($_POST['action'] ?? ''));
$campaignId = (int)($_POST['campaign_id'] ?? 0);
$jobId      = (int)($_POST['job_id'] ?? 0);

if ($campaignId <= 0) {
    queue_runner_redirect('/admin/email/campaign-monitoring.php?error=invalid_campaign');
}

$detailsBase = '/admin/email/campaign-details.php?id=' . $campaignId;

switch ($action) {

    // ── Create Queue Job ───────────────────────────────────────────────────────
    case 'create_queue':
        $sendMode    = trim((string)($_POST['send_mode'] ?? 'pending'));
        $batchSize   = max(1, min(500, (int)($_POST['batch_size'] ?? 25)));
        $maxPerRun   = max(1, min(1000, (int)($_POST['max_per_run'] ?? 100)));
        $initiatedBy = campaign_safe_admin_label();

        $allowedModes = ['pending', 'failed', 'pending_and_failed'];
        if (!in_array($sendMode, $allowedModes, true)) $sendMode = 'pending';

        // Prevent duplicate active jobs
        $existing = campaign_get_active_queue_job($conn, $campaignId);
        if ($existing) {
            queue_runner_redirect($detailsBase . '&queue_error=job_exists');
        }

        $jobInfo = campaign_create_queue_job(
            $conn, $campaignId, $sendMode, $initiatedBy, $batchSize, $maxPerRun
        );

        if ($jobInfo['id'] > 0) {
            $conn->query(
                "UPDATE `email_campaigns`
                 SET queue_status = 'queued',
                     queue_started_at = IF(queue_started_at IS NULL, NOW(), queue_started_at)
                 WHERE id = {$campaignId}"
            );
            queue_runner_redirect($detailsBase . '&queue_created=1&queue_job_id=' . $jobInfo['id']);
        } else {
            error_log("campaign-queue-runner: create_queue failed for campaign {$campaignId}");
            queue_runner_redirect($detailsBase . '&queue_error=create_failed');
        }

    // ── Run One Batch ──────────────────────────────────────────────────────────
    case 'run_once':
        if ($jobId <= 0) {
            $activeJob = campaign_get_active_queue_job($conn, $campaignId);
            if ($activeJob) $jobId = (int)$activeJob['id'];
        }
        if ($jobId <= 0) {
            queue_runner_redirect($detailsBase . '&queue_error=no_job');
        }

        // Load sender dependencies
        require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/layout.php';
        require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/ses-config.php';

        $runResult = campaign_process_queue_job($conn, $jobId, 0);

        $query = http_build_query([
            'id'              => $campaignId,
            'queue_run'       => 1,
            'queue_sent'      => $runResult['sent'],
            'queue_failed'    => $runResult['failed'],
            'queue_remaining' => $runResult['remaining'],
            'queue_status'    => $runResult['status'],
            'queue_error_msg' => $runResult['error'],
        ]);
        queue_runner_redirect('/admin/email/campaign-details.php?' . $query);

    // ── Pause ──────────────────────────────────────────────────────────────────
    case 'pause_queue':
        if ($jobId <= 0) {
            $activeJob = campaign_get_active_queue_job($conn, $campaignId);
            if ($activeJob) $jobId = (int)$activeJob['id'];
        }
        if ($jobId > 0) {
            $conn->query(
                "UPDATE `email_campaign_queue_jobs`
                 SET status = 'paused', locked_at = NULL, locked_by = NULL
                 WHERE id = {$jobId} AND status IN ('queued','processing')"
            );
            $conn->query(
                "UPDATE `email_campaigns` SET queue_status = 'paused' WHERE id = {$campaignId}"
            );
        }
        queue_runner_redirect($detailsBase . '&queue_actioned=paused');

    // ── Resume ─────────────────────────────────────────────────────────────────
    case 'resume_queue':
        if ($jobId <= 0) {
            $stmt = $conn->prepare(
                "SELECT id FROM `email_campaign_queue_jobs`
                 WHERE campaign_id = ? AND status = 'paused'
                 ORDER BY created_at DESC LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) $jobId = (int)$r['id'];
            }
        }
        if ($jobId > 0) {
            $conn->query(
                "UPDATE `email_campaign_queue_jobs`
                 SET status = 'queued', locked_at = NULL, locked_by = NULL
                 WHERE id = {$jobId} AND status = 'paused'"
            );
            $conn->query(
                "UPDATE `email_campaigns` SET queue_status = 'queued' WHERE id = {$campaignId}"
            );
        }
        queue_runner_redirect($detailsBase . '&queue_actioned=resumed');

    // ── Cancel ─────────────────────────────────────────────────────────────────
    case 'cancel_queue':
        if ($jobId <= 0) {
            $activeJob = campaign_get_active_queue_job($conn, $campaignId);
            if ($activeJob) $jobId = (int)$activeJob['id'];
        }
        if ($jobId > 0) {
            $conn->query(
                "UPDATE `email_campaign_queue_jobs`
                 SET status = 'cancelled', locked_at = NULL, locked_by = NULL
                 WHERE id = {$jobId} AND status IN ('queued','processing','paused','error')"
            );
            $conn->query(
                "UPDATE `email_campaigns` SET queue_status = 'cancelled' WHERE id = {$campaignId}"
            );
        }
        queue_runner_redirect($detailsBase . '&queue_actioned=cancelled');

    default:
        queue_runner_redirect($detailsBase);
}
