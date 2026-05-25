<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!defined('DEMO_MODE')) define('DEMO_MODE', true);

$envLoader = rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/env.php";
if (!is_readable($envLoader)) {
  http_response_code(500);
  exit("Missing env loader: {$envLoader}");
}
require_once $envLoader;

if (function_exists('loadEnv')) {
  $envFile = __DIR__ . "/.env";
  if (is_readable($envFile)) {
    loadEnv($envFile);
  }
}

const SST_RATE = 0.08;

require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db.php";
session_start();

/** @var mysqli|null $conn */
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!($conn instanceof mysqli)) {
  http_response_code(500);
  exit("DB unavailable. Please check error_log Siteground.");
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");


function setDiscountInactive(mysqli $conn, int $dcId): void
{
  if ($dcId <= 0) return;
  $st = $conn->prepare("UPDATE Discount_Codes SET status='inactive' WHERE id=? AND status='active' LIMIT 1");
  if ($st) {
    $st->bind_param("i", $dcId);
    $st->execute();
    $st->close();
  }
}

function columnExists(mysqli $conn, string $table, string $col): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

function ensureBillingPaymentSchema(mysqli $conn): void
{
  if (!columnExists($conn, 'Payment', 'transaction_ref')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `transaction_ref` VARCHAR(120) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'verified')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `verified` TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!columnExists($conn, 'Payment', 'status')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'pending'");
  }

  if (!columnExists($conn, 'Payment', 'product_type')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `product_type` VARCHAR(60) NULL DEFAULT NULL AFTER `codeid`");
  }

  if (!columnExists($conn, 'Payment', 'discount_code')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `discount_code` VARCHAR(64) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'discount_amount')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0");
  }
  if (!columnExists($conn, 'Payment', 'subtotal_before_discount')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `subtotal_before_discount` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'product_category_id')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `product_category_id` VARCHAR(60) NULL DEFAULT NULL AFTER `product_type`");
  }
  if (!columnExists($conn, 'Payment', 'variant_type')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `variant_type` VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER `product_category_id`");
  }
  if (!columnExists($conn, 'Payment', 'elearning_course_id')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `elearning_course_id` VARCHAR(60) NULL DEFAULT NULL AFTER `variant_type`");
  }
  if (!columnExists($conn, 'Payment', 'subscription_id')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `subscription_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `elearning_course_id`");
  }
  if (!columnExists($conn, 'Payment', 'subscription_mode')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `subscription_mode` VARCHAR(20) NULL DEFAULT NULL AFTER `subscription_id`");
  }
  if (!columnExists($conn, 'Payment', 'is_subscription')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `is_subscription` TINYINT(1) NOT NULL DEFAULT 0 AFTER `subscription_mode`");
  }
  if (!columnExists($conn, 'Payment', 'duration_value')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `duration_value` INT NULL DEFAULT NULL AFTER `is_subscription`");
  }
  if (!columnExists($conn, 'Payment', 'duration_unit')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `duration_unit` VARCHAR(20) NULL DEFAULT NULL AFTER `duration_value`");
  }
  if (!columnExists($conn, 'Payment', 'first_month_price')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `first_month_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `duration_unit`");
  }
  if (!columnExists($conn, 'Payment', 'remaining_month_price')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `remaining_month_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `first_month_price`");
  }
  if (!columnExists($conn, 'Payment', 'retention_price')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `retention_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `remaining_month_price`");
  }
}

function ensureOrdersDiscountSchema(mysqli $conn): void
{
  if (!columnExists($conn, 'Orders', 'discount_code')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `discount_code` VARCHAR(64) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'discount_amount')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0");
  }
  if (!columnExists($conn, 'Orders', 'subtotal_before_discount')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `subtotal_before_discount` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'subscription_id')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `subscription_id` BIGINT UNSIGNED NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'subscription_mode')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `subscription_mode` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'is_subscription')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `is_subscription` TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!columnExists($conn, 'Orders', 'duration_value')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `duration_value` INT NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'duration_unit')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `duration_unit` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'first_month_price')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `first_month_price` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'remaining_month_price')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `remaining_month_price` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'retention_price')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `retention_price` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'discount_code_id')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `discount_code_id` INT UNSIGNED NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'renewal_discount_type_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `renewal_discount_type_snapshot` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'renewal_discount_value_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `renewal_discount_value_snapshot` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'retention_discount_type_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `retention_discount_type_snapshot` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Orders', 'retention_discount_value_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Orders` ADD COLUMN `retention_discount_value_snapshot` DECIMAL(10,2) NULL DEFAULT NULL");
  }
}

function ensurePaymentSnapshotSchema(mysqli $conn): void
{
  if (!columnExists($conn, 'Payment', 'discount_code_id')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `discount_code_id` INT UNSIGNED NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'renewal_discount_type_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `renewal_discount_type_snapshot` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'renewal_discount_value_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `renewal_discount_value_snapshot` DECIMAL(10,2) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'retention_discount_type_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `retention_discount_type_snapshot` VARCHAR(20) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Payment', 'retention_discount_value_snapshot')) {
    mysqli_query($conn, "ALTER TABLE `Payment` ADD COLUMN `retention_discount_value_snapshot` DECIMAL(10,2) NULL DEFAULT NULL");
  }
}

