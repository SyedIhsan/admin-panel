<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$conn = getBillingConn();
if (!$conn) {
    header("Location: /admin/webinar/index.php?error=db");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function mktg_status_badge(string $s): string {
    $map = [
        'draft'    => 'bg-amber-50 text-amber-700 border border-amber-100',
        'active'   => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
        'inactive' => 'bg-slate-50 text-slate-500 border border-slate-100',
    ];
    return $map[$s] ?? 'bg-slate-50 text-slate-500 border border-slate-100';
}

function bind_mktg_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $bind = [$types];
    foreach ($params as $k => $_) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

// ── Filters ───────────────────────────────────────────────────────────────────

$filterWebinarId = (int)($_GET['webinar_id'] ?? 0);
$filterStatus    = trim((string)($_GET['status'] ?? ''));

// ── Webinar List for Dropdown ─────────────────────────────────────────────────

$webinarList = [];
$wRes = $conn->query("SELECT id, webinar_title, start_datetime FROM sdc_webinars ORDER BY start_datetime DESC");
if ($wRes instanceof mysqli_result) {
    while ($r = $wRes->fetch_assoc()) {
        $webinarList[] = $r;
    }
}

// ── Main Query ────────────────────────────────────────────────────────────────

$baseSql = "
    SELECT
        e.id,
        e.webinar_id,
        e.title,
        e.delay_value,
        e.delay_unit,
        e.send_before_webinar_only,
        e.apply_to_existing,
        e.status,
        e.sort_order,
        e.created_at,
        w.webinar_title,
        COALESCE(lc.total_queued,  0) AS total_queued,
        COALESCE(lc.pending_count, 0) AS pending_count,
        COALESCE(lc.sent_count,    0) AS sent_count,
        COALESCE(lc.failed_count,  0) AS failed_count,
        COALESCE(lc.skipped_count, 0) AS skipped_count
    FROM sdc_webinar_marketing_emails e
    LEFT JOIN sdc_webinars w ON w.id = e.webinar_id
    LEFT JOIN (
        SELECT
            marketing_email_id,
            COUNT(*) AS total_queued,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'sent'    THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped_count
        FROM sdc_webinar_marketing_logs
        GROUP BY marketing_email_id
    ) lc ON lc.marketing_email_id = e.id
";

$where  = [];
$params = [];
$types  = '';

if ($filterWebinarId > 0) {
    $where[]  = '(e.webinar_id = ? OR e.webinar_id IS NULL)';
    $params[] = $filterWebinarId;
    $types   .= 'i';
}
if ($filterStatus !== '') {
    $where[]  = 'e.status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$fullSql  = $baseSql . ' ' . $whereSql . ' ORDER BY e.sort_order ASC, e.id ASC';

$rows = [];
if ($types === '') {
    $res = $conn->query($fullSql);
    if ($res instanceof mysqli_result) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $stmt = $conn->prepare($fullSql);
    if ($stmt) {
        bind_mktg_params($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
}

// ── Flash Messages ────────────────────────────────────────────────────────────

$successType = $_GET['success'] ?? '';
$errorType   = $_GET['error']   ?? '';
$successMsg  = '';
$errorMsg    = '';

if ($successType === 'created')        $successMsg = 'Marketing automation created successfully.';
elseif ($successType === 'updated')    $successMsg = 'Marketing automation updated successfully.';
elseif ($successType === 'status_updated') $successMsg = 'Status updated.';
elseif ($successType === 'deleted')    $successMsg = 'Automation deleted.';

if ($errorType === 'invalid_id')   $errorMsg = 'Invalid automation ID.';
elseif ($errorType === 'not_found') $errorMsg = 'Automation not found.';
elseif ($errorType === 'update_failed') $errorMsg = 'Failed to update automation.';
elseif ($errorType === 'delete_failed') $errorMsg = 'Failed to delete automation.';
elseif ($errorType === 'db')       $errorMsg = 'Database connection error.';

// ── Page Setup ────────────────────────────────────────────────────────────────

$pageTitle = 'Webinar Marketing Automation';
$pageDesc  = 'Schedule pre-event marketing emails for webinar registrants.';

$addParams = $filterWebinarId > 0 ? '?webinar_id=' . $filterWebinarId : '';

$headerActionsHtmlDesktop = '
  <a href="/admin/webinar/marketing-form.php' . h($addParams) . '"
     class="hidden sm:inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2 rounded-2xl font-black hover:bg-emerald-700 transition shadow-md shadow-emerald-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
    Add Marketing Email
  </a>
';

$headerActionsHtmlMobile = '
  <a href="/admin/webinar/marketing-form.php' . h($addParams) . '"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-emerald-600 text-white shadow-md shadow-emerald-100"
     aria-label="Add Marketing Email" title="Add Marketing Email">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
  </a>
';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/nav.php';
?>

<div class="space-y-8">
  <!-- Mobile page title -->
  <div class="md:hidden">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= h($pageTitle) ?></h1>
    <p class="mt-1 text-sm text-slate-400"><?= h($pageDesc) ?></p>
  </div>

  <?php if ($successMsg): ?>
    <div class="bg-emerald-50 border-l-4 border-emerald-400 p-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <p class="text-sm text-emerald-700 font-bold"><?= h($successMsg) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
    <div class="bg-red-50 border-l-4 border-red-400 p-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <p class="text-sm text-red-700 font-bold"><?= h($errorMsg) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Back link -->
  <div>
    <a href="/admin/webinar/index.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
      Back to Webinar Management
    </a>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
      <h2 class="text-base font-semibold text-slate-800">Filters</h2>
      <p class="text-xs text-slate-400 mt-0.5">Filter automations by webinar or status.</p>
      <?php if ($filterWebinarId > 0): ?>
        <p class="text-xs text-slate-500 mt-1">Showing automations for selected webinar and global automations.</p>
      <?php endif; ?>
    </div>
    <form method="GET" class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Webinar</label>
        <select name="webinar_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All Webinars</option>
          <?php foreach ($webinarList as $webinar): ?>
            <option value="<?= (int)$webinar['id'] ?>" <?= $filterWebinarId === (int)$webinar['id'] ? 'selected' : '' ?>>
              ID <?= (int)$webinar['id'] ?> — <?= h((string)$webinar['webinar_title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Status</label>
        <select name="status" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All</option>
          <option value="draft"    <?= $filterStatus === 'draft'    ? 'selected' : '' ?>>Draft</option>
          <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="flex items-end gap-3">
        <button type="submit" class="inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2.5 rounded-2xl font-black hover:bg-yellow-600 transition shadow-sm shadow-yellow-100">
          Filter
        </button>
        <a href="/admin/webinar/marketing.php" class="inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2.5 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
          Reset
        </a>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold text-slate-800">Marketing Automations</h2>
        <p class="text-xs text-slate-400 mt-0.5"><?= count($rows) ?> automation<?= count($rows) !== 1 ? 's' : '' ?></p>
      </div>
      <a href="/admin/webinar/marketing-form.php<?= h($addParams) ?>"
         class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-600 hover:text-emerald-700 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add
      </a>
    </div>

    <?php if (empty($rows)): ?>
      <div class="py-24 text-center">
        <p class="text-sm font-medium text-slate-500">No marketing automations found.</p>
        <p class="text-xs text-slate-400 mt-1 mb-5">Create your first automation to get started.</p>
        <a href="/admin/webinar/marketing-form.php<?= h($addParams) ?>"
           class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          Add Marketing Email
        </a>
      </div>
    <?php else: ?>
      <!-- Desktop Table -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="border-b border-slate-100">
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Title</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Webinar Scope</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Delay</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Before Webinar</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-center">Pending</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-center">Sent</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-center">Failed</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-center">Skipped</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Created</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            <?php foreach ($rows as $m):
              $isActive = ((string)$m['status']) === 'active';
            ?>
              <tr class="group hover:bg-yellow-50/30 transition-colors duration-75">
                <td class="px-6 py-4">
                  <p class="text-sm font-semibold text-slate-900 max-w-[200px] truncate"><?= h((string)$m['title']) ?></p>
                  <p class="text-[10px] text-slate-400 mt-0.5">ID: <?= (int)$m['id'] ?> · Order: <?= (int)$m['sort_order'] ?></p>
                </td>
                <td class="px-6 py-4">
                  <?php if ($m['webinar_id'] === null): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">All Webinars</span>
                  <?php else: ?>
                    <p class="text-xs font-semibold text-slate-700">ID <?= (int)$m['webinar_id'] ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5 max-w-[140px] truncate"><?= h((string)($m['webinar_title'] ?? 'Unknown')) ?></p>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-semibold text-slate-900"><?= (int)$m['delay_value'] ?> <?= h((string)$m['delay_unit']) ?></span>
                  <p class="text-[10px] text-slate-400 mt-0.5">after registration</p>
                  <?php if ((int)$m['apply_to_existing'] === 1): ?>
                    <span class="inline-flex items-center mt-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-blue-50 text-blue-600 border border-blue-100">Existing + new</span>
                  <?php else: ?>
                    <span class="inline-flex items-center mt-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-slate-50 text-slate-400 border border-slate-100">New only</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php if ((int)$m['send_before_webinar_only'] === 1): ?>
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                      Before webinar only
                    </span>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">Not restricted</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= mktg_status_badge((string)$m['status']) ?>">
                    <?= h((string)$m['status']) ?>
                  </span>
                </td>
                <td class="px-6 py-4 text-center">
                  <?php if ((int)$m['pending_count'] > 0): ?>
                    <span class="text-xs font-bold text-amber-600"><?= number_format((int)$m['pending_count']) ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-center">
                  <?php if ((int)$m['sent_count'] > 0): ?>
                    <span class="text-xs font-bold text-emerald-600"><?= number_format((int)$m['sent_count']) ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-center">
                  <?php if ((int)$m['failed_count'] > 0): ?>
                    <span class="text-xs font-bold text-rose-600"><?= number_format((int)$m['failed_count']) ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-center">
                  <?php if ((int)$m['skipped_count'] > 0): ?>
                    <span class="text-xs font-bold text-slate-500"><?= number_format((int)$m['skipped_count']) ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="text-xs text-slate-400"><?= fmtDate((string)$m['created_at']) ?></p>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center justify-end gap-0.5">
                    <!-- Edit -->
                    <a href="/admin/webinar/marketing-form.php?id=<?= (int)$m['id'] ?>"
                       class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors"
                       title="Edit" aria-label="Edit">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </a>
                    <!-- Logs -->
                    <a href="/admin/webinar/marketing-logs.php?marketing_email_id=<?= (int)$m['id'] ?>"
                       class="inline-flex items-center justify-center p-1.5 text-purple-600 hover:text-purple-700 transition-colors"
                       title="View Logs" aria-label="View Logs">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"/>
                      </svg>
                    </a>
                    <!-- Toggle Status -->
                    <form action="marketing-actions.php" method="POST" class="inline"
                          data-confirm="<?= $isActive ? 'Deactivate automation?' : 'Activate automation?' ?>"
                          data-confirm-desc="<?= $isActive ? 'New pending emails for this automation will be stopped.' : 'This automation will resume sending emails.' ?>"
                          data-confirm-ok="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                      <button type="submit"
                              class="inline-flex items-center justify-center p-1.5 transition-colors <?= $isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>"
                              title="<?= $isActive ? 'Deactivate' : 'Activate' ?>"
                              aria-label="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                      </button>
                    </form>
                    <!-- Delete -->
                    <form action="marketing-actions.php" method="POST" class="inline"
                          data-confirm="Delete automation?"
                          data-confirm-desc="This will permanently delete the automation and cancel all pending emails. This action cannot be undone."
                          data-confirm-ok="Delete">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                      <button type="submit"
                              class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors"
                              title="Delete" aria-label="Delete">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile Cards -->
      <div class="md:hidden divide-y divide-slate-100">
        <?php foreach ($rows as $m):
          $isActive = ((string)$m['status']) === 'active';
        ?>
          <div class="px-4 py-4">
            <div class="flex items-start justify-between gap-2">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-900 leading-snug"><?= h((string)$m['title']) ?></p>
                <p class="text-[11px] text-slate-400 mt-1">
                  <?php if ($m['webinar_id'] === null): ?>
                    All Webinars
                  <?php else: ?>
                    ID <?= (int)$m['webinar_id'] ?> — <?= h((string)($m['webinar_title'] ?? 'Unknown')) ?>
                  <?php endif; ?>
                </p>
                <p class="text-[11px] text-slate-500 mt-1">
                  <?= (int)$m['delay_value'] ?> <?= h((string)$m['delay_unit']) ?> after registration
                  <?php if ((int)$m['send_before_webinar_only']): ?> · Before webinar only<?php endif; ?>
                  · <?= (int)$m['apply_to_existing'] === 1 ? 'Existing + new' : 'New only' ?>
                </p>
                <div class="mt-2 flex items-center gap-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= mktg_status_badge((string)$m['status']) ?>">
                    <?= h((string)$m['status']) ?>
                  </span>
                  <?php if ((int)$m['pending_count'] > 0): ?>
                    <span class="text-[10px] text-amber-600 font-bold"><?= (int)$m['pending_count'] ?> pending</span>
                  <?php endif; ?>
                  <?php if ((int)$m['sent_count'] > 0): ?>
                    <span class="text-[10px] text-emerald-600 font-bold"><?= (int)$m['sent_count'] ?> sent</span>
                  <?php endif; ?>
                  <?php if ((int)$m['failed_count'] > 0): ?>
                    <span class="text-[10px] text-rose-600 font-bold"><?= (int)$m['failed_count'] ?> failed</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="flex items-center gap-0.5 shrink-0 mt-0.5">
                <a href="/admin/webinar/marketing-form.php?id=<?= (int)$m['id'] ?>"
                   class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors"
                   title="Edit" aria-label="Edit">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </a>
                <a href="/admin/webinar/marketing-logs.php?marketing_email_id=<?= (int)$m['id'] ?>"
                   class="inline-flex items-center justify-center p-1.5 text-purple-600 hover:text-purple-700 transition-colors"
                   title="View Logs" aria-label="View Logs">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"/></svg>
                </a>
                <form action="marketing-actions.php" method="POST" class="inline"
                      data-confirm="<?= $isActive ? 'Deactivate automation?' : 'Activate automation?' ?>"
                      data-confirm-desc="<?= $isActive ? 'New pending emails for this automation will be stopped.' : 'This automation will resume sending emails.' ?>"
                      data-confirm-ok="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button type="submit" class="inline-flex items-center justify-center p-1.5 transition-colors <?= $isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>" title="<?= $isActive ? 'Deactivate' : 'Activate' ?>" aria-label="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                  </button>
                </form>
                <form action="marketing-actions.php" method="POST" class="inline"
                      data-confirm="Delete automation?"
                      data-confirm-desc="This will permanently delete the automation and cancel all pending emails. This action cannot be undone."
                      data-confirm-ok="Delete">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button type="submit" class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors" title="Delete" aria-label="Delete">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/confirm-modal.php'; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
