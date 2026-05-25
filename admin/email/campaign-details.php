<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

// Get mysqli connection
$campaignConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!$campaignConn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$campaignConn->set_charset('utf8mb4');
$campaignConn->query("SET time_zone = '+08:00'");

// Ensure schema exists
if (!campaign_ensure_schema($campaignConn)) {
    $schemaError = true;
} else {
    $schemaError = false;
}

// Helper function for HTML escaping (fallback)
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// 1. Load Campaign
$campaignId = (int)($_GET['id'] ?? 0);
$campaign = null;

if ($campaignId > 0) {
    try {
        $stmt = $campaignConn->prepare("SELECT * FROM `email_campaigns` WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $campaign = $res->fetch_assoc();
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("campaign-details.php error loading campaign: " . $e->getMessage());
    }
}

// 2. Defensive schema check
$requiredTables = ['email_campaigns', 'email_campaign_recipients', 'email_campaign_link_clicks'];
$schemaIncomplete = false;

if (!$schemaError) {
    foreach ($requiredTables as $table) {
        if (!campaign_table_exists($campaignConn, $table)) {
            $schemaIncomplete = true;
            break;
        }
    }
    
    // Check specific columns needed for recipients
    if (!$schemaIncomplete) {
        $neededRecipientCols = ['campaign_id', 'recipient_email', 'delivery_status', 'opened', 'clicked'];
        foreach ($neededRecipientCols as $col) {
            if (!campaign_column_exists($campaignConn, 'email_campaign_recipients', $col)) {
                $schemaIncomplete = true;
                break;
            }
        }
    }
}

// Page metadata
$title = 'Campaign Details - Demo Admin';
$pageTitle = 'Campaign Details';
$pageDesc = 'Review campaign delivery, opens, clicks, and recipient-level activity.';

// Include header and nav
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8">
    <!-- Back Button & Heading -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="/admin/email/campaign-monitoring.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 transition mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Monitoring
            </a>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">
                <?= $campaign ? e((string)$campaign['campaign_name']) : 'Campaign Not Found' ?>
            </h1>
            <?php if ($campaign): ?>
                <p class="mt-1 text-sm font-semibold text-slate-500">
                    <?= e((string)$campaign['subject']) ?>
                </p>
            <?php endif; ?>
        </div>
        
        <?php if ($campaign): ?>
            <div class="flex items-center gap-3">
                <?php
                $status = (string)($campaign['status'] ?? 'draft');
                $statusColor = match ($status) {
                    'sent' => 'bg-emerald-100 text-emerald-800',
                    'partial_failed' => 'bg-amber-100 text-amber-800',
                    'failed' => 'bg-rose-100 text-rose-800',
                    'sending', 'queued' => 'bg-blue-100 text-blue-800',
                    'scheduled' => 'bg-amber-100 text-amber-800',
                    default => 'bg-slate-100 text-slate-800',
                };
                ?>
                <span class="inline-flex items-center gap-1 px-4 py-2 rounded-full text-sm font-bold <?= $statusColor ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                </span>
                <div class="text-right hidden md:block">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-widest">Campaign UID</div>
                    <div class="text-sm font-mono text-slate-600"><?= e((string)$campaign['campaign_uid']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Error/Not Found State -->
    <?php if (!$campaign): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
            <div class="mb-6">
                <svg class="w-16 h-16 mx-auto text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="text-xl font-black text-slate-900 mb-2">Campaign not found</h3>
            <p class="text-sm text-slate-600 mb-6">
                The campaign you are looking for does not exist or has been removed.
            </p>
            <a href="/admin/email/campaign-monitoring.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-yellow-500 text-white font-bold hover:bg-yellow-400 transition shadow-sm">
                Back to Campaign Monitoring
            </a>
        </div>
    <?php elseif ($schemaIncomplete): ?>
        <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 px-6 py-4">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 pt-0.5">
                    <svg class="w-6 h-6 text-rose-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-rose-900">Schema Incomplete</h2>
                    <p class="mt-1 text-sm text-rose-800">
                        Campaign details schema is incomplete. Please run schema test to ensure all tables and columns are present.
                    </p>
                    <a href="/admin/email/campaign-schema-test.php" class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-rose-600 text-white font-bold hover:bg-rose-700 transition text-sm">
                        Run Schema Test
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Campaign Loaded & Schema OK -->

        <?php
        $remaining             = campaign_count_remaining_recipients($campaignConn, $campaignId);
        $statusCounts          = campaign_count_recipients_by_status($campaignConn, $campaignId);
        $pendingRecipientCount = $statusCounts['pending'] + $statusCounts['queued'];
        $failedRecipientCount  = $statusCounts['failed'];
        $batchSent             = (int)($_GET['batch_sent'] ?? -1);
        $batchFailed           = (int)($_GET['batch_failed'] ?? -1);
        $batchAttempted        = (int)($_GET['batch_attempted'] ?? -1);
        $batchSkipped          = (int)($_GET['batch_skipped'] ?? 0);
        $batchMode             = (string)($_GET['batch_mode'] ?? '');
        $batchUidFlash         = (string)($_GET['batch_uid'] ?? '');
        $lastErr               = (string)($_GET['last_err'] ?? '');
        $notice                = (string)($_GET['notice'] ?? '');

        // Queue flash params
        $queueCreated    = (string)($_GET['queue_created'] ?? '');
        $queueActioned   = (string)($_GET['queue_actioned'] ?? '');
        $queueError      = (string)($_GET['queue_error'] ?? '');
        $queueErrorMsg   = (string)($_GET['queue_error_msg'] ?? '');
        $queueRunDone    = (int)($_GET['queue_run'] ?? -1);
        $queueRunSent    = (int)($_GET['queue_sent'] ?? -1);
        $queueRunFailed  = (int)($_GET['queue_failed'] ?? -1);
        $queueRunRemaining = (int)($_GET['queue_remaining'] ?? -1);
        $queueRunStatus  = (string)($_GET['queue_status'] ?? '');

        // Load next upcoming schedule for this campaign
        $nextSchedule = campaign_get_next_schedule_for_campaign($campaignConn, $campaignId);

        // Load active queue job and recent job history
        $activeQueueJob  = null;
        $queueJobHistory = [];
        if (campaign_table_exists($campaignConn, 'email_campaign_queue_jobs')) {
            $activeQueueJob = campaign_get_active_queue_job($campaignConn, $campaignId);
            try {
                $qjStmt = $campaignConn->prepare(
                    "SELECT * FROM `email_campaign_queue_jobs`
                     WHERE campaign_id = ? ORDER BY created_at DESC LIMIT 5"
                );
                if ($qjStmt) {
                    $qjStmt->bind_param('i', $campaignId);
                    $qjStmt->execute();
                    $qjRes = $qjStmt->get_result();
                    while ($qjRow = $qjRes->fetch_assoc()) $queueJobHistory[] = $qjRow;
                    $qjStmt->close();
                }
            } catch (Exception $qjEx) {
                error_log('Queue job history fetch error: ' . $qjEx->getMessage());
            }
        }
        ?>

        <!-- Batch Process Results -->
        <?php if ($batchSent >= 0): ?>
            <div class="mb-8 rounded-2xl border <?= $batchFailed > 0 ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' ?> px-6 py-4 shadow-sm animate-pulse">
                <div class="flex items-center gap-4">
                    <div class="flex-shrink-0">
                        <?php if ($batchFailed > 0): ?>
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <?php else: ?>
                            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-sm font-black <?= $batchFailed > 0 ? 'text-amber-900' : 'text-emerald-900' ?>">Batch Processed</h4>
                        <p class="text-xs font-bold <?= $batchFailed > 0 ? 'text-amber-700' : 'text-emerald-700' ?>">
                            <?php if ($batchMode !== ''): ?>
                                Mode: <span><?= e(match ($batchMode) {
                                    'failed'             => 'Retry Failed',
                                    'pending_and_failed' => 'Pending + Failed',
                                    default              => 'Send Pending',
                                }) ?></span> &nbsp;|&nbsp;
                            <?php endif; ?>
                            Sent: <span class="underline"><?= $batchSent ?></span> &nbsp;|&nbsp;
                            Failed: <span class="<?= $batchFailed > 0 ? 'text-rose-600' : '' ?>"><?= $batchFailed ?></span> &nbsp;|&nbsp;
                            Remaining: <span class="underline"><?= $remaining ?></span>
                        </p>
                        <?php if ($batchUidFlash !== ''): ?>
                            <p class="mt-0.5 text-[10px] font-mono text-slate-400">Batch: <?= e($batchUidFlash) ?></p>
                        <?php endif; ?>
                        <?php if ($lastErr !== ''): ?>
                            <p class="mt-1 text-[10px] font-mono text-rose-500 italic">Last Error: <?= e($lastErr) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($notice === 'no_pending'): ?>
            <div class="mb-8 rounded-2xl border border-blue-200 bg-blue-50 px-6 py-4 shadow-sm">
                <p class="text-sm font-bold text-blue-800 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    No pending recipients left to send.
                </p>
            </div>
        <?php endif; ?>

        <!-- Queue Action Flash Banners -->
        <?php if ($queueRunDone >= 0): ?>
            <div class="mb-8 rounded-2xl border <?= $queueRunFailed > 0 ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' ?> px-6 py-4 shadow-sm">
                <div class="flex items-center gap-4">
                    <svg class="w-6 h-6 <?= $queueRunFailed > 0 ? 'text-amber-600' : 'text-emerald-600' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <h4 class="text-sm font-black <?= $queueRunFailed > 0 ? 'text-amber-900' : 'text-emerald-900' ?>">Queue Batch Processed</h4>
                        <p class="text-xs font-bold <?= $queueRunFailed > 0 ? 'text-amber-700' : 'text-emerald-700' ?>">
                            Sent: <span class="underline"><?= $queueRunSent ?></span> &nbsp;|&nbsp;
                            Failed: <span class="<?= $queueRunFailed > 0 ? 'text-rose-600' : '' ?>"><?= $queueRunFailed ?></span> &nbsp;|&nbsp;
                            Remaining: <span class="underline"><?= $queueRunRemaining ?></span> &nbsp;|&nbsp;
                            Status: <span class="uppercase"><?= e($queueRunStatus) ?></span>
                        </p>
                        <?php if ($queueErrorMsg !== ''): ?>
                            <p class="mt-0.5 text-[10px] font-mono text-rose-500 italic">Error: <?= e($queueErrorMsg) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($queueCreated !== ''): ?>
            <div class="mb-8 rounded-2xl border border-blue-200 bg-blue-50 px-6 py-4 shadow-sm">
                <p class="text-sm font-bold text-blue-800 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Queue job created. Click "Run Once Now" to process a batch, or let the cron runner handle it automatically.
                </p>
            </div>
        <?php elseif ($queueActioned !== ''): ?>
            <?php
            $actionedLabel = match ($queueActioned) {
                'paused'    => 'Queue paused.',
                'resumed'   => 'Queue resumed — job is queued for next run.',
                'cancelled' => 'Queue job cancelled.',
                default     => 'Queue action completed.',
            };
            ?>
            <div class="mb-8 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-4 shadow-sm">
                <p class="text-sm font-bold text-slate-700 flex items-center gap-2">
                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?= e($actionedLabel) ?>
                </p>
            </div>
        <?php elseif ($queueError !== ''): ?>
            <?php
            $queueErrorLabel = match ($queueError) {
                'job_exists'    => 'An active queue job already exists for this campaign.',
                'create_failed' => 'Failed to create queue job. Check server logs.',
                'no_job'        => 'No active queue job found.',
                default         => 'Queue error: ' . $queueError,
            };
            ?>
            <div class="mb-8 rounded-2xl border border-rose-200 bg-rose-50 px-6 py-4 shadow-sm">
                <p class="text-sm font-bold text-rose-800 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?= e($queueErrorLabel) ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Send Campaign Section -->
        <div id="send-campaign" class="mb-8 rounded-3xl border border-slate-200 bg-white p-8 shadow-sm overflow-hidden relative">
            <div class="absolute top-0 right-0 p-8 opacity-10 pointer-events-none">
                <svg class="w-32 h-32 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>

            <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 mb-6">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 mb-1">Manual Batch Send</h3>
                        <p class="text-sm font-semibold text-slate-500">Manually process and deliver one batch of campaign emails.</p>
                    </div>
                    <?php if ($failedRecipientCount > 0): ?>
                        <a href="?id=<?= $campaignId ?>&status=failed"
                           class="inline-flex items-center gap-1.5 text-xs font-bold text-rose-600 hover:text-rose-800 transition shrink-0 mt-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            View Failed Recipients
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Recipient status mini-counts -->
                <?php
                $totalRecipientsForSend = (int)($campaign['total_recipients'] ?? 0);
                $sentCountForSend       = (int)($campaign['sent_count'] ?? 0);
                ?>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                    <div class="rounded-2xl bg-amber-50 border border-amber-100 p-3 text-center">
                        <div class="text-2xl font-black text-amber-700"><?= number_format($pendingRecipientCount) ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-amber-500 mt-0.5">Pending</div>
                    </div>
                    <div class="rounded-2xl bg-rose-50 border border-rose-100 p-3 text-center">
                        <div class="text-2xl font-black text-rose-700"><?= number_format($failedRecipientCount) ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-rose-400 mt-0.5">Failed</div>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-3 text-center">
                        <div class="text-2xl font-black text-emerald-700"><?= number_format($sentCountForSend) ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-emerald-400 mt-0.5">Sent</div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 border border-slate-200 p-3 text-center">
                        <div class="text-2xl font-black text-slate-700"><?= number_format($totalRecipientsForSend) ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-0.5">Total</div>
                    </div>
                </div>

                <?php if ($pendingRecipientCount === 0 && $failedRecipientCount === 0 && $totalRecipientsForSend > 0): ?>
                    <div class="flex items-center gap-3 p-4 rounded-2xl bg-emerald-50 border border-emerald-100 w-fit">
                        <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <span class="text-sm font-black text-emerald-800 uppercase tracking-tight">Campaign sending completed.</span>
                    </div>
                <?php elseif ($totalRecipientsForSend === 0): ?>
                    <div class="flex items-center gap-3 p-4 rounded-2xl bg-slate-100 border border-slate-200 flex-wrap">
                        <span class="text-sm font-bold text-slate-500 italic">Import recipients before sending.</span>
                        <a href="/admin/email/campaign-import.php?campaign_id=<?= $campaignId ?>" class="text-xs font-black text-slate-700 hover:underline">Import CSV &rarr;</a>
                        <a href="/admin/email/campaign-import.php?mode=group&campaign_id=<?= $campaignId ?>" class="text-xs font-black text-yellow-600 hover:underline">Add from Group &rarr;</a>
                    </div>
                <?php else: ?>
                    <!-- Shared batch size selector -->
                    <div class="mb-5">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Batch Size</label>
                        <select id="batchSizeGlobal" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                            <option value="10">10 Recipients</option>
                            <option value="25" selected>25 Recipients</option>
                            <option value="50">50 Recipients</option>
                            <option value="100">100 Recipients (Max)</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <?php if ($pendingRecipientCount > 0): ?>
                        <!-- A: Send Pending Batch -->
                        <form action="/admin/email/process-campaign-send.php" method="POST"
                            data-confirm="Send Pending Campaign Batch?"
                            data-confirm-desc="This will send emails to pending recipients only. Already sent recipients will not be sent again."
                            data-confirm-ok="Send Now">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="pending">
                            <input type="hidden" name="batch_size" class="batch-size-value" value="25">
                            <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow-lg hover:-translate-y-0.5 active:translate-y-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                Send Pending Batch
                                <span class="text-yellow-100 font-bold text-sm">(<?= number_format($pendingRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($failedRecipientCount > 0): ?>
                        <!-- B: Retry Failed -->
                        <form action="/admin/email/process-campaign-send.php" method="POST"
                            data-confirm="Retry Failed Recipients?"
                            data-confirm-desc="This will retry recipients that previously failed. Already sent recipients will not be sent again."
                            data-confirm-ok="Retry Now">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="failed">
                            <input type="hidden" name="batch_size" class="batch-size-value" value="25">
                            <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-rose-500 text-white font-black hover:bg-rose-400 transition shadow-lg hover:-translate-y-0.5 active:translate-y-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Retry Failed
                                <span class="text-rose-100 font-bold text-sm">(<?= number_format($failedRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($pendingRecipientCount > 0 && $failedRecipientCount > 0): ?>
                        <!-- C: Send Pending + Retry Failed -->
                        <form action="/admin/email/process-campaign-send.php" method="POST"
                            data-confirm="Send Pending and Retry Failed?"
                            data-confirm-desc="This will send pending recipients and retry failed recipients. Already sent recipients will not be sent again."
                            data-confirm-ok="Send + Retry">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="pending_and_failed">
                            <input type="hidden" name="batch_size" class="batch-size-value" value="25">
                            <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-slate-700 text-white font-black hover:bg-slate-600 transition shadow-lg hover:-translate-y-0.5 active:translate-y-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Send Pending + Failed
                                <span class="text-slate-300 font-bold text-sm">(<?= number_format($pendingRecipientCount + $failedRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <script>
                    (function () {
                        var sel = document.getElementById('batchSizeGlobal');
                        if (!sel) return;
                        function sync() {
                            document.querySelectorAll('.batch-size-value').forEach(function (el) {
                                el.value = sel.value;
                            });
                        }
                        sel.addEventListener('change', sync);
                        sync();
                    })();
                    </script>
                <?php endif; ?>
            </div>
        </div>

        <!-- Queue / Cron Sending Section -->
        <div id="queue-sending" class="mb-8 rounded-3xl border border-slate-200 bg-white p-8 shadow-sm overflow-hidden relative">
            <div class="absolute top-0 right-0 p-8 opacity-10 pointer-events-none">
                <svg class="w-32 h-32 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 mb-6">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 mb-1">Queue / Cron Sending</h3>
                        <p class="text-sm font-semibold text-slate-500">Send large campaigns safely in background batches. Use cron URL or Run Once manually.</p>
                    </div>
                    <?php if ($activeQueueJob): ?>
                        <?php
                        $qjStatus = (string)($activeQueueJob['status'] ?? 'queued');
                        $qjStatusColor = match ($qjStatus) {
                            'processing' => 'bg-blue-100 text-blue-800',
                            'paused'     => 'bg-amber-100 text-amber-800',
                            'error'      => 'bg-rose-100 text-rose-800',
                            default      => 'bg-emerald-100 text-emerald-800',
                        };
                        ?>
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-black uppercase tracking-widest <?= $qjStatusColor ?>">
                            <?= e($qjStatus) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($activeQueueJob): ?>
                    <!-- Active job details -->
                    <?php
                    $qj = $activeQueueJob;
                    $qjId         = (int)($qj['id'] ?? 0);
                    $qjMode       = (string)($qj['send_mode'] ?? 'pending');
                    $qjModeLabel  = match ($qjMode) {
                        'failed'             => 'Retry Failed',
                        'pending_and_failed' => 'Pending + Failed',
                        default              => 'Send Pending',
                    };
                    $qjStatus     = (string)($qj['status'] ?? 'queued');
                    $qjRemaining  = (int)($qj['remaining_count'] ?? 0);
                    $qjSent       = (int)($qj['sent_count'] ?? 0);
                    $qjFailed     = (int)($qj['failed_count'] ?? 0);
                    $qjLastRun    = (string)($qj['last_run_at'] ?? '');
                    ?>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                        <div class="rounded-2xl bg-blue-50 border border-blue-100 p-3 text-center">
                            <div class="text-2xl font-black text-blue-700"><?= number_format($qjRemaining) ?></div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-blue-400 mt-0.5">Remaining</div>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-3 text-center">
                            <div class="text-2xl font-black text-emerald-700"><?= number_format($qjSent) ?></div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-emerald-400 mt-0.5">Sent (this job)</div>
                        </div>
                        <div class="rounded-2xl bg-rose-50 border border-rose-100 p-3 text-center">
                            <div class="text-2xl font-black text-rose-700"><?= number_format($qjFailed) ?></div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-rose-400 mt-0.5">Failed (this job)</div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 border border-slate-200 p-3 text-center">
                            <div class="text-xs font-black text-slate-500 uppercase tracking-widest mb-1">Mode</div>
                            <div class="text-sm font-black text-slate-700"><?= e($qjModeLabel) ?></div>
                        </div>
                    </div>

                    <div class="mb-5 p-4 rounded-2xl bg-slate-50 border border-slate-100 text-xs font-mono text-slate-500 space-y-1">
                        <div><span class="font-black uppercase text-slate-400">Job UID:</span> <?= e((string)($qj['job_uid'] ?? '—')) ?></div>
                        <div><span class="font-black uppercase text-slate-400">Batch / Max-per-run:</span> <?= (int)($qj['batch_size'] ?? 25) ?> / <?= (int)($qj['max_per_run'] ?? 100) ?></div>
                        <div><span class="font-black uppercase text-slate-400">Last run:</span> <?= $qjLastRun ? e(date('M d H:i:s', strtotime($qjLastRun))) : '—' ?></div>
                        <div><span class="font-black uppercase text-slate-400">Created by:</span> <?= e((string)($qj['initiated_by'] ?? '—')) ?></div>
                        <?php if (!empty($qj['error_message'])): ?>
                            <div class="text-rose-500"><span class="font-black uppercase text-rose-400">Error:</span> <?= e((string)$qj['error_message']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-3">
                        <?php if (in_array($qjStatus, ['queued', 'error'], true)): ?>
                            <form action="/admin/email/campaign-queue-runner.php" method="POST"
                                data-confirm="Run One Batch Now?"
                                data-confirm-desc="This will process up to max_per_run recipients immediately."
                                data-confirm-ok="Run Now">
                                <input type="hidden" name="action" value="run_once">
                                <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                <input type="hidden" name="job_id" value="<?= $qjId ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Run Once Now
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($qjStatus === 'queued' || $qjStatus === 'processing'): ?>
                            <form action="/admin/email/campaign-queue-runner.php" method="POST">
                                <input type="hidden" name="action" value="pause_queue">
                                <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                <input type="hidden" name="job_id" value="<?= $qjId ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-amber-500 text-white font-black hover:bg-amber-400 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Pause
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($qjStatus === 'paused'): ?>
                            <form action="/admin/email/campaign-queue-runner.php" method="POST">
                                <input type="hidden" name="action" value="resume_queue">
                                <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                <input type="hidden" name="job_id" value="<?= $qjId ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-emerald-600 text-white font-black hover:bg-emerald-500 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Resume
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($qjStatus, ['queued', 'processing', 'paused', 'error'], true)): ?>
                            <form action="/admin/email/campaign-queue-runner.php" method="POST"
                                data-confirm="Cancel this queue job?"
                                data-confirm-desc="Recipients currently pending/failed will remain as-is and can be retried later."
                                data-confirm-ok="Cancel Job">
                                <input type="hidden" name="action" value="cancel_queue">
                                <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                <input type="hidden" name="job_id" value="<?= $qjId ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-slate-200 text-slate-700 font-black hover:bg-slate-300 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    Cancel
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                <?php elseif ($totalRecipientsForSend > 0 && ($pendingRecipientCount > 0 || $failedRecipientCount > 0)): ?>
                    <!-- No active job — show create forms -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Batch Size (per run)</label>
                            <select name="q_batch_size" id="qBatchSize" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Max Per Cron Run</label>
                            <select name="q_max_per_run" id="qMaxPerRun" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100" selected>100</option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <?php if ($pendingRecipientCount > 0): ?>
                        <form action="/admin/email/campaign-queue-runner.php" method="POST"
                            data-confirm="Queue Pending Recipients?"
                            data-confirm-desc="Creates a background queue job for all pending recipients. Use cron or Run Once to process."
                            data-confirm-ok="Create Queue">
                            <input type="hidden" name="action" value="create_queue">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="pending">
                            <input type="hidden" name="batch_size" class="q-batch-size" value="25">
                            <input type="hidden" name="max_per_run" class="q-max-per-run" value="100">
                            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Queue Pending
                                <span class="text-yellow-100 font-bold text-sm">(<?= number_format($pendingRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($failedRecipientCount > 0): ?>
                        <form action="/admin/email/campaign-queue-runner.php" method="POST"
                            data-confirm="Queue Retry Failed?"
                            data-confirm-desc="Creates a background queue job to retry all failed recipients."
                            data-confirm-ok="Create Queue">
                            <input type="hidden" name="action" value="create_queue">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="failed">
                            <input type="hidden" name="batch_size" class="q-batch-size" value="25">
                            <input type="hidden" name="max_per_run" class="q-max-per-run" value="100">
                            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-rose-500 text-white font-black hover:bg-rose-400 transition shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Queue Retry Failed
                                <span class="text-rose-100 font-bold text-sm">(<?= number_format($failedRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($pendingRecipientCount > 0 && $failedRecipientCount > 0): ?>
                        <form action="/admin/email/campaign-queue-runner.php" method="POST"
                            data-confirm="Queue Pending + Failed?"
                            data-confirm-desc="Creates a background queue job for both pending and failed recipients."
                            data-confirm-ok="Create Queue">
                            <input type="hidden" name="action" value="create_queue">
                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="send_mode" value="pending_and_failed">
                            <input type="hidden" name="batch_size" class="q-batch-size" value="25">
                            <input type="hidden" name="max_per_run" class="q-max-per-run" value="100">
                            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-slate-700 text-white font-black hover:bg-slate-600 transition shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Queue Pending + Failed
                                <span class="text-slate-300 font-bold text-sm">(<?= number_format($pendingRecipientCount + $failedRecipientCount) ?>)</span>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <script>
                    (function () {
                        var bs = document.getElementById('qBatchSize');
                        var mp = document.getElementById('qMaxPerRun');
                        function sync() {
                            document.querySelectorAll('.q-batch-size').forEach(function(el){ el.value = bs ? bs.value : '25'; });
                            document.querySelectorAll('.q-max-per-run').forEach(function(el){ el.value = mp ? mp.value : '100'; });
                        }
                        if (bs) bs.addEventListener('change', sync);
                        if (mp) mp.addEventListener('change', sync);
                        sync();
                    })();
                    </script>
                <?php else: ?>
                    <div class="flex items-center gap-3 p-4 rounded-2xl bg-slate-100 border border-slate-200 w-fit">
                        <span class="text-sm font-bold text-slate-500 italic">No eligible recipients to queue.</span>
                    </div>
                <?php endif; ?>

                <!-- Recent queue job history -->
                <?php if (!empty($queueJobHistory)): ?>
                    <div class="mt-6">
                        <h4 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-3">Recent Queue Jobs</h4>
                        <div class="rounded-2xl border border-slate-200 overflow-hidden">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200">
                                        <th class="px-3 py-2 text-left font-bold uppercase tracking-widest text-slate-400">UID</th>
                                        <th class="px-3 py-2 text-left font-bold uppercase tracking-widest text-slate-400">Mode</th>
                                        <th class="px-3 py-2 text-left font-bold uppercase tracking-widest text-slate-400">Status</th>
                                        <th class="px-3 py-2 text-right font-bold uppercase tracking-widest text-slate-400">Sent</th>
                                        <th class="px-3 py-2 text-right font-bold uppercase tracking-widest text-slate-400">Failed</th>
                                        <th class="px-3 py-2 text-right font-bold uppercase tracking-widest text-slate-400">Remaining</th>
                                        <th class="px-3 py-2 text-left font-bold uppercase tracking-widest text-slate-400">Last Run</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($queueJobHistory as $qh): ?>
                                        <?php
                                        $qhStatus = (string)($qh['status'] ?? 'queued');
                                        $qhStatusColor = match ($qhStatus) {
                                            'completed'  => 'bg-emerald-100 text-emerald-800',
                                            'processing' => 'bg-blue-100 text-blue-800',
                                            'paused'     => 'bg-amber-100 text-amber-800',
                                            'cancelled'  => 'bg-slate-100 text-slate-500',
                                            'error'      => 'bg-rose-100 text-rose-800',
                                            default      => 'bg-blue-50 text-blue-700',
                                        };
                                        $qhMode = (string)($qh['send_mode'] ?? 'pending');
                                        $qhModeLabel = match ($qhMode) {
                                            'failed'             => 'Retry Failed',
                                            'pending_and_failed' => 'Pending + Failed',
                                            default              => 'Send Pending',
                                        };
                                        ?>
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="px-3 py-2 font-mono text-slate-400"><?= e(substr((string)($qh['job_uid'] ?? '—'), 0, 20)) ?></td>
                                            <td class="px-3 py-2 text-slate-700"><?= e($qhModeLabel) ?></td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $qhStatusColor ?>"><?= e($qhStatus) ?></span>
                                            </td>
                                            <td class="px-3 py-2 text-right font-black text-emerald-600"><?= number_format((int)$qh['sent_count']) ?></td>
                                            <td class="px-3 py-2 text-right font-black <?= (int)$qh['failed_count'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>"><?= number_format((int)$qh['failed_count']) ?></td>
                                            <td class="px-3 py-2 text-right font-bold text-slate-500"><?= number_format((int)$qh['remaining_count']) ?></td>
                                            <td class="px-3 py-2 text-slate-400">
                                                <?= !empty($qh['last_run_at']) ? e(date('M d H:i', strtotime((string)$qh['last_run_at']))) : '—' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Scheduled Sending Section -->
        <div class="mb-8 rounded-3xl border border-slate-200 bg-white p-8 shadow-sm overflow-hidden relative">
            <div class="absolute top-0 right-0 p-8 opacity-10 pointer-events-none">
                <svg class="w-32 h-32 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 mb-6">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 mb-1">Scheduled Sending</h3>
                        <p class="text-sm font-semibold text-slate-500">Prepare emails ahead of time and let the cron runner send them automatically on selected dates.</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="/admin/email/campaign-schedule-form.php?campaign_id=<?= $campaignId ?>"
                           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm shadow whitespace-nowrap">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            Schedule Campaign
                        </a>
                        <a href="/admin/email/campaign-schedules.php?campaign_id=<?= $campaignId ?>"
                           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-600 font-black hover:bg-slate-50 transition text-sm whitespace-nowrap">
                            All Schedules
                        </a>
                    </div>
                </div>

                <?php if ($nextSchedule): ?>
                    <?php
                    $nsAt = (string)$nextSchedule['scheduled_at'];
                    $nsModeLabel = match ((string)$nextSchedule['send_mode']) {
                        'failed'             => 'Failed only',
                        'pending_and_failed' => 'Pending + Failed',
                        default              => 'Pending only',
                    };
                    ?>
                    <div class="rounded-2xl border border-blue-200 bg-blue-50 px-6 py-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-black uppercase tracking-widest text-blue-500 mb-1">Next Scheduled Send</div>
                                <div class="text-lg font-black text-blue-900">
                                    <?= e(date('D, d M Y', strtotime($nsAt))) ?>
                                    <span class="text-blue-600 ml-2"><?= e(date('H:i', strtotime($nsAt))) ?></span>
                                </div>
                                <div class="mt-1 text-xs font-bold text-blue-600">
                                    <?= e((string)($nextSchedule['schedule_name'] ?: 'Unnamed')) ?>
                                    &nbsp;&middot;&nbsp; Mode: <?= e($nsModeLabel) ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="/admin/email/campaign-schedule-form.php?id=<?= (int)$nextSchedule['id'] ?>"
                                   class="inline-flex items-center gap-1 px-4 py-2 rounded-xl bg-white border border-blue-200 text-blue-700 font-black text-xs hover:bg-blue-50 transition">
                                    Edit
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 py-6 text-center">
                        <p class="text-sm font-bold text-slate-500 mb-3">No upcoming schedules for this campaign.</p>
                        <a href="/admin/email/campaign-schedule-form.php?campaign_id=<?= $campaignId ?>"
                           class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-yellow-500 text-white font-black text-sm hover:bg-yellow-400 transition shadow">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            Create Schedule
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Campaign Content Card -->
        <div class="mb-8 rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h3 class="text-xl font-black text-slate-900">Campaign Content</h3>
                    <p class="text-sm font-semibold text-slate-500">The message and styling that recipients will see.</p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="/admin/email/campaign-content.php?id=<?= $campaignId ?>" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-slate-900 text-white font-black hover:bg-slate-800 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Edit Content
                    </a>
                    <a href="/admin/email/campaign-targeting.php?source_campaign_id=<?= $campaignId ?>" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                        Target Segment
                    </a>
                </div>
            </div>

            <?php
            $contentReady = campaign_campaign_content_ready($campaign);
            $lastUpdated = (string)($campaign['content_updated_at'] ?? '');
            ?>

            <?php if (!$contentReady): ?>
                <div class="p-6 rounded-2xl bg-rose-50 border border-rose-100 flex items-center gap-4">
                    <div class="h-10 w-10 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-rose-900 uppercase">Configuration Required</h4>
                        <p class="text-xs font-bold text-rose-700">Campaign content is not configured yet. Please edit content before sending.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Subject Line</div>
                            <div class="text-sm font-bold text-slate-900"><?= e((string)$campaign['subject']) ?></div>
                        </div>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Button Action</div>
                            <div class="text-sm font-bold text-slate-900 truncate"><?= e((string)$campaign['button_text']) ?> &rarr; <?= e((string)$campaign['button_url']) ?></div>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Brand Context</div>
                            <div class="text-sm font-bold text-slate-900"><?= e((string)($campaign['brand_name'] ?: 'Demo')) ?> (<?= e((string)($campaign['support_email'] ?: 'support@demo.local')) ?>)</div>
                        </div>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Last Updated</div>
                            <div class="text-sm font-bold text-slate-900">
                                <?= $lastUpdated ? e(date('M d, Y H:i', strtotime($lastUpdated))) : 'Never' ?>
                                <span class="text-[10px] text-slate-400 ml-1 font-bold">by <?= e((string)($campaign['content_updated_by'] ?: 'Admin')) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Info Strip -->
        <div class="flex flex-wrap gap-x-8 gap-y-2 mb-8 text-sm font-semibold text-slate-500">
            <div class="flex items-center gap-1.5">
                <span class="text-xs uppercase tracking-widest text-slate-400 font-bold">Created By:</span>
                <span class="text-slate-900"><?= e((string)$campaign['created_by']) ?></span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="text-xs uppercase tracking-widest text-slate-400 font-bold">Created At:</span>
                <span class="text-slate-900"><?= e(date('M d, Y H:i', strtotime((string)$campaign['created_at']))) ?></span>
            </div>
            <?php if ($campaign['sent_at']): ?>
                <div class="flex items-center gap-1.5">
                    <span class="text-xs uppercase tracking-widest text-slate-400 font-bold">Sent At:</span>
                    <span class="text-slate-900"><?= e(date('M d, Y H:i', strtotime((string)$campaign['sent_at']))) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Summary Cards -->
        <?php
        $totalRecipients = (int)($campaign['total_recipients'] ?? 0);
        $sentCount = (int)($campaign['sent_count'] ?? 0);
        $failedCount = (int)($campaign['failed_count'] ?? 0);
        $deliveredCount = (int)($campaign['delivered_count'] ?? 0);
        $openedCount = (int)($campaign['opened_count'] ?? 0);
        $totalOpenCount = (int)($campaign['total_open_count'] ?? 0);
        $clickedCount = (int)($campaign['clicked_count'] ?? 0);
        $totalClickCount = (int)($campaign['total_click_count'] ?? 0);
        $openRate = (float)($campaign['open_rate'] ?? 0);
        $clickRate = (float)($campaign['click_rate'] ?? 0);
        ?>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Total Recipients</div>
                <div class="text-2xl font-black text-slate-900"><?= number_format($totalRecipients) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Sent</div>
                <div class="text-2xl font-black text-emerald-600"><?= number_format($sentCount) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Failed</div>
                <div class="text-2xl font-black text-rose-600"><?= number_format($failedCount) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Delivered</div>
                <div class="text-2xl font-black text-blue-600"><?= number_format($deliveredCount) ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Open Rate</div>
                <div class="text-2xl font-black text-slate-900"><?= number_format($openRate, 2) ?>%</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Unique Opens</div>
                <div class="text-2xl font-black text-slate-900"><?= number_format($openedCount) ?></div>
                <div class="text-[10px] font-bold text-slate-400 mt-1"><?= number_format($totalOpenCount) ?> total</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Unique Clicks</div>
                <div class="text-2xl font-black text-slate-900"><?= number_format($clickedCount) ?></div>
                <div class="text-[10px] font-bold text-slate-400 mt-1"><?= number_format($totalClickCount) ?> total</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Click Rate</div>
                <div class="text-2xl font-black text-slate-900"><?= number_format($clickRate, 2) ?>%</div>
            </div>
        </div>

        <!-- Recipient Filters -->
        <?php
        $searchQ = trim((string)($_GET['q'] ?? ''));
        $filterActivity = trim((string)($_GET['activity'] ?? 'all'));
        $filterStatus = trim((string)($_GET['status'] ?? 'all'));
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="id" value="<?= $campaignId ?>">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">Search Recipient</label>
                    <input type="text" name="q" value="<?= e($searchQ) ?>" placeholder="Email, name or phone..." class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">Activity</label>
                    <select name="activity" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="all">All Activity</option>
                        <option value="opened" <?= $filterActivity === 'opened' ? 'selected' : '' ?>>Opened</option>
                        <option value="not_opened" <?= $filterActivity === 'not_opened' ? 'selected' : '' ?>>Not Opened</option>
                        <option value="clicked" <?= $filterActivity === 'clicked' ? 'selected' : '' ?>>Clicked</option>
                        <option value="never_clicked" <?= $filterActivity === 'never_clicked' ? 'selected' : '' ?>>Never Clicked</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">Status</label>
                    <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="all">All Statuses</option>
                        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="queued" <?= $filterStatus === 'queued' ? 'selected' : '' ?>>Queued</option>
                        <option value="sent" <?= $filterStatus === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="bounced" <?= $filterStatus === 'bounced' ? 'selected' : '' ?>>Bounced</option>
                        <option value="spam" <?= $filterStatus === 'spam' ? 'selected' : '' ?>>Spam</option>
                        <option value="complained" <?= $filterStatus === 'complained' ? 'selected' : '' ?>>Complained</option>
                        <option value="unsubscribed" <?= $filterStatus === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-yellow-500 py-2.5 text-sm font-bold text-white hover:bg-yellow-400 transition shadow-sm">
                    Filter Recipients
                </button>
            </form>
        </div>

        <!-- Recipient Table -->
        <?php
        $where = "WHERE campaign_id = ?";
        $params = [$campaignId];
        $types = 'i';

        if ($searchQ !== '') {
            $where .= " AND (recipient_email LIKE ? OR recipient_name LIKE ? OR recipient_phone LIKE ?)";
            $lq = '%' . $searchQ . '%';
            $params[] = $lq; $params[] = $lq; $params[] = $lq;
            $types .= 'sss';
        }

        if ($filterActivity === 'opened') $where .= " AND opened = 1";
        if ($filterActivity === 'not_opened') $where .= " AND opened = 0";
        if ($filterActivity === 'clicked') $where .= " AND clicked = 1";
        if ($filterActivity === 'never_clicked') $where .= " AND clicked = 0";

        if ($filterStatus !== 'all') {
            $where .= " AND delivery_status = ?";
            $params[] = $filterStatus;
            $types .= 's';
        }

        // Count total recipients for pagination
        $totalRecipientsFiltered = 0;
        try {
            $countStmt = $campaignConn->prepare("SELECT COUNT(*) FROM `email_campaign_recipients` {$where}");
            if ($countStmt) {
                $countStmt->bind_param($types, ...$params);
                $countStmt->execute();
                $countStmt->bind_result($totalRecipientsFiltered);
                $countStmt->fetch();
                $countStmt->close();
            }
        } catch (Exception $e) {
            error_log("Recipient count error: " . $e->getMessage());
        }

        $totalPages = ceil($totalRecipientsFiltered / $perPage);
        $offset = ($currentPage - 1) * $perPage;

        $recipients = [];
        try {
            $recipientSql = "SELECT * FROM `email_campaign_recipients` {$where} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
            $recStmt = $campaignConn->prepare($recipientSql);
            if ($recStmt) {
                $rParams = array_merge($params, [$perPage, $offset]);
                $rTypes = $types . 'ii';
                $recStmt->bind_param($rTypes, ...$rParams);
                $recStmt->execute();
                $recRes = $recStmt->get_result();
                while ($row = $recRes->fetch_assoc()) {
                    $recipients[] = $row;
                }
                $recStmt->close();
            }
        } catch (Exception $e) {
            error_log("Recipient fetch error: " . $e->getMessage());
        }
        ?>

        <div class="mb-12">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                <h2 class="text-xl font-black text-slate-900 flex items-center gap-2">
                    Recipients
                    <span class="text-sm font-bold bg-slate-100 px-2 py-0.5 rounded text-slate-500"><?= number_format($totalRecipientsFiltered) ?></span>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <a href="/admin/email/campaign-import.php?mode=group&campaign_id=<?= $campaignId ?>"
                       class="px-3 py-1.5 rounded-lg bg-yellow-500 text-white text-[10px] font-black uppercase tracking-widest hover:bg-yellow-400 transition">Add from Group</a>
                    <a href="/admin/email/campaign-import.php?campaign_id=<?= $campaignId ?>"
                       class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-[10px] font-black uppercase tracking-widest hover:bg-slate-700 transition">Import CSV</a>
                    <a href="/admin/email/campaign-export.php?campaign_id=<?= $campaignId ?>&segment=all" class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 transition">Export All</a>
                    <a href="/admin/email/campaign-export.php?campaign_id=<?= $campaignId ?>&segment=opened" class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-[10px] font-black uppercase tracking-widest hover:bg-emerald-100 transition border border-emerald-100">Export Opened</a>
                    <a href="/admin/email/campaign-export.php?campaign_id=<?= $campaignId ?>&segment=clicked" class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-[10px] font-black uppercase tracking-widest hover:bg-blue-100 transition border border-blue-100">Export Clicked</a>
                    <a href="/admin/email/campaign-export.php?campaign_id=<?= $campaignId ?>&segment=failed" class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-[10px] font-black uppercase tracking-widest hover:bg-rose-100 transition border border-rose-100">Export Failed</a>
                </div>
            </div>
            
            <?php if (empty($recipients)): ?>
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-12 text-center">
                    <p class="text-slate-500 font-bold">No recipients found for this campaign yet.</p>
                </div>
            <?php else: ?>
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-left">
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Recipient</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Status</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Sent At</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400 text-center">Engagement</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Notes</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($recipients as $rec): ?>
                                    <tr class="hover:bg-slate-50/50 transition">
                                        <td class="px-6 py-4">
                                            <div class="font-bold text-slate-900"><?= e((string)$rec['recipient_name'] ?: 'No Name') ?></div>
                                            <div class="text-sm text-slate-500"><?= e((string)$rec['recipient_email']) ?></div>
                                            <?php if ($rec['recipient_phone']): ?>
                                                <div class="text-[10px] font-mono text-slate-400 mt-1"><?= e((string)$rec['recipient_phone']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php
                                            $rStatus = (string)$rec['delivery_status'];
                                            $rColor = match ($rStatus) {
                                                'sent', 'delivered' => 'bg-emerald-100 text-emerald-800',
                                                'failed', 'bounced', 'spam', 'complained' => 'bg-rose-100 text-rose-800',
                                                'pending', 'queued' => 'bg-amber-100 text-amber-800',
                                                'unsubscribed' => 'bg-slate-100 text-slate-800',
                                                default => 'bg-slate-100 text-slate-800',
                                            };
                                            ?>
                                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest <?= $rColor ?>">
                                                <?= e($rStatus) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-500">
                                            <?= $rec['created_at'] ? e(date('M d, H:i', strtotime((string)$rec['created_at']))) : '—' ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center justify-center gap-4">
                                                <div class="text-center">
                                                    <div class="text-[10px] font-bold uppercase text-slate-400">Opens</div>
                                                    <div class="font-black <?= $rec['opened'] ? 'text-emerald-600' : 'text-slate-300' ?>"><?= (int)$rec['open_count'] ?></div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="text-[10px] font-bold uppercase text-slate-400">Clicks</div>
                                                    <div class="font-black <?= $rec['clicked'] ? 'text-blue-600' : 'text-slate-300' ?>"><?= (int)$rec['click_count'] ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-rose-500 font-medium max-w-xs truncate">
                                            <?= e((string)$rec['failed_reason']) ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="text-[10px] font-bold uppercase text-slate-300">Use Segment Export</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="mt-6 flex items-center justify-center gap-2">
                        <?php if ($currentPage > 1): ?>
                            <a href="?id=<?= $campaignId ?>&page=<?= $currentPage - 1 ?>&q=<?= urlencode($searchQ) ?>&activity=<?= urlencode($filterActivity) ?>&status=<?= urlencode($filterStatus) ?>" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm font-bold text-slate-600 hover:bg-slate-50 transition">← Previous</a>
                        <?php endif; ?>
                        <div class="text-sm font-bold text-slate-400">Page <?= $currentPage ?> of <?= $totalPages ?></div>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="?id=<?= $campaignId ?>&page=<?= $currentPage + 1 ?>&q=<?= urlencode($searchQ) ?>&activity=<?= urlencode($filterActivity) ?>&status=<?= urlencode($filterStatus) ?>" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm font-bold text-slate-600 hover:bg-slate-50 transition">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Activity Sections -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Latest Click Activity -->
            <div>
                <h2 class="text-xl font-black text-slate-900 mb-4">Latest Click Activity</h2>
                <?php
                $clicks = [];
                try {
                    $clickSql = "
                        SELECT cl.*, rec.recipient_email, rec.recipient_name 
                        FROM `email_campaign_link_clicks` cl
                        JOIN `email_campaign_recipients` rec ON cl.recipient_id = rec.id
                        WHERE cl.campaign_id = ?
                        ORDER BY cl.clicked_at DESC
                        LIMIT 25
                    ";
                    $clStmt = $campaignConn->prepare($clickSql);
                    if ($clStmt) {
                        $clStmt->bind_param('i', $campaignId);
                        $clStmt->execute();
                        $clRes = $clStmt->get_result();
                        while ($clRow = $clRes->fetch_assoc()) {
                            $clicks[] = $clRow;
                        }
                        $clStmt->close();
                    }
                } catch (Exception $e) {
                    error_log("Click activity fetch error: " . $e->getMessage());
                }
                ?>
                
                <?php if (empty($clicks)): ?>
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                        <p class="text-slate-400 text-sm font-bold">No click activity recorded yet.</p>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($clicks as $cl): ?>
                                <div class="px-6 py-4 hover:bg-slate-50 transition">
                                    <div class="flex justify-between items-start mb-1">
                                        <div class="font-bold text-slate-900 text-sm truncate max-w-[200px] md:max-w-xs" title="<?= e((string)$cl['recipient_email']) ?>">
                                            <?= e((string)$cl['recipient_name'] ?: $cl['recipient_email']) ?>
                                        </div>
                                        <div class="text-[10px] font-bold text-slate-400 whitespace-nowrap">
                                            <?= e(date('M d, H:i:s', strtotime((string)$cl['clicked_at']))) ?>
                                        </div>
                                    </div>
                                    <div class="text-xs text-blue-600 font-medium truncate mb-1">
                                        <a href="<?= e((string)$cl['link_url']) ?>" target="_blank" class="hover:underline">
                                            <?= e((string)$cl['link_url']) ?>
                                        </a>
                                    </div>
                                    <div class="text-[10px] text-slate-400 truncate font-mono italic">
                                        <?= e(substr((string)$cl['user_agent'], 0, 80)) ?><?= strlen((string)$cl['user_agent']) > 80 ? '...' : '' ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Clicked Links -->
            <div>
                <h2 class="text-xl font-black text-slate-900 mb-4">Top Clicked Links</h2>
                <?php
                $topLinks = [];
                try {
                    $topSql = "
                        SELECT link_url, link_hash,
                               COUNT(*) AS total_clicks,
                               COUNT(DISTINCT recipient_id) AS unique_clicks,
                               MAX(clicked_at) AS last_clicked_at
                        FROM `email_campaign_link_clicks`
                        WHERE campaign_id = ?
                        GROUP BY link_hash, link_url
                        ORDER BY total_clicks DESC
                        LIMIT 10
                    ";
                    $topStmt = $campaignConn->prepare($topSql);
                    if ($topStmt) {
                        $topStmt->bind_param('i', $campaignId);
                        $topStmt->execute();
                        $topRes = $topStmt->get_result();
                        while ($topRow = $topRes->fetch_assoc()) {
                            $topLinks[] = $topRow;
                        }
                        $topStmt->close();
                    }
                } catch (Exception $e) {
                    error_log("Top links fetch error: " . $e->getMessage());
                }
                ?>
                
                <?php if (empty($topLinks)): ?>
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                        <p class="text-slate-400 text-sm font-bold">No click data available yet.</p>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200">
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400">Link URL</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Unique</th>
                                    <th class="px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($topLinks as $link): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-slate-900 truncate max-w-xs" title="<?= e((string)$link['link_url']) ?>">
                                                <?= e((string)$link['link_url']) ?>
                                            </div>
                                            <div class="text-[10px] font-bold text-slate-400 mt-0.5">
                                                Last clicked: <?= e(date('M d, H:i', strtotime((string)$link['last_clicked_at']))) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-right font-black text-slate-900">
                                            <?= number_format((int)$link['unique_clicks']) ?>
                                        </td>
                                        <td class="px-6 py-4 text-right font-black text-blue-600">
                                            <?= number_format((int)$link['total_clicks']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Send Batch History -->
        <?php
        $sendBatches = [];
        if (campaign_table_exists($campaignConn, 'email_campaign_send_batches')) {
            try {
                $batchHistStmt = $campaignConn->prepare(
                    "SELECT * FROM `email_campaign_send_batches`
                     WHERE campaign_id = ?
                     ORDER BY started_at DESC
                     LIMIT 20"
                );
                if ($batchHistStmt) {
                    $batchHistStmt->bind_param('i', $campaignId);
                    $batchHistStmt->execute();
                    $batchHistRes = $batchHistStmt->get_result();
                    while ($bRow = $batchHistRes->fetch_assoc()) {
                        $sendBatches[] = $bRow;
                    }
                    $batchHistStmt->close();
                }
            } catch (Exception $batchHistEx) {
                error_log('Send batch history fetch error: ' . $batchHistEx->getMessage());
            }
        }
        ?>
        <div class="mt-10 pt-8 border-t border-slate-100 mb-12">
            <h2 class="text-xl font-black text-slate-900 mb-4">Send Batch History</h2>
            <?php if (empty($sendBatches)): ?>
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                    <p class="text-slate-400 text-sm font-bold">No send batches recorded yet.</p>
                </div>
            <?php else: ?>
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-left">
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 whitespace-nowrap">Started</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 whitespace-nowrap">Completed</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400">Mode</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400">Status</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Size</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Sent</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Failed</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400 text-right">Left</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400">By</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-widest text-slate-400">Batch UID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($sendBatches as $sb): ?>
                                    <?php
                                    $sbStatus = (string)($sb['status'] ?? 'started');
                                    $sbStatusColor = match ($sbStatus) {
                                        'completed'      => 'bg-emerald-100 text-emerald-800',
                                        'partial_failed' => 'bg-amber-100 text-amber-800',
                                        'failed'         => 'bg-rose-100 text-rose-800',
                                        default          => 'bg-blue-100 text-blue-800',
                                    };
                                    $sbMode = (string)($sb['send_mode'] ?? 'pending');
                                    $sbModeColor = match ($sbMode) {
                                        'failed'             => 'bg-rose-50 text-rose-700',
                                        'pending_and_failed' => 'bg-amber-50 text-amber-700',
                                        default              => 'bg-blue-50 text-blue-700',
                                    };
                                    $sbModeLabel = match ($sbMode) {
                                        'failed'             => 'Retry Failed',
                                        'pending_and_failed' => 'Pending + Failed',
                                        default              => 'Send Pending',
                                    };
                                    ?>
                                    <tr class="hover:bg-slate-50/50 transition">
                                        <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                            <?= $sb['started_at'] ? e(date('M d H:i', strtotime((string)$sb['started_at']))) : '—' ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-500 whitespace-nowrap">
                                            <?php if ($sb['completed_at']): ?>
                                                <?= e(date('M d H:i', strtotime((string)$sb['completed_at']))) ?>
                                            <?php else: ?>
                                                <span class="text-slate-300 italic text-xs">In progress</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest <?= $sbModeColor ?>">
                                                <?= e($sbModeLabel) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest <?= $sbStatusColor ?>">
                                                <?= e(str_replace('_', ' ', $sbStatus)) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold text-slate-700"><?= number_format((int)$sb['batch_size']) ?></td>
                                        <td class="px-4 py-3 text-right font-black text-emerald-600"><?= number_format((int)$sb['sent_count']) ?></td>
                                        <td class="px-4 py-3 text-right font-black <?= (int)$sb['failed_count'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>">
                                            <?= number_format((int)$sb['failed_count']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold text-slate-500"><?= number_format((int)$sb['remaining_count']) ?></td>
                                        <td class="px-4 py-3 text-slate-500 text-xs"><?= e((string)($sb['initiated_by'] ?? '—')) ?></td>
                                        <td class="px-4 py-3">
                                            <span class="text-[10px] font-mono text-slate-400"><?= e((string)($sb['batch_uid'] ?? '—')) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>

<!-- SDC Confirm Modal -->
<div id="sdcConfirm" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
  <!-- backdrop -->
  <div data-sdc-confirm-close class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>

  <!-- panel -->
  <div
    id="sdcConfirmPanel"
    class="relative w-full max-w-md rounded-[2rem] bg-white border border-slate-100 shadow-2xl shadow-slate-900/20
           transform transition-all duration-150 scale-95 opacity-0"
    role="dialog" aria-modal="true" aria-labelledby="sdcConfirmTitle" aria-describedby="sdcConfirmDesc"
  >
    <div class="p-6">
      <div class="flex items-start gap-4">
        <div class="shrink-0 w-11 h-11 rounded-2xl bg-yellow-50 border border-yellow-100 flex items-center justify-center text-yellow-600">
          <!-- mail icon -->
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
        </div>

        <div class="min-w-0">
          <h3 id="sdcConfirmTitle" class="text-lg font-black text-slate-900">Confirm</h3>
          <p id="sdcConfirmDesc" class="mt-1 text-sm font-semibold text-slate-500 whitespace-pre-line">Are you sure?</p>
        </div>
      </div>

      <div class="mt-6 flex items-center justify-end gap-3">
        <button type="button" data-sdc-confirm-cancel
          class="px-5 py-2.5 rounded-2xl font-black text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition">
          Cancel
        </button>

        <button type="button" data-sdc-confirm-ok
          class="px-6 py-2.5 rounded-2xl font-black bg-yellow-500 text-white hover:bg-yellow-600 transition shadow-lg active:scale-[0.98]">
          Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById("sdcConfirm");
  const panel = document.getElementById("sdcConfirmPanel");
  if (!modal || !panel) return;

  const titleEl = document.getElementById("sdcConfirmTitle");
  const descEl  = document.getElementById("sdcConfirmDesc");
  const btnOk   = modal.querySelector("[data-sdc-confirm-ok]");
  const btnCancel = modal.querySelector("[data-sdc-confirm-cancel]");

  let pendingForm = null;
  let lastActive = null;

  const open = (form) => {
    pendingForm = form;
    lastActive = document.activeElement;

    const t = form.getAttribute("data-confirm") || "Confirm";
    let d = form.getAttribute("data-confirm-desc") || "Are you sure?";
    const okLbl = form.getAttribute("data-confirm-ok") || "Confirm";

    // Handle batch size display if exists
    const batchSizeEl = form.querySelector('[name="batch_size"]');
    if (batchSizeEl) {
        d += "\n\nBatch size: " + batchSizeEl.value;
    }

    titleEl.textContent = t;
    descEl.textContent = d;
    btnOk.textContent = okLbl;

    modal.classList.remove("hidden");
    modal.classList.add("flex");
    document.documentElement.classList.add("overflow-hidden");

    requestAnimationFrame(() => {
      panel.classList.remove("opacity-0", "scale-95");
      panel.classList.add("opacity-100", "scale-100");
    });

    btnOk.focus();
  };

  const close = () => {
    panel.classList.remove("opacity-100", "scale-100");
    panel.classList.add("opacity-0", "scale-95");

    setTimeout(() => {
      modal.classList.add("hidden");
      modal.classList.remove("flex");
      document.documentElement.classList.remove("overflow-hidden");
      pendingForm = null;
      if (lastActive && typeof lastActive.focus === "function") lastActive.focus();
    }, 120);
  };

  // Intercept submit for forms with data-confirm
  document.addEventListener("submit", (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute("data-confirm")) return;

    // Allow confirmed submission to pass once
    if (form.dataset.sdcConfirmPass === "1") {
      delete form.dataset.sdcConfirmPass;
      return;
    }

    e.preventDefault();
    open(form);
  }, true);

  // OK
  btnOk.addEventListener("click", () => {
    if (!pendingForm) return;

    btnOk.disabled = true;
    btnOk.textContent = "Sending...";
    btnOk.classList.add('opacity-70');

    const f = pendingForm;
    f.dataset.sdcConfirmPass = "1";

    // Small delay to show "Sending..." state before closing/submitting
    setTimeout(() => {
        close();
        setTimeout(() => {
            if (typeof f.requestSubmit === "function") f.requestSubmit();
            else f.submit();
        }, 80);
    }, 150);
  });

  // Cancel + backdrop close
  btnCancel.addEventListener("click", close);
  modal.addEventListener("click", (e) => {
    if (e.target && e.target.hasAttribute("data-sdc-confirm-close")) close();
  });

  // ESC close
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
  });
})();
</script>
