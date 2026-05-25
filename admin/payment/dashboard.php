<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$STATUS_OK = "LOWER(TRIM(COALESCE(`status`,''))) = 'completed'";
$VERIFIED_OK = "COALESCE(`verified`,0)=1";
$PRICE_OK = "COALESCE(`price`,0) > 0";

// Use ENV_PAY_WHERE from _init.php to handle Real vs Test separation
$REAL_PAY_WHERE = "($STATUS_OK) AND ($VERIFIED_OK) AND ($PRICE_OK) AND ($ENV_PAY_WHERE)";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }
function money(float $n): string { return "RM" . number_format($n, 2); }

function pctChange(float $current, float $prev): float {
  if ($prev <= 0) return ($current > 0) ? 100.0 : 0.0;
  return (($current - $prev) / $prev) * 100.0;
}
function fmtPct(float $pct): string {
  $sign = $pct > 0 ? "+" : "";
  return $sign . number_format($pct, 1) . "%";
}
function timeAgo(string $ts): string {
  try {
    $now = new DateTime("now", new DateTimeZone("Asia/Kuala_Lumpur"));
    $dt  = new DateTime($ts,  new DateTimeZone("Asia/Kuala_Lumpur"));
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff/60) . " min ago";
    if ($diff < 86400) return floor($diff/3600) . " hours ago";
    return floor($diff/86400) . " days ago";
  } catch (Throwable $e) {
    return $ts;
  }
}

/** KPI: total revenue & total transactions */
$kpi = [
  "totalRevenue" => 0.0,
  "totalTx" => 0,
  "avgOrder" => 0.0,
  "activeProducts" => 0,
];

$res = $conn->query("
  SELECT COALESCE(SUM(`price`),0) AS rev, COUNT(*) AS tx
  FROM `Payment`
  WHERE $REAL_PAY_WHERE
");

if ($res) {
  $row = $res->fetch_assoc();
  $kpi["totalRevenue"] = (float)($row["rev"] ?? 0);
  $kpi["totalTx"] = (int)($row["tx"] ?? 0);
  $res->free();
}
$kpi["avgOrder"] = $kpi["totalTx"] > 0 ? ($kpi["totalRevenue"] / $kpi["totalTx"]) : 0.0;

$activeProductCount = 0;

$resP = $conn->query("SELECT COUNT(*) AS c FROM `Products` WHERE LOWER(`status`)='active'");
if ($resP) {
  $rowP = $resP->fetch_assoc();
  $activeProductCount = (int)($rowP["c"] ?? 0);
  $resP->free();
}

$kpi["activeProducts"] = $activeProductCount;

/** Month-over-month (this month vs last month) untuk change badge */
$tz = new DateTimeZone("Asia/Kuala_Lumpur");
$thisStart = (new DateTime("first day of this month 00:00:00", $tz))->format("Y-m-d H:i:s");
$nextStart = (new DateTime("first day of next month 00:00:00", $tz))->format("Y-m-d H:i:s");
$lastStart = (new DateTime("first day of last month 00:00:00", $tz))->format("Y-m-d H:i:s");

$stmt = $conn->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN `timestamp` >= ? AND `timestamp` < ? AND $REAL_PAY_WHERE THEN `price` END),0) AS rev_this,
    COALESCE(SUM(CASE WHEN `timestamp` >= ? AND `timestamp` < ? AND $REAL_PAY_WHERE THEN `price` END),0) AS rev_last,
    COALESCE(SUM(CASE WHEN `timestamp` >= ? AND `timestamp` < ? AND $REAL_PAY_WHERE THEN 1 END),0) AS tx_this,
    COALESCE(SUM(CASE WHEN `timestamp` >= ? AND `timestamp` < ? AND $REAL_PAY_WHERE THEN 1 END),0) AS tx_last
  FROM `Payment`
");
$revThis = $revLast = 0.0;
$txThis = $txLast = 0;
if ($stmt) {
  $stmt->bind_param("ssssssss", $thisStart, $nextStart, $lastStart, $thisStart, $thisStart, $nextStart, $lastStart, $thisStart);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc() ?? [];
  $revThis = (float)($r["rev_this"] ?? 0);
  $revLast = (float)($r["rev_last"] ?? 0);
  $txThis  = (int)($r["tx_this"] ?? 0);
  $txLast  = (int)($r["tx_last"] ?? 0);
  $stmt->close();
}

$chgRevenue = fmtPct(pctChange($revThis, $revLast));
$chgTx      = fmtPct(pctChange((float)$txThis, (float)$txLast));
$chgAvg     = fmtPct(pctChange(
  $txThis > 0 ? ($revThis/$txThis) : 0.0,
  $txLast > 0 ? ($revLast/$txLast) : 0.0
));

