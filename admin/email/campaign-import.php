<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

// Get mysqli connection
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// Ensure schema
campaign_ensure_schema($conn);

// 1. Handle Sample CSV Download (MUST BE BEFORE ANY HTML)
if (isset($_GET['sample'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=campaign_sample.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['email', 'name', 'phone']);
    fputcsv($output, ['test1@example.test', 'Test User 1', '0123456789']);
    fputcsv($output, ['test2@example.test', 'Test User 2', '0199999999']);
    fclose($output);
    exit;
}

$errorMsg = null;
$isDebug = isset($_GET['debug']) && $_GET['debug'] === '1';

// 2. Handle POST Import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $mode = $_POST['mode'] ?? 'existing'; // 'existing', 'new', or 'group'
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $newCampaignName = trim((string)($_POST['new_campaign_name'] ?? ''));
        $newSubject = trim((string)($_POST['new_subject'] ?? ''));
        $processedAt = date('Y-m-d H:i:s');
        $debugInfo = [];

        // ── Group → Campaign mode ────────────────────────────────────────────
        if ($mode === 'group') {
            $groupId        = (int)($_POST['group_id'] ?? 0);
            $groupCampaignId = (int)($_POST['campaign_id'] ?? 0);
            $adminLabel     = campaign_safe_admin_label();

            if ($groupId <= 0) throw new Exception("Please select an audience group.");

            // Optionally create a new campaign for this group
            if ($groupCampaignId <= 0) {
                $gcName = trim((string)($_POST['new_campaign_name'] ?? ''));
                if ($gcName === '') throw new Exception("Please choose a campaign or enter a new campaign name.");
                $uid = campaign_generate_unique_uid($conn, 'camp');
                $stmt = $conn->prepare(
                    "INSERT INTO `email_campaigns` (campaign_uid, campaign_name, subject, status, campaign_type, created_by)
                     VALUES (?, ?, ?, 'draft', 'manual_blast', ?)"
                );
                $stmt->bind_param('ssss', $uid, $gcName, $newSubject, $adminLabel);
                if (!$stmt->execute()) throw new Exception("Failed to create campaign: " . $stmt->error);
                $groupCampaignId = (int)$conn->insert_id;
                $stmt->close();
            }

            // Verify group & campaign
            $gStmt = $conn->prepare("SELECT group_name, active_members FROM `email_audience_groups` WHERE id = ? LIMIT 1");
            $gStmt->bind_param('i', $groupId);
            $gStmt->execute();
            $groupRow = $gStmt->get_result()->fetch_assoc();
            $gStmt->close();
            if (!$groupRow) throw new Exception("Audience group not found.");

            $copyResult = campaign_copy_group_to_campaign_recipients($conn, $groupId, $groupCampaignId);
            if (!$copyResult['ok']) throw new Exception("Copy failed: " . $copyResult['error']);

            $cStmt = $conn->prepare("SELECT campaign_name FROM `email_campaigns` WHERE id = ? LIMIT 1");
            $cStmt->bind_param('i', $groupCampaignId);
            $cStmt->execute();
            $cRow = $cStmt->get_result()->fetch_assoc();
            $cStmt->close();

            $_SESSION['import_flash'] = [
                'campaign_id'   => $groupCampaignId,
                'campaign_name' => $cRow['campaign_name'] ?? 'Unknown',
                'total'         => (int)$groupRow['active_members'],
                'imported'      => $copyResult['imported'],
                'skipped'       => $copyResult['skipped'],
                'invalid'       => 0,
                'errors'        => $copyResult['failed'],
                'filename'      => 'Audience Group: ' . $groupRow['group_name'],
                'filesize'      => 0,
                'mode'          => 'From Audience Group',
                'processed_at'  => $processedAt,
                'debug'         => null,
            ];
            header("Location: campaign-import.php");
            exit;
        }
        // ── /Group mode ──────────────────────────────────────────────────────

        // ── Webinar Group → Campaign mode ────────────────────────────────────
        if ($mode === 'webinar_group') {
            $webinarId    = (int)($_POST['webinar_id'] ?? 0);
            $wgCampaignId = (int)($_POST['campaign_id'] ?? 0);
            $adminLabel   = campaign_safe_admin_label();

            if ($webinarId <= 0) throw new Exception("Please select a webinar.");

            $wStmt = $conn->prepare("SELECT webinar_title FROM sdc_webinars WHERE id = ? LIMIT 1");
            $wStmt->bind_param('i', $webinarId);
            $wStmt->execute();
            $webinarRow = $wStmt->get_result()->fetch_assoc();
            $wStmt->close();
            if (!$webinarRow) throw new Exception("Webinar not found.");

            if ($wgCampaignId <= 0) {
                $wgName = trim((string)($_POST['new_campaign_name'] ?? ''));
                if ($wgName === '') throw new Exception("Please choose a campaign or enter a new campaign name.");
                $uid = campaign_generate_unique_uid($conn, 'camp');
                $stmt = $conn->prepare(
                    "INSERT INTO `email_campaigns` (campaign_uid, campaign_name, subject, status, campaign_type, created_by)
                     VALUES (?, ?, ?, 'draft', 'manual_blast', ?)"
                );
                $stmt->bind_param('ssss', $uid, $wgName, $newSubject, $adminLabel);
                if (!$stmt->execute()) throw new Exception("Failed to create campaign: " . $stmt->error);
                $wgCampaignId = (int)$conn->insert_id;
                $stmt->close();
            }

            $rStmt = $conn->prepare(
                "SELECT name, email, phone FROM sdc_webinar_registrations WHERE webinar_id = ? AND consent = 1"
            );
            $rStmt->bind_param('i', $webinarId);
            $rStmt->execute();
            $rResult = $rStmt->get_result();

            $wgImported = 0; $wgSkipped = 0; $wgFailed = 0; $wgTotal = 0;
            while ($rRow = $rResult->fetch_assoc()) {
                $wgTotal++;
                $errorReason = '';
                $resCode = campaign_insert_recipient_with_retry(
                    $conn, $wgCampaignId,
                    strtolower(trim((string)$rRow['email'])),
                    ($rRow['name'] !== '' ? $rRow['name'] : null),
                    ($rRow['phone'] !== '' ? $rRow['phone'] : null),
                    $errorReason
                );
                if ($resCode > 0) $wgImported++;
                elseif ($resCode === 0) $wgSkipped++;
                else $wgFailed++;
            }
            $rStmt->close();

            $auditHash = 'webinar_' . $webinarId . '_' . time();
            $auditFile = 'Webinar: ' . $webinarRow['webinar_title'];
            $audit = $conn->prepare(
                "INSERT INTO `email_campaign_imports`
                    (campaign_id, imported_by, import_file_hash, original_filename, total_rows, imported_rows, skipped_rows, error_rows, status, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())"
            );
            $audit->bind_param('isssiiii', $wgCampaignId, $adminLabel, $auditHash, $auditFile, $wgTotal, $wgImported, $wgSkipped, $wgFailed);
            $audit->execute();
            $audit->close();

            campaign_recalculate_metrics($conn, $wgCampaignId);

            $cStmt = $conn->prepare("SELECT campaign_name FROM `email_campaigns` WHERE id = ? LIMIT 1");
            $cStmt->bind_param('i', $wgCampaignId);
            $cStmt->execute();
            $cRow = $cStmt->get_result()->fetch_assoc();
            $cStmt->close();

            $_SESSION['import_flash'] = [
                'campaign_id'   => $wgCampaignId,
                'campaign_name' => $cRow['campaign_name'] ?? 'Unknown',
                'total'         => $wgTotal,
                'imported'      => $wgImported,
                'skipped'       => $wgSkipped,
                'invalid'       => 0,
                'errors'        => $wgFailed,
                'filename'      => $auditFile,
                'filesize'      => 0,
                'mode'          => 'From Webinar Group',
                'processed_at'  => $processedAt,
                'debug'         => null,
            ];
            header("Location: campaign-import.php");
            exit;
        }
        // ── /Webinar Group mode ───────────────────────────────────────────────

        // 2.1 Mode Validation
        if ($mode === 'new') {
            if ($newCampaignName === '') {
                throw new Exception("Campaign name is required for new campaign.");
            }

            $uid = campaign_generate_unique_uid($conn, 'camp');
            $stmt = $conn->prepare("INSERT INTO `email_campaigns` (campaign_uid, campaign_name, subject, status, campaign_type, created_by) VALUES (?, ?, ?, 'draft', 'manual_blast', ?)");
            $adminLabel = campaign_safe_admin_label();
            $stmt->bind_param('ssss', $uid, $newCampaignName, $newSubject, $adminLabel);
            if (!$stmt->execute()) {
                throw new Exception("Failed to create new campaign: " . $stmt->error);
            }
            $campaignId = (int)$conn->insert_id;
            $stmt->close();
        }

        if ($campaignId <= 0) {
            throw new Exception("Please select a valid campaign or create a new one.");
        }

        // 2.2 Upload Validation
        if (!isset($_FILES['csv_file'])) {
            throw new Exception("Please choose a CSV file.");
        }

        $uploadErr = $_FILES['csv_file']['error'];
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE => "CSV file is too large based on server upload limit.",
                UPLOAD_ERR_FORM_SIZE => "CSV file is too large.",
                UPLOAD_ERR_PARTIAL => "File was only partially uploaded.",
                UPLOAD_ERR_NO_FILE => "No file was uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
                UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload."
            ];
            $msg = $errMap[$uploadErr] ?? "Unknown upload error (Code: {$uploadErr}).";
            error_log("CSV Upload Error: {$msg}");
            throw new Exception($msg);
        }

        $fileTmp = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileHash = hash_file('sha256', $fileTmp);
        $fileMime = $_FILES['csv_file']['type'];

        if ($isDebug) {
            $debugInfo['upload'] = [
                'name' => $fileName,
                'mime' => $fileMime,
                'size' => $fileSize
            ];
        }

        $allowedExts = ['csv'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            throw new Exception("Only .csv files are allowed.");
        }

        $handle = fopen($fileTmp, 'r');
        if (!$handle) {
            throw new Exception("Failed to open uploaded file.");
        }

        // Detect Headers
        $rawHeaders = fgetcsv($handle);
        if (!$rawHeaders) {
            fclose($handle);
            throw new Exception("CSV file is empty or missing headers.");
        }

        $emailIdx = -1;
        $nameIdx = -1;
        $phoneIdx = -1;

        $emailAliases = ['email', 'recipient email', 'email address', 'e mail'];
        $nameAliases = ['name', 'recipient name', 'full name'];
        $phoneAliases = ['phone', 'recipient phone', 'mobile', 'phone number'];

        $normalizedHeaders = [];
        foreach ($rawHeaders as $idx => $header) {
            $h = campaign_normalize_csv_header((string)$header);
            $normalizedHeaders[] = $h;
            if (in_array($h, $emailAliases)) $emailIdx = $idx;
            if (in_array($h, $nameAliases)) $nameIdx = $idx;
            if (in_array($h, $phoneAliases)) $phoneIdx = $idx;
        }

        if ($isDebug) {
            $debugInfo['headers'] = [
                'raw' => $rawHeaders,
                'normalized' => $normalizedHeaders,
                'mapped' => ['email' => $emailIdx, 'name' => $nameIdx, 'phone' => $phoneIdx]
            ];
            $debugInfo['rows'] = [];
        }

        if ($emailIdx === -1) {
            fclose($handle);
            throw new Exception("CSV must contain an 'email' column. Detected headers: " . implode(', ', $normalizedHeaders));
        }

        $importedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $invalidEmailCount = 0;
        $rowNumber = 1; // 1 was header
        $batchEmails = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $email = strtolower(trim((string)($row[$emailIdx] ?? '')));
            $name = $nameIdx !== -1 ? trim((string)($row[$nameIdx] ?? '')) : null;
            $phone = $phoneIdx !== -1 ? trim((string)($row[$phoneIdx] ?? '')) : null;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmailCount++;
                if ($isDebug) $debugInfo['rows'][] = "Row {$rowNumber}: Invalid email '{$email}'";
                continue;
            }

            // Check duplicate in same CSV
            if (isset($batchEmails[$email])) {
                $skippedCount++;
                if ($isDebug) $debugInfo['rows'][] = "Row {$rowNumber}: Duplicate email inside CSV '{$email}'";
                continue;
            }

            // Insert with duplicate/token collision handling
            $errorReason = '';
            $resCode = campaign_insert_recipient_with_retry($conn, $campaignId, $email, $name, $phone, $errorReason);
            
            if ($resCode > 0) {
                $importedCount++;
                $batchEmails[$email] = true;
            } elseif ($resCode === 0) {
                $skippedCount++;
                if ($isDebug) $debugInfo['rows'][] = "Row {$rowNumber}: Duplicate email in campaign '{$email}'";
            } else {
                $errorCount++;
                if ($isDebug) $debugInfo['rows'][] = "Row {$rowNumber}: Failed '{$email}' - Reason: {$errorReason}";
            }
        }
        fclose($handle);

        // Audit log
        $adminLabel = campaign_safe_admin_label();
        $totalRowsProcessed = $rowNumber - 1; // excluding header
        $audit = $conn->prepare("INSERT INTO `email_campaign_imports` (campaign_id, imported_by, import_file_hash, original_filename, total_rows, imported_rows, skipped_rows, error_rows, status, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())");
        $audit->bind_param('isssiiii', $campaignId, $adminLabel, $fileHash, $fileName, $totalRowsProcessed, $importedCount, $skippedCount, $errorCount);
        $audit->execute();
        $audit->close();

        // Recalculate metrics
        campaign_recalculate_metrics($conn, $campaignId);

        $campaignName = 'Unknown';
        $stmt = $conn->prepare("SELECT campaign_name FROM `email_campaigns` WHERE id = ?");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $cRow = $stmt->get_result()->fetch_assoc();
        $campaignName = $cRow['campaign_name'] ?? 'Unknown';
        $stmt->close();

        // Store flash result
        $_SESSION['import_flash'] = [
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'total' => $totalRowsProcessed,
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'invalid' => $invalidEmailCount,
            'errors' => $errorCount,
            'filename' => $fileName,
            'filesize' => $fileSize,
            'mode' => ($mode === 'new' ? 'New Campaign' : 'Existing Campaign'),
            'processed_at' => $processedAt,
            'debug' => ($isDebug ? $debugInfo : null)
        ];

        // Redirect to avoid re-submit
        header("Location: campaign-import.php" . ($isDebug ? "?debug=1" : ""));
        exit;

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log("CSV Import Error: " . $errorMsg);
    }
}

