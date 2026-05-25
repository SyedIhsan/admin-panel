<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$conn = getBillingConn();
if (!$conn) {
    header("Location: /admin/webinar/marketing.php?error=db");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function mlog_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $bind = [$types];
    foreach ($params as $k => $_) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function mlog_status_badge(string $s): string {
    $map = [
        'pending' => 'bg-amber-50 text-amber-700 border border-amber-100',
        'sent'    => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
        'failed'  => 'bg-rose-50 text-rose-700 border border-rose-100',
        'skipped' => 'bg-slate-50 text-slate-500 border border-slate-100',
    ];
    return $map[$s] ?? 'bg-slate-50 text-slate-500 border border-slate-100';
}

// ── Filters ───────────────────────────────────────────────────────────────────

$filterWebinarId    = (int)($_GET['webinar_id']         ?? 0);
$filterMarketingId  = (int)($_GET['marketing_email_id'] ?? 0);
$filterStatus       = trim((string)($_GET['status']     ?? ''));
$filterSearch       = trim((string)($_GET['q']          ?? ''));
$page               = max(1, (int)($_GET['page']        ?? 1));
$perPage            = 50;
$offset             = ($page - 1) * $perPage;

// ── Webinar List for Dropdown ─────────────────────────────────────────────────

$webinarList = [];
$wRes = $conn->query("SELECT id, webinar_title, start_datetime FROM sdc_webinars ORDER BY start_datetime DESC");
if ($wRes instanceof mysqli_result) {
    while ($r = $wRes->fetch_assoc()) {
        $webinarList[] = $r;
    }
}

// ── Marketing Email List for Dropdown ────────────────────────────────────────

$marketingEmailList = [];
$meRes = $conn->query("SELECT id, title, webinar_id FROM sdc_webinar_marketing_emails ORDER BY sort_order ASC, id ASC");
if ($meRes instanceof mysqli_result) {
    while ($r = $meRes->fetch_assoc()) {
        $marketingEmailList[] = $r;
    }
}

// ── Dynamic WHERE ─────────────────────────────────────────────────────────────

$where  = [];
$params = [];
$types  = '';

if ($filterWebinarId > 0) {
    $where[]  = 'l.webinar_id = ?';
    $params[] = $filterWebinarId;
    $types   .= 'i';
}
if ($filterMarketingId > 0) {
    $where[]  = 'l.marketing_email_id = ?';
    $params[] = $filterMarketingId;
    $types   .= 'i';
}
if ($filterStatus !== '') {
    $where[]  = 'l.status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterSearch !== '') {
    $where[]  = 'l.recipient LIKE ?';
    $like     = '%' . $filterSearch . '%';
    $params[] = $like;
    $types   .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Count Query ───────────────────────────────────────────────────────────────

$countSql = "
    SELECT COUNT(*) AS total
    FROM sdc_webinar_marketing_logs l
    LEFT JOIN sdc_webinars w               ON w.id  = l.webinar_id
    LEFT JOIN sdc_webinar_marketing_emails e ON e.id = l.marketing_email_id
    $whereSql
";

$total = 0;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $cp = $params;
    mlog_bind_params($countStmt, $types, $cp);
    $countStmt->execute();
    $cr = $countStmt->get_result();
    $total = (int)(($cr ? $cr->fetch_assoc() : [])['total'] ?? 0);
    $countStmt->close();
}

// ── List Query ────────────────────────────────────────────────────────────────

$listSql = "
    SELECT
        l.id,
        l.webinar_id,
        l.marketing_email_id,
        l.recipient,
        l.status,
        l.event_at,
        w.webinar_title,
        e.title  AS marketing_email_title,
        e.delay_value,
        e.delay_unit
    FROM sdc_webinar_marketing_logs l
    LEFT JOIN sdc_webinars w               ON w.id  = l.webinar_id
    LEFT JOIN sdc_webinar_marketing_emails e ON e.id = l.marketing_email_id
    $whereSql
    ORDER BY l.event_at DESC
    LIMIT ? OFFSET ?
";

$rows = [];
$listStmt = $conn->prepare($listSql);
if ($listStmt) {
    $lp    = $params;
    $lt    = $types . 'ii';
    $lp[]  = $perPage;
    $lp[]  = $offset;
    mlog_bind_params($listStmt, $lt, $lp);
    $listStmt->execute();
    $lr = $listStmt->get_result();
    while ($lr && ($row = $lr->fetch_assoc())) {
        $rows[] = $row;
    }
    $listStmt->close();
}

// ── Page Setup ────────────────────────────────────────────────────────────────

$pageTitle = 'Marketing Email Logs';
$pageDesc  = 'Monitor automated marketing email delivery for webinar registrants.';

$headerActionsHtmlDesktop = '
  <a href="/admin/webinar/marketing.php"
     class="hidden sm:inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back to Automations
  </a>
';
$headerActionsHtmlMobile = '
  <a href="/admin/webinar/marketing.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-white text-slate-700 border border-slate-200 shadow-sm"
     aria-label="Back to Automations" title="Back to Automations">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
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

  <!-- Filters -->
  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
      <h2 class="text-base font-semibold text-slate-800">Filters</h2>
      <p class="text-xs text-slate-400 mt-0.5">Filter logs by webinar, automation, status, or recipient.</p>
    </div>
    <form method="GET" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
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
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Marketing Email</label>
        <select name="marketing_email_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All</option>
          <?php foreach ($marketingEmailList as $me): ?>
            <option value="<?= (int)$me['id'] ?>" <?= $filterMarketingId === (int)$me['id'] ? 'selected' : '' ?>>
              ID <?= (int)$me['id'] ?> — <?= h((string)$me['title']) ?>
              <?= $me['webinar_id'] === null ? ' (global)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Status</label>
        <select name="status" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100">
          <option value="">All</option>
          <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>pending</option>
          <option value="sent"    <?= $filterStatus === 'sent'    ? 'selected' : '' ?>>sent</option>
          <option value="failed"  <?= $filterStatus === 'failed'  ? 'selected' : '' ?>>failed</option>
          <option value="skipped" <?= $filterStatus === 'skipped' ? 'selected' : '' ?>>skipped</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Search</label>
        <input type="text" name="q" value="<?= h($filterSearch) ?>" placeholder="Recipient email"
               class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-100" />
      </div>
      <div class="md:col-span-2 lg:col-span-4 flex flex-wrap gap-3 pt-2">
        <button type="submit" class="inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2.5 rounded-2xl font-black hover:bg-yellow-600 transition shadow-sm shadow-yellow-100">
          Filter
        </button>
        <a href="/admin/webinar/marketing-logs.php" class="inline-flex items-center gap-2 bg-white text-slate-700 border border-slate-200 px-4 py-2.5 rounded-2xl font-black hover:bg-slate-50 transition shadow-sm">
          Reset
        </a>
        <a href="/admin/webinar/marketing.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition ml-auto">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
          Back to Automations
        </a>
      </div>
    </form>
  </div>

  <!-- Logs Table -->
  <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold text-slate-800">Marketing Email Logs</h2>
        <p class="text-xs text-slate-400 mt-0.5"><?= number_format($total) ?> log<?= $total === 1 ? '' : 's' ?></p>
      </div>
    </div>

    <?php if (empty($rows)): ?>
      <div class="py-24 text-center">
        <p class="text-sm font-medium text-slate-500">No marketing email logs found.</p>
        <p class="text-xs text-slate-400 mt-1">Logs will appear here once the cron worker starts queuing emails (Phase 9D).</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="border-b border-slate-100">
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Webinar</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Recipient</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Marketing Email</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Event At</th>
              <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
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
                  <p class="text-sm text-slate-700"><?= h((string)($r['recipient'] ?? '')) ?></p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-semibold text-slate-900 max-w-[180px] truncate" title="<?= h((string)($r['marketing_email_title'] ?? '')) ?>">
                    <?= h((string)($r['marketing_email_title'] ?? 'N/A')) ?>
                  </p>
                  <p class="text-[10px] text-slate-400 mt-0.5">
                    ID <?= (int)$r['marketing_email_id'] ?> · <?= (int)$r['delay_value'] ?> <?= h((string)($r['delay_unit'] ?? '')) ?> after reg
                  </p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="text-sm font-semibold text-slate-900"><?= h((string)($r['event_at'] ?? '')) ?></p>
                  <p class="text-[10px] text-slate-400 mt-0.5">UTC</p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= mlog_status_badge((string)$r['status']) ?>">
                    <?= h((string)$r['status']) ?>
                  </span>
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
        $baseParams = $_GET;
    ?>
      <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-center gap-2 flex-wrap">
        <?php for ($p = 1; $p <= $totalPages; $p++):
          $baseParams['page'] = $p;
        ?>
          <a href="<?= h('/admin/webinar/marketing-logs.php?' . http_build_query($baseParams)) ?>"
             class="inline-flex items-center px-3 py-1.5 rounded-2xl text-sm font-bold transition <?= $p === $page ? 'bg-slate-900 text-white' : 'bg-slate-50 text-slate-700 hover:bg-slate-100' ?>">
            <?= $p ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
