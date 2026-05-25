<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
// Strong anti-cache (force most CDNs/plugins to skip caching)
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Expires: 0');
header('Surrogate-Control: no-store'); // untuk reverse proxy/CDN tertentu

setcookie('sp_nocache', '1', [
  'expires'  => 0,
  'path'     => '/payment',
  'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => false,
  'samesite' => 'Lax',
]);

const SST_RATE = 0.08;

require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db.php";

/** @var mysqli|null $conn */
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!($conn instanceof mysqli)) {
  http_response_code(500);
  exit("Database connection unavailable.");
}

$conn->query("SET time_zone = '+08:00'");

// Demo mode: show a placeholder instead of the live SenangPay checkout
if (defined('DEMO_MODE') && DEMO_MODE) {
    echo "<!doctype html><html><head><title>Payment (Demo)</title>"
        . "<script src='https://cdn.tailwindcss.com'></script></head>"
        . "<body class='min-h-screen bg-slate-50 flex items-center justify-center' style='font-family:Inter,ui-sans-serif,sans-serif;'>"
        . "<div class='bg-white rounded-2xl shadow p-10 max-w-md text-center'>"
        . "<h1 class='text-2xl font-bold mb-2'>Demo Checkout</h1>"
        . "<p class='text-slate-500 mb-6'>This is the customer checkout page.<br><small>(Demo — SenangPay not active)</small></p>"
        . "<a href='/admin/payment/dashboard.php' class='bg-yellow-500 text-white px-6 py-3 rounded-xl font-bold'>Back to Admin</a>"
        . "</div></body></html>";
    exit;
}

function columnExists(mysqli $conn, string $table, string $col): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

if (!function_exists('tableExists')) {
  function tableExists(mysqli $conn, string $table): bool
  {
    $t = mysqli_real_escape_string($conn, $table);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return $r && mysqli_num_rows($r) > 0;
  }
}

if (!function_exists('normalizeLifecycleDiscountType')) {
  function normalizeLifecycleDiscountType(?string $type): string
  {
    $type = strtolower(trim((string)$type));
    if ($type === 'percentage') return 'percent';
    return in_array($type, ['percent', 'fixed'], true) ? $type : '';
  }
}

if (!function_exists('findAutoRetentionDiscount')) {
  function findAutoRetentionDiscount(mysqli $conn, string $productId, string $categoryId = '', string $email = ''): ?array
  {
    if (!tableExists($conn, 'Discount_Codes')) return null;
    if (!columnExists($conn, 'Discount_Codes', 'auto_apply_retention')) return null;
    if (!columnExists($conn, 'Discount_Codes', 'retention_discount_type')) return null;
    if (!columnExists($conn, 'Discount_Codes', 'retention_discount_value')) return null;

    $sql = "
      SELECT id, code, product_id, category_id, allowed_email,
             valid_from, valid_until, status,
             retention_discount_type, retention_discount_value
      FROM Discount_Codes
      WHERE status = 'active'
        AND auto_apply_retention = 1
        AND retention_discount_type IS NOT NULL
        AND retention_discount_type <> ''
        AND retention_discount_value IS NOT NULL
        AND retention_discount_value > 0
        AND (product_id IS NULL OR product_id = '' OR product_id = ?)
        AND (category_id IS NULL OR category_id = '' OR category_id = ?)
      ORDER BY
        CASE
          WHEN product_id = ? AND category_id = ? THEN 1
          WHEN product_id = ? AND (category_id IS NULL OR category_id = '') THEN 2
          WHEN (product_id IS NULL OR product_id = '') THEN 3
          ELSE 4
        END,
        id DESC
      LIMIT 20
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;

    $stmt->bind_param("sssss", $productId, $categoryId, $productId, $categoryId, $productId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $email = strtolower(trim($email));
    $nowTs = time();

    foreach ($rows as $row) {
      $type = normalizeLifecycleDiscountType((string)($row['retention_discount_type'] ?? ''));
      $value = (float)($row['retention_discount_value'] ?? 0);

      if ($type === '' || $value <= 0) continue;

      $validFrom = trim((string)($row['valid_from'] ?? ''));
      $validUntil = trim((string)($row['valid_until'] ?? ''));

      if ($validFrom !== '') {
        $fromTs = strtotime($validFrom);
        if ($fromTs !== false && $nowTs < $fromTs) continue;
      }

      if ($validUntil !== '') {
        $untilTs = strtotime($validUntil);
        if ($untilTs !== false && $nowTs > $untilTs) continue;
      }

      $allowedEmail = strtolower(trim((string)($row['allowed_email'] ?? '')));
      if ($allowedEmail !== '') {
        if ($email === '') continue;

        $emailList = preg_split('/[,\s;]+/', $allowedEmail, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emailList = array_values(array_unique(array_map('trim', $emailList)));

        if (!in_array($email, $emailList, true)) continue;
      }

      $row['retention_discount_type'] = $type;
      $row['retention_discount_value'] = $value;
      return $row;
    }

    return null;
  }
}

if (!function_exists('applyLifecycleDiscountAmount')) {
  function applyLifecycleDiscountAmount(float $amount, ?array $discount): array
  {
    if (!$discount || $amount <= 0) return [$amount, 0.0];

    $type = normalizeLifecycleDiscountType((string)($discount['retention_discount_type'] ?? ''));
    $value = (float)($discount['retention_discount_value'] ?? 0);

    if ($type === '' || $value <= 0) return [$amount, 0.0];

    if ($type === 'percent') {
      $discountAmount = $amount * ($value / 100);
    } else {
      $discountAmount = $value;
    }

    $discountAmount = max(0, min($discountAmount, $amount));
    $finalAmount = max(0, $amount - $discountAmount);

    return [
      (float)number_format($finalAmount, 2, '.', ''),
      (float)number_format($discountAmount, 2, '.', '')
    ];
  }
}

if (!function_exists('lifecycleDiscountLabel')) {
  function lifecycleDiscountLabel(?array $discount): string
  {
    if (!$discount) return 'Retention Discount';

    $type = normalizeLifecycleDiscountType((string)($discount['retention_discount_type'] ?? ''));
    $value = (float)($discount['retention_discount_value'] ?? 0);

    if ($type === 'percent') {
      return 'Retention Discount (' . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%)';
    }

    if ($type === 'fixed') {
      return 'Retention Discount (RM' . number_format($value, 2, '.', '') . ')';
    }

    return 'Retention Discount';
  }
}

function durationLabel(int|float|string|null $value, int|float|string|null $unit): string
{
  $v = (int)($value ?? 0);
  $u = strtolower(trim((string)($unit ?? "")));

  if ($v <= 0 || !in_array($u, ['day', 'month'], true)) return "";
  if ($u === 'day') return $v . ' ' . ($v === 1 ? 'day' : 'days');

  return $v . ' ' . ($v === 1 ? 'month' : 'months');
}

$hasProdIsSubscriptionCol   = columnExists($conn, "Products", "is_subscription");
$hasProdDurationValueCol    = columnExists($conn, "Products", "duration_value");
$hasProdDurationUnitCol     = columnExists($conn, "Products", "duration_unit");
$hasProdFirstMonthPriceCol  = columnExists($conn, "Products", "first_month_price");
$hasProdRemainPriceCol      = columnExists($conn, "Products", "remaining_month_price");
$hasProdRetentionPriceCol   = columnExists($conn, "Products", "retention_price");

$hasCatIsSubscriptionCol    = columnExists($conn, "Product_Categories", "is_subscription");
$hasCatDurationValueCol     = columnExists($conn, "Product_Categories", "duration_value");
$hasCatDurationUnitCol      = columnExists($conn, "Product_Categories", "duration_unit");
$hasCatFirstMonthPriceCol   = columnExists($conn, "Product_Categories", "first_month_price");
$hasCatRemainPriceCol       = columnExists($conn, "Product_Categories", "remaining_month_price");
$hasCatRetentionPriceCol    = columnExists($conn, "Product_Categories", "retention_price");

// Products list
$products = [];
$res = $conn->query("
  SELECT
    id,
    name,
    description,
    base_price AS basePrice,
    currency,
    has_categories AS hasCategories,
    status,
    poster
    " . ($hasProdIsSubscriptionCol ? ", is_subscription" : ", 0 AS is_subscription") . "
    " . ($hasProdDurationValueCol ? ", duration_value" : ", NULL AS duration_value") . "
    " . ($hasProdDurationUnitCol ? ", duration_unit" : ", NULL AS duration_unit") . "
    " . ($hasProdFirstMonthPriceCol ? ", first_month_price" : ", NULL AS first_month_price") . "
    " . ($hasProdRemainPriceCol ? ", remaining_month_price" : ", NULL AS remaining_month_price") . "
    " . ($hasProdRetentionPriceCol ? ", retention_price" : ", NULL AS retention_price") . "
    " . (columnExists($conn, "Products", "allow_installment") ? ", allow_installment" : ", 0 AS allow_installment") . "
    " . (columnExists($conn, "Products", "installment_count") ? ", installment_count" : ", NULL AS installment_count") . "
    " . (columnExists($conn, "Products", "installment_interval_unit") ? ", installment_interval_unit" : ", 'month' AS installment_interval_unit") . "
  FROM Products
  WHERE status = 'active'
  ORDER BY id DESC
");
if ($res) $products = $res->fetch_all(MYSQLI_ASSOC);

$requestedProductId = trim((string)($_GET["product"] ?? ""));
$requestedMode = strtolower(trim((string)($_GET["mode"] ?? "initial")));

// Legacy safety: old renewal links should behave as next installment/payment, not expiry extension.
if ($requestedMode === "renewal") {
  $requestedMode = "installment";
}

if (!in_array($requestedMode, ["initial", "installment", "retention"], true)) {
  $requestedMode = "initial";
}

$isCyclePaymentMode = in_array($requestedMode, ["installment", "retention"], true);

$requestedSubId = trim((string)($_GET["sid"] ?? ""));
$requestedCategoryId = trim((string)($_GET["category"] ?? ""));

if ($requestedProductId !== "") {
  $found = null;
  foreach ($products as $p) {
    if ((string)($p["id"] ?? "") === $requestedProductId) {
      $found = $p;
      break;
    }
  }

  if (!$found || strtolower((string)($found["status"] ?? "")) !== "active") {
    http_response_code(404);
    include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/404.php";
    exit;
  }
}

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function money(int|float|string|null $n): string
{
  return number_format((float)($n ?? 0), 2, '.', '');
}

function sanitizeDescriptionHtml(string $html): string
{
  $html = trim($html);
  if ($html === "") return "";

  $allowed = "<p><br><strong><em><u><ul><ol><li>";
  $clean = strip_tags($html, $allowed);
  $clean = preg_replace('/<(\/?)(p|br|strong|em|u|ul|ol|li)(\s[^>]*)?>/i', '<$1$2>', $clean);
  $clean = preg_replace('/<br\s*\/?>/i', '<br>', $clean);
  $clean = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $clean);

  return $clean;
}

function renderDescription(string $text): string
{
  $text = trim($text);
  if ($text === "") return "";

  // If it's Quill/HTML formatted, render HTML (sanitized)
  if (preg_match('/<\/?(p|br|strong|em|u|ul|ol|li)\b/i', $text)) {
    $html = sanitizeDescriptionHtml($text);
    return '<div class="desc">' . $html . '</div>';
  }

  // fallback lama: plain text per line
  $lines = preg_split("/\R/u", $text);
  $out = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;

    if (preg_match('/^Bonus\s*\d+\s*:/i', $ln)) {
      $safe = h($ln);
      $safe = preg_replace('/^(Bonus\s*\d+\s*:)/i', '<span class="font-semibold text-slate-900">$1</span>', $safe);
      $out[] = '<p class="mt-3 text-slate-600 leading-relaxed text-sm">' . $safe . '</p>';
    } else {
      $out[] = '<p class="mt-2 text-slate-600 leading-relaxed text-sm">' . h($ln) . '</p>';
    }
  }
  return implode("", $out);
}