// 3. Load Results from Session
$importResult = $_SESSION['import_flash'] ?? null;
unset($_SESSION['import_flash']);

// Get existing campaigns for dropdown
$campaigns = [];
$res = $conn->query("SELECT id, campaign_name, status FROM `email_campaigns` ORDER BY created_at DESC LIMIT 100");
while ($row = $res->fetch_assoc()) {
    $campaigns[] = $row;
}

// Get audience groups for group mode
$audienceGroups = [];
$agRes = $conn->query("SELECT id, group_name, active_members FROM `email_audience_groups` ORDER BY group_name ASC LIMIT 200");
while ($agRow = $agRes->fetch_assoc()) {
    $audienceGroups[] = $agRow;
}

// Get webinars for webinar_group mode
$webinars = [];
$wRes = $conn->query("SELECT id, webinar_title, start_datetime, status FROM sdc_webinars ORDER BY start_datetime DESC LIMIT 100");
if ($wRes) {
    while ($wRow = $wRes->fetch_assoc()) {
        $webinars[] = $wRow;
    }
}

// Pre-set from URL (e.g., from audience-groups.php)
$presetMode      = in_array($_GET['mode'] ?? '', ['existing', 'new', 'group', 'webinar_group'], true) ? $_GET['mode'] : '';
$presetGroupId   = (int)($_GET['group_id'] ?? 0);
$presetWebinarId = (int)($_GET['webinar_id'] ?? 0);

