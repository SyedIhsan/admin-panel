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

// 1. Load Campaign
$campaignId = (int)($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    header('Location: /admin/email/campaign-monitoring.php?error=invalid_id');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $campaignId);
$stmt->execute();
$campaign = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$campaign) {
    header('Location: /admin/email/campaign-monitoring.php?error=not_found');
    exit;
}

$successMsg = null;
$errorMsg = null;

// 2. Handle POST Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $subject      = trim((string)($_POST['subject'] ?? ''));
        $preheader    = trim((string)($_POST['preheader'] ?? ''));
        $emailBody    = trim((string)($_POST['email_body'] ?? ''));
        $buttonText   = trim((string)($_POST['button_text'] ?? ''));
        $buttonUrl    = trim((string)($_POST['button_url'] ?? ''));
        $closingText  = trim((string)($_POST['closing_text'] ?? ''));
        $brandName    = trim((string)($_POST['brand_name'] ?? ''));
        $supportEmail = trim((string)($_POST['support_email'] ?? ''));
        $footerNote   = trim((string)($_POST['footer_note'] ?? ''));

        if ($subject === '') throw new Exception("Subject is required.");
        if ($emailBody === '') throw new Exception("Email body is required.");
        if ($buttonUrl !== '' && !preg_match('~^https?://~i', $buttonUrl)) {
            throw new Exception("Button URL must start with http:// or https://");
        }

        $sql = "UPDATE `email_campaigns` SET 
                subject = ?, preheader = ?, email_body = ?, 
                button_text = ?, button_url = ?, closing_text = ?, 
                brand_name = ?, support_email = ?, footer_note = ?,
                content_updated_at = NOW(), content_updated_by = ?
                WHERE id = ?";
        
        $upt = $conn->prepare($sql);
        $adminLabel = campaign_safe_admin_label();
        $upt->bind_param(
            'ssssssssssi', 
            $subject, $preheader, $emailBody, 
            $buttonText, $buttonUrl, $closingText, 
            $brandName, $supportEmail, $footerNote, 
            $adminLabel, $campaignId
        );
        
        if ($upt->execute()) {
            $successMsg = "Campaign content saved successfully.";
            // Reload campaign data
            $stmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $campaign = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            throw new Exception("Failed to save content: " . $upt->error);
        }
        $upt->close();

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// 3. Build initial preview URL
$previewBase = '/admin/email/campaign-preview.php';
$initialPreviewSrc = $previewBase . '?' . http_build_query([
    'campaign_name' => (string)($campaign['campaign_name'] ?? ''),
    'subject'       => (string)($campaign['subject'] ?: 'Update from Demo Company'),
    'preheader'     => (string)($campaign['preheader'] ?: 'A quick update from Demo Company.'),
    'email_body'    => (string)($campaign['email_body'] ?: "Hi {{name}},\n\nThis is a campaign email from Demo Company."),
    'button_text'   => (string)($campaign['button_text'] ?: 'Visit Demo'),
    'button_url'    => (string)($campaign['button_url'] ?: 'https://demo.local'),
    'closing_text'  => (string)($campaign['closing_text'] ?: "Best regards,\nDemo Team"),
    'brand_name' => 'Demo',
    'support_email' => (string)($campaign['support_email'] ?: 'support@demo.local'),
    'footer_note'   => (string)($campaign['footer_note'] ?: 'You are receiving this email because you joined our mailing list.'),
]);

// Page metadata
$title = 'Campaign Content - Demo Admin';
$pageTitle = 'Edit Campaign Content';
$pageDesc = 'Customize the email message and appearance for this campaign.';