function ensureInstallmentPaymentSchema(mysqli $conn): void
{
  if (!columnExists($conn, 'Products', 'allow_full_payment')) {
    mysqli_query($conn, "ALTER TABLE `Products` ADD COLUMN `allow_full_payment` TINYINT(1) NOT NULL DEFAULT 1");
  }
  if (!columnExists($conn, 'Products', 'allow_installment')) {
    mysqli_query($conn, "ALTER TABLE `Products` ADD COLUMN `allow_installment` TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!columnExists($conn, 'Products', 'installment_count')) {
    mysqli_query($conn, "ALTER TABLE `Products` ADD COLUMN `installment_count` INT NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Products', 'installment_interval_unit')) {
    mysqli_query($conn, "ALTER TABLE `Products` ADD COLUMN `installment_interval_unit` VARCHAR(20) NULL DEFAULT 'month'");
  }
  if (!columnExists($conn, 'Product_Categories', 'allow_full_payment')) {
    mysqli_query($conn, "ALTER TABLE `Product_Categories` ADD COLUMN `allow_full_payment` TINYINT(1) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Product_Categories', 'allow_installment')) {
    mysqli_query($conn, "ALTER TABLE `Product_Categories` ADD COLUMN `allow_installment` TINYINT(1) NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Product_Categories', 'installment_count')) {
    mysqli_query($conn, "ALTER TABLE `Product_Categories` ADD COLUMN `installment_count` INT NULL DEFAULT NULL");
  }
  if (!columnExists($conn, 'Product_Categories', 'installment_interval_unit')) {
    mysqli_query($conn, "ALTER TABLE `Product_Categories` ADD COLUMN `installment_interval_unit` VARCHAR(20) NULL DEFAULT NULL");
  }
}

if (getenv("RUN_SCHEMA_MIGRATION") === "1") {
  ensureBillingPaymentSchema($conn);
  ensureOrdersDiscountSchema($conn);
  ensurePaymentSnapshotSchema($conn);
  ensureInstallmentPaymentSchema($conn);
}

function clip(?string $s, int $max): ?string
{
  if ($s === null) return null;
  $s = trim($s);
  if ($s === "") return null;

  // Fallback if mbstring is not enabled
  if (function_exists('mb_substr')) {
    return mb_substr($s, 0, $max, 'UTF-8');
  }
  return substr($s, 0, $max);
}

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function money(int|float|string|null $n): string
{
  return number_format((float)($n ?? 0), 2, '.', '');
}

function makePrefix3(string $productName): string
{
  $productName = trim($productName);
  if ($productName === '') return 'AAA';

  $words = preg_split('/\s+/u', $productName) ?: [];

  $cleanToken = function (string $t): string {
    $t = preg_replace('/[^A-Za-z]/', '', $t);
    return strtoupper($t ?? '');
  };

  // 1) Use first word ONLY if ALL CAPS (e.g. CNY)
  $origFirst = (string)($words[0] ?? '');
  $lettersOnly = preg_replace('/[^A-Za-z]/', '', $origFirst);
  $isAllCaps = ($lettersOnly !== '' && $lettersOnly === strtoupper($lettersOnly));
  $first = strtoupper($lettersOnly);

  if ($isAllCaps && strlen($first) >= 3) {
    return substr($first . 'AAA', 0, 3);
  }

  // 2) Otherwise, build from initials of up to 3 words
  $stop = ['AND', 'OF', 'THE', 'DAN', '&'];

  $letters = [];
  foreach ($words as $w) {
    $wClean = $cleanToken((string)$w);
    if ($wClean === '' || in_array($wClean, $stop, true)) continue;

    $letters[] = $wClean[0];
    if (count($letters) >= 3) break;
  }

  $prefix = implode('', $letters);
  $prefix = substr($prefix . 'AAA', 0, 3);
  $prefix = preg_replace('/[^A-Z]/', 'A', $prefix);

  return $prefix;
}

function random5Digits(): string
{
  // 10000..99999 (always 5 digits)
  return (string)random_int(10000, 99999);
}

function generateUniqueCodeId(mysqli $conn, string $productName): string
{
  $prefix = makePrefix3($productName);

  $sql = "SELECT 1 FROM `Payment` WHERE `codeid` = ? LIMIT 1";
  $stmt = $conn->prepare($sql);

  // If prepare fails, fallback without uniqueness check
  if (!$stmt) {
    return $prefix . random5Digits();
  }

  for ($attempt = 0; $attempt < 30; $attempt++) {
    $codeId = $prefix . random5Digits(); // 3 letters + 5 digits

    $stmt->bind_param("s", $codeId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
      $stmt->close();
      return $codeId;
    }
  }

  $stmt->close();
  return $prefix . random5Digits();
}

function detectMembershipPrefixFromVariant(string $variantName): string
{
  $v = strtoupper(trim($variantName));

  // Golden Circle
  if ($v !== "" && (strpos($v, "GOLDEN CIRCLE") !== false || preg_match('/\bGC\b/', $v))) {
    return "GC";
  }

  // SDC variants
  if ($v !== "" && preg_match('/\bSDC\b/', $v)) {
    return "SDC";
  }

  return "";
}

function validMemberCode(?string $code): ?string
{
  if (!$code) return null;
  $code = strtoupper(trim($code));
  return preg_match('/^(SDC|GC)\d{4}$/', $code) ? $code : null;
}

function generateUniqueMemberCode(mysqli $conn, string $prefix): string
{
  $sql = "SELECT 1 FROM `Payment` WHERE `sid` = ? LIMIT 1";
  $stmt = $conn->prepare($sql);

  for ($i = 0; $i < 80; $i++) {
    $candidate = $prefix . random_int(1000, 9999); // SDC1234 / GC1234
    if (!$stmt) return $candidate;

    $stmt->bind_param("s", $candidate);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
      $stmt->close();
      return $candidate;
    }
  }

  if ($stmt) $stmt->close();
  return $prefix . random_int(1000, 9999);
}

// ====== DB: load product ======
$fullName = trim((string)($_POST["fullName"] ?? ""));
$email    = trim((string)($_POST["email"] ?? ""));
$phone    = trim((string)($_POST["phone"] ?? ""));
$agreed   = isset($_POST["agreedTerms"]);
$payMode  = ((string)($_POST["pay_mode"] ?? "live")) === "sandbox" ? "sandbox" : "live";
$hasCatVariantTypeCol = columnExists($conn, "Product_Categories", "variant_type");
$hasCatElearningCourseCol = columnExists($conn, "Product_Categories", "elearning_course_id");

$host = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER["HTTP_HOST"] ?? "")));
if (!in_array($host, ["localhost", "127.0.0.1"], true)) {
  $payMode = "live";
}

if (!$agreed) {
  http_response_code(400);
  exit("Terms not agreed.");
}

if ($fullName === "" || $email === "" || $phone === "") {
  http_response_code(400);
  exit("Missing required fields.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  exit("Invalid email.");
}

if (!function_exists('norm_email')) {
  function norm_email(string $s): string
  {
    return strtolower(trim($s));
  }
}
if (!function_exists('norm_code')) {
  function norm_code(string $s): string
  {
    $s = strtoupper(trim($s));
    $s = preg_replace('/\s+/', '', $s);
    return $s;
  }
}
if (!function_exists('normalizeSubscriptionMode')) {
  function normalizeSubscriptionMode(string $mode): string
  {
    $mode = strtolower(trim($mode));

    // Legacy support
    if ($mode === 'initial') {
      return 'initial_full';
    }
    if ($mode === 'renewal') {
      error_log("WARN: Legacy 'renewal' mode detected, normalizing to 'installment'.");
      return 'installment';
    }

    // Validate and return new modes
    if (in_array($mode, ['initial_full', 'initial_installment', 'installment', 'retention', 'retention_installment', 'retention_legacy'], true)) {
      return $mode;
    }

    // Default fallback
    return 'initial_full';
  }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $conn, string $table): bool
  {
    $t = mysqli_real_escape_string($conn, $table);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return $r && mysqli_num_rows($r) > 0;
  }
}
if (!function_exists('bindParamsDynamic')) {
  function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$params): void
  {
    $refs = [];
    $refs[] = &$types;
    foreach ($params as $k => $v) {
      $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
  }
}

$emailNorm = norm_email($email);
$discountCodeIn = norm_code((string)($_POST["discount_code"] ?? "")); // hidden input dari payment.php
$productId  = (string)($_POST["product_id"] ?? "");
$categoryId = (string)($_POST["category_id"] ?? "");
$paymentMethod = strtolower(trim((string)($_POST["payment_method"] ?? "full")));
if (!in_array($paymentMethod, ["full", "installment"], true)) {
  $paymentMethod = "full";
}
if ($paymentMethod === "installment" && $discountCodeIn !== "") {
  http_response_code(400);
  exit("Discount codes are only applicable for full payment.");
}
$subscriptionMode = strtolower(trim((string)($_POST["subscription_mode"] ?? "initial")));
$subscriptionMode = normalizeSubscriptionMode($subscriptionMode);
$subscriptionIdIn = trim((string)($_POST["subscription_id"] ?? ""));

if ($productId === "") {
  http_response_code(400);
  exit("Missing product_id.");
}

