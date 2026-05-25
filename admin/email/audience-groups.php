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

// Summary stats
$statsRes = $conn->query("SELECT
    COUNT(*) AS total_groups,
    IFNULL(SUM(total_members),0) AS total_members,
    IFNULL(SUM(active_members),0) AS active_members,
    IFNULL(SUM(unsubscribed_members),0) AS unsubscribed_members
    FROM `email_audience_groups`");
$stats = $statsRes ? $statsRes->fetch_assoc() : [];

// Groups list
$groups = [];
$res = $conn->query("SELECT id, group_uid, group_name, description, source_type, total_members, active_members, unsubscribed_members, created_by, created_at, updated_at
    FROM `email_audience_groups` ORDER BY updated_at DESC LIMIT 200");
while ($row = $res->fetch_assoc()) $groups[] = $row;

$title = 'Audience Groups - Demo Admin';
$pageTitle = 'Audience Groups';
$pageDesc = 'Manage reusable contact groups for email campaigns.';
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
            <a href="/admin/email/audience-group-form.php"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm shadow">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                New Group
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <?php foreach ([
            ['Total Groups',      $stats['total_groups'] ?? 0,       'bg-slate-100 text-slate-600'],
            ['Total Members',     $stats['total_members'] ?? 0,      'bg-blue-100 text-blue-700'],
            ['Active Members',    $stats['active_members'] ?? 0,     'bg-emerald-100 text-emerald-700'],
            ['Unsubscribed',      $stats['unsubscribed_members'] ?? 0, 'bg-amber-100 text-amber-700'],
        ] as [$label, $val, $color]): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2"><?= e($label) ?></div>
            <div class="text-3xl font-black text-slate-900"><?= number_format((int)$val) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Groups Table -->
    <?php if (empty($groups)): ?>
        <div class="rounded-3xl border border-slate-200 bg-white p-16 text-center shadow-sm">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <h3 class="text-xl font-black text-slate-900 mb-2">No audience groups yet</h3>
            <p class="text-sm text-slate-500 font-semibold mb-6">Create your first group to start organising contacts.</p>
            <a href="/admin/email/audience-group-form.php"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                Create First Group
            </a>
        </div>
    <?php else: ?>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Group Name</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Source</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-black uppercase tracking-wider text-slate-500">Active</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Created By</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500 whitespace-nowrap">Updated</th>
                            <th class="px-4 py-3 text-left text-xs font-black uppercase tracking-wider text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($groups as $g): ?>
                            <?php
                            $sourceLabel = match ($g['source_type']) {
                                'csv_import'  => 'CSV Import',
                                'lead_table'  => 'Lead Table',
                                'mixed'       => 'Mixed',
                                default       => 'Manual',
                            };
                            $sourceColor = match ($g['source_type']) {
                                'csv_import' => 'bg-blue-50 text-blue-700',
                                'lead_table' => 'bg-purple-50 text-purple-700',
                                'mixed'      => 'bg-amber-50 text-amber-700',
                                default      => 'bg-slate-100 text-slate-600',
                            };
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-black text-sm text-slate-900"><?= e($g['group_name']) ?></div>
                                    <?php if ($g['description'] !== ''): ?>
                                        <div class="text-xs text-slate-400 truncate max-w-[220px]" title="<?= e((string)$g['description']) ?>"><?= e((string)$g['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-black <?= $sourceColor ?>"><?= e($sourceLabel) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-slate-900"><?= number_format((int)$g['total_members']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-sm font-bold text-emerald-700"><?= number_format((int)$g['active_members']) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs text-slate-500 font-semibold"><?= e((string)$g['created_by']) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($g['updated_at']): ?>
                                        <div class="text-sm text-slate-700 whitespace-nowrap"><?= e(date('d M Y', strtotime((string)$g['updated_at']))) ?></div>
                                        <div class="text-xs text-slate-400"><?= e(date('H:i', strtotime((string)$g['updated_at']))) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin/email/audience-group-detail.php?id=<?= (int)$g['id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-yellow-500 text-white font-black text-xs hover:bg-yellow-400 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            View
                                        </a>
                                        <a href="/admin/email/audience-group-import.php?group_id=<?= (int)$g['id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-600 font-black text-xs hover:bg-slate-50 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                            Add CSV
                                        </a>
                                        <a href="/admin/email/campaign-import.php?mode=group&group_id=<?= (int)$g['id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 font-black text-xs hover:bg-emerald-100 transition whitespace-nowrap">
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                            Campaign
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