/** Chart data: last 6 months revenue + sales count */
$tz = new DateTimeZone("Asia/Kuala_Lumpur");

$base = new DateTime("first day of this month 00:00:00", $tz);

$months = [];
for ($i = 5; $i >= 0; $i--) {
  $d = (clone $base)->modify("-{$i} months");
  $key = $d->format("Y-m");
  $months[$key] = [
    "label" => $d->format("M Y"), // bagi jelas (Dec 2025)
    "revenue" => 0.0,
    "sales" => 0,
  ];
}

$start6 = (clone $base)->modify("-5 months")->format("Y-m-d H:i:s");

$stmt = $conn->prepare("
  SELECT DATE_FORMAT(`timestamp`, '%Y-%m') AS ym,
         COALESCE(SUM(`price`),0) AS revenue,
         COUNT(*) AS sales
  FROM `Payment`
  WHERE `timestamp` >= ?
    AND $REAL_PAY_WHERE
  GROUP BY ym
  ORDER BY ym ASC
");

if ($stmt) {
  $stmt->bind_param("s", $start6);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $ym = (string)$r["ym"];
    if (isset($months[$ym])) {
      $months[$ym]["revenue"] = (float)$r["revenue"];
      $months[$ym]["sales"]   = (int)$r["sales"];
    }
  }
  $stmt->close();
}

$chartLabels  = array_column($months, "label");
$chartRevenue = array_map(fn($m) => round((float)$m["revenue"], 2), array_values($months));
$chartSales   = array_map(fn($m) => (int)$m["sales"], array_values($months));

