<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }
function clip(?string $s, int $max): ?string {
  $s = $s === null ? null : trim($s);
  if ($s === '') return null;

  $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
  if ($len > $max) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
  }
  return $s;
}

function pickChannels(mysqli $conn): array {
  $list = [];
  $res = $conn->query("
    SELECT DISTINCT `channel`
    FROM `Payment`
    WHERE `channel` IS NOT NULL AND TRIM(`channel`) <> ''
    ORDER BY `channel` ASC
  ");
  if ($res) {
    while ($r = $res->fetch_assoc()) $list[] = (string)$r["channel"];
    $res->free();
  }
  if (!$list) {
    $list = ['senangpay-sandbox','elearning','stripe','paypal','bank-transfer'];
  }
  return $list;
}

$channels = pickChannels($conn);

$editId = (int)($_GET["edit"] ?? 0);
$editing = $editId > 0;

$backUrl = $editing
  ? ("/admin/payment/transaction-detail.php?id=" . urlencode((string)$editId))
  : "/admin/payment/transactions.php";

$initial = null;
if ($editing) {
  $stmt = $conn->prepare("SELECT * FROM `Payment` WHERE `id` = ? AND ($ENV_PAY_WHERE) LIMIT 1");
  $stmt->bind_param("i", $editId);
  $stmt->execute();
  $initial = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$initial) {
    http_response_code(404);
    exit("Transaction not found.");
  }
}

$err = "";
$ok  = "";

$form = [
  "id" => $editing ? (string)$editId : "",
  "transaction_id" => $initial["transaction_id"] ?? "",
  "transaction_ref" => $initial["transaction_ref"] ?? "",
  "name" => $initial["name"] ?? "",
  "email" => $initial["email"] ?? "",
  "phone" => $initial["phone"] ?? "",
  "item" => $initial["item"] ?? "",
  "package" => $initial["package"] ?? "General",
  "price" => $initial["price"] ?? "0",
  "channel" => $initial["channel"] ?? ($channels[0] ?? "senangpay-sandbox"),
  "codeid" => $initial["codeid"] ?? "",
  "referred_by" => $initial["referred_by"] ?? "",
  "status" => $initial["status"] ?? "pending",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (function_exists("csrf_validate")) csrf_validate();
  $idPost = (int)($_POST["id"] ?? 0);
  $isUpdate = $idPost > 0;

  $transaction_id = clip($_POST["transaction_id"] ?? null, 50);
  $transaction_ref = clip($_POST["transaction_ref"] ?? null, 120);
  $channel        = clip($_POST["channel"] ?? null, 50);
  $codeid         = clip($_POST["codeid"] ?? null, 50);
  $priceRaw       = trim((string)($_POST["price"] ?? "0"));
  $price          = is_numeric($priceRaw) ? (float)$priceRaw : -1.0;

  $status = strtolower(trim((string)($_POST["status"] ?? "pending")));

  $allowedStatuses = ['pending', 'completed', 'failed', 'cancelled'];
  if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
  }

  $verified = in_array($status, ['completed'], true) ? 1 : 0;

  $name  = clip($_POST["name"] ?? null, 255);
  $email = clip($_POST["email"] ?? null, 255);
  $phone = clip($_POST["phone"] ?? null, 20);

  $item    = clip($_POST["item"] ?? null, 255);
  $package = clip($_POST["package"] ?? null, 50);
  $referred_by = clip($_POST["referred_by"] ?? null, 50);

  // keep sticky values
  $form = [
    "id" => (string)$idPost,
    "transaction_id" => (string)($transaction_id ?? ""),
    "transaction_ref" => (string)($transaction_ref ?? ""),
    "name" => (string)($name ?? ""),
    "email" => (string)($email ?? ""),
    "phone" => (string)($phone ?? ""),
    "item" => (string)($item ?? ""),
    "package" => (string)($package ?? ""),
    "price" => (string)$priceRaw,
    "channel" => (string)($channel ?? ""),
    "codeid" => (string)($codeid ?? ""),
    "referred_by" => (string)($referred_by ?? ""),
    "status" => $status,
  ];

  if (!$transaction_id || !$channel || !$codeid || !$name || !$email || !$phone || !$item) {
    $err = "Required fields cannot be empty.";
  } elseif ($price < 0) {
    $err = "Invalid price.";
  } elseif ($status === 'completed' && !$transaction_ref) {
    $err = "Transaction reference is required when status is completed.";
  } else {
    if ($isUpdate) {
      $sql = "UPDATE `Payment`
              SET `transaction_id`=?,
                  `transaction_ref`=?,
                  `channel`=?,
                  `codeid`=?,
                  `price`=?,
                  `name`=?,
                  `email`=?,
                  `phone`=?,
                  `item`=?,
                  `package`=?,
                  `referred_by`=?,
                  `status`=?,
                  `verified`=?
              WHERE `id`=? LIMIT 1";
      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        error_log("Payment UPDATE prepare failed: " . $conn->error);
        $err = "DB error. Please try again.";
      } else {
        $stmt->bind_param(
          "ssssdsssssssii",
          $transaction_id,
          $transaction_ref,
          $channel,
          $codeid,
          $price,
          $name,
          $email,
          $phone,
          $item,
          $package,
          $referred_by,
          $status,
          $verified,
          $idPost
        );
        if (!$stmt->execute()) {
          $err = "Update failed: " . $stmt->error;
        } else {
          header("Location: /admin/payment/transaction-detail.php?id=" . urlencode((string)$idPost));
          exit;
        }
        $stmt->close();
      }
    } else {
      $sid = "SID-MANUAL-" . random_int(1000, 9999);

      $tsNow = (new DateTime("now", new DateTimeZone("Asia/Kuala_Lumpur")))->format("Y-m-d H:i:s");

      $sql = "INSERT INTO `Payment`
              (`codeid`,`name`,`email`,`phone`,`item`,`package`,`channel`,`price`,
              `transaction_id`,`transaction_ref`,`sid`,`referred_by`,`status`,`verified`,`timestamp`)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        error_log("Payment INSERT prepare failed: " . $conn->error);
        $err = "DB error. Please try again.";
      } else {
        $stmt->bind_param(
          "sssssssdsssssis",
          $codeid,
          $name,
          $email,
          $phone,
          $item,
          $package,
          $channel,
          $price,
          $transaction_id,
          $transaction_ref,
          $sid,
          $referred_by,
          $status,
          $verified,
          $tsNow
        );

        if (!$stmt->execute()) {
          $err = "Insert failed: " . $stmt->error;
        } else {
          header("Location: /admin/payment/transactions.php?added=1");
          exit;
        }
        $stmt->close();
      }
    }
  }
}

