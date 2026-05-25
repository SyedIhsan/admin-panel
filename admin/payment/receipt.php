<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

$currentView = "transactions.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function formatMYR(float $amount): string {
  if (class_exists('NumberFormatter')) {
    try {
      $fmt = new NumberFormatter('en_MY', NumberFormatter::CURRENCY);
      $out = $fmt->formatCurrency($amount, 'MYR');
      if ($out !== false) return $out;
    } catch (Throwable $e) {}
  }
  return 'RM' . number_format($amount, 2);
}

function formatDateOnly(string $dateStr): string {
  try {
    // ikut version asal kau: assume timestamp UTC -> convert ke KL
    $dt = new DateTime($dateStr, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
    return $dt->format('d F Y'); // "27 January 2026"
  } catch (Throwable $e) {
    return $dateStr;
  }
}

/**
 * Accept:
 * - /receipt.php?id=123
 * - /receipt.php?codeid=ABC12345 (atau code=)
 * - /receipt.php?transaction_id=SDC-...
 */
$idParam     = trim((string)($_GET["id"] ?? ""));
$codeidParam = trim((string)($_GET["codeid"] ?? ($_GET["code"] ?? "")));
$trxParam    = trim((string)($_GET["transaction_id"] ?? ""));

$sqlBase = "SELECT `id`,`codeid`,`transaction_id`,`name`,`email`,`phone`,`item`,`package`,`channel`,`price`,`timestamp`,`sid`,`referred_by`,`discount_code`,`discount_amount`
            FROM `Payment` WHERE %s AND ($ENV_PAY_WHERE) LIMIT 1";

$stmt = null;

if ($idParam !== "" && ctype_digit($idParam) && (int)$idParam > 0) {
  $id = (int)$idParam;
  $sql = str_replace("%s", "`id`=?", $sqlBase);
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $id);
} elseif ($codeidParam !== "") {
  $sql = str_replace("%s", "`codeid`=?", $sqlBase);
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $codeidParam);
} elseif ($trxParam !== "") {
  $sql = str_replace("%s", "`transaction_id`=?", $sqlBase);
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $trxParam);
} else {
  http_response_code(400);
  exit("Missing id/codeid/transaction_id.");
}

$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tx) {
  http_response_code(404);
  exit("Transaction not found.");
}

$paidAmount = (float)($tx["price"] ?? 0);
$discountDeduction = (float)($tx["discount_amount"] ?? 0);
if ($discountDeduction < 0) {
  $discountDeduction = abs($discountDeduction);
}
$hasDiscountDeduction = $discountDeduction > 0;
$originalAmount = $paidAmount + $discountDeduction;
$discountPercentLabel = '';
if ($hasDiscountDeduction && $originalAmount > 0) {
  $discountPercent = ($discountDeduction / $originalAmount) * 100;
  $discountPercentLabel = rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
}

// ✅ redirect/back ikut Payment.id
$detailId = (int)($tx["id"] ?? 0);
$backUrl  = "/admin/payment/transaction-detail.php?id=" . urlencode((string)$detailId);

// ---- Header vars (ikut pattern dashboard/product-form) ----
$pageTitle = "Receipt";
$pageDesc  = "Printable proof of payment for " . (string)($tx["transaction_id"] ?? "");

$headerShowSearch = false;
$headerAddDesktop = false;
$headerAddMobile  = false;

// biar page-header render back button sendiri
$headerBackUrl   = $backUrl;
$headerBackLabel = "Back to Details";

// Desktop: Back + Download + Print (actions override default back)
$headerActionsHtmlDesktop = '
  <div class="flex items-center gap-3 shrink-0">
    <a href="'.h($backUrl).'"
      class="flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
      <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
      <span>Back to Details</span>
    </a>

    <button type="button" onclick="downloadPDF()"
      class="px-4 py-2 rounded-2xl bg-slate-900 text-white shadow-sm hover:bg-slate-800 font-bold inline-flex items-center gap-2">
      <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M12 3v12m0 0l-3-3m3 3l3-3M6 21h12" />
      </svg>
      <span>Download Receipt</span>
    </button>
    <button type="button" onclick="printReceipt()"
      class="flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-500 hover:bg-yellow-600 text-white font-semibold transition shadow-md shadow-yellow-100 active:scale-[0.98]">
      <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
      </svg>
      <span>Print Receipt</span>
    </button>
  </div>
