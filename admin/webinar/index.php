<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

/** @var mysqli $conn */

// ── Data Fetching ─────────────────────────────────────────────────────────────

// Stats
$statsSql = "
    SELECT 
        COUNT(*) as total_webinars,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_webinars,
        (SELECT COUNT(*) FROM sdc_webinar_registrations) as total_registrations,
        (SELECT created_at FROM sdc_webinar_registrations ORDER BY created_at DESC LIMIT 1) as latest_reg
    FROM sdc_webinars
";
$statsRes = $conn->query($statsSql);
$stats = $statsRes->fetch_assoc();

// Webinar List
$webinarsSql = "
    SELECT 
        w.id,
        w.webinar_title,
        w.start_datetime,
        w.end_datetime,
        w.status,
        w.created_at,
        w.poster_url,
        COALESCE(r.reg_count, 0) as reg_count
    FROM sdc_webinars w
    LEFT JOIN (
        SELECT webinar_id, COUNT(*) as reg_count
        FROM sdc_webinar_registrations
        GROUP BY webinar_id
    ) r ON r.webinar_id = w.id
    ORDER BY w.start_datetime DESC
";
$webinarsRes = $conn->query($webinarsSql);
$webinars = $webinarsRes->fetch_all(MYSQLI_ASSOC);

// Fetch reminder summaries grouped by webinar, type and status
$webinarIds = array_map(function($w){ return (int)$w['id']; }, $webinars);
$reminderSummaries = [];
if (!empty($webinarIds)) {
    $placeholders = implode(',', array_fill(0, count($webinarIds), '?'));
    $sql = "SELECT webinar_id, reminder_type, status, COUNT(*) as cnt FROM sdc_webinar_reminders WHERE webinar_id IN ($placeholders) GROUP BY webinar_id, reminder_type, status";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // bind params dynamically
        $types = str_repeat('i', count($webinarIds));
        $refs = [];
        $refs[] = & $types;
        foreach ($webinarIds as $k => $id) {
            $refs[] = & $webinarIds[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $wid = (int)$row['webinar_id'];
            $rtype = (string)$row['reminder_type'];
            $status = (string)$row['status'];
            $cnt = (int)$row['cnt'];
            if (!isset($reminderSummaries[$wid])) $reminderSummaries[$wid] = [];
            if (!isset($reminderSummaries[$wid][$rtype])) $reminderSummaries[$wid][$rtype] = [];
            $reminderSummaries[$wid][$rtype][$status] = $cnt;
        }
        $stmt->close();
    }
}

// ── Page Setup ────────────────────────────────────────────────────────────────

$pageTitle = "Webinar Management";
$pageDesc  = "Create webinars and monitor registrations.";
$addUrl    = "/admin/webinar/form.php";

$headerActionsHtmlDesktop = '
  <a href="' . h($addUrl) . '"
     class="hidden sm:inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
    Add Webinar
  </a>
';

$headerActionsHtmlMobile = '
  <a href="' . h($addUrl) . '"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
     aria-label="Add Webinar" title="Add Webinar">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
  </a>
';

include __DIR__ . "/../partials/header.php";
include __DIR__ . "/../partials/nav.php";

$successMsg = '';
$errorMsg = '';
$successType = $_GET['success'] ?? '';
$errorType = $_GET['error'] ?? '';

if ($successType === 'created') $successMsg = "Webinar created successfully!";
elseif ($successType === 'updated') $successMsg = "Webinar updated successfully!";
elseif ($successType === 'status_updated') $successMsg = "Webinar status updated!";
elseif ($successType === 'deleted') $successMsg = "Webinar deleted successfully!";

if ($errorType === 'update_failed') $errorMsg = "Failed to update webinar status.";
elseif ($errorType === 'delete_failed') $errorMsg = "Failed to delete webinar.";
elseif ($errorType === 'invalid_id') $errorMsg = "Invalid webinar ID.";
?>

