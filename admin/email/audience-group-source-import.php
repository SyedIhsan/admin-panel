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

$allowedTables = campaign_allowed_lead_source_tables();
$presetGroupId = (int)($_GET['group_id'] ?? 0);
$errorMsg      = null;
$importResult  = null;

// Load groups
$groups = [];
$res = $conn->query("SELECT id, group_name, total_members FROM `email_audience_groups` ORDER BY group_name ASC LIMIT 200");
while ($row = $res->fetch_assoc()) $groups[] = $row;

// AJAX preview count
if (isset($_GET['preview'])) {
    header('Content-Type: application/json');
    $tbl = $_GET['table'] ?? '';
    if (!in_array($tbl, $allowedTables, true)) { echo json_encode(['count' => 0, 'error' => 'Invalid table']); exit; }
    $r = $conn->query("SELECT COUNT(*) as cnt FROM `{$tbl}` WHERE email IS NOT NULL AND TRIM(email) != ''");
    $cnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
    echo json_encode(['count' => $cnt]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $sourceTable = (string)($_POST['source_table'] ?? '');
        $groupId     = (int)($_POST['group_id'] ?? 0);
        $adminLabel  = campaign_safe_admin_label();

        // Security: strict whitelist check
        if (!in_array($sourceTable, $allowedTables, true)) {
            throw new Exception("Invalid lead source table selected.");
        }
        if ($groupId <= 0) throw new Exception("Please select a target group.");

        // Verify group exists
        $gStmt = $conn->prepare("SELECT id, group_name, source_type FROM `email_audience_groups` WHERE id = ? LIMIT 1");
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $groupRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        if (!$groupRow) throw new Exception("Group not found.");

        // Detect columns defensively
        $cols = campaign_detect_lead_table_columns($conn, $sourceTable);
        $hasEmail = in_array('email', $cols, true);
        $hasName  = in_array('name',  $cols, true);
        $hasPhone = in_array('phone', $cols, true);
        $hasId    = in_array('id',    $cols, true);

        if (!$hasEmail) throw new Exception("Source table '{$sourceTable}' does not have an email column.");

        // Build SELECT
        $selectCols = ['email'];
        if ($hasName)  $selectCols[] = 'name';
        if ($hasPhone) $selectCols[] = 'phone';
        if ($hasId)    $selectCols[] = 'id';
        $selectSQL = implode(', ', array_map(fn($c) => "`{$c}`", $selectCols));

        // Give the batch query breathing room for very large source tables.
        // Primary fix is the batch SQL — set_time_limit is a safety net only.
        set_time_limit(120);

        $imported = $skipped = $invalid = $failed = $total = 0;

        // Count all source rows with a non-empty email value.
        $cntStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM `{$sourceTable}`
             WHERE email IS NOT NULL AND TRIM(email) != ''"
        );
        if ($cntStmt) {
            $cntStmt->execute();
            $total = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cntStmt->close();
        }

        // Count rows whose email fails the basic format check (no '@' or no '.' after '@').
        // This approximates filter_var(FILTER_VALIDATE_EMAIL) for the batch approach.
        $invalStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM `{$sourceTable}`
             WHERE email IS NOT NULL AND TRIM(email) != ''
               AND email NOT LIKE '%@%.%'"
        );
        if ($invalStmt) {
            $invalStmt->execute();
            $invalid = (int)($invalStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $invalStmt->close();
        }

        $validTotal = $total - $invalid;

        if ($validTotal > 0) {
            // MIN() picks one value when the source table has duplicate email rows.
            $nameExpr  = $hasName  ? 'MIN(src.`name`)'                 : "''";
            $phoneExpr = $hasPhone ? 'MIN(src.`phone`)'                 : 'NULL';
            $idExpr    = $hasId    ? 'MIN(CAST(src.`id` AS CHAR(120)))' : 'NULL';

            // Single batch INSERT replaces the per-row prepared-statement loop.
            // LEFT JOIN anti-join: skips emails already present in this audience group.
            // GROUP BY:           deduplicates emails that appear multiple times in the source table.
            $batchSql = "
                INSERT INTO `email_audience_group_members`
                    (group_id, email, name, phone, source_table, source_id, status, added_by)
                SELECT
                    ?                         AS group_id,
                    LOWER(TRIM(src.`email`))  AS email,
                    {$nameExpr}               AS name,
                    {$phoneExpr}              AS phone,
                    ?                         AS source_table,
                    {$idExpr}                 AS source_id,
                    'active'                  AS status,
                    ?                         AS added_by
                FROM `{$sourceTable}` src
                LEFT JOIN `email_audience_group_members` m
                       ON m.group_id = ?
                      AND LOWER(TRIM(m.email))       COLLATE utf8mb4_unicode_ci
                        = LOWER(TRIM(src.`email`))   COLLATE utf8mb4_unicode_ci
                WHERE src.email IS NOT NULL
                  AND TRIM(src.email) != ''
                  AND src.email LIKE '%@%.%'
                  AND m.id IS NULL
                GROUP BY LOWER(TRIM(src.`email`))    COLLATE utf8mb4_unicode_ci
            ";

            $batchStmt = $conn->prepare($batchSql);
            if ($batchStmt) {
                $batchStmt->bind_param('issi', $groupId, $sourceTable, $adminLabel, $groupId);
                if ($batchStmt->execute()) {
                    $imported = (int)$batchStmt->affected_rows;
                    $skipped  = max(0, $validTotal - $imported);
                } else {
                    $failed = $validTotal;
                    error_log("Lead source batch import execute failed (group={$groupId}, table={$sourceTable}): " . $conn->error);
                }
                $batchStmt->close();
            } else {
                $failed = $validTotal;
                error_log("Lead source batch import prepare failed (group={$groupId}, table={$sourceTable}): " . $conn->error);
            }
        }

        // Recalculate counts
        campaign_recalculate_group_counts($conn, $groupId);

        // Update source_type
        $currentType = (string)($groupRow['source_type'] ?? 'manual');
        if (in_array($currentType, ['manual', 'lead_table'], true)) {
            $newType = 'lead_table';
        } else {
            $newType = 'mixed';
        }
        $conn->query("UPDATE `email_audience_groups` SET source_type='{$newType}' WHERE id={$groupId}");

        // Audit log
        $importUid    = 'gimp_' . substr((string)time(), -6) . '_' . bin2hex(random_bytes(4));
        $importStatus = $failed > 0 ? 'partial_failed' : 'completed';
        $sourceName   = ucwords(str_replace('_', ' ', $sourceTable));
        $stmt2 = $conn->prepare(
            "INSERT INTO `email_audience_group_imports`
             (group_id, import_uid, source_type, source_name, total_rows, imported_rows, skipped_rows, invalid_rows, failed_rows, imported_by, status)
             VALUES (?, ?, 'lead_table', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('iss'.'iiiii'.'ss', $groupId, $importUid, $sourceName, $total, $imported, $skipped, $invalid, $failed, $adminLabel, $importStatus);
        $stmt2->execute(); $stmt2->close();

        $importResult = [
            'group_id'     => $groupId,
            'group_name'   => (string)$groupRow['group_name'],
            'source_table' => $sourceTable,
            'total'        => $total,
            'imported'     => $imported,
            'skipped'      => $skipped,
            'invalid'      => $invalid,
            'failed'       => $failed,
        ];

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log("Audience Group Source Import Error: " . $errorMsg);
    }
}

$title     = 'Import from Lead Source - Demo Admin';
$pageTitle = 'Import from Lead Source';
$pageDesc  = 'Add contacts from TikTok, Telegram, Meta, or Webinar lead tables into an audience group.';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8 max-w-3xl">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
            <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
        </div>
        <a href="/admin/email/audience-groups.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Groups
        </a>
    </div>

    <!-- Import Result -->
    <?php if ($importResult): ?>
        <?php
        $allOk   = $importResult['imported'] > 0 && $importResult['failed'] === 0;
        $color   = $allOk ? 'emerald' : 'amber';
        $srcName = ucwords(str_replace('_', ' ', (string)$importResult['source_table']));
        ?>
        <div class="rounded-3xl border border-<?= $color ?>-200 bg-<?= $color ?>-50 p-8 mb-8 shadow-sm">
            <h3 class="text-2xl font-black text-<?= $color ?>-900 mb-1">Import Complete</h3>
            <p class="text-sm font-bold text-<?= $color ?>-700 mb-2">Imported from <strong><?= e($srcName) ?></strong> into <strong><?= e($importResult['group_name']) ?></strong></p>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <?php foreach ([
                    ['Total Leads', $importResult['total'],    'text-slate-900'],
                    ['Imported',    $importResult['imported'], 'text-emerald-600'],
                    ['Skipped Dups',$importResult['skipped'],  'text-amber-600'],
                    ['Invalid',     $importResult['invalid'],  'text-rose-600'],
                    ['Failed',      $importResult['failed'],   'text-rose-600'],
                ] as [$lbl, $val, $cls]): ?>
                <div class="bg-white rounded-xl border border-<?= $color ?>-100 p-4 text-center">
                    <div class="text-[10px] font-bold uppercase text-slate-400 mb-1"><?= e($lbl) ?></div>
                    <div class="text-xl font-black <?= $cls ?>"><?= number_format((int)$val) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="/admin/email/audience-group-detail.php?id=<?= (int)$importResult['group_id'] ?>&imported=1"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-<?= $color ?>-600 text-white font-black hover:bg-<?= $color ?>-700 transition text-sm shadow">
                View Group
            </a>
        </div>
    <?php endif; ?>

    <!-- Error -->
    <?php if ($errorMsg): ?>
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 mb-6 flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-bold text-rose-700"><?= e($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
        <form method="POST" id="sourceForm" class="space-y-8">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <!-- Step 1: Lead Source -->
            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Step 1: Choose Lead Source</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($allowedTables as $tbl): ?>
                        <?php $lbl = ucwords(str_replace('_', ' ', $tbl)); ?>
                        <label class="relative flex cursor-pointer rounded-2xl border-2 border-slate-100 p-5 transition hover:border-yellow-200 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                            <input type="radio" name="source_table" value="<?= e($tbl) ?>" class="peer sr-only"
                                   onchange="fetchPreview('<?= e($tbl) ?>')">
                            <div class="flex items-center justify-between w-full">
                                <div>
                                    <span class="font-black text-slate-900 block"><?= e($lbl) ?></span>
                                    <span class="text-[10px] font-mono text-slate-400"><?= e($tbl) ?></span>
                                </div>
                                <span class="preview-count-<?= e($tbl) ?> text-xs font-black text-slate-400 bg-slate-100 px-2 py-1 rounded-lg ml-3">
                                    Loading…
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Target Group -->
            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-3">Step 2: Target Audience Group</label>
                <?php if (empty($groups)): ?>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-bold text-amber-700">
                        No groups found.
                        <a href="/admin/email/audience-group-form.php" class="underline">Create one first.</a>
                    </div>
                <?php else: ?>
                    <select name="group_id" required
                            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="">-- Choose Group --</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $presetGroupId ? 'selected' : '' ?>>
                                <?= e((string)$g['group_name']) ?> (<?= number_format((int)$g['total_members']) ?> members)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- Info Box -->
            <div class="rounded-2xl bg-blue-50 border border-blue-100 p-5 text-sm text-blue-800">
                <p class="font-black mb-2">How this works:</p>
                <ul class="space-y-1.5 font-bold text-blue-700 list-disc ml-4 text-xs">
                    <li>All valid leads from the selected table are read.</li>
                    <li>Leads already in the group (by email) are skipped automatically.</li>
                    <li>Source table name and lead ID are stored for traceability.</li>
                    <li>No emails are sent — this only populates the group.</li>
                </ul>
            </div>

            <div>
                <button type="submit" id="submitBtn" <?= empty($groups) ? 'disabled' : '' ?>
                        class="w-full py-5 rounded-2xl bg-yellow-500 text-white font-black text-xl shadow-xl hover:bg-yellow-400 hover:-translate-y-1 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                    Import from Lead Source
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const allowedTables = <?= json_encode($allowedTables) ?>;

function fetchPreview(tbl) {
    fetch('/admin/email/audience-group-source-import.php?preview=1&table=' + encodeURIComponent(tbl))
        .then(r => r.json())
        .then(d => {
            const el = document.querySelector('.preview-count-' + tbl);
            if (el) el.textContent = d.error ? 'Error' : (d.count.toLocaleString() + ' leads');
        })
        .catch(() => {
            const el = document.querySelector('.preview-count-' + tbl);
            if (el) el.textContent = 'N/A';
        });
}

// Load all counts on page load
allowedTables.forEach(fetchPreview);

document.getElementById('sourceForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Importing…';
});
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