';

// Mobile: hanya icon actions (back biar page-header handle)
$headerActionsHtmlMobile = '
  <button type="button" onclick="downloadPDF()"
    class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition shadow-sm"
    title="Download Receipt" aria-label="Download Receipt">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M12 3v12m0 0l-3-3m3 3l3-3M6 21h12" />
    </svg>
  </button>

  <button type="button" onclick="printReceipt()"
    class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
    title="Print Receipt" aria-label="Print Receipt">
    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
    </svg>
  </button>
';

$title = "Receipt";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<style>
  @media print {
    /* bagi warna background & badge/pill keluar betul */
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    /* hide admin chrome je, jangan kacau receipt styling */
    aside,
    #adminDrawer,
    #notifPanel,
    #notifOverlay,
    .md\:hidden.sticky,
    .hidden.md\:block.sticky {
      display: none !important;
    }

    /* bagi ruang print kemas */
    @page { size: A4; margin: 12mm; }
    html, body { background: #fff !important; }

    /* jangan “strip” receipt */
    .print-receipt { box-shadow: none !important; }
  }

  /* export only */
  #receipt.pdf-export [data-pdf-fix="brand"]{
    line-height: 1 !important;
    position: relative !important;
    top: -2px !important; /* naikkan sikit */
    display: block !important;
  }

  #receipt.pdf-export [data-pdf-fix="pill"]{
    line-height: 1 !important;
    position: relative !important;
    top: -1px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
  }
</style>
<style>
  /* ===== html2canvas PDF export fixes (ONLY for clone) ===== */
  #receipt_export.pdf-export {
    width: 794px !important;
    max-width: 794px !important;
    padding: 48px !important;
    margin: 0 !important;
    border-radius: 0 !important;
  }

  /* hanya untuk preview on-page, bukan untuk html2canvas clone */
  #receipt.pdf-export [data-pdf-fix="brand"]{
    display: inline-block !important;
    font-size: 24px !important;
    line-height: 24px !important;
    position: relative !important;
    top: -2px !important;
  }

  /* Pill GENERAL - jangan guna flex untuk vertical centering masa export */
  #receipt.pdf-export [data-pdf-fix="pill"]{
    display: inline-block !important;
    height: 24px !important;
    line-height: 24px !important;
    padding: 0 12px !important;
    vertical-align: middle !important;
    position: relative !important;
    top: -1px !important;
  }
</style>