// Selected product from URL (?product=p_xxx) OR first active
$selectedProductId = (string)($_GET["product"] ?? ($products[0]["id"] ?? ""));
$selectedProduct = null;

foreach ($products as $p) {
  if (($p["id"] ?? "") === $selectedProductId) {
    $selectedProduct = $p;
    break;
  }
}

if (!$selectedProduct) {
?>
  <!doctype html>
  <html lang="en">

  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Payment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
      html,
      body {
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
      }
    </style>
    <script src="https://cdn.tailwindcss.com"></script>
  </head>

  <body class="bg-slate-50 min-h-screen flex items-center justify-center p-8">
    <div class="bg-white border rounded-[2.5rem] p-10 max-w-lg w-full text-center shadow-2xl shadow-slate-200">
      <h1 class="text-2xl font-black text-slate-900 mb-2">No Active Products</h1>
      <p class="text-slate-500">Please add a product in the admin dashboard first.</p>
      <a href="/payment/admin-products.php"
        class="inline-block mt-6 px-6 py-3 rounded-2xl bg-yellow-500 font-black text-black hover:bg-yellow-600">
        Go to Admin
      </a>
    </div>
  </body>

  </html>
<?php
  exit;
}

$hasCats = (bool)($selectedProduct["hasCategories"] ?? false);

$cats = [];