// Helper function for HTML escaping
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// Page metadata
$title = 'Import Campaign Recipients - Demo Admin';
$pageTitle = 'Import Recipients';
$pageDesc = 'Upload CSV contacts into an email campaign.';

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
            <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/admin/email/campaign-monitoring.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Monitoring
            </a>
        </div>
    </div>

    <!-- Import Result Card -->
    <?php if ($importResult): ?>
        <?php
        $isSuccess = ($importResult['imported'] > 0 && $importResult['invalid'] == 0 && $importResult['errors'] == 0);
        $isAllDuplicate = ($importResult['total'] > 0 && $importResult['imported'] == 0 && $importResult['skipped'] == $importResult['total']);
        $hasIssues = ($importResult['invalid'] > 0 || $importResult['errors'] > 0);
        
        $cardColor = 'emerald';
        $statusMsg = 'Your CSV file was uploaded and processed successfully.';
        
        if ($isAllDuplicate) {
            $cardColor = 'amber';
            $statusMsg = 'Your CSV file was uploaded successfully. No new contacts were imported because all rows already exist in this campaign.';
        } elseif ($hasIssues) {
            $cardColor = 'amber';
            $statusMsg = 'Your CSV file was uploaded and processed, but some rows need review.';
        }
        ?>
        <div class="rounded-3xl border border-<?= $cardColor ?>-200 bg-<?= $cardColor ?>-50 p-8 shadow-sm mb-8">
            <div class="flex items-start gap-6">
                <div class="h-14 w-14 rounded-2xl bg-<?= $cardColor ?>-100 text-<?= $cardColor ?>-600 flex items-center justify-center shrink-0 shadow-sm">
                    <?php if ($cardColor === 'emerald'): ?>
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?php else: ?>
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <h3 class="text-2xl font-black text-<?= $cardColor ?>-900 mb-1">Import Summary</h3>
                    <p class="text-sm text-<?= $cardColor ?>-700 font-bold opacity-80 mb-6"><?= e($statusMsg) ?></p>
                    
                    <!-- File Metadata -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8 bg-white/50 p-4 rounded-2xl border border-<?= $cardColor ?>-100">
                        <div class="space-y-1">
                            <div class="text-[10px] font-bold uppercase tracking-widest text-<?= $cardColor ?>-500">Uploaded File</div>
                            <div class="text-sm font-black text-slate-900 truncate"><?= e($importResult['filename']) ?></div>
                            <div class="text-[10px] font-bold text-slate-400"><?= number_format($importResult['filesize']) ?> bytes</div>
                        </div>
                        <div class="space-y-1">
                            <div class="text-[10px] font-bold uppercase tracking-widest text-<?= $cardColor ?>-500">Processed Information</div>
                            <div class="text-sm font-black text-slate-900"><?= e($importResult['campaign_name']) ?> <span class="text-[10px] text-slate-400 ml-1 font-bold">(<?= e($importResult['mode']) ?>)</span></div>
                            <div class="text-[10px] font-bold text-slate-400"><?= e($importResult['processed_at']) ?></div>
                        </div>
                    </div>

                    <!-- Counters -->
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                        <div class="bg-white p-4 rounded-xl border border-<?= $cardColor ?>-100 text-center">
                            <div class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Rows</div>
                            <div class="text-xl font-black text-slate-900"><?= number_format($importResult['total']) ?></div>
                        </div>
                        <div class="bg-white p-4 rounded-xl border border-<?= $cardColor ?>-100 text-center">
                            <div class="text-[10px] font-bold uppercase text-emerald-500 mb-1">Imported</div>
                            <div class="text-xl font-black text-emerald-600"><?= number_format($importResult['imported']) ?></div>
                        </div>
                        <div class="bg-white p-4 rounded-xl border border-<?= $cardColor ?>-100 text-center">
                            <div class="text-[10px] font-bold uppercase text-amber-500 mb-1">Skipped Dups</div>
                            <div class="text-xl font-black text-amber-600"><?= number_format($importResult['skipped']) ?></div>
                        </div>
                        <div class="bg-white p-4 rounded-xl border border-<?= $cardColor ?>-100 text-center">
                            <div class="text-[10px] font-bold uppercase text-rose-500 mb-1">Invalid</div>
                            <div class="text-xl font-black text-rose-600"><?= number_format($importResult['invalid']) ?></div>
                        </div>
                        <div class="bg-white p-4 rounded-xl border border-<?= $cardColor ?>-100 text-center">
                            <div class="text-[10px] font-bold uppercase text-rose-500 mb-1">Failed</div>
                            <div class="text-xl font-black text-rose-600"><?= number_format($importResult['errors']) ?></div>
                        </div>
                    </div>

                    <div class="mt-8 flex flex-wrap gap-4">
                        <a href="/admin/email/campaign-details.php?id=<?= $importResult['campaign_id'] ?>" class="px-6 py-3 rounded-xl bg-<?= $cardColor ?>-600 text-white font-black hover:bg-<?= $cardColor ?>-700 transition shadow-sm text-sm">
                            View Campaign Details
                        </a>
                        <a href="/admin/email/campaign-import.php" class="px-6 py-3 rounded-xl bg-white border border-<?= $cardColor ?>-200 text-<?= $cardColor ?>-700 font-black hover:bg-<?= $cardColor ?>-100 transition text-sm">
                            Import More
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($importResult['debug']): ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-900 p-6 shadow-sm mb-8 text-slate-300 font-mono text-xs overflow-x-auto">
                <h4 class="text-yellow-500 font-bold mb-4 uppercase flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    Debug Info
                </h4>
                <pre><?= e(json_encode($importResult['debug'], JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if ($errorMsg): ?>
        <div class="rounded-3xl border border-rose-200 bg-rose-50 p-6 shadow-sm mb-8 flex items-center gap-4">
            <div class="h-12 w-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h4 class="font-black text-rose-900">Import Failed</h4>
                <p class="text-sm text-rose-700 font-semibold"><?= e($errorMsg) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Import Form -->
    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
        <form id="importForm" action="?<?= e(http_build_query($_GET)) ?>" method="POST" enctype="multipart/form-data" class="space-y-8">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <!-- Mode Selection -->
            <?php
            // Determine initial active mode based on URL hint or campaign availability
            $initMode = 'existing';
            if ($presetMode === 'group') $initMode = 'group';
            elseif ($presetMode === 'webinar_group') $initMode = 'webinar_group';
            elseif ($presetMode === 'new' || empty($campaigns)) $initMode = 'new';
            ?>
            <div class="space-y-4">
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400">Step 1: Choose Import Mode</label>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-slate-100 p-5 transition hover:border-yellow-200 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                        <input type="radio" name="mode" value="existing" class="peer sr-only"
                               <?= $initMode === 'existing' ? 'checked' : '' ?>
                               onclick="setImportMode('existing')">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900">Existing Campaign</span>
                            <span class="text-xs font-semibold text-slate-500">Add recipients to an existing campaign.</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-slate-100 p-5 transition hover:border-yellow-200 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                        <input type="radio" name="mode" value="new" class="peer sr-only"
                               <?= $initMode === 'new' ? 'checked' : '' ?>
                               onclick="setImportMode('new')">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900">Create New Campaign</span>
                            <span class="text-xs font-semibold text-slate-500">Create a new draft campaign and import.</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-yellow-200 p-5 transition hover:border-yellow-400 has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                        <input type="radio" name="mode" value="group" class="peer sr-only"
                               <?= $initMode === 'group' ? 'checked' : '' ?>
                               onclick="setImportMode('group')">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900 flex items-center gap-1.5">
                                From Audience Group
                            </span>
                            <span class="text-xs font-semibold text-slate-500">Copy active members of a saved group into a campaign.</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-2xl border-2 border-indigo-100 p-5 transition hover:border-indigo-300 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                        <input type="radio" name="mode" value="webinar_group" class="peer sr-only"
                               <?= $initMode === 'webinar_group' ? 'checked' : '' ?>
                               onclick="setImportMode('webinar_group')">
                        <div class="flex flex-col gap-1">
                            <span class="font-black text-slate-900 flex items-center gap-1.5">
                                From Webinar Group
                                <span class="text-[9px] font-black bg-indigo-500 text-white px-1.5 py-0.5 rounded uppercase">New</span>
                            </span>
                            <span class="text-xs font-semibold text-slate-500">Import consented webinar registrants into a campaign.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Mode Details -->
            <div class="space-y-4">
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400">Step 2: Campaign Details</label>

                <!-- Existing campaign -->
                <div id="mode-existing-fields" class="<?= $initMode !== 'existing' ? 'hidden' : '' ?> p-6 rounded-2xl bg-slate-50 border border-slate-100">
                    <select name="campaign_id" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="">-- Choose Campaign --</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['campaign_name']) ?> (<?= e(ucfirst($c['status'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- New campaign -->
                <div id="mode-new-fields" class="<?= $initMode !== 'new' ? 'hidden' : '' ?> p-6 rounded-2xl bg-slate-50 border border-slate-100 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Campaign Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="new_campaign_name" placeholder="e.g. June Newsletter" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Default Subject</label>
                            <input type="text" name="new_subject" placeholder="e.g. Exclusive Offer!" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                    </div>
                </div>

                <!-- From Audience Group -->
                <div id="mode-group-fields" class="<?= $initMode !== 'group' ? 'hidden' : '' ?> p-6 rounded-2xl bg-yellow-50 border border-yellow-200 space-y-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Select Audience Group <span class="text-rose-500">*</span></label>
                        <?php if (empty($audienceGroups)): ?>
                            <div class="text-sm font-bold text-amber-700">
                                No audience groups found.
                                <a href="/admin/email/audience-group-form.php" class="underline">Create one first.</a>
                            </div>
                        <?php else: ?>
                            <select name="group_id"
                                    class="w-full rounded-xl border border-yellow-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                                <option value="">-- Choose Group --</option>
                                <?php foreach ($audienceGroups as $ag): ?>
                                    <option value="<?= (int)$ag['id'] ?>" <?= (int)$ag['id'] === $presetGroupId ? 'selected' : '' ?>>
                                        <?= e((string)$ag['group_name']) ?> (<?= number_format((int)$ag['active_members']) ?> active members)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Target Campaign</label>
                        <?php if (!empty($campaigns)): ?>
                        <select name="campaign_id"
                                class="w-full rounded-xl border border-yellow-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                            <option value="">-- Create New Campaign --</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['campaign_name']) ?> (<?= e(ucfirst($c['status'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">New Campaign Name <span class="text-slate-400">(if not choosing existing above)</span></label>
                        <input type="text" name="new_campaign_name" placeholder="e.g. Hot Leads Blast May 2026"
                               class="w-full rounded-xl border border-yellow-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    </div>
                    <p class="text-xs font-bold text-yellow-800 bg-yellow-100 rounded-xl px-4 py-3">
                        Only <strong>active</strong> members are copied. Fresh tracking tokens are generated. No emails are sent automatically.
                    </p>
                </div>

                <!-- From Webinar Group -->
                <div id="mode-webinar-fields" class="<?= $initMode !== 'webinar_group' ? 'hidden' : '' ?> p-6 rounded-2xl bg-indigo-50 border border-indigo-200 space-y-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Select Webinar <span class="text-rose-500">*</span></label>
                        <?php if (empty($webinars)): ?>
                            <div class="text-sm font-bold text-indigo-700">No webinars found.</div>
                        <?php else: ?>
                            <select name="webinar_id"
                                    class="w-full rounded-xl border border-indigo-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400 transition">
                                <option value="">-- Choose Webinar --</option>
                                <?php foreach ($webinars as $w): ?>
                                    <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $presetWebinarId ? 'selected' : '' ?>>
                                        <?= e((string)$w['webinar_title']) ?> — <?= date('d M Y', strtotime((string)$w['start_datetime'])) ?> (<?= e(ucfirst((string)($w['status'] ?? 'active'))) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Target Campaign</label>
                        <?php if (!empty($campaigns)): ?>
                        <select name="campaign_id"
                                class="w-full rounded-xl border border-indigo-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400 transition">
                            <option value="">-- Create New Campaign --</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['campaign_name']) ?> (<?= e(ucfirst($c['status'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">New Campaign Name <span class="text-slate-400">(if not choosing existing above)</span></label>
                        <input type="text" name="new_campaign_name" placeholder="e.g. Webinar Registrants May 2026"
                               class="w-full rounded-xl border border-indigo-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400 transition">
                    </div>
                    <p class="text-xs font-bold text-indigo-800 bg-indigo-100 rounded-xl px-4 py-3">
                        Only registrants with <strong>consent</strong> are imported. Duplicates are skipped automatically. No emails are sent.
                    </p>
                </div>
            </div>

            <!-- File Upload (hidden in group/webinar_group mode) -->
            <div id="csv-upload-section" class="<?= in_array($initMode, ['group', 'webinar_group']) ? 'hidden' : '' ?> space-y-4">
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400">Step 3: Select CSV File</label>
                <div class="relative group">
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,text/plain,application/vnd.ms-excel" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                    <div id="dropzone" class="rounded-3xl border-2 border-dashed border-slate-200 bg-slate-50 p-10 text-center transition group-hover:border-yellow-400 group-hover:bg-yellow-50">
                        <div id="upload-icon">
                            <svg class="w-14 h-14 mx-auto text-slate-300 mb-4 group-hover:text-yellow-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <p id="file-name-display" class="text-lg font-black text-slate-900">Click to upload or drag and drop</p>
                        <p id="file-size-display" class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-2">Only .CSV files allowed (max 5MB)</p>
                    </div>
                </div>
                <div class="flex items-center justify-between text-xs font-black">
                    <span class="text-slate-400 uppercase tracking-tight">Required Headers: <span class="text-slate-600">email</span></span>
                    <a href="?sample=1" class="text-yellow-600 hover:text-yellow-700 flex items-center gap-1.5 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download Sample
                    </a>
                </div>
            </div>

            <!-- Submit -->
            <div class="pt-4">
                <button type="submit" id="submitBtn" class="w-full py-5 rounded-2xl bg-yellow-500 text-white font-black text-xl shadow-xl hover:bg-yellow-400 hover:-translate-y-1 transition-all active:translate-y-0 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                    Start Import
                </button>
            </div>
        </form>
    </div>

    <!-- Audience Groups shortcut -->
    <div class="mt-6 rounded-2xl border border-yellow-100 bg-yellow-50 p-5 flex items-center justify-between gap-4">
        <div>
            <div class="text-sm font-black text-yellow-900">Using Audience Groups?</div>
            <div class="text-xs font-bold text-yellow-700">Manage reusable contact groups, import from lead sources, and build targeted lists.</div>
        </div>
        <a href="/admin/email/audience-groups.php"
           class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition text-sm">
            Audience Groups →
        </a>
    </div>

    <!-- Instructions -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-8">
        <div class="rounded-3xl border border-slate-100 bg-slate-50 p-8">
            <h4 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2">
                <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                Quick Guide
            </h4>
            <ul class="space-y-4 text-sm text-slate-600 font-bold">
                <li class="flex gap-4">
                    <span class="flex-shrink-0 w-6 h-6 rounded-lg bg-white border border-slate-200 text-[10px] flex items-center justify-center font-black shadow-sm">1</span>
                    <span>First row must be headers.</span>
                </li>
                <li class="flex gap-4">
                    <span class="flex-shrink-0 w-6 h-6 rounded-lg bg-white border border-slate-200 text-[10px] flex items-center justify-center font-black shadow-sm">2</span>
                    <span>Mapping: <code class="bg-white px-2 py-0.5 rounded border border-slate-200 font-mono text-xs">email</code>, <code class="bg-white px-2 py-0.5 rounded border border-slate-200 font-mono text-xs">name</code>, <code class="bg-white px-2 py-0.5 rounded border border-slate-200 font-mono text-xs">phone</code>.</span>
                </li>
                <li class="flex gap-4">
                    <span class="flex-shrink-0 w-6 h-6 rounded-lg bg-white border border-slate-200 text-[10px] flex items-center justify-center font-black shadow-sm">3</span>
                    <span>Duplicate emails in the same campaign are skipped.</span>
                </li>
            </ul>
        </div>
        
        <div class="rounded-3xl border border-slate-100 bg-white p-8">
            <h4 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"/></svg>
                CSV Structure
            </h4>
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 font-mono text-[10px] text-slate-600 overflow-x-auto">
                <div>email,name,phone</div>
                <div>ali@test.com,Ali,0123456789</div>
                <div>siti@test.com,Siti,01122334455</div>
            </div>
            <p class="mt-4 text-[10px] font-bold text-slate-400 leading-relaxed italic">
                Tip: You can use "Email Address" or "Recipient Name" as headers too. We'll find them!
            </p>
        </div>
    </div>
</div>

<script>
(function() {
    const fileInput  = document.getElementById('csv_file');
    const nameDisplay = document.getElementById('file-name-display');
    const sizeDisplay = document.getElementById('file-size-display');
    const dropzone   = document.getElementById('dropzone');
    const importForm = document.getElementById('importForm');
    const submitBtn  = document.getElementById('submitBtn');

    // Mode switching
    window.setImportMode = function(mode) {
        const existing    = document.getElementById('mode-existing-fields');
        const newMode     = document.getElementById('mode-new-fields');
        const grpMode     = document.getElementById('mode-group-fields');
        const webinarMode = document.getElementById('mode-webinar-fields');
        const csvSect     = document.getElementById('csv-upload-section');

        existing    && existing.classList.add('hidden');
        newMode     && newMode.classList.add('hidden');
        grpMode     && grpMode.classList.add('hidden');
        webinarMode && webinarMode.classList.add('hidden');
        csvSect     && csvSect.classList.add('hidden');

        if (mode === 'existing') {
            existing && existing.classList.remove('hidden');
            csvSect  && csvSect.classList.remove('hidden');
        } else if (mode === 'new') {
            newMode  && newMode.classList.remove('hidden');
            csvSect  && csvSect.classList.remove('hidden');
        } else if (mode === 'group') {
            grpMode  && grpMode.classList.remove('hidden');
        } else if (mode === 'webinar_group') {
            webinarMode && webinarMode.classList.remove('hidden');
        }

        const noUpload = (mode === 'group' || mode === 'webinar_group');
        if (fileInput) fileInput.required = !noUpload;
        if (submitBtn) submitBtn.textContent = noUpload ? 'Copy to Campaign' : 'Start Import';
    };

    fileInput && fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            const file = this.files[0];
            nameDisplay.textContent = 'Selected: ' + file.name;
            nameDisplay.classList.add('text-yellow-600');
            let sizeStr = file.size + ' bytes';
            if (file.size > 1048576) sizeStr = (file.size / 1048576).toFixed(2) + ' MB';
            else if (file.size > 1024) sizeStr = (file.size / 1024).toFixed(2) + ' KB';
            sizeDisplay.textContent = 'Size: ' + sizeStr;
            sizeDisplay.classList.remove('text-slate-400');
            sizeDisplay.classList.add('text-slate-900');
            dropzone.classList.remove('bg-slate-50', 'border-slate-200');
            dropzone.classList.add('bg-yellow-50', 'border-yellow-400');
        } else {
            nameDisplay.textContent = 'Click to upload or drag and drop';
            nameDisplay.classList.remove('text-yellow-600');
            sizeDisplay.textContent = 'Only .CSV files allowed (max 5MB)';
            sizeDisplay.classList.add('text-slate-400');
            sizeDisplay.classList.remove('text-slate-900');
            dropzone.classList.add('bg-slate-50', 'border-slate-200');
            dropzone.classList.remove('bg-yellow-50', 'border-yellow-400');
        }
    });

    importForm && importForm.addEventListener('submit', function() {
        // Disable inputs inside hidden sections so duplicate field names don't
        // overwrite the active section's values in PHP $_POST.
        ['mode-existing-fields','mode-new-fields','mode-group-fields','mode-webinar-fields'].forEach(function(id) {
            var section = document.getElementById(id);
            if (section && section.classList.contains('hidden')) {
                section.querySelectorAll('input, select, textarea').forEach(function(el) {
                    el.disabled = true;
                });
            }
        });

        submitBtn.disabled = true;
        const modeVal = document.querySelector('input[name="mode"]:checked')?.value;
        const noUpload = (modeVal === 'group' || modeVal === 'webinar_group');
        submitBtn.textContent = noUpload ? 'Copying to Campaign…' : 'Uploading & Importing…';
        submitBtn.classList.add('opacity-70');
    });

    // Init correct button label and required state from PHP
    <?php if (in_array($initMode, ['group', 'webinar_group'])): ?>
    if (fileInput) fileInput.required = false;
    if (submitBtn) submitBtn.textContent = 'Copy to Campaign';
    <?php endif; ?>
})();
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
