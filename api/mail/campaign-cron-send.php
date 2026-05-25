<?php
declare(strict_types=1);

/**
 * campaign-cron-send.php
 * Cron-safe queue runner. Works via CLI or HTTP with secret token.
 *
 * HTTP: /api/mail/campaign-cron-send.php?token=YOUR_SECRET[&limit=50]
 * CLI:  php campaign-cron-send.php [limit]
 *
 * Configure token via PHP constant or environment variable:
 *   define('CAMPAIGN_CRON_TOKEN', 'your-secret');
 *   OR set env CAMPAIGN_CRON_TOKEN=your-secret
 */

$isCli = PHP_SAPI === 'cli';

// Demo mode: stub response — emails are logged to demo/mail-outbox/, none sent
if (defined('DEMO_MODE') && DEMO_MODE && !$isCli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'note' => '[DEMO] Cron stub — no real emails sent.', 'sent' => 0]);
    exit;
}

// ── Security ───────────────────────────────────────────────────────────────────
if (!$isCli) {
    // Resolve configured token
    $configuredToken = '';
    if (defined('CAMPAIGN_CRON_TOKEN')) {
        $configuredToken = (string)CAMPAIGN_CRON_TOKEN;
    } elseif ('' !== (string)getenv('CAMPAIGN_CRON_TOKEN')) {
        $configuredToken = (string)getenv('CAMPAIGN_CRON_TOKEN');
    }

    if ($configuredToken === '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cron token not configured on server.']);
        exit;
    }

    $providedToken = trim((string)($_GET['token'] ?? ''));
    if (!hash_equals($configuredToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid token.']);
        exit;
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────
if ($isCli) {
    // Derive document root: api/mail/campaign-cron-send.php → two levels up
    $docRoot = rtrim(dirname(__DIR__, 2), '/');
    $_SERVER['DOCUMENT_ROOT'] = $docRoot;
    $_SERVER['HTTP_HOST']     = 'demo.local';
    $_SERVER['HTTPS']         = 'on';
    $limitParam = isset($argv[1]) ? (int)$argv[1] : 0;
} else {
    $docRoot    = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $limitParam = (int)($_GET['limit'] ?? 0);
}

require_once $docRoot . '/api/db_router.php';
require_once $docRoot . '/api/mail/campaign-helpers.php';
require_once $docRoot . '/api/mail/layout.php';
require_once $docRoot . '/api/ses-config.php';

// ── Database ───────────────────────────────────────────────────────────────────
$cronConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$cronConn instanceof mysqli) {
    $msg = ['error' => 'Database connection unavailable.'];
    if ($isCli) { fwrite(STDERR, 'ERROR: ' . $msg['error'] . PHP_EOL); exit(1); }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($msg);
    exit;
}
$cronConn->set_charset('utf8mb4');
$cronConn->query("SET time_zone = '+08:00'");

// Ensure queue + schedule schemas are present (idempotent)
campaign_ensure_queue_schema($cronConn);
campaign_ensure_schedule_schema($cronConn);

// ── Phase 1L: Process due schedules ───────────────────────────────────────────
// Converts due email_campaign_schedules rows into queue jobs BEFORE the normal
// queue runner picks them up, so a single cron tick handles both.
$scheduleResult = campaign_queue_due_schedules($cronConn);
if ($isCli && (!empty($scheduleResult['queued']) || !empty($scheduleResult['errors']))) {
    foreach ($scheduleResult['queued'] as $sq) {
        $note = $sq['note'] ?? '';
        $jobPart = isset($sq['job_id']) ? ' → job_id=' . $sq['job_id'] : '';
        echo 'SCHEDULE_QUEUED: schedule_id=' . $sq['schedule_id'] . $jobPart . ($note ? ' (' . $note . ')' : '') . PHP_EOL;
    }
    foreach ($scheduleResult['errors'] as $se) {
        echo 'SCHEDULE_ERROR: schedule_id=' . $se['schedule_id'] . ' error=' . $se['error'] . PHP_EOL;
    }
}

// ── Find next runnable job ─────────────────────────────────────────────────────
// Selects 'queued' jobs, or 'processing' jobs with a stale lock (>15 min old).
$nextJobRes = $cronConn->query(
    "SELECT id, job_uid, campaign_id, max_per_run
     FROM `email_campaign_queue_jobs`
     WHERE status = 'queued'
        OR (status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
     ORDER BY created_at ASC
     LIMIT 1"
);
$nextJob = $nextJobRes ? $nextJobRes->fetch_assoc() : null;

if (!$nextJob) {
    $out = [
        'status'            => 'idle',
        'message'           => 'No queued jobs found.',
        'ok'                => true,
        'schedules_queued'  => count($scheduleResult['queued']),
        'schedules_errors'  => count($scheduleResult['errors']),
    ];
    if ($isCli) {
        echo 'IDLE: No queued jobs.' . PHP_EOL;
        exit(0);
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

$jobId          = (int)$nextJob['id'];
$effectiveLimit = $limitParam > 0 ? $limitParam : (int)($nextJob['max_per_run'] ?? 100);

// ── Process ────────────────────────────────────────────────────────────────────
$result = campaign_process_queue_job($cronConn, $jobId, $effectiveLimit);

// ── Phase 1L: Sync schedule status from completed job ─────────────────────────
campaign_update_schedule_status_from_queue($cronConn, $jobId, (string)($result['status'] ?? ''));

$out = [
    'job_uid'           => $result['job_uid'],
    'campaign_id'       => $result['campaign_id'],
    'attempted'         => $result['attempted'],
    'sent'              => $result['sent'],
    'failed'            => $result['failed'],
    'remaining'         => $result['remaining'],
    'status'            => $result['status'],
    'ok'                => $result['ok'],
    'error'             => $result['error'],
    'schedules_queued'  => count($scheduleResult['queued']),
    'schedules_errors'  => count($scheduleResult['errors']),
];

if ($isCli) {
    foreach ($out as $k => $v) {
        echo strtoupper((string)$k) . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . PHP_EOL;
    }
    exit($result['ok'] ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($out);
exit;