// Helper for HTML escaping
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8">
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="/admin/email/campaign-details.php?id=<?= $campaignId ?>" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 transition mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Details
            </a>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Campaign Content</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500"><?= e($campaign['campaign_name']) ?></p>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-4 flex items-center gap-4 text-emerald-800 font-bold">
            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <?= e($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="mb-8 rounded-2xl border border-rose-200 bg-rose-50 px-6 py-4 flex items-center gap-4 text-rose-800 font-bold">
            <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= e($errorMsg) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Form Side -->
        <div class="lg:col-span-7">
            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Subject Line <span class="text-rose-500">*</span></label>
                            <input type="text" name="subject" value="<?= e((string)($campaign['subject'] ?: 'Update from Demo Company')) ?>" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>

                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Preheader</label>
                            <input type="text" name="preheader" value="<?= e((string)($campaign['preheader'] ?: 'A quick update from Demo Company.')) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>

                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Brand Name</label>
                            <input type="text" name="brand_name" value="<?= e((string)($campaign['brand_name'] ?: 'Demo')) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Email Body <span class="text-rose-500">*</span></label>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach(['name','email','phone','campaign_name'] as $tk): ?>
                                    <button type="button" onclick="insertToken('{{<?= $tk ?>}}')" class="text-[10px] px-2 py-0.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 font-bold transition">{{<?= $tk ?>}}</button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <textarea id="email_body" name="email_body" rows="10" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-700 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition"><?= e((string)($campaign['email_body'] ?: "Hi {{name}},\n\nThis is a campaign email from Demo Company.")) ?></textarea>
                        <p class="mt-2 text-[10px] font-semibold text-slate-400">Unsubscribe and manage preferences links are automatically included in the email footer.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Button Text</label>
                            <input type="text" name="button_text" value="<?= e((string)($campaign['button_text'] ?: 'Visit Demo')) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Button URL</label>
                            <input type="text" name="button_url" value="<?= e((string)($campaign['button_url'] ?: 'https://demo.local')) ?>" placeholder="https://..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Closing Text</label>
                        <textarea name="closing_text" rows="3" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-700 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition"><?= e((string)($campaign['closing_text'] ?: "Best regards,\nDemo Team")) ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Support Email</label>
                            <input type="email" name="support_email" value="<?= e((string)($campaign['support_email'] ?: 'support@demo.local')) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Footer Note</label>
                            <input type="text" name="footer_note" value="<?= e((string)($campaign['footer_note'] ?: 'You are receiving this email because you joined our mailing list.')) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                        </div>
                    </div>

                    <div class="pt-4 flex gap-4">
                        <button type="submit" class="flex-1 py-4 rounded-2xl bg-yellow-500 text-white font-black text-lg shadow-xl hover:bg-yellow-400 hover:-translate-y-1 transition-all active:translate-y-0">
                            Save Content
                        </button>
                        <a href="/admin/email/campaign-details.php?id=<?= $campaignId ?>" class="px-8 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black text-lg hover:bg-slate-200 transition text-center">
                            Back
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Preview Side -->
        <div class="lg:col-span-5">
            <div class="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm">
                <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4">
                    <div class="text-sm font-black text-slate-900">Live Preview</div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-600">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-yellow-100 text-yellow-700">JD</span>
                        Jane Doe (Sample)
                    </div>
                </div>
                <div class="bg-slate-100 p-4">
                    <iframe
                        id="campaignPreviewFrame"
                        title="Email Preview"
                        class="h-[860px] w-full rounded-[20px] border border-slate-200 bg-[#09090b] shadow-sm"
                        src="<?= e($initialPreviewSrc) ?>"
                    ></iframe>
                </div>
                <div class="px-4 pb-4">
                    <p class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-center text-[10px] font-bold uppercase tracking-widest text-slate-500">
                        Tracking links and pixels are disabled in preview.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function insertToken(token) {
    const el = document.getElementById('email_body');
    if (!el) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    el.value = el.value.substring(0, start) + token + el.value.substring(end);
    el.focus();
    el.setSelectionRange(start + token.length, start + token.length);
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

(function () {
    const iframe = document.getElementById('campaignPreviewFrame');
    const form   = document.querySelector('form[method="POST"]');
    const previewBase = <?= json_encode($previewBase, JSON_UNESCAPED_SLASHES) ?>;
    const campaignName = <?= json_encode((string)($campaign['campaign_name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

    if (!iframe || !form) return;

    let timer = null;

    function buildPreviewUrl() {
        const fd = new FormData(form);
        const params = new URLSearchParams();
        params.set('campaign_name', campaignName);
        ['subject', 'preheader', 'email_body', 'button_text', 'button_url',
         'closing_text', 'brand_name', 'support_email', 'footer_note'].forEach(function (key) {
            params.set(key, String(fd.get(key) || ''));
        });
        params.set('_', String(Date.now()));
        return previewBase + '?' + params.toString();
    }

    function syncPreview() {
        iframe.src = buildPreviewUrl();
    }

    function debounceSync() {
        clearTimeout(timer);
        timer = setTimeout(syncPreview, 250);
    }

    form.addEventListener('input', debounceSync);
    form.addEventListener('change', debounceSync);
})();
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
