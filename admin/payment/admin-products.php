<?php
declare(strict_types=1);

require_once __DIR__ . "/_init.php";
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

/** @var mysqli|null $conn */
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!($conn instanceof mysqli) && function_exists('getBillingConn')) {
  $conn = getBillingConn();
}

if (!($conn instanceof mysqli)) {
  http_response_code(500);
  exit("Database connection unavailable.");
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// ── Helpers ───────────────────────────────────────────────────────────────────

if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
  }
}

function money(int|float|string|null $n): string {
  return number_format((float)($n ?? 0), 2, ".", "");
}

function moneyOrDash(mixed $value): string {
  if ($value === null || $value === "" || $value === false) return "—";
  $f = (float)$value;
  if ($f <= 0) return "—";
  return "RM " . number_format($f, 2, ".", "");
}

function durationLabel(mixed $value, mixed $unit): string {
  $v = (int)($value ?? 0);
  $u = strtolower(trim((string)($unit ?? "")));
  if ($v <= 0 || !in_array($u, ["day", "month"], true)) return "";
  return $v . " " . ($u === "day" ? ($v === 1 ? "day" : "days") : ($v === 1 ? "month" : "months"));
}

function intervalLabel(int $count, string $interval): string {
  if ($count <= 0) return "";
  $u = strtolower(trim($interval)) ?: "month";
  if ($count > 1 && !str_ends_with($u, "s")) $u .= "s";
  return "× " . $count . " " . $u;
}

function calcRemaining(float $full, float $first, int $count): ?float {
  if ($count <= 0 || $first <= 0 || $first >= $full) return null;
  $r = ($full - $first) / $count;
  return $r > 0 ? $r : null;
}

function formatPriceRange(float $min, float $max): string {
  if (abs($min - $max) < 0.005) return "RM " . number_format($min, 2, ".", "");
  return "RM " . number_format($min, 2, ".", "") . " – RM " . number_format($max, 2, ".", "");
}

function safeBool(mixed $value): bool {
  return ((int)($value ?? 0)) === 1;
}

