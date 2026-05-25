<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$currentView = basename($_SERVER["PHP_SELF"]);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function ellipsize(string $s, int $max = 20): string {
  $s = trim($s);
  if ($s === '') return '';

  $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
  if ($len <= $max) return $s;

  $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
  return $cut . '...';
}

function formatMYR(float $amount): string {
  return 'RM' . number_format($amount, 2);
}

function dtParts(string $ts): array {
  try {
    $dt = new DateTime($ts);
    $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
    return [
      'date' => $dt->format('d M Y'), // 26 Jan 2024
      'time' => $dt->format('H:i'),   // 10:30
    ];
  } catch (Throwable $e) {
    return ['date' => $ts, 'time' => ''];
  }
}

function channelBadge(string $channel): string {
  $base = 'px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider whitespace-nowrap inline-block';
  $channel = trim($channel);
  if ($channel === "") $channel = "unknown";

  $ch = strtolower($channel);

  if ($ch === 'senangpay-sandbox') $cls = 'bg-purple-100 text-purple-700';
  elseif ($ch === 'elearning')     $cls = 'bg-blue-100 text-blue-700';
  elseif ($ch === 'stripe')        $cls = 'bg-indigo-100 text-indigo-700';
  else                             $cls = 'bg-slate-100 text-slate-700';

  return '<span class="'.$base.' '.$cls.'">'.h($channel).'</span>';
}

function statusBadge(string $status): string {
  $base = 'px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider whitespace-nowrap inline-block';
  $status = trim($status);
  $st = strtolower($status);

  if ($st === "" || $st === "null") { $st = "unknown"; $status = "UNKNOWN"; }

  if (in_array($st, ["completed","complete","success","successful","paid","approved"], true)) {
    $cls = "bg-emerald-100 text-emerald-700";
  } elseif (in_array($st, ["pending","processing","initiated"], true)) {
    $cls = "bg-amber-100 text-amber-700";
  } elseif (in_array($st, ["expired","timeout"], true)) {
    $cls = "bg-rose-100 text-rose-700";
  } elseif (in_array($st, ["failed","cancelled","canceled","rejected","error"], true)) {
    $cls = "bg-slate-200 text-slate-700";
  } else {
    $cls = "bg-slate-100 text-slate-700";
  }

  $label = $status !== "" ? $status : strtoupper($st);
  return '<span class="'.$base.' '.$cls.'">'.h($label).'</span>';
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
  $refs = [];
  foreach ($params as $k => $v) $refs[$k] = &$params[$k];
  array_unshift($refs, $types);
  call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Filters
$q       = trim((string)($_GET["q"] ?? ""));
$channel = trim((string)($_GET["channel"] ?? ""));
$from    = trim((string)($_GET["from"] ?? ""));
$to      = trim((string)($_GET["to"] ?? ""));
$item    = trim((string)($_GET["item"] ?? ""));
$status  = strtolower(trim((string)($_GET["status"] ?? "")));

$page  = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$limit  = 50;
$offset = ($page - 1) * $limit;

// WHERE builder
$where = [];
$params = [];
$types = "";

if ($q !== "") {
  $where[] = "(
    `transaction_id` LIKE CONCAT('%', ?, '%')
    OR `codeid` LIKE CONCAT('%', ?, '%')
    OR `name` LIKE CONCAT('%', ?, '%')
    OR `email` LIKE CONCAT('%', ?, '%')
    OR `phone` LIKE CONCAT('%', ?, '%')
  )";
  $types .= "sssss";
  $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
}

if ($channel !== "") {
  $where[] = "`channel` = ?";
  $types .= "s";
  $params[] = $channel;
}

if ($from !== "") {
  $where[] = "DATE(`timestamp`) >= ?";
  $types .= "s";
  $params[] = $from;
}
if ($to !== "") {
  $where[] = "DATE(`timestamp`) <= ?";
  $types .= "s";
  $params[] = $to;
}
if ($item !== "") {
  $where[] = "`item` LIKE CONCAT('%', ?, '%')";
  $types .= "s";
  $params[] = $item;
}
if ($status !== "") {
  $where[] = "LOWER(TRIM(`status`)) = ?";
  $types .= "s";
  $params[] = $status;
}

