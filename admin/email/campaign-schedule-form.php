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

$editId   = (int)($_GET['id'] ?? 0);
$isEdit   = $editId > 0;
$schedule = null;
$errorMsg = null;

// Load schedule for edit mode
if ($isEdit) {
    $stmt = $conn->prepare(
        "SELECT s.*, c.campaign_name FROM `email_campaign_schedules` s
         INNER JOIN `email_campaigns` c ON c.id = s.campaign_id
         WHERE s.id = ? AND s.status = 'scheduled' LIMIT 1"
    );
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$schedule) {
        header('Location: /admin/email/campaign-schedules.php');
        exit;
    }
}

// Load campaigns for dropdown
$campaigns = [];
$campRes = $conn->query("SELECT id, campaign_name FROM `email_campaigns` ORDER BY campaign_name ASC");
if ($campRes) {
    while ($row = $campRes->fetch_assoc()) $campaigns[] = $row;
}

$presetCampaignId = (int)($_GET['campaign_id'] ?? 0);

// ── POST Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $formMode   = (string)($_POST['form_mode'] ?? 'single');
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $sendMode   = (string)($_POST['send_mode'] ?? 'pending');
        $batchSize  = max(1, min(500, (int)($_POST['batch_size'] ?? 100)));
        $maxPerRun  = max(1, min(1000, (int)($_POST['max_per_run'] ?? 100)));
        $timezone   = 'Asia/Kuala_Lumpur';
        $adminLabel = campaign_safe_admin_label();

        $allowedSendModes = ['pending', 'failed', 'pending_and_failed'];
        if (!in_array($sendMode, $allowedSendModes, true)) $sendMode = 'pending';
        if ($campaignId <= 0) throw new Exception('Please select a campaign.');

        if ($formMode === 'repeat') {
            // Weekday repeat mode
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate   = trim((string)($_POST['end_date']   ?? ''));
            $sendTime  = trim((string)($_POST['send_time']  ?? '09:00'));
            $weekdays  = array_map('intval', (array)($_POST['weekdays'] ?? []));
            $weekdays  = array_filter($weekdays, fn($d) => $d >= 1 && $d <= 7);

            if ($startDate === '' || $endDate === '') throw new Exception('Start date and end date are required.');
            if (empty($weekdays)) throw new Exception('Please select at least one weekday.');
            if ($startDate > $endDate) throw new Exception('Start date must be before end date.');
            if (strtotime($startDate) === false || strtotime($endDate) === false) throw new Exception('Invalid date format.');

            // Validate send time
            if (!preg_match('/^\d{1,2}:\d{2}$/', $sendTime)) $sendTime = '09:00';

            $results = campaign_create_schedules_for_weekdays(
                $conn, $campaignId, $startDate, $endDate,
                array_values($weekdays), $sendTime, $timezone,
                $sendMode, $batchSize, $maxPerRun, $adminLabel
            );

            $createdCount = count(array_filter($results, fn($r) => $r['ok']));
            if ($createdCount === 0) throw new Exception('No schedules were created. Check your date range and weekday selection.');

            header('Location: /admin/email/campaign-schedules.php?created=' . $createdCount . '&campaign_id=' . $campaignId);
            exit;

        } else {
            // Single date mode
            $scheduleName = trim((string)($_POST['schedule_name'] ?? ''));
            $schedDate    = trim((string)($_POST['scheduled_date'] ?? ''));
            $schedTime    = trim((string)($_POST['scheduled_time'] ?? '09:00'));

            if ($schedDate === '') throw new Exception('Scheduled date is required.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) throw new Exception('Invalid date format.');
            if (!preg_match('/^\d{1,2}:\d{2}$/', $schedTime)) $schedTime = '09:00';

            $scheduledAt = $schedDate . ' ' . $schedTime . ':00';

            if ($isEdit) {
                // Update existing schedule
                $stmt = $conn->prepare(
                    "UPDATE `email_campaign_schedules`
                     SET schedule_name=?, campaign_id=?, scheduled_at=?, timezone=?,
                         send_mode=?, batch_size=?, max_per_run=?, updated_at=NOW()
                     WHERE id=? AND status='scheduled'"
                );
                $stmt->bind_param('sisssiii',
                    $scheduleName, $campaignId, $scheduledAt, $timezone,
                    $sendMode, $batchSize, $maxPerRun, $editId
                );
                if (!$stmt->execute() || $stmt->affected_rows < 0) throw new Exception('Failed to update schedule.');
                $stmt->close();
                header('Location: /admin/email/campaign-schedules.php?saved=1');
                exit;
            } else {
                $r = campaign_create_schedule(
                    $conn, $campaignId, $scheduleName, $scheduledAt, $timezone,
                    $sendMode, $batchSize, $maxPerRun, $adminLabel
                );
                if ($r['id'] <= 0) throw new Exception('Failed to create schedule. Please try again.');
                header('Location: /admin/email/campaign-schedules.php?created=1&campaign_id=' . $campaignId);
                exit;
            }
        }

    } catch (Exception $ex) {
        $errorMsg = $ex->getMessage();
    }
}

