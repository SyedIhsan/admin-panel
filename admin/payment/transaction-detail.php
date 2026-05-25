<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$currentView = "transactions.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function formatMYR(float $amount): string {
  // Avoid fatal if intl extension not enabled
  if (class_exists('NumberFormatter')) {
    $fmt = new NumberFormatter('en_MY', NumberFormatter::CURRENCY);
    $out = $fmt->formatCurrency($amount, 'MYR');
    if (is_string($out) && $out !== '') return $out;
  }
  return 'RM' . number_format($amount, 2);
}

function formatDateTime(string $dateStr): string {
  try {
    $dt = new DateTime($dateStr);
    $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
    return $dt->format('d F Y, H:i:s'); // match TSX style
  } catch (Throwable $e) {
    return $dateStr;
  }
}

function firstLetter(string $name): string {
  $name = trim($name);
  if ($name === '') return '?';
  if (function_exists('mb_substr')) {
    $ch = mb_substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($ch) : strtoupper($ch);
  }
  return strtoupper(substr($name, 0, 1));
}

$id = (int)($_GET["id"] ?? 0);
$trx = trim((string)($_GET["trx"] ?? ""));

if ($id <= 0 && $trx === "") {
  http_response_code(400);
  exit("Missing id.");
}

// fallback: allow trx
if ($id <= 0 && $trx !== "") {
  $stmt0 = $conn->prepare("SELECT id FROM `Payment` WHERE `transaction_id`=? LIMIT 1");
  if ($stmt0) {
    $stmt0->bind_param("s", $trx);
    $stmt0->execute();
    $row0 = $stmt0->get_result()->fetch_assoc() ?? [];
    $stmt0->close();
    $id = (int)($row0["id"] ?? 0);
  }
  if ($id <= 0) {
    http_response_code(404);
    exit("Transaction not found.");
  }
}

$sql = "SELECT `id`,`codeid`,`transaction_id`,`name`,`email`,`phone`,`item`,`package`,`channel`,`price`,`status`,`timestamp`,`sid`,`referred_by`
        FROM `Payment` WHERE `id`=? AND ($ENV_PAY_WHERE) LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tx) {
  http_response_code(404);
  exit("Transaction not found.");
}

$receiptUrl = "/admin/payment/receipt.php?id=" . urlencode((string)$id);
$editUrl    = "/admin/payment/add-transaction.php?edit=" . urlencode((string)$id);

$ref = trim((string)($tx["referred_by"] ?? ""));
$hasRef = $ref !== "";

$channel = trim((string)($tx["channel"] ?? ""));
$trxId   = (string)($tx["transaction_id"] ?? "");

// ---- Header vars (ikut pattern dashboard/receipt) ----
$pageTitle = "Transaction Detail";
$pageDesc  = "Audit view for " . $trxId;

$headerShowSearch = false;
$headerAddDesktop = false;
$headerAddMobile  = false;

$headerBackUrl   = "/admin/payment/transactions.php";
$headerBackLabel = "Back to List";

$headerActionsHtmlDesktop = '
  <a href="/admin/payment/transactions.php"
    class="flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
    <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back to List
  </a>

  <a href="'.h($receiptUrl).'"
    class="px-4 py-2 rounded-2xl bg-slate-900 text-white shadow-sm hover:bg-slate-800 font-bold inline-flex items-center gap-2"
    title="Generate Receipt" aria-label="Generate Receipt">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v16l-3-2-3 2-3-2-3 2V6a2 2 0 012-2z"/>
    </svg>
    Generate Receipt
  </a>

  <a href="'.h($editUrl).'"
    class="px-4 py-2 rounded-2xl bg-amber-500 text-white shadow-sm hover:bg-amber-600 font-bold inline-flex items-center gap-2"
    title="Edit Record" aria-label="Edit Record">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
    </svg>
    Edit Record
  </a>
';

$headerActionsHtmlMobile = '
  <a href="'.h($receiptUrl).'"
    class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition shadow-sm"
    title="Generate Receipt" aria-label="Generate Receipt">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v16l-3-2-3 2-3-2-3 2V6a2 2 0 012-2z"/>
    </svg>
  </a>

  <a href="'.h($editUrl).'"
    class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
    title="Edit Record" aria-label="Edit Record">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
    </svg>
  </a>
';

