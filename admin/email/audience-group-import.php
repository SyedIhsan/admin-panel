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

// Sample CSV download
if (isset($_GET['sample'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audience_group_sample.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'phone']);
    fputcsv($out, ['ali@example.com', 'Ali Ahmad', '0123456789']);
    fputcsv($out, ['siti@example.com', 'Siti Norzah', '01199998888']);
    fclose($out);
    exit;
}

$presetGroupId = (int)($_GET['group_id'] ?? 0);
$errorMsg  = null;
$importResult = null;

// Load all groups for selector
$groups = [];
$res = $conn->query("SELECT id, group_name, total_members FROM `email_audience_groups` ORDER BY group_name ASC LIMIT 200");
while ($row = $res->fetch_assoc()) $groups[] = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $mode      = $_POST['mode'] ?? 'existing_group';
        $groupId   = (int)($_POST['group_id'] ?? 0);
        $newGrpName = trim((string)($_POST['new_group_name'] ?? ''));
        $adminLabel = campaign_safe_admin_label();

        if ($mode === 'new_group') {
            if ($newGrpName === '') throw new Exception("Group name is required.");
            $uid = campaign_generate_unique_group_uid($conn);
            $stmt = $conn->prepare(
                "INSERT INTO `email_audience_groups` (group_uid, group_name, source_type, created_by) VALUES (?, ?, 'csv_import', ?)"
            );
            $stmt->bind_param('sss', $uid, $newGrpName, $adminLabel);
            if (!$stmt->execute()) throw new Exception("Failed to create group.");
            $groupId = (int)$conn->insert_id;
            $stmt->close();
        }

        if ($groupId <= 0) throw new Exception("Please select a valid group or create a new one.");

        // Verify group exists
        $gStmt = $conn->prepare("SELECT id, group_name FROM `email_audience_groups` WHERE id = ? LIMIT 1");
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $groupRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        if (!$groupRow) throw new Exception("Group not found.");

        // Upload validation
        if (!isset($_FILES['csv_file'])) throw new Exception("Please choose a CSV file.");
        $uploadErr = $_FILES['csv_file']['error'];
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => "File too large (server limit).",
                UPLOAD_ERR_FORM_SIZE  => "File too large.",
                UPLOAD_ERR_PARTIAL    => "File only partially uploaded.",
                UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
                UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the upload.",
            ];
            throw new Exception($errMap[$uploadErr] ?? "Upload error (Code: {$uploadErr}).");
        }

        $fileTmp  = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileHash = hash_file('sha256', $fileTmp);
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') throw new Exception("Only .csv files are allowed.");

        $handle = fopen($fileTmp, 'r');
        if (!$handle) throw new Exception("Failed to open uploaded file.");

        // Detect UTF-8 BOM
        $rawHeaders = fgetcsv($handle);
        if (!$rawHeaders) { fclose($handle); throw new Exception("CSV is empty or missing headers."); }

        $emailIdx = $nameIdx = $phoneIdx = -1;
        $emailAliases = ['email', 'recipient email', 'email address', 'e mail'];
        $nameAliases  = ['name', 'recipient name', 'full name'];
        $phoneAliases = ['phone', 'recipient phone', 'mobile', 'phone number'];

        foreach ($rawHeaders as $idx => $hdr) {
            $h = campaign_normalize_csv_header((string)$hdr);
            if (in_array($h, $emailAliases, true)) $emailIdx = $idx;
            if (in_array($h, $nameAliases, true))  $nameIdx  = $idx;
            if (in_array($h, $phoneAliases, true)) $phoneIdx = $idx;
        }

        if ($emailIdx === -1) { fclose($handle); throw new Exception("CSV must have an 'email' column."); }

        $imported = $skipped = $invalid = $failed = $rowNum = 0;
        $batchEmails = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $email = strtolower(trim((string)($row[$emailIdx] ?? '')));
            $name  = $nameIdx  !== -1 ? trim((string)($row[$nameIdx]  ?? '')) : null;
            $phone = $phoneIdx !== -1 ? trim((string)($row[$phoneIdx] ?? '')) : null;
            if ($name === '') $name = null;
            if ($phone === '') $phone = null;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
            if (isset($batchEmails[$email])) { $skipped++; continue; }
            $batchEmails[$email] = true;

            $res = campaign_insert_group_member_with_duplicate_handling(
                $conn, $groupId, $email, $name, $phone, null, null, $adminLabel
            );
            if ($res > 0) $imported++;
            elseif ($res === 0) $skipped++;
            else $failed++;
        }
        fclose($handle);

        // Recalculate group counts
        campaign_recalculate_group_counts($conn, $groupId);

        // Determine updated source_type
        $currentType = $conn->query("SELECT source_type FROM `email_audience_groups` WHERE id = {$groupId}")->fetch_assoc()['source_type'] ?? 'manual';
        if ($currentType === 'manual' || $currentType === 'csv_import') {
            $conn->query("UPDATE `email_audience_groups` SET source_type='csv_import' WHERE id={$groupId}");
        } elseif ($currentType !== 'csv_import') {
            $conn->query("UPDATE `email_audience_groups` SET source_type='mixed' WHERE id={$groupId}");
        }

        // Audit log
        $importUid = 'gimp_' . substr((string)time(), -6) . '_' . bin2hex(random_bytes(4));
        $importStatus = $failed > 0 ? 'partial_failed' : 'completed';
        $stmt2 = $conn->prepare(
            "INSERT INTO `email_audience_group_imports`
             (group_id, import_uid, source_type, source_name, original_filename, import_file_hash, total_rows, imported_rows, skipped_rows, invalid_rows, failed_rows, imported_by, status)
             VALUES (?, ?, 'csv', 'CSV Upload', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('issssiiiiss', $groupId, $importUid, $fileName, $fileHash, $rowNum, $imported, $skipped, $invalid, $failed, $adminLabel, $importStatus);
        $stmt2->execute(); $stmt2->close();

        $importResult = [
            'group_id'   => $groupId,
            'group_name' => (string)$groupRow['group_name'],
            'total'      => $rowNum,
            'imported'   => $imported,
            'skipped'    => $skipped,
            'invalid'    => $invalid,
            'failed'     => $failed,
            'filename'   => $fileName,
            'filesize'   => $fileSize,
        ];

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log("Audience Group CSV Import Error: " . $errorMsg);
    }
}