function columnExists(mysqli $conn, string $table, string $col): bool {
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

function tableExists(mysqli $conn, string $table): bool {
  $t = mysqli_real_escape_string($conn, $table);
  $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
  return $r && mysqli_num_rows($r) > 0;
}

function firstExistingColumn(mysqli $conn, string $table, array $candidates): string {
  foreach ($candidates as $col) {
    if (columnExists($conn, $table, $col)) return $col;
  }
  return "";
}

function sheetVal(mixed $v): string {
  if ($v === null) return "";
  return (string)$v;
}

function fmtDiscount(mixed $typeRaw, mixed $valueRaw): string {
  $type = strtolower(trim((string)$typeRaw));
  $value = trim((string)$valueRaw);
  if ($value === "") return "";

  if (preg_match('/(RM|%)/i', $value)) {
    return $value;
  }

  if (is_numeric($value)) {
    $num = (float)$value;
    $numFmt = rtrim(rtrim(number_format($num, 2, ".", ""), "0"), ".");
    if ($type === "percent") return $numFmt . "%";
    if ($type === "fixed") return "RM" . $numFmt;
    return $numFmt;
  }

  return $value;
}

function fmtDateReadable(mixed $v): string {
  $s = trim((string)$v);
  if ($s === "") return "";
  $ts = strtotime($s);
  if ($ts === false) return $s;
  return date("d M Y H:i", $ts);
}

function priceWithSst(int|float|string|null $price, float $sstRate = 0.08): string
{
    $base = (float)($price ?? 0);
    return number_format($base * (1 + $sstRate), 2, '.', '');
}

function normalizeSheetRowsToHeaders(array $headers, array $rows): array
{
    $width = count($headers);

    return array_map(static function (array $row) use ($width): array {
        if (count($row) > $width) {
            return array_slice($row, 0, $width);
        }

        if (count($row) < $width) {
            return array_pad($row, $width, '');
        }

        return $row;
    }, $rows);
}

function assertSheetRowWidths(array $headers, array $rows): void
{
    $expected = count($headers);

    foreach ($rows as $i => $row) {
        $actual = count($row);

        if ($actual !== $expected) {
            throw new RuntimeException(
                "PRODUCT LIST export width mismatch at data row " . ($i + 1) .
                ": expected {$expected} columns, got {$actual}."
            );
        }
    }
}

// ── Data Fetching ─────────────────────────────────────────────────────────────

function buildSheetRow(
    string $rowType,
    string $productGroup,
    string $productName,
    string $productId,
    string $variantId,
    bool   $isSub,
    int    $durVal,
    string $durUnit,
    float  $price,
    bool   $allowInstall,
    int    $installCount,
    string $installInt,
    ?float $firstMonth,
    ?float $retention,
    ?float $retFirst,
    ?array $dc,
    string $status
): array {
    $productType     = $isSub ? "Subscription" : "One-time";
    $duration        = durationLabel($durVal, $durUnit);
    $retFirstForCalc = ($retFirst !== null && $retFirst > 0) ? $retFirst : $firstMonth;

    $initRemaining = null;
    if ($allowInstall && $installCount > 0
        && $firstMonth !== null && $firstMonth > 0 && $firstMonth < $price) {
        $initRemaining = calcRemaining($price, $firstMonth, $installCount);
    }

    $retRemaining = null;
    if ($allowInstall && $installCount > 0
        && $retention !== null && $retention > 0
        && $retFirstForCalc !== null && $retFirstForCalc > 0
        && $retFirstForCalc < $retention) {
        $retRemaining = calcRemaining($retention, $retFirstForCalc, $installCount);
    }

    $dcCode      = $dc ? sheetVal($dc["dc_code"] ?? "") : "";
    $dcType      = $dc ? (string)($dc["dc_type"] ?? "") : "";
    $dcValue     = $dc ? (float)($dc["dc_value"] ?? 0) : 0.0;
    $discountFmt = $dc ? fmtDiscount($dcType, $dc["dc_value"] ?? "") : "";

    $initialFinalPrice = "";
    if ($discountFmt !== "" && $price > 0) {
        $discountType = strtolower(trim($dcType));
        $disc = ($discountType === "percent") ? $price * ($dcValue / 100) : $dcValue;
        $initialFinalPrice = money(max(0.0, $price - $disc));
    }

    $retDcType  = $dc ? (string)($dc["dc_ret_type"] ?? "") : "";
    $autoRet    = $dc ? ((int)($dc["dc_auto_ret"] ?? 0) === 1) : false;
    $retDiscFmt = ($autoRet && $retDcType !== "") ? fmtDiscount($retDcType, $dc["dc_ret_value"] ?? "") : "";
    $retDcCode  = ($retDiscFmt !== "") ? $dcCode : "";

    return [
        $rowType,                                                                           // 1  Row Type
        $productGroup,                                                                      // 2  Product Group
        $productName,                                                                       // 3  Product Name
        $productId,                                                                         // 4  Product ID
        $variantId,                                                                         // 5  Variant ID
        $productType,                                                                       // 6  Product Type
        $duration,                                                                          // 7  Duration
        $price > 0 ? money($price) : "",                                                   // 8  Price
        $price > 0 ? priceWithSst($price) : "",                                            // 9  Price w SST
        $allowInstall ? "Yes" : "No",                                                      // 10 Allow Installment
        $installCount > 0 ? (string)$installCount : "",                                    // 11 Installment Count
        $installCount > 0 ? $installInt : "",                                              // 12 Installment Interval
        $firstMonth !== null && $firstMonth > 0 ? money($firstMonth) : "",                // 13 Initial First Payment
        $initRemaining !== null ? money($initRemaining) : "",                              // 14 Initial Remaining Installment
        $discountFmt,                                                                       // 15 Initial Discount
        $dcCode,                                                                            // 16 Initial Discount Code
        $initialFinalPrice,                                                                 // 17 Initial Final Price
        $retention !== null && $retention > 0 ? money($retention) : "",                   // 18 Retention Offer Price
        $retFirstForCalc !== null && $retFirstForCalc > 0 ? money($retFirstForCalc) : "", // 19 Retention First Payment
        $retRemaining !== null ? money($retRemaining) : "",                                // 20 Retention Remaining Installment
        $retDiscFmt,                                                                        // 21 Retention Discount
        $retDcCode,                                                                         // 22 Retention Discount Code
        $status,                                                                            // 23 Status
    ];
}

function fetchProductsForSheetsExport(mysqli $conn): array
{
    // Schema discovery
    $hasProdIsSub       = columnExists($conn, "Products", "is_subscription");
    $hasProdDurVal      = columnExists($conn, "Products", "duration_value");
    $hasProdDurUnit     = columnExists($conn, "Products", "duration_unit");
    $hasProdFirstMonth  = columnExists($conn, "Products", "first_month_price");
    $hasProdRetention   = columnExists($conn, "Products", "retention_price");
    $hasProdRetFirst    = columnExists($conn, "Products", "retention_first_month_price");
    $hasAllowInstall    = columnExists($conn, "Products", "allow_installment");
    $hasInstallCount    = columnExists($conn, "Products", "installment_count");
    $hasInstallInterval = columnExists($conn, "Products", "installment_interval_unit");

    $hasCatIsSub      = columnExists($conn, "Product_Categories", "is_subscription");
    $hasCatDurVal     = columnExists($conn, "Product_Categories", "duration_value");
    $hasCatDurUnit    = columnExists($conn, "Product_Categories", "duration_unit");
    $hasCatFirstMonth = columnExists($conn, "Product_Categories", "first_month_price");
    $hasCatRetention  = columnExists($conn, "Product_Categories", "retention_price");
    $hasCatRetFirst   = columnExists($conn, "Product_Categories", "retention_first_month_price");

    $hasDiscountCodes = tableExists($conn, "Discount_Codes");
    $dcProductCol     = $hasDiscountCodes ? firstExistingColumn($conn, "Discount_Codes", ["product_id", "productid"]) : "";
    $dcCodeCol        = $hasDiscountCodes ? firstExistingColumn($conn, "Discount_Codes", ["code", "discount_code"]) : "";
    $dcTypeCol        = $hasDiscountCodes ? firstExistingColumn($conn, "Discount_Codes", ["discount_type", "discounttype"]) : "";
    $dcValueCol       = $hasDiscountCodes ? firstExistingColumn($conn, "Discount_Codes", ["discount_value", "discountvalue"]) : "";
    $hasDcStatus      = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "status");
    $hasDcValidFrom   = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "valid_from");
    $hasDcValidUntil  = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "valid_until");
    $hasDcCategoryId  = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "category_id");
    $hasDcRetType     = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "retention_discount_type");
    $hasDcRetValue    = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "retention_discount_value");
    $hasDcAutoRet     = $hasDiscountCodes && columnExists($conn, "Discount_Codes", "auto_apply_retention");

    // Fetch all products
    $pSel = [
        "p.id",
        "p.name",
        "p.base_price",
        "p.status",
        ($hasProdIsSub       ? "p.is_subscription"            : "0")    . " AS is_subscription",
        ($hasProdDurVal      ? "p.duration_value"              : "NULL") . " AS duration_value",
        ($hasProdDurUnit     ? "p.duration_unit"               : "NULL") . " AS duration_unit",
        ($hasProdFirstMonth  ? "p.first_month_price"           : "NULL") . " AS first_month_price",
        ($hasProdRetention   ? "p.retention_price"             : "NULL") . " AS retention_price",
        ($hasProdRetFirst    ? "p.retention_first_month_price" : "NULL") . " AS retention_first_month_price",
        ($hasAllowInstall    ? "p.allow_installment"           : "0")    . " AS allow_installment",
        ($hasInstallCount    ? "p.installment_count"           : "NULL") . " AS installment_count",
        ($hasInstallInterval ? "p.installment_interval_unit"   : "NULL") . " AS installment_interval_unit",
    ];
    $pSql = "SELECT " . implode(", ", $pSel) . " FROM Products p ORDER BY p.id DESC";
    $pRes = $conn->query($pSql);
    if (!$pRes) return [];

    $products = $pRes->fetch_all(MYSQLI_ASSOC);
    if (empty($products)) return [];

    $productIds = array_column($products, "id");
    $inList = implode(",", array_map(
        fn($id) => "'" . $conn->real_escape_string((string)$id) . "'",
        $productIds
    ));

    // Fetch all categories in one query
    $catSel = [
        "c.id AS cat_id",
        "c.product_id",
        "c.name AS cat_name",
        "c.price_modifier",
        ($hasCatIsSub      ? "c.is_subscription"            : "NULL") . " AS is_subscription",
        ($hasCatDurVal     ? "c.duration_value"              : "NULL") . " AS duration_value",
        ($hasCatDurUnit    ? "c.duration_unit"               : "NULL") . " AS duration_unit",
        ($hasCatFirstMonth ? "c.first_month_price"           : "NULL") . " AS first_month_price",
        ($hasCatRetention  ? "c.retention_price"             : "NULL") . " AS retention_price",
        ($hasCatRetFirst   ? "c.retention_first_month_price" : "NULL") . " AS retention_first_month_price",
    ];
    $catSql = "SELECT " . implode(", ", $catSel)
            . " FROM Product_Categories c"
            . " WHERE c.product_id IN ({$inList})"
            . " ORDER BY c.product_id DESC, c.sort_order ASC, c.id ASC";
    $catRes = $conn->query($catSql);

    $catsByProduct = [];
    if ($catRes) {
        foreach ($catRes->fetch_all(MYSQLI_ASSOC) as $cat) {
            $catsByProduct[(string)$cat["product_id"]][] = $cat;
        }
    }

    // Fetch all active, non-expired discounts — keyed by [product_id][category_id | ""]
    $discountMap = [];
    if ($hasDiscountCodes && $dcProductCol !== "" && $dcCodeCol !== "") {
        $nowEsc = $conn->real_escape_string(date("Y-m-d H:i:s"));

        $dcSel = [
            "d.`{$dcProductCol}` AS dc_product_id",
            ($hasDcCategoryId ? "d.category_id" : "NULL") . " AS dc_category_id",
            "d.`{$dcCodeCol}` AS dc_code",
            ($dcTypeCol !== ""  ? "d.`{$dcTypeCol}`"  : "NULL") . " AS dc_type",
            ($dcValueCol !== "" ? "d.`{$dcValueCol}`" : "NULL") . " AS dc_value",
            ($hasDcRetType  ? "d.retention_discount_type"  : "NULL") . " AS dc_ret_type",
            ($hasDcRetValue ? "d.retention_discount_value" : "NULL") . " AS dc_ret_value",
            ($hasDcAutoRet  ? "d.auto_apply_retention"     : "0")    . " AS dc_auto_ret",
        ];

        $dcWhere = "d.`{$dcProductCol}` IN ({$inList})";
        if ($hasDcStatus)     $dcWhere .= " AND d.status = 'active'";
        if ($hasDcValidFrom)  $dcWhere .= " AND (d.valid_from IS NULL OR d.valid_from <= '{$nowEsc}')";
        if ($hasDcValidUntil) $dcWhere .= " AND (d.valid_until IS NULL OR d.valid_until >= '{$nowEsc}')";

        // ORDER BY id DESC so first-seen per group = highest ID = preferred
        $dcSql = "SELECT " . implode(", ", $dcSel)
               . " FROM Discount_Codes d"
               . " WHERE {$dcWhere}"
               . " ORDER BY d.id DESC";

        $dcRes = $conn->query($dcSql);
        if ($dcRes) {
            foreach ($dcRes->fetch_all(MYSQLI_ASSOC) as $dcRow) {
                $dcPid = (string)$dcRow["dc_product_id"];
                $dcCid = ($dcRow["dc_category_id"] !== null && $dcRow["dc_category_id"] !== "")
                         ? (string)$dcRow["dc_category_id"] : "";
                if (!isset($discountMap[$dcPid][$dcCid])) {
                    $discountMap[$dcPid][$dcCid] = $dcRow;
                }
            }
        }
    }

    // Build export rows: 1 variant = 1 row
    $exportRows = [];

    foreach ($products as $p) {
        $pid         = (string)$p["id"];
        $productName = (string)$p["name"];
        $basePrice   = (float)$p["base_price"];
        $status      = strtolower(trim((string)($p["status"] ?? "active")));
        if ($status !== "inactive") $status = "active";

        $allowInstall = safeBool($p["allow_installment"] ?? 0);
        $installCount = (int)($p["installment_count"] ?? 0);
        $installInt   = trim((string)($p["installment_interval_unit"] ?? "month")) ?: "month";

        $prodIsSub    = safeBool($p["is_subscription"] ?? 0);
        $prodDurVal   = (int)($p["duration_value"] ?? 0);
        $prodDurUnit  = (string)($p["duration_unit"] ?? "");
        $prodFirst    = ($p["first_month_price"] !== null && $p["first_month_price"] !== "")
                        ? (float)$p["first_month_price"] : null;
        $prodRet      = ($p["retention_price"] !== null && $p["retention_price"] !== "")
                        ? (float)$p["retention_price"] : null;
        $prodRetFirst = ($p["retention_first_month_price"] !== null && $p["retention_first_month_price"] !== "")
                        ? (float)$p["retention_first_month_price"] : null;

        $cats = $catsByProduct[$pid] ?? [];

        if (empty($cats)) {
            $dc = $discountMap[$pid][""] ?? null;

            $exportRows[] = buildSheetRow(
                "Single", $productName, $productName, $pid, "",
                $prodIsSub, $prodDurVal, $prodDurUnit,
                $basePrice,
                $allowInstall, $installCount, $installInt,
                $prodFirst, $prodRet, $prodRetFirst,
                $dc, $status
            );
        } else {
            foreach ($cats as $cat) {
                $modifier  = (float)($cat["price_modifier"] ?? 0);
                $fullPrice = $basePrice + $modifier;
                $catName   = (string)($cat["cat_name"] ?? $productName);
                $catId     = (string)($cat["cat_id"] ?? "");

                // Category-specific discount first; fall back to product-level
                $dc = ($catId !== "" && isset($discountMap[$pid][$catId]))
                      ? $discountMap[$pid][$catId]
                      : ($discountMap[$pid][""] ?? null);

                $isSub   = ($cat["is_subscription"] !== null)
                           ? safeBool($cat["is_subscription"]) : $prodIsSub;
                $durVal  = ($cat["duration_value"] !== null && $cat["duration_value"] !== "")
                           ? (int)$cat["duration_value"] : $prodDurVal;
                $durUnit = ($cat["duration_unit"] !== null && $cat["duration_unit"] !== "")
                           ? (string)$cat["duration_unit"] : $prodDurUnit;
                $first   = ($cat["first_month_price"] !== null && $cat["first_month_price"] !== "")
                           ? (float)$cat["first_month_price"] : $prodFirst;
                $ret     = ($cat["retention_price"] !== null && $cat["retention_price"] !== "")
                           ? (float)$cat["retention_price"] : $prodRet;
                $retF    = ($cat["retention_first_month_price"] !== null && $cat["retention_first_month_price"] !== "")
                           ? (float)$cat["retention_first_month_price"] : $prodRetFirst;

                $exportRows[] = buildSheetRow(
                    "Variant", $productName, $catName, $pid, $catId,
                    $isSub, $durVal, $durUnit,
                    $fullPrice,
                    $allowInstall, $installCount, $installInt,
                    $first, $ret, $retF,
                    $dc, $status
                );
            }
        }
    }

    return $exportRows;
}