$hasMembershipCol = columnExists($conn, "Products", "membership_types");
$hasProductElearningCourseCol = columnExists($conn, "Products", "elearning_course_id");
$hasProdIsSubscriptionCol = columnExists($conn, "Products", "is_subscription");
$hasProdFirstMonthPriceCol = columnExists($conn, "Products", "first_month_price");
$hasProdDurationValueCol = columnExists($conn, "Products", "duration_value");
$hasProdDurationUnitCol = columnExists($conn, "Products", "duration_unit");
$hasProdRemainingMonthPriceCol = columnExists($conn, "Products", "remaining_month_price");
$hasProdRetentionPriceCol = columnExists($conn, "Products", "retention_price");
$hasProdAllowFullPaymentCol = columnExists($conn, "Products", "allow_full_payment");
$hasProdAllowInstallmentCol = columnExists($conn, "Products", "allow_installment");
$hasProdInstallmentCountCol = columnExists($conn, "Products", "installment_count");
$hasProdInstallmentIntervalCol = columnExists($conn, "Products", "installment_interval_unit");

$hasCatAllowFullPaymentCol = columnExists($conn, "Product_Categories", "allow_full_payment");
$hasCatAllowInstallmentCol = columnExists($conn, "Product_Categories", "allow_installment");
$hasCatInstallmentCountCol = columnExists($conn, "Product_Categories", "installment_count");
$hasCatInstallmentIntervalCol = columnExists($conn, "Product_Categories", "installment_interval_unit");

$sqlProduct = "
  SELECT
    id,
    name,
    base_price AS basePrice,
    has_categories AS hasCategories,
    status
    " . ($hasMembershipCol ? ", membership_types" : "") . "
    " . ($hasProductElearningCourseCol ? ", elearning_course_id" : ", NULL AS elearning_course_id") . "
    " . ($hasProdIsSubscriptionCol ? ", is_subscription" : ", 0 AS is_subscription") . "
    " . ($hasProdFirstMonthPriceCol ? ", first_month_price" : ", NULL AS first_month_price") . "
    " . ($hasProdDurationValueCol ? ", duration_value" : ", NULL AS duration_value") . "
    " . ($hasProdDurationUnitCol ? ", duration_unit" : ", NULL AS duration_unit") . "
    " . ($hasProdRemainingMonthPriceCol ? ", remaining_month_price" : ", NULL AS remaining_month_price") . "
    " . ($hasProdRetentionPriceCol ? ", retention_price" : ", NULL AS retention_price") . "
    " . ($hasProdAllowFullPaymentCol ? ", allow_full_payment" : ", 1 AS allow_full_payment") . "
    " . ($hasProdAllowInstallmentCol ? ", allow_installment" : ", 0 AS allow_installment") . "
    " . ($hasProdInstallmentCountCol ? ", installment_count" : ", NULL AS installment_count") . "
    " . ($hasProdInstallmentIntervalCol ? ", installment_interval_unit" : ", 'month' AS installment_interval_unit") . "
  FROM Products
  WHERE id = ?
  LIMIT 1
";

$stmt = $conn->prepare($sqlProduct);
if (!$stmt) {
  http_response_code(500);
  exit("DB error (prepare product).");
}
$stmt->bind_param("s", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
  http_response_code(404);
  exit("Product not found.");
}

if (strtolower((string)($product["status"] ?? "")) !== "active") {
  http_response_code(404);
  include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/404.php";
  exit;
}

