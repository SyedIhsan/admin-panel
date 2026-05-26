<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

$logConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$logConn instanceof mysqli) {
  http_response_code(500);
  exit('Main email log database is unavailable.');
}

$logConn->set_charset('utf8mb4');
$logConn->query("SET time_zone = '+08:00'");

function el_table_exists(mysqli $conn, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;
  $safe = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function el_column_exists(mysqli $conn, string $table, string $column): bool {
  $table = trim($table);
  $column = trim($column);
  if ($table === '' || $column === '') return false;
  $safeTable = $conn->real_escape_string($table);
  $safeCol = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function el_pick_table(mysqli $conn): ?string {
  foreach (['email_logs', 'mail_logs', 'sent_emails', 'outbound_email_logs'] as $table) {
    if (el_table_exists($conn, $table)) return $table;
  }
  return null;
}

function el_pick_col(mysqli $conn, string $table, array $choices): ?string {
  foreach ($choices as $col) {
    if (el_column_exists($conn, $table, $col)) return $col;
  }
  return null;
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$logTable = el_pick_table($logConn);
$rows = [];
$error = null;
$summary = ['total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0];

if ($logTable !== null) {
  $map = [
    'id'         => el_pick_col($logConn, $logTable, ['id']),
    'recipient'  => el_pick_col($logConn, $logTable, ['recipient_email', 'email', 'to_email', 'customer_email']),
    'subject'    => el_pick_col($logConn, $logTable, ['subject', 'email_subject']),
    'template'   => el_pick_col($logConn, $logTable, ['template_name', 'template', 'email_type', 'mailer']),
    'status'     => el_pick_col($logConn, $logTable, ['status', 'send_status', 'delivery_status']),
    'error'      => el_pick_col($logConn, $logTable, ['error_message', 'error', 'fail_reason']),
    'created_at' => el_pick_col($logConn, $logTable, ['created_at', 'sent_at', 'logged_at']),
  ];

  $select = [];
  foreach ($map as $alias => $col) {
    if ($col !== null) {
      $select[] = "`{$col}` AS `{$alias}`";
    }
  }
  if (empty($select)) {
    $error = 'A log table was found, but none of the expected columns are available.';
  } else {
    $sql = "SELECT " . implode(', ', $select) . " FROM `{$logTable}` WHERE 1=1";
    
    // Environment-based filtering (Real vs Test)
    // DEMO_MODE: show all rows regardless of email domain (all seed data uses test addresses)
    $envWhere = "1=1";
    $recCol = $map['recipient'];
    if ($recCol !== null && !(defined('DEMO_MODE') && DEMO_MODE === true)) {
      if (IS_LOCALHOST) {
        // Show only test emails on localhost
        $envWhere = "(`{$recCol}` LIKE '%@demo.local' OR `{$recCol}` LIKE '%test%')";
      } else {
        // Show only real emails in production
        $envWhere = "(`{$recCol}` NOT LIKE '%@demo.local' AND `{$recCol}` NOT LIKE '%test%')";
      }
    }
    $sql .= " AND $envWhere";

    $params = [];
    $types = '';

    if ($q !== '') {
      $filters = [];
      foreach (['recipient', 'subject', 'template', 'error'] as $alias) {
        if ($map[$alias] !== null) {
          $filters[] = "`{$map[$alias]}` LIKE ?";
          $params[] = '%' . $q . '%';
          $types .= 's';
        }
      }
      if ($filters) {
        $sql .= ' AND (' . implode(' OR ', $filters) . ')';
      }
    }

    if ($statusFilter !== '' && $map['status'] !== null) {
      $sql .= " AND `{$map['status']}` = ?";
      $params[] = $statusFilter;
      $types .= 's';
    }

    if ($map['created_at'] !== null) {
      $sql .= " ORDER BY `{$map['created_at']}` DESC LIMIT 100";
    } elseif ($map['id'] !== null) {
      $sql .= " ORDER BY `{$map['id']}` DESC LIMIT 100";
    } else {
      $sql .= " LIMIT 100";
    }

    $stmt = $logConn->prepare($sql);
    if (!$stmt) {
      $error = 'Failed to prepare the email logs query.';
    } else {
      if ($params) {
        $stmt->bind_param($types, ...$params);
      }
      if (!$stmt->execute()) {
        $error = 'Failed to execute the email logs query.';
      } else {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
          while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
          }
          $res->close();
        }
      }
      $stmt->close();
    }

    if (!$error && $map['status'] !== null) {
      $statusCol = $map['status'];
      $sumSql = "SELECT LOWER(TRIM(`{$statusCol}`)) AS status_key, COUNT(*) AS total FROM `{$logTable}` WHERE $envWhere GROUP BY LOWER(TRIM(`{$statusCol}`))";
      $sumRes = $logConn->query($sumSql);
      if ($sumRes instanceof mysqli_result) {
        while ($row = $sumRes->fetch_assoc()) {
          $key = (string)($row['status_key'] ?? '');
          $total = (int)($row['total'] ?? 0);
          $summary['total'] += $total;
          if (in_array($key, ['sent', 'success', 'delivered'], true)) {
            $summary['sent'] += $total;
          } elseif (in_array($key, ['failed', 'error', 'bounce'], true)) {
            $summary['failed'] += $total;
          } elseif (in_array($key, ['pending', 'queued', 'processing', 'retrying', 'blocked_pending_payment'], true)) {
            $summary['pending'] += $total;
          }
        }
        $sumRes->close();
      }
    } else {
      $summary['total'] = count($rows);
    }
  }
}

$title = 'Email Logs';
$pageTitle = 'Email Logs';
$pageDesc = 'Review sent emails, delivery activity, and recent email events.';
include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="md:hidden mb-8">
  <h1 class="text-3xl font-black text-slate-900 tracking-tight">
    <?= e((string)$pageTitle) ?>
  </h1>

  <?php if (trim((string)$pageDesc) !== ''): ?>
    <p class="mt-2 text-sm font-semibold text-slate-500">
      <?= e((string)$pageDesc) ?>
    </p>
  <?php endif; ?>
</div>

<div class="space-y-6">
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_18px_50px_rgba(15,23,42,.05)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Log Source</div>
      <div class="mt-2 text-2xl font-extrabold text-slate-900"><?= e($logTable ?? 'Not Found') ?></div>
    </div>
    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_18px_50px_rgba(15,23,42,.05)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Total</div>
      <div class="mt-2 text-2xl font-extrabold text-slate-900"><?= number_format((int)$summary['total']) ?></div>
    </div>
    <div class="rounded-[1.75rem] border border-emerald-200 bg-emerald-50 p-5 shadow-[0_18px_50px_rgba(16,185,129,.06)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-emerald-600">Sent</div>
      <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?= number_format((int)$summary['sent']) ?></div>
    </div>
    <div class="rounded-[1.75rem] border border-rose-200 bg-rose-50 p-5 shadow-[0_18px_50px_rgba(244,63,94,.06)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-rose-600">Failed</div>
      <div class="mt-2 text-2xl font-extrabold text-rose-700"><?= number_format((int)$summary['failed']) ?></div>
    </div>
  </div>

  <div class="rounded-[2rem] border border-slate-200 bg-white shadow-[0_18px_50px_rgba(15,23,42,.06)] overflow-hidden">
    <div class="border-b border-slate-100 px-6 py-5">
      <form method="GET" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_auto]">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search recipient, subject, template, or error"
          class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100">

        <select name="status" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100">
          <option value="">All Status</option>
          <?php foreach ([
            'sent' => 'Sent',
            'pending' => 'Pending',
            'queued' => 'Queued',
            'retrying' => 'Retrying',
            'blocked_pending_payment' => 'Blocked: Payment Pending',
            'failed' => 'Failed',
            'success' => 'Success',
            'error' => 'Error',
            'skipped_duplicate' => 'Skipped Duplicate'
          ] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="inline-flex items-center justify-center rounded-[1.25rem] bg-yellow-400 px-5 py-3.5 text-sm font-black text-slate-900 shadow-lg shadow-yellow-100 transition hover:bg-yellow-300">
          Filter Logs
        </button>
      </form>
    </div>

    <?php if ($error !== null): ?>
      <div class="p-6">
        <div class="rounded-[1.75rem] border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-black text-rose-700"><?= e($error) ?></div>
      </div>
    <?php elseif ($logTable === null): ?>
      <div class="p-6">
        <div class="rounded-[1.75rem] border border-yellow-200 bg-yellow-50 px-5 py-5">
          <div class="text-sm font-black text-yellow-800">No email log table was found.</div>
          <p class="mt-2 text-sm font-semibold text-yellow-700">If you are not storing outbound email logs yet, create a table such as <code class="rounded bg-white/70 px-2 py-1 text-xs">email_logs</code> and save fields like recipient_email, subject, template_name, status, error_message, and created_at.</p>
        </div>
      </div>
    <?php elseif (!$rows): ?>
      <div class="p-6">
        <div class="rounded-[1.75rem] border border-slate-200 bg-slate-50 px-5 py-5 text-sm font-black text-slate-600">No records match the current filter.</div>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Recipient</th>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Subject</th>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Template</th>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Status</th>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Time</th>
              <th class="px-6 py-4 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <?php foreach ($rows as $row): ?>
              <?php
                $status = strtolower(trim((string)($row['status'] ?? 'unknown')));
                $badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                if (in_array($status, ['sent', 'success', 'delivered'], true)) $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                elseif (in_array($status, ['failed', 'error', 'bounce'], true)) $badgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
                elseif (in_array($status, ['pending', 'queued', 'processing'], true)) $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
              ?>
              <tr class="align-top">
                <td class="px-6 py-4">
                  <div class="text-sm font-extrabold text-slate-900"><?= e((string)($row['recipient'] ?? '-')) ?></div>
                  <?php if (!empty($row['error'])): ?>
                    <div class="mt-2 line-clamp-2 text-xs font-semibold text-rose-600"><?= e((string)$row['error']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-sm font-semibold text-slate-700"><?= e((string)($row['subject'] ?? '-')) ?></td>
                <td class="px-6 py-4 text-sm font-semibold text-slate-500"><?= e((string)($row['template'] ?? '-')) ?></td>
                <td class="px-6 py-4">
                  <span class="inline-flex rounded-full border px-3 py-1 text-xs font-black uppercase tracking-[0.15em] <?= $badgeClass ?>">
                    <?= e((string)($row['status'] ?? 'unknown')) ?>
                  </span>
                </td>
                <td class="px-6 py-4 text-sm font-semibold text-slate-500"><?= e((string)($row['created_at'] ?? '-')) ?></td>
                <?php
                  $canRetry = in_array($status, ['failed', 'error', 'bounce', 'pending', 'queued', 'processing', 'retrying', 'blocked_pending_payment'], true);
                ?>
                <td class="px-6 py-4">
                  <?php if ($canRetry && !empty($row['id'])): ?>
                    <form method="POST" action="/admin/email/retry-email.php" class="inline-flex">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="log_id" value="<?= e((string)$row['id']) ?>">
                      <button type="submit" class="inline-flex items-center rounded-xl bg-yellow-400 px-3 py-2 text-xs font-black text-slate-900 shadow-sm transition hover:bg-yellow-300">
                        Retry Send
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs font-bold text-slate-400">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
