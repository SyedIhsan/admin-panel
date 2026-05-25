<?php
// /admin/subscription/index.php
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/subscription/init.php";
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

$conn = getBillingConn();

// 1. Data Fetching
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSearch = trim((string)($_GET['search'] ?? ''));

$sql = "SELECT * FROM Subscriptions WHERE 1=1";
$params = [];
$types = "";

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host);
$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

if ($isLocalhost) {
    // Localhost: show only staff/internal testing records (@demo.local)
    $sql .= " AND LOWER(TRIM(customer_email)) LIKE ?";
    $params[] = "%@demo.local";
    $types .= "s";
} else {
    // Production: hide staff/internal records, show real customers only
    $sql .= " AND LOWER(TRIM(customer_email)) NOT LIKE ?";
    $params[] = "%@demo.local";
    $types .= "s";
}

if ($filterStatus !== '') {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

if ($filterSearch !== '') {
    $sql .= " AND (subscription_no LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)";
    $like = "%{$filterSearch}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}

$sql .= " ORDER BY expiry_date ASC";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$allRows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2. Layout Setup
$pageTitle = "Subscriptions";
$pageDesc  = "Manage recurring customer billing, expiry dates, and lifecycle status.";
$title = "Subscriptions";

// Header Actions (Desktop/Mobile)
$headerActionsHtmlDesktop = '';

include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="max-w-[1600px] mx-auto space-y-6">

  <!-- Mobile Page Title -->
  <div class="md:hidden mb-8">
    <h1 class="text-3xl font-black text-slate-900 tracking-tight">
      <?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, "UTF-8") ?>
    </h1>
    <?php if (trim((string)$pageDesc) !== ""): ?>
      <p class="mt-2 text-sm font-semibold text-slate-500">
        <?= htmlspecialchars((string)$pageDesc, ENT_QUOTES, "UTF-8") ?>
      </p>
    <?php endif; ?>
  </div>

  <!-- Status Overview Cards -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <?php
      $stats = ['active' => 0, 'expired' => 0, 'past_due' => 0, 'total' => count($allRows)];
      foreach($allRows as $r) {
          if ($r['status'] === 'active') {
              if (strtotime($r['expiry_date']) < time()) $stats['past_due']++;
              else $stats['active']++;
          } else if ($r['status'] === 'expired') {
              $stats['expired']++;
          }
      }
    ?>
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
      <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Active</div>
      <div class="text-2xl font-black text-emerald-600"><?= $stats['active'] ?></div>
    </div>
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
      <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Past Due</div>
      <div class="text-2xl font-black text-orange-500"><?= $stats['past_due'] ?></div>
    </div>
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
      <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Expired</div>
      <div class="text-2xl font-black text-rose-600"><?= $stats['expired'] ?></div>
    </div>
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
      <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Records</div>
      <div class="text-2xl font-black text-slate-900"><?= $stats['total'] ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="bg-white p-4 rounded-3xl shadow-sm border border-slate-200">
    <form method="GET" id="filterForm" action="/admin/subscription/index.php" class="flex flex-wrap gap-4 items-end">
      <div class="flex-1 min-w-[240px]">
        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 px-1">Quick Search</label>
        <div class="relative">
          <input type="text" name="search" value="<?= h($filterSearch) ?>" 
                 placeholder="Search by name, email or sub no..." 
                 class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-11 py-3 focus:ring-2 focus:ring-yellow-500 focus:outline-none transition-all text-sm font-semibold">
          <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-width="2" stroke-linecap="round"/></svg>
        </div>
      </div>
      <div class="w-full md:w-48">
        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 px-1">Status Filter</label>
        <?php
          $statusLabel = 'All Status';
          if ($filterStatus === 'active') $statusLabel = 'Active';
          if ($filterStatus === 'expired') $statusLabel = 'Expired';
          if ($filterStatus === 'cancelled') $statusLabel = 'Cancelled';
        ?>

        <div class="relative">
          <input type="hidden" name="status" id="statusInput" value="<?= h($filterStatus) ?>">

          <button type="button" id="statusBtn"
            class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-yellow-500 transition-all text-sm font-bold text-slate-700 flex items-center justify-between gap-3">
            <span id="statusLabel" class="truncate"><?= h($statusLabel) ?></span>
            <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>

          <div id="statusPanel"
            class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
            <div class="p-2">
              <button type="button" class="statusItem w-full text-left px-4 py-3 rounded-xl hover:bg-slate-50 text-sm font-bold text-slate-700"
                data-value="" data-label="All Status">All Status</button>

              <button type="button" class="statusItem w-full text-left px-4 py-3 rounded-xl hover:bg-slate-50 text-sm font-bold text-slate-700"
                data-value="active" data-label="Active">Active</button>

              <button type="button" class="statusItem w-full text-left px-4 py-3 rounded-xl hover:bg-slate-50 text-sm font-bold text-slate-700"
                data-value="expired" data-label="Expired">Expired</button>

              <button type="button" class="statusItem w-full text-left px-4 py-3 rounded-xl hover:bg-slate-50 text-sm font-bold text-slate-700"
                data-value="cancelled" data-label="Cancelled">Cancelled</button>
            </div>
          </div>
        </div>
      </div>
      <div class="w-full md:w-auto flex gap-2">
        <button type="submit" class="flex-1 md:flex-none bg-slate-900 text-white px-8 py-3 rounded-2xl font-black hover:bg-slate-800 transition-all shadow-lg shadow-slate-200 text-sm">Apply</button>
        <a href="/admin/subscription/index.php" class="bg-slate-100 text-slate-600 px-6 py-3 rounded-2xl font-black hover:bg-slate-200 transition-all text-sm">Reset</a>
      </div>
    </form>
    <?php if ($isLocalhost): ?>
      <p class="mt-3 text-[11px] font-semibold text-slate-400 px-1">Local mode: showing @demo.local test records only</p>
    <?php else: ?>
      <p class="mt-3 text-[11px] font-semibold text-slate-400 px-1">Production mode: staff @demo.local records are hidden</p>
    <?php endif; ?>
  </div>

  <!-- Subscriptions Table -->
  <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-slate-50/50 border-b border-slate-100">
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Subscription ID</th>
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer Details</th>
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Plan / Variant</th>
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Lifecycle Pricing</th>
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Next Expiry</th>
            <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php if (empty($allRows)): ?>
            <tr>
              <td colspan="6" class="p-20 text-center">
                <div class="flex flex-col items-center">
                  <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </div>
                  <h3 class="text-slate-900 font-black">No subscriptions found</h3>
                  <p class="text-slate-400 text-sm font-semibold">Try adjusting your filters or search terms.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($allRows as $row): ?>
            <?php 
              $expiryTs = strtotime($row['expiry_date']);
              $isExpired = $expiryTs < time();
              $statusColor = 'bg-slate-100 text-slate-600';
              if ($row['status'] === 'active') $statusColor = $isExpired ? 'bg-orange-100 text-orange-600' : 'bg-emerald-100 text-emerald-600 border border-emerald-200';
              if ($row['status'] === 'expired' || $row['status'] === 'cancelled') $statusColor = 'bg-rose-50 text-rose-600 border border-rose-100';
            ?>
            <tr class="hover:bg-slate-50/50 transition-colors group">
              <td class="p-6">
                <div class="font-mono text-sm font-black text-slate-900"><?= h($row['subscription_no']) ?></div>
                <div class="mt-2">
                  <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider <?= $statusColor ?>">
                    <?= h($row['status']) ?><?= ($row['status'] === 'active' && $isExpired) ? ' (Past Due)' : '' ?>
                  </span>
                </div>
              </td>
              <td class="p-6">
                <div class="text-sm font-black text-slate-900"><?= h($row['customer_name']) ?></div>
                <div class="text-xs text-slate-400 font-semibold mt-0.5"><?= h($row['customer_email']) ?></div>
              </td>
              <td class="p-6 text-sm">
                <div class="font-black text-slate-700"><?= h($row['product_name_snapshot']) ?></div>
                <div class="text-[10px] text-slate-400 font-bold uppercase tracking-tight mt-1"><?= h($row['variant_name_snapshot'] ?: 'Default Package') ?></div>
              </td>
              <td class="p-6 text-sm">
                <div class="text-slate-900 font-black text-base">RM <?= number_format((float)$row['remaining_month_price'], 2) ?></div>
                <div class="text-[10px] text-slate-400 uppercase font-black tracking-widest mt-1">Recurring Rate</div>
              </td>
              <td class="p-6 text-sm">
                <div class="font-black <?= $isExpired ? 'text-rose-600' : 'text-slate-900' ?> text-base">
                  <?= date('d M Y', $expiryTs) ?>
                </div>
                <div class="text-[10px] text-slate-400 uppercase font-black tracking-widest mt-1"><?= $row['duration_value'] ?> <?= h($row['duration_unit']) ?>(s) Period</div>
              </td>
              <td class="p-6 text-right">
                <a href="/admin/subscription/detail.php?id=<?= $row['id'] ?>" 
                   class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-400 hover:text-yellow-600 hover:border-yellow-200 hover:bg-yellow-50 transition-all shadow-sm group-hover:shadow-md">
                   <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(() => {
  // Page is interactive - auto-submit form on select change
  const filterForm = document.getElementById("filterForm");
  const statusInput = document.getElementById("statusInput");
  const statusBtn = document.getElementById("statusBtn");
  const statusPanel = document.getElementById("statusPanel");
  const statusLabel = document.getElementById("statusLabel");

  if (statusBtn && statusPanel && statusInput && statusLabel) {
    statusBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      statusPanel.classList.toggle("hidden");
    });

    document.querySelectorAll(".statusItem").forEach(item => {
      item.addEventListener("click", () => {
        statusInput.value = item.dataset.value || "";
        statusLabel.textContent = item.dataset.label || "All Status";
        statusPanel.classList.add("hidden");

        if (filterForm) filterForm.submit();
      });
    });

    document.addEventListener("click", (e) => {
      if (!statusPanel.contains(e.target) && !statusBtn.contains(e.target)) {
        statusPanel.classList.add("hidden");
      }
    });
  }

  // Handle row clicks for better UX
  document.querySelectorAll("tbody tr").forEach(row => {
    const detailBtn = row.querySelector('a[href*="/admin/subscription/detail.php"]');
    if (!detailBtn) return;
    row.style.cursor = "pointer";
    row.addEventListener("click", (e) => {
      if (e.target.closest("a, button, input, select, textarea")) return;
      detailBtn.click();
    });
  });
})();
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>
