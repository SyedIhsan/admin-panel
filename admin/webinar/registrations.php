<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

/** @var mysqli $conn */

$webinar_id = (int)($_GET['webinar_id'] ?? 0);
if ($webinar_id <= 0) {
    header("Location: /admin/webinar/index.php");
    exit;
}

// Verify webinar exists
$stmt = $conn->prepare("SELECT webinar_title, start_datetime FROM sdc_webinars WHERE id = ?");
$stmt->bind_param("i", $webinar_id);
$stmt->execute();
$webinar = $stmt->get_result()->fetch_assoc();
if (!$webinar) {
    header("Location: /admin/webinar/index.php");
    exit;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $conn->prepare("
        SELECT name, email, phone, consent, registered_at
        FROM sdc_webinar_registrations
        WHERE webinar_id = ?
        ORDER BY registered_at DESC
    ");
    $stmt->bind_param("i", $webinar_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $safeTitle = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $webinar['webinar_title']);
    $safeTitle = trim(str_replace(' ', '_', $safeTitle));
    $filename = $safeTitle . "_participants_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM for Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Phone', 'Consent', 'Registered At'], ',', '"', "\\");

    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['name'],
            $r['email'],
            $r['phone'],
            ((int)$r['consent'] === 1 ? 'Yes' : 'No'),
            $r['registered_at']
        ], ',', '"', "\\");
    }
    fclose($out);
    exit;
}

// ── Search & Data Fetching ────────────────────────────────────────────────────
$q = trim((string)($_GET['q'] ?? ''));
$searchSql = "";
if ($q !== "") {
    $safeQ = "%" . $q . "%";
    $searchSql = " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
}

$stmt = $conn->prepare("
    SELECT * FROM sdc_webinar_registrations
    WHERE webinar_id = ? $searchSql
    ORDER BY registered_at DESC
");

if ($q !== "") {
    $stmt->bind_param("isss", $webinar_id, $safeQ, $safeQ, $safeQ);
} else {
    $stmt->bind_param("i", $webinar_id);
}

$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$stats = [
    'total' => count($registrations),
    'latest' => $registrations[0]['registered_at'] ?? null,
    'unique_emails' => count(array_unique(array_column($registrations, 'email'))),
    'consent' => count(array_filter($registrations, fn($r) => (int)$r['consent'] === 1))
];

$pageTitle = "Participants";
$pageDesc = $webinar['webinar_title'];

include __DIR__ . "/../partials/header.php";
include __DIR__ . "/../partials/nav.php";
?>

<div class="space-y-8">
    <!-- Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <a href="/admin/webinar/index.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Management
            </a>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight"><?= h($webinar['webinar_title']) ?></h1>
            <p class="text-sm font-bold text-slate-400 mt-1"><?= fmtDate($webinar['start_datetime']) ?></p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?webinar_id=<?= $webinar_id ?>&export=csv" 
               class="inline-flex items-center gap-2 bg-white border border-slate-200 px-4 py-2.5 rounded-2xl font-bold text-slate-600 hover:bg-slate-50 transition shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export CSV
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Total Registrations</p>
            <p class="text-2xl font-black text-slate-900"><?= number_format((float)$stats['total']) ?></p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Unique Emails</p>
            <p class="text-2xl font-black text-indigo-600"><?= number_format((float)$stats['unique_emails']) ?></p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Consent Given</p>
            <p class="text-2xl font-black text-emerald-600"><?= number_format((float)$stats['consent']) ?></p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Latest Entry</p>
            <p class="text-sm font-bold text-slate-600 mt-2"><?= $stats['latest'] ? fmtDate($stats['latest']) : 'N/A' ?></p>
        </div>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="px-8 py-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-50/30">
            <h2 class="text-lg font-bold text-slate-800">Registration List</h2>
            <form action="" method="GET" class="relative w-full md:w-72">
                <input type="hidden" name="webinar_id" value="<?= $webinar_id ?>">
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search participants..."
                       class="w-full bg-white border border-slate-200 rounded-2xl pl-10 pr-4 py-2 text-sm focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold">
                <svg class="w-4 h-4 absolute left-3.5 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50/50">
                        <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Participant</th>
                        <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Contact</th>
                        <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-wider text-center">Consent</th>
                        <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Registered At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($registrations)): ?>
                        <tr>
                            <td colspan="6" class="px-8 py-12 text-center text-slate-400 font-bold">No participants found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registrations as $r): ?>
                            <tr class="hover:bg-yellow-50/30 transition-colors">
                                <td class="px-8 py-5">
                                    <p class="text-sm font-bold text-slate-900"><?= h($r['name']) ?></p>
                                    <p class="text-xs text-slate-400 font-semibold mt-0.5"><?= h($r['email']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-sm font-semibold text-slate-600"><?= h((string)($r['phone'] ?? '')) ?></p>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php if ((int)$r['consent'] === 1): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-50 text-emerald-700 border border-emerald-100 uppercase">Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black bg-slate-50 text-slate-400 border border-slate-100 uppercase">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-xs text-slate-400 font-semibold"><?= fmtDate($r['registered_at']) ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . "/../partials/footer.php"; ?>
