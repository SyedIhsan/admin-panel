<?php
// /admin/subscription/detail.php
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/subscription/init.php";
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

$conn = getBillingConn();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) die("Invalid ID");

$stmt = $conn->prepare("SELECT * FROM Subscriptions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sub) die("Subscription not found");

// Get Billing History (from Subscription_Billing_History joined with Payment for transaction details)
$stmtR = $conn->prepare("
    SELECT 
        sbh.*,
        p.verified,
        p.status as payment_status,
        p.transaction_ref as payment_transaction_ref
    FROM Subscription_Billing_History sbh
    LEFT JOIN Payment p ON sbh.payment_id = p.id
    WHERE sbh.subscription_id = ?
    ORDER BY sbh.created_at DESC, sbh.id DESC
");
$stmtR->bind_param("i", $id);
$stmtR->execute();
$billingHistory = $stmtR->get_result();
$stmtR->close();

function countRemainingInstallmentCycles(?string $nextDateRaw, ?string $expiryDateRaw): int
{
  $nextDateRaw = trim((string)($nextDateRaw ?? ''));
  $expiryDateRaw = trim((string)($expiryDateRaw ?? ''));

  if ($nextDateRaw === '' || $expiryDateRaw === '') {
    return 0;
  }

  try {
    $nextDate = new DateTime($nextDateRaw);
    $expiryDate = new DateTime($expiryDateRaw);
  } catch (Throwable $e) {
    return 0;
  }

  if ($nextDate >= $expiryDate) {
    return 0;
  }

  $count = 0;

  // Safety cap to avoid infinite loop if date data is wrong.
  while ($nextDate < $expiryDate && $count < 120) {
    $count++;
    $nextDate->modify('+1 month');
  }

  return $count;
}

$remainingRate = max(0, (float)($sub['remaining_month_price'] ?? 0));
$remainingCycles = countRemainingInstallmentCycles(
  $sub['next_renewal_date'] ?? null,
  $sub['expiry_date'] ?? null
);

$balanceDue = $remainingRate * $remainingCycles;

// 2. Layout Setup
$pageTitle = h($sub['subscription_no']);
$pageDesc  = "Detailed subscription history and pricing snapshots for " . h($sub['customer_name']);
$title = "Sub Detail | Demo Admin";

// Header Actions
$headerActionsHtmlDesktop = '
  <a href="/admin/subscription/index.php" class="px-4 py-2 rounded-2xl bg-white border border-slate-200 text-slate-600 shadow-sm hover:bg-slate-50 font-bold inline-flex items-center gap-2 transition-all">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    Back to List
  </a>
';

include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="max-w-[1400px] mx-auto">
  <div class="mb-8 flex items-center gap-4">
    <a href="/admin/subscription/index.php" class="bg-white border border-slate-200 p-2 rounded-xl text-slate-400 hover:text-slate-600 transition-all">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
    </a>
    <div>
      <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= h($sub['subscription_no']) ?></h1>
      <p class="text-slate-500">Subscription Details & History</p>
    </div>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
    <!-- Left Col: History & Info -->
    <div class="lg:col-span-2 space-y-8">

      <!-- Info Card -->
      <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-8 border-b border-slate-50 flex items-center justify-between bg-slate-50/30">
          <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">Customer Information</h2>
          <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-tight bg-emerald-100 text-emerald-700 border border-emerald-200">
            <?= h($sub['status']) ?>
          </span>
        </div>
        <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-8">
          <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Full Name</label>
            <div class="text-slate-900 font-bold text-lg"><?= h($sub['customer_name']) ?></div>
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Email Address</label>
            <div class="text-slate-900 font-bold text-lg"><?= h($sub['customer_email']) ?></div>
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Phone</label>
            <div class="text-slate-700 font-semibold"><?= h($sub['customer_phone'] ?: '-') ?></div>
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Subscription Since</label>
            <div class="text-slate-700 font-semibold"><?= date('d M Y, h:i A', strtotime($sub['start_date'])) ?></div>
          </div>
        </div>
      </div>

      <!-- History Card -->
      <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-8 border-b border-slate-50 bg-slate-50/30 flex items-center justify-between gap-4">
          <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">Billing History</h2>

          <div class="text-right">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Balance Due</div>
            <div class="text-sm font-black text-slate-400">
              RM <?= number_format($balanceDue, 2) ?>
            </div>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50/50">
                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date Paid</th>
                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Transaction ID</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <?php if ($billingHistory->num_rows === 0): ?>
                <tr>
                  <td colspan="4" class="p-12 text-center text-slate-400 font-bold italic">No billing history recorded yet.</td>
                </tr>
              <?php endif; ?>
              <?php
              $typeDisplay = [
                'initial_full' => 'Initial Payment',
                'initial_installment' => 'Initial Installment',
                'installment' => 'Installment Payment',
                'retention' => 'Retention',
                'retention_legacy' => 'Legacy Billing Payment',
              ];
              ?>
              <?php while ($r = $billingHistory->fetch_assoc()): ?>
                <tr>
                  <td class="p-6">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-tight bg-slate-100 text-slate-600">
                      <?= h($typeDisplay[$r['billing_type']] ?? $r['billing_type']) ?>
                    </span>
                  </td>
                  <td class="p-6 font-black text-slate-900">RM <?= number_format((float)$r['amount'], 2) ?></td>
                  <td class="p-6 text-sm text-slate-600 font-bold"><?= $r['paid_at'] ? date('d M Y, h:i A', strtotime($r['paid_at'])) : '-' ?></td>
                  <td class="p-6 text-xs font-mono text-slate-400">
                    <?php
                    $txRef = $r['transaction_ref'] ?? $r['payment_transaction_ref'] ?? $r['order_id'] ?? '';
                    echo h($txRef ?: '-');
                    ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right Col: Financials -->
    <div class="space-y-8">

      <!-- Pricing Snapshot -->
      <div class="bg-slate-900 rounded-[3rem] p-10 text-white shadow-2xl relative overflow-hidden group">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-yellow-500/10 rounded-full blur-3xl group-hover:bg-yellow-500/20 transition-all"></div>
        <div class="relative z-10">
          <label class="block text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-8 px-1">Pricing Snapshots</label>
          <div class="space-y-8">
            <div>
              <div class="text-[10px] text-slate-500 font-black uppercase mb-1">Signup Rate</div>
              <div class="text-2xl font-black">RM <?= number_format((float)$sub['first_month_price'], 2) ?></div>
            </div>
            <div class="pt-6 border-t border-white/5">
              <div class="text-[10px] text-yellow-500/60 font-black uppercase mb-1">Next Billing Rate</div>
              <div class="text-3xl font-black text-yellow-500">RM <?= number_format((float)$sub['remaining_month_price'], 2) ?></div>
              <div class="text-[10px] text-slate-500 font-bold mt-2 italic">Future payment links will use this rate</div>
            </div>
            <div class="pt-6 border-t border-white/5">
              <div class="text-[10px] text-slate-500 font-black uppercase mb-1">Special Continuation Offer</div>
              <div class="text-2xl font-black text-slate-300">RM <?= number_format((float)$sub['retention_price'], 2) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Expiry Box -->
      <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 p-10">
        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Expiry Control</label>
        <div class="space-y-8">
          <div>
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Expiry Date</div>
            <div class="text-2xl font-black text-slate-900 mt-1"><?= date('d M Y', strtotime($sub['expiry_date'])) ?></div>
            <?php if (strtotime($sub['expiry_date']) < time()): ?>
              <div class="mt-2 text-rose-600 font-black text-[10px] uppercase tracking-widest flex items-center gap-1">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" />
                </svg>
                Currently Overdue
              </div>
            <?php endif; ?>
          </div>
          <div class="pt-6 border-t border-slate-50">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Reminder Status</div>
            <div class="text-sm font-bold text-slate-600 mt-1"><?= $sub['last_reminder_sent_at'] ? 'Sent on ' . date('d M Y', strtotime($sub['last_reminder_sent_at'])) : 'No reminders sent yet' ?></div>
          </div>
          <div class="pt-6 border-t border-slate-50">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Cycle Count</div>
            <div class="text-sm font-bold text-slate-600 mt-1"><?= $sub['renewal_count'] ?> successful billing cycles completed</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>