$title     = 'Import to Audience Group - Demo Admin';
$pageTitle = 'Import CSV to Audience Group';
$pageDesc  = 'Upload contacts from a CSV file into an audience group.';
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
        $allOk    = $importResult['imported'] > 0 && $importResult['invalid'] === 0 && $importResult['failed'] === 0;
        $allSkip  = $importResult['total'] > 0 && $importResult['imported'] === 0 && $importResult['skipped'] === $importResult['total'];
        $color    = $allOk ? 'emerald' : 'amber';
        $statusMsg = $allOk ? 'CSV imported successfully.' : ($allSkip ? 'All rows were duplicates — nothing new was imported.' : 'Import completed with some rows needing review.');
        ?>
        <div class="rounded-3xl border border-<?= $color ?>-200 bg-<?= $color ?>-50 p-8 mb-8 shadow-sm">
            <h3 class="text-2xl font-black text-<?= $color ?>-900 mb-1">Import Summary</h3>
            <p class="text-sm font-bold text-<?= $color ?>-700 mb-6"><?= e($statusMsg) ?></p>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <?php foreach ([
                    ['Total', $importResult['total'], 'text-slate-900'],
                    ['Imported', $importResult['imported'], 'text-emerald-600'],
                    ['Skipped Dups', $importResult['skipped'], 'text-amber-600'],
                    ['Invalid', $importResult['invalid'], 'text-rose-600'],
                    ['Failed', $importResult['failed'], 'text-rose-600'],
                ] as [$lbl, $val, $cls]): ?>
                <div class="bg-white rounded-xl border border-<?= $color ?>-100 p-4 text-center">
                    <div class="text-[10px] font-bold uppercase text-slate-400 mb-1"><?= e($lbl) ?></div>
                    <div class="text-xl font-black <?= $cls ?>"><?= number_format($val) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="flex gap-4">
                <a href="/admin/email/audience-group-detail.php?id=<?= $importResult['group_id'] ?>&imported=1"
                   class="px-5 py-2.5 rounded-xl bg-<?= $color ?>-600 text-white font-black hover:bg-<?= $color ?>-700 transition text-sm shadow">
                    View Group: <?= e($importResult['group_name']) ?>
                </a>
                <a href="/admin/email/audience-group-import.php"
                   class="px-5 py-2.5 rounded-xl bg-white border border-<?= $color ?>-200 text-<?= $color ?>-700 font-black hover:bg-<?= $color ?>-100 transition text-sm">
                    Import More
                </a>
            </div>
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
        <form id="importForm" method="POST" enctype="multipart/form-data" class="space-y-8">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <!-- Step 1: Choose Group -->
            <div class="space-y-4">
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400">Step 1: Choose Target Group</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-slate-100 p-5 transition hover:border-yellow-200 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                        <input type="radio" name="mode" value="existing_group" class="peer sr-only" <?= empty($groups) ? '' : 'checked' ?>
                               onclick="document.getElementById('new-grp-fields').classList.add('hidden'); document.getElementById('existing-grp-fields').classList.remove('hidden');">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900">Existing Group</span>
                            <span class="text-xs font-semibold text-slate-500">Add to an existing audience group.</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-slate-100 p-5 transition hover:border-yellow-200 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                        <input type="radio" name="mode" value="new_group" class="peer sr-only" <?= empty($groups) ? 'checked' : '' ?>
                               onclick="document.getElementById('existing-grp-fields').classList.add('hidden'); document.getElementById('new-grp-fields').classList.remove('hidden');">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900">New Group</span>
                            <span class="text-xs font-semibold text-slate-500">Create a new group and import.</span>
                        </div>
                    </label>
                </div>

                <div id="existing-grp-fields" class="<?= empty($groups) ? 'hidden' : '' ?> p-5 rounded-2xl bg-slate-50 border border-slate-100">
                    <select name="group_id"
                            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="">-- Choose Group --</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $presetGroupId ? 'selected' : '' ?>>
                                <?= e((string)$g['group_name']) ?> (<?= number_format((int)$g['total_members']) ?> members)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="new-grp-fields" class="<?= empty($groups) ? '' : 'hidden' ?> p-5 rounded-2xl bg-slate-50 border border-slate-100">
                    <input type="text" name="new_group_name" maxlength="190" placeholder="Group name e.g. Hot Leads May 2026"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                </div>
            </div>

            <!-- Step 2: CSV File -->
            <div class="space-y-4">
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400">Step 2: Select CSV File</label>
                <div class="relative group">
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                    <div id="dropzone" class="rounded-3xl border-2 border-dashed border-slate-200 bg-slate-50 p-10 text-center transition group-hover:border-yellow-400 group-hover:bg-yellow-50">
                        <svg class="w-14 h-14 mx-auto text-slate-300 mb-4 group-hover:text-yellow-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <p id="file-name-display" class="text-lg font-black text-slate-900">Click to upload or drag and drop</p>
                        <p id="file-size-display" class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-2">Only .CSV allowed</p>
                    </div>
                </div>
                <div class="flex justify-between text-xs font-black">
                    <span class="text-slate-400">Required: <span class="text-slate-600">email</span> | Optional: name, phone</span>
                    <a href="?sample=1" class="text-yellow-600 hover:text-yellow-700 flex items-center gap-1.5 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download Sample
                    </a>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" id="submitBtn"
                        class="w-full py-5 rounded-2xl bg-yellow-500 text-white font-black text-xl shadow-xl hover:bg-yellow-400 hover:-translate-y-1 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                    Import to Group
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const fileInput = document.getElementById('csv_file');
    const nameDisp  = document.getElementById('file-name-display');
    const sizeDisp  = document.getElementById('file-size-display');
    const dropzone  = document.getElementById('dropzone');
    const form      = document.getElementById('importForm');
    const submitBtn = document.getElementById('submitBtn');

    fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            const f = this.files[0];
            nameDisp.textContent = 'Selected: ' + f.name;
            nameDisp.classList.add('text-yellow-600');
            let s = f.size + ' bytes';
            if (f.size > 1048576) s = (f.size/1048576).toFixed(2) + ' MB';
            else if (f.size > 1024) s = (f.size/1024).toFixed(2) + ' KB';
            sizeDisp.textContent = 'Size: ' + s;
            dropzone.classList.remove('bg-slate-50','border-slate-200');
            dropzone.classList.add('bg-yellow-50','border-yellow-400');
        }
    });
    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Importing…';
    });
})();
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