$hasDescCol       = columnExists($conn, "Products", "description");
$hasDiscountCodes = tableExists($conn, "Discount_Codes");

function fetchProducts(mysqli $conn, bool $hasDescCol): array {
  $hasIsSubscription      = columnExists($conn, "Products", "is_subscription");
  $hasDurationValue       = columnExists($conn, "Products", "duration_value");
  $hasDurationUnit        = columnExists($conn, "Products", "duration_unit");
  $hasFirstMonth          = columnExists($conn, "Products", "first_month_price");
  $hasRetentionPrice      = columnExists($conn, "Products", "retention_price");
  $hasRetentionFirstMonth = columnExists($conn, "Products", "retention_first_month_price");
  $hasAllowInstallment    = columnExists($conn, "Products", "allow_installment");
  $hasInstallmentCount    = columnExists($conn, "Products", "installment_count");
  $hasInstallmentInterval = columnExists($conn, "Products", "installment_interval_unit");

  $hasCatFirstMonth           = columnExists($conn, "Product_Categories", "first_month_price");
  $hasCatRetentionPrice       = columnExists($conn, "Product_Categories", "retention_price");
  $hasCatRetentionFirstMonth  = columnExists($conn, "Product_Categories", "retention_first_month_price");

  $sel = [
    "p.id",
    "p.name",
    ($hasDescCol             ? "p.description"                  : "'' AS description"),
    "p.base_price",
    "p.status",
    ($hasIsSubscription      ? "p.is_subscription"             : "0 AS is_subscription"),
    ($hasDurationValue       ? "p.duration_value"              : "NULL AS duration_value"),
    ($hasDurationUnit        ? "p.duration_unit"               : "NULL AS duration_unit"),
    ($hasFirstMonth          ? "p.first_month_price"           : "NULL AS first_month_price"),
    ($hasRetentionPrice      ? "p.retention_price"             : "NULL AS retention_price"),
    ($hasRetentionFirstMonth ? "p.retention_first_month_price" : "NULL AS retention_first_month_price"),
    ($hasAllowInstallment    ? "p.allow_installment"           : "0 AS allow_installment"),
    ($hasInstallmentCount    ? "p.installment_count"           : "NULL AS installment_count"),
    ($hasInstallmentInterval ? "p.installment_interval_unit"   : "'month' AS installment_interval_unit"),
    "COUNT(c.id) AS variant_count",
    "COALESCE(MIN(p.base_price + COALESCE(c.price_modifier, 0)), p.base_price) AS min_full_price",
    "COALESCE(MAX(p.base_price + COALESCE(c.price_modifier, 0)), p.base_price) AS max_full_price",
    ($hasCatFirstMonth           ? "MIN(c.first_month_price)"           : "NULL") . " AS cat_min_first_month",
    ($hasCatFirstMonth           ? "MAX(c.first_month_price)"           : "NULL") . " AS cat_max_first_month",
    ($hasCatRetentionPrice       ? "MIN(c.retention_price)"             : "NULL") . " AS cat_min_retention",
    ($hasCatRetentionPrice       ? "MAX(c.retention_price)"             : "NULL") . " AS cat_max_retention",
    ($hasCatRetentionFirstMonth  ? "MIN(c.retention_first_month_price)" : "NULL") . " AS cat_min_ret_first",
    ($hasCatRetentionFirstMonth  ? "MAX(c.retention_first_month_price)" : "NULL") . " AS cat_max_ret_first",
  ];

  $grp = ["p.id", "p.name", "p.base_price", "p.status"];
  if ($hasDescCol)             $grp[] = "p.description";
  if ($hasIsSubscription)      $grp[] = "p.is_subscription";
  if ($hasDurationValue)       $grp[] = "p.duration_value";
  if ($hasDurationUnit)        $grp[] = "p.duration_unit";
  if ($hasFirstMonth)          $grp[] = "p.first_month_price";
  if ($hasRetentionPrice)      $grp[] = "p.retention_price";
  if ($hasRetentionFirstMonth) $grp[] = "p.retention_first_month_price";
  if ($hasAllowInstallment)    $grp[] = "p.allow_installment";
  if ($hasInstallmentCount)    $grp[] = "p.installment_count";
  if ($hasInstallmentInterval) $grp[] = "p.installment_interval_unit";

  $sql = "
    SELECT " . implode(",\n      ", $sel) . "
    FROM Products p
    LEFT JOIN `Product_Categories` c ON c.product_id = p.id
    GROUP BY " . implode(", ", $grp) . "
    ORDER BY p.id DESC
  ";

  $res = $conn->query($sql);
  if (!$res) return [];
  return $res->fetch_all(MYSQLI_ASSOC);
}