$whereSql = "WHERE " . ($where ? (implode(" AND ", $where) . " AND ($ENV_PAY_WHERE)") : $ENV_PAY_WHERE);

// ---- Export CSV ----
if ((string)($_GET["export"] ?? "") === "1") {
  $exportSql = "
    SELECT `transaction_id`,`codeid`,`name`,`email`,`phone`,`item`,`package`,`channel`,`price`,`status`,`referred_by`,`timestamp`
    FROM `Payment`
    $whereSql
    ORDER BY `timestamp` DESC
  ";

  $stmtE = $conn->prepare($exportSql);
  if ($types !== "") bindParams($stmtE, $types, $params);
  $stmtE->execute();
  $resE = $stmtE->get_result();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="transactions.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Transaction ID','Code ID','Name','Email','Phone','Item','Package','Channel','Price (MYR)','Status','Referrer','Timestamp']);

  if ($resE) {
    while ($row = $resE->fetch_assoc()) {
      fputcsv($out, [
        $row["transaction_id"] ?? '',
        $row["codeid"] ?? '',
        $row["name"] ?? '',
        $row["email"] ?? '',
        $row["phone"] ?? '',
        $row["item"] ?? '',
        $row["package"] ?? '',
        $row["channel"] ?? '',
        number_format((float)($row["price"] ?? 0), 2, '.', ''),
        $row["status"] ?? '',
        $row["referred_by"] ?? 'NONE',
        $row["timestamp"] ?? '',
      ]);
    }
  }
  fclose($out);
  $stmtE->close();
  exit;
}

