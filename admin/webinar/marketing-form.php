<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$conn = getBillingConn();
if (!$conn) {
    header("Location: /admin/webinar/marketing.php?error=db");
    exit;
}

$id     = (int)($_REQUEST['id'] ?? 0);
$isEdit = $id > 0;

// ── Webinar List for Dropdown ─────────────────────────────────────────────────

$webinarList = [];
$wRes = $conn->query("SELECT id, webinar_title, start_datetime FROM sdc_webinars ORDER BY start_datetime DESC");
if ($wRes instanceof mysqli_result) {
    while ($r = $wRes->fetch_assoc()) {
        $webinarList[] = $r;
    }
}

// ── Initialize Form Defaults ──────────────────────────────────────────────────

$fWebinarId = (int)($_GET['webinar_id'] ?? 0);
$fTitle     = '';
$fSubject   = '';
$fBodyHtml  = '';
$fDelayValue      = 1;
$fDelayUnit       = 'days';
$fSendBefore      = 1;
$fApplyToExisting = 0;
$fStatus          = 'draft';
$fSortOrder       = 0;
$errorMsg         = '';

// ── Edit Mode: Load from DB ───────────────────────────────────────────────────

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $conn->prepare("SELECT * FROM sdc_webinar_marketing_emails WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        header("Location: /admin/webinar/marketing.php?error=not_found");
        exit;
    }

    $fWebinarId  = (int)($row['webinar_id'] ?? 0);
    $fTitle      = (string)($row['title']      ?? '');
    $fSubject    = (string)($row['subject']     ?? '');
    $fBodyHtml   = (string)($row['body_html']   ?? '');
    $fDelayValue = (int)($row['delay_value']    ?? 1);
    $fDelayUnit  = (string)($row['delay_unit']  ?? 'days');
    $fSendBefore      = (int)($row['send_before_webinar_only'] ?? 1);
    $fApplyToExisting = (int)($row['apply_to_existing']       ?? 0);
    $fStatus          = (string)($row['status']      ?? 'draft');
    $fSortOrder       = (int)($row['sort_order']     ?? 0);
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $fWebinarId  = (int)($_POST['webinar_id']  ?? 0);
    $fTitle      = trim((string)($_POST['title']     ?? ''));
    $fSubject    = trim((string)($_POST['subject']    ?? ''));
    $fBodyHtml   = trim((string)($_POST['body_html']  ?? ''));
    $fDelayValue = (int)($_POST['delay_value'] ?? 1);
    $fDelayUnit  = trim((string)($_POST['delay_unit'] ?? 'days'));
    $fSendBefore      = isset($_POST['send_before_webinar_only']) ? 1 : 0;
    $fApplyToExisting = isset($_POST['apply_to_existing'])       ? 1 : 0;
    $fStatus          = trim((string)($_POST['status']    ?? 'draft'));
    $fSortOrder       = (int)($_POST['sort_order']  ?? 0);

    // Validate
    $errors = [];
    if ($fTitle === '')   $errors[] = 'Title is required.';
    if ($fSubject === '') $errors[] = 'Subject is required.';
    if ($fBodyHtml === '') $errors[] = 'Body is required.';
    if ($fDelayValue <= 0) $errors[] = 'Delay must be greater than 0.';
    if (!in_array($fDelayUnit, ['hours', 'days', 'weeks'], true)) $errors[] = 'Invalid delay unit.';
    if (!in_array($fStatus, ['draft', 'active', 'inactive'], true)) $errors[] = 'Invalid status.';

    if ($fWebinarId > 0) {
        $wCheck = $conn->prepare("SELECT id FROM sdc_webinars WHERE id = ?");
        $wCheck->bind_param("i", $fWebinarId);
        $wCheck->execute();
        if (!$wCheck->get_result()->fetch_assoc()) {
            $errors[] = 'Selected webinar does not exist.';
        }
        $wCheck->close();
    }

    if (empty($errors)) {
        $webinarIdSave = ($fWebinarId === 0) ? null : $fWebinarId;
        $ok = false;

        if ($isEdit) {
            if ($webinarIdSave === null) {
                $stmt = $conn->prepare("UPDATE sdc_webinar_marketing_emails SET webinar_id = NULL, title = ?, subject = ?, body_html = ?, delay_value = ?, delay_unit = ?, send_before_webinar_only = ?, apply_to_existing = ?, status = ?, sort_order = ? WHERE id = ?");
                $stmt->bind_param("sssisiisii", $fTitle, $fSubject, $fBodyHtml, $fDelayValue, $fDelayUnit, $fSendBefore, $fApplyToExisting, $fStatus, $fSortOrder, $id);
            } else {
                $stmt = $conn->prepare("UPDATE sdc_webinar_marketing_emails SET webinar_id = ?, title = ?, subject = ?, body_html = ?, delay_value = ?, delay_unit = ?, send_before_webinar_only = ?, apply_to_existing = ?, status = ?, sort_order = ? WHERE id = ?");
                $stmt->bind_param("isssisiisii", $webinarIdSave, $fTitle, $fSubject, $fBodyHtml, $fDelayValue, $fDelayUnit, $fSendBefore, $fApplyToExisting, $fStatus, $fSortOrder, $id);
            }
        } else {
            if ($webinarIdSave === null) {
                $stmt = $conn->prepare("INSERT INTO sdc_webinar_marketing_emails (webinar_id, title, subject, body_html, delay_value, delay_unit, send_before_webinar_only, apply_to_existing, status, sort_order) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisiisi", $fTitle, $fSubject, $fBodyHtml, $fDelayValue, $fDelayUnit, $fSendBefore, $fApplyToExisting, $fStatus, $fSortOrder);
            } else {
                $stmt = $conn->prepare("INSERT INTO sdc_webinar_marketing_emails (webinar_id, title, subject, body_html, delay_value, delay_unit, send_before_webinar_only, apply_to_existing, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssisiisi", $webinarIdSave, $fTitle, $fSubject, $fBodyHtml, $fDelayValue, $fDelayUnit, $fSendBefore, $fApplyToExisting, $fStatus, $fSortOrder);
            }
        }

        if ($stmt->execute()) {
            $ok = true;
        } else {
            $errorMsg = 'Database error. Please try again.';
        }
        $stmt->close();

        if ($ok) {
            $qs = ($isEdit ? 'updated' : 'created');
            $redirectUrl = '/admin/webinar/marketing.php?success=' . $qs;
            if ($webinarIdSave !== null) {
                $redirectUrl .= '&webinar_id=' . (int)$webinarIdSave;
            }
            header("Location: $redirectUrl");
            exit;
        }
    } else {
        $errorMsg = implode(' ', $errors);
    }
}

