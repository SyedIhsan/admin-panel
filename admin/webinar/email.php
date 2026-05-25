<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

/** @var mysqli $conn */

$webinar_id = (int)($_GET['webinar_id'] ?? 0);
if ($webinar_id <= 0) {
    header("Location: /admin/webinar/index.php");
    exit;
}

// ── Fetch Webinar Details ───────────────────────────────────────────────────
$stmtW = $conn->prepare("
    SELECT w.*, COALESCE(r.total_participants, 0) as total_participants
    FROM sdc_webinars w
    LEFT JOIN (
        SELECT webinar_id, COUNT(*) as total_participants
        FROM sdc_webinar_registrations
        GROUP BY webinar_id
    ) r ON r.webinar_id = w.id
    WHERE w.id = ?
    LIMIT 1
");
$stmtW->bind_param("i", $webinar_id);
$stmtW->execute();
$webinar = $stmtW->get_result()->fetch_assoc();

if (!$webinar) {
    header("Location: /admin/webinar/index.php?error=not_found");
    exit;
}

$pageTitle = "Email Participants";
$pageDesc = "Send updates or Zoom details to registered participants.";

// ── Default Content ─────────────────────────────────────────────────────────
$defaultSubject = $webinar['email_subject'] ?: "Update for {webinar_title}";
$defaultBody = "Hi {name},\n\nThank you for registering for {webinar_title}.\n\nWebinar details:\nDate: {webinar_date}\nTime: {webinar_time}\n\nJoin link:\n{zoom_join_url}\n\nSee you there.\n\nRegards,\nDemo Team";

include __DIR__ . "/../partials/header.php";
include __DIR__ . "/../partials/nav.php";

$status = $_GET['status'] ?? '';
$attempted = (int)($_GET['attempted'] ?? 0);
$sent = (int)($_GET['sent'] ?? 0);
$skipped = (int)($_GET['skipped'] ?? 0);
$failed = (int)($_GET['failed'] ?? 0);
?>

<div class="max-w-5xl mx-auto space-y-8">
    <!-- Back Button -->
    <div class="mb-2">
        <a href="/admin/webinar/index.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Management
        </a>
    </div>

    <!-- Campaign Import Recommendation -->
    <div class="bg-blue-50 border border-blue-200 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <p class="text-sm font-black text-blue-900">Recommended: Use Campaign Import for tracked broadcasts</p>
            <p class="text-xs font-bold text-blue-600 mt-1">Import registrants into a campaign to get open tracking, click tracking, and full monitoring.</p>
        </div>
        <a href="../email/campaign-import.php?mode=webinar_group&webinar_id=<?= $webinar_id ?>"
           class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 text-white font-black hover:bg-blue-700 transition text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            Create Campaign from Webinar Registrants
        </a>
    </div>

    <?php if ($status === 'success'): ?>
        <div class="bg-emerald-50 border-l-4 border-emerald-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-emerald-700 font-bold">Email Blast Completed!</p>
                    <p class="text-xs text-emerald-600 mt-1">
                        Attempted: <?= $attempted ?> | Sent: <?= $sent ?> | Skipped: <?= $skipped ?> | Failed: <?= $failed ?>
                    </p>
                </div>
            </div>
        </div>
    <?php elseif ($status === 'test_sent'): ?>
        <div class="bg-indigo-50 border-l-4 border-indigo-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-indigo-700 font-bold">Test email sent successfully.</p>
                </div>
            </div>
        </div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
                </div>
                <div class="ml-3 text-sm text-red-700 font-bold">
                    <?= h($_GET['error_msg'] ?? 'An error occurred while sending email.') ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Composer Form -->
        <div class="lg:col-span-2 space-y-6">
            <form action="send-email.php" method="POST" id="emailForm" class="space-y-6">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="webinar_id" value="<?= $webinar_id ?>">

                <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                        <h2 class="text-lg font-bold text-slate-800">Message Composer</h2>
                    </div>
                    <div class="p-8 space-y-6">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Subject</label>
                            <input type="text" name="subject" value="<?= h($defaultSubject) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-bold text-slate-700">Message Body</label>
                                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">HTML Supported</span>
                            </div>
                            <textarea name="body" id="emailBody" rows="12" required
                                      class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-mono text-sm text-slate-800"><?= h($defaultBody) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Send Options -->
                <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Test Send -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">1. Send Test</h3>
                            <div class="flex gap-2">
                                <input type="email" name="test_recipient" placeholder="Enter test email..."
                                       class="flex-1 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-2 text-sm focus:bg-white outline-none transition font-semibold text-slate-800">
                                <button type="submit" name="send_mode" value="test"
                                        class="px-4 py-2 bg-slate-800 text-white rounded-2xl text-xs font-black hover:bg-slate-900 transition">
                                    Send Test
                                </button>
                            </div>
                            <p class="text-[10px] text-slate-400 font-bold italic">* Use this to verify layout and placeholders before sending to all.</p>
                        </div>

                        <!-- Bulk Send -->
                        <div class="space-y-4 border-l border-slate-100 pl-0 md:pl-8">
                            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">2. Bulk Send</h3>
                            <div class="flex items-start gap-3">
                                <input type="checkbox" name="confirm_bulk" id="confirm_bulk" value="1" class="mt-1 w-4 h-4 text-yellow-500 border-slate-300 rounded focus:ring-yellow-500">
                                <label for="confirm_bulk" class="text-xs font-bold text-slate-600 leading-relaxed cursor-pointer">
                                    I understand this will send email to all <strong><?= $webinar['total_participants'] ?></strong> registered participants.
                                </label>
                            </div>
                            <button type="submit" name="send_mode" value="bulk" id="bulkSendBtn" disabled
                                    class="w-full py-3 bg-yellow-500 text-white rounded-2xl font-black shadow-lg shadow-yellow-100 hover:bg-yellow-600 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                Send to All Participants (<?= $webinar['total_participants'] ?>)
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Sidebar Info -->
        <div class="space-y-6">
            <!-- Webinar Details Card -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden p-6">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Webinar Context</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Title</p>
                        <p class="text-sm font-bold text-slate-800 leading-tight"><?= h($webinar['webinar_title']) ?></p>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <p class="text-[10px] font-bold text-slate-400 uppercase">Date</p>
                            <p class="text-sm font-bold text-slate-800"><?= date('d M Y', strtotime($webinar['start_datetime'])) ?></p>
                        </div>
                        <div class="flex-1">
                            <p class="text-[10px] font-bold text-slate-400 uppercase">Time</p>
                            <p class="text-sm font-bold text-slate-800"><?= date('H:i', strtotime($webinar['start_datetime'])) ?></p>
                        </div>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Status</p>
                        <p class="text-sm font-bold text-slate-800"><?= h(ucfirst($webinar['status'] ?? 'active')) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Zoom Link</p>
                        <p class="text-[11px] font-mono text-slate-500 break-all bg-slate-50 p-2 rounded-lg mt-1"><?= h($webinar['zoom_join_url'] ?: 'No link provided') ?></p>
                    </div>
                </div>
            </div>

            <!-- Placeholder Helper Card -->
            <div class="bg-slate-900 rounded-3xl shadow-sm overflow-hidden p-6 text-white">
                <h3 class="text-xs font-black text-amber-400 uppercase tracking-widest mb-4">Placeholders</h3>
                <p class="text-[11px] text-slate-400 mb-4 font-bold">Click a chip to insert into message body:</p>
                <div class="flex flex-wrap gap-2">
                    <?php
                    $placeholders = ['{name}', '{email}', '{phone}', '{webinar_title}', '{webinar_date}', '{webinar_time}', '{zoom_join_url}'];
                    foreach ($placeholders as $ph):
                    ?>
                        <button type="button" onclick="insertAtCursor('<?= $ph ?>')"
                                class="px-2.5 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-[11px] font-mono font-bold transition-all">
                            <?= $ph ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function insertAtCursor(text) {
    const el = document.getElementById('emailBody');
    const start = el.selectionStart;
    const end = el.selectionEnd;
    el.value = el.value.substring(0, start) + text + el.value.substring(end);
    el.focus();
    el.selectionStart = el.selectionEnd = start + text.length;
}

document.getElementById('confirm_bulk').addEventListener('change', function(e) {
    document.getElementById('bulkSendBtn').disabled = !e.target.checked;
});

document.getElementById('emailForm').addEventListener('submit', function(e) {
    const mode = e.submitter.value;
    if (mode === 'bulk') {
        e.preventDefault();
        sdcConfirm(
            'Send to ALL participants?',
            'This will immediately send the email to every registered participant. This cannot be undone.',
            'Send Now',
            () => { e.target.dataset.sdcConfirmPass = '1'; e.target.requestSubmit(e.submitter); }
        );
    }
});
</script>

<?php include __DIR__ . "/../partials/confirm-modal.php"; ?>
<?php include __DIR__ . "/../partials/footer.php"; ?>
