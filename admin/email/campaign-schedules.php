<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$conn instanceof mysqli) { http_response_code(500); exit('Database connection unavailable.'); }
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
campaign_ensure_schema($conn);

if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_validate();
    $cancelId = (int)($_POST['schedule_id'] ?? 0);
    if ($cancelId > 0) {
        $conn->query("UPDATE `email_campaign_schedules`
            SET status='cancelled', cancelled_at=NOW(), updated_at=NOW()
            WHERE id={$cancelId} AND status='scheduled'");
    }
    header('Location: /admin/email/campaign-schedules.php?cancelled=1');
    exit;
}

// Filters
$filterStatus   = trim((string)($_GET['status'] ?? ''));
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo   = trim((string)($_GET['date_to'] ?? ''));
$search         = trim((string)($_GET['q'] ?? ''));
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;

$allowedStatuses = ['scheduled', 'queued', 'processing', 'completed', 'cancelled', 'missed', 'error'];

$where  = ['1=1'];
$types  = '';
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
    $where[] = 's.status = ?'; $types .= 's'; $params[] = $filterStatus;
}
if ($filterDateFrom !== '') {
    $where[] = 'DATE(s.scheduled_at) >= ?'; $types .= 's'; $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[] = 'DATE(s.scheduled_at) <= ?'; $types .= 's'; $params[] = $filterDateTo;
}
if ($search !== '') {
    $where[] = '(c.campaign_name LIKE ? OR s.schedule_name LIKE ?)';
    $types  .= 'ss';
    $s = '%' . $search . '%';
    $params[] = $s; $params[] = $s;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
if ($types !== '') {
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM `email_campaign_schedules` s INNER JOIN `email_campaigns` c ON c.id=s.campaign_id {$whereSQL}");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_row()[0];
    $countStmt->close();
} else {
    $r = $conn->query("SELECT COUNT(*) FROM `email_campaign_schedules` s INNER JOIN `email_campaigns` c ON c.id=s.campaign_id {$whereSQL}");
    $total = $r ? (int)$r->fetch_row()[0] : 0;
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch rows
$baseSQL = "SELECT s.id, s.schedule_uid, s.schedule_name, s.scheduled_at, s.status, s.send_mode,
            s.batch_size, s.max_per_run, s.queue_job_id, s.created_by, s.created_at,
            s.queued_at, s.completed_at, s.cancelled_at, s.error_message,
            c.id AS campaign_id, c.campaign_name, c.status AS campaign_status
            FROM `email_campaign_schedules` s
            INNER JOIN `email_campaigns` c ON c.id = s.campaign_id
            {$whereSQL}
            ORDER BY s.scheduled_at DESC LIMIT ? OFFSET ?";

$schedules = [];
if ($types !== '') {
    $stmt = $conn->prepare($baseSQL);
    $allT = $types . 'ii';
    $allP = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($allT, ...$allP);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $stmt = $conn->prepare($baseSQL);
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Summary stats
$statsRes = $conn->query("SELECT status, COUNT(*) as cnt FROM `email_campaign_schedules` GROUP BY status");
$statsByStatus = [];
while ($sr = $statsRes->fetch_assoc()) $statsByStatus[(string)$sr['status']] = (int)$sr['cnt'];

$title     = 'Campaign Schedules - Demo Admin';
$pageTitle = 'Campaign Schedules';
$pageDesc  = 'View and manage all scheduled campaign sends.';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8">

    <!-- Header -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
            <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/admin/email/campaign-monitoring.php"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Monitoring
            </a>
            <a href="/admin/email/campaign-schedule-form.php"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm shadow">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                New Schedule
            </a>
        </div>
    </div>

    <?php if (isset($_GET['cancelled'])): ?>
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-700">Schedule cancelled successfully.</div>
    <?php endif; ?>

    <!-- Status Summary -->
    <div class="grid grid-cols-3 md:grid-cols-7 gap-3 mb-8">
        <?php foreach ([
            'scheduled' => ['Scheduled', 'bg-blue-50 text-blue-700'],
            'queued'    => ['Queued',    'bg-indigo-50 text-indigo-700'],
            'processing'=> ['Running',   'bg-purple-50 text-purple-700'],
            'completed' => ['Completed', 'bg-emerald-50 text-emerald-700'],
            'cancelled' => ['Cancelled', 'bg-slate-100 text-slate-500'],
            'missed'    => ['Missed',    'bg-amber-50 text-amber-700'],
            'error'     => ['Error',     'bg-rose-50 text-rose-700'],
        ] as $st => [$lbl, $cls]): ?>
        <a href="?status=<?= e($st) ?>" class="rounded-xl border border-slate-200 bg-white p-3 text-center hover:border-yellow-300 transition <?= $filterStatus === $st ? 'ring-2 ring-yellow-400' : '' ?>">
            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1"><?= e($lbl) ?></div>
            <div class="text-xl font-black <?= $cls ?>"><?= number_format($statsByStatus[$st] ?? 0) ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-6 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search campaign or schedule name…"
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
        </div>
        <div>
            <select name="status" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                <option value="">All Statuses</option>
                <?php foreach ($allowedStatuses as $st): ?>
                    <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>"
                   class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
            <span class="text-slate-400 font-bold text-sm">to</span>
            <input type="date" name="date_to" value="<?= e($filterDateTo) ?>"
                   class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
        </div>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-black text-sm hover:bg-slate-700 transition">Filter</button>
        <?php if ($search !== '' || $filterStatus !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
            <a href="/admin/email/campaign-schedules.php" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-600 font-bold text-sm hover:bg-slate-200 transition">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <?php if (empty($schedules)): ?>
        <div class="rounded-3xl border border-slate-200 bg-white p-16 text-center shadow-sm">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="text-xl font-black text-slate-900 mb-2">No schedules found</h3>
            <p class="text-sm text-slate-500 font-semibold mb-6">Create a schedule to automatically send campaigns on specific dates.</p>
            <a href="/admin/email/campaign-schedule-form.php"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow">
                Create Schedule
            </a>
        </div>
    <?php else: ?>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm mb-6">
            <div class="px-4 py-3 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= number_format($total) ?> schedule<?= $total !== 1 ? 's' : '' ?></span>
                <span class="text-xs font-bold text-slate-400">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Schedule / Campaign</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500 whitespace-nowrap">Scheduled At</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Send Mode</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Batch</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Queue Job</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($schedules as $s): ?>
                            <?php
                            $st = (string)$s['status'];
                            $stColor = match ($st) {
                                'scheduled'  => 'bg-blue-100 text-blue-800',
                                'queued'     => 'bg-indigo-100 text-indigo-800',
                                'processing' => 'bg-purple-100 text-purple-800',
                                'completed'  => 'bg-emerald-100 text-emerald-800',
                                'cancelled'  => 'bg-slate-100 text-slate-500',
                                'missed'     => 'bg-amber-100 text-amber-800',
                                'error'      => 'bg-rose-100 text-rose-800',
                                default      => 'bg-slate-100 text-slate-700',
                            };
                            $modeLabel = match ((string)$s['send_mode']) {
                                'failed'             => 'Failed',
                                'pending_and_failed' => 'All',
                                default              => 'Pending',
                            };
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-black text-sm text-slate-900">
                                        <?= e($s['schedule_name'] !== '' && $s['schedule_name'] !== null ? (string)$s['schedule_name'] : 'Unnamed Schedule') ?>
                                    </div>
                                    <div class="text-xs text-slate-400 font-semibold mt-0.5">
                                        <a href="/admin/email/campaign-details.php?id=<?= (int)$s['campaign_id'] ?>" class="hover:text-yellow-600 transition">
                                            <?= e((string)$s['campaign_name']) ?>
                                        </a>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-bold text-slate-900 whitespace-nowrap"><?= e(date('d M Y', strtotime((string)$s['scheduled_at']))) ?></div>
                                    <div class="text-xs text-slate-400"><?= e(date('H:i', strtotime((string)$s['scheduled_at']))) ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-black <?= $stColor ?>"><?= e(ucfirst($st)) ?></span>
                                    <?php if ($s['error_message'] !== '' && $s['error_message'] !== null && in_array($st, ['error', 'completed'], true)): ?>
                                        <div class="text-[10px] text-slate-400 mt-1 max-w-[160px] truncate" title="<?= e((string)$s['error_message']) ?>">
                                            <?= e(substr((string)$s['error_message'], 0, 60)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs font-bold text-slate-600"><?= e($modeLabel) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-xs font-bold text-slate-600"><?= (int)$s['batch_size'] ?></span>
                                    <span class="text-[10px] text-slate-400">/ <?= (int)$s['max_per_run'] ?> max</span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($s['queue_job_id']): ?>
                                        <span class="text-xs font-mono text-slate-500">#<?= (int)$s['queue_job_id'] ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin/email/campaign-details.php?id=<?= (int)$s['campaign_id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-yellow-500 text-white font-black text-xs hover:bg-yellow-400 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            Campaign
                                        </a>
                                        <?php if ($st === 'scheduled'): ?>
                                            <a href="/admin/email/campaign-schedule-form.php?id=<?= (int)$s['id'] ?>"
                                               class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-600 font-black text-xs hover:bg-slate-50 transition whitespace-nowrap">
                                                Edit
                                            </a>
                                            <button type="button"
                                                    onclick="sdcConfirm('Cancel this schedule?','This schedule will not be queued. The campaign itself is unaffected.', 'Cancel Schedule', 'Keep it', function(){ document.getElementById('cancel-form-<?= (int)$s['id'] ?>').submit(); });"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-600 font-black text-xs hover:bg-rose-100 transition whitespace-nowrap">
                                                Cancel
                                            </button>
                                            <form id="cancel-form-<?= (int)$s['id'] ?>" method="POST" class="hidden">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                            </form>
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
            <div class="flex items-center justify-center gap-2">
                <?php if ($page > 1): ?>
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => 1]))) ?>" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">First</a>
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">← Prev</a>
                <?php endif; ?>
                <span class="px-4 py-2 text-sm font-black text-slate-600"><?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">Next →</a>
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $totalPages]))) ?>" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">Last</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Confirm Modal -->
<div id="sdcConfirmModal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="sdcConfirmClose()"></div>
    <div class="relative w-full max-w-sm rounded-3xl bg-white border border-slate-100 shadow-2xl p-6 transform transition-all">
        <h3 id="sdcConfirmTitle" class="text-lg font-black text-slate-900 mb-2"></h3>
        <p id="sdcConfirmDesc" class="text-sm font-semibold text-slate-500 mb-6"></p>
        <div class="flex gap-3">
            <button id="sdcConfirmOk" class="flex-1 py-3 rounded-xl bg-rose-600 text-white font-black hover:bg-rose-500 transition text-sm"></button>
            <button id="sdcConfirmCancel" onclick="sdcConfirmClose()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-700 font-black hover:bg-slate-200 transition text-sm"></button>
        </div>
    </div>
</div>

<script>
let _sdcCb = null;
function sdcConfirm(title, desc, okLabel, cancelLabel, cb) {
    document.getElementById('sdcConfirmTitle').textContent = title;
    document.getElementById('sdcConfirmDesc').textContent  = desc;
    document.getElementById('sdcConfirmOk').textContent    = okLabel;
    document.getElementById('sdcConfirmCancel').textContent = cancelLabel;
    _sdcCb = cb;
    const m = document.getElementById('sdcConfirmModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
    document.getElementById('sdcConfirmOk').onclick = function() { sdcConfirmClose(); if (_sdcCb) _sdcCb(); };
}
function sdcConfirmClose() {
    const m = document.getElementById('sdcConfirmModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