// ── POST Actions ──────────────────────────────────────────────────────────────

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $isAjax = isset($_POST["ajax"]) || (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest");
  if (function_exists('csrf_validate')) csrf_validate();

  $action = (string)($_POST["action"] ?? "");
  $pid    = (string)($_POST["product_id"] ?? "");

  if ($action === "export_google_sheets") {
    // DEMO STUB: in-page toast via AJAX; fallback redirect for non-AJAX
    if (defined('DEMO_MODE') && DEMO_MODE) {
      if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
      }
      header("Location: /admin/payment/admin-products.php?export=1&ts=" . time(), true, 303);
      exit;
    }
    try {
      $oauthLib  = __DIR__ . "/../elearning/lib/google_oauth.php";
      $sheetsLib = __DIR__ . "/../elearning/lib/sheets_api.php";
      if (!file_exists($oauthLib) || !file_exists($sheetsLib)) {
        throw new RuntimeException("Google Sheets export is not available in this environment.");
      }
      require_once $oauthLib;
      require_once $sheetsLib;

      $refreshToken = google_token_get_refresh();
      if (!$refreshToken) {
        throw new RuntimeException("Google is not connected. Please connect Google first.");
      }

      $tok = google_access_token_from_refresh($refreshToken);
      $accessToken = (string)($tok["access_token"] ?? "");
      if ($accessToken === "") {
        throw new RuntimeException("Failed to obtain Google access token.");
      }

      $sheetId  = "159lx5uPaysYZLoNQR-9Elb_wbB7U7WpPMSdS4HecZww";
      $sheetTab = "PRODUCT LIST";

      $headers = [
        "Row Type",
        "Product Group",
        "Product Name",
        "Product ID",
        "Variant ID",
        "Product Type",
        "Duration",
        "Price",
        "Price w SST",
        "Allow Installment",
        "Installment Count",
        "Installment Interval",
        "Initial First Payment",
        "Initial Remaining Installment",
        "Initial Discount",
        "Initial Discount Code",
        "Initial Final Price",
        "Retention Offer Price",
        "Retention First Payment",
        "Retention Remaining Installment",
        "Retention Discount",
        "Retention Discount Code",
        "Status",
      ];

      $dataRows = fetchProductsForSheetsExport($conn);
      $dataRows = normalizeSheetRowsToHeaders($headers, $dataRows);
      assertSheetRowWidths($headers, $dataRows);

      sheetsWriteHeaderRow($accessToken, $sheetId, $sheetTab, $headers);
      sheetsClearDataRows($accessToken, $sheetId, $sheetTab, count($headers));
      sheetsWriteDataRows($accessToken, $sheetId, $sheetTab, $dataRows);

      $sheetUrl = "https://docs.google.com/spreadsheets/d/" . rawurlencode($sheetId) . "/edit";
      header("Location: " . $sheetUrl, true, 303);
      exit;
    } catch (Throwable $e) {
      $msg = substr($e->getMessage(), 0, 220);
      header("Location: /admin/payment/admin-products.php?export=0&export_error=" . urlencode($msg) . "&ts=" . time(), true, 303);
      exit;
    }
  }

  $ok        = true;
  $newStatus = null;

  if ($pid === "" || !in_array($action, ["delete", "toggle"], true)) {
    $ok = false;
  } else {
    try {
      if ($action === "toggle") {
        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT status FROM Products WHERE id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException("Prepare failed: " . $conn->error);
        $stmt->bind_param("s", $pid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
          $ok = false;
          $conn->rollback();
        } else {
          $st        = strtolower((string)($row["status"] ?? "active"));
          $newStatus = ($st === "active") ? "inactive" : "active";

          $stmt2 = $conn->prepare("UPDATE Products SET status = ? WHERE id = ? LIMIT 1");
          if (!$stmt2) throw new RuntimeException("Prepare failed: " . $conn->error);
          $stmt2->bind_param("ss", $newStatus, $pid);
          $stmt2->execute();
          $stmt2->close();

          if ($hasDiscountCodes && $newStatus === "inactive") {
            $stmtD = $conn->prepare("UPDATE Discount_Codes SET status='inactive' WHERE product_id = ?");
            if (!$stmtD) throw new RuntimeException("Prepare failed: " . $conn->error);
            $stmtD->bind_param("s", $pid);
            $stmtD->execute();
            $stmtD->close();
          }

          $conn->commit();
        }

      } else { // delete
        $conn->begin_transaction();

        if ($hasDiscountCodes) {
          $stmtD = $conn->prepare("UPDATE Discount_Codes SET status='inactive' WHERE product_id = ?");
          if (!$stmtD) throw new RuntimeException("Prepare failed: " . $conn->error);
          $stmtD->bind_param("s", $pid);
          $stmtD->execute();
          $stmtD->close();
        }

        $stmtC = $conn->prepare("DELETE FROM `Product_Categories` WHERE product_id = ?");
        if (!$stmtC) throw new RuntimeException("Prepare failed: " . $conn->error);
        $stmtC->bind_param("s", $pid);
        $stmtC->execute();
        $stmtC->close();

        $stmtP = $conn->prepare("DELETE FROM Products WHERE id = ? LIMIT 1");
        if (!$stmtP) throw new RuntimeException("Prepare failed: " . $conn->error);
        $stmtP->bind_param("s", $pid);
        $stmtP->execute();
        $deleted = $stmtP->affected_rows;
        $stmtP->close();

        $conn->commit();
        $ok = ($deleted > 0);
      }
    } catch (Throwable $e) {
      @$conn->rollback();
      error_log("admin-products action failed: " . $e->getMessage());
      $ok = false;
    }
  }

  if ($isAjax) {
    if (!$ok) http_response_code(400);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => $ok, "product_id" => $pid, "status" => $newStatus, "action" => $action]);
    exit;
  }

  header("Location: /admin/payment/admin-products.php?ts=" . time(), true, 303);
  exit;
}

// ── Page Setup ────────────────────────────────────────────────────────────────

$products       = fetchProducts($conn, $hasDescCol);
$flashExportOk  = ((string)($_GET["export"] ?? "") === "1");
$flashExportFail  = ((string)($_GET["export"] ?? "") === "0");
$flashExportError = trim((string)($_GET["export_error"] ?? ""));

$pageTitle = "Products";
$pageDesc  = "Manage your storefront and billing infrastructure.";
$addUrl    = "/admin/payment/product-form.php";
$discountUrl = "/admin/payment/discount-form.php";

$discountIconDesktop = '
<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none">
  <g fill="none" stroke="currentColor">
    <path stroke-width="2.5" d="M10.51 3.665a2 2 0 0 1 2.98 0l.7.782a2 2 0 0 0 1.601.663l1.05-.058a2 2 0 0 1 2.107 2.108l-.058 1.049a2 2 0 0 0 .663 1.6l.782.7a2 2 0 0 1 0 2.981l-.782.7a2 2 0 0 0-.663 1.601l.058 1.05a2 2 0 0 1-2.108 2.107l-1.049-.058a2 2 0 0 0-1.6.663l-.7.782a2 2 0 0 1-2.981 0l-.7-.782a2 2 0 0 0-1.601-.663l-1.05.058a2 2 0 0 1-2.107-2.108l.058-1.049a2 2 0 0 0-.663-1.6l-.782-.7a2 2 0 0 1 0-2.981l.782-.7a2 2 0 0 0 .663-1.601l-.058-1.05A2 2 0 0 1 7.16 5.053l1.049.058a2 2 0 0 0 1.6-.663l.7-.782Z"/>
    <path stroke-linejoin="round" stroke-width="3.75" d="M9.5 9.5h.01v.01H9.5zm5 5h.01v.01h-.01z"/>
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m15 9l-6 6"/>
  </g>
</svg>';

$discountIconMobile = '
<svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
  <g fill="none" stroke="currentColor">
    <path stroke-width="2.5" d="M10.51 3.665a2 2 0 0 1 2.98 0l.7.782a2 2 0 0 0 1.601.663l1.05-.058a2 2 0 0 1 2.107 2.108l-.058 1.049a2 2 0 0 0 .663 1.6l.782.7a2 2 0 0 1 0 2.981l-.782.7a2 2 0 0 0-.663 1.601l.058 1.05a2 2 0 0 1-2.108 2.107l-1.049-.058a2 2 0 0 0-1.6.663l-.7.782a2 2 0 0 1-2.981 0l-.7-.782a2 2 0 0 0-1.601-.663l-1.05.058a2 2 0 0 1-2.107-2.108l.058-1.049a2 2 0 0 0-.663-1.6l-.782-.7a2 2 0 0 1 0-2.981l.782-.7a2 2 0 0 0 .663-1.601l-.058-1.05A2 2 0 0 1 7.16 5.053l1.049.058a2 2 0 0 0 1.6-.663l.7-.782Z"/>
    <path stroke-linejoin="round" stroke-width="3.75" d="M9.5 9.5h.01v.01H9.5zm5 5h.01v.01h-.01z"/>
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m15 9l-6 6"/>
  </g>