/** Recent Activity: last 3 payments */
$recent = [];
$res = $conn->query("
  SELECT `name`,`item`,`price`,`timestamp`
  FROM `Payment`
  WHERE $REAL_PAY_WHERE
  ORDER BY `timestamp` DESC
  LIMIT 3
");
if ($res) {
  while ($r = $res->fetch_assoc()) $recent[] = $r;
  $res->free();
}

$pageTitle = "Dashboard";
$pageDesc  = "Manage your storefront and billing infrastructure.";

$addUrl = "/admin/payment/product-form.php";

$headerActionsHtmlDesktop = '
  <a href="'.h($addUrl).'" class="hidden sm:inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Add Product
  </a>
';

$headerActionsHtmlMobile = '
  <a href="'.h($addUrl).'" class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
     aria-label="Add Product" title="Add Product">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
  </a>
';

$title = "Dashboard";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>
<div class="pb-10">
  <div class="space-y-8 animate-in">
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

  <!-- KPI CARDS (same TSX) -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    <?php
    $stats = [
      ["label" => "Total Revenue",       "value" => money($kpi["totalRevenue"]), "change" => $chgRevenue],
      ["label" => "Total Transactions",  "value" => number_format($kpi["totalTx"]), "change" => null],
      ["label" => "Active Products",     "value" => number_format($kpi["activeProducts"]), "change" => null],
      ["label" => "Avg. Order Value",    "value" => money($kpi["avgOrder"]), "change" => $chgAvg],
    ];

    foreach ($stats as $stat):
      $change = (string)($stat["change"] ?? "");      // ✅ pastikan string
      $hasChange = ($change !== "");
      $isPlus = $hasChange && str_starts_with($change, "+");
    ?>
      <div class="bg-white p-6 rounded-2xl border shadow-sm hover:shadow-md transition-shadow">
        <p class="text-sm font-medium text-gray-500 mb-1"><?= htmlspecialchars((string)$stat["label"]) ?></p>

        <div class="flex items-end justify-between">
          <h3 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars((string)$stat["value"]) ?></h3>

          <?php if ($hasChange): ?>
            <span class="text-xs font-bold px-2 py-1 rounded-full <?= $isPlus ? "bg-emerald-50 text-emerald-600" : "bg-rose-50 text-rose-600" ?>">
              <?= htmlspecialchars($change) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts row (same TSX layout) -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded-2xl border shadow-sm">
      <h3 class="text-lg font-bold text-gray-800 mb-6">Revenue Growth</h3>
      <div class="relative h-64">
        <canvas id="revChart" class="absolute inset-0 w-full h-full"></canvas>
      </div>
    </div>

    <div class="bg-white p-6 rounded-2xl border shadow-sm">
      <h3 class="text-lg font-bold text-gray-800 mb-6">Sales Volume</h3>
      <div class="relative h-64">
        <canvas id="salesChart" class="absolute inset-0 w-full h-full"></canvas>
      </div>
    </div>
  </div>

  <!-- Recent Activity (same TSX) -->
  <div class="bg-white p-6 rounded-2xl border shadow-sm">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Recent Activity</h3>
    <div class="space-y-4">
      <?php if (!$recent): ?>
        <p class="text-sm text-slate-500">No recent activity yet.</p>
      <?php else: ?>
        <?php foreach($recent as $r): ?>
          <div class="flex items-start justify-between gap-3 py-3 border-b last:border-0">
            <!-- left -->
            <div class="flex items-start gap-3 min-w-0">
              <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>

              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate">
                  Payment received: <?= h((string)$r["item"]) ?>
                </p>
                <p class="text-xs text-gray-500 truncate">
                  <?= h(timeAgo((string)$r["timestamp"])) ?> • by <?= h((string)$r["name"]) ?>
                </p>
              </div>
            </div>

            <!-- right -->
            <span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-100 whitespace-nowrap shrink-0">
              <?= h(money((float)$r["price"])) ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div> <!-- end Recent Activity card -->
  </div>
</div>

<!-- Chart.js (replacement for Recharts) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<script>
const labels  = <?= json_encode($chartLabels) ?>;
const sales   = <?= json_encode($chartSales) ?>;
const revenue = <?= json_encode($chartRevenue) ?>;

// ✅ Yellow theme
const LINE = "#F7B500";          // SDC Yellow
const AMBER_TEXT = "#92400e";    // readable (amber-800)
const GRID = "rgba(148,163,184,.25)";

const moneyMYR = (n) =>
  "RM" + Number(n || 0).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const areaGradient = (ctx, top, bottom) => {
  const chart = ctx.chart;
  const { ctx: c, chartArea } = chart;
  if (!chartArea) return null;
  const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
  g.addColorStop(0, top);
  g.addColorStop(1, bottom);
  return g;
};

const crosshairPlugin = {
  id: "crosshair",
  afterDraw(chart) {
    const tooltip = chart.tooltip;
    if (!tooltip || !tooltip.getActiveElements().length) return;

    const { ctx } = chart;
    const { top, bottom } = chart.chartArea;
    const x = tooltip.getActiveElements()[0].element.x;

    ctx.save();
    ctx.beginPath();
    ctx.setLineDash([4, 4]);
    ctx.lineWidth = 1;
    ctx.strokeStyle = "rgba(247,181,0,.45)"; // ✅ yellow-ish crosshair
    ctx.moveTo(x, top);
    ctx.lineTo(x, bottom);
    ctx.stroke();
    ctx.restore();
  }
};

// ---- Revenue (Area/Line) ----
new Chart(document.getElementById("revChart"), {
  type: "line",
  data: {
    labels,
    datasets: [{
      label: "revenue",
      data: revenue,
      borderColor: LINE,
      borderWidth: 3,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 5,
      pointHoverBorderWidth: 2,
      pointHoverBackgroundColor: "#fff",
      pointHoverBorderColor: LINE,
      fill: true,
      backgroundColor: (ctx) =>
        areaGradient(ctx, "rgba(247,181,0,.22)", "rgba(247,181,0,0)")
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: "index", intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: "#fff",
        titleColor: "#111827",
        bodyColor: AMBER_TEXT,                 // ✅ readable, still yellow vibe
        borderColor: "rgba(226,232,240,1)",
        borderWidth: 1,
        padding: 12,
        displayColors: false,
        callbacks: {
          title: (items) => items?.[0]?.label ?? "",
          label: (item) => `${item.dataset.label} : ${moneyMYR(item.parsed.y)}`
        }
      }
    },
    scales: {
      x: {
        grid: { display: false, drawBorder: false },
        ticks: { color: "#94a3b8", font: { size: 12 } }
      },
      y: {
        ticks: { display: false },
        grid: {
          drawBorder: false,
          borderDash: [4, 4],
          color: GRID
        }
      }
    }
  },
  plugins: [crosshairPlugin]
});

// ---- Sales Volume (Bar) ----
new Chart(document.getElementById("salesChart"), {
  type: "bar",
  data: {
    labels,
    datasets: [{
      label: "sales",
      data: sales,
      backgroundColor: LINE,   // ✅ yellow bars
      borderRadius: 10,
      borderSkipped: false
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: "index", intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: "#fff",
        titleColor: "#111827",
        bodyColor: AMBER_TEXT,
        borderColor: "rgba(226,232,240,1)",
        borderWidth: 1,
        padding: 12,
        displayColors: false,
        callbacks: {
          title: (items) => items?.[0]?.label ?? "",
          label: (item) => `${item.dataset.label} : ${Number(item.parsed.y || 0)}`
        }
      }
    },
    scales: {
      x: {
        grid: { display: false, drawBorder: false },
        ticks: { color: "#94a3b8", font: { size: 12 } }
      },
      y: {
        ticks: { display: false },
        grid: {
          drawBorder: false,
          borderDash: [4, 4],
          color: GRID
        }
      }
    }
  }
});
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>