$title = "Transaction Detail";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="pb-12">
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="md:hidden mb-8">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">
            <?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, "UTF-8") ?>
          </h1>

          <?php if (trim((string)$pageDesc) !== ""): ?>
            <p class="mt-2 text-sm font-semibold text-slate-500">
              <?= htmlspecialchars((string)$pageDesc, ENT_QUOTES, "UTF-8") ?>
            </p>
          <?php endif; ?>
        </div>

        <!-- Back (desktop style) duduk belah kanan -->
        <a href="<?= h($headerBackUrl) ?>"
          class="inline-flex items-center gap-2 px-5 py-3
                text-base font-extrabold text-slate-700 hover:text-slate-900
                transition-all group whitespace-nowrap">
          <svg class="w-6 h-6 transition-transform group-hover:-translate-x-1"
              fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          <span>Back</span>
        </a>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Main Content Column -->
      <div class="lg:col-span-2 space-y-6">

        <!-- Header Card -->
        <div class="bg-white border border-slate-200 rounded-3xl p-8 shadow-sm">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
              <span class="text-[10px] font-extrabold text-amber-500 uppercase tracking-widest bg-amber-50 px-3 py-1 rounded-full border border-amber-100 mb-3 inline-block">
                Transaction Verified
              </span>
              <h2 class="text-3xl font-black text-slate-900"><?= h($trxId) ?></h2>
              <p class="text-slate-400 font-mono text-sm mt-1 uppercase break-words">
                CODE ID: <?= h((string)($tx["codeid"] ?? "")) ?> • SID: <?= h((string)($tx["sid"] ?? "")) ?>
              </p>
            </div>

            <div class="md:text-right">
              <div class="text-4xl font-black text-slate-900 tracking-tight whitespace-nowrap">
                <?= h(formatMYR((float)($tx["price"] ?? 0))) ?>
              </div>
              <p class="text-slate-400 text-sm font-medium">
                Payment via <?= h($channel) ?>
              </p>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-t border-slate-100 pt-8">
            <div class="space-y-4">
              <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em]">Item Specification</h4>
              <div class="bg-slate-50 rounded-2xl p-4">
                <div class="text-lg font-bold text-slate-800 break-words"><?= h((string)($tx["item"] ?? "")) ?></div>
                <div class="text-sm font-bold text-amber-600 mt-1 uppercase tracking-wider">
                  <?= h((string)($tx["package"] ?? "")) ?> Package
                </div>
              </div>
            </div>

            <div class="space-y-4">
              <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em]">Referrer Info</h4>
              <div class="bg-slate-50 rounded-2xl p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center <?= $hasRef ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200 text-slate-400' ?>">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                  </svg>
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-bold text-slate-800 break-words"><?= h($hasRef ? $ref : "Direct Entry") ?></div>
                  <div class="text-[10px] text-slate-400 font-bold uppercase">Referral Code</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Timeline -->
        <div class="bg-white border border-slate-200 rounded-3xl p-8 shadow-sm">
          <h3 class="text-lg font-bold text-slate-900 mb-6">Process Timeline</h3>

          <div class="space-y-6">
            <?php
              $steps = [
                ["title"=>"Payment Success", "desc"=>"Received via " . $channel, "time"=>formatDateTime((string)($tx["timestamp"] ?? ""))],
                ["title"=>"Validation Complete", "desc"=>"Transaction ID verified by gateway", "time"=>"Auto-verified"],
                ["title"=>"Record Created", "desc"=>"Logged by system", "time"=>formatDateTime((string)($tx["timestamp"] ?? ""))],
              ];
            ?>
            <?php foreach ($steps as $idx => $step): ?>
              <div class="flex gap-4">
                <div class="flex flex-col items-center">
                  <div class="w-3 h-3 rounded-full <?= $idx === 0 ? 'bg-emerald-500 shadow-lg shadow-emerald-200' : 'bg-slate-200' ?>"></div>
                  <?php if ($idx !== count($steps)-1): ?>
                    <div class="w-px h-full bg-slate-100 my-1"></div>
                  <?php endif; ?>
                </div>
                <div class="pb-2 min-w-0">
                  <div class="text-sm font-bold text-slate-800"><?= h((string)$step["title"]) ?></div>
                  <div class="text-xs text-slate-500 font-medium break-words"><?= h((string)$step["desc"]) ?></div>
                  <div class="text-[10px] text-slate-400 font-mono mt-1 break-words"><?= h((string)$step["time"]) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- Sidebar / Customer Card -->
      <div class="space-y-6">
        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
          <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] mb-6">Customer Details</h4>

          <div class="flex items-center gap-4 mb-8">
            <div class="w-14 h-14 bg-slate-900 rounded-2xl flex items-center justify-center text-white text-xl font-bold">
              <?= h(firstLetter((string)($tx["name"] ?? ""))) ?>
            </div>

            <div class="min-w-0">
              <div class="text-lg font-black text-slate-900 whitespace-normal break-words">
                <?= h((string)($tx["name"] ?? "")) ?>
              </div>
            </div>
          </div>

          <div class="space-y-4">
            <div class="p-4 rounded-2xl border border-slate-100 hover:border-slate-200 transition-colors">
              <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Email</div>
              <div class="text-sm font-bold text-slate-800 break-all"><?= h((string)($tx["email"] ?? "")) ?></div>
            </div>

            <div class="p-4 rounded-2xl border border-slate-100 hover:border-slate-200 transition-colors">
              <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Phone</div>
              <div class="text-sm font-bold text-slate-800 break-words"><?= h((string)($tx["phone"] ?? "")) ?></div>
            </div>
          </div>

          <?php $mail = (string)($tx["email"] ?? ""); ?>
          <?php if ($mail !== ""): ?>
            <a href="mailto:<?= h($mail) ?>"
              class="w-full mt-6 py-3 border border-slate-200 text-slate-600 font-bold text-sm rounded-xl hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              Contact Customer
            </a>
          <?php endif; ?>
        </div>

        <div class="bg-amber-50 border border-amber-100 rounded-3xl p-6">
          <h4 class="text-xs font-black text-amber-600 uppercase tracking-widest mb-2">Audit Information</h4>
          <p class="text-xs text-amber-700 font-medium leading-relaxed break-words">
            This record was automatically generated via the <?= h($channel) ?> gateway on <?= h(formatDateTime((string)($tx["timestamp"] ?? ""))) ?>.
          </p>
        </div>
      </div>
    </div>

  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>