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

$groupId = (int)($_GET['id'] ?? 0);
if ($groupId <= 0) { header('Location: /admin/email/audience-groups.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM `email_audience_groups` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) { header('Location: /admin/email/audience-groups.php'); exit; }

// Flash messages
$createdFlash     = isset($_GET['created']);
$savedFlash       = isset($_GET['saved']);
$importedFlash    = isset($_GET['imported']);

// Filters
$search      = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSource = trim((string)($_GET['source'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;

// Build WHERE
$where   = ['group_id = ?'];
$types   = 'i';
$params  = [$groupId];

if ($search !== '') {
    $where[] = '(email LIKE ? OR name LIKE ? OR phone LIKE ?)';
    $types  .= 'sss';
    $s = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($filterStatus !== '') {
    $allowed = ['active', 'unsubscribed', 'bounced', 'invalid'];
    if (in_array($filterStatus, $allowed, true)) {
        $where[] = 'status = ?'; $types .= 's'; $params[] = $filterStatus;
    }
}
if ($filterSource !== '') {
    $allowedSrc = campaign_allowed_lead_source_tables();
    if (in_array($filterSource, $allowedSrc, true)) {
        $where[] = 'source_table = ?'; $types .= 's'; $params[] = $filterSource;
    } elseif ($filterSource === 'csv') {
        $where[] = 'source_table IS NULL';
    }
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $conn->prepare("SELECT COUNT(*) FROM `email_audience_group_members` {$whereSQL}");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalMembers = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalMembers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Members
$memberStmt = $conn->prepare(
    "SELECT id, email, name, phone, source_table, source_id, status, added_by, added_at
     FROM `email_audience_group_members` {$whereSQL}
     ORDER BY added_at DESC LIMIT ? OFFSET ?"
);
$allTypes  = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$memberStmt->bind_param($allTypes, ...$allParams);
$memberStmt->execute();
$members = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$memberStmt->close();

// Source tables for filter dropdown
$sourceTables = campaign_allowed_lead_source_tables();

$queryBase = http_build_query(array_filter(['id' => $groupId, 'q' => $search, 'status' => $filterStatus, 'source' => $filterSource]));

$title     = e((string)$group['group_name']) . ' — Audience Group';
$pageTitle = (string)$group['group_name'];
$pageDesc  = 'Audience group details and member list.';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8">

    <!-- Flash Messages -->
    <?php if ($createdFlash): ?>
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <p class="text-sm font-bold text-emerald-700">Group created successfully.</p>
        </div>
    <?php elseif ($savedFlash): ?>
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <p class="text-sm font-bold text-emerald-700">Group updated successfully.</p>
        </div>
    <?php elseif ($importedFlash): ?>
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <p class="text-sm font-bold text-emerald-700">Members imported successfully.</p>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="mb-8 flex flex-col md:flex-row md:items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="/admin/email/audience-groups.php" class="text-slate-400 hover:text-slate-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e((string)$group['group_name']) ?></h1>
            </div>
            <?php if ($group['description'] !== '' && $group['description'] !== null): ?>
                <p class="text-sm text-slate-500 font-semibold ml-8"><?= e((string)$group['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2 flex-wrap shrink-0">
            <a href="/admin/email/audience-group-import.php?group_id=<?= $groupId ?>"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Add CSV
            </a>
            <a href="/admin/email/audience-group-source-import.php?group_id=<?= $groupId ?>"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Add From Lead Source
            </a>
            <a href="/admin/email/audience-group-export.php?group_id=<?= $groupId ?>"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
            </a>
            <a href="/admin/email/campaign-import.php?mode=group&group_id=<?= $groupId ?>"
               class="inline-flex items-center gap-1.5 px-5 py-2 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm shadow">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Create Campaign
            </a>
        </div>
    </div>

    <!-- Group Info Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <?php foreach ([
            ['Total Members',   $group['total_members'],        'text-slate-900'],
            ['Active',          $group['active_members'],       'text-emerald-700'],
            ['Unsubscribed',    $group['unsubscribed_members'], 'text-amber-700'],
            ['Source Type',     ucwords(str_replace('_', ' ', (string)$group['source_type'])), 'text-slate-700'],
        ] as [$lbl, $val, $cls]): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2"><?= e($lbl) ?></div>
            <div class="text-2xl font-black <?= $cls ?>"><?= is_numeric($val) ? number_format((int)$val) : e((string)$val) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Edit Group link -->
    <div class="mb-6 flex items-center gap-2 text-xs">
        <a href="/admin/email/audience-group-form.php?id=<?= $groupId ?>"
           class="inline-flex items-center gap-1 text-slate-500 hover:text-yellow-600 font-black transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Edit Group Details
        </a>
        <span class="text-slate-300">·</span>
        <span class="text-slate-400">Created by <?= e((string)$group['created_by']) ?> on <?= e(date('d M Y', strtotime((string)$group['created_at']))) ?></span>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-6 flex flex-wrap gap-3 items-end">
        <input type="hidden" name="id" value="<?= $groupId ?>">
        <div class="flex-1 min-w-[200px]">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone…"
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
        </div>
        <div>
            <select name="status" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                <option value="">All Statuses</option>
                <?php foreach (['active' => 'Active', 'unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced', 'invalid' => 'Invalid'] as $v => $l): ?>
                    <option value="<?= e($v) ?>" <?= $filterStatus === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <select name="source" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                <option value="">All Sources</option>
                <option value="csv" <?= $filterSource === 'csv' ? 'selected' : '' ?>>CSV Import</option>
                <?php foreach ($sourceTables as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterSource === $t ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $t))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-black text-sm hover:bg-slate-700 transition">Filter</button>
        <?php if ($search !== '' || $filterStatus !== '' || $filterSource !== ''): ?>
            <a href="/admin/email/audience-group-detail.php?id=<?= $groupId ?>" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-600 font-bold text-sm hover:bg-slate-200 transition">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Members Table -->
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm mb-6">
        <?php if (empty($members)): ?>
            <div class="p-12 text-center">
                <p class="text-sm font-bold text-slate-400">No members found.</p>
                <div class="mt-4 flex items-center justify-center gap-3">
                    <a href="/admin/email/audience-group-import.php?group_id=<?= $groupId ?>"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-500 text-white font-black text-sm hover:bg-yellow-400 transition">
                        Import CSV
                    </a>
                    <a href="/admin/email/audience-group-source-import.php?group_id=<?= $groupId ?>"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold text-sm hover:bg-slate-50 transition">
                        Add From Lead Source
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="px-4 py-3 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <span class="text-xs font-black text-slate-500 uppercase tracking-widest">
                    <?= number_format($totalMembers) ?> member<?= $totalMembers !== 1 ? 's' : '' ?>
                    <?= ($search !== '' || $filterStatus !== '' || $filterSource !== '') ? '(filtered)' : '' ?>
                </span>
                <span class="text-xs font-bold text-slate-400">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Phone</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Source</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500 whitespace-nowrap">Added At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($members as $m): ?>
                            <?php
                            $statusColor = match ((string)$m['status']) {
                                'active'       => 'bg-emerald-100 text-emerald-800',
                                'unsubscribed' => 'bg-amber-100 text-amber-800',
                                'bounced'      => 'bg-rose-100 text-rose-800',
                                'invalid'      => 'bg-slate-100 text-slate-600',
                                default        => 'bg-slate-100 text-slate-500',
                            };
                            $sourceDisplay = $m['source_table'] !== null && $m['source_table'] !== ''
                                ? ucwords(str_replace('_', ' ', (string)$m['source_table']))
                                : 'CSV Import';
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3">
                                    <span class="text-sm font-bold text-slate-900"><?= e($m['name'] ?? '—') ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-slate-600 font-mono"><?= e((string)$m['email']) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-slate-500"><?= e($m['phone'] ?? '—') ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs font-bold text-slate-500"><?= e($sourceDisplay) ?></span>
                                    <?php if ($m['source_id'] !== null && $m['source_id'] !== ''): ?>
                                        <span class="text-[10px] font-mono text-slate-400 ml-1">#<?= e((string)$m['source_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-black <?= $statusColor ?>"><?= e(ucfirst((string)$m['status'])) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-slate-700 whitespace-nowrap"><?= e(date('d M Y', strtotime((string)$m['added_at']))) ?></div>
                                    <div class="text-xs text-slate-400"><?= e(date('H:i', strtotime((string)$m['added_at']))) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-4">
            <?php if ($page > 1): ?>
                <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => 1]))) ?>"
                   class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">First</a>
                <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
                   class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">← Prev</a>
            <?php endif; ?>
            <span class="px-4 py-2 text-sm font-black text-slate-600"><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
                   class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">Next →</a>
                <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $totalPages]))) ?>"
                   class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-900 font-bold hover:bg-slate-50 transition text-sm">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