// membership_types (JSON array atau CSV) — ikut table kau
$membershipTypes = [];
if ($hasMembershipCol) {
  $mtRaw = trim((string)($product["membership_types"] ?? ""));
  if ($mtRaw !== "") {
    $mtJson = json_decode($mtRaw, true);
    if (is_array($mtJson)) {
      $membershipTypes = $mtJson;
    } else {
      $membershipTypes = preg_split('/\s*,\s*/', $mtRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
  }
}

// ====== DB: load categories (if product hasCategories) ======
$base = (float)($product["basePrice"] ?? 0);
$prodIsSubscription = ((int)($product["is_subscription"] ?? 0) === 1);
$prodFirstMonth = ($product["first_month_price"] === null || $product["first_month_price"] === "") ? null : (float)$product["first_month_price"];
$prodDurationValue = ($product["duration_value"] === null || $product["duration_value"] === "") ? null : (int)$product["duration_value"];
$prodDurationUnit = trim((string)($product["duration_unit"] ?? ""));
$prodRemainingMonth = ($product["remaining_month_price"] === null || $product["remaining_month_price"] === "") ? null : (float)$product["remaining_month_price"];
$prodRetentionPrice = ($product["retention_price"] === null || $product["retention_price"] === "") ? null : (float)$product["retention_price"];

$resolvedIsSubscription = $prodIsSubscription;
$resolvedFirstMonth = $prodFirstMonth;
$resolvedDurationValue = $prodDurationValue;
$resolvedDurationUnit = $prodDurationUnit;
$resolvedRemainingMonth = $prodRemainingMonth;
$resolvedRetentionPrice = $prodRetentionPrice;
$subTotal = $base;
$variantLabel = "";

$variantType = "normal";
$variantCourseId = trim((string)($product["elearning_course_id"] ?? ""));

$hasCats = !empty($product["hasCategories"]);
$cats = [];

if ($hasCats) {
  $hasCatIsSubscriptionCol = columnExists($conn, "Product_Categories", "is_subscription");
  $hasCatFirstMonthPriceCol = columnExists($conn, "Product_Categories", "first_month_price");
  $hasCatDurationValueCol = columnExists($conn, "Product_Categories", "duration_value");
  $hasCatDurationUnitCol = columnExists($conn, "Product_Categories", "duration_unit");
  $hasCatRemainingMonthPriceCol = columnExists($conn, "Product_Categories", "remaining_month_price");
  $hasCatRetentionPriceCol = columnExists($conn, "Product_Categories", "retention_price");
  $stmt = $conn->prepare("
    SELECT
      id,
      name,
      price_modifier AS priceModifier,
      " . ($hasCatVariantTypeCol ? "variant_type" : "'normal' AS variant_type") . ",
      " . ($hasCatElearningCourseCol ? "elearning_course_id" : "NULL AS elearning_course_id") . ",
      " . ($hasCatIsSubscriptionCol ? "is_subscription" : "NULL AS is_subscription") . ",
      " . ($hasCatFirstMonthPriceCol ? "first_month_price" : "NULL AS first_month_price") . ",
      " . ($hasCatDurationValueCol ? "duration_value" : "NULL AS duration_value") . ",
      " . ($hasCatDurationUnitCol ? "duration_unit" : "NULL AS duration_unit") . ",
      " . ($hasCatRemainingMonthPriceCol ? "remaining_month_price" : "NULL AS remaining_month_price") . ",
      " . ($hasCatRetentionPriceCol ? "retention_price" : "NULL AS retention_price") . "
    FROM Product_Categories
    WHERE product_id = ?
    ORDER BY id ASC
  ");
  if (!$stmt) {
    http_response_code(500);
    exit("DB error (prepare categories).");
  }
  $stmt->bind_param("s", $productId);
  $stmt->execute();
  $cats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  if (count($cats) > 0) {
    if ($categoryId === "") {
      http_response_code(400);
      exit("Missing package selection.");
    }

    $picked = null;
    foreach ($cats as $c) {
      if ((string)($c["id"] ?? "") === $categoryId) {
        $picked = $c;
        break;
      }
    }
    if (!$picked) {
      http_response_code(400);
      exit("Invalid package selection.");
    }

    $mod = (float)($picked["priceModifier"] ?? 0);
    $catSubRaw = $picked["is_subscription"] ?? null;
    $catIsSubscription = ($catSubRaw === null || $catSubRaw === "") ? null : ((int)$catSubRaw === 1);
    $resolvedIsSubscription = ($catIsSubscription === null) ? $prodIsSubscription : $catIsSubscription;
    $catFirstMonth = ($picked["first_month_price"] === null || $picked["first_month_price"] === "") ? null : (float)$picked["first_month_price"];

    // For products with variants, installment first payment must come from the selected variant/category.
    $resolvedFirstMonth = $catFirstMonth;
    $catDurationValue = ($picked["duration_value"] === null || $picked["duration_value"] === "") ? null : (int)$picked["duration_value"];
    $catDurationUnit = trim((string)($picked["duration_unit"] ?? ""));
    $catRemainingMonth = ($picked["remaining_month_price"] === null || $picked["remaining_month_price"] === "") ? null : (float)$picked["remaining_month_price"];
    $catRetentionPrice = ($picked["retention_price"] === null || $picked["retention_price"] === "") ? null : (float)$picked["retention_price"];
    $resolvedDurationValue = $catDurationValue ?? $prodDurationValue;
    $resolvedDurationUnit = $catDurationUnit !== "" ? $catDurationUnit : $prodDurationUnit;
    $resolvedRemainingMonth = $catRemainingMonth ?? $prodRemainingMonth;
    $resolvedRetentionPrice = $catRetentionPrice ?? $prodRetentionPrice;
    $subTotal = $base + $mod;
    $variantLabel = (string)($picked["name"] ?? "");

    $variantType = strtolower(trim((string)($picked["variant_type"] ?? "normal")));
    if (!in_array($variantType, ["normal", "elearning"], true)) {
      $variantType = "normal";
    }

    $variantCourseId = trim((string)($picked["elearning_course_id"] ?? ""));
    if ($variantType !== "elearning") {
      // kalau variant biasa, fallback ke product-level e-Learning course
      $variantCourseId = trim((string)($product["elearning_course_id"] ?? ""));
    }
  }
}

// ====== PAYMENT METHOD HANDLING ======
// Determine if installment is allowed for the product/category
$allowInstallment = false;
$installmentCount = null;
$installmentIntervalUnit = "month";

if ($categoryId !== "" && !empty($cats)) {
  $pickedCat = null;
  foreach ($cats as $c) {
    if ((string)($c["id"] ?? "") === $categoryId) {
      $pickedCat = $c;
      break;
    }
  }
  if ($pickedCat) {
    $catAllowInstallment = ($pickedCat["allow_installment"] === null || $pickedCat["allow_installment"] === "") ? null : (int)$pickedCat["allow_installment"];
    $catInstallmentCount = ($pickedCat["installment_count"] === null || $pickedCat["installment_count"] === "") ? null : (int)$pickedCat["installment_count"];
    $catInstallmentInterval = trim((string)($pickedCat["installment_interval_unit"] ?? ""));

    if ($catAllowInstallment === 1) {
      $allowInstallment = true;
      $installmentCount = $catInstallmentCount;
      $installmentIntervalUnit = $catInstallmentInterval ?: "month";
    } elseif ($catAllowInstallment === 0) {
      $allowInstallment = false;
    }
  }
}

// If category doesn't override, use product level
if (!isset($catAllowInstallment)) {
  $prodAllowInstallment = (int)($product["allow_installment"] ?? 0);
  $prodInstallmentCount = ($product["installment_count"] === null || $product["installment_count"] === "") ? null : (int)$product["installment_count"];
  $prodInstallmentInterval = trim((string)($product["installment_interval_unit"] ?? ""));

  if ($prodAllowInstallment === 1) {
    $allowInstallment = true;
    $installmentCount = $prodInstallmentCount;
    $installmentIntervalUnit = $prodInstallmentInterval ?: "month";
  }
}

// Validate and adjust payment method
if (!$allowInstallment) {
  $paymentMethod = "full";  // Force full payment if installment not allowed
}

$isInitialPayment = in_array($subscriptionMode, ["initial", "initial_full", "initial_installment"], true);

if (!$isInitialPayment && (!$resolvedIsSubscription || $subscriptionIdIn === "")) {
  http_response_code(400);
  exit("Missing subscription_id for renewal/retention mode.");
}

if ($isInitialPayment) {
  $fullInitialSubTotal = $subTotal;

  if ($paymentMethod === "installment" && $allowInstallment) {
    if ($resolvedFirstMonth === null || $resolvedFirstMonth <= 0) {
      http_response_code(400);
      exit("Installment payment not properly configured for this product.");
    }

    if ($resolvedFirstMonth >= $fullInitialSubTotal) {
      http_response_code(400);
      exit("First payment price must be less than the total product price.");
    }

    if ($installmentCount === null || $installmentCount <= 0) {
      http_response_code(400);
      exit("Invalid installment configuration.");
    }

    $remainingMonthCalculated = ($fullInitialSubTotal - $resolvedFirstMonth) / $installmentCount;

    if ($remainingMonthCalculated <= 0) {
      http_response_code(400);
      exit("Invalid installment configuration.");
    }

    // Subscription product = initial_installment.
    // One-time product = installment.
    $subscriptionMode = $resolvedIsSubscription ? "initial_installment" : "installment";
    $subTotal = $resolvedFirstMonth;
    $resolvedRemainingMonth = $remainingMonthCalculated;
  } else {
    $subscriptionMode = "initial_full";
    $subTotal = $fullInitialSubTotal;
    $resolvedRemainingMonth = null;
  }
} elseif ($subscriptionMode === "installment" || $subscriptionMode === "retention_legacy") {
  // Installment / next payment: charge remaining installment only.
  // Do NOT extend expiry date in callback.
  if ($resolvedRemainingMonth === null || $resolvedRemainingMonth <= 0) {
    http_response_code(400);
    exit("Installment / next payment amount is not configured.");
  }

  $subTotal = $resolvedRemainingMonth;
} elseif ($subscriptionMode === "retention") {
  // Retention payment is the only mode that should extend expiry date.
  // It can be paid full or by installment.
  $retentionPayable = $resolvedRetentionPrice ?? $resolvedRemainingMonth;

  if ($retentionPayable === null || $retentionPayable <= 0) {
    http_response_code(400);
    exit("Retention price is not configured.");
  }

  if ($paymentMethod === "installment" && $allowInstallment) {
    if ($resolvedFirstMonth === null || $resolvedFirstMonth <= 0) {
      http_response_code(400);
      exit("Retention installment first payment is not configured.");
    }

    if ($resolvedFirstMonth >= $retentionPayable) {
      http_response_code(400);
      exit("Retention first payment must be less than the retention offer price.");
    }

    if ($installmentCount === null || $installmentCount <= 0) {
      http_response_code(400);
      exit("Invalid retention installment configuration.");
    }

    $retentionRemainingCalculated = ($retentionPayable - $resolvedFirstMonth) / $installmentCount;

    if ($retentionRemainingCalculated <= 0) {
      http_response_code(400);
      exit("Invalid retention installment remaining amount.");
    }

    $subscriptionMode = "retention_installment";
    $subTotal = $resolvedFirstMonth;
    $resolvedRemainingMonth = $retentionRemainingCalculated;
  } else {
    $subscriptionMode = "retention";
    $subTotal = $retentionPayable;

    // Full retention payment should not create installment balance.
    $resolvedRemainingMonth = null;
  }
} else {
  http_response_code(400);
  exit("Invalid subscription payment mode.");
}

$subTotalBeforeDiscount = $subTotal;
$discountAmount = 0.0;
$discountCodeUsed = ""; // for DB logging later
$discountCodeId = null;
$renewalDiscountTypeSnapshot = null;
$renewalDiscountValueSnapshot = null;
$retentionDiscountTypeSnapshot = null;
$retentionDiscountValueSnapshot = null;

// Discount code is only accepted for initial full payment.
if ($subscriptionMode !== "initial_full" || $paymentMethod !== "full") {
  $discountCodeIn = "";
}

// Auto retention discount applies only to full retention payment.
// Installment payment must not receive discount.
if ($subscriptionMode === "retention" && $paymentMethod === "full") {
  $autoRetentionDiscount = findAutoRetentionDiscount(
    $conn,
    (string)$productId,
    (string)$categoryId,
    (string)$emailNorm
  );

  if ($autoRetentionDiscount) {
    [$subTotalAfterRetentionDiscount, $autoRetentionDiscountAmount] = applyLifecycleDiscountAmount(
      (float)$subTotal,
      $autoRetentionDiscount
    );

    if ($autoRetentionDiscountAmount > 0) {
      $subTotal = $subTotalAfterRetentionDiscount;
      $discountAmount = $autoRetentionDiscountAmount;
      $discountCodeUsed = (string)($autoRetentionDiscount["code"] ?? "AUTO_RETENTION");
      $discountCodeId = (int)($autoRetentionDiscount["id"] ?? 0);

      $retentionDiscountTypeSnapshot = (string)($autoRetentionDiscount["retention_discount_type"] ?? "");
      $retentionDiscountValueSnapshot = (float)($autoRetentionDiscount["retention_discount_value"] ?? 0);
    }
  }
}

if ($discountCodeIn !== "") {
  if (!tableExists($conn, "Discount_Codes")) {
    http_response_code(400);
    exit("Discount system not available (missing table).");
  }

  $stmt = $conn->prepare("
    SELECT id, discount_type, discount_value, product_id, category_id, allowed_email,
           valid_from, valid_until, max_redemptions, per_email_limit, status,
           " . (columnExists($conn, "Discount_Codes", "renewal_discount_type") ? "renewal_discount_type" : "NULL AS renewal_discount_type") . ",
           " . (columnExists($conn, "Discount_Codes", "renewal_discount_value") ? "renewal_discount_value" : "NULL AS renewal_discount_value") . ",
           " . (columnExists($conn, "Discount_Codes", "retention_discount_type") ? "retention_discount_type" : "NULL AS retention_discount_type") . ",
           " . (columnExists($conn, "Discount_Codes", "retention_discount_value") ? "retention_discount_value" : "NULL AS retention_discount_value") . "
    FROM Discount_Codes
    WHERE code = ?
    LIMIT 1
  ");
  if (!$stmt) {
    http_response_code(500);
    exit("DB error (prepare discount).");
  }

  $stmt->bind_param("s", $discountCodeIn);
  $stmt->execute();
  $dc = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$dc || strtolower((string)($dc["status"] ?? "")) !== "active") {
    http_response_code(400);
    exit("Invalid / inactive discount code.");
  }

  $now = new DateTime("now");
  $vf = !empty($dc["valid_from"]) ? new DateTime((string)$dc["valid_from"]) : null;
  $vu = !empty($dc["valid_until"]) ? new DateTime((string)$dc["valid_until"]) : null;

  // ✅ blank timer = NULL => skip checks
  if ($vf && $now < $vf) {
    http_response_code(400);
    exit("Discount code not active yet.");
  }
  if ($vu && $now > $vu) {
    setDiscountInactive($conn, (int)$dc["id"]);
    http_response_code(400);
    exit("Discount code expired.");
  }

  $allowedEmail = strtolower(trim((string)($dc["allowed_email"] ?? "")));

  if ($allowedEmail !== "") {
    $list = preg_split('/[,\s;]+/', $allowedEmail, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $list = array_values(array_unique(array_map('trim', $list)));

    if (!in_array($emailNorm, $list, true)) {
      http_response_code(400);
      exit("Discount code not valid for this email.");
    }
  }

  if (!empty($dc["product_id"]) && (string)$dc["product_id"] !== $productId) {
    http_response_code(400);
    exit("Discount code not valid for this product.");
  }
  if (!empty($dc["category_id"]) && (string)$dc["category_id"] !== $categoryId) {
    http_response_code(400);
    exit("Discount code not valid for this package.");
  }

  // limits (only if redemptions table exists)
  if (tableExists($conn, "Discount_Redemptions")) {
    $dcId = (int)$dc["id"];

    if (!empty($dc["max_redemptions"])) {
      $max = (int)$dc["max_redemptions"];
      $st2 = $conn->prepare("SELECT COUNT(*) c FROM Discount_Redemptions WHERE discount_code_id=? AND status='paid'");
      $st2->bind_param("i", $dcId);
      $st2->execute();
      $c = (int)($st2->get_result()->fetch_assoc()["c"] ?? 0);
      $st2->close();
      if ($c >= $max) {
        setDiscountInactive($conn, (int)$dc["id"]);
        http_response_code(400);
        exit("Discount code fully used.");
      }
    }

    $per = (int)($dc["per_email_limit"] ?? 1);
    if ($per > 0) {
      $st3 = $conn->prepare("SELECT COUNT(*) c FROM Discount_Redemptions WHERE discount_code_id=? AND email=? AND status='paid'");
      $st3->bind_param("is", $dcId, $emailNorm);
      $st3->execute();
      $c = (int)($st3->get_result()->fetch_assoc()["c"] ?? 0);
      $st3->close();
      if ($c >= $per) {
        http_response_code(400);
        exit("Discount code already used for this email.");
      }
    }
  }

  // calculate discount (before SST)
  $type = (string)$dc["discount_type"];
  $val  = (float)$dc["discount_value"];

  if ($type === "percent") $discountAmount = $subTotal * ($val / 100.0);
  else $discountAmount = $val;

  if ($discountAmount < 0) $discountAmount = 0.0;
  if ($discountAmount > $subTotal) $discountAmount = $subTotal;

  $subTotal = $subTotal - $discountAmount;
  $discountCodeUsed = $discountCodeIn;

  // Store discount code ID and retention snapshot only for initial full payment.
  $discountCodeId = (int)($dc["id"] ?? 0);

  if ($resolvedIsSubscription && $subscriptionMode === "initial_full" && $paymentMethod === "full") {
    $retentionDiscountTypeSnapshot = (string)($dc["retention_discount_type"] ?? "");
    $retentionDiscountValueSnapshot = ($dc["retention_discount_value"] === null || $dc["retention_discount_value"] === "") ? null : (float)$dc["retention_discount_value"];
  }
}

// SID = Member Code (SDC1234 / GC1234) based on selected variant name + whitelist
if (!is_array($membershipTypes)) $membershipTypes = [];
$membershipTypes = array_values(array_unique(array_filter(
  array_map('strtoupper', $membershipTypes),
  fn($t) => in_array($t, ['SDC', 'GC'], true)
)));

$variantNameForMembership = $variantLabel !== "" ? $variantLabel : ((string)($product["name"] ?? ""));
$prefix = detectMembershipPrefixFromVariant($variantNameForMembership);

// ✅ only generate if admin enabled that type for this product AND variant indicates it
$isEnabledType = ($prefix !== "" && in_array($prefix, $membershipTypes, true));

$incomingSid = validMemberCode($_POST["sid"] ?? ($_GET["sid"] ?? null));

$sessionCodes = $_SESSION["member_codes"] ?? [];
if (!is_array($sessionCodes)) $sessionCodes = [];

$sessionSid = ($prefix !== "" && isset($sessionCodes[$prefix]))
  ? validMemberCode((string)$sessionCodes[$prefix])
  : null;

if ($isEnabledType) {
  if ($incomingSid && strpos($incomingSid, $prefix) === 0) {
    $sidDb = $incomingSid;
  } elseif ($sessionSid) {
    $sidDb = $sessionSid;
  } else {
    $sidDb = generateUniqueMemberCode($conn, $prefix);
    $sessionCodes[$prefix] = $sidDb;
    $_SESSION["member_codes"] = $sessionCodes;
  }
} else {
  $sidDb = "NONE";
}

// ✅ apply SST supaya match UI payment.php
$sstAmount = $subTotal * SST_RATE;
$totalDue = $subTotal + $sstAmount;
$amountStr = money($totalDue);
if ((float)$amountStr < 2) {
  http_response_code(400);
  exit("Minimum amount is RM2.00 (SenangPay restriction). amount=RM{$amountStr}");
}

// Demo mode: amount now computed — forward all params to demo gateway
if (defined('DEMO_MODE') && DEMO_MODE) {
    $demoOrderId = 'DEMO-' . strtoupper(bin2hex(random_bytes(4)));
    header('Location: /demo-gateway.php?' . http_build_query([
        'order_id' => $demoOrderId,
        'amount'   => $amountStr,
        'name'     => $fullName,
        'email'    => $email,
        'phone'    => $phone,
    ]));
    exit;
}

// build SenangPay params
// order_id allowed: A-Z a-z 0-9 and dash only
$orderId = "SDC-" . date("YmdHis") . "-" . random_int(1000, 9999);

// detail allowed: underscore, dash, etc
$detail = trim(((string)($product["name"] ?? 'Demo Product')) . ($variantLabel ? " - " . $variantLabel : ""));
$detail = preg_replace('/[^A-Za-z0-9\.\,\-\_\s]/', '', $detail);
$detail = clip($detail, 200) ?? $detail;
if ($detail === '') $detail = 'SDC Product';

if ($payMode === "sandbox") {
  $merchantId = getenv("SENANGPAY_SANDBOX_MERCHANT_ID") ?: '';
  $secretKey  = getenv("SENANGPAY_SANDBOX_SECRET_KEY") ?: '';
  $payUrl     = "https://sandbox.senangpay.my/payment/";
} else {
  $merchantId = getenv("SENANGPAY_LIVE_MERCHANT_ID") ?: '';
  $secretKey  = getenv("SENANGPAY_LIVE_SECRET_KEY") ?: '';
  $payUrl     = "https://app.senangpay.my/payment/";
}

if ($merchantId === '' || $secretKey === '') {
  http_response_code(500);
  exit("Missing SenangPay env keys.");
}

$payUrl .= $merchantId;

// SenangPay Open API hash: secretKey + detail + amount + order_id
$strToHash = $secretKey . $detail . $amountStr . $orderId;
$hash = hash_hmac('sha256', $strToHash, $secretKey);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

$returnUrl = $baseUrl . "/payment-result.php";

// sandbox di localhost: jangan set callback_url
$host = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));

// ── Dynamic callback routing based on subscriptionMode ──
$callbackUrlPath = "/process-payment.php";

if (in_array($subscriptionMode, ["retention", "retention_installment"], true)) {
  // Retention full and retention installment both extend expiry date.
  $callbackUrlPath = "/payment/process-retention.php";
}

$callbackUrl = ($payMode === 'sandbox' && in_array($host, ['localhost', '127.0.0.1'], true))
  ? ''
  : ($baseUrl . $callbackUrlPath);

// ✅ INSERT into Payment table (for dashboard & transaction logs)
$itemNameRaw = (string)($product["name"] ?? "Product");
$itemNameDb  = clip($itemNameRaw, 255) ?? "Product";

// codeid example: WKS98356 (3 letters + 5 digits)
$codeId = generateUniqueCodeId($conn, $itemNameRaw);

// Optional fields
$packageDb = clip($variantLabel !== "" ? $variantLabel : null, 50);
$channelDb = clip("senangpay-" . $payMode, 50) ?? ("senangpay-" . $payMode);

// Store total due (incl SST) in price
$priceDb     = (float)$totalDue;
$trxIdDb  = clip($orderId, 120) ?? $orderId;

// Referral: accept POST or ?ref=
$referredBy = clip($_POST["referred_by"] ?? ($_GET["ref"] ?? null), 50);

$nameDb  = clip($fullName, 255) ?? $fullName;
$emailDb = clip($email, 255) ?? $email;
$phoneDb = clip($phone, 20) ?? $phone;

// ====== PREP VALUES ======
$productIdDb = clip($productId, 60) ?? $productId;

// pastikan string tak jadi NULL masa bind_param
$packageDb   = $packageDb   ?? '';
$referredBy  = $referredBy  ?? '';
$statusDb    = 'pending';
$verifiedDb  = 0;

// Orders fields ikut table structure
$productNameDb = clip((string)($product["name"] ?? ""), 255) ?? (string)($product["name"] ?? "");
$variantDb     = clip($variantLabel !== "" ? $variantLabel : null, 100) ?? "";
$refDb         = $referredBy;

// decimal fields
$subDb       = (float)$subTotal;
$amtDb = (float)$totalDue;

$orderStatus = 'pending';

// Orders.created_at = datetime (nullable) → kita isi
$createdAt = date("Y-m-d H:i:s");

// Orders.transaction_id (nullable) → aku isi sama macam Payment.transaction_id
$ordersTrxId = $trxIdDb;

$discCodeDb = clip($discountCodeUsed !== "" ? $discountCodeUsed : null, 64);
$discAmtDb   = (float)$discountAmount;
$subBeforeDb = (float)$subTotalBeforeDiscount;
$discountCodeIdDb = $discountCodeId > 0 ? (int)$discountCodeId : null;
$renewalDiscountTypeSnapshotDb = ($renewalDiscountTypeSnapshot !== null && $renewalDiscountTypeSnapshot !== "") ? $renewalDiscountTypeSnapshot : null;
$renewalDiscountValueSnapshotDb = $renewalDiscountValueSnapshot !== null ? money($renewalDiscountValueSnapshot) : null;
$retentionDiscountTypeSnapshotDb = ($retentionDiscountTypeSnapshot !== null && $retentionDiscountTypeSnapshot !== "") ? $retentionDiscountTypeSnapshot : null;
$retentionDiscountValueSnapshotDb = $retentionDiscountValueSnapshot !== null ? money($retentionDiscountValueSnapshot) : null;
$subscriptionIdDb = ($subscriptionIdIn !== "") ? (clip($subscriptionIdIn, 30) ?? $subscriptionIdIn) : null;
$subscriptionModeDb = $subscriptionMode;
$isSubscriptionDb = $resolvedIsSubscription ? 1 : 0;
$durationValueDb = $resolvedDurationValue;
$durationUnitDb = ($resolvedDurationUnit !== "") ? clip($resolvedDurationUnit, 20) : null;
$firstMonthPriceDb = $resolvedFirstMonth !== null ? money($resolvedFirstMonth) : null;
$remainingMonthPriceDb = $resolvedRemainingMonth !== null ? money($resolvedRemainingMonth) : null;
$retentionPriceDb = $resolvedRetentionPrice !== null ? money($resolvedRetentionPrice) : null;

$hasPaySubscriptionIdCol = columnExists($conn, "Payment", "subscription_id");
$hasPaySubscriptionModeCol = columnExists($conn, "Payment", "subscription_mode");
$hasPayIsSubscriptionCol = columnExists($conn, "Payment", "is_subscription");
$hasPayDurationValueCol = columnExists($conn, "Payment", "duration_value");
$hasPayDurationUnitCol = columnExists($conn, "Payment", "duration_unit");
$hasPayFirstMonthPriceCol = columnExists($conn, "Payment", "first_month_price");
$hasPayRemainingMonthPriceCol = columnExists($conn, "Payment", "remaining_month_price");
$hasPayRetentionPriceCol = columnExists($conn, "Payment", "retention_price");
$hasPayDiscountCodeIdCol = columnExists($conn, "Payment", "discount_code_id");
$hasPayRenewalDiscountTypeSnapshotCol = columnExists($conn, "Payment", "renewal_discount_type_snapshot");
$hasPayRenewalDiscountValueSnapshotCol = columnExists($conn, "Payment", "renewal_discount_value_snapshot");
$hasPayRetentionDiscountTypeSnapshotCol = columnExists($conn, "Payment", "retention_discount_type_snapshot");
$hasPayRetentionDiscountValueSnapshotCol = columnExists($conn, "Payment", "retention_discount_value_snapshot");

$hasOrdSubscriptionIdCol = columnExists($conn, "Orders", "subscription_id");
$hasOrdSubscriptionModeCol = columnExists($conn, "Orders", "subscription_mode");
$hasOrdIsSubscriptionCol = columnExists($conn, "Orders", "is_subscription");
$hasOrdDurationValueCol = columnExists($conn, "Orders", "duration_value");
$hasOrdDurationUnitCol = columnExists($conn, "Orders", "duration_unit");
$hasOrdFirstMonthPriceCol = columnExists($conn, "Orders", "first_month_price");
$hasOrdRemainingMonthPriceCol = columnExists($conn, "Orders", "remaining_month_price");
$hasOrdRetentionPriceCol = columnExists($conn, "Orders", "retention_price");
$hasOrdDiscountCodeIdCol = columnExists($conn, "Orders", "discount_code_id");
$hasOrdRenewalDiscountTypeSnapshotCol = columnExists($conn, "Orders", "renewal_discount_type_snapshot");
$hasOrdRenewalDiscountValueSnapshotCol = columnExists($conn, "Orders", "renewal_discount_value_snapshot");
$hasOrdRetentionDiscountTypeSnapshotCol = columnExists($conn, "Orders", "retention_discount_type_snapshot");
$hasOrdRetentionDiscountValueSnapshotCol = columnExists($conn, "Orders", "retention_discount_value_snapshot");

// ====== TRANSACTION: Payment + Orders ======
if ($variantType === "elearning" && $variantCourseId === "") {
  http_response_code(400);
  exit("Selected e-Learning variant is missing linked course.");
}

$conn->begin_transaction();

$stmtPay = null;
$stmtOrd = null;

try {
  // -------------------------
  // 1) INSERT Payment
  // -------------------------
  $productCategoryIdDb = $categoryId !== '' ? (clip($categoryId, 60) ?? $categoryId) : null;
  $variantTypeDb = $variantType;
  $elearningCourseIdDb = $variantCourseId !== '' ? (clip($variantCourseId, 60) ?? $variantCourseId) : null;

  $payCols = [
    "codeid",
    "product_type",
    "product_category_id",
    "variant_type",
    "elearning_course_id",
    "name",
    "email",
    "phone",
    "item",
    "package",
    "channel",
    "price",
    "transaction_id",
    "sid",
    "referred_by",
    "status",
    "verified",
    "discount_code",
    "discount_amount",
    "subtotal_before_discount"
  ];
  $payTypes = "sssssssssssdssssisdd";
  $payParams = [
    $codeId,
    $productIdDb,
    $productCategoryIdDb,
    $variantTypeDb,
    $elearningCourseIdDb,
    $nameDb,
    $emailDb,
    $phoneDb,
    $itemNameDb,
    $packageDb,
    $channelDb,
    $priceDb,
    $trxIdDb,
    $sidDb,
    $referredBy,
    $statusDb,
    $verifiedDb,
    $discCodeDb,
    $discAmtDb,
    $subBeforeDb
  ];

  if ($hasPaySubscriptionIdCol) {
    $payCols[] = "subscription_id";
    $payTypes .= "s";
    $payParams[] = $subscriptionIdDb;
  }
  if ($hasPaySubscriptionModeCol) {
    $payCols[] = "subscription_mode";
    $payTypes .= "s";
    $payParams[] = $subscriptionModeDb;
  }
  if ($hasPayIsSubscriptionCol) {
    $payCols[] = "is_subscription";
    $payTypes .= "i";
    $payParams[] = $isSubscriptionDb;
  }
  if ($hasPayDurationValueCol) {
    $payCols[] = "duration_value";
    $payTypes .= "s";
    $payParams[] = ($durationValueDb === null ? null : (string)$durationValueDb);
  }
  if ($hasPayDurationUnitCol) {
    $payCols[] = "duration_unit";
    $payTypes .= "s";
    $payParams[] = $durationUnitDb;
  }
  if ($hasPayFirstMonthPriceCol) {
    $payCols[] = "first_month_price";
    $payTypes .= "s";
    $payParams[] = $firstMonthPriceDb;
  }
  if ($hasPayRemainingMonthPriceCol) {
    $payCols[] = "remaining_month_price";
    $payTypes .= "s";
    $payParams[] = $remainingMonthPriceDb;
  }
  if ($hasPayRetentionPriceCol) {
    $payCols[] = "retention_price";
    $payTypes .= "s";
    $payParams[] = $retentionPriceDb;
  }
  if ($hasPayDiscountCodeIdCol) {
    $payCols[] = "discount_code_id";
    $payTypes .= "s";
    $payParams[] = ($discountCodeIdDb === null ? null : (string)$discountCodeIdDb);
  }
  if ($hasPayRenewalDiscountTypeSnapshotCol) {
    $payCols[] = "renewal_discount_type_snapshot";
    $payTypes .= "s";
    $payParams[] = $renewalDiscountTypeSnapshotDb;
  }
  if ($hasPayRenewalDiscountValueSnapshotCol) {
    $payCols[] = "renewal_discount_value_snapshot";
    $payTypes .= "s";
    $payParams[] = $renewalDiscountValueSnapshotDb;
  }
  if ($hasPayRetentionDiscountTypeSnapshotCol) {
    $payCols[] = "retention_discount_type_snapshot";
    $payTypes .= "s";
    $payParams[] = $retentionDiscountTypeSnapshotDb;
  }
  if ($hasPayRetentionDiscountValueSnapshotCol) {
    $payCols[] = "retention_discount_value_snapshot";
    $payTypes .= "s";
    $payParams[] = $retentionDiscountValueSnapshotDb;
  }

  $sqlPay = "INSERT INTO `Payment` (`" . implode("`,`", $payCols) . "`) VALUES (" . implode(",", array_fill(0, count($payCols), "?")) . ")";

  $stmtPay = $conn->prepare($sqlPay);
  if (!$stmtPay) {
    throw new RuntimeException("Prepare failed (Payment insert): " . $conn->error);
  }

  bindParamsDynamic($stmtPay, $payTypes, $payParams);

  if (!$stmtPay->execute()) {
    throw new RuntimeException("Execute failed (Payment insert): " . $stmtPay->error);
  }
  $stmtPay->close();
  $stmtPay = null;

  // -------------------------
  // 2) INSERT Orders (ikut table: created_at + transaction_id)
  // -------------------------
  $productIdOrd = $productIdDb;               // dah clip 60
  $categoryIdOrd = ($categoryId !== '') ? (clip($categoryId, 60) ?? $categoryId) : null;

  $ordCols = [
    "order_id",
    "codeid",
    "product_id",
    "category_id",
    "product_name",
    "variant",
    "subtotal",
    "amount",
    "name",
    "email",
    "phone",
    "mode",
    "status",
    "referred_by",
    "created_at",
    "transaction_id",
    "discount_code",
    "discount_amount",
    "subtotal_before_discount"
  ];
  $ordTypes = "ssssssddsssssssssdd";
  $ordParams = [
    $orderId,
    $codeId,
    $productIdOrd,
    $categoryIdOrd,
    $productNameDb,
    $variantDb,
    $subDb,
    $amtDb,
    $nameDb,
    $emailDb,
    $phoneDb,
    $payMode,
    $orderStatus,
    $refDb,
    $createdAt,
    $ordersTrxId,
    $discCodeDb,
    $discAmtDb,
    $subBeforeDb
  ];

  if ($hasOrdSubscriptionIdCol) {
    $ordCols[] = "subscription_id";
    $ordTypes .= "s";
    $ordParams[] = $subscriptionIdDb;
  }
  if ($hasOrdSubscriptionModeCol) {
    $ordCols[] = "subscription_mode";
    $ordTypes .= "s";
    $ordParams[] = $subscriptionModeDb;
  }
  if ($hasOrdIsSubscriptionCol) {
    $ordCols[] = "is_subscription";
    $ordTypes .= "i";
    $ordParams[] = $isSubscriptionDb;
  }
  if ($hasOrdDurationValueCol) {
    $ordCols[] = "duration_value";
    $ordTypes .= "s";
    $ordParams[] = ($durationValueDb === null ? null : (string)$durationValueDb);
  }
  if ($hasOrdDurationUnitCol) {
    $ordCols[] = "duration_unit";
    $ordTypes .= "s";
    $ordParams[] = $durationUnitDb;
  }
  if ($hasOrdFirstMonthPriceCol) {
    $ordCols[] = "first_month_price";
    $ordTypes .= "s";
    $ordParams[] = $firstMonthPriceDb;
  }
  if ($hasOrdRemainingMonthPriceCol) {
    $ordCols[] = "remaining_month_price";
    $ordTypes .= "s";
    $ordParams[] = $remainingMonthPriceDb;
  }
  if ($hasOrdRetentionPriceCol) {
    $ordCols[] = "retention_price";
    $ordTypes .= "s";
    $ordParams[] = $retentionPriceDb;
  }
  if ($hasOrdDiscountCodeIdCol) {
    $ordCols[] = "discount_code_id";
    $ordTypes .= "s";
    $ordParams[] = ($discountCodeIdDb === null ? null : (string)$discountCodeIdDb);
  }
  if ($hasOrdRenewalDiscountTypeSnapshotCol) {
    $ordCols[] = "renewal_discount_type_snapshot";
    $ordTypes .= "s";
    $ordParams[] = $renewalDiscountTypeSnapshotDb;
  }
  if ($hasOrdRenewalDiscountValueSnapshotCol) {
    $ordCols[] = "renewal_discount_value_snapshot";
    $ordTypes .= "s";
    $ordParams[] = $renewalDiscountValueSnapshotDb;
  }
  if ($hasOrdRetentionDiscountTypeSnapshotCol) {
    $ordCols[] = "retention_discount_type_snapshot";
    $ordTypes .= "s";
    $ordParams[] = $retentionDiscountTypeSnapshotDb;
  }
  if ($hasOrdRetentionDiscountValueSnapshotCol) {
    $ordCols[] = "retention_discount_value_snapshot";
    $ordTypes .= "s";
    $ordParams[] = $retentionDiscountValueSnapshotDb;
  }

  $sqlOrd = "INSERT INTO `Orders` (`" . implode("`,`", $ordCols) . "`) VALUES (" . implode(",", array_fill(0, count($ordCols), "?")) . ")";

  $stmtOrd = $conn->prepare($sqlOrd);
  if (!$stmtOrd) {
    throw new RuntimeException("Prepare failed (Orders insert): " . $conn->error);
  }

  bindParamsDynamic($stmtOrd, $ordTypes, $ordParams);

  if (!$stmtOrd->execute()) {
    throw new RuntimeException("Execute failed (Orders insert): " . $stmtOrd->error);
  }
  $stmtOrd->close();
  $stmtOrd = null;

  // -------------------------
  // 3) COMMIT
  // -------------------------
  $conn->commit();

  // ✅ Log subscription mode and callback routing for debugging
  error_log(
    '[start-payment.php] Payment created successfully: order_id=' . $orderId
      . ' subscription_mode=' . $subscriptionMode
      . ' subscription_id=' . ($subscriptionIdDb ?? 'null')
      . ' callback_url=' . ($callbackUrl ?: 'NONE (sandbox/localhost)')
  );
} catch (Throwable $e) {
  if ($stmtPay) $stmtPay->close();
  if ($stmtOrd) $stmtOrd->close();

  $conn->rollback();
  error_log("Transaction failed: " . $e->getMessage());
  http_response_code(500);
  exit("Unable to create transaction record.");
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Redirecting...</title>
  <link href="/img/demo_logo.svg" rel="icon">
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

<body class="bg-slate-50">
  <div class="min-h-screen flex items-center justify-center p-6">
    <div class="bg-white border shadow-xl rounded-[2.5rem] p-10 max-w-lg w-full text-center">
      <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-yellow-100 flex items-center justify-center">
        <svg class="w-8 h-8 text-yellow-600 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>
      <h1 class="text-2xl font-black text-slate-900 mb-2">Redirecting to SenangPay</h1>
      <p class="text-slate-500 mb-6">Do not close this page...</p>

      <div class="text-left bg-slate-50 border rounded-2xl p-4 text-sm">
        <div class="font-black text-slate-900 mb-1"><?= h($detail) ?></div>
        <div class="text-slate-600">Order ID: <span class="font-mono"><?= h($orderId) ?></span></div>
        <div class="text-slate-600">Amount: <span class="font-black"><?= h("RM" . $amountStr) ?></span></div>
      </div>

      <form id="spForm" action="<?= h($payUrl) ?>" method="POST" class="hidden">
        <input type="hidden" name="detail" value="<?= h($detail) ?>">
        <input type="hidden" name="amount" value="<?= h($amountStr) ?>">
        <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
        <input type="hidden" name="hash" value="<?= h($hash) ?>">
        <input type="hidden" name="name" value="<?= h($fullName) ?>">
        <input type="hidden" name="email" value="<?= h($email) ?>">
        <input type="hidden" name="phone" value="<?= h($phone) ?>">

        <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php if ($callbackUrl !== ''): ?>
          <input type="hidden" name="callback_url" value="<?= h($callbackUrl) ?>">
        <?php endif; ?>
      </form>

      <button
        onclick="document.getElementById('spForm').submit()"
        class="mt-6 w-full py-4 rounded-2xl bg-yellow-500 hover:bg-yellow-600 font-black">
        Continue
      </button>
    </div>
  </div>

  <script>
    setTimeout(() => document.getElementById("spForm").submit(), 400);
  </script>
</body>

</html>