<div class="pb-12">
  <div class="max-w-3xl mx-auto space-y-8 pb-12">
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

    <div
      id="receipt"
      class="bg-white p-6 sm:p-12 border border-slate-200 shadow-xl
             print:shadow-none print:border-none print:p-0 print:m-0 print-receipt mx-auto
             rounded-2xl sm:rounded-none print:rounded-none"
    >
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-6 border-b-2 border-slate-900 pb-8 mb-8">
        <div class="space-y-2 min-w-0">
          <div data-pdf-fix="brandrow" class="flex items-center gap-3 mb-2 min-w-0">
            <div data-pdf-fix="logowrap" class="h-10 w-10 shrink-0 flex items-center justify-center">
              <svg data-pdf-fix="logo" xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" class="h-10 w-10" aria-label="Demo"><path d="M6.429 5.143h3v3h-3zm9 0l3 3h-3zm0 13.714l3-3h-3zm-6-13.714h6v3h-6zm-3 10.714h9v3h-9zm0-7.714h3v3.428h-3z"/><path d="M15.429 8.143h3v7.714h-3zm-9 3h3v4.714h-3z"/></svg>
            </div>

            <span data-pdf-fix="brand" data-pdf-nudge="-10"
              class="text-lg sm:text-2xl font-black text-slate-900 tracking-tighter uppercase leading-none break-words min-w-0">
              Demo Company
            </span>
          </div>

          <p class="text-xs text-slate-500 font-bold leading-tight">
            Demo Company<br />
            No. 60-1, Jalan Prima SG 2<br />
            Prima Seri Gombak, 68100 Batu Caves, Selangor
          </p>
        </div>

        <div class="sm:text-right space-y-1 min-w-0">
          <h1 data-pdf-fix="receiptTitle" data-pdf-nudge="-10" class="text-3xl sm:text-4xl font-black text-slate-900 uppercase tracking-tight">Receipt</h1>
          <p data-pdf-fix="receiptCode" class="text-sm font-bold text-slate-400 font-mono break-all">
            <?= h((string)$tx["transaction_id"]) ?>
          </p>
        </div>
      </div>

      <!-- Customer & Info Grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 sm:gap-12 mb-12">
        <div class="space-y-4 min-w-0">
          <h4 data-pdf-fix="sectionTitle" data-pdf-nudge="-4" class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1">Bill To</h4>
          <div class="space-y-1 min-w-0">
            <p class="text-lg font-black text-slate-900 leading-tight break-words"><?= h((string)$tx["name"]) ?></p>
            <p class="text-sm text-slate-500 font-medium break-all"><?= h((string)$tx["email"]) ?></p>
            <p class="text-sm text-slate-500 font-medium break-words"><?= h((string)$tx["phone"]) ?></p>
          </div>
        </div>

        <div class="space-y-4 min-w-0">
          <h4 data-pdf-fix="sectionTitle" data-pdf-nudge="-4" class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1">Transaction Info</h4>

          <div class="grid grid-cols-[90px_1fr] sm:grid-cols-2 gap-x-4 gap-y-2 text-sm min-w-0">
            <span class="text-slate-400 font-bold uppercase text-[10px]">Date</span>
            <span class="text-slate-900 font-bold break-words min-w-0"><?= h(formatDateOnly((string)$tx["timestamp"])) ?></span>

            <span class="text-slate-400 font-bold uppercase text-[10px]">Method</span>
            <span class="text-slate-900 font-bold capitalize break-words min-w-0">
              <?= h(trim(str_replace('-', ' ', (string)$tx["channel"]))) ?>
            </span>

            <span class="text-slate-400 font-bold uppercase text-[10px]">SID</span>
            <span class="text-slate-900 font-mono font-bold break-all min-w-0"><?= h((string)$tx["sid"]) ?></span>

            <?php if (trim((string)($tx["discount_code"] ?? "")) !== ""): ?>
              <span class="text-slate-400 font-bold uppercase text-[10px]">Coupon used</span>
              <span class="text-slate-900 font-mono font-bold break-all min-w-0"><?= h((string)$tx["discount_code"]) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Items Table (scrollable on mobile) -->
      <div class="-mx-6 sm:mx-0 overflow-x-auto">
        <div class="px-6 sm:px-0">
          <table class="w-full min-w-[640px] mb-12">
            <thead>
              <tr class="border-b-2 border-slate-900">
                <th class="py-4 text-left text-xs font-black text-slate-400 uppercase tracking-widest">Description</th>
                <th class="py-4 text-center text-xs font-black text-slate-400 uppercase tracking-widest">Package</th>
                <th class="py-4 text-right text-xs font-black text-slate-400 uppercase tracking-widest">Amount</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr>
                <td class="py-6 align-top">
                  <p class="text-base font-black text-slate-900 break-words"><?= h((string)$tx["item"]) ?></p>
                  <p class="text-xs text-slate-400 font-medium mt-1">Course Content &amp; Digital Access</p>
                </td>
                <td class="py-6 text-center align-top">
                  <span data-pdf-fix="pill" data-pdf-nudge="8"
                    class="inline-flex items-center justify-center px-3 py-1 bg-slate-50 border border-slate-200 rounded-full text-xs font-bold text-slate-600 uppercase leading-none">
                    <span data-pdf-fix="pilltext" data-pdf-nudge="-9"><?= h((string)$tx["package"]) ?></span>
                  </span>
                </td>
                <td class="py-6 text-right align-top text-base font-black text-slate-900 whitespace-nowrap">
                  <?= h(formatMYR($originalAmount)) ?>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <?php if ($hasDiscountDeduction): ?>
                <tr>
                  <td colspan="2" class="py-2 text-right text-sm font-black text-slate-400 uppercase tracking-widest">Discount Deduction<?= $discountPercentLabel !== '' ? ' (' . h($discountPercentLabel) . '%)' : '' ?></td>
                  <td class="py-2 text-right text-base font-black text-emerald-700 whitespace-nowrap">
                    -<?= h(formatMYR($discountDeduction)) ?>
                  </td>
                </tr>
              <?php endif; ?>
              <tr class="border-t-2 border-slate-900">
                <td colspan="2" data-pdf-nudge="-4" class="py-6 text-right text-sm font-black text-slate-400 uppercase tracking-widest">Total Amount Paid</td>
                <td data-pdf-nudge="-8" class="py-6 text-right text-2xl font-black text-slate-900 whitespace-nowrap">
                  <?= h(formatMYR($paidAmount)) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-6 pt-8 border-t border-dashed border-slate-200">
        <div class="max-w-[240px] space-y-2 text-[10px] text-slate-400 font-medium leading-relaxed italic">
          Note: This is a computer-generated receipt. No signature is required. Please keep this for your records.
        </div>
        <div class="text-center space-y-3">
          <div class="w-32 h-1 bg-slate-200 mx-auto"></div>
          <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">Verified Official</p>
        </div>
      </div>

      <div class="mt-14 text-center">
        <p class="text-sm font-bold text-slate-900">Thank you for choosing Demo Company.</p>
        <p class="text-xs text-slate-400 font-medium mt-1">Visit us at https://demo.local</p>
      </div>
    </div>

  </div>