$pageTitle = $editing ? "Edit Transaction" : "Add Transaction";
$pageDesc  = $editing
? ("Modifying record for " . ($form["transaction_id"] ?: ("ID#" . $editId)))
: "Manual payment entry for administrative tracking.";

// Back label ikut mode
$backLabel = $editing ? "Back to Details" : "Back to Log";

// Desktop: sama style macam Back to List (text link + arrow animate)
$headerActionsHtmlDesktop = '
<a href="'.h($backUrl).'"
    class="flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
    <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    '.$backLabel.'
</a>
';

$title = $pageTitle;
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>
<div class="md:hidden mb-8">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <h1 class="text-3xl font-black text-slate-900 tracking-tight">
        <?= h((string)$pageTitle) ?>
      </h1>

      <?php if (trim((string)$pageDesc) !== ""): ?>
        <p class="mt-2 text-sm font-semibold text-slate-500 break-words">
          <?= h((string)$pageDesc) ?>
        </p>
      <?php endif; ?>
    </div>

    <!-- Back link belah kanan -->
    <a href="<?= h($backUrl) ?>"
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

<div class="pb-10">
  <div class="max-w-4xl mx-auto">

    <?php if ($err): ?>
      <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl p-4 font-semibold">
        <?= h($err) ?>
      </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      <div class="px-8 py-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <div>
          <h2 class="text-xl font-bold text-slate-900"><?= $editing ? "Edit Transaction" : "Manual Entry" ?></h2>
          <p class="text-sm text-slate-500 font-medium">
            <?= $editing ? ("Modifying record for " . h((string)$form["transaction_id"])) : "Add a new transaction record to the system." ?>
          </p>
        </div>
      </div>

      <form method="POST" class="p-8 space-y-8">
        <?php if (function_exists("csrf_token")): ?>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <?php endif; ?>

        <input type="hidden" name="id" value="<?= h((string)$form["id"]) ?>"/>

        <div class="space-y-4">
          <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest border-l-4 border-amber-400 pl-3">Transaction Details</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Transaction ID</label>
              <input required type="text" name="transaction_id"
                value="<?= h((string)$form["transaction_id"]) ?>"
                placeholder="e.g. TRX-123456"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Transaction Ref</label>
              <input type="text" name="transaction_ref"
                value="<?= h((string)$form["transaction_ref"]) ?>"
                placeholder="e.g. 17083930293"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Payment Status</label>
              <select name="status"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 appearance-none bg-white cursor-pointer text-sm">
                <option value="pending" <?= ((string)$form["status"] === 'pending') ? "selected" : "" ?>>Pending</option>
                <option value="completed" <?= ((string)$form["status"] === 'completed') ? "selected" : "" ?>>Completed</option>
                <option value="failed" <?= ((string)$form["status"] === 'failed') ? "selected" : "" ?>>Failed</option>
                <option value="cancelled" <?= ((string)$form["status"] === 'cancelled') ? "selected" : "" ?>>Cancelled</option>
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Payment Channel</label>
              <select name="channel"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 appearance-none bg-white cursor-pointer text-sm">
                <?php foreach ($channels as $ch): ?>
                  <option value="<?= h($ch) ?>" <?= ((string)$form["channel"] === $ch) ? "selected" : "" ?>><?= h($ch) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Code ID</label>
              <input required type="text" name="codeid"
                value="<?= h((string)$form["codeid"]) ?>"
                placeholder="e.g. C-991"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Price (RM)</label>
              <input required type="number" step="0.01" name="price"
                value="<?= h((string)$form["price"]) ?>"
                placeholder="0.00"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>
          </div>
        </div>

        <div class="space-y-4 pt-4 border-t border-slate-100">
          <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest border-l-4 border-slate-200 pl-3">Customer Information</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-2 md:col-span-2">
              <label class="text-sm font-semibold text-slate-700">Full Name</label>
              <input required type="text" name="name"
                value="<?= h((string)$form["name"]) ?>"
                placeholder="Customer legal name"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Email Address</label>
              <input required type="email" name="email"
                value="<?= h((string)$form["email"]) ?>"
                placeholder="customer@email.com"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Phone Number</label>
              <input required type="tel" name="phone"
                value="<?= h((string)$form["phone"]) ?>"
                placeholder="012-3456789"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>
          </div>
        </div>

        <div class="space-y-4 pt-4 border-t border-slate-100">
          <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest border-l-4 border-slate-200 pl-3">Item & Referrer</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Item Name</label>
              <input required type="text" name="item"
                value="<?= h((string)$form["item"]) ?>"
                placeholder="Product or service name"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Package</label>
              <input type="text" name="package"
                value="<?= h((string)$form["package"]) ?>"
                placeholder="e.g. starter, pro, general"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>

            <div class="space-y-2">
              <label class="text-sm font-semibold text-slate-700">Referrer / Referral Code</label>
              <input type="text" name="referred_by"
                value="<?= h((string)$form["referred_by"]) ?>"
                placeholder="e.g. DEMO0001"
                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all text-sm"/>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-6">
          <a href="<?= $editing ? ("/admin/payment/transaction-detail.php?id=" . urlencode((string)$editId)) : "/admin/payment/transactions.php" ?>"
              class="px-6 py-2.5 text-slate-500 text-sm font-bold rounded-xl hover:bg-slate-50 transition-all">
            Discard
          </a>
          <button type="submit"
            class="px-10 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-extrabold rounded-xl shadow-lg transition-all active:scale-95">
            <?= $editing ? "Update Record" : "Save Transaction" ?>
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>