<!-- Mobile page title -->
<div class="md:hidden mb-6">
  <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= h($pageTitle) ?></h1>
  <p class="mt-1 text-sm text-slate-400"><?= h($pageDesc) ?></p>
</div>

<?php if ($successMsg): ?>
    <div class="bg-emerald-50 border-l-4 border-emerald-400 p-4 mb-8">
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
    <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-8">
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

<!-- Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Total Webinars</p>
    <p class="text-2xl font-black text-slate-900"><?= number_format((float)($stats['total_webinars'] ?? 0)) ?></p>
  </div>
  <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Active Webinars</p>
    <p class="text-2xl font-black text-emerald-600"><?= number_format((float)($stats['active_webinars'] ?? 0)) ?></p>
  </div>
  <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Total Registrations</p>
    <p class="text-2xl font-black text-yellow-600"><?= number_format((float)($stats['total_registrations'] ?? 0)) ?></p>
  </div>
  <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Latest Registration</p>
    <p class="text-sm font-bold text-slate-600 mt-2"><?= $stats['latest_reg'] ? fmtDate($stats['latest_reg']) : 'No registrations' ?></p>
  </div>
</div>

<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
  <!-- Card header -->
  <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
    <div>
      <h2 class="text-base font-semibold text-slate-800">Webinars</h2>
      <p class="text-xs text-slate-400 mt-0.5"><?= count($webinars) ?> webinar<?= count($webinars) !== 1 ? "s" : "" ?></p>
    </div>
  </div>

  <?php if (count($webinars) === 0): ?>
    <div class="py-24 text-center">
      <p class="text-sm font-medium text-slate-500">No webinars yet</p>
      <p class="text-xs text-slate-400 mt-1 mb-5">Start by adding your first webinar.</p>
      <a href="<?= h($addUrl) ?>"
         class="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-600 hover:text-amber-700 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Create a webinar
      </a>
    </div>
  <?php else: ?>
    <!-- Desktop Table -->
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-left">
        <thead>
          <tr class="border-b border-slate-100">
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Webinar</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Date / Time</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Registrations</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Reminders</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Created At</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php foreach ($webinars as $w): 
            $isActive = strtolower((string)($w['status'] ?? '')) === 'active';
          ?>
            <tr class="group hover:bg-yellow-50/30 transition-colors duration-75">
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="shrink-0 w-10 h-10 rounded-lg overflow-hidden border border-slate-100">
                    <?php if ($w['poster_url']): ?>
                      <img src="<?= h($w['poster_url']) ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                      <div class="w-full h-full bg-slate-100 flex items-center justify-center text-slate-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-900 truncate max-w-[200px]"><?= h($w['webinar_title']) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">ID: <?= (int)$w['id'] ?></p>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <p class="text-sm font-semibold text-slate-900"><?= fmtDate($w['start_datetime']) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5"><?= date('H:i', strtotime($w['start_datetime'])) ?> – <?= date('H:i', strtotime($w['end_datetime'] ?? $w['start_datetime'])) ?></p>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-yellow-50 text-yellow-700 border border-yellow-100">
                  <?= number_format((float)$w['reg_count']) ?>
                </span>
              </td>
              <td class="px-6 py-4">
                <?php
                  $rs = $reminderSummaries[$w['id'] ] ?? [];
                  if (empty($rs)) {
                    echo '<span class="text-xs text-slate-400">No reminders yet</span>';
                  } else {
                    // types order
                    $types = ['reminder_24h' => '24h', 'reminder_1h' => '1h', 'reminder_start' => 'Start'];
                    echo '<div class="text-[12px] text-slate-700">';
                    foreach ($types as $k => $label) {
                      $sent = $rs[$k]['sent'] ?? 0;
                      $failed = $rs[$k]['failed'] ?? 0;
                      $pending = $rs[$k]['pending'] ?? 0;
                      if ($sent === 0 && $failed === 0 && $pending === 0) continue;
                      if ($pending > 0) {
                        echo '<div>' . h($label) . ': <span class="text-xs text-amber-600 font-semibold">' . $pending . ' pending</span></div>';
                      } else {
                        echo '<div>' . h($label) . ': <span class="text-xs text-emerald-600 font-semibold">' . $sent . ' sent</span>';
                        if ($failed > 0) echo ' / <span class="text-xs text-rose-600 font-semibold">' . $failed . ' failed</span>';
                        echo '</div>';
                      }
                    }
                    echo '</div>';
                  }
                ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-1.5">
                  <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? "bg-emerald-400" : "bg-rose-400" ?>"></span>
                  <span class="text-xs font-medium text-slate-700 capitalize"><?= h((string)$w['status']) ?></span>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <p class="text-xs text-slate-400"><?= fmtDate($w['created_at']) ?></p>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center justify-end gap-0.5">
                  <a href="/admin/webinar/registrations.php?webinar_id=<?= (int)$w['id'] ?>"
                     class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 transition-colors"
                     title="View Registrations" aria-label="View Registrations">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                  </a>
                  <a href="/admin/webinar/form.php?id=<?= (int)$w['id'] ?>"
                     class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors"
                     title="Edit Webinar" aria-label="Edit Webinar">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </a>
                  <a href="/admin/webinar/reminder-logs.php?webinar_id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-purple-600 hover:text-purple-700 transition-colors" title="View Reminder Logs" aria-label="View Reminder Logs">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z" />
                    </svg>
                  </a>
                  <a href="/admin/webinar/marketing.php?webinar_id=<?= (int)$w['id'] ?>"
                     class="inline-flex items-center justify-center p-1.5 text-emerald-500 hover:text-emerald-600 transition-colors"
                     title="Marketing Automation" aria-label="Marketing Automation">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                  </a>
                  <a href="/admin/email/campaign-import.php?mode=webinar_group&webinar_id=<?= (int)$w['id'] ?>"
                     class="inline-flex items-center justify-center p-1.5 text-blue-500 hover:text-blue-600 transition-colors"
                     title="Create Campaign from Webinar Registrants" aria-label="Create Campaign from Webinar Registrants">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                  </a>
                  <form action="actions.php" method="POST" class="inline"
                        data-confirm="<?= $isActive ? 'Deactivate webinar?' : 'Activate webinar?' ?>"
                        data-confirm-desc="<?= $isActive ? 'This will hide the webinar from the registration page.' : 'This will make the webinar visible on the registration page.' ?>"
                        data-confirm-ok="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                    <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                    <button type="submit" class="inline-flex items-center justify-center p-1.5 transition-colors <?= $isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>" title="<?= $isActive ? 'Deactivate Webinar' : 'Activate Webinar' ?>" aria-label="<?= $isActive ? 'Deactivate Webinar' : 'Activate Webinar' ?>">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                      </svg>
                    </button>
                  </form>
                  <form action="actions.php" method="POST" class="inline"
                        data-confirm="Delete webinar?"
                        data-confirm-desc="The webinar will be removed. Existing registrations will NOT be deleted."
                        data-confirm-ok="Delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                    <button type="submit" class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors" title="Delete Webinar" aria-label="Delete Webinar">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
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
      <?php foreach ($webinars as $w): 
        $isActive = strtolower((string)($w['status'] ?? '')) === 'active';
      ?>
        <div class="px-4 py-4">
          <div class="flex items-start gap-3">
            <div class="shrink-0 w-10 h-10 rounded-lg overflow-hidden border border-slate-100">
              <?php if ($w['poster_url']): ?>
                <img src="<?= h($w['poster_url']) ?>" alt="" class="w-full h-full object-cover">
              <?php else: ?>
                <div class="w-full h-full bg-slate-100 flex items-center justify-center text-slate-400">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </div>
              <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-semibold text-slate-900 leading-snug"><?= h($w['webinar_title']) ?></p>
                <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                  <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? "bg-emerald-400" : "bg-rose-400" ?>"></span>
                  <span class="text-xs font-medium text-slate-500 capitalize"><?= h((string)$w['status']) ?></span>
                </div>
              </div>
              <p class="text-[11px] text-slate-400 mt-1"><?= fmtDate($w['start_datetime']) ?></p>
              <div class="mt-3 flex items-center justify-between">
                <div>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-yellow-50 text-yellow-700 border border-yellow-100">
                    <?= number_format((float)$w['reg_count']) ?> regs
                  </span>
                  <?php
                    $rs = $reminderSummaries[$w['id']] ?? [];
                    if (!empty($rs)) {
                      $short = [];
                      if (($rs['reminder_24h']['pending'] ?? 0) > 0) $short[] = '24h: ' . $rs['reminder_24h']['pending'] . 'p';
                      elseif (($rs['reminder_24h']['sent'] ?? 0) > 0) $short[] = '24h: ' . $rs['reminder_24h']['sent'] . 's';
                      if (($rs['reminder_1h']['pending'] ?? 0) > 0) $short[] = '1h: ' . $rs['reminder_1h']['pending'] . 'p';
                      elseif (($rs['reminder_1h']['sent'] ?? 0) > 0) $short[] = '1h: ' . $rs['reminder_1h']['sent'] . 's';
                      if (($rs['reminder_start']['pending'] ?? 0) > 0) $short[] = 'Start: ' . $rs['reminder_start']['pending'] . 'p';
                      elseif (($rs['reminder_start']['sent'] ?? 0) > 0) $short[] = 'Start: ' . $rs['reminder_start']['sent'] . 's';
                      if (!empty($short)) echo '<div class="text-[11px] text-slate-500 mt-1">' . h(implode(' • ', $short)) . '</div>';
                    }
                  ?>
                </div>
                <div class="flex items-center gap-0.5">
                  <a href="/admin/webinar/registrations.php?webinar_id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 transition-colors" title="View Registrations" aria-label="View Registrations"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg></a>
                  <a href="/admin/webinar/form.php?id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors" title="Edit Webinar" aria-label="Edit Webinar"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg></a>
                  <a href="/admin/webinar/reminder-logs.php?webinar_id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-purple-600 hover:text-purple-700 transition-colors" title="View Reminder Logs" aria-label="View Reminder Logs"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z" /></svg></a>
                  <a href="/admin/webinar/marketing.php?webinar_id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-emerald-500 hover:text-emerald-600 transition-colors" title="Marketing Automation" aria-label="Marketing Automation"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg></a>
                  <a href="/admin/email/campaign-import.php?mode=webinar_group&webinar_id=<?= (int)$w['id'] ?>" class="inline-flex items-center justify-center p-1.5 text-blue-500 hover:text-blue-600 transition-colors" title="Create Campaign from Webinar Registrants" aria-label="Create Campaign from Webinar Registrants"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></a>
                  <form action="actions.php" method="POST" class="inline"
                        data-confirm="<?= $isActive ? 'Deactivate webinar?' : 'Activate webinar?' ?>"
                        data-confirm-desc="<?= $isActive ? 'This will hide the webinar from the registration page.' : 'This will make the webinar visible on the registration page.' ?>"
                        data-confirm-ok="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                    <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                    <button type="submit" class="inline-flex items-center justify-center p-1.5 transition-colors <?= $isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>" title="<?= $isActive ? 'Deactivate Webinar' : 'Activate Webinar' ?>" aria-label="<?= $isActive ? 'Deactivate Webinar' : 'Activate Webinar' ?>">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                    </button>
                  </form>
                  <form action="actions.php" method="POST" class="inline"
                        data-confirm="Delete webinar?"
                        data-confirm-desc="The webinar will be removed. Existing registrations will NOT be deleted."
                        data-confirm-ok="Delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                    <button type="submit" class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors" title="Delete Webinar" aria-label="Delete Webinar">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . "/../partials/confirm-modal.php"; ?>
<?php include __DIR__ . "/../partials/footer.php"; ?>