</svg>';

$headerActionsHtmlDesktop = '
  <form method="POST" data-export-form class="hidden sm:inline-flex">
    ' . (function_exists('csrf_token') ? '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">' : '') . '
    <input type="hidden" name="action" value="export_google_sheets">
    <button type="submit"
      class="px-4 py-2 rounded-2xl bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 font-bold inline-flex items-center gap-2">
      <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l-3-3m3 3l3-3M5 19h14" />
      </svg>
      Export to Google Sheets
    </button>
  </form>

  <a href="' . h($discountUrl) . '"
     class="hidden sm:inline-flex px-4 py-2 rounded-2xl bg-slate-900 text-white shadow-sm hover:bg-slate-800 font-bold inline-flex items-center gap-2">
    ' . $discountIconDesktop . '
    Discount Codes
  </a>

  <a href="' . h($addUrl) . '"
     class="hidden sm:inline-flex items-center gap-2 bg-yellow-500 text-white px-4 py-2 rounded-2xl font-black hover:bg-yellow-600 transition shadow-md shadow-yellow-100">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
    Add Product
  </a>
';

$headerActionsHtmlMobile = '
  <form method="POST" data-export-form class="inline-flex sm:hidden">
    ' . (function_exists('csrf_token') ? '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">' : '') . '
    <input type="hidden" name="action" value="export_google_sheets">
    <button type="submit"
      class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-emerald-600 text-white hover:bg-emerald-700 transition shadow-sm"
      aria-label="Export to Google Sheets" title="Export to Google Sheets">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l-3-3m3 3l3-3M5 19h14" />
      </svg>
    </button>
  </form>

  <a href="' . h($discountUrl) . '"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition shadow-sm"
     aria-label="Discount Codes" title="Discount Codes">
    ' . $discountIconMobile . '
  </a>

  <a href="' . h($addUrl) . '"
     class="inline-flex sm:hidden items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-md shadow-yellow-100"
     aria-label="Add Product" title="Add Product">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
    </svg>
  </a>
';

$title = "Products";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<!-- Mobile page title -->
<div class="md:hidden mb-6">
  <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= h((string)$pageTitle) ?></h1>
  <?php if (trim((string)$pageDesc) !== ""): ?>
    <p class="mt-1 text-sm text-slate-400"><?= h((string)$pageDesc) ?></p>
  <?php endif; ?>
</div>

<?php if ($flashExportOk): ?>
  <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 font-medium">
    Exported to Google Sheets successfully.
  </div>
<?php endif; ?>

<?php if ($flashExportFail): ?>
  <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
    <span class="font-semibold">Export failed.</span>
    <?php if ($flashExportError !== ""): ?>
      <?= h($flashExportError) ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
/* ── shared per-row data prep ── */
$_rows = [];
foreach ($products as $_p) {
  $_pid          = (string)($_p["id"] ?? "");
  $_name         = (string)($_p["name"] ?? "Product");
  $_desc         = (string)($_p["description"] ?? "");
  $_base         = (float)($_p["base_price"] ?? 0);
  $_st           = strtolower((string)($_p["status"] ?? "active"));
  $_isActive     = ($_st === "active");
  $_isSub        = safeBool($_p["is_subscription"] ?? 0);
  $_vc           = (int)($_p["variant_count"] ?? 0);
  $_minFull      = (float)($_p["min_full_price"] ?? $_base);
  $_maxFull      = (float)($_p["max_full_price"] ?? $_base);

  $_fm   = ($_p["first_month_price"] !== null && $_p["first_month_price"] !== "")
           ? (float)$_p["first_month_price"] : null;
  $_ret  = ($_p["retention_price"] !== null && $_p["retention_price"] !== "")
           ? (float)$_p["retention_price"] : null;
  $_rfm  = ($_p["retention_first_month_price"] !== null && $_p["retention_first_month_price"] !== "")
           ? (float)$_p["retention_first_month_price"] : null;

  $_ai  = safeBool($_p["allow_installment"] ?? 0);
  $_ic  = (int)($_p["installment_count"] ?? 0);
  $_iu  = trim((string)($_p["installment_interval_unit"] ?? "month")) ?: "month";
  $_dv  = (int)($_p["duration_value"] ?? 0);
  $_du  = (string)($_p["duration_unit"] ?? "");

  $_cmf  = ($_p["cat_min_first_month"] !== null) ? (float)$_p["cat_min_first_month"] : null;
  $_cxf  = ($_p["cat_max_first_month"] !== null) ? (float)$_p["cat_max_first_month"] : null;
  $_cmr  = ($_p["cat_min_retention"] !== null)   ? (float)$_p["cat_min_retention"]   : null;
  $_cxr  = ($_p["cat_max_retention"] !== null)   ? (float)$_p["cat_max_retention"]   : null;
  $_cmrf = ($_p["cat_min_ret_first"] !== null)   ? (float)$_p["cat_min_ret_first"]   : null;
  $_cxrf = ($_p["cat_max_ret_first"] !== null)   ? (float)$_p["cat_max_ret_first"]   : null;

  $_hasProdRet  = ($_ret !== null && $_ret > 0);
  $_hasVarRet   = ($_vc > 0 && $_cmr !== null && $_cmr > 0);
  $_hasRet      = $_hasProdRet || $_hasVarRet;

  $_remaining = null;
  if ($_ai && $_ic > 0 && $_fm !== null && $_fm > 0) {
    $_remaining = calcRemaining($_base, $_fm, $_ic);
  }

  $_retFc = ($_rfm !== null && $_rfm > 0) ? $_rfm : $_fm;
  $_retRem = null;
  if ($_ai && $_ic > 0 && $_hasProdRet && $_retFc !== null && $_retFc > 0) {
    $_retRem = calcRemaining($_ret, $_retFc, $_ic);
  }

  $_varIncomplete = ($_vc > 0 && $_ai && ($_cmf === null || $_cmf <= 0));
  $_durLabel = durationLabel($_dv, $_du);

  // Build compact meta line for product column
  $_metaParts = [];
  if ($_durLabel !== "") $_metaParts[] = $_durLabel . " access";
  if ($_isSub) $_metaParts[] = "Subscription";
  if ($_vc > 0) $_metaParts[] = $_vc . " variant" . ($_vc > 1 ? "s" : "");
  $_metaLine = implode(" · ", $_metaParts);

  $_rows[] = compact(
    '_pid','_name','_desc','_base','_st','_isActive','_isSub','_vc',
    '_minFull','_maxFull','_fm','_ret','_rfm','_ai','_ic','_iu',
    '_cmf','_cxf','_cmr','_cxr','_cmrf','_cxrf',
    '_hasProdRet','_hasVarRet','_hasRet','_remaining',
    '_retFc','_retRem','_varIncomplete','_durLabel','_metaLine'
  );
}
?>