// Channels dropdown
$channels = [];
$chRes = $conn->query("
  SELECT DISTINCT TRIM(`channel`) AS channel
  FROM `Payment`
  WHERE `channel` IS NOT NULL AND TRIM(`channel`) <> ''
  ORDER BY channel ASC
");
if ($chRes) {
  while ($r = $chRes->fetch_assoc()) {
    $ch = trim((string)($r["channel"] ?? ""));
    if ($ch === "") continue;
    $channels[] = $ch;
  }
  $chRes->free();
}

$statuses = [];
$stRes = $conn->query("
  SELECT LOWER(TRIM(`status`)) AS sk, MIN(TRIM(`status`)) AS label
  FROM `Payment`
  WHERE `status` IS NOT NULL AND TRIM(`status`) <> ''
  GROUP BY sk
  ORDER BY sk ASC
");
if ($stRes) {
  while ($r = $stRes->fetch_assoc()) {
    $sk = trim((string)($r["sk"] ?? ""));
    $label = trim((string)($r["label"] ?? ""));
    if ($sk === "") continue;
    if ($label === "") $label = strtoupper($sk);
    $statuses[] = ["value" => $sk, "label" => $label];
  }
  $stRes->free();
}

$selectedChannelLabel = ($channel !== "") ? $channel : "All Channels";

$selectedStatusLabel = "All Status";
if ($status !== "") {
  // cuba cari label yang elok dari $statuses array
  foreach ($statuses as $st) {
    if (($st["value"] ?? "") === $status) { $selectedStatusLabel = (string)$st["label"]; break; }
  }
  if ($selectedStatusLabel === "All Status") $selectedStatusLabel = $status; // fallback
}

// Count
$countSql = "SELECT COUNT(*) AS c FROM `Payment` $whereSql";
$stmtC = $conn->prepare($countSql);
if ($types !== "") bindParams($stmtC, $types, $params);
$stmtC->execute();
$totalRows = (int)($stmtC->get_result()->fetch_assoc()["c"] ?? 0);
$stmtC->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

// Sum revenue
$sumSql = "SELECT COALESCE(SUM(`price`),0) AS total FROM `Payment` $whereSql";
$stmtS = $conn->prepare($sumSql);
if ($types !== "") bindParams($stmtS, $types, $params);
$stmtS->execute();
$totalRevenue = (float)($stmtS->get_result()->fetch_assoc()["total"] ?? 0);
$stmtS->close();

// Data
$dataSql = "
  SELECT
    `id`,`codeid`,`transaction_id`,`name`,`email`,`phone`,
    `item`,`package`,`channel`,`price`,`status`,`timestamp`,`sid`,`referred_by`
  FROM `Payment`
  $whereSql
  ORDER BY `timestamp` DESC
  LIMIT ? OFFSET ?
";

$stmtD = $conn->prepare($dataSql);
$paramsData = $params;
$typesData  = $types . "ii";
$paramsData[] = $limit;
$paramsData[] = $offset;

bindParams($stmtD, $typesData, $paramsData);
$stmtD->execute();
$result = $stmtD->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmtD->close();

// ---- Header ----
$pageTitle = "Transactions";
$pageDesc  = "Transaction log from the Payment table.";

$qs = $_GET;
$qs["export"] = "1";
unset($qs["page"]);
$exportUrl = "/admin/payment/transactions.php?" . http_build_query($qs);

$addTrxUrl = "/admin/payment/add-transaction.php";

$headerActionsHtmlDesktop = '
  <a href="'.h($exportUrl).'"
    class="px-4 py-2 rounded-2xl bg-slate-900 text-white shadow-sm hover:bg-slate-800 font-bold inline-flex items-center gap-2">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
    Export CSV
  </a>

  <a href="'.h($addTrxUrl).'"
    class="hidden sm:inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    Add Transaction
  </a>
';

$headerActionsHtmlMobile = '
  <a href="'.h($exportUrl).'"
    class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition shadow-sm"
    title="Export CSV" aria-label="Export CSV">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
  </a>

  <a href="'.h($addTrxUrl).'"
    class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
    title="Add Transaction" aria-label="Add Transaction">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
  </a>
';

$title = "Transactions";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";

$avgTicket = $totalRows > 0 ? ($totalRevenue / $totalRows) : 0.0;
$pageCount = count($rows);
?>

<div class="pb-10 space-y-8 animate-in">
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

  <!-- Filters -->
  <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
    <form method="GET">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-6">

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Search</label>
          <input type="text" name="q" value="<?= h($q) ?>"
            placeholder="Name / ID / Phone"
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all placeholder:text-slate-300 text-sm" />
        </div>

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Item Filter</label>
          <input type="text" name="item" value="<?= h($item) ?>"
            placeholder="Item name..."
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 transition-all placeholder:text-slate-300 text-sm" />
        </div>

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Channel</label>

          <div class="relative" data-dd>
            <input type="hidden" name="channel" value="<?= h($channel) ?>" data-dd-input>

            <button type="button" data-dd-btn
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-left
                    focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500
                    text-sm font-medium text-slate-700 transition-all shadow-sm
                    flex items-center justify-between gap-3">
              <span class="truncate" data-dd-label><?= h($selectedChannelLabel) ?></span>
              <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
              </svg>
            </button>

            <div class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50" data-dd-panel>
              <div class="max-h-64 overflow-y-auto p-2" data-dd-list>
                <button type="button"
                  class="ddItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 font-semibold text-slate-900"
                  data-value="" data-label="All Channels">All Channels</button>

                <?php foreach ($channels as $ch): ?>
                  <button type="button"
                    class="ddItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 font-semibold text-slate-900"
                    data-value="<?= h($ch) ?>" data-label="<?= h($ch) ?>">
                    <span class="block truncate"><?= h($ch) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Status</label>

          <div class="relative" data-dd>
            <input type="hidden" name="status" value="<?= h($status) ?>" data-dd-input>

            <button type="button" data-dd-btn
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-left
                    focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500
                    text-sm font-medium text-slate-700 transition-all shadow-sm
                    flex items-center justify-between gap-3">
              <span class="truncate" data-dd-label><?= h($selectedStatusLabel) ?></span>
              <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
              </svg>
            </button>

            <div class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50" data-dd-panel>
              <div class="max-h-64 overflow-y-auto p-2" data-dd-list>
                <button type="button"
                  class="ddItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 font-semibold text-slate-900"
                  data-value="" data-label="All Status">All Status</button>

                <?php foreach ($statuses as $st): ?>
                  <button type="button"
                    class="ddItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 font-semibold text-slate-900"
                    data-value="<?= h($st["value"]) ?>" data-label="<?= h($st["label"]) ?>">
                    <span class="block truncate"><?= h($st["label"]) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">From</label>
          <input type="date" name="from" value="<?= h($from) ?>"
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 cursor-pointer text-sm transition-all" />
        </div>

        <div class="space-y-2">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">To</label>
          <input type="date" name="to" value="<?= h($to) ?>"
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-400 cursor-pointer text-sm transition-all" />
        </div>

      </div>

      <div class="flex justify-end gap-3 mt-8 border-t border-slate-100 pt-6">
        <a href="/admin/payment/transactions.php"
          class="px-6 py-2.5 text-slate-500 text-sm font-bold rounded-xl hover:bg-slate-50 transition-all">
          Clear All
        </a>
        <button type="submit"
          class="px-10 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-extrabold rounded-xl shadow-lg shadow-amber-100 transition-all active:scale-95">
          Apply Filters
        </button>
      </div>
    </form>
  </section>

  <!-- Results -->
  <section class="space-y-4">
    <div class="flex items-center justify-between px-2">
      <h2 class="text-xl font-bold text-slate-800">Results</h2>
      <span class="text-xs font-bold text-slate-400 uppercase bg-slate-100 px-3 py-1 rounded-full">
        <?= number_format($totalRows) ?> Total
      </span>
    </div>

    <?php if (!$rows): ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-20 flex flex-col items-center justify-center text-center">
        <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9.172 9.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h3 class="text-lg font-medium text-slate-900">No transactions found</h3>
        <p class="text-slate-500 max-w-sm mt-1">Try adjusting your filters or search terms.</p>
      </div>
    <?php else: ?>

      <div class="overflow-x-auto bg-white border border-slate-200 rounded-2xl shadow-sm">
        <table class="w-full text-left border-separate border-spacing-0">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">#</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Transaction Details</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Customer</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Item / Package</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Amount</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Status</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">Timestamp</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php foreach ($rows as $i => $r):
              $dt = dtParts((string)($r["timestamp"] ?? ""));
              $detailUrl = "/admin/payment/transaction-detail.php?id=" . urlencode((string)($r["id"] ?? ""));
            ?>
              <tr class="hover:bg-slate-50/80 transition-colors group cursor-pointer"
                  data-href="<?= h($detailUrl) ?>">
                <td class="px-6 py-5 text-sm text-slate-400 font-mono align-top">
                  <?= h((string)($offset + $i + 1)) ?>
                </td>

                <td class="px-6 py-5 align-top">
                  <div class="flex flex-col space-y-2">
                    <span class="text-sm font-bold text-slate-900 break-all"><?= h((string)($r["transaction_id"] ?? "")) ?></span>
                    <div class="flex flex-col space-y-1">
                      <?= channelBadge((string)($r["channel"] ?? "")) ?>
                    </div>
                  </div>
                </td>

                <td class="px-6 py-5 align-top">
                  <div class="flex flex-col">
                    <span class="text-sm font-bold text-slate-900 line-clamp-1"><?= h((string)($r["name"] ?? "")) ?></span>
                    <span class="text-xs text-slate-500 truncate max-w-[180px]"><?= h((string)($r["email"] ?? "")) ?></span>
                    <span class="text-xs text-slate-400 font-medium"><?= h((string)($r["phone"] ?? "")) ?></span>
                  </div>
                </td>

                <td class="px-6 py-5 align-top">
                  <div class="flex flex-col">
                    <span class="text-sm font-medium text-slate-700 line-clamp-1">
                      <?= h(ellipsize((string)($r["item"] ?? ""), 20)) ?>
                    </span>
                    <span class="text-xs font-medium text-amber-600 capitalize"><?= h((string)($r["package"] ?? "")) ?></span>
                  </div>
                </td>

                <td class="px-6 py-5 align-top">
                  <span class="text-sm font-bold text-emerald-600 whitespace-nowrap">
                    <?= h(formatMYR((float)($r["price"] ?? 0))) ?>
                  </span>
                </td>

                <td class="px-6 py-5 align-top">
                  <?= statusBadge((string)($r["status"] ?? "")) ?>
                </td>

                <td class="px-6 py-5 align-top">
                  <div class="flex flex-col">
                    <span class="text-xs text-slate-600 font-bold"><?= h($dt["date"]) ?></span>
                    <span class="text-[10px] text-slate-400 font-medium"><?= h($dt["time"]) ?></span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </section>

  <!-- Pagination -->
  <?php
    $qsBase = $_GET;
    $pageUrl = function(int $n) use ($qsBase): string {
      $qs = $qsBase;
      $qs["page"] = $n;
      return "/admin/payment/transactions.php?" . http_build_query($qs);
    };

    $prevDisabled = ($page <= 1);
    $nextDisabled = ($page >= $totalPages);

    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);

    $showing = count($rows);
  ?>

  <footer class="flex items-center justify-between bg-white border border-slate-200 rounded-2xl px-6 py-4 shadow-sm mt-6">
    <p class="text-sm font-bold text-slate-400 uppercase tracking-widest">
      Viewing <?= number_format($showing) ?> Entries
    </p>

    <div class="flex items-center gap-1">
      <a href="<?= h($pageUrl(max(1, $page - 1))) ?>"
        class="w-10 h-10 flex items-center justify-center rounded-xl
              <?= $prevDisabled ? 'text-slate-300 border border-transparent cursor-not-allowed pointer-events-none' : 'text-slate-400 hover:bg-slate-50 transition-all' ?>">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </a>

      <?php for ($p = $start; $p <= $end; $p++): ?>
        <a href="<?= h($pageUrl($p)) ?>"
          class="w-10 h-10 flex items-center justify-center rounded-xl font-bold
                <?= $p === $page ? 'bg-amber-500 text-white shadow-lg shadow-amber-100' : 'hover:bg-slate-50 text-slate-400 transition-all' ?>">
          <?= $p ?>
        </a>
      <?php endfor; ?>

      <a href="<?= h($pageUrl(min($totalPages, $page + 1))) ?>"
        class="w-10 h-10 flex items-center justify-center rounded-xl
              <?= $nextDisabled ? 'text-slate-300 border border-transparent cursor-not-allowed pointer-events-none' : 'text-slate-400 hover:bg-slate-50 transition-all' ?>">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </a>
    </div>
  </footer>

</div>

<script>
  document.querySelectorAll('tr[data-href]').forEach((tr) => {
    tr.addEventListener('click', (e) => {
      if (e.target.closest('button, a, input, select, textarea, label')) return;
      const href = tr.getAttribute('data-href');
      if (href) window.location.href = href;
    });
  });
</script>
<script>
(() => {
  const dds = Array.from(document.querySelectorAll('[data-dd]'));
  if (!dds.length) return;

  const closeAll = () => {
    dds.forEach(dd => dd.querySelector('[data-dd-panel]')?.classList.add('hidden'));
  };

  dds.forEach(dd => {
    const btn   = dd.querySelector('[data-dd-btn]');
    const panel = dd.querySelector('[data-dd-panel]');
    const input = dd.querySelector('[data-dd-input]');
    const label = dd.querySelector('[data-dd-label]');
    const list  = dd.querySelector('[data-dd-list]');
    if (!btn || !panel || !input || !label || !list) return;

    const open = () => { closeAll(); panel.classList.remove('hidden'); };
    const close = () => panel.classList.add('hidden');

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      panel.classList.contains('hidden') ? open() : close();
    });

    list.addEventListener('click', (e) => {
      const item = e.target.closest('.ddItem');
      if (!item) return;

      input.value = item.getAttribute('data-value') || '';
      label.textContent = (item.getAttribute('data-label') || item.textContent || '').trim();

      close();

      // Kalau kau nak auto-apply bila pilih (tak perlu tekan Apply Filters):
      // dd.closest('form')?.submit();
    });
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-dd]')) closeAll();
  });

  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAll();
  });
})();
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>