if ($hasCats) {
  $stmt = $conn->prepare("
    SELECT id, name, price_modifier AS priceModifier
      " . ($hasCatIsSubscriptionCol ? ", is_subscription" : ", NULL AS is_subscription") . "
      " . ($hasCatDurationValueCol ? ", duration_value" : ", NULL AS duration_value") . "
      " . ($hasCatDurationUnitCol ? ", duration_unit" : ", NULL AS duration_unit") . "
      " . ($hasCatFirstMonthPriceCol ? ", first_month_price" : ", NULL AS first_month_price") . "
      " . ($hasCatRemainPriceCol ? ", remaining_month_price" : ", NULL AS remaining_month_price") . "
      " . ($hasCatRetentionPriceCol ? ", retention_price" : ", NULL AS retention_price") . "
    FROM Product_Categories
    WHERE product_id = ?
    ORDER BY sort_order ASC, id ASC
  ");
  $stmt->bind_param("s", $selectedProductId);
  $stmt->execute();
  $cats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$productName = (string)($selectedProduct["name"] ?? "Product");
$productDesc = trim((string)($selectedProduct["description"] ?? ""));
$basePrice   = (float)($selectedProduct["basePrice"] ?? 0);
$hasCats     = (bool)($selectedProduct["hasCategories"] ?? false);
$posterUrl   = (string)($selectedProduct["poster"] ?? "");
$prefillReferral = trim((string)($_GET["ref"] ?? ""));

$prodIsSubscription = ((int)($selectedProduct["is_subscription"] ?? 0) === 1);
$prodDurationValue = (int)($selectedProduct["duration_value"] ?? 0);
$prodDurationUnit = (string)($selectedProduct["duration_unit"] ?? "");
$prodFirstMonthPrice = ($selectedProduct["first_month_price"] === null || $selectedProduct["first_month_price"] === "") ? null : (float)$selectedProduct["first_month_price"];
$prodRemainingPrice = ($selectedProduct["remaining_month_price"] === null || $selectedProduct["remaining_month_price"] === "") ? null : (float)$selectedProduct["remaining_month_price"];
$prodRetentionPrice = ($selectedProduct["retention_price"] === null || $selectedProduct["retention_price"] === "") ? null : (float)$selectedProduct["retention_price"];

// Defaults used when product has no category.
// Category block below will override these when applicable.
$resolvedRemainingPrice = $prodRemainingPrice;
$resolvedRetentionPrice = $prodRetentionPrice;

// Payment method configuration
$prodAllowInstallment = (int)($selectedProduct["allow_installment"] ?? 0);
$prodInstallmentCount = ($selectedProduct["installment_count"] === null || $selectedProduct["installment_count"] === "") ? null : (int)$selectedProduct["installment_count"];
$prodInstallmentIntervalUnit = (string)($selectedProduct["installment_interval_unit"] ?? "month");

$showSandbox = false;

// Initial selection (first category if exists)
$initialCategoryId = "";
$initialVariantName = $hasCats && count($cats) ? (string)($cats[0]["name"] ?? "") : "";
$initialResolvedIsSubscription = $prodIsSubscription;
$initialDurationLabel = durationLabel($prodDurationValue, $prodDurationUnit);
$initialRenewalPrice = $prodRemainingPrice;
$initialRetentionPrice = $prodRetentionPrice;
$initialResolvedFirstPrice = $prodFirstMonthPrice;

// Always use base price for default display. First payment price only shows if customer selects installment.
$initialSubTotal = $basePrice;

if ($hasCats && count($cats) > 0) {
  $initialCat = $cats[0];

  if ($requestedCategoryId !== "") {
    foreach ($cats as $candidateCat) {
      if ((string)($candidateCat["id"] ?? "") === $requestedCategoryId) {
        $initialCat = $candidateCat;
        break;
      }
    }
  }

  $initialCategoryId = (string)($initialCat["id"] ?? "");
  $catMod = (float)($initialCat["priceModifier"] ?? 0);

  $catSubRaw = $initialCat["is_subscription"] ?? null;
  $catIsSubscription = ($catSubRaw === null || $catSubRaw === "") ? null : ((int)$catSubRaw === 1);
  $resolvedIsSubscription = ($catIsSubscription === null) ? $prodIsSubscription : $catIsSubscription;

  $catDurationValue = ($initialCat["duration_value"] === null || $initialCat["duration_value"] === "") ? null : (int)$initialCat["duration_value"];
  $catDurationUnit = (string)($initialCat["duration_unit"] ?? "");
  $catFirstPrice = ($initialCat["first_month_price"] === null || $initialCat["first_month_price"] === "") ? null : (float)$initialCat["first_month_price"];
  $catRemainingPrice = ($initialCat["remaining_month_price"] === null || $initialCat["remaining_month_price"] === "") ? null : (float)$initialCat["remaining_month_price"];
  $catRetentionPrice = ($initialCat["retention_price"] === null || $initialCat["retention_price"] === "") ? null : (float)$initialCat["retention_price"];

  // For products with variants, installment first payment must come from the selected variant/category.
  $resolvedFirstPrice = $catFirstPrice;
  $resolvedRemainingPrice = $catRemainingPrice ?? $prodRemainingPrice;
  $resolvedRetentionPrice = $catRetentionPrice ?? $prodRetentionPrice;
  $resolvedDurationValue = $catDurationValue ?? $prodDurationValue;
  $resolvedDurationUnit = $catDurationUnit !== "" ? $catDurationUnit : $prodDurationUnit;

  $initialResolvedIsSubscription = $resolvedIsSubscription;
  $initialDurationLabel = durationLabel($resolvedDurationValue, $resolvedDurationUnit);
  $initialRenewalPrice = $resolvedRemainingPrice;
  $initialRetentionPrice = $resolvedRetentionPrice;
  $initialResolvedFirstPrice = $resolvedFirstPrice;

  // Always use base price for default display. First payment price only shows if customer selects installment.
  $initialSubTotal = $basePrice + $catMod;
}

// ── DETERMINE IF INSTALLMENT SHOULD BE SHOWN ──────────────────────
// Initial installment uses full/base price.
// Retention installment uses retention offer price.
$installmentBaseAmount = $initialSubTotal;

if ($requestedMode === 'retention' && $resolvedRetentionPrice !== null) {
  $installmentBaseAmount = $resolvedRetentionPrice;
}

$showInstallment = (
  in_array($requestedMode, ['initial', 'retention'], true) &&
  $prodAllowInstallment === 1 &&
  $prodInstallmentCount !== null &&
  $prodInstallmentCount > 0 &&
  $initialResolvedFirstPrice !== null &&
  $initialResolvedFirstPrice > 0 &&
  $initialResolvedFirstPrice < $installmentBaseAmount
);

$initialInstallmentFirstMonth = null;
$initialInstallmentRemaining = null;
$initialInstallmentCount = $prodInstallmentCount;
$initialInstallmentIntervalUnit = $prodInstallmentIntervalUnit;

if ($showInstallment) {
  $initialInstallmentFirstMonth = $initialResolvedFirstPrice;
  $initialInstallmentRemaining = ($installmentBaseAmount - $initialResolvedFirstPrice) / $prodInstallmentCount;
}

$initialTotalDue = $initialSubTotal * (1 + SST_RATE);
$initialSst = $initialSubTotal * SST_RATE;

// ── MODE OVERRIDE ──────────────────────────────────────────
if ($requestedMode === 'installment' && $resolvedRemainingPrice !== null) {
  // Installment / next payment: charge remaining installment only.
  // This must NOT extend expiry later.
  $initialSubTotal = $resolvedRemainingPrice;
} elseif ($requestedMode === 'retention' && $resolvedRetentionPrice !== null) {
  // Retention / continuation offer: this is the only mode that extends expiry.
  $initialSubTotal = $resolvedRetentionPrice;
}
// Recalculate SST and total due after override
$initialTotalDue = $initialSubTotal * (1 + SST_RATE);
$initialSst = $initialSubTotal * SST_RATE;

// ── PREFILL CUSTOMER DETAILS FOR RENEWAL/RETENTION ──────────
$prefillName = $prefillEmail = $prefillPhone = '';
if (in_array($requestedMode, ['installment', 'retention'], true) && $requestedSubId !== '') {
  $stSub = $conn->prepare(
    "SELECT customer_name, customer_email, customer_phone FROM Subscriptions WHERE id=? LIMIT 1"
  );
  if ($stSub) {
    $stSub->bind_param('i', $requestedSubId);
    $stSub->execute();
    $subRow = $stSub->get_result()->fetch_assoc();
    $stSub->close();
    if ($subRow) {
      $prefillName  = $subRow['customer_name']  ?? '';
      $prefillEmail = $subRow['customer_email'] ?? '';
      $prefillPhone = $subRow['customer_phone'] ?? '';
    }
  }
}

// ── AUTO RETENTION DISCOUNT ─────────────────────────────────
// Retention discount is not a customer-entered code.
// It should auto-apply only for full retention payment.
$initialSummarySubTotal = $initialSubTotal;
$initialRetentionAutoDiscount = null;
$initialRetentionAutoDiscountAmount = 0.0;
$initialRetentionAutoDiscountLabel = '';

if ($requestedMode === 'retention') {
  $retentionAmountBeforeDiscount = $initialSubTotal;

  $initialRetentionAutoDiscount = findAutoRetentionDiscount(
    $conn,
    (string)$selectedProductId,
    (string)$initialCategoryId,
    (string)$prefillEmail
  );

  [$initialSubTotal, $initialRetentionAutoDiscountAmount] = applyLifecycleDiscountAmount(
    (float)$initialSubTotal,
    $initialRetentionAutoDiscount
  );

  if ($initialRetentionAutoDiscountAmount > 0) {
    $initialSummarySubTotal = $retentionAmountBeforeDiscount;
    $initialRetentionAutoDiscountLabel = lifecycleDiscountLabel($initialRetentionAutoDiscount);
  }

  $initialTotalDue = $initialSubTotal * (1 + SST_RATE);
  $initialSst = $initialSubTotal * SST_RATE;
}

// ── PAGE HEADING BASED ON MODE ──────────────────────────────
$pageHeading = match ($requestedMode) {
  'installment' => 'Complete Your Installment Payment',
  'retention'   => 'Special Continuation Offer',
  default       => 'Finalize Your Selection',
};
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h($productName) ?> - Payment</title>
  <link href="../img/demo_logo.svg" rel="icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    html,
    body {
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(8px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .animate-in {
      animation: fadeIn .5s ease both;
    }

    .desc p {
      margin-top: .5rem;
      color: #475569;
      font-size: .875rem;
      line-height: 1.6;
    }

    .desc p:first-child {
      margin-top: 0;
    }

    .desc ul,
    .desc ol {
      margin-top: .5rem;
      padding-left: 1.25rem;
      color: #475569;
      font-size: .875rem;
      line-height: 1.6;
    }

    .desc ul {
      list-style: disc;
    }

    .desc ol {
      list-style: decimal;
    }

    .desc li {
      margin-top: .25rem;
    }
  </style>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 min-h-screen">

  <div class="min-h-screen bg-[#f8fafc] py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-6xl mx-auto">

      <!-- Header -->
      <header class="mb-12 text-center animate-in">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-yellow-50 text-yellow-800 text-xs font-black uppercase tracking-widest mb-4 border border-yellow-100">
          <!-- Shopping bag icon -->
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5 8h14l-1 12H6L5 8zM9 8a3 3 0 016 0" />
          </svg>
          Secure Checkout
        </div>
        <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-4 tracking-tight">
          <?= h($pageHeading) ?>
        </h1>
        <p class="text-slate-500 max-w-xl mx-auto">
          Complete your details to proceed with payment.
        </p>
      </header>

      <main class="grid grid-cols-1 lg:grid-cols-12 gap-12">

        <!-- Left: Poster + Product details -->
        <div class="lg:col-span-5 flex flex-col items-center animate-in">

          <!-- Poster Preview -->
          <div class="flex flex-col items-center">
            <div class="relative group">
              <div class="absolute -inset-2 bg-slate-900/5 rounded-xl blur-xl transition-all duration-500 group-hover:bg-slate-900/10"></div>

              <div class="relative w-64 md:w-80 aspect-[1/1.414] bg-white shadow-2xl overflow-hidden border-8 border-white ring-1 ring-slate-200 transition-transform duration-500 hover:scale-[1.02]">
                <?php if ($posterUrl): ?>
                  <img src="<?= h($posterUrl) ?>" alt="Poster" class="w-full h-full object-cover" loading="lazy" />
                <?php else: ?>
                  <div class="w-full h-full flex items-center justify-center bg-slate-100 text-slate-500 text-sm font-semibold">
                    No poster uploaded
                  </div>
                <?php endif; ?>

                <div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent pointer-events-none"></div>

                <div class="absolute bottom-4 left-4 right-4 text-white">
                  <p class="text-[10px] tracking-widest uppercase opacity-70 mb-1">SDC Checkout</p>
                  <p id="posterLabel" class="text-xs font-semibold tracking-wide italic">
                    <?= h($hasCats ? ($initialVariantName ?: "Choose Package") : "Digital Product") ?>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Product Details Card -->
          <div class="mt-12 w-full bg-white p-8 rounded-2xl shadow-sm border border-slate-100">
            <h2 class="text-2xl font-black text-slate-900 mb-3 tracking-tight"><?= h($productName) ?></h2>

            <?php if ($productDesc !== ""): ?>
              <div class="mb-6">
                <?= renderDescription($productDesc) ?>
              </div>
            <?php else: ?>
              <p class="text-slate-600 leading-relaxed mb-6 text-sm">
                A premium digital product delivered instantly after payment.
              </p>
            <?php endif; ?>

            <div class="space-y-4">
              <div class="flex items-center gap-3 text-sm text-slate-600">
                <div class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-700 border border-yellow-100">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <span>Instant access after payment</span>
              </div>

              <div class="flex items-center gap-3 text-sm text-slate-600">
                <div class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-700 border border-yellow-100">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 11c1.105 0 2-.895 2-2V7a2 2 0 10-4 0v2c0 1.105.895 2 2 2z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 11H7a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2z" />
                  </svg>
                </div>
                <span>Secure payment via SenangPay</span>
              </div>

              <div class="flex items-center gap-3 text-sm text-slate-600">
                <div class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-700 border border-yellow-100">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M18.364 5.636l-1.414 1.414A7 7 0 105.636 18.364l1.414-1.414" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 14l-2-2m2 2l2-2m-2 2V6" />
                  </svg>
                </div>
                <span>Support provided by Demo Team</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Checkout form -->
        <div class="lg:col-span-7 animate-in">
          <form id="checkoutForm" action="/payment/start-payment.php" method="POST" class="space-y-8">
            <input type="hidden" name="product_id" value="<?= h((string)$selectedProductId) ?>">
            <input type="hidden" name="category_id" id="categoryInput" value="<?= h((string)$initialCategoryId) ?>">
            <input type="hidden" name="amount" id="amountInput" value="<?= h(money($initialTotalDue)) ?>">
            <input type="hidden" name="subscription_mode" id="subscriptionModeInput" value="<?= h($requestedMode) ?>">
            <input type="hidden" name="subscription_id" id="subscriptionIdInput" value="<?= h($requestedSubId) ?>">

            <!-- 1) Package selection -->
            <?php if ($hasCats && count($cats) > 0 && $requestedMode === 'initial'): ?>
              <section class="bg-white p-8 rounded-2xl shadow-sm border border-slate-100">
                <h3 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-3">
                  <div class="w-2 h-6 bg-yellow-500 rounded-full"></div>
                  Select Package
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="packageGrid">
                  <?php foreach ($cats as $i => $c):
                    $cid = (string)($c["id"] ?? "");
                    $cname = (string)($c["name"] ?? "");
                    $mod = (float)($c["priceModifier"] ?? 0);
                    $catSubRaw = $c["is_subscription"] ?? null;
                    $catIsSubscription = ($catSubRaw === null || $catSubRaw === "") ? null : ((int)$catSubRaw === 1);
                    $resolvedIsSubscription = ($catIsSubscription === null) ? $prodIsSubscription : $catIsSubscription;
                    $catDurationValue = ($c["duration_value"] === null || $c["duration_value"] === "") ? null : (int)$c["duration_value"];
                    $catDurationUnit = (string)($c["duration_unit"] ?? "");
                    $catFirstPrice = ($c["first_month_price"] === null || $c["first_month_price"] === "") ? null : (float)$c["first_month_price"];
                    $catRemainingPrice = ($c["remaining_month_price"] === null || $c["remaining_month_price"] === "") ? null : (float)$c["remaining_month_price"];
                    $catRetentionPrice = ($c["retention_price"] === null || $c["retention_price"] === "") ? null : (float)$c["retention_price"];

                    // For variant products, use variant/category first payment only.
                    $resolvedFirstPrice = $catFirstPrice;
                    $resolvedRemainingPrice = $catRemainingPrice ?? $prodRemainingPrice;
                    $resolvedRetentionPrice = $catRetentionPrice ?? $prodRetentionPrice;
                    $resolvedDurationValue = $catDurationValue ?? $prodDurationValue;
                    $resolvedDurationUnit = $catDurationUnit !== "" ? $catDurationUnit : $prodDurationUnit;
                    $resolvedDurationLabel = durationLabel($resolvedDurationValue, $resolvedDurationUnit);

                    $fullPrice = $basePrice + $mod;

                    // Default initial checkout must use full/base price.
                    // First month price is only used when customer selects installment.
                    $sub = $fullPrice;

                    if ($requestedMode === 'renewal' && $resolvedRemainingPrice !== null) {
                      $sub = $resolvedRemainingPrice;
                    } elseif ($requestedMode === 'retention' && $resolvedRetentionPrice !== null) {
                      $sub = $resolvedRetentionPrice;
                    }

                    $installmentFirstMonth = $resolvedFirstPrice;
                    $installmentRemaining = null;

                    if (
                      $prodAllowInstallment === 1 &&
                      $installmentFirstMonth !== null &&
                      $installmentFirstMonth > 0 &&
                      $installmentFirstMonth < $fullPrice &&
                      $prodInstallmentCount !== null &&
                      $prodInstallmentCount > 0
                    ) {
                      $installmentRemaining = ($fullPrice - $installmentFirstMonth) / $prodInstallmentCount;
                    }

                    $sst = $sub * SST_RATE;
                    $due = $sub * (1 + SST_RATE);

                    $isActive = ($i === 0);
                  ?>
                    <button
                      type="button"
                      class="packageCard relative flex flex-col p-5 rounded-xl border-2 transition-all text-left group
                             <?= $isActive ? 'border-yellow-500 bg-yellow-50/50' : 'border-slate-100 hover:border-yellow-200 bg-white' ?>"
                      data-id="<?= h($cid) ?>"
                      data-name="<?= h($cname) ?>"
                      data-mod="<?= h((string)$mod) ?>"
                      data-sub="<?= h(money($sub)) ?>"
                      data-sst="<?= h(money($sst)) ?>"
                      data-total="<?= h(money($due)) ?>"
                      data-full-price="<?= h(money($fullPrice)) ?>"
                      data-first-month-price="<?= $installmentFirstMonth === null ? '' : h(money($installmentFirstMonth)) ?>"
                      data-remaining-installment="<?= $installmentRemaining === null ? '' : h(money($installmentRemaining)) ?>"
                      data-installment-count="<?= h((string)($prodInstallmentCount ?? '')) ?>"
                      data-installment-interval="<?= h($prodInstallmentIntervalUnit) ?>"
                      data-is-subscription="<?= $resolvedIsSubscription ? '1' : '0' ?>"
                      data-duration-label="<?= h($resolvedDurationLabel) ?>"
                      data-renewal-price="<?= $resolvedRemainingPrice === null ? '' : h(money($resolvedRemainingPrice)) ?>"
                      aria-pressed="<?= $isActive ? 'true' : 'false' ?>">
                      <span class="text-xs font-black uppercase tracking-wider mb-2 <?= $isActive ? 'text-yellow-700' : 'text-slate-400' ?>">
                        <?= h($cname) ?>
                      </span>

                      <span class="text-xl font-black text-slate-900">RM<?= h(money($due)) ?></span>
                      <span class="text-[11px] mt-2 text-slate-500 leading-tight group-hover:text-slate-700">
                        <?= $resolvedIsSubscription ? 'Pay today only • Next payment link sent later' : 'Includes SST • Instant digital delivery' ?>
                      </span>

                      <div class="checkMark absolute top-4 right-4 <?= $isActive ? '' : 'hidden' ?> text-yellow-600">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8.25 8.25a1 1 0 01-1.414 0l-3.5-3.5a1 1 0 011.414-1.414l2.793 2.793 7.543-7.543a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                        </svg>
                      </div>
                    </button>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>

            <section
              id="subscriptionTermsCard"
              class="bg-white p-8 rounded-2xl shadow-sm border border-slate-100 <?= ($initialResolvedIsSubscription || $showInstallment) ? '' : 'hidden' ?>">
              <h3 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-3">
                <div class="w-2 h-6 bg-yellow-500 rounded-full"></div>
                <span id="termsTitle"><?= $initialResolvedIsSubscription ? 'Subscription Terms' : 'Payment Terms' ?></span>
              </h3>

              <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="mt-3 space-y-1 text-sm text-slate-700">
                  <div id="subDurationRow" class="<?= $initialResolvedIsSubscription ? '' : 'hidden' ?>">
                    Duration: <span id="subDuration" class="font-semibold text-slate-900"><?= h($initialDurationLabel !== '' ? $initialDurationLabel : 'Not configured') ?></span>
                  </div>
                  <div><span id="priceLabel">Price:</span> <span id="subPayToday" class="font-semibold text-slate-900">RM<?= h(money($initialTotalDue)) ?></span></div>
                  <div id="subInstallmentDetailsRow" class="hidden pl-0 text-sm text-slate-600">
                    <span id="subInstallmentDetails"></span>
                  </div>
                </div>
                <p id="paymentTermsNote" class="mt-3 text-[11px] text-slate-500 leading-relaxed">
                  <?php if ($showInstallment): ?>
                    You can choose to pay full today or start with the first payment amount. Future installment payments are <strong>not automatically charged</strong> to your card. You will receive a secure payment link via email/WhatsApp when payment is due.
                  <?php elseif ($initialResolvedIsSubscription): ?>
                    This is a one-time full payment for this access period. Future next-payment links are <strong>not automatically charged</strong> to your card.
                  <?php else: ?>
                    This is a one-time full payment.
                  <?php endif; ?>
                </p>
              </div>
            </section>

            <!-- Payment Method Selection (if applicable) -->
            <section
              id="paymentMethodCard"
              class="bg-white p-8 rounded-2xl shadow-sm border border-slate-100 <?= (in_array($requestedMode, ['initial', 'retention'], true) && $showInstallment) ? '' : 'hidden' ?>">
              <h3 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-3">
                <div class="w-2 h-6 bg-yellow-500 rounded-full"></div>
                Payment Method
              </h3>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="paymentMethodGrid">
                <!-- Full Payment Option -->
                <button
                  type="button"
                  id="paymentMethodFull"
                  class="paymentMethodCard relative flex flex-col p-6 rounded-xl border-2 transition-all text-left border-yellow-500 bg-yellow-50/50"
                  data-method="full"
                  aria-pressed="true">
                  <div class="flex items-start justify-between mb-3">
                    <div>
                      <span class="text-xs font-black uppercase tracking-wider text-yellow-700">Pay Full</span>
                      <p class="text-sm text-slate-600 mt-1">Complete payment now</p>
                    </div>
                    <div class="w-5 h-5 rounded-full border-2 border-yellow-500 bg-yellow-500 flex items-center justify-center">
                      <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8.25 8.25a1 1 0 01-1.414 0l-3.5-3.5a1 1 0 011.414-1.414l2.793 2.793 7.543-7.543a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                      </svg>
                    </div>
                  </div>
                  <span class="text-2xl font-black text-slate-900" id="fullPaymentPrice">RM0.00</span>
                </button>

                <!-- Installment Payment Option -->
                <button
                  type="button"
                  id="paymentMethodInstallment"
                  class="paymentMethodCard relative flex flex-col p-6 rounded-xl border-2 transition-all text-left border-slate-100 hover:border-yellow-200 bg-white <?= $showInstallment ? '' : 'hidden' ?>"
                  data-method="installment"
                  aria-pressed="false">
                  <div class="flex items-start justify-between mb-3">
                    <div>
                      <span class="text-xs font-black uppercase tracking-wider text-slate-400">Installment Payment</span>
                      <p class="text-sm text-slate-600 mt-1">
                        <?= $requestedMode === 'retention' ? 'Pay first retention amount now, next payments later' : 'Pay first amount now, next payments later' ?>
                      </p>
                    </div>
                    <div class="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center">
                    </div>
                  </div>
                  <span class="text-lg font-bold text-slate-900" id="installmentPaymentTerms">RM0 today + next payments</span>
                </button>
              </div>
            </section>

            <!-- Hidden input for payment method -->
            <input type="hidden" name="payment_method" id="paymentMethodInput" value="full">

            <!-- 2) Contact information -->
            <section class="bg-white p-8 rounded-2xl shadow-sm border border-slate-100">
              <h3 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-3">
                <div class="w-2 h-6 bg-yellow-500 rounded-full"></div>
                Contact Information
              </h3>

              <div class="space-y-5">
                <div class="flex flex-col gap-1.5 w-full">
                  <label class="text-sm font-semibold text-slate-700 ml-0.5">Full Name</label>
                  <input
                    type="text"
                    name="fullName"
                    required
                    value="<?= h($prefillName) ?>"
                    placeholder="Jane Doe"
                    class="w-full px-4 py-3 rounded-lg border bg-white text-slate-900 placeholder:text-slate-400 transition-all focus:outline-none focus:ring-2 border-slate-200 focus:border-yellow-500 focus:ring-yellow-500/10" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div class="flex flex-col gap-1.5 w-full">
                    <label class="text-sm font-semibold text-slate-700 ml-0.5">Email Address</label>
                    <input
                      type="email"
                      name="email"
                      required
                      value="<?= h($prefillEmail) ?>"
                      placeholder="jane@example.com"
                      class="w-full px-4 py-3 rounded-lg border bg-white text-slate-900 placeholder:text-slate-400 transition-all focus:outline-none focus:ring-2 border-slate-200 focus:border-yellow-500 focus:ring-yellow-500/10" />
                  </div>

                  <div class="flex flex-col gap-1.5 w-full">
                    <label class="text-sm font-semibold text-slate-700 ml-0.5">Phone Number</label>
                    <input
                      type="tel"
                      name="phone"
                      required
                      value="<?= h($prefillPhone) ?>"
                      placeholder="+60 1X-XXXXXXX"
                      class="w-full px-4 py-3 rounded-lg border bg-white text-slate-900 placeholder:text-slate-400 transition-all focus:outline-none focus:ring-2 border-slate-200 focus:border-yellow-500 focus:ring-yellow-500/10" />
                  </div>

                  <?php if (!$isCyclePaymentMode): ?>
                    <div class="flex flex-col gap-1.5 w-full md:col-span-2">
                      <label class="text-sm font-semibold text-slate-700 ml-0.5">
                        Referral Code <span class="text-slate-400 font-medium">(optional)</span>
                      </label>
                      <input
                        type="text"
                        name="referred_by"
                        value="<?= h($prefillReferral) ?>"
                        placeholder="e.g. DEMO0001"
                        maxlength="50"
                        class="w-full px-4 py-3 rounded-lg border bg-white text-slate-900 placeholder:text-slate-400 transition-all
                              focus:outline-none focus:ring-2 border-slate-200 focus:border-yellow-500 focus:ring-yellow-500/10 uppercase" />
                      <p class="text-xs text-slate-400 mt-1">If you have a referral code, enter it here.</p>
                    </div>

                    <div class="flex flex-col gap-1.5 w-full md:col-span-2">
                      <label class="text-sm font-semibold text-slate-700 ml-0.5">
                        Discount Code <span class="text-slate-400 font-medium">(optional)</span>
                      </label>

                      <div class="flex gap-2">
                        <input
                          type="text"
                          id="discountCode"
                          placeholder="Discount code"
                          maxlength="64"
                          class="w-full px-4 py-3 rounded-lg border bg-white text-slate-900 placeholder:text-slate-400 transition-all uppercase
                                focus:outline-none focus:ring-2 border-slate-200 focus:border-yellow-500 focus:ring-yellow-500/10" />
                        <button
                          type="button"
                          id="applyDiscountBtn"
                          class="shrink-0 px-4 py-3 rounded-lg bg-yellow-500 hover:bg-yellow-600 text-black font-bold">
                          Apply
                        </button>
                      </div>

                      <p id="discountMsg" class="text-xs text-slate-400 mt-1"></p>
                    </div>
                  <?php endif; ?>

                  <input type="hidden" name="discount_code" id="discountCodeHidden" value="">

                </div>
              </div>
            </section>

            <!-- 3) Order summary + completion -->
            <section class="bg-slate-900 p-8 rounded-2xl shadow-xl text-white">
              <h3 class="text-lg font-black mb-6">Order Summary</h3>

              <div class="space-y-3 mb-8">
                <div class="grid grid-cols-[1fr_auto] items-center gap-6 text-sm">
                  <span id="sumLineItem" class="text-slate-300 font-medium truncate">
                    <?= h($hasCats ? ($initialVariantName ?: "Package") : "Digital Product") ?>
                  </span>
                  <span class="text-white font-semibold tabular-nums">
                    RM<span id="sumSubTotal"><?= h(money($initialSummarySubTotal)) ?></span>
                  </span>
                </div>

                <!-- Discount (hidden by default) -->
                <div id="discountRow" class="grid grid-cols-[1fr_auto] items-center gap-6 text-sm <?= $initialRetentionAutoDiscountAmount > 0 ? '' : 'hidden' ?>">
                  <span class="text-slate-400">
                    <?= h($initialRetentionAutoDiscountLabel !== '' ? $initialRetentionAutoDiscountLabel : 'Discount') ?>
                  </span>
                  <span class="text-white font-semibold tabular-nums">
                    -RM<span id="sumDiscount"><?= h(money($initialRetentionAutoDiscountAmount)) ?></span>
                  </span>
                </div>

                <!-- SST -->
                <div class="grid grid-cols-[1fr_auto] items-center gap-6 text-sm">
                  <span class="text-slate-400">SST (<?= number_format(SST_RATE * 100, 0) ?>%)</span>
                  <span class="text-white font-semibold tabular-nums">
                    RM<span id="sumSst"><?= h(money($initialSst)) ?></span>
                  </span>
                </div>

                <!-- Total -->
                <div class="pt-4 border-t border-slate-800 grid grid-cols-[1fr_auto] items-end gap-6">
                  <span class="text-lg font-semibold">Total Due</span>
                  <span class="text-3xl font-black text-yellow-400 tabular-nums">
                    RM<span id="sumTotal"><?= h(money($initialTotalDue)) ?></span>
                  </span>
                </div>
              </div>

              <div class="space-y-6">
                <label class="flex gap-4 cursor-pointer group">
                  <div class="relative flex items-center shrink-0">
                    <input
                      type="checkbox"
                      id="agreedTerms"
                      name="agreedTerms"
                      required
                      class="peer h-5 w-5 cursor-pointer appearance-none rounded border border-slate-700 bg-slate-800 transition-all
														checked:bg-yellow-500 checked:border-yellow-500" />
                    <svg
                      class="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2
														w-3.5 h-3.5 text-black opacity-0 peer-checked:opacity-100 transition-opacity"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7" />
                    </svg>
                  </div>

                  <span class="text-sm text-slate-400 leading-snug group-hover:text-slate-300">
                    I certify that I am at least 18 years old and that I agree to the
                    <a href="/terms.php" class="underline decoration-slate-700 underline-offset-4 hover:text-yellow-300">Terms &amp; Conditions</a>
                    and
                    <a href="/privacy.php" class="underline decoration-slate-700 underline-offset-4 hover:text-yellow-300">Privacy Policy</a>.
                  </span>
                </label>

                <button
                  type="submit"
                  name="pay_mode"
                  value="live"
                  id="payBtn"
                  disabled
                  class="w-full rounded-xl font-black flex items-center justify-between
												px-4 sm:px-6 py-4 sm:py-5
												transition-all transform active:scale-[0.98]
												bg-slate-800 text-slate-500 cursor-not-allowed
												text-[13px] sm:text-base">
                  <span id="payBtnLabel" class="min-w-0 truncate">Complete Payment</span>

                  <span class="flex items-center gap-2 shrink-0">
                    <span id="payBtnAmount" class="tabular-nums">RM<?= h(money($initialTotalDue)) ?></span>
                    <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                  </span>
                </button>

                <?php if ($showSandbox): ?>
                  <button
                    type="submit"
                    name="pay_mode"
                    value="sandbox"
                    id="sandboxBtn"
                    disabled
                    class="w-full py-4 rounded-xl font-black text-sm transition-all active:scale-[0.98]
                           bg-slate-800/60 text-slate-500 cursor-not-allowed border border-slate-700">
                    Test Sandbox Payment
                  </button>
                <?php endif; ?>

                <p class="text-center text-[10px] text-slate-400 uppercase tracking-widest font-black">
                  🛡️ Secure Payment &amp; Digital Delivery by SDC
                </p>
              </div>
            </section>

          </form>
        </div>
      </main>

      <footer class="mt-20 text-center pb-8">
        <p class="text-slate-400 text-sm">© <?= date("Y") ?> Demo Company. All rights reserved.</p>
        <div class="flex justify-center gap-6 mt-4 text-xs font-medium text-slate-500 uppercase tracking-widest">
          <a href="/privacy.php" class="hover:text-yellow-600">Privacy</a>
          <a href="/terms.php" class="hover:text-yellow-600">Terms</a>
          <a href="mailto:support@demo.local" class="hover:text-yellow-600">Help</a>
        </div>
      </footer>
    </div>
  </div>

  <script>
    const SST_RATE = <?= json_encode((float)SST_RATE) ?>;

    const hasCats = <?= $hasCats && count($cats) ? "true" : "false" ?>;

    const categoryInput = document.getElementById("categoryInput");
    const amountInput = document.getElementById("amountInput");

    const posterLabel = document.getElementById("posterLabel");

    const sumLineItem = document.getElementById("sumLineItem");

    const sumSubTotal = document.getElementById("sumSubTotal");
    const sumSst = document.getElementById("sumSst");
    const sumTotal = document.getElementById("sumTotal");
    const subscriptionTermsCard = document.getElementById("subscriptionTermsCard");
    const termsTitleEl = document.getElementById("termsTitle");
    const paymentTermsNoteEl = document.getElementById("paymentTermsNote");
    const subDurationRowEl = document.getElementById("subDurationRow");
    const subDurationEl = document.getElementById("subDuration");
    const subPayTodayEl = document.getElementById("subPayToday");

    const payBtn = document.getElementById("payBtn");
    const payBtnLabel = document.getElementById("payBtnLabel");
    const payBtnAmount = document.getElementById("payBtnAmount");
    const sandboxBtn = document.getElementById("sandboxBtn");
    const agreed = document.getElementById("agreedTerms");

    const rm = (n) => Number(n || 0).toFixed(2);

    const productId = <?= json_encode((string)$selectedProductId) ?>;

    const emailEl = document.querySelector('input[name="email"]');
    const discountEl = document.getElementById("discountCode");
    const discountHidden = document.getElementById("discountCodeHidden");
    const applyDiscountBtn = document.getElementById("applyDiscountBtn");
    const discountMsg = document.getElementById("discountMsg");
    const paymentMethodInput = document.getElementById("paymentMethodInput");
    const retentionAutoDiscountAmount = parseFloat(<?= json_encode((float)$initialRetentionAutoDiscountAmount) ?>);

    function getAutoDiscountAmountForMethod(method) {
      return method === "full" ? retentionAutoDiscountAmount : 0;
    }

    function setDiscountMessage(message = "", type = "") {
      if (!discountMsg) return;

      discountMsg.textContent = message || "";
      discountMsg.className = `text-xs mt-1 ${
      type === "success"
        ? "text-emerald-600"
        : type === "error"
        ? "text-red-500"
        : "text-slate-400"
    }`;
    }

    const discountRow = document.getElementById("discountRow");
    const sumDiscount = document.getElementById("sumDiscount");

    let baseState = {
      subTotal: parseFloat(<?= json_encode((float)$initialSubTotal) ?>),
      sst: parseFloat(<?= json_encode((float)$initialSst) ?>),
      total: parseFloat(<?= json_encode((float)$initialTotalDue) ?>),
    };

    function renderSubscriptionTerms({
      isSubscription,
      durationLabel,
      payToday
    }) {
      if (!subscriptionTermsCard) return;

      const installmentInfo = getInstallmentInfo();
      const hasInstallment =
        installmentInfo.firstMonth > 0 &&
        installmentInfo.remaining > 0 &&
        installmentInfo.count > 0;

      if (!isSubscription && !hasInstallment) {
        subscriptionTermsCard.classList.add("hidden");
        return;
      }

      subscriptionTermsCard.classList.remove("hidden");

      if (termsTitleEl) {
        termsTitleEl.textContent = isSubscription ? "Subscription Terms" : "Payment Terms";
      }

      if (subDurationRowEl) {
        subDurationRowEl.classList.toggle("hidden", !isSubscription);
      }

      if (subDurationEl) {
        subDurationEl.textContent = durationLabel || "Not configured";
      }

      if (subPayTodayEl) {
        subPayTodayEl.textContent = `RM${rm(payToday)}`;
      }

      if (paymentTermsNoteEl) {
        paymentTermsNoteEl.innerHTML = hasInstallment ?
          `You can choose to pay full today or start with the first payment amount. Upcoming installment / next payment links are <strong>not automatically charged</strong> to your card. You will receive a secure payment link via email/WhatsApp when payment is due.` :
          `This is a one-time full payment for this access period. No automatic charge will be made for the next payment.`;
      }
    }

    function renderTotals({
      sst,
      total,
      discountAmount
    }) {
      if (sumSst) sumSst.textContent = rm(sst);
      if (sumTotal) sumTotal.textContent = rm(total);

      if (amountInput) amountInput.value = rm(total);
      if (payBtnAmount) payBtnAmount.textContent = `RM${rm(total)}`;
      if (sandboxBtn) sandboxBtn.textContent = `Test Sandbox Payment - RM${rm(total)}`;

      const da = parseFloat(discountAmount || 0);
      if (discountRow && sumDiscount) {
        if (da > 0.00001) {
          discountRow.classList.remove("hidden");
          sumDiscount.textContent = rm(da);
        } else {
          discountRow.classList.add("hidden");
          sumDiscount.textContent = "0.00";
        }
      }
    }

    async function validateDiscount(code) {
      const email = (emailEl?.value || "").trim();
      if (!email) {
        return {
          ok: false,
          message: "Please fill in your details first before applying a discount code.",
          messageType: "error"
        };
      }

      const fd = new FormData();
      fd.append("product_id", productId);
      fd.append("category_id", categoryInput?.value || "");
      fd.append("email", email);
      fd.append("code", code);
      fd.append("payment_method", paymentMethodInput?.value || "full");

      const res = await fetch("/payment/validate-discount.php", {
        method: "POST",
        body: fd
      });
      return await res.json();
    }

    async function applyDiscountFlow() {
      const code = (discountEl?.value || "").trim();
      const email = (emailEl?.value || "").trim();

      if (!email && code) {
        discountHidden.value = "";
        setDiscountMessage("Please fill in your details first before applying a discount code.", "error");
        renderTotals({
          sst: baseState.sst,
          total: baseState.total,
          discountAmount: 0,
        });
        return;
      }

      if (!code) {
        discountHidden.value = "";
        setDiscountMessage("", "");
        renderTotals({
          sst: baseState.sst,
          total: baseState.total,
          discountAmount: 0,
        });
        return;
      }

      if ((paymentMethodInput?.value || "full") !== "full") {
        if (discountHidden) discountHidden.value = "";
        setDiscountMessage("Discount codes are only available for full payment.", "error");
        renderTotals({
          sst: baseState.sst,
          total: baseState.total,
          discountAmount: 0,
        });
        return;
      }

      applyDiscountBtn.disabled = true;
      applyDiscountBtn.textContent = "Applying...";

      try {
        const out = await validateDiscount(code);
        if (out?.ok) {
          discountHidden.value = code;
          setDiscountMessage(
            out.message || "Discount applied.",
            out?.messageType || "success"
          );
          renderTotals({
            sst: parseFloat(out.sst || baseState.sst),
            total: parseFloat(out.total || baseState.total),
            discountAmount: parseFloat(out.discountAmount || 0),
          });
        } else {
          discountHidden.value = "";
          setDiscountMessage(
            out?.message || "Invalid / expired discount code.",
            out?.messageType || "error"
          );
          renderTotals({
            sst: baseState.sst,
            total: baseState.total,
            discountAmount: 0,
          });
        }
      } catch (e) {
        discountHidden.value = "";
        setDiscountMessage("Error apply discount. Try again.", "error");
        renderTotals({
          sst: baseState.sst,
          total: baseState.total,
          discountAmount: 0,
        });
      } finally {
        applyDiscountBtn.disabled = false;
        applyDiscountBtn.textContent = "Apply";
      }
    }

    applyDiscountBtn?.addEventListener("click", applyDiscountFlow);

    // Re-validate bila user tukar email (kalau dah ada code)
    let tEmail = null;
    emailEl?.addEventListener("input", () => {
      clearTimeout(tEmail);
      tEmail = setTimeout(() => {
        if (discountHidden.value) applyDiscountFlow();
      }, 450);
    });

    function setSubmitState(enabled) {
      if (!enabled) {
        payBtn.disabled = true;
        payBtn.className =
          "w-full rounded-xl font-black flex items-center justify-between " +
          "px-4 sm:px-6 py-4 sm:py-5 text-[13px] sm:text-base " +
          "transition-all transform active:scale-[0.98] " +
          "bg-slate-800 text-slate-500 cursor-not-allowed";
        if (sandboxBtn) {
          sandboxBtn.disabled = true;
          sandboxBtn.className =
            "w-full py-4 rounded-xl font-black text-sm transition-all active:scale-[0.98] " +
            "bg-slate-800/60 text-slate-500 cursor-not-allowed border border-slate-700";
        }
        return;
      }

      payBtn.disabled = false;
      payBtn.className =
        "w-full rounded-xl font-black flex items-center justify-between " +
        "px-4 sm:px-6 py-4 sm:py-5 text-[13px] sm:text-base " +
        "transition-all transform active:scale-[0.98] " +
        "bg-yellow-500 hover:bg-yellow-600 text-black shadow-lg shadow-yellow-500/20";
      if (sandboxBtn) {
        sandboxBtn.disabled = false;
        sandboxBtn.className =
          "w-full py-4 rounded-xl font-black text-sm transition-all active:scale-[0.98] " +
          "bg-slate-800 text-white hover:bg-slate-700 border border-slate-700";
      }
    }

    function updateSummary({
      label,
      subTotal,
      sst,
      total,
      categoryId,
      isSubscription = false,
      durationLabel = ""
    }) {
      if (categoryInput && categoryId !== undefined) categoryInput.value = categoryId;
      if (posterLabel && label) posterLabel.textContent = label;

      if (sumLineItem && label) sumLineItem.textContent = label;

      if (sumSubTotal) sumSubTotal.textContent = rm(subTotal);
      if (sumSst) sumSst.textContent = rm(sst);
      if (sumTotal) sumTotal.textContent = rm(total);

      // track base totals (before discount)
      baseState = {
        subTotal: subTotal,
        sst: sst,
        total: total
      };

      // kalau ada discount code yang dah applied, re-apply ikut package terkini
      if (discountHidden?.value) {
        applyDiscountFlow();
      } else {
        renderTotals({
          sst,
          total,
          discountAmount: 0
        });
      }

      if (amountInput) amountInput.value = rm(total);

      if (payBtnAmount) payBtnAmount.textContent = `RM${rm(total)}`;
      if (sandboxBtn) sandboxBtn.textContent = `Test Sandbox Payment - RM${rm(total)}`;

      renderSubscriptionTerms({
        isSubscription: !!isSubscription,
        durationLabel,
        payToday: total
      });
    }

    function initPackages() {
      const cards = document.querySelectorAll(".packageCard");
      if (!cards.length) return;

      const hoverClass = "hover:border-yellow-200";

      function selectCard(card) {
        cards.forEach((c) => {
          c.setAttribute("aria-pressed", "false");
          c.classList.remove("border-yellow-500", "bg-yellow-50/50");
          c.classList.add("border-slate-100", "bg-white");
          c.classList.add(hoverClass);

          const mark = c.querySelector(".checkMark");
          if (mark) mark.classList.add("hidden");

          const label = c.querySelector("span.text-xs");
          if (label) {
            label.classList.remove("text-yellow-700");
            label.classList.add("text-slate-400");
          }
        });

        card.setAttribute("aria-pressed", "true");
        card.classList.remove("border-slate-100", "bg-white");
        card.classList.add("border-yellow-500", "bg-yellow-50/50");
        card.classList.remove(hoverClass);

        const mark = card.querySelector(".checkMark");
        if (mark) mark.classList.remove("hidden");

        const label = card.querySelector("span.text-xs");
        if (label) {
          label.classList.remove("text-slate-400");
          label.classList.add("text-yellow-700");
        }

        const categoryId = card.dataset.id || "";
        const name = card.dataset.name || "Package";
        const sub = parseFloat(card.dataset.sub || "0");
        const sst = parseFloat(card.dataset.sst || "0");
        const total = parseFloat(card.dataset.total || "0");
        const isSubscription = card.dataset.isSubscription === "1";
        const durationLabel = card.dataset.durationLabel || "";

        updateSummary({
          label: name,
          subTotal: sub,
          sst: sst,
          total: total,
          categoryId,
          isSubscription,
          durationLabel
        });

        syncPaymentMethodLabels();
        applyPaymentMethod(document.getElementById("paymentMethodInput")?.value || "full");
      }

      cards.forEach((card) => {
        card.addEventListener("click", () => selectCard(card));
      });

      // Ensure first card is applied on load
      selectCard(cards[0]);
    }

    agreed?.addEventListener("change", () => {
      setSubmitState(!!agreed.checked);
    });

    document.getElementById("checkoutForm")?.addEventListener("submit", () => {
      payBtn.disabled = true;
      if (payBtnLabel) payBtnLabel.textContent = "Processing Payment...";
      payBtn.classList.remove("bg-yellow-500", "hover:bg-yellow-600");
      payBtn.classList.add("bg-yellow-300", "cursor-wait");
    });

    // If no categories, still set totals based on initial values
    updateSummary({
      label: <?= json_encode($hasCats ? ($initialVariantName ?: "Package") : "Digital Product") ?>,
      subTotal: <?= json_encode((float)$initialSubTotal) ?>,
      sst: <?= json_encode((float)$initialSst) ?>,
      total: <?= json_encode((float)$initialTotalDue) ?>,
      categoryId: <?= json_encode((string)$initialCategoryId) ?>,
      isSubscription: <?= $initialResolvedIsSubscription ? "true" : "false" ?>,
      durationLabel: <?= json_encode($initialDurationLabel) ?>
    });

    if (hasCats) initPackages();
    setSubmitState(false);

    // Payment method handling
    function getActivePackageCard() {
      return document.querySelector('.packageCard[aria-pressed="true"]');
    }

    function getFullPaymentAmount() {
      const card = getActivePackageCard();
      if (card?.dataset.fullPrice) return parseFloat(card.dataset.fullPrice || "0");

      return parseFloat(<?= json_encode((float)$initialSubTotal) ?>);
    }

    function getInstallmentInfo() {
      const card = getActivePackageCard();

      const firstMonth = card?.dataset.firstMonthPrice ?
        parseFloat(card.dataset.firstMonthPrice || "0") :
        parseFloat(<?= json_encode($initialInstallmentFirstMonth ?? 0) ?>);

      const remaining = card?.dataset.remainingInstallment ?
        parseFloat(card.dataset.remainingInstallment || "0") :
        parseFloat(<?= json_encode($initialInstallmentRemaining ?? 0) ?>);

      const count = card?.dataset.installmentCount ?
        parseInt(card.dataset.installmentCount || "0", 10) :
        parseInt(<?= json_encode($initialInstallmentCount ?? 0) ?>, 10);

      const interval = card?.dataset.installmentInterval || <?= json_encode($initialInstallmentIntervalUnit) ?>;

      return {
        firstMonth,
        remaining,
        count,
        interval
      };
    }

    function syncPaymentMethodLabels() {
      const fullPaymentPriceEl = document.getElementById("fullPaymentPrice");
      const installmentPaymentTermsEl = document.getElementById("installmentPaymentTerms");

      if (fullPaymentPriceEl) {
        fullPaymentPriceEl.textContent = `RM${rm(getFullPaymentAmount())}`;
      }

      if (installmentPaymentTermsEl) {
        const {
          firstMonth,
          remaining,
          count,
          interval
        } = getInstallmentInfo();

        if (firstMonth > 0 && remaining > 0 && count > 0) {
          installmentPaymentTermsEl.textContent = `RM${rm(firstMonth)} today + RM${rm(remaining)}/${interval} for ${count} next payments`;
        } else {
          installmentPaymentTermsEl.textContent = "Next payment plan not configured";
        }
      }
    }

    function setPaymentMethodVisual(method) {
      document.querySelectorAll(".paymentMethodCard").forEach((card) => {
        const selected = card.dataset.method === method;

        card.classList.toggle("border-yellow-500", selected);
        card.classList.toggle("bg-yellow-50/50", selected);
        card.classList.toggle("border-slate-100", !selected);
        card.classList.toggle("bg-white", !selected);
        card.setAttribute("aria-pressed", selected ? "true" : "false");

        const label = card.querySelector("span.text-xs");
        if (label) {
          label.classList.toggle("text-yellow-700", selected);
          label.classList.toggle("text-slate-400", !selected);
        }

        const check = card.querySelector(".w-5");
        if (check) {
          check.className = selected ?
            "w-5 h-5 rounded-full border-2 border-yellow-500 bg-yellow-500 flex items-center justify-center" :
            "w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center";

          check.innerHTML = selected ?
            '<svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8.25 8.25a1 1 0 01-1.414 0l-3.5-3.5a1 1 0 011.414-1.414l2.793 2.793 7.543-7.543a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>' :
            "";
        }
      });
    }

    function applyPaymentMethod(method) {
      const installmentCard = document.getElementById("paymentMethodInstallment");
      const paymentMethodInput = document.getElementById("paymentMethodInput");

      if (method === "installment" && installmentCard?.classList.contains("hidden")) {
        method = "full";
      }

      syncPaymentMethodLabels();
      setPaymentMethodVisual(method);

      const priceLabel = document.getElementById("priceLabel");
      const subPayToday = document.getElementById("subPayToday");
      const subInstallmentDetailsRow = document.getElementById("subInstallmentDetailsRow");
      const subInstallmentDetails = document.getElementById("subInstallmentDetails");

      let amountToCharge = getFullPaymentAmount();

      if (method === "installment") {
        const {
          firstMonth,
          remaining,
          count,
          interval
        } = getInstallmentInfo();

        if (firstMonth > 0) {
          amountToCharge = firstMonth;
        }

        if (priceLabel) priceLabel.textContent = "First Payment:";
        if (subPayToday) subPayToday.textContent = `RM${rm(amountToCharge)}`;

        if (subInstallmentDetailsRow && subInstallmentDetails && remaining > 0 && count > 0) {
          subInstallmentDetails.textContent = `Remaining: RM${rm(remaining)}/${interval} for ${count} payments`;
          subInstallmentDetailsRow.classList.remove("hidden");
        }
      } else {
        if (priceLabel) priceLabel.textContent = "Price:";
        if (subPayToday) subPayToday.textContent = `RM${rm(amountToCharge)}`;

        if (subInstallmentDetailsRow) {
          subInstallmentDetailsRow.classList.add("hidden");
        }
      }

      const sst = amountToCharge * SST_RATE;
      const total = amountToCharge + sst;

      baseState = {
        subTotal: amountToCharge,
        sst,
        total,
      };

      const autoDiscountAmount = getAutoDiscountAmountForMethod(method);
      const displaySubTotal = autoDiscountAmount > 0 ? amountToCharge + autoDiscountAmount : amountToCharge;

      if (sumSubTotal) sumSubTotal.textContent = rm(displaySubTotal);

      const hadDiscountValue = !!((discountHidden?.value || "") || (discountEl?.value || "").trim());

      if (method === "installment") {
        if (discountEl) discountEl.value = "";
        if (discountHidden) discountHidden.value = "";

        if (hadDiscountValue) {
          setDiscountMessage("Discount codes are only available for full payment.", "error");
        } else {
          setDiscountMessage("Discount code is not applicable for installment payment.", "");
        }

        renderTotals({
          sst,
          total,
          discountAmount: 0
        });
      } else if (discountHidden?.value) {
        applyDiscountFlow();
      } else {
        renderTotals({
          sst,
          total,
          discountAmount: autoDiscountAmount
        });
      }

      if (paymentMethodInput) paymentMethodInput.value = method;
    }

    document.querySelectorAll(".paymentMethodCard").forEach((btn) => {
      btn.addEventListener("click", function(e) {
        e.preventDefault();
        applyPaymentMethod(this.dataset.method || "full");
      });
    });

    syncPaymentMethodLabels();
    applyPaymentMethod(document.getElementById("paymentMethodInput")?.value || "full");
  </script>

</body>

</html>