// Defaults for form
$fCampaignId   = $isEdit ? (int)$schedule['campaign_id'] : $presetCampaignId;
$fScheduleName = $isEdit ? (string)($schedule['schedule_name'] ?? '') : '';
$fScheduledDate= $isEdit ? date('Y-m-d', strtotime((string)$schedule['scheduled_at'])) : date('Y-m-d', strtotime('+1 day'));
$fScheduledTime= $isEdit ? date('H:i', strtotime((string)$schedule['scheduled_at'])) : '09:00';
$fSendMode     = $isEdit ? (string)$schedule['send_mode'] : 'pending';
$fBatchSize    = $isEdit ? (int)$schedule['batch_size'] : 100;
$fMaxPerRun    = $isEdit ? (int)$schedule['max_per_run'] : 100;

$title     = ($isEdit ? 'Edit' : 'New') . ' Schedule - Demo Admin';
$pageTitle = $isEdit ? 'Edit Schedule' : 'New Schedule';
$pageDesc  = $isEdit ? 'Update the schedule date, time, or send settings.' : 'Schedule a campaign to send automatically on specific date(s).';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8 max-w-2xl">

    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
            <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
        </div>
        <a href="/admin/email/campaign-schedules.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Schedules
        </a>
    </div>

    <?php if ($errorMsg): ?>
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 mb-6 flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-bold text-rose-700"><?= e($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$isEdit): ?>
    <!-- Mode Tabs -->
    <div class="flex rounded-2xl bg-slate-100 p-1 mb-6">
        <button type="button" id="tab-single" onclick="setFormMode('single')"
                class="flex-1 py-2.5 rounded-xl text-sm font-black transition">
            Single Date
        </button>
        <button type="button" id="tab-repeat" onclick="setFormMode('repeat')"
                class="flex-1 py-2.5 rounded-xl text-sm font-black transition">
            Repeat (Weekdays)
        </button>
    </div>
    <?php endif; ?>

    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
        <form method="POST" id="scheduleForm" class="space-y-6">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="form_mode" id="formModeInput" value="single">

            <!-- Campaign -->
            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                    Campaign <span class="text-rose-500">*</span>
                </label>
                <?php if ($isEdit): ?>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                        <?= e((string)$schedule['campaign_name']) ?>
                    </div>
                    <input type="hidden" name="campaign_id" value="<?= $fCampaignId ?>">
                <?php else: ?>
                    <select name="campaign_id" required
                            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        <option value="">— Select campaign —</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $fCampaignId === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e((string)$c['campaign_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- ── SINGLE DATE FIELDS ── -->
            <div id="single-fields" class="space-y-6">

                <div>
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Schedule Name</label>
                    <input type="text" name="schedule_name" maxlength="190"
                           value="<?= e($fScheduleName) ?>"
                           placeholder="e.g. Monday Blast"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <p class="text-xs text-slate-400 font-semibold mt-1">Optional — auto-generated if blank.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                            Date <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="scheduled_date" id="scheduledDate"
                               value="<?= e($fScheduledDate) ?>"
                               min="<?= date('Y-m-d') ?>"
                               class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                            Time <span class="text-rose-500">*</span>
                        </label>
                        <input type="time" name="scheduled_time" id="scheduledTime"
                               value="<?= e($fScheduledTime) ?>"
                               class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    </div>
                </div>

            </div>

            <!-- ── REPEAT WEEKDAY FIELDS ── -->
            <div id="repeat-fields" class="space-y-6 hidden">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                            Start Date <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="start_date" id="startDate"
                               value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"
                               min="<?= date('Y-m-d') ?>"
                               class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                            End Date <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="end_date" id="endDate"
                               value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>"
                               min="<?= date('Y-m-d') ?>"
                               class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-3">
                        Weekdays <span class="text-rose-500">*</span>
                    </label>
                    <div class="grid grid-cols-4 gap-2">
                        <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n => $lbl): ?>
                        <label class="relative flex cursor-pointer">
                            <input type="checkbox" name="weekdays[]" value="<?= $n ?>" class="peer sr-only">
                            <span class="w-full py-2.5 rounded-xl border-2 border-slate-200 bg-white text-xs font-black text-slate-500 text-center
                                         peer-checked:border-yellow-400 peer-checked:bg-yellow-50 peer-checked:text-yellow-700
                                         hover:border-slate-300 transition select-none">
                                <?= $lbl ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-slate-400 font-semibold mt-2">One schedule row is created per matching day in the date range.</p>
                </div>

                <div>
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                        Send Time <span class="text-rose-500">*</span>
                    </label>
                    <input type="time" name="send_time" value="09:00"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <p class="text-xs text-slate-400 font-semibold mt-1">Applied to all selected weekdays. Timezone: Asia/Kuala Lumpur (UTC+8).</p>
                </div>

            </div>

            <!-- ── SHARED SETTINGS ── -->

            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Send Mode</label>
                <select name="send_mode"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <option value="pending" <?= $fSendMode === 'pending' ? 'selected' : '' ?>>Pending only (not yet sent)</option>
                    <option value="failed" <?= $fSendMode === 'failed' ? 'selected' : '' ?>>Failed only (retry failed)</option>
                    <option value="pending_and_failed" <?= $fSendMode === 'pending_and_failed' ? 'selected' : '' ?>>Pending + Failed (all unsent)</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                        Batch Size
                        <span class="normal-case text-slate-300 font-semibold ml-1">(1–500)</span>
                    </label>
                    <input type="number" name="batch_size" min="1" max="500"
                           value="<?= $fBatchSize ?>"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <p class="text-xs text-slate-400 font-semibold mt-1">Emails sent per cron tick.</p>
                </div>
                <div>
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                        Max Per Run
                        <span class="normal-case text-slate-300 font-semibold ml-1">(1–1000)</span>
                    </label>
                    <input type="number" name="max_per_run" min="1" max="1000"
                           value="<?= $fMaxPerRun ?>"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <p class="text-xs text-slate-400 font-semibold mt-1">Hard cap for this job.</p>
                </div>
            </div>

            <!-- Timezone note -->
            <div class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-xs text-slate-500 font-semibold">
                <span class="font-black text-slate-600">Timezone:</span> Asia/Kuala Lumpur (UTC+8) — all times stored and triggered in MYT.
            </div>

            <div class="pt-2">
                <button type="submit" id="submitBtn"
                        class="w-full py-4 rounded-2xl bg-yellow-500 text-white font-black text-lg shadow-xl hover:bg-yellow-400 hover:-translate-y-0.5 transition-all">
                    <?= $isEdit ? 'Save Changes' : 'Create Schedule' ?>
                </button>
            </div>

        </form>
    </div>

</div>

<?php if (!$isEdit): ?>
<script>
const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition';

function setFormMode(mode) {
    document.getElementById('formModeInput').value = mode;

    const single = document.getElementById('single-fields');
    const repeat = document.getElementById('repeat-fields');
    const tabS   = document.getElementById('tab-single');
    const tabR   = document.getElementById('tab-repeat');
    const btn    = document.getElementById('submitBtn');

    if (mode === 'repeat') {
        single.classList.add('hidden');
        repeat.classList.remove('hidden');

        tabS.classList.remove('bg-white', 'shadow', 'text-slate-900');
        tabS.classList.add('text-slate-500');
        tabR.classList.add('bg-white', 'shadow', 'text-slate-900');
        tabR.classList.remove('text-slate-500');

        // Remove required from single fields, add to repeat fields
        document.getElementById('scheduledDate').removeAttribute('required');
        document.getElementById('startDate').setAttribute('required', '');
        document.getElementById('endDate').setAttribute('required', '');

        btn.textContent = 'Create Schedules';
    } else {
        single.classList.remove('hidden');
        repeat.classList.add('hidden');

        tabR.classList.remove('bg-white', 'shadow', 'text-slate-900');
        tabR.classList.add('text-slate-500');
        tabS.classList.add('bg-white', 'shadow', 'text-slate-900');
        tabS.classList.remove('text-slate-500');

        document.getElementById('scheduledDate').setAttribute('required', '');
        document.getElementById('startDate').removeAttribute('required');
        document.getElementById('endDate').removeAttribute('required');

        btn.textContent = 'Create Schedule';
    }
}

// Initialize
setFormMode('single');
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
