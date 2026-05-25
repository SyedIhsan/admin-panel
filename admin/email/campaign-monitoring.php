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

// Environment detection — IS_LOCALHOST is set by admin/bootstrap.php (via auth.php).
// Local: show ONLY test campaigns. Production: hide test campaigns.
$isLocalEnv = defined('IS_LOCALHOST') ? IS_LOCALHOST : (
    str_contains(strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')), 'localhost')
    || str_contains(strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')), '127.0.0.1')
    || in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
);

// SQL fragment applied to every email_campaigns query on this page.
// DEMO_MODE overrides the env split so all campaigns are always visible.
$envCampaignFilter = (defined('DEMO_MODE') && DEMO_MODE)
    ? '1=1'
    : ($isLocalEnv
        ? "LOWER(COALESCE(campaign_name,'')) LIKE '%test%'"
        : "LOWER(COALESCE(campaign_name,'')) NOT LIKE '%test%'");

// Get filter parameters
$searchQ = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? 'all'));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// ===== SUMMARY CARDS DATA =====
$summaryData = [
    'total_campaigns' => 0,
    'total_recipients' => 0,
    'avg_open_rate' => 0.00,
    'avg_click_rate' => 0.00,
    'failed_emails' => 0,
];

$campaignRows = [];
$totalCampaigns = 0;

// Defensive schema check - verify all required columns exist
$requiredCols = ['total_recipients', 'sent_count', 'failed_count', 'open_rate', 'click_rate', 'created_at', 'status'];
$missingCols = [];

if (!$schemaError) {
    foreach ($requiredCols as $col) {
        if (!campaign_column_exists($campaignConn, 'email_campaigns', $col)) {
            $missingCols[] = $col;
            $schemaError = true;
        }
    }
    
    if (!empty($missingCols)) {
        error_log("campaign-monitoring.php: Missing required columns in email_campaigns: " . implode(', ', $missingCols));
    }
}

