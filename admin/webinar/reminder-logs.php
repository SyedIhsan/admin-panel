<?php
require_once __DIR__ . '/_init.php';

$conn = getBillingConn();
if (!$conn) {
    header('Location: /admin/webinar/index.php?error=db');
    exit;
}

$webinarId = isset($_GET['webinar_id']) ? (int)$_GET['webinar_id'] : 0;
$reminderType = isset($_GET['reminder_type']) ? trim((string)$_GET['reminder_type']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$webinars = [];
$selectedWebinarLabel = '';

function bind_stmt_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => $_) {
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function status_badge(string $s): string {
  $s = (string)$s;
  $map = [
    'pending' => 'bg-amber-50 text-amber-700 border border-amber-100',
    'sent' => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
    'failed' => 'bg-rose-50 text-rose-700 border border-rose-100',
    'skipped' => 'bg-slate-50 text-slate-700 border border-slate-100'
  ];
  return $map[$s] ?? 'bg-slate-50 text-slate-500 border border-slate-100';
}

function type_label(string $t): string {
  $map = [
    'reminder_24h' => '24h Before',
    'reminder_1h' => '1h Before',
    'reminder_start' => 'Webinar Time'
  ];
  return $map[$t] ?? h($t);
}

$where = [];
$params = [];
$types = '';

if ($webinarId > 0) {
    $where[] = 'r.webinar_id = ?';
    $params[] = $webinarId;
    $types .= 'i';
}
if ($reminderType !== '') {
    $where[] = 'r.reminder_type = ?';
    $params[] = $reminderType;
    $types .= 's';
}
if ($status !== '') {
    $where[] = 'r.status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(r.email LIKE ? OR reg.name LIKE ?)';
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'ss';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$webinarListSql = "
    SELECT id, webinar_title, start_datetime, status
    FROM sdc_webinars
    ORDER BY start_datetime DESC, id DESC
";
$webinarListRes = $conn->query($webinarListSql);
if ($webinarListRes instanceof mysqli_result) {
    while ($row = $webinarListRes->fetch_assoc()) {
        $webinars[] = $row;
        if ($webinarId > 0 && (int)$row['id'] === $webinarId) {
            $selectedWebinarLabel = 'ID ' . (int)$row['id'] . ' — ' . (string)$row['webinar_title'];
        }
    }
} else {
    error_log('[WEBINAR_REMINDER_LOGS] Failed to load webinar list: ' . $conn->error);
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM sdc_webinar_reminders r
    LEFT JOIN sdc_webinars w ON w.id = r.webinar_id
    LEFT JOIN sdc_webinar_registrations reg ON reg.id = r.registration_id
    $whereSql
";

$total = 0;
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    error_log('[WEBINAR_REMINDER_LOGS] Failed to prepare count query: ' . $conn->error);
} else {
    $countParams = $params;
    bind_stmt_params($countStmt, $types, $countParams);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : [];
    $total = (int)($countRow['total'] ?? 0);
    $countStmt->close();
}

$listSql = "
    SELECT
        r.id,
        r.webinar_id,
        r.registration_id,
        r.email,
        r.reminder_type,
        r.due_at,
        r.sent_at,
        r.status,
        r.error_message,
        r.created_at,
        w.webinar_title,
        reg.name AS registrant_name
    FROM sdc_webinar_reminders r
    LEFT JOIN sdc_webinars w ON w.id = r.webinar_id
    LEFT JOIN sdc_webinar_registrations reg ON reg.id = r.registration_id
    $whereSql
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
";

$rows = [];
$listStmt = $conn->prepare($listSql);
if (!$listStmt) {
    error_log('[WEBINAR_REMINDER_LOGS] Failed to prepare list query: ' . $conn->error);
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;
    bind_stmt_params($listStmt, $listTypes, $listParams);
    $listStmt->execute();
    $listResult = $listStmt->get_result();
    while ($row = $listResult ? $listResult->fetch_assoc() : null) {
        $rows[] = $row;
    }
    $listStmt->close();
}

?>

<?php
$pageTitle = 'Webinar Reminder Logs';
$pageDesc = 'Monitor automated webinar reminder emails.';
$headerActionsHtmlDesktop = '
  <a href="/admin/webinar/index.php"
     class="hidden sm:inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back to Webinar Management
  </a>
';
$headerActionsHtmlMobile = '
  <a href="/admin/webinar/index.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-white text-slate-700 border border-slate-200 shadow-sm"
     aria-label="Back to Webinar Management" title="Back to Webinar Management">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
  </a>
';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/nav.php';
?>

<div class="space-y-8">
  <div class="md:hidden">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= h($pageTitle) ?></h1>
    <p class="mt-1 text-sm text-slate-400"><?= h($pageDesc) ?></p>
  </div>

  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
      <h2 class="text-base font-semibold text-slate-800">Filters</h2>
      <p class="text-xs text-slate-400 mt-0.5">Filter reminder logs by webinar, type, status, or recipient.</p>
      <?php if ($selectedWebinarLabel !== ''): ?>
        <p class="text-xs text-slate-500 mt-1">Selected Webinar: <?= h($selectedWebinarLabel) ?></p>
      <?php endif; ?>
    </div>
    <form method="GET" class="p-6 grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Webinar</label>
        <select name="webinar_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All Webinars</option>
          <?php foreach ($webinars as $webinar): ?>
            <?php $webinarDate = !empty($webinar['start_datetime']) ? date('d M Y, h:i A', strtotime((string)$webinar['start_datetime'])) : ''; ?>
            <option value="<?= (int)$webinar['id'] ?>" <?= $webinarId === (int)$webinar['id'] ? 'selected' : '' ?>>
              ID <?= (int)$webinar['id'] ?> — <?= h((string)$webinar['webinar_title']) ?> — <?= h($webinarDate) ?> — <?= h((string)$webinar['status']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Reminder Type</label>
        <select name="reminder_type" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All</option>
          <option value="reminder_24h" <?= $reminderType === 'reminder_24h' ? 'selected' : '' ?>>24h Before</option>
          <option value="reminder_1h" <?= $reminderType === 'reminder_1h' ? 'selected' : '' ?>>1h Before</option>
          <option value="reminder_start" <?= $reminderType === 'reminder_start' ? 'selected' : '' ?>>Webinar Time</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Status</label>
        <select name="status" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All</option>
          <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>pending</option>
          <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>sent</option>
          <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>failed</option>
          <option value="skipped" <?= $status === 'skipped' ? 'selected' : '' ?>>skipped</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Search</label>
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Email or name" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100" />
      </div>
      <div class="md:col-span-4 flex flex-wrap gap-3 pt-2">
        <button type="submit" class="inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2.5 rounded-2xl font-black hover:bg-yellow-600 transition shadow-sm shadow-yellow-100">
          Filter
        </button>
        <a href="/admin/webinar/reminder-logs.php" class="inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2.5 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
          Reset
        </a>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold text-slate-800">Reminder Logs</h2>
        <p class="text-xs text-slate-400 mt-0.5"><?= number_format((int)$total) ?> log<?= (int)$total === 1 ? '' : 's' ?></p>
      </div>
    </div>

    <?php if (empty($rows)): ?>
      <div class="py-24 text-center">
        <p class="text-sm font-medium text-slate-500">No reminder logs found.</p>
        <p class="text-xs text-slate-400 mt-1">Try adjusting the filters or check back after the cron runs.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="border-b border-slate-100">
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Webinar</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Recipient</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Type</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Due At</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Sent At</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Error</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Created</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            <?php foreach ($rows as $r): ?>
              <tr class="group hover:bg-yellow-50/30 transition-colors duration-75">
                <td class="px-6 py-4">
                  <p class="text-sm font-semibold text-slate-900"><?= h((string)($r['webinar_title'] ?? 'N/A')) ?></p>
                  <p class="text-[11px] text-slate-400 mt-0.5">ID: <?= (int)$r['webinar_id'] ?></p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-semibold text-slate-900"><?= h((string)($r['registrant_name'] ?? '')) ?></p>
                  <p class="text-[11px] text-slate-400 mt-0.5"><?= h((string)$r['email']) ?></p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-50 text-slate-700 border border-slate-100">
                    <?= h(type_label((string)$r['reminder_type'])) ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="text-sm font-semibold text-slate-900"><?= h((string)$r['due_at']) ?></p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= status_badge((string)$r['status']) ?>">
                    <?= h((string)$r['status']) ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="text-sm font-semibold text-slate-900"><?= h((string)$r['sent_at']) ?></p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-700 max-w-[320px] truncate" title="<?= h((string)($r['error_message'] ?? '')) ?>">
                    <?= h(mb_strimwidth((string)($r['error_message'] ?? ''), 0, 180, '...')) ?>
                  </p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="text-sm text-slate-400"><?= h((string)$r['created_at']) ?></p>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php
      $totalPages = (int)ceil($total / $perPage);
      if ($totalPages > 1):
    ?>
      <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-center gap-2 flex-wrap">
        <?php
          $baseParams = $_GET;
          for ($p = 1; $p <= $totalPages; $p++):
            $baseParams['page'] = $p;
        ?>
          <a href="<?= h('/admin/webinar/reminder-logs.php?' . http_build_query($baseParams)) ?>" class="inline-flex items-center px-3 py-1.5 rounded-2xl text-sm font-bold transition <?= $p === $page ? 'bg-slate-900 text-white' : 'bg-slate-50 text-slate-700 hover:bg-slate-100' ?>">
            <?= $p ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