// ── Page Setup ────────────────────────────────────────────────────────────────

$pageTitle = $isEdit ? 'Edit Marketing Automation' : 'Add Marketing Automation';
$pageDesc  = $isEdit ? 'Update the automation email template.' : 'Create a new automated email for webinar registrants.';

$backUrl = $fWebinarId > 0
    ? '/admin/webinar/marketing.php?webinar_id=' . $fWebinarId
    : '/admin/webinar/marketing.php';

$headerActionsHtmlDesktop = '
  <a href="' . $backUrl . '"
     class="hidden sm:inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back
  </a>
';
$headerActionsHtmlMobile = '
  <a href="' . $backUrl . '"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-white text-slate-700 border border-slate-200 shadow-sm"
     aria-label="Back" title="Back">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
  </a>
';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/nav.php';
?>

<div class="max-w-5xl mx-auto space-y-8">

  <!-- Mobile page title -->
  <div class="md:hidden">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= h($pageTitle) ?></h1>
    <p class="mt-1 text-sm text-slate-400"><?= h($pageDesc) ?></p>
  </div>

  <!-- Back link -->
  <div>
    <a href="<?= h($backUrl) ?>" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Marketing Automation
    </a>
  </div>

  <?php if ($errorMsg !== ''): ?>
    <div class="bg-red-50 border-l-4 border-red-400 p-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
          </svg>
        </div>
        <div class="ml-3">
          <p class="text-sm text-red-700 font-bold"><?= h($errorMsg) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- Form -->
    <div class="lg:col-span-2 space-y-6">
      <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>

        <!-- Scope & Timing Card -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
          <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
            <h2 class="text-lg font-bold text-slate-800">Scope &amp; Timing</h2>
          </div>
          <div class="p-8 space-y-6">

            <!-- Webinar Scope -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Webinar Scope</label>
              <select name="webinar_id"
                      class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition text-slate-800 text-sm">
                <option value="0" <?= $fWebinarId === 0 ? 'selected' : '' ?>>All Webinars / Global Automation</option>
                <?php foreach ($webinarList as $webinar): ?>
                  <option value="<?= (int)$webinar['id'] ?>" <?= $fWebinarId === (int)$webinar['id'] ? 'selected' : '' ?>>
                    ID <?= (int)$webinar['id'] ?> — <?= h((string)$webinar['webinar_title']) ?>
                    (<?= date('d M Y', strtotime((string)$webinar['start_datetime'])) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="mt-1.5 text-xs text-slate-400">Choose a specific webinar, or leave as global to apply to all webinars.</p>
            </div>

            <!-- Delay -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Send Delay After Registration</label>
              <div class="flex gap-3">
                <input type="number" name="delay_value" value="<?= (int)$fDelayValue ?>" min="1" max="999" required
                       class="w-28 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800 text-sm">
                <select name="delay_unit"
                        class="flex-1 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition text-slate-800 text-sm">
                  <option value="hours" <?= $fDelayUnit === 'hours' ? 'selected' : '' ?>>hours after registration</option>
                  <option value="days"  <?= $fDelayUnit === 'days'  ? 'selected' : '' ?>>days after registration</option>
                  <option value="weeks" <?= $fDelayUnit === 'weeks' ? 'selected' : '' ?>>weeks after registration</option>
                </select>
              </div>
            </div>

            <!-- Send Before Webinar Only -->
            <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-100 rounded-2xl">
              <input type="checkbox" name="send_before_webinar_only" id="send_before_webinar_only" value="1"
                     <?= $fSendBefore ? 'checked' : '' ?>
                     class="mt-0.5 w-4 h-4 text-yellow-500 border-slate-300 rounded focus:ring-yellow-500">
              <div>
                <label for="send_before_webinar_only" class="text-sm font-bold text-amber-900 cursor-pointer">
                  Only send before webinar start time
                </label>
                <p class="text-xs text-amber-700 mt-1 leading-relaxed">
                  If registration time + delay &ge; webinar start, this email will be <strong>skipped</strong>.
                  Late registrants will not receive emails that would have been due in the past.
                </p>
              </div>
            </div>

            <!-- Apply to Existing Registrants -->
            <div class="flex items-start gap-3 p-4 bg-slate-50 border border-slate-200 rounded-2xl">
              <input type="checkbox" name="apply_to_existing" id="apply_to_existing" value="1"
                     <?= $fApplyToExisting ? 'checked' : '' ?>
                     class="mt-0.5 w-4 h-4 text-yellow-500 border-slate-300 rounded focus:ring-yellow-500">
              <div>
                <label for="apply_to_existing" class="text-sm font-bold text-slate-800 cursor-pointer">
                  Apply to existing registrants
                </label>
                <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                  If enabled, the cron backfill will queue this automation for people who registered
                  <strong>before</strong> this automation was created.
                  If disabled (default), only new registrations after activation will be queued.
                  The timing rule still applies in both cases.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Email Content Card -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
          <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
            <h2 class="text-lg font-bold text-slate-800">Email Content</h2>
          </div>
          <div class="p-8 space-y-6">

            <!-- Title (internal label) -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Automation Title <span class="text-slate-400 font-normal">(internal label)</span></label>
              <input type="text" name="title" value="<?= h($fTitle) ?>" required placeholder="e.g. 2-Day Pre-Webinar Education Email"
                     class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800 text-sm">
            </div>

            <!-- Subject -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Email Subject</label>
              <input type="text" name="subject" value="<?= h($fSubject) ?>" required placeholder="e.g. Before the webinar: what to prepare"
                     class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800 text-sm">
            </div>

            <!-- Body -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-bold text-slate-700">Email Body</label>
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">HTML Supported</span>
              </div>

              <!-- Placeholder helper -->
              <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                <?php foreach (['{name}', '{email}', '{webinar_title}', '{webinar_date}', '{webinar_time}', '{zoom_join_url}'] as $ph): ?>
                  <button type="button" onclick="insertAtCursor('<?= h($ph) ?>')"
                          class="rounded bg-slate-100 px-2 py-1 font-mono text-slate-700 hover:bg-yellow-50 hover:text-yellow-700 transition-colors">
                    <?= h($ph) ?>
                  </button>
                <?php endforeach; ?>
              </div>

              <textarea name="body_html" id="emailBody" rows="16" required
                        class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-mono text-sm text-slate-800"><?= h($fBodyHtml) ?></textarea>
              <p class="mt-1.5 text-xs text-slate-400">Use HTML for formatting. Click a placeholder above to insert it at cursor position.</p>
            </div>

          </div>
        </div>

        <!-- Settings Card -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
          <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
            <h2 class="text-lg font-bold text-slate-800">Settings</h2>
          </div>
          <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Status -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Status</label>
              <select name="status"
                      class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition text-slate-800 text-sm">
                <option value="draft"    <?= $fStatus === 'draft'    ? 'selected' : '' ?>>Draft — not processed by cron</option>
                <option value="active"   <?= $fStatus === 'active'   ? 'selected' : '' ?>>Active — cron will queue &amp; send</option>
                <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive — paused</option>
              </select>
            </div>
            <!-- Sort Order -->
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Sort Order</label>
              <input type="number" name="sort_order" value="<?= (int)$fSortOrder ?>" min="0"
                     class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800 text-sm">
              <p class="mt-1.5 text-xs text-slate-400">Lower numbers appear first in the list.</p>
            </div>
          </div>
        </div>

        <!-- Submit -->
        <div class="flex items-center gap-4">
          <button type="submit"
                  class="inline-flex items-center gap-2 bg-yellow-500 text-white px-8 py-3 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <?= $isEdit ? 'Save Changes' : 'Create Automation' ?>
          </button>
          <a href="<?= h($backUrl) ?>"
             class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition">
            Cancel
          </a>
        </div>
      </form>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">

      <!-- Timing Rule Notice -->
      <div class="bg-amber-50 border border-amber-200 rounded-3xl p-6">
        <h3 class="text-xs font-black text-amber-700 uppercase tracking-widest mb-3">Timing Rule</h3>
        <p class="text-sm text-amber-900 leading-relaxed font-medium">
          This email will only be sent if:
        </p>
        <p class="mt-2 text-xs font-mono bg-amber-100 border border-amber-200 rounded-xl p-3 text-amber-800 leading-relaxed">
          registration_time<br>+ delay<br>&lt; webinar_start
        </p>
        <p class="mt-3 text-xs text-amber-700 leading-relaxed">
          If the due time is after or equal to the webinar start, the email will be <strong>skipped</strong>. This prevents sending irrelevant emails after the event starts.
        </p>
        <p class="mt-3 text-xs text-amber-600 font-bold">
          This enforcement happens in Phase 9D (cron worker).
        </p>
      </div>

      <!-- Tips -->
      <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3">Tips</h3>
        <ul class="space-y-2 text-xs text-slate-600 leading-relaxed">
          <li><span class="font-bold text-slate-800">Draft</span> — template saved, cron ignores it.</li>
          <li><span class="font-bold text-slate-800">Active</span> — cron will queue emails on new registrations (Phase 9D).</li>
          <li><span class="font-bold text-slate-800">Global scope</span> — automation applies to every webinar.</li>
          <li><span class="font-bold text-slate-800">Specific scope</span> — only runs for that webinar's registrants.</li>
          <li><span class="font-bold text-slate-800">Sort order</span> — sequences run in order (1, 2, 3 …). Use 0 for no preference.</li>
        </ul>
      </div>

    </div>
  </div>
</div>

<script>
function insertAtCursor(text) {
    const el = document.getElementById('emailBody');
    const start = el.selectionStart;
    const end   = el.selectionEnd;
    el.value = el.value.substring(0, start) + text + el.value.substring(end);
    el.focus();
    el.selectionStart = el.selectionEnd = start + text.length;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