if (!$schemaError) {
    try {
        // Fetch summary data
        $summaryStmt = $campaignConn->prepare("
            SELECT
                COUNT(*) as total_campaigns,
                COALESCE(SUM(total_recipients), 0) as total_recipients,
                COALESCE(AVG(open_rate), 0) as avg_open_rate,
                COALESCE(AVG(click_rate), 0) as avg_click_rate,
                COALESCE(SUM(failed_count), 0) as failed_emails
            FROM `email_campaigns`
            WHERE status != 'draft' AND {$envCampaignFilter}
        ");

        if ($summaryStmt) {
            $summaryStmt->execute();
            $summaryRes = $summaryStmt->get_result();
            if ($summaryRes) {
                $row = $summaryRes->fetch_assoc();
                if ($row) {
                    $summaryData['total_campaigns'] = (int)($row['total_campaigns'] ?? 0);
                    $summaryData['total_recipients'] = (int)($row['total_recipients'] ?? 0);
                    $summaryData['avg_open_rate'] = (float)($row['avg_open_rate'] ?? 0);
                    $summaryData['avg_click_rate'] = (float)($row['avg_click_rate'] ?? 0);
                    $summaryData['failed_emails'] = (int)($row['failed_emails'] ?? 0);
                }
            }
            $summaryStmt->close();
        }

        // Build campaign query with filters
        $where = "WHERE 1=1 AND {$envCampaignFilter}";
        $params = [];
        $types = '';

        if ($searchQ !== '') {
            $where .= " AND (campaign_name LIKE ? OR subject LIKE ?)";
            $likeQ = '%' . $searchQ . '%';
            $params[] = $likeQ;
            $params[] = $likeQ;
            $types .= 'ss';
        }

        if ($filterStatus !== 'all' && $filterStatus !== '') {
            $where .= " AND status = ?";
            $params[] = $filterStatus;
            $types .= 's';
        }

        if ($dateFrom !== '') {
            $where .= " AND created_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }

        if ($dateTo !== '') {
            $where .= " AND created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM `email_campaigns` {$where}";
        $countStmt = $campaignConn->prepare($countSql);

        if ($countStmt && !empty($params)) {
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $countRes = $countStmt->get_result();
            if ($countRes) {
                $countRow = $countRes->fetch_assoc();
                $totalCampaigns = (int)($countRow['total'] ?? 0);
            }
            $countStmt->close();
        } elseif ($countStmt) {
            $countStmt->execute();
            $countRes = $countStmt->get_result();
            if ($countRes) {
                $countRow = $countRes->fetch_assoc();
                $totalCampaigns = (int)($countRow['total'] ?? 0);
            }
            $countStmt->close();
        }

        // Get paginated campaigns
        $offset = ($currentPage - 1) * $perPage;
        $campaignSql = "
            SELECT
                id,
                campaign_uid,
                campaign_name,
                subject,
                status,
                COALESCE(queue_status, 'none') AS queue_status,
                total_recipients,
                sent_count,
                failed_count,
                delivered_count,
                opened_count,
                clicked_count,
                open_rate,
                click_rate,
                created_at,
                sent_at,
                created_by
            FROM `email_campaigns`
            {$where}
            ORDER BY created_at DESC, id DESC
            LIMIT ? OFFSET ?
        ";

        $campaignStmt = $campaignConn->prepare($campaignSql);

        if ($campaignStmt) {
            $params[] = $perPage;
            $params[] = $offset;
            $types .= 'ii';

            if (!empty($params)) {
                $campaignStmt->bind_param($types, ...$params);
            }
            $campaignStmt->execute();
            $campaignRes = $campaignStmt->get_result();

            if ($campaignRes) {
                while ($campaignRow = $campaignRes->fetch_assoc()) {
                    $campaignRows[] = $campaignRow;
                }
            }
            $campaignStmt->close();
        }

    } catch (Exception $e) {
        error_log("campaign-monitoring.php error: " . $e->getMessage());
        $schemaError = true;
    }
}

// Load next scheduled send for each displayed campaign (one query)
$nextScheduleMap = [];
if (!empty($campaignRows) && campaign_table_exists($campaignConn, 'email_campaign_schedules')) {
    $campIds = implode(',', array_map('intval', array_column($campaignRows, 'id')));
    $nsRes = $campaignConn->query(
        "SELECT campaign_id, MIN(scheduled_at) AS next_at
         FROM `email_campaign_schedules`
         WHERE campaign_id IN ({$campIds}) AND status='scheduled' AND scheduled_at >= NOW()
         GROUP BY campaign_id"
    );
    if ($nsRes) {
        while ($nsRow = $nsRes->fetch_assoc()) {
            $nextScheduleMap[(int)$nsRow['campaign_id']] = (string)$nsRow['next_at'];
        }
    }
}

$totalPages = ceil($totalCampaigns / $perPage);
if ($totalPages < 1) {
    $totalPages = 1;
}

// Page metadata
$title     = 'Campaign Monitoring - Demo Admin';
$pageTitle = 'Campaign Monitoring';
$pageDesc  = 'View email blast performance, delivery status, opens, clicks, and recipient activity.';

$headerActionsHtmlDesktop = '
  <a href="/admin/email/audience-groups.php"
     class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition shadow-sm text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    Audience Groups
  </a>
  <a href="/admin/email/campaign-schedules.php"
     class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition shadow-sm text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    Schedules
  </a>
  <a href="/admin/email/campaign-import.php"
     class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-yellow-500 text-white font-bold hover:bg-yellow-400 transition shadow-md shadow-yellow-100 text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Import Recipients
  </a>
';

$headerActionsHtmlMobile = '
  <a href="/admin/email/audience-groups.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition shadow-sm"
     aria-label="Audience Groups" title="Audience Groups">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
  </a>
  <a href="/admin/email/campaign-schedules.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition shadow-sm"
     aria-label="Schedules" title="Schedules">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </a>
  <a href="/admin/email/campaign-import.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white hover:bg-yellow-400 transition shadow-md shadow-yellow-100"
     aria-label="Import Recipients" title="Import Recipients">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
  </a>
';

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="md:hidden mb-8 px-4 pt-6">
    <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
    <?php if ($pageDesc !== ''): ?>
        <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
    <?php endif; ?>
</div>

<div class="mx-auto px-4 py-8">

    <!-- Schema error alert -->
    <?php if ($schemaError): ?>
        <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 px-6 py-4">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 pt-0.5">
                    <svg class="w-6 h-6 text-rose-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-rose-900">Schema Verification Failed</h2>
                    <p class="mt-1 text-sm text-rose-800">
                        <?php if (!empty($missingCols)): ?>
                            Campaign monitoring schema is incomplete. Missing columns: <code class="font-mono bg-rose-100 px-2 py-1 rounded text-xs"><?= htmlspecialchars(implode(', ', $missingCols), ENT_QUOTES, 'UTF-8') ?></code>
                        <?php else: ?>
                            Campaign monitoring schema could not be verified. Please check server logs.
                        <?php endif; ?>
                    </p>
                    <a href="/admin/email/campaign-schema-test.php" class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-rose-600 text-white font-bold hover:bg-rose-700 transition text-sm">
                        Run Schema Test
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
        <!-- Total Campaigns -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-bold uppercase tracking-wide text-slate-500 mb-2">Total Campaigns</div>
            <div class="text-4xl font-black text-slate-900"><?= $summaryData['total_campaigns'] ?></div>
            <div class="text-xs text-slate-400 mt-2"><?= $summaryData['total_campaigns'] === 1 ? 'campaign' : 'campaigns' ?></div>
        </div>

        <!-- Total Recipients -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-bold uppercase tracking-wide text-slate-500 mb-2">Total Recipients</div>
            <div class="text-4xl font-black text-slate-900"><?= number_format($summaryData['total_recipients']) ?></div>
            <div class="text-xs text-slate-400 mt-2">sent emails</div>
        </div>

        <!-- Avg Open Rate -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-bold uppercase tracking-wide text-slate-500 mb-2">Avg Open Rate</div>
            <div class="text-4xl font-black text-emerald-600"><?= number_format($summaryData['avg_open_rate'], 2) ?>%</div>
            <div class="text-xs text-slate-400 mt-2">across campaigns</div>
        </div>

        <!-- Avg Click Rate -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-bold uppercase tracking-wide text-slate-500 mb-2">Avg Click Rate</div>
            <div class="text-4xl font-black text-blue-600"><?= number_format($summaryData['avg_click_rate'], 2) ?>%</div>
            <div class="text-xs text-slate-400 mt-2">across campaigns</div>
        </div>

        <!-- Failed Emails -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-bold uppercase tracking-wide text-slate-500 mb-2">Failed Emails</div>
            <div class="text-4xl font-black text-rose-600"><?= number_format($summaryData['failed_emails']) ?></div>
            <div class="text-xs text-slate-400 mt-2">to retry</div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm mb-8">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Search -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-600 mb-2">Search</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= e($searchQ) ?>"
                        placeholder="Campaign name or subject..."
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100"
                    />
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-600 mb-2">Status</label>
                    <select
                        name="status"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 outline-none transition focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100"
                    >
                        <option value="all">All Statuses</option>
                        <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="scheduled" <?= $filterStatus === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="queued" <?= $filterStatus === 'queued' ? 'selected' : '' ?>>Queued</option>
                        <option value="sending" <?= $filterStatus === 'sending' ? 'selected' : '' ?>>Sending</option>
                        <option value="sent" <?= $filterStatus === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="partial_failed" <?= $filterStatus === 'partial_failed' ? 'selected' : '' ?>>Partial Failed</option>
                        <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <!-- Date From -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-600 mb-2">From</label>
                    <input
                        type="date"
                        name="date_from"
                        value="<?= e($dateFrom) ?>"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 outline-none transition focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100"
                    />
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-600 mb-2">To</label>
                    <input
                        type="date"
                        name="date_to"
                        value="<?= e($dateTo) ?>"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 outline-none transition focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100"
                    />
                </div>

                <!-- Submit -->
                <div class="flex items-end">
                    <button
                        type="submit"
                        class="w-full rounded-xl bg-yellow-500 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-yellow-400 transition"
                    >
                        Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Campaigns Table or Empty State -->
    <?php if (empty($campaignRows) && $totalCampaigns === 0): ?>
        <!-- Empty State -->
        <div class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
            <div class="mb-6">
                <svg class="w-16 h-16 mx-auto text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
            </div>
            <h3 class="text-xl font-black text-slate-900 mb-2">No campaigns found yet</h3>
            <p class="text-sm text-slate-600 mb-6">
                Future email blasts will appear here after campaign tracking is connected.
            </p>
            <div class="flex gap-3 justify-center">
                <a href="/admin/email/email-logs.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-900 font-bold hover:bg-slate-200 transition text-sm">
                    View Email Logs
                </a>
                <a href="/admin/email/campaign-schema-test.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-500 text-white font-bold hover:bg-yellow-400 transition text-sm">
                    Run Schema Test
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Campaigns Table -->
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Campaign</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Subject</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Recip.</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Sent</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Failed</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Open%</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Click%</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500 whitespace-nowrap">Date Sent</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($campaignRows as $campaign): ?>
                            <?php
                                $campaignId       = (int)($campaign['id'] ?? 0);
                                $campaignName     = (string)($campaign['campaign_name'] ?? 'Untitled');
                                $campaignUid      = (string)($campaign['campaign_uid'] ?? '');
                                $subject          = (string)($campaign['subject'] ?? '');
                                $status           = (string)($campaign['status'] ?? 'draft');
                                $queueStatus      = (string)($campaign['queue_status'] ?? 'none');
                                $totalRecipients  = (int)($campaign['total_recipients'] ?? 0);
                                $sentCount        = (int)($campaign['sent_count'] ?? 0);
                                $failedCount      = (int)($campaign['failed_count'] ?? 0);
                                $openRate         = (float)($campaign['open_rate'] ?? 0);
                                $clickRate        = (float)($campaign['click_rate'] ?? 0);
                                $createdAt        = (string)($campaign['created_at'] ?? '');
                                $sentAt           = (string)($campaign['sent_at'] ?? '');
                                $displayDate      = $sentAt !== '' && $sentAt !== '0000-00-00 00:00:00' ? $sentAt : $createdAt;

                                $statusLabel = match ($status) {
                                    'sent'           => 'Sent',
                                    'draft'          => 'Draft',
                                    'sending'        => 'Sending',
                                    'partial_failed' => 'Partial Failed',
                                    'failed'         => 'Failed',
                                    'queued'         => 'Queued',
                                    'scheduled'      => 'Scheduled',
                                    'cancelled'      => 'Cancelled',
                                    default          => ucwords(str_replace('_', ' ', $status)),
                                };

                                $statusColor = match ($status) {
                                    'sent'              => 'bg-emerald-100 text-emerald-800',
                                    'partial_failed'    => 'bg-amber-100 text-amber-800',
                                    'failed'            => 'bg-rose-100 text-rose-800',
                                    'sending', 'queued' => 'bg-blue-100 text-blue-800',
                                    'scheduled'         => 'bg-amber-100 text-amber-800',
                                    default             => 'bg-slate-100 text-slate-700',
                                };

                                $queueLabel = match ($queueStatus) {
                                    'queued'     => 'Queue: Queued',
                                    'processing' => 'Queue: Running',
                                    'completed'  => 'Queue: Done',
                                    'paused'     => 'Queue: Paused',
                                    'error'      => 'Queue: Error',
                                    'cancelled'  => 'Queue: Cancelled',
                                    default      => '',
                                };

                                $queueStatusColor = match ($queueStatus) {
                                    'queued'     => 'bg-blue-50 text-blue-700 border border-blue-100',
                                    'processing' => 'bg-indigo-50 text-indigo-700 border border-indigo-100',
                                    'completed'  => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
                                    'paused'     => 'bg-amber-50 text-amber-700 border border-amber-100',
                                    'error'      => 'bg-rose-50 text-rose-700 border border-rose-100',
                                    'cancelled'  => 'bg-slate-50 text-slate-500 border border-slate-100',
                                    default      => '',
                                };
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <!-- Campaign name + UID -->
                                <td class="px-4 py-3">
                                    <div class="font-black text-sm text-slate-900 leading-snug"><?= e($campaignName) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono mt-0.5"><?= e($campaignUid) ?></div>
                                </td>
                                <!-- Subject (truncated) -->
                                <td class="px-4 py-3 max-w-[180px]">
                                    <div class="text-sm text-slate-600 truncate" title="<?= e($subject) ?>">
                                        <?= e($subject !== '' ? $subject : '—') ?>
                                    </div>
                                </td>
                                <!-- Status + queue badge -->
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $statusColor ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                    <?php if ($queueStatus !== 'none' && $queueLabel !== ''): ?>
                                        <div class="mt-1">
                                            <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-black <?= $queueStatusColor ?>">
                                                <?= e($queueLabel) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (isset($nextScheduleMap[$campaignId])): ?>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-black bg-blue-100 text-blue-700" title="Next schedule: <?= e(date('d M Y H:i', strtotime($nextScheduleMap[$campaignId]))) ?>">
                                                <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <?= e(date('d M H:i', strtotime($nextScheduleMap[$campaignId]))) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <!-- Recipients -->
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-slate-900"><?= number_format($totalRecipients) ?></span>
                                </td>
                                <!-- Sent -->
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-emerald-700"><?= number_format($sentCount) ?></span>
                                </td>
                                <!-- Failed (clickable → retry) -->
                                <td class="px-4 py-3 text-right">
                                    <?php if ($failedCount > 0): ?>
                                        <a href="/admin/email/campaign-details.php?id=<?= $campaignId ?>#send-campaign"
                                           class="text-sm font-bold text-rose-600 hover:text-rose-800 transition"
                                           title="<?= number_format($failedCount) ?> failed — click to retry">
                                            <?= number_format($failedCount) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400">0</span>
                                    <?php endif; ?>
                                </td>
                                <!-- Open Rate -->
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-slate-900"><?= number_format($openRate, 1) ?>%</span>
                                </td>
                                <!-- Click Rate -->
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-slate-900"><?= number_format($clickRate, 1) ?>%</span>
                                </td>
                                <!-- Date Sent — two-line: date / time -->
                                <td class="px-4 py-3">
                                    <?php if ($displayDate !== '' && $displayDate !== '0000-00-00 00:00:00'): ?>
                                        <div class="text-sm text-slate-700 whitespace-nowrap"><?= e(date('d M Y', strtotime($displayDate))) ?></div>
                                        <div class="text-xs text-slate-400"><?= e(date('H:i', strtotime($displayDate))) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <!-- Actions -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin/email/campaign-details.php?id=<?= $campaignId ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-yellow-500 text-white font-black text-xs hover:bg-yellow-400 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            View
                                        </a>
                                        <a href="/admin/email/campaign-export.php?campaign_id=<?= $campaignId ?>&segment=all"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-600 font-black text-xs hover:bg-slate-50 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Export
                                        </a>
                                        <a href="/admin/email/campaign-targeting.php?source_campaign_id=<?= $campaignId ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-600 font-black text-xs hover:bg-slate-50 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            Target
                                        </a>
                                        <?php if (in_array($queueStatus, ['queued', 'processing', 'paused'], true)): ?>
                                            <a href="/admin/email/campaign-details.php?id=<?= $campaignId ?>#queue-sending"
                                               class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 font-black text-xs hover:bg-blue-100 transition whitespace-nowrap">
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                Queue
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-8 flex items-center justify-center gap-2">
                <?php if ($currentPage > 1): ?>
                    <a href="?page=1&q=<?= urlencode($searchQ) ?>&status=<?= urlencode($filterStatus) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">
                        First
                    </a>
                    <a href="?page=<?= $currentPage - 1 ?>&q=<?= urlencode($searchQ) ?>&status=<?= urlencode($filterStatus) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">
                        ← Previous
                    </a>
                <?php endif; ?>

                <div class="text-sm font-bold text-slate-600">
                    Page <?= $currentPage ?> of <?= $totalPages ?>
                </div>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?= $currentPage + 1 ?>&q=<?= urlencode($searchQ) ?>&status=<?= urlencode($filterStatus) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">
                        Next →
                    </a>
                    <a href="?page=<?= $totalPages ?>&q=<?= urlencode($searchQ) ?>&status=<?= urlencode($filterStatus) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">
                        Last
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