</div>

<!-- PDF libs (only on this page) -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>

<script>
  async function waitAssets(root) {
    // ✅ force Inter to load (avoid fallback metrics)
    if (document.fonts && document.fonts.load) {
      try {
        await Promise.all([
          document.fonts.load('400 16px "Inter"'),
          document.fonts.load('600 16px "Inter"'),
          document.fonts.load('700 16px "Inter"'),
          document.fonts.load('900 24px "Inter"')
        ]);
        if (document.fonts.ready) await document.fonts.ready;
      } catch (e) {}
    }

    const imgs = Array.from(root.querySelectorAll("img"));
    await Promise.all(imgs.map(img => {
      if (img.complete) return Promise.resolve();
      if (img.decode) return img.decode().catch(() => {});
      return new Promise(res => { img.onload = img.onerror = () => res(); });
    }));

    // settle layout
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  }

  async function downloadPDF() {
    const orig = document.getElementById("receipt");
    if (!orig) return;

    const trxId = <?= json_encode((string)($tx["transaction_id"] ?? "receipt")) ?>;
    const safeName = String(trxId).replace(/[^A-Za-z0-9\-_\.]/g, "_");

    const EXPORT_PX = 794; // A4 width ~96dpi
    const SCALE = 2;

    // ✅ make sure fonts + images memang settle dulu
    await waitAssets(orig);
    if (document.fonts?.ready) await document.fonts.ready;
    // force Inter weights (kalau Inter ada)
    try {
      await Promise.all([
        document.fonts.load('400 16px "Inter"'),
        document.fonts.load('600 16px "Inter"'),
        document.fonts.load('700 16px "Inter"'),
        document.fonts.load('900 32px "Inter"'),
      ]);
    } catch(e){}

    // offscreen wrap
    const wrap = document.createElement("div");
    wrap.style.position = "fixed";
    wrap.style.left = "-10000px";
    wrap.style.top = "0";
    wrap.style.width = EXPORT_PX + "px";
    wrap.style.background = "#fff";
    wrap.style.zIndex = "-1";
    document.body.appendChild(wrap);

    // clone
    const clone = orig.cloneNode(true);
    clone.id = "receipt_export";
    clone.setAttribute("data-pdf-root", "1");

    clone.style.width = EXPORT_PX + "px";
    clone.style.maxWidth = EXPORT_PX + "px";
    clone.style.margin = "0";
    clone.style.boxSizing = "border-box";

    wrap.appendChild(clone);

    try {
      await waitAssets(clone);

      const canvas = await html2canvas(clone, {
        scale: SCALE,
        backgroundColor: "#ffffff",
        width: EXPORT_PX,
        windowWidth: EXPORT_PX,
        scrollX: 0,
        scrollY: 0,
        useCORS: true,

        // ❌ JANGAN guna ni dulu, banyak browser jadi blank/transparent
        // foreignObjectRendering: true,

        onclone: (doc) => {
          const root = doc.getElementById("receipt_export");
          if (!root) return;

          root.querySelectorAll('[data-pdf-nudge]').forEach(el => {
            const n = parseFloat(el.getAttribute('data-pdf-nudge') || "0");
            const disp = doc.defaultView.getComputedStyle(el).display;
            if (disp === "inline") el.style.display = "inline-block";

            const existing = el.style.transform && el.style.transform !== "none"
              ? el.style.transform
              : "";

            el.style.transform = `${existing} translateY(${n}px)`.trim();
          });

          // pastikan background putih betul-betul
          doc.documentElement.style.background = "#fff";
          doc.body.style.background = "#fff";
          root.style.background = "#fff";

          const style = doc.createElement("style");
          style.textContent = `
            #receipt_export, #receipt_export *{
              font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, "Noto Sans", "Helvetica Neue", sans-serif !important;
              -webkit-font-smoothing: antialiased !important;
            }

            #receipt_export [data-pdf-fix="pill"]{
              display: inline-block !important;
              height: 20px !important;
              line-height: 20px !important;
              padding: 0 14px !important;
              border-radius: 9999px !important;
              white-space: nowrap !important;
              box-sizing: border-box !important;
            }
            #receipt_export [data-pdf-fix="pilltext"]{
              display: inline-block !important;
              line-height: inherit !important;
            }
          `;
          doc.head.appendChild(style);
        }
      });

      // ✅ Anti-black guarantee (JPEG + transparency issue)
      const finalCanvas = document.createElement("canvas");
      finalCanvas.width = canvas.width;
      finalCanvas.height = canvas.height;
      const ctx = finalCanvas.getContext("2d");
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, finalCanvas.width, finalCanvas.height);
      ctx.drawImage(canvas, 0, 0);

      const imgData = finalCanvas.toDataURL("image/jpeg", 0.98);

      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF("p", "mm", "a4");
      const pageW = pdf.internal.pageSize.getWidth();
      const pageH = pdf.internal.pageSize.getHeight();

      const imgW = pageW;
      const imgH = (finalCanvas.height * imgW) / finalCanvas.width;

      let remaining = imgH;
      pdf.addImage(imgData, "JPEG", 0, 0, imgW, imgH, undefined, "FAST");
      remaining -= pageH;

      while (remaining > 0) {
        pdf.addPage();
        const yy = 0 - (imgH - remaining);
        pdf.addImage(imgData, "JPEG", 0, yy, imgW, imgH, undefined, "FAST");
        remaining -= pageH;
      }

      pdf.save(`receipt_${safeName}.pdf`);
    } finally {
      try { wrap.remove(); } catch(e){}
    }
  }

  // Print tanpa new tab: print dari iframe hidden (popup/dialog je)
  async function printReceipt() {
    const el = document.getElementById("receipt");
    if (!el) return;

    await waitAssets(el);

    const iframe = document.createElement("iframe");
    iframe.style.position = "fixed";
    iframe.style.right = "0";
    iframe.style.bottom = "0";
    iframe.style.width = "0";
    iframe.style.height = "0";
    iframe.style.border = "0";
    document.body.appendChild(iframe);

    // copy stylesheets (kalau admin css/tailwind datang dari <link>)
    const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
      .map(n => n.outerHTML)
      .join("\n");

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
${styles}
<style>
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  @page { size: A4; margin: 12mm; }
  html, body { background:#fff !important; }
</style>
</head>
<body>
  ${el.outerHTML}
</body>
</html>`);
    doc.close();

    // bagi browser settle layout dulu
    setTimeout(() => {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();

      // cleanup lepas print (fallback timer)
      const cleanup = () => {
        try { document.body.removeChild(iframe); } catch(e) {}
      };
      iframe.contentWindow.onafterprint = cleanup;
      setTimeout(cleanup, 2000);
    }, 250);
  }
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>
