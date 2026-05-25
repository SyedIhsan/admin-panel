<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function et_table_exists(mysqli $conn, string $table): bool {
  $safe = $conn->real_escape_string(trim($table));
  if ($safe === '') return false;
  $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function et_col_exists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string(trim($table));
  $column = $conn->real_escape_string(trim($column));
  if ($table === '' || $column === '') return false;
  $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function current_admin_label(): string {
  return trim((string)(
    $_SESSION['admin_email']
    ?? $_SESSION['admin_username']
    ?? $_SESSION['admin_name']
    ?? $_SESSION['admin_id']
    ?? 'admin'
  ));
}

function fetch_template_summary(mysqli $conn): array {
  $summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
  ];

  $sql = "
    SELECT is_active, COUNT(*) AS total
    FROM `email_templates`
    GROUP BY is_active
  ";

  $res = $conn->query($sql);
  if (!$res instanceof mysqli_result) {
    return $summary;
  }

  while ($row = $res->fetch_assoc()) {
    $count = (int)($row['total'] ?? 0);
    $summary['total'] += $count;

    if ((int)($row['is_active'] ?? 0) === 1) {
      $summary['active'] += $count;
    } else {
      $summary['inactive'] += $count;
    }
  }

  $res->close();
  return $summary;
}

function fetch_templates(mysqli $conn, string $q = '', string $statusFilter = 'all'): array {
  $sql = "
    SELECT
      id,
      product_type,
      product_category_id,
      target_scope,
      category,
      product_name,
      variant_name,
      subject,
      is_active,
      last_updated_by,
      updated_at
    FROM `email_templates`
    WHERE 1=1
  ";

  $params = [];
  $types = '';

  if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (
      product_type LIKE ?
      OR product_name LIKE ?
      OR variant_name LIKE ?
      OR category LIKE ?
      OR subject LIKE ?
      OR last_updated_by LIKE ?
    )";
    $params = [$like, $like, $like, $like, $like, $like];
    $types = 'ssssss';
  }

  if ($statusFilter === 'active') {
    $sql .= " AND is_active = 1";
  } elseif ($statusFilter === 'inactive') {
    $sql .= " AND is_active = 0";
  }

  $orderCol = et_col_exists($conn, 'email_templates', 'updated_at') ? 'updated_at' : 'id';
  $sql .= " ORDER BY {$orderCol} DESC, id DESC LIMIT 200";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return [];
  }

  if ($params) {
    $stmt->bind_param($types, ...$params);
  }

  if (!$stmt->execute()) {
    $stmt->close();
    return [];
  }

  $res = $stmt->get_result();
  $rows = $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
  if ($res instanceof mysqli_result) {
    $res->close();
  }
  $stmt->close();

  return $rows;
}

$templateConn = null;
if (function_exists('getBillingConn')) {
  $templateConn = getBillingConn();
}
if (!$templateConn instanceof mysqli && isset($conn) && $conn instanceof mysqli) {
  $templateConn = $conn;
}
if (!$templateConn instanceof mysqli) {
  http_response_code(500);
  exit('Email template database is unavailable.');
}

$templateConn->set_charset('utf8mb4');
$templateConn->query("SET time_zone = '+08:00'");

/**
 * Actions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isAjax = isset($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
  if (function_exists('csrf_validate')) csrf_validate();

  $action = trim((string)($_POST['action'] ?? ''));
  $templateId = (int)($_POST['template_id'] ?? 0);

  $ok = true;
  $newStatus = null;
  $oldStatus = null;

  if ($templateId <= 0 || !in_array($action, ['toggle', 'delete'], true)) {
    $ok = false;
  } else {
    try {
      if ($action === 'toggle') {
        $templateConn->begin_transaction();

        $stmt = $templateConn->prepare("
          SELECT is_active
          FROM `email_templates`
          WHERE id = ?
          LIMIT 1
        ");
        if (!$stmt) {
          throw new RuntimeException('Prepare failed: ' . $templateConn->error);
        }

        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
          $ok = false;
          $templateConn->rollback();
        } else {
          $oldStatus = ((int)($row['is_active'] ?? 0) === 1) ? 'active' : 'inactive';
          $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
          $isActiveValue = $newStatus === 'active' ? 1 : 0;
          $updatedBy = current_admin_label();

          $stmt2 = $templateConn->prepare("
            UPDATE `email_templates`
            SET is_active = ?,
                last_updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
          ");
          if (!$stmt2) {
            throw new RuntimeException('Prepare failed: ' . $templateConn->error);
          }

          $stmt2->bind_param('isi', $isActiveValue, $updatedBy, $templateId);
          $stmt2->execute();
          $stmt2->close();

          $templateConn->commit();
        }
      } else {
        $stmt = $templateConn->prepare("
          DELETE FROM `email_templates`
          WHERE id = ?
          LIMIT 1
        ");
        if (!$stmt) {
          throw new RuntimeException('Prepare failed: ' . $templateConn->error);
        }

        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $ok = ($deleted > 0);
      }
    } catch (Throwable $e) {
      @ $templateConn->rollback();
      error_log('email-templates action failed: ' . $e->getMessage());
      $ok = false;
    }
  }

  if ($isAjax) {
    if (!$ok) http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => $ok,
      'template_id' => $templateId,
      'status' => $newStatus,
      'old_status' => $oldStatus,
      'action' => $action,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  header('Location: /admin/email/email-templates.php?ts=' . time(), true, 303);
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));

$error = null;
$rows = [];
$summary = [
  'total' => 0,
  'active' => 0,
  'inactive' => 0,
];

if (!et_table_exists($templateConn, 'email_templates')) {
  $error = 'Table email_templates was not found.';
} else {
  $rows = fetch_templates($templateConn, $q, $statusFilter);
  $summary = fetch_template_summary($templateConn);
}

$title = 'Email Templates';
$pageTitle = 'Email Templates';
$pageDesc = 'View, edit, delete, and toggle saved email templates.';

$headerActionsHtmlDesktop = '
  <a href="/admin/email/custom-email.php"
     class="hidden sm:inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
    New Template
  </a>
';

$headerActionsHtmlMobile = '
  <a href="/admin/email/custom-email.php"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
     aria-label="New Template" title="New Template">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
  </a>
';

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="md:hidden mb-8">
  <h1 class="text-3xl font-black text-slate-900 tracking-tight">
    <?= h((string)$pageTitle) ?>
  </h1>

  <?php if (trim((string)$pageDesc) !== ''): ?>
    <p class="mt-2 text-sm font-semibold text-slate-500">
      <?= h((string)$pageDesc) ?>
    </p>
  <?php endif; ?>
</div>

<?php
$flashMessage = null;

if (isset($_GET['saved'])) {
  $flashMessage = ['type' => 'success', 'message' => 'Template saved successfully.'];
} elseif (isset($_GET['updated'])) {
  $flashMessage = ['type' => 'success', 'message' => 'Template updated successfully.'];
}
?>

<?php if (is_array($flashMessage)): ?>
  <div class="mb-6 rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-emerald-700">
    <div class="text-sm font-black"><?= h((string)$flashMessage['message']) ?></div>
  </div>
<?php endif; ?>

<div class="space-y-6" data-current-filter="<?= h($statusFilter) ?>">
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_18px_50px_rgba(15,23,42,.05)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-400">Total Templates</div>
      <div class="mt-2 text-2xl font-extrabold text-slate-900" data-summary-total><?= number_format((int)$summary['total']) ?></div>
    </div>

    <div class="rounded-[1.75rem] border border-emerald-200 bg-emerald-50 p-5 shadow-[0_18px_50px_rgba(16,185,129,.06)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-emerald-600">Active</div>
      <div class="mt-2 text-2xl font-extrabold text-emerald-700" data-summary-active><?= number_format((int)$summary['active']) ?></div>
    </div>

    <div class="rounded-[1.75rem] border border-rose-200 bg-rose-50 p-5 shadow-[0_18px_50px_rgba(244,63,94,.08)]">
      <div class="text-[11px] font-black uppercase tracking-[0.18em] text-rose-600">Inactive</div>
      <div class="mt-2 text-2xl font-extrabold text-rose-700" data-summary-inactive><?= number_format((int)$summary['inactive']) ?></div>
    </div>
  </div>

  <div class="bg-white rounded-2xl border shadow-sm overflow-hidden">
    <div class="p-6 border-b">
      <form method="GET" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_auto]">
        <input
          type="text"
          name="q"
          value="<?= h($q) ?>"
          placeholder="Search product, category, subject, or updated by"
          class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100"
        >

        <select
          name="status"
          class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100"
        >
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <button
          type="submit"
          class="inline-flex items-center justify-center rounded-[1.25rem] bg-yellow-400 px-5 py-3.5 text-sm font-black text-slate-900 shadow-lg shadow-yellow-100 transition hover:bg-yellow-300"
        >
          Filter Templates
        </button>
      </form>
    </div>

    <?php if ($error !== null): ?>
      <div class="p-6">
        <div class="rounded-[1.75rem] border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-black text-rose-700">
          <?= h($error) ?>
        </div>
      </div>
    <?php elseif (count($rows) === 0): ?>
      <div class="p-16 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center">
          <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
        </div>
        <h3 class="text-xl font-black text-slate-900 mb-2">No templates yet</h3>
        <p class="text-sm text-slate-500 font-semibold mb-6">Create your first email template to use in campaigns and triggers.</p>
        <a href="/admin/email/custom-email.php"
           class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-yellow-500 text-white font-black hover:bg-yellow-400 transition shadow">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
          </svg>
          Create First Template
        </a>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider">
            <tr>
              <th class="px-6 py-4">Product</th>
              <th class="px-6 py-4">Subject</th>
              <th class="px-6 py-4">Category</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Updated</th>
              <th class="px-6 py-4">Actions</th>
            </tr>
          </thead>

          <tbody class="divide-y">
            <?php foreach ($rows as $row): ?>
              <?php
                $templateId = (int)($row['id'] ?? 0);
                $productName = trim((string)($row['product_name'] ?? ''));
                $productType = trim((string)($row['product_type'] ?? ''));
                $productCategoryId = trim((string)($row['product_category_id'] ?? ''));
                $targetScope = trim((string)($row['target_scope'] ?? ''));
                $variantName = trim((string)($row['variant_name'] ?? ''));
                $category = trim((string)($row['category'] ?? 'both'));
                $subject = trim((string)($row['subject'] ?? ''));
                $updatedAt = trim((string)($row['updated_at'] ?? ''));
                $updatedBy = trim((string)($row['last_updated_by'] ?? ''));
                $isActive = (int)($row['is_active'] ?? 0) === 1;

                if ($productName === '') {
                  $productName = $productType !== '' ? $productType : 'Untitled Template';
                }

                $productMeta = 'Product Default';

                if ($targetScope === 'variant') {
                  $productMeta = $variantName !== ''
                    ? 'Variant: ' . $variantName
                    : 'Variant Target';
                } elseif ($targetScope === 'default' || $productType === '__default') {
                  $productMeta = 'Applies to all products';
                }

                $productInitial = strtoupper(substr($productName, 0, 1));
              ?>
              <tr data-template-id="<?= h((string)$templateId) ?>" class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center">
                    <div class="w-10 h-10 bg-yellow-100 rounded-xl flex items-center justify-center mr-3 text-yellow-700 font-bold">
                      <?= h($productInitial) ?>
                    </div>
                    <div>
                      <div class="font-semibold text-gray-800"><?= h($productName) ?></div>
                      <div class="text-xs text-gray-500 max-w-[380px] truncate">
                        <?= h($productMeta) ?>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="px-6 py-4 font-semibold text-gray-800">
                  <?= h($subject !== '' ? $subject : '-') ?>
                </td>

                <td class="px-6 py-4">
                  <?php
                    $categoryBadgeClass = match (strtolower($category)) {
                      'elearning' => 'bg-indigo-50 text-indigo-700 border-indigo-100',
                      'non_elearning' => 'bg-slate-100 text-slate-700 border-slate-200',
                      default => 'bg-yellow-50 text-yellow-700 border-yellow-100',
                    };
                  ?>
                  <span class="inline-flex items-center whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-semibold border <?= $categoryBadgeClass ?>">
                    <?= h($category !== '' ? $category : 'both') ?>
                  </span>
                </td>

                <td class="px-6 py-4">
                  <span class="flex items-center">
                    <span class="w-2 h-2 rounded-full <?= $isActive ? 'bg-emerald-500' : 'bg-rose-500' ?> mr-2" data-status-dot></span>
                    <span class="text-xs font-semibold text-gray-700 uppercase" data-status-text><?= $isActive ? 'ACTIVE' : 'INACTIVE' ?></span>
                  </span>
                </td>

                <td class="px-6 py-4 text-sm text-slate-500">
                  <div><?= h($updatedAt !== '' ? $updatedAt : '-') ?></div>
                  <div class="text-xs text-slate-400"><?= h($updatedBy !== '' ? $updatedBy : '-') ?></div>
                </td>

                <td class="px-6 py-4">
                  <div class="flex gap-4">
                    <!-- Edit -->
                    <a
                      href="/admin/email/custom-email.php?id=<?= h((string)$templateId) ?>"
                      class="text-yellow-700 hover:text-yellow-900 p-1"
                      title="Edit"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </a>

                    <!-- Toggle -->
                    <form method="POST" data-toggle-form>
                      <?php if (function_exists('csrf_token')): ?>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <?php endif; ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="template_id" value="<?= h((string)$templateId) ?>">
                      <button class="text-slate-500 hover:text-slate-900 p-1" title="Toggle Active/Inactive">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                        </svg>
                      </button>
                    </form>

                    <!-- Delete -->
                    <form method="POST"
                          data-confirm="Delete this template?"
                          data-confirm-desc="This will permanently remove the email template. This action cannot be undone."
                          data-confirm-ok="Delete">
                      <?php if (function_exists('csrf_token')): ?>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <?php endif; ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="template_id" value="<?= h((string)$templateId) ?>">
                      <button class="text-red-500 hover:text-red-700 p-1" title="Delete">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
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
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/confirm-modal.php'; ?>

<script>
(() => {
  const summaryTotal = document.querySelector('[data-summary-total]');
  const summaryActive = document.querySelector('[data-summary-active]');
  const summaryInactive = document.querySelector('[data-summary-inactive]');
  const currentFilter = document.querySelector('[data-current-filter]')?.getAttribute('data-current-filter') || 'all';

  const parseCount = (el) => {
    if (!el) return 0;
    const raw = (el.textContent || '').replace(/,/g, '').trim();
    const n = parseInt(raw, 10);
    return Number.isNaN(n) ? 0 : n;
  };

  const setCount = (el, value) => {
    if (!el) return;
    el.textContent = String(value);
  };

  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.matches('[data-toggle-form]')) return;

    e.preventDefault();

    const fd = new FormData(form);
    fd.append('ajax', '1');

    const btn = form.querySelector('button');
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
      });

      const data = await res.json();
      if (!data?.ok) throw new Error('Toggle failed');

      const templateId = fd.get('template_id');
      const row = document.querySelector(`tr[data-template-id="${templateId}"]`);
      if (!row) return;

      const statusText = row.querySelector('[data-status-text]');
      const statusDot = row.querySelector('[data-status-dot]');
      const newStatus = String(data.status || '').toLowerCase();
      const oldStatus = String(data.old_status || '').toLowerCase();

      if (statusText) statusText.textContent = newStatus.toUpperCase();

      if (statusDot) {
        statusDot.classList.remove('bg-emerald-500', 'bg-rose-500');
        statusDot.classList.add(newStatus === 'active' ? 'bg-emerald-500' : 'bg-rose-500');
      }

      let activeCount = parseCount(summaryActive);
      let inactiveCount = parseCount(summaryInactive);

      if (oldStatus === 'active' && newStatus === 'inactive') {
        activeCount = Math.max(0, activeCount - 1);
        inactiveCount += 1;
      } else if (oldStatus === 'inactive' && newStatus === 'active') {
        inactiveCount = Math.max(0, inactiveCount - 1);
        activeCount += 1;
      }

      setCount(summaryActive, activeCount);
      setCount(summaryInactive, inactiveCount);

      if (
        (currentFilter === 'active' && newStatus !== 'active') ||
        (currentFilter === 'inactive' && newStatus !== 'inactive')
      ) {
        row.remove();
      }
    } catch (err) {
      alert('Toggle failed. Please refresh and try again.');
    } finally {
      if (btn) btn.disabled = false;
    }
  }, true);
})();
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>