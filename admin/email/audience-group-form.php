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

$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$group  = null;
$errorMsg = null;
$successMsg = null;

if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM `email_audience_groups` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$group) { header('Location: /admin/email/audience-groups.php'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();

        $groupName   = trim((string)($_POST['group_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $sourceType  = $_POST['source_type'] ?? 'manual';
        $allowed     = ['manual', 'csv_import', 'lead_table', 'mixed'];
        if (!in_array($sourceType, $allowed, true)) $sourceType = 'manual';

        if ($groupName === '') throw new Exception("Group name is required.");

        $adminLabel = campaign_safe_admin_label();

        if ($isEdit) {
            $stmt = $conn->prepare(
                "UPDATE `email_audience_groups` SET group_name=?, description=?, source_type=?, updated_at=NOW() WHERE id=?"
            );
            $stmt->bind_param('sssi', $groupName, $description, $sourceType, $editId);
            if (!$stmt->execute()) throw new Exception("Failed to update group.");
            $stmt->close();
            header('Location: /admin/email/audience-group-detail.php?id=' . $editId . '&saved=1');
            exit;
        } else {
            $uid = campaign_generate_unique_group_uid($conn);
            $stmt = $conn->prepare(
                "INSERT INTO `email_audience_groups` (group_uid, group_name, description, source_type, created_by) VALUES (?,?,?,?,?)"
            );
            $stmt->bind_param('sssss', $uid, $groupName, $description, $sourceType, $adminLabel);
            if (!$stmt->execute()) throw new Exception("Failed to create group.");
            $newId = (int)$conn->insert_id;
            $stmt->close();
            header('Location: /admin/email/audience-group-detail.php?id=' . $newId . '&created=1');
            exit;
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

$title     = ($isEdit ? 'Edit' : 'New') . ' Audience Group - Demo Admin';
$pageTitle = $isEdit ? 'Edit Audience Group' : 'New Audience Group';
$pageDesc  = $isEdit ? 'Update group name and description.' : 'Create a new reusable contact group.';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8 max-w-2xl">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= e($pageTitle) ?></h1>
            <p class="mt-2 text-sm font-semibold text-slate-500"><?= e($pageDesc) ?></p>
        </div>
        <a href="/admin/email/audience-groups.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </a>
    </div>

    <?php if ($errorMsg): ?>
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 mb-6 flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-bold text-rose-700"><?= e($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">
                    Group Name <span class="text-rose-500">*</span>
                </label>
                <input type="text" name="group_name" required maxlength="190"
                       value="<?= e((string)($group['group_name'] ?? '')) ?>"
                       placeholder="e.g. Hot Leads"
                       class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Description</label>
                <textarea name="description" maxlength="1000" rows="3"
                          placeholder="Optional notes about this group…"
                          class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition resize-none"
                ><?= e((string)($group['description'] ?? '')) ?></textarea>
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Source Type</label>
                <select name="source_type"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:ring-4 focus:ring-yellow-100 focus:border-yellow-400 transition">
                    <?php foreach (['manual' => 'Manual', 'csv_import' => 'CSV Import', 'lead_table' => 'Lead Table', 'mixed' => 'Mixed'] as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= ($group['source_type'] ?? 'manual') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4">
                <button type="submit"
                        class="w-full py-4 rounded-2xl bg-yellow-500 text-white font-black text-lg shadow-xl hover:bg-yellow-400 hover:-translate-y-0.5 transition-all">
                    <?= $isEdit ? 'Save Changes' : 'Create Group' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