<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">

  <!-- Card header -->
  <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
    <div>
      <h2 class="text-base font-semibold text-slate-800">Products</h2>
      <p class="text-xs text-slate-400 mt-0.5"><?= count($products) ?> product<?= count($products) !== 1 ? "s" : "" ?></p>
    </div>
  </div>

  <?php if (count($products) === 0): ?>
    <div class="py-24 text-center">
      <p class="text-sm font-medium text-slate-500">No products yet</p>
      <p class="text-xs text-slate-400 mt-1 mb-5">Start by adding your first payment product.</p>
      <a href="/admin/payment/product-form.php"
         class="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-600 hover:text-amber-700 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Create a product
      </a>
    </div>

  <?php else: ?>

    <!-- ── DESKTOP TABLE (md+) ──────────────────────────────────────────── -->
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-left">
        <thead>
          <tr class="border-b border-slate-100">
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Product</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Price</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Payment plan</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Retention</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
            <th class="px-6 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php foreach ($_rows as $r): extract($r); ?>
            <tr data-product-id="<?= h($_pid) ?>"
                class="group hover:bg-yellow-50/30 transition-colors duration-75">

              <!-- Product info -->
              <td class="px-6 py-4 max-w-xs">
                <div class="flex items-start gap-3">
                  <div class="shrink-0 w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-bold mt-0.5">
                    <?= strtoupper(substr($_name, 0, 1)) ?>
                  </div>
                  <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-900 leading-snug"><?= h($_name) ?></p>
                    <?php if ($_desc !== ""): ?>
                      <p class="text-xs text-slate-400 truncate mt-0.5 max-w-[260px]"><?= h(strip_tags($_desc)) ?></p>
                    <?php endif; ?>
                    <?php if ($_metaLine !== ""): ?>
                      <p class="text-[11px] text-slate-300 mt-1"><?= h($_metaLine) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- Price -->
              <td class="px-6 py-4 whitespace-nowrap">
                <?php if ($_vc > 0 && abs($_minFull - $_maxFull) > 0.005): ?>
                  <p class="text-sm font-semibold text-slate-900 tabular-nums">
                    RM <?= h(money($_minFull)) ?> – RM <?= h(money($_maxFull)) ?>
                  </p>
                  <p class="text-[11px] text-slate-400 mt-0.5">Base RM <?= h(money($_base)) ?></p>
                <?php else: ?>
                  <p class="text-sm font-semibold text-slate-900 tabular-nums">
                    RM <?= h(money($_vc > 0 ? $_minFull : $_base)) ?>
                  </p>
                <?php endif; ?>
              </td>

              <!-- Payment plan -->
              <td class="px-6 py-4">
                <?php if (!$_ai): ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-50 text-slate-400 border border-slate-200">Full payment</span>
                <?php else: ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-100">Installment</span>
                  <div class="mt-1 space-y-0.5">
                    <?php if ($_vc > 0): ?>
                      <?php if ($_varIncomplete): ?>
                        <p class="text-[11px] text-amber-500">⚠ Variant config incomplete</p>
                      <?php elseif ($_cmf !== null && $_cmf > 0): ?>
                        <p class="text-[11px] text-slate-400 tabular-nums">
                          First payment:
                          <?php if ($_cxf !== null && abs($_cxf - $_cmf) > 0.005): ?>
                            RM <?= h(money($_cmf)) ?> – RM <?= h(money($_cxf)) ?>
                          <?php else: ?>
                            RM <?= h(money($_cmf)) ?>
                          <?php endif; ?>
                        </p>
                        <?php if ($_ic > 0): ?>
                          <p class="text-[11px] text-slate-300">Remaining varies by variant <?= h(intervalLabel($_ic, $_iu)) ?></p>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php if ($_fm !== null && $_fm > 0): ?>
                        <p class="text-[11px] text-slate-400 tabular-nums">First payment: RM <?= h(money($_fm)) ?></p>
                        <?php if ($_remaining !== null && $_ic > 0): ?>
                          <p class="text-[11px] text-slate-400 tabular-nums">Remaining: RM <?= h(money($_remaining)) ?> <?= h(intervalLabel($_ic, $_iu)) ?></p>
                        <?php else: ?>
                          <p class="text-[11px] text-amber-500">⚠ Check installment count</p>
                        <?php endif; ?>
                      <?php else: ?>
                        <p class="text-[11px] text-amber-500">⚠ First payment not set</p>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Retention -->
              <td class="px-6 py-4">
                <?php if (!$_hasRet): ?>
                  <span class="text-slate-300 text-sm">—</span>
                <?php elseif ($_hasVarRet && !$_hasProdRet): ?>
                  <p class="text-xs font-medium text-slate-700 tabular-nums">
                    <?php if ($_cxr !== null && abs($_cxr - $_cmr) > 0.005): ?>
                      RM <?= h(money($_cmr)) ?> – RM <?= h(money($_cxr)) ?>
                    <?php else: ?>
                      RM <?= h(money($_cmr)) ?>
                    <?php endif; ?>
                  </p>
                  <?php if ($_ai && $_cmrf !== null && $_cmrf > 0): ?>
                    <div class="mt-1 space-y-0.5">
                      <p class="text-[11px] text-slate-400 tabular-nums">
                        First:
                        <?php if ($_cxrf !== null && abs($_cxrf - $_cmrf) > 0.005): ?>
                          RM <?= h(money($_cmrf)) ?> – RM <?= h(money($_cxrf)) ?>
                        <?php else: ?>
                          RM <?= h(money($_cmrf)) ?>
                        <?php endif; ?>
                      </p>
                      <?php if ($_ic > 0): ?>
                        <p class="text-[11px] text-slate-300">Remaining varies <?= h(intervalLabel($_ic, $_iu)) ?></p>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="text-xs font-medium text-emerald-700 tabular-nums">RM <?= h(money($_ret)) ?></p>
                  <?php if ($_ai && $_ic > 0): ?>
                    <div class="mt-1 space-y-0.5">
                      <?php if ($_retFc !== null && $_retFc > 0): ?>
                        <p class="text-[11px] text-slate-400 tabular-nums">
                          First: RM <?= h(money($_retFc)) ?>
                          <?php if ($_rfm === null && $_fm !== null): ?><span class="text-slate-300">(initial)</span><?php endif; ?>
                        </p>
                        <?php if ($_retRem !== null): ?>
                          <p class="text-[11px] text-slate-400 tabular-nums">Remaining: RM <?= h(money($_retRem)) ?> <?= h(intervalLabel($_ic, $_iu)) ?></p>
                        <?php endif; ?>
                      <?php else: ?>
                        <p class="text-[11px] text-amber-500">⚠ First payment not set</p>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>

              <!-- Status (includes variant count) -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-1.5">
                  <span class="w-1.5 h-1.5 rounded-full <?= $_isActive ? "bg-emerald-400" : "bg-rose-400" ?> shrink-0" data-status-dot></span>
                  <span class="text-xs font-medium text-slate-700 capitalize" data-status-text><?= h($_st) ?></span>
                </div>
                <p class="text-[11px] text-slate-400 mt-1 pl-3">
                  <?= $_vc > 0 ? h((string)$_vc) . " variant" . ($_vc > 1 ? "s" : "") : "Single" ?>
                </p>
                <?php if ($_varIncomplete): ?>
                  <p class="text-[11px] text-amber-500 mt-0.5 pl-3">⚠ Plan incomplete</p>
                <?php endif; ?>
              </td>

              <!-- Actions -->
              <td class="px-6 py-4">
                <div class="flex items-center justify-end gap-0.5">

                  <!-- Edit -->
                  <a href="/admin/payment/product-form.php?id=<?= h($_pid) ?>"
                     class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </a>

                  <!-- View -->
                  <?php
                    $_vh  = "/payment/payment.php?product=" . rawurlencode($_pid);
                    $_vd  = !$_isActive;
                    $_vc2 = $_vd ? "inline-flex items-center justify-center p-1.5 text-slate-400 opacity-30 cursor-not-allowed" : "inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 transition-colors";
                  ?>
                  <a data-view-link
                     href="<?= h($_vh) ?>"
                     class="<?= $_vc2 ?>"
                     title="<?= $_vd ? "Inactive – view disabled" : "View" ?>"
                     aria-disabled="<?= $_vd ? "true" : "false" ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </a>

                  <!-- Copy Link -->
                  <button type="button"
                    class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 transition-colors"
                    title="Copy link"
                    data-copy-link="<?= h("/payment/payment.php?product=" . $_pid) ?>">
                    <span data-icon-copy>
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                          stroke-miterlimit="10" stroke-width="1.5"
                          d="M6 15h-.6C4.07 15 3 13.93 3 12.6V5.4C3 4.07 4.07 3 5.4 3h7.2C13.93 3 15 4.07 15 5.4V6m-3.6 3h7.2a2.4 2.4 0 0 1 2.4 2.4v7.2a2.4 2.4 0 0 1-2.4 2.4h-7.2A2.4 2.4 0 0 1 9 18.6v-7.2A2.4 2.4 0 0 1 11.4 9"/>
                      </svg>
                    </span>
                    <span data-icon-tick class="hidden text-emerald-500">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <path fill="currentColor"
                          d="M16.972 6.251a1.999 1.999 0 0 0-2.72.777l-3.713 6.682l-2.125-2.125a2 2 0 1 0-2.828 2.828l4 4c.378.379.888.587 1.414.587l.277-.02a2 2 0 0 0 1.471-1.009l5-9a2 2 0 0 0-.776-2.72z"/>
                      </svg>
                    </span>
                  </button>

                  <!-- Toggle -->
                  <form method="POST" data-toggle-form class="inline">
                    <?php if (function_exists('csrf_token')): ?>
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="product_id" value="<?= h($_pid) ?>">
                    <button type="submit"
                      class="inline-flex items-center justify-center p-1.5 transition-colors <?= $_isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>"
                      title="<?= $_isActive ? 'Deactivate Product' : 'Activate Product' ?>">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
                      </svg>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="POST" class="inline"
                    data-confirm="Delete this product?"
                    data-confirm-desc="This will remove the product and all its variants permanently."
                    data-confirm-ok="Delete">
                    <?php if (function_exists('csrf_token')): ?>
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" value="<?= h($_pid) ?>">
                    <button type="submit"
                      class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors"
                      title="Delete">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
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

    <!-- ── MOBILE CARDS (below md) ────────────────────────────────────────── -->
    <div class="md:hidden divide-y divide-slate-100">
      <?php foreach ($_rows as $r): extract($r); ?>
        <div data-product-id="<?= h($_pid) ?>" class="px-4 py-4">

          <!-- Top row: avatar + name + status -->
          <div class="flex items-start gap-3">
            <div class="shrink-0 w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-bold mt-0.5">
              <?= strtoupper(substr($_name, 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-semibold text-slate-900 leading-snug"><?= h($_name) ?></p>
                <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                  <span class="w-1.5 h-1.5 rounded-full <?= $_isActive ? "bg-emerald-400" : "bg-rose-400" ?> shrink-0" data-status-dot></span>
                  <span class="text-xs font-medium text-slate-500 capitalize" data-status-text><?= h($_st) ?></span>
                </div>
              </div>
              <?php if ($_desc !== ""): ?>
                <p class="text-xs text-slate-400 mt-0.5 line-clamp-1"><?= h(strip_tags($_desc)) ?></p>
              <?php endif; ?>
              <?php if ($_metaLine !== ""): ?>
                <p class="text-[11px] text-slate-300 mt-0.5"><?= h($_metaLine) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Price + plan row -->
          <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
              <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Price</p>
              <?php if ($_vc > 0 && abs($_minFull - $_maxFull) > 0.005): ?>
                <p class="text-sm font-semibold text-slate-900 tabular-nums leading-snug">RM <?= h(money($_minFull)) ?> –<br>RM <?= h(money($_maxFull)) ?></p>
              <?php else: ?>
                <p class="text-sm font-semibold text-slate-900 tabular-nums">RM <?= h(money($_vc > 0 ? $_minFull : $_base)) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Plan</p>
              <?php if (!$_ai): ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-50 text-slate-400 border border-slate-200">Full payment</span>
              <?php else: ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-100">Installment</span>
                <?php if ($_vc > 0 && $_varIncomplete): ?>
                  <p class="text-[11px] text-amber-500 mt-0.5">⚠ Config incomplete</p>
                <?php elseif ($_vc > 0 && $_cmf !== null && $_cmf > 0): ?>
                  <p class="text-[11px] text-slate-400 tabular-nums mt-0.5">First: RM <?= h(money($_cmf)) ?><?= ($_cxf !== null && abs($_cxf - $_cmf) > 0.005) ? " – RM " . h(money($_cxf)) : "" ?></p>
                <?php elseif (!$_vc && $_fm !== null && $_fm > 0): ?>
                  <p class="text-[11px] text-slate-400 tabular-nums mt-0.5">First: RM <?= h(money($_fm)) ?></p>
                  <?php if ($_remaining !== null && $_ic > 0): ?>
                    <p class="text-[11px] text-slate-400 tabular-nums">Rem: RM <?= h(money($_remaining)) ?> <?= h(intervalLabel($_ic, $_iu)) ?></p>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Retention row (only if relevant) -->
          <?php if ($_hasRet): ?>
            <div class="mt-2.5">
              <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Retention offer</p>
              <?php if ($_hasProdRet): ?>
                <p class="text-xs font-medium text-emerald-700 tabular-nums">RM <?= h(money($_ret)) ?></p>
                <?php if ($_ai && $_retFc !== null && $_retFc > 0 && $_ic > 0): ?>
                  <p class="text-[11px] text-slate-400 tabular-nums mt-0.5">First: RM <?= h(money($_retFc)) ?><?php if ($_retRem !== null): ?> · Rem: RM <?= h(money($_retRem)) ?> <?= h(intervalLabel($_ic, $_iu)) ?><?php endif; ?></p>
                <?php endif; ?>
              <?php elseif ($_hasVarRet): ?>
                <p class="text-xs font-medium text-slate-700 tabular-nums">RM <?= h(money($_cmr)) ?><?= ($_cxr !== null && abs($_cxr - $_cmr) > 0.005) ? " – RM " . h(money($_cxr)) : "" ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Actions row -->
          <div class="mt-3 flex items-center justify-between">
            <p class="text-[11px] text-slate-300">
              <?= $_vc > 0 ? h((string)$_vc) . " variant" . ($_vc > 1 ? "s" : "") : "Single" ?>
            </p>
            <div class="flex items-center gap-0.5">

              <a href="/admin/payment/product-form.php?id=<?= h($_pid) ?>"
                 class="inline-flex items-center justify-center p-1.5 text-amber-500 hover:text-amber-600 transition-colors" title="Edit">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
              </a>

              <?php $_vhm = "/payment/payment.php?product=" . rawurlencode($_pid); ?>
              <a data-view-link
                 href="<?= h($_vhm) ?>"
                 class="inline-flex items-center justify-center p-1.5 <?= !$_isActive ? "text-slate-400 opacity-30 cursor-not-allowed" : "text-slate-400 hover:text-slate-600 transition-colors" ?>"
                 title="<?= !$_isActive ? "Inactive" : "View" ?>"
                 aria-disabled="<?= !$_isActive ? "true" : "false" ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
              </a>

              <button type="button"
                class="inline-flex items-center justify-center p-1.5 text-slate-400 hover:text-slate-600 transition-colors"
                title="Copy link"
                data-copy-link="<?= h("/payment/payment.php?product=" . $_pid) ?>">
                <span data-icon-copy>
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                      stroke-miterlimit="10" stroke-width="1.5"
                      d="M6 15h-.6C4.07 15 3 13.93 3 12.6V5.4C3 4.07 4.07 3 5.4 3h7.2C13.93 3 15 4.07 15 5.4V6m-3.6 3h7.2a2.4 2.4 0 0 1 2.4 2.4v7.2a2.4 2.4 0 0 1-2.4 2.4h-7.2A2.4 2.4 0 0 1 9 18.6v-7.2A2.4 2.4 0 0 1 11.4 9"/>
                  </svg>
                </span>
                <span data-icon-tick class="hidden text-emerald-500">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <path fill="currentColor"
                      d="M16.972 6.251a1.999 1.999 0 0 0-2.72.777l-3.713 6.682l-2.125-2.125a2 2 0 1 0-2.828 2.828l4 4c.378.379.888.587 1.414.587l.277-.02a2 2 0 0 0 1.471-1.009l5-9a2 2 0 0 0-.776-2.72z"/>
                  </svg>
                </span>
              </button>

              <form method="POST" data-toggle-form class="inline">
                <?php if (function_exists('csrf_token')): ?>
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="product_id" value="<?= h($_pid) ?>">
                <button type="submit" class="inline-flex items-center justify-center p-1.5 transition-colors <?= $_isActive ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' ?>" title="<?= $_isActive ? 'Deactivate' : 'Activate' ?>">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
                  </svg>
                </button>
              </form>

              <form method="POST" class="inline"
                data-confirm="Delete this product?"
                data-confirm-desc="This will remove the product and all its variants permanently."
                data-confirm-ok="Delete">
                <?php if (function_exists('csrf_token')): ?>
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="product_id" value="<?= h($_pid) ?>">
                <button type="submit" class="inline-flex items-center justify-center p-1.5 text-red-500 hover:text-red-600 transition-colors" title="Delete">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </form>

            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>

<!-- SDC Confirm Modal -->
<div id="sdcConfirm" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
  <div data-sdc-confirm-close class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>
  <div
    id="sdcConfirmPanel"
    class="relative w-full max-w-md rounded-[2rem] bg-white border border-slate-100 shadow-2xl shadow-slate-900/20
           transform transition-all duration-150 scale-95 opacity-0"
    role="dialog" aria-modal="true" aria-labelledby="sdcConfirmTitle" aria-describedby="sdcConfirmDesc"
  >
    <div class="p-6">
      <div class="flex items-start gap-4">
        <div class="shrink-0 w-11 h-11 rounded-2xl bg-red-50 border border-red-100 flex items-center justify-center text-red-600">
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 7l-1 14H6L5 7m3 0V5a2 2 0 012-2h4a2 2 0 012 2v2m-9 0h10"/>
          </svg>
        </div>
        <div class="min-w-0">
          <h3 id="sdcConfirmTitle" class="text-lg font-black text-slate-900">Confirm</h3>
          <p id="sdcConfirmDesc" class="mt-1 text-sm font-semibold text-slate-500">Are you sure?</p>
        </div>
      </div>
      <div class="mt-6 flex items-center justify-end gap-3">
        <button type="button" data-sdc-confirm-cancel
          class="px-5 py-2.5 rounded-2xl font-black text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition">
          Cancel
        </button>
        <button type="button" data-sdc-confirm-ok
          class="px-6 py-2.5 rounded-2xl font-black bg-slate-900 text-white hover:bg-slate-800 transition shadow-lg active:scale-[0.98]">
          Delete
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById("sdcConfirm");
  const panel = document.getElementById("sdcConfirmPanel");
  if (!modal || !panel) return;

  const titleEl   = document.getElementById("sdcConfirmTitle");
  const descEl    = document.getElementById("sdcConfirmDesc");
  const btnOk     = modal.querySelector("[data-sdc-confirm-ok]");
  const btnCancel = modal.querySelector("[data-sdc-confirm-cancel]");

  let pendingForm = null;
  let lastActive  = null;

  const restoreY = sessionStorage.getItem("sdcScrollY");
  if (restoreY) {
    sessionStorage.removeItem("sdcScrollY");
    const y = parseInt(restoreY, 10);
    if (!Number.isNaN(y)) {
      requestAnimationFrame(() => setTimeout(() => window.scrollTo(0, y), 0));
    }
  }

  const open = (form) => {
    pendingForm = form;
    lastActive  = document.activeElement;

    titleEl.textContent = form.getAttribute("data-confirm") || "Confirm";
    descEl.textContent  = form.getAttribute("data-confirm-desc") || "Are you sure?";
    btnOk.textContent   = form.getAttribute("data-confirm-ok") || "Confirm";

    modal.classList.remove("hidden");
    modal.classList.add("flex");
    document.documentElement.classList.add("overflow-hidden");

    requestAnimationFrame(() => {
      panel.classList.remove("opacity-0", "scale-95");
      panel.classList.add("opacity-100", "scale-100");
    });

    btnOk.focus();
  };

  const close = () => {
    panel.classList.remove("opacity-100", "scale-100");
    panel.classList.add("opacity-0", "scale-95");

    setTimeout(() => {
      modal.classList.add("hidden");
      modal.classList.remove("flex");
      document.documentElement.classList.remove("overflow-hidden");
      pendingForm = null;
      if (lastActive && typeof lastActive.focus === "function") lastActive.focus();
    }, 120);
  };

  document.addEventListener("submit", (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute("data-confirm")) return;
    if (form.dataset.sdcConfirmPass === "1") {
      delete form.dataset.sdcConfirmPass;
      return;
    }
    e.preventDefault();
    open(form);
  }, true);

  btnOk.addEventListener("click", () => {
    if (!pendingForm) return;
    try {
      const act = pendingForm.querySelector('input[name="action"]')?.value || "";
      if (act === "delete" || act.startsWith("del_")) {
        sessionStorage.setItem("sdcScrollY", String(window.scrollY));
      }
    } catch (_) {}

    const f = pendingForm;
    f.dataset.sdcConfirmPass = "1";
    close();
    setTimeout(() => {
      if (typeof f.requestSubmit === "function") f.requestSubmit();
      else f.submit();
    }, 80);
  });

  btnCancel.addEventListener("click", close);
  modal.addEventListener("click", (e) => {
    if (e.target && e.target.hasAttribute("data-sdc-confirm-close")) close();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
  });
})();
</script>
<script>
(() => {
  const hardBase = null;

  const absoluteUrl = (pathOrUrl) => {
    if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl;
    if (hardBase) return hardBase + pathOrUrl;
    return window.location.origin + pathOrUrl;
  };

  async function copyText(text) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.left = "-9999px";
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(ta);
    return ok;
  }

  document.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-copy-link]");
    if (!btn) return;

    const path = btn.getAttribute("data-copy-link") || "";
    const url  = absoluteUrl(path);

    try {
      await copyText(url);

      const iconCopy = btn.querySelector("[data-icon-copy]");
      const iconTick = btn.querySelector("[data-icon-tick]");
      const oldTitle = btn.getAttribute("title") || "Copy Link";

      iconCopy?.classList.add("hidden");
      iconTick?.classList.remove("hidden");
      btn.setAttribute("title", "Link Copied");

      clearTimeout(btn._t);
      btn._t = setTimeout(() => {
        iconTick?.classList.add("hidden");
        iconCopy?.classList.remove("hidden");
        btn.setAttribute("title", oldTitle);
      }, 1200);
    } catch (err) {
      btn.setAttribute("title", "Copy failed");
      clearTimeout(btn._t);
      btn._t = setTimeout(() => btn.setAttribute("title", "Copy Link"), 1200);
    }
  });
})();
</script>
<script>
(() => {
  window.addEventListener("pageshow", (e) => {
    const nav = performance.getEntriesByType?.("navigation")?.[0];
    const isBFCache = e.persisted || nav?.type === "back_forward";
    if (isBFCache) location.reload();
  });
})();
</script>
<script>
(() => {
  document.addEventListener("submit", async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.matches("[data-toggle-form]")) return;

    e.preventDefault();

    const fd = new FormData(form);
    fd.append("ajax", "1");

    const btn = form.querySelector("button");
    if (btn) btn.disabled = true;

    try {
      const res  = await fetch(window.location.href, {
        method:  "POST",
        body:    fd,
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache:   "no-store",
      });

      const data = await res.json();
      if (!data?.ok) throw new Error("Toggle failed");

      const pid = fd.get("product_id");
      const row = document.querySelector(`tr[data-product-id="${pid}"]`);
      if (!row) return;

      const dot  = row.querySelector("[data-status-dot]");
      const text = row.querySelector("[data-status-text]");
      const st   = String(data.status || "").toLowerCase();

      if (text) text.textContent = st;

      if (dot) {
        dot.classList.remove("bg-emerald-400", "bg-rose-400");
        dot.classList.add(st === "active" ? "bg-emerald-400" : "bg-rose-400");
      }

      const viewLink = row.querySelector("[data-view-link]");
      if (viewLink) {
        const isActive = (st === "active");
        viewLink.classList.toggle("opacity-30", !isActive);
        viewLink.classList.toggle("cursor-not-allowed", !isActive);
        viewLink.setAttribute("title", isActive ? "View" : "Inactive");
        viewLink.setAttribute("aria-disabled", isActive ? "false" : "true");
      }

    } catch (err) {
      alert("Toggle failed. Please refresh and try again.");
    } finally {
      if (btn) btn.disabled = false;
    }
  }, true);
})();

(() => {
  document.addEventListener("click", (e) => {
    const a = e.target.closest('a[data-view-link]');
    if (!a) return;
    if (a.getAttribute("aria-disabled") === "true") {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();

// Export to Google Sheets — in-page toast (AJAX, no full-page reload)
(() => {
  function sdcExportToast(ok) {
    const el = document.createElement('div');
    el.className = 'fixed bottom-6 right-6 z-[100] flex items-center gap-3 px-5 py-3 rounded-2xl text-sm font-bold shadow-xl transition-all ' +
      (ok ? 'bg-emerald-600 text-white shadow-emerald-200' : 'bg-red-600 text-white');
    el.textContent = ok ? 'Exported to Google Sheets!' : 'Export failed. Please try again.';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }

  document.querySelectorAll('form[data-export-form]').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.set('_ajax', '1');
      try {
        const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const json = await r.json().catch(() => ({ ok: false }));
        sdcExportToast(!!json.ok);
      } catch {
        sdcExportToast(false);
      }
    });
  });
})();
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>
