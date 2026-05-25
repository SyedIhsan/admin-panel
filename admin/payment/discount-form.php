<?php

declare(strict_types=1);
require_once __DIR__ . "/_init.php"; // auth + db + csrf
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

date_default_timezone_set('Asia/Kuala_Lumpur');

$mainConn = getBillingConn();     // main DB: Discount_Codes, Products, Product_Categories
$elConn   = getElearningConn();   // e-Learning DB: courses

$conn = $mainConn;

function currentDbName(mysqli $conn): string
{
  $res = $conn->query("SELECT DATABASE() AS db");
  if (!$res) return '';
  $row = $res->fetch_assoc();
  return (string)($row['db'] ?? '');
}

if ($elConn instanceof mysqli) {
  if (tableExists($elConn, 'courses')) {
    $chk = $elConn->query("SELECT COUNT(*) AS total FROM courses");
    if ($chk) {
      $row = $chk->fetch_assoc();
    } else {
      error_log('discount-form courses query failed = ' . $elConn->error);
    }
  } else {
    error_log('discount-form: courses table not found in elearning connection');
  }
}

/**
 * Helpers
 */
if (!function_exists('h')) {
  function h(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
  }
}
function tableExists(mysqli $conn, string $table): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
  return $r && mysqli_num_rows($r) > 0;
}
function columnExists(mysqli $conn, string $table, string $col): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}
function addColumnIfMissing(mysqli $conn, string $table, string $col, string $definition): bool
{
  if (columnExists($conn, $table, $col)) return true;

  $t = mysqli_real_escape_string($conn, $table);
  $sql = "ALTER TABLE `{$t}` ADD COLUMN `{$col}` {$definition}";
  $ok = mysqli_query($conn, $sql);
  if (!$ok) {
    error_log("discount-form addColumnIfMissing failed for {$table}.{$col}: " . $conn->error);
  }
  return $ok && columnExists($conn, $table, $col);
}
function norm_code(string $s): string
{
  $s = strtoupper(trim($s));
  $s = preg_replace('/\s+/', '', $s);
  return $s;
}
function norm_email(string $s): string
{
  return strtolower(trim($s));
}
function is_valid_email(string $s): bool
{
  return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}
function norm_discount_type(string $s, bool $allowBlank = false): ?string
{
  $s = trim(strtolower($s));
  if ($s === '' && $allowBlank) return null;
  return ($s === 'fixed') ? 'fixed' : (($s === 'percent') ? 'percent' : null);
}
function decimal_string_or_null(?float $value): ?string
{
  if ($value === null) return null;
  return number_format($value, 2, '.', '');
}
function stmt_bind_all(mysqli_stmt $stmt, string $types, array $params): void
{
  $bind = array_merge([$types], $params);
  $refs = [];
  foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
  call_user_func_array([$stmt, 'bind_param'], $refs);
}
function effective_subscription_flag(
  mysqli $conn,
  string $productId,
  string $categoryId,
  bool $hasProdIsSubscriptionCol,
  bool $hasCatIsSubscriptionCol,
  array $courseMap = []
): bool {
  if ($productId === '' || $productId === 'all') return false;
  if (isset($courseMap[$productId])) return false;

  $productIsSubscription = false;
  if ($hasProdIsSubscriptionCol) {
    $stmt = $conn->prepare("SELECT is_subscription FROM Products WHERE id = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $productId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      $productIsSubscription = ((int)($row['is_subscription'] ?? 0) === 1);
    }
  }

  // If the product itself is a subscription, it applies to all variants.
  if ($productIsSubscription) return true;

  if ($categoryId !== '' && $categoryId !== 'all' && $hasCatIsSubscriptionCol) {
    $stmt = $conn->prepare("SELECT is_subscription FROM Product_Categories WHERE id = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $categoryId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row && array_key_exists('is_subscription', $row) && $row['is_subscription'] !== null) {
        return ((int)$row['is_subscription'] === 1);
      }
    }
  }

  // If "All Variants" selected, check if ANY variant is a subscription
  if (($categoryId === '' || $categoryId === 'all') && $hasCatIsSubscriptionCol) {
    $stmt = $conn->prepare("SELECT 1 FROM Product_Categories WHERE product_id = ? AND is_subscription = 1 LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $productId);
      $stmt->execute();
      $res = $stmt->get_result();
      $hasSub = ($res && $res->num_rows > 0);
      $stmt->close();
      return $hasSub;
    }
  }

  return false;
}
function validate_lifecycle_discount(
  string $typeRaw,
  string $valueRaw,
  string $typeField,
  string $valueField,
  string $label,
  array &$fieldErrors,
  array &$errors
): array {
  $type = norm_discount_type($typeRaw, true);
  $valueRaw = trim($valueRaw);
  $value = null;

  if ($typeRaw !== '' && $type === null) {
    $fieldErrors[$typeField] = $label . " discount type is invalid";
    $errors[] = $fieldErrors[$typeField];
  }

  if ($valueRaw !== '') {
    if (!is_numeric($valueRaw)) {
      $fieldErrors[$valueField] = $label . " discount value must be a number";
      $errors[] = $fieldErrors[$valueField];
    } else {
      $value = (float)$valueRaw;
      if ($value < 0) {
        $fieldErrors[$valueField] = $label . " discount value cannot be negative";
        $errors[] = $fieldErrors[$valueField];
      } elseif ($type === null) {
        $fieldErrors[$typeField] = $label . " discount type is required when value is provided";
        $errors[] = $fieldErrors[$typeField];
      } elseif ($type === 'percent' && $value > 100) {
        $fieldErrors[$valueField] = $label . " percentage cannot exceed 100%";
        $errors[] = $fieldErrors[$valueField];
      }
    }
  }

  if ($valueRaw === '') {
    $type = null;
  }

  return [$type, $value];
}

function dt_now_sql(): string
{
  return date("Y-m-d H:i:s");
}

function parse_email_list(string $raw): array
{
  $raw = trim($raw);
  if ($raw === '') return [];
  $parts = preg_split('/[,\s;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $out = [];
  foreach ($parts as $p) {
    $e = norm_email((string)$p);
    if ($e !== '') $out[] = $e;
  }
  $out = array_values(array_unique($out));
  return $out;
}
function join_email_list(array $emails): string
{
  $emails = array_values(array_unique(array_filter(array_map('norm_email', $emails))));
  return implode(',', $emails);
}

/**
 * AJAX: categories loader (no page reload)
 */
if (($_GET['ajax'] ?? '') === 'categories') {
  header('Content-Type: application/json; charset=utf-8');
  $pid = trim((string)($_GET['product_id'] ?? ''));
  if (!$pid || $pid === 'all') {
    echo json_encode(['ok' => true, 'categories' => []]);
    exit;
  }

  $cats = [];
  $hasCatIsSubscriptionColAjax = columnExists($conn, 'Product_Categories', 'is_subscription');
  $sqlAjaxCats = "SELECT id, name"
    . ($hasCatIsSubscriptionColAjax ? ", is_subscription" : ", NULL AS is_subscription")
    . " FROM Product_Categories WHERE product_id = ? ORDER BY sort_order ASC, id ASC";
  $stmt = $conn->prepare($sqlAjaxCats);
  if ($stmt) {
    $stmt->bind_param("s", $pid);
    $stmt->execute();
    $cats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
  foreach ($cats as &$catRow) {
    if (!array_key_exists('is_subscription', $catRow) || $catRow['is_subscription'] === null || $catRow['is_subscription'] === '') {
      $catRow['is_subscription'] = null;
    } else {
      $catRow['is_subscription'] = ((int)$catRow['is_subscription'] === 1) ? '1' : '0';
    }
  }
  unset($catRow);
  echo json_encode(['ok' => true, 'categories' => $cats]);
  exit;
}

/**
 * Setup
 */
$hasCodes       = tableExists($conn, "Discount_Codes");
$hasRedemptions = tableExists($conn, "Discount_Redemptions");
$hasProdIsSubscriptionCol = $conn ? columnExists($conn, "Products", "is_subscription") : false;
$hasCatIsSubscriptionCol  = $conn ? columnExists($conn, "Product_Categories", "is_subscription") : false;

if ($hasCodes) {
  // Renewal discount is deprecated. Installment flow now replaces renewal discount logic.
  // Keep only retention lifecycle discount.
  addColumnIfMissing($conn, "Discount_Codes", "retention_discount_type", "VARCHAR(20) NULL AFTER `discount_value`");
  addColumnIfMissing($conn, "Discount_Codes", "retention_discount_value", "DECIMAL(10,2) NULL AFTER `retention_discount_type`");
  addColumnIfMissing($conn, "Discount_Codes", "auto_apply_retention", "TINYINT(1) NOT NULL DEFAULT 0 AFTER `retention_discount_value`");
}

// Legacy columns may still exist. Keep flags so old values can be cleared safely on save.
$hasRenewalTypeCol      = $hasCodes && columnExists($conn, "Discount_Codes", "renewal_discount_type");
$hasRenewalValueCol     = $hasCodes && columnExists($conn, "Discount_Codes", "renewal_discount_value");
$hasAutoApplyRenewalCol = $hasCodes && columnExists($conn, "Discount_Codes", "auto_apply_renewal");

$hasRetentionTypeCol    = $hasCodes && columnExists($conn, "Discount_Codes", "retention_discount_type");
$hasRetentionValueCol   = $hasCodes && columnExists($conn, "Discount_Codes", "retention_discount_value");
$hasAutoApplyRetCol     = $hasCodes && columnExists($conn, "Discount_Codes", "auto_apply_retention");

$editId       = trim((string)($_GET["id"] ?? ""));
$flashSaved   = ((string)($_GET["saved"] ?? "") === "1");
$flashDeleted = ((string)($_GET["deleted"] ?? "") === "1");

$errors = [];
$fieldErrors = [
  'code'  => '',
  'value' => '',
  'renewal_type' => '',
  'renewal_value' => '',
  'retention_type' => '',
  'retention_value' => '',
];

// Products for dropdowns
$products = [];
$productHasCats = [];
$productIsSubscriptionMap = [];
$courseMap = [];
$hasHasCatsCol = $conn ? columnExists($conn, 'Products', 'has_categories') : false;

if ($conn) {
  // 1) Main products
  $sqlP = "SELECT p.id, p.name, p.status"
    . ($hasHasCatsCol ? ", p.has_categories" : "")
    . ($hasProdIsSubscriptionCol ? ", p.is_subscription" : ", 0 AS p.is_subscription")
    . ($hasCatIsSubscriptionCol ? ", (SELECT 1 FROM Product_Categories pc WHERE pc.product_id = p.id AND pc.is_subscription = 1 LIMIT 1) AS has_sub_category" : ", 0 AS has_sub_category")
    . " FROM Products p ORDER BY p.name ASC";
  $resP = $conn->query($sqlP);
  if ($resP) {
    $mainProducts = $resP->fetch_all(MYSQLI_ASSOC);

    foreach ($mainProducts as $p) {
      $pid = (string)($p['id'] ?? '');
      if ($pid === '') continue;

      $isSub = ((int)($p['is_subscription'] ?? 0) === 1) || ((int)($p['has_sub_category'] ?? 0) === 1);

      $products[] = [
        'id' => $pid,
        'name' => (string)($p['name'] ?? ''),
        'status' => (string)($p['status'] ?? 'active'),
        'source' => 'product',
        'has_categories' => $hasHasCatsCol ? (!empty($p['has_categories'])) : true,
        'is_subscription' => $isSub,
        'is_pure_subscription' => ((int)($p['is_subscription'] ?? 0) === 1),
      ];

      $productHasCats[$pid] = $hasHasCatsCol ? (!empty($p['has_categories'])) : true;
      $productIsSubscriptionMap[$pid] = $isSub;
    }
  }

  // 2) e-Learning courses
  if ($elConn instanceof mysqli && tableExists($elConn, 'courses')) {
    $resC = $elConn->query("SELECT id, title, level FROM courses ORDER BY title ASC");
    if ($resC) {
      $courses = $resC->fetch_all(MYSQLI_ASSOC);

      foreach ($courses as $c) {
        $cid = (string)($c['id'] ?? '');
        if ($cid === '') continue;

        $level = trim((string)($c['level'] ?? ''));
        $courseName = '[e-Learning] ' . (string)($c['title'] ?? $cid);

        if ($level !== '') {
          $courseName .= ' (' . $level . ')';
        }

        $products[] = [
          'id' => $cid,
          'name' => $courseName,
          'status' => 'active',
          'source' => 'course',
          'has_categories' => false,
          'is_subscription' => false,
        ];

        $productHasCats[$cid] = false;
        $productIsSubscriptionMap[$cid] = false;
        $courseMap[$cid] = [
          'id' => $cid,
          'name' => $courseName,
          'status' => 'active',
        ];
      }
    } else {
      error_log('discount-form: e-Learning courses query failed = ' . $elConn->error);
    }
  } else {
    error_log('discount-form: e-Learning DB missing or courses table not found');
  }

  usort($products, static function (array $a, array $b): int {
    return strcasecmp((string)$a['name'], (string)$b['name']);
  });
}

// Defaults: match TSX semantics ('all' means global)
$form = [
  "id" => "",
  "product_id" => "all",
  "category_id" => "all",
  "code" => "",
  "discount_type" => "percent",
  "discount_value" => "",
  "renewal_discount_type" => "",
  "renewal_discount_value" => "",
  "retention_discount_type" => "",
  "retention_discount_value" => "",
  "allowed_email" => "",
  "max_redemptions" => "",
  "per_email_limit" => "1",
  "status" => "active",
  "valid_from" => "",
  "valid_until" => "",
];

// Load edit row
if ($hasCodes && $editId !== "") {
  $editLifecycleCols = [
    $hasRenewalTypeCol ? "renewal_discount_type" : "NULL AS renewal_discount_type",
    $hasRenewalValueCol ? "renewal_discount_value" : "NULL AS renewal_discount_value",
    $hasRetentionTypeCol ? "retention_discount_type" : "NULL AS retention_discount_type",
    $hasRetentionValueCol ? "retention_discount_value" : "NULL AS retention_discount_value",
    $hasAutoApplyRenewalCol ? "auto_apply_renewal" : "0 AS auto_apply_renewal",
    $hasAutoApplyRetCol ? "auto_apply_retention" : "0 AS auto_apply_retention",
  ];
  $stmt = $conn->prepare("
    SELECT id, code, discount_type, discount_value,
           " . implode(",\n           ", $editLifecycleCols) . ",
           product_id, category_id, allowed_email,
           valid_from, valid_until,
           max_redemptions, per_email_limit, status
    FROM Discount_Codes
    WHERE id = ?
    LIMIT 1
  ");
  if ($stmt) {
    $eid = (int)$editId;
    $stmt->bind_param("i", $eid);
    $stmt->execute();
    $dc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dc) {
      $form["id"]            = (string)$dc["id"];
      $form["product_id"]    = (string)($dc["product_id"] ?? "");
      $form["category_id"]   = (string)($dc["category_id"] ?? "");
      $form["code"]          = (string)($dc["code"] ?? "");
      $form["discount_type"] = ((string)($dc["discount_type"] ?? "percent") === "fixed") ? "fixed" : "percent";
      $form["discount_value"] = (string)($dc["discount_value"] ?? "0");
      $form["renewal_discount_type"] = (string)(norm_discount_type((string)($dc["renewal_discount_type"] ?? ""), true) ?? "");
      $form["renewal_discount_value"] = ($dc["renewal_discount_value"] === null || $dc["renewal_discount_value"] === "") ? "" : (string)$dc["renewal_discount_value"];
      $form["retention_discount_type"] = (string)(norm_discount_type((string)($dc["retention_discount_type"] ?? ""), true) ?? "");
      $form["retention_discount_value"] = ($dc["retention_discount_value"] === null || $dc["retention_discount_value"] === "") ? "" : (string)$dc["retention_discount_value"];
      $form["allowed_email"] = (string)($dc["allowed_email"] ?? "");
      $form["status"]        = ((string)($dc["status"] ?? "active") === "inactive") ? "inactive" : "active";
      $form["valid_from"]    = (string)($dc["valid_from"] ?? "");
      $form["valid_until"]   = (string)($dc["valid_until"] ?? "");

      $mr = $dc["max_redemptions"] ?? null;
      $form["max_redemptions"] = ($mr === null || $mr === "") ? "" : (string)$mr;

      $form["per_email_limit"] = (string)($dc["per_email_limit"] ?? "1");

      // derive timer_minutes from valid_from + valid_until (to mimic TSX "timerMinutes")
      if ($form["valid_from"] !== "" && $form["valid_until"] !== "") {
        $ts1 = strtotime($form["valid_from"]);
        $ts2 = strtotime($form["valid_until"]);
        if ($ts1 !== false && $ts2 !== false && $ts2 > $ts1) {
          $form["timer_minutes"] = (string)max(1, (int)round(($ts2 - $ts1) / 60));
        }
      }

      // normalize UI "all"
      if ($form["product_id"] === "") $form["product_id"] = "all";
      if ($form["category_id"] === "") $form["category_id"] = "all";
    } else {
      $errors[] = "Discount code not found.";
    }
  }
}

// Categories initial (form)
$categories = [];
$categorySubscriptionMap = [];
if (
  $form["product_id"] !== "" &&
  $form["product_id"] !== "all" &&
  !isset($courseMap[$form["product_id"]])
) {
  $sqlCats = "SELECT id, name"
    . ($hasCatIsSubscriptionCol ? ", is_subscription" : ", NULL AS is_subscription")
    . " FROM Product_Categories WHERE product_id = ? ORDER BY sort_order ASC, id ASC";
  $stmt = $conn->prepare($sqlCats);
  if ($stmt) {
    $stmt->bind_param("s", $form["product_id"]);
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
  foreach ($categories as $cc) {
    $cid = (string)($cc['id'] ?? '');
    if ($cid === '') continue;
    $categorySubscriptionMap[$cid] = ($cc['is_subscription'] === null || $cc['is_subscription'] === '')
      ? null
      : (((int)$cc['is_subscription'] === 1) ? '1' : '0');
  }
}

$formEffectiveSubscription = effective_subscription_flag(
  $conn,
  ($form["product_id"] === "all") ? "" : (string)$form["product_id"],
  ($form["category_id"] === "all") ? "" : (string)$form["category_id"],
  $hasProdIsSubscriptionCol,
  $hasCatIsSubscriptionCol,
  $courseMap
);

/**
 * POST handler
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (function_exists("csrf_validate")) csrf_validate();

  $action = (string)($_POST["action"] ?? "save");

  if (!$hasCodes) {
    $errors[] = "Discount_Codes table not found. Please create the table first.";
  } else {
    if ($action === "delete") {
      $did = (int)($_POST["id"] ?? 0);
      if ($did <= 0) {
        $errors[] = "Invalid delete request.";
      } else {
        $stmt = $conn->prepare("DELETE FROM Discount_Codes WHERE id = ? LIMIT 1");
        if (!$stmt) {
          $errors[] = "Delete failed: " . $conn->error;
        } else {
          $stmt->bind_param("i", $did);
          $stmt->execute();
          $stmt->close();

          header("Location: /admin/payment/discount-form.php?deleted=1", true, 303);
          exit;
        }
      }
    } else {
      // save
      $id         = trim((string)($_POST["id"] ?? ""));
      $productUi  = trim((string)($_POST["product_id"] ?? "all"));
      $variantUi  = trim((string)($_POST["category_id"] ?? "all"));

      $productId  = ($productUi === "all") ? "" : $productUi;
      $categoryId = ($variantUi === "all") ? "" : $variantUi;

      $code       = norm_code((string)($_POST["code"] ?? ""));
      $dtype      = ((string)($_POST["discount_type"] ?? "percent") === "fixed") ? "fixed" : "percent";
      $dvalueRaw  = trim((string)($_POST["discount_value"] ?? "0"));
      // Renewal discount is deprecated; force legacy renewal fields to clear on save.
      $renewalTypeRaw = "";
      $renewalValueRaw = "";

      $retentionTypeRaw = trim((string)($_POST["retention_discount_type"] ?? ""));
      $retentionValueRaw = trim((string)($_POST["retention_discount_value"] ?? ""));

      $validUntilLocalRaw = trim((string)($_POST["valid_until_local"] ?? ""));
      $emailsRaw   = trim((string)($_POST["allowed_email"] ?? ""));
      $maxRedRaw   = trim((string)($_POST["max_redemptions"] ?? ""));
      $perEmailRaw = trim((string)($_POST["per_email_limit"] ?? "1"));
      $status      = ((string)($_POST["status"] ?? "active") === "inactive") ? "inactive" : "active";

      // validation (match TSX rules)
      if ($code === "") {
        $fieldErrors['code'] = "Discount code is required";
        $errors[] = $fieldErrors['code'];
      } else if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
        $fieldErrors['code'] = "Only A-Z, 0-9, underscore and hyphens allowed";
        $errors[] = $fieldErrors['code'];
      }

      $dvalue = (float)$dvalueRaw;
      if ($dvalue < 0) {
        $fieldErrors['value'] = "Invalid discount value";
        $errors[] = $fieldErrors['value'];
      }
      if ($dtype === "percent" && $dvalue > 100) {
        $fieldErrors['value'] = "Percentage cannot exceed 100%";
        $errors[] = $fieldErrors['value'];
      }

      $isSubscriptionTarget = effective_subscription_flag(
        $conn,
        $productId,
        $categoryId,
        $hasProdIsSubscriptionCol,
        $hasCatIsSubscriptionCol,
        $courseMap
      );

      // Renewal discount is removed because installment flow replaces it.
      $renewalType = null;
      $renewalValue = null;
      $autoApplyRenewal = 0;

      [$retentionType, $retentionValue] = validate_lifecycle_discount(
        $retentionTypeRaw,
        $retentionValueRaw,
        'retention_type',
        'retention_value',
        'Retention',
        $fieldErrors,
        $errors
      );

      if (!$isSubscriptionTarget) {
        $retentionType = null;
        $retentionValue = null;
      }

      $autoApplyRetention = ($isSubscriptionTarget && $retentionType !== null && $retentionValue !== null) ? 1 : 0;

      // expiry -> valid_from & valid_until
      $validFrom  = null;
      $validUntil = null;

      if ($validUntilLocalRaw !== "") {
        // datetime-local: 2026-02-23T22:30
        $ts = strtotime(str_replace("T", " ", $validUntilLocalRaw));

        if ($ts === false) {
          $errors[] = "Invalid expiry date/time.";
        } else {
          $validFrom  = dt_now_sql();              // mula sekarang
          $validUntil = date("Y-m-d H:i:s", $ts);  // simpan SQL datetime
        }
      }

      // strict-mode friendly (elak '' jadi DATETIME error)
      if ($validFrom === "")  $validFrom = null;
      if ($validUntil === "") $validUntil = null;

      // allowed emails (multi)
      $allowedEmail = null;
      $emailsList = parse_email_list($emailsRaw);
      if (!empty($emailsList)) {
        $bad = [];
        foreach ($emailsList as $em) if (!is_valid_email($em)) $bad[] = $em;
        if ($bad) $errors[] = "Some emails are invalid: " . implode(", ", $bad);
        $allowedEmail = join_email_list($emailsList);
      }

      // max redemptions: TSX uses "Unlimited" string
      $maxRedemptions = null;
      if ($maxRedRaw !== "" && strtolower($maxRedRaw) !== "unlimited") {
        if (!ctype_digit($maxRedRaw) || (int)$maxRedRaw <= 0) {
          $errors[] = "Max redemptions must be a positive integer or 'Unlimited'.";
        } else {
          $maxRedemptions = (int)$maxRedRaw;
        }
      }

      // per email limit
      $perEmailLimit = (int)($perEmailRaw === "" ? 1 : $perEmailRaw);
      if ($perEmailLimit <= 0) $errors[] = "Per-email limit must be at least 1.";

      // if product empty, category must be empty
      if ($productId === "") $categoryId = "";

      if (!$errors) {
        try {
          if ($id !== "") {
            $did = (int)$id;
            $updateFields = [
              "code = ?",
              "discount_type = ?",
              "discount_value = ?",
              "product_id = ?",
              "category_id = ?",
              "allowed_email = ?",
              "valid_from = ?",
              "valid_until = ?",
              "max_redemptions = ?",
              "per_email_limit = ?",
              "status = ?",
            ];
            $updateTypes = "ssdsssssiis";
            $updateParams = [
              $code,
              $dtype,
              $dvalue,
              $productId,
              $categoryId,
              $allowedEmail,
              $validFrom,
              $validUntil,
              $maxRedemptions,
              $perEmailLimit,
              $status,
            ];

            if ($hasRenewalTypeCol) {
              $updateFields[] = "renewal_discount_type = ?";
              $updateTypes .= "s";
              $updateParams[] = $renewalType;
            }
            if ($hasRenewalValueCol) {
              $updateFields[] = "renewal_discount_value = ?";
              $updateTypes .= "s";
              $updateParams[] = decimal_string_or_null($renewalValue);
            }
            if ($hasRetentionTypeCol) {
              $updateFields[] = "retention_discount_type = ?";
              $updateTypes .= "s";
              $updateParams[] = $retentionType;
            }
            if ($hasRetentionValueCol) {
              $updateFields[] = "retention_discount_value = ?";
              $updateTypes .= "s";
              $updateParams[] = decimal_string_or_null($retentionValue);
            }
            if ($hasAutoApplyRenewalCol) {
              $updateFields[] = "auto_apply_renewal = ?";
              $updateTypes .= "i";
              $updateParams[] = $autoApplyRenewal;
            }
            if ($hasAutoApplyRetCol) {
              $updateFields[] = "auto_apply_retention = ?";
              $updateTypes .= "i";
              $updateParams[] = $autoApplyRetention;
            }

            $stmt = $conn->prepare("
              UPDATE Discount_Codes
              SET " . implode(",\n                  ", $updateFields) . "
              WHERE id = ?
              LIMIT 1
            ");
            if (!$stmt) throw new RuntimeException("Prepare UPDATE failed: " . $conn->error);
            $updateTypes .= "i";
            $updateParams[] = $did;
            stmt_bind_all($stmt, $updateTypes, $updateParams);
            $stmt->execute();
            $stmt->close();

            header("Location: /admin/payment/discount-form.php?saved=1", true, 303);
            exit;
          } else {
            $insertCols = [
              "code",
              "discount_type",
              "discount_value",
              "product_id",
              "category_id",
              "allowed_email",
              "valid_from",
              "valid_until",
              "max_redemptions",
              "per_email_limit",
              "status",
            ];
            $insertQs = array_fill(0, count($insertCols), "?");
            $insertTypes = "ssdsssssiis";
            $insertParams = [
              $code,
              $dtype,
              $dvalue,
              $productId,
              $categoryId,
              $allowedEmail,
              $validFrom,
              $validUntil,
              $maxRedemptions,
              $perEmailLimit,
              $status,
            ];

            if ($hasRenewalTypeCol) {
              $insertCols[] = "renewal_discount_type";
              $insertQs[] = "?";
              $insertTypes .= "s";
              $insertParams[] = $renewalType;
            }
            if ($hasRenewalValueCol) {
              $insertCols[] = "renewal_discount_value";
              $insertQs[] = "?";
              $insertTypes .= "s";
              $insertParams[] = decimal_string_or_null($renewalValue);
            }
            if ($hasRetentionTypeCol) {
              $insertCols[] = "retention_discount_type";
              $insertQs[] = "?";
              $insertTypes .= "s";
              $insertParams[] = $retentionType;
            }
            if ($hasRetentionValueCol) {
              $insertCols[] = "retention_discount_value";
              $insertQs[] = "?";
              $insertTypes .= "s";
              $insertParams[] = decimal_string_or_null($retentionValue);
            }
            if ($hasAutoApplyRenewalCol) {
              $insertCols[] = "auto_apply_renewal";
              $insertQs[] = "?";
              $insertTypes .= "i";
              $insertParams[] = $autoApplyRenewal;
            }
            if ($hasAutoApplyRetCol) {
              $insertCols[] = "auto_apply_retention";
              $insertQs[] = "?";
              $insertTypes .= "i";
              $insertParams[] = $autoApplyRetention;
            }

            $stmt = $conn->prepare("
              INSERT INTO Discount_Codes
                (" . implode(", ", $insertCols) . ")
              VALUES
                (" . implode(",", $insertQs) . ")
            ");
            if (!$stmt) throw new RuntimeException("Prepare INSERT failed: " . $conn->error);
            stmt_bind_all($stmt, $insertTypes, $insertParams);
            $stmt->execute();
            $newId = (string)$conn->insert_id;
            $stmt->close();

            header("Location: /admin/payment/discount-form.php?id=" . urlencode($newId) . "&saved=1", true, 303);
            exit;
          }
        } catch (Throwable $e) {
          error_log("discount-form save failed: " . $e->getMessage());
          $errors[] = "Save failed. Please check error_log.";
        }
      }

      // repopulate on error (keep UI values)
      $form["id"] = $id;
      $form["product_id"] = ($productId === "") ? "all" : $productId;
      $form["category_id"] = ($categoryId === "") ? "all" : $categoryId;
      $form["code"] = $code;
      $form["discount_type"] = $dtype;
      $form["discount_value"] = (string)$dvalueRaw;
      $form["renewal_discount_type"] = $renewalTypeRaw;
      $form["renewal_discount_value"] = $renewalValueRaw;
      $form["retention_discount_type"] = $retentionTypeRaw;
      $form["retention_discount_value"] = $retentionValueRaw;
      $form["allowed_email"] = $emailsRaw;
      $form["valid_from"]  = (string)($validFrom ?? "");
      $form["valid_until"] = (string)($validUntil ?? "");
      $form["max_redemptions"] = $maxRedRaw;
      $form["per_email_limit"] = $perEmailRaw;
      $form["status"] = $status;
    }
  }
}

/**
 * Load list (match ExistingCodesList.tsx)
 */
$rows = [];
if ($hasCodes) {
  $orderBy = "dc.id DESC";
  if (columnExists($conn, "Discount_Codes", "created_at")) $orderBy = "dc.created_at DESC, dc.id DESC";

  $sql = "
    SELECT
      dc.*,
      p.name AS product_name,
      " . ($hasProdIsSubscriptionCol ? "p.is_subscription AS product_is_subscription," : "0 AS product_is_subscription,") . "
      " . ($hasCatIsSubscriptionCol ? "pc.is_subscription AS category_is_subscription," : "NULL AS category_is_subscription,") . "
      pc.name AS category_name,
      COALESCE(r.paid_count, 0) AS redeemed_paid
    FROM Discount_Codes dc
    LEFT JOIN Products p ON p.id = dc.product_id
    LEFT JOIN Product_Categories pc ON pc.id = dc.category_id
    LEFT JOIN (
      " . ($hasRedemptions ? "
      SELECT discount_code_id, COUNT(*) AS paid_count
      FROM Discount_Redemptions
      WHERE status = 'paid'
      GROUP BY discount_code_id
      " : "
      SELECT NULL AS discount_code_id, 0 AS paid_count
      ") . "
    ) r ON r.discount_code_id = dc.id
    ORDER BY {$orderBy}
    LIMIT 500
  ";

  $res = $conn->query($sql);
  if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);

  foreach ($rows as &$r) {
    $pid = (string)($r['product_id'] ?? '');
    if ($pid !== '' && empty($r['product_name']) && isset($courseMap[$pid])) {
      $r['product_name'] = $courseMap[$pid]['name'];
    }
  }
  unset($r);
}

/**
 * Page chrome (use your existing admin header; no duplicate header markup in page)
 */
$pageTitle = "Discount Codes";
$pageDesc  = "Create, edit, and manage your promotional campaigns.";
$backUrl   = "/admin/payment/admin-products.php";
$backLabel = "Back to Products";

// If your header.php supports desktop header actions, it will pick this up (same pattern you used before).
$headerActionsHtmlDesktop = '
<a href="' . h($backUrl) . '"
   class="flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
  <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
  </svg>
  ' . $backLabel . '
</a>
';

$title = $pageTitle;

// keep your existing layout partials
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";

$csrfToken = function_exists("csrf_token") ? csrf_token() : "";
?>

<style>
  /* Match "custom-scrollbar" behaviour in TSX */
  .custom-scrollbar::-webkit-scrollbar {
    width: 10px;
    height: 10px;
  }

  .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 9999px;
    border: 2px solid #f8fafc;
  }

  .custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
  }
</style>

<!-- Mobile Back button (requested) -->
<div class="md:hidden mb-6">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= h($pageTitle) ?></h1>
      <p class="mt-2 text-sm font-semibold text-slate-500"><?= h($pageDesc) ?></p>
    </div>

    <a href="<?= h($backUrl) ?>"
      class="inline-flex items-center gap-2 px-5 py-3
            text-base font-extrabold text-slate-700 hover:text-slate-900
            transition-all group whitespace-nowrap">
      <svg class="w-6 h-6 transition-transform group-hover:-translate-x-1"
        fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
      Back
    </a>
  </div>
</div>

<?php if (!$hasCodes): ?>
  <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
    <div class="font-semibold">Missing table: Discount_Codes</div>
    <div class="mt-1 text-red-700">Create the table first, then reload this page.</div>
  </div>
<?php endif; ?>

<?php if ($flashSaved): ?>
  <div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
    <div class="font-semibold">Saved successfully.</div>
  </div>
<?php endif; ?>

<?php if ($flashDeleted): ?>
  <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
    <div class="font-semibold">Deleted successfully.</div>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
    <div class="font-semibold mb-2">Please fix the following:</div>
    <ul class="list-disc pl-5 space-y-1 text-red-700">
      <?php foreach ($errors as $e): ?>
        <li><?= h((string)$e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

  <!-- LEFT: CreateDiscountForm.tsx (match 1:1) -->
  <?php $isEditing = ($form["id"] !== ""); ?>
  <div class="bg-white rounded-xl border border-slate-200 shadow-sm h-full lg:col-span-4 border-t-4 <?= $isEditing ? "border-t-yellow-500" : "border-t-transparent" ?>">
    <div class="p-6 border-b border-slate-100 flex justify-between items-start">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">
          <?= $isEditing ? "Edit Discount Code" : "Create Discount Code" ?>
        </h2>
        <p class="text-sm text-slate-500 mt-1">
          <?= $isEditing ? "Update the details for this promotion." : "Configure rules and limits for your new promotion." ?>
        </p>
      </div>

      <?php if ($isEditing): ?>
        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-50 text-yellow-800">
          Editing Mode
        </span>
      <?php endif; ?>
    </div>

    <div class="p-6">
      <form method="POST" class="space-y-5" id="discountForm">
        <?php if (function_exists("csrf_token")): ?>
          <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <?php endif; ?>

        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($form["id"]) ?>">

        <?php
        // product label
        $selectedProductLabel = "— All Products —";
        $selectedProductHasCats = "1";
        $selectedProductIsSubscription = "0";

        if ($form["product_id"] !== "all" && $form["product_id"] !== "") {
          foreach ($products as $pp) {
            if ((string)$pp["id"] === (string)$form["product_id"]) {
              $selectedProductLabel = (!empty($pp["is_subscription"]) ? "[subscription] " : "")
                . (string)$pp["name"]
                . (((string)($pp["status"] ?? "") === "inactive") ? " (inactive)" : "");
              $selectedProductHasCats = (!empty($productHasCats[(string)$pp["id"]])) ? "1" : "0";
              $selectedProductIsSubscription = !empty($pp["is_subscription"]) ? "1" : "0";
              break;
            }
          }
        }

        // category label
        $selectedCategoryLabel = "— All variants —";
        $selectedCategoryIsSubscription = "";
        if ($form["category_id"] !== "all" && $form["category_id"] !== "") {
          foreach ($categories as $cc) {
            if ((string)$cc["id"] === (string)$form["category_id"]) {
              $selectedCategoryLabel = (string)$cc["name"];
              $selectedCategoryIsSubscription = (string)($categorySubscriptionMap[(string)$cc["id"]] ?? "");
              break;
            }
          }
        }
        ?>

        <!-- Product & Variant -->
        <div class="space-y-4">
          <!-- Product (Select.tsx style) -->
          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Product (optional)</label>

            <div class="relative">
              <input type="hidden" name="product_id" id="dcProductInput" value="<?= h((string)$form["product_id"]) ?>">

              <button type="button" id="dcProductBtn"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none flex items-center justify-between gap-3">
                <span id="dcProductLabel" class="truncate" data-has-cats="<?= h($selectedProductHasCats) ?>" data-is-subscription="<?= h($selectedProductIsSubscription) ?>">
                  <?= h($selectedProductLabel) ?>
                </span>
                <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div id="dcProductPanel"
                class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
                <div class="p-3 border-b border-slate-100">
                  <input id="dcProductSearch" type="text"
                    placeholder="Search product or e-Learning course..."
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none focus:ring-2 focus:ring-yellow-400">
                </div>

                <div id="dcProductList" class="max-h-64 overflow-y-auto p-2">
                  <button type="button"
                    class="dcProdItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="all"
                    data-label="— All Products —"
                    data-has-cats="1"
                    data-is-subscription="0">
                    <span class="block truncate">— All Products —</span>
                  </button>

                  <?php foreach ($products as $p): ?>
                    <?php
                    $pid = (string)($p["id"] ?? "");
                    if ($pid === "") continue;
                    $pname = (string)($p["name"] ?? "");
                    $inactive = ((string)($p["status"] ?? "") === "inactive");
                    $hasCats = !empty($productHasCats[$pid]);
                    $isSubscription = !empty($p["is_subscription"]);
                    $lbl = ($isSubscription ? "[subscription] " : "") . $pname . ($inactive ? " (inactive)" : "");
                    ?>
                    <button type="button"
                      class="dcProdItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                      data-value="<?= h($pid) ?>"
                      data-label="<?= h($lbl) ?>"
                      data-has-cats="<?= $hasCats ? "1" : "0" ?>"
                      data-is-subscription="<?= $isSubscription ? "1" : "0" ?>">
                      <span class="block truncate"><?= h($lbl) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <p class="mt-1 text-xs text-slate-500">
              Selected: <span id="dcProductSelected"><?= h($selectedProductLabel) ?></span>
            </p>
          </div>

          <!-- Variant/Category (contents.php style dropdown) -->
          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Variant/Category (optional)</label>

            <div class="relative">
              <input type="hidden" name="category_id" id="dcCategoryInput" value="<?= h((string)$form["category_id"]) ?>">

              <button type="button" id="dcCategoryBtn"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none flex items-center justify-between gap-3">
                <span id="dcCategoryLabel" class="truncate" data-is-subscription="<?= h($selectedCategoryIsSubscription) ?>"><?= h($selectedCategoryLabel) ?></span>
                <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div id="dcCategoryPanel"
                class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
                <div id="dcCategoryList" class="max-h-64 overflow-y-auto p-2">
                  <button type="button"
                    class="dcCatItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="all"
                    data-label="— All variants —"
                    data-is-subscription="">
                    <span class="block truncate">— All variants —</span>
                  </button>

                  <?php foreach ($categories as $c): ?>
                    <?php
                    $cid = (string)($c["id"] ?? "");
                    $cname = (string)($c["name"] ?? "");
                    if ($cid === "") continue;
                    $catIsSubscription = (string)($categorySubscriptionMap[$cid] ?? "");
                    ?>
                    <button type="button"
                      class="dcCatItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                      data-value="<?= h($cid) ?>"
                      data-label="<?= h($cname) ?>"
                      data-is-subscription="<?= h($catIsSubscription) ?>">
                      <span class="block truncate"><?= h($cname) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <p id="dcCatHelp" class="mt-1 text-xs text-slate-500">Leave default to apply to all variants.</p>
          </div>
        </div>

        <hr class="border-slate-100" />

        <!-- Code (Input.tsx style) -->
        <div class="w-full">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Code</label>
          <input
            id="dcCodeInput"
            type="text"
            name="code"
            value="<?= h($form["code"]) ?>"
            placeholder="e.g. SUMMER_SALE_2024"
            maxlength="64"
            required
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none font-mono uppercase" />
          <?php if ($fieldErrors['code']): ?>
            <p class="mt-1 text-xs text-red-600"><?= h($fieldErrors['code']) ?></p>
          <?php else: ?>
            <p class="mt-1 text-xs text-slate-500">Allowed: A-Z, 0-9, underscore, hyphen. No spaces.</p>
          <?php endif; ?>
        </div>

        <!-- Discount Type & Value -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount Type</label>
            <?php $dtypeLbl = ($form["discount_type"] === "fixed") ? "Fixed (RM)" : "Percent (%)"; ?>
            <input type="hidden" name="discount_type" id="dcDiscountTypeInput" value="<?= h($form["discount_type"]) ?>">

            <div class="relative">
              <button type="button" id="dcDiscountTypeBtn"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none flex items-center justify-between gap-3">
                <span id="dcDiscountTypeLabel" class="truncate"><?= h($dtypeLbl) ?></span>
                <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div id="dcDiscountTypePanel"
                class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
                <div class="p-2">
                  <button type="button"
                    class="dcDTypeItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="percent" data-label="Percent (%)">
                    <span class="block truncate">Percent (%)</span>
                  </button>
                  <button type="button"
                    class="dcDTypeItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="fixed" data-label="Fixed (RM)">
                    <span class="block truncate">Fixed (RM)</span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount Value</label>
            <input
              id="dcValueInput"
              type="number"
              step="0.01"
              min="0"
              name="discount_value"
              value="<?= h((string)$form["discount_value"]) ?>"
              placeholder="e.g. 20"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none" />
            <?php if ($fieldErrors['value']): ?>
              <p class="mt-1 text-xs text-red-600"><?= h($fieldErrors['value']) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <?php
        $retentionTypeLbl = ($form["retention_discount_type"] === "fixed") ? "Fixed (RM)" : "Percent (%)";
        ?>
        <div id="dcLifecycleSection" class="<?= $formEffectiveSubscription ? "" : "hidden " ?>rounded-2xl border border-yellow-200 bg-yellow-50/40 p-4 space-y-4">
          <div>
            <h3 class="text-sm font-semibold text-slate-900">Retention Discount</h3>
            <p class="mt-1 text-xs text-slate-600">
              Retention discount is only used for continuation offers.
            </p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="w-full">
              <label class="block text-sm font-medium text-slate-700 mb-1.5">Retention Discount Type</label>
              <input type="hidden" name="retention_discount_type" id="dcRetentionTypeInput" value="<?= h((string)$form["retention_discount_type"]) ?>">

              <div class="relative">
                <button type="button" id="dcRetentionTypeBtn"
                  class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none flex items-center justify-between gap-3">
                  <span id="dcRetentionTypeLabel" class="truncate"><?= h($form["retention_discount_type"] === "" ? "Select type" : $retentionTypeLbl) ?></span>
                  <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>

                <div id="dcRetentionTypePanel"
                  class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-40">
                  <div class="p-2">
                    <button type="button"
                      class="dcRetentionTypeItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                      data-value="" data-label="Select type">
                      <span class="block truncate">Select type</span>
                    </button>
                    <button type="button"
                      class="dcRetentionTypeItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                      data-value="percent" data-label="Percent (%)">
                      <span class="block truncate">Percent (%)</span>
                    </button>
                    <button type="button"
                      class="dcRetentionTypeItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                      data-value="fixed" data-label="Fixed (RM)">
                      <span class="block truncate">Fixed (RM)</span>
                    </button>
                  </div>
                </div>
              </div>

              <?php if ($fieldErrors['retention_type']): ?>
                <p class="mt-1 text-xs text-red-600"><?= h($fieldErrors['retention_type']) ?></p>
              <?php endif; ?>
            </div>

            <div class="w-full">
              <label class="block text-sm font-medium text-slate-700 mb-1.5">Retention Discount Value</label>
              <input
                type="number"
                step="0.01"
                min="0"
                name="retention_discount_value"
                value="<?= h((string)$form["retention_discount_value"]) ?>"
                placeholder="e.g. 15"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none" />
              <?php if ($fieldErrors['retention_value']): ?>
                <p class="mt-1 text-xs text-red-600"><?= h($fieldErrors['retention_value']) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php
        // untuk edit mode: convert SQL datetime -> datetime-local value
        $validUntilLocal = "";
        if (!empty($form["valid_until"])) {
          $ts = strtotime((string)$form["valid_until"]);
          if ($ts !== false) $validUntilLocal = date("Y-m-d\\TH:i", $ts);
        }
        ?>

        <!-- Timer & Allowed Emails -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Expiry (optional)</label>
            <input
              type="datetime-local"
              name="valid_until_local"
              value="<?= h($validUntilLocal) ?>"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none" />
            <p class="mt-1 text-xs text-slate-500">Leave blank for no time limit.</p>
          </div>

          <!-- Multi-email tags (match TSX) -->
          <div class="w-full">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Specific User Emails</label>

            <div class="min-h-[38px] w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm transition-colors focus-within:border-yellow-500 focus-within:ring-2 focus-within:ring-yellow-100 flex items-start">
              <div class="flex flex-wrap gap-2 w-full min-w-0" id="emailTagsWrap">
                <input
                  type="text"
                  id="emailInput"
                  class="flex-grow min-w-[120px] outline-none text-slate-900 placeholder-slate-400 bg-transparent p-0 h-5"
                  placeholder="user@example.com"
                  value="" />
              </div>
            </div>

            <input type="hidden" name="allowed_email" id="allowedEmailHidden" value="<?= h((string)$form["allowed_email"]) ?>">

            <p class="mt-1 text-xs text-slate-500">
              Leave blank to apply to all. Press Enter or Comma to add multiple.
            </p>
          </div>
        </div>

        <!-- Limits & Status -->
        <div class="grid grid-cols-3 gap-4">
          <div class="col-span-1">
            <label class="block min-h-[2.5rem] text-sm font-medium text-slate-700 flex items-end mb-1.5">Max Redemptions</label>
            <input
              type="text"
              name="max_redemptions"
              value="<?= h((string)$form["max_redemptions"]) ?>"
              placeholder="Unlimited"
              inputmode="numeric"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none" />
          </div>

          <div class="col-span-1">
            <label class="block min-h-[2.5rem] text-sm font-medium text-slate-700 flex items-end mb-1.5">Per-Email Limit</label>
            <input
              type="number"
              min="1"
              name="per_email_limit"
              value="<?= h((string)$form["per_email_limit"]) ?>"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none" />
          </div>

          <div class="col-span-1">
            <label class="block min-h-[2.5rem] text-sm font-medium text-slate-700 flex items-end mb-1.5">Status</label>
            <?php $statusLbl = ($form["status"] === "inactive") ? "Inactive" : "Active"; ?>
            <input type="hidden" name="status" id="dcStatusInput" value="<?= h($form["status"]) ?>">

            <div class="relative">
              <button type="button" id="dcStatusBtn"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-yellow-500 focus:ring-2 focus:ring-yellow-100 focus:outline-none flex items-center justify-between gap-3">
                <span id="dcStatusLabel" class="truncate"><?= h($statusLbl) ?></span>
                <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div id="dcStatusPanel"
                class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
                <div class="p-2">
                  <button type="button"
                    class="dcStatusItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="active" data-label="Active">
                    <span class="block truncate">Active</span>
                  </button>
                  <button type="button"
                    class="dcStatusItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900"
                    data-value="inactive" data-label="Inactive">
                    <span class="block truncate">Inactive</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="pt-4 flex flex-col sm:flex-row lg:flex-col sm:items-center lg:items-stretch sm:justify-between gap-4 min-w-0">
          <div class="flex items-center text-xs text-slate-400 order-2 sm:order-1">
            <svg class="w-3 h-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13 16h-1v-4h-1m1-4h.01M12 20a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            Changes take effect immediately.
          </div>

          <div class="flex gap-3 w-full flex-col sm:flex-row order-1 sm:order-2">
            <?php if ($isEditing): ?>
              <a
                href="/admin/payment/discount-form.php"
                class="inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-amber-100 disabled:opacity-50 disabled:pointer-events-none bg-slate-100 text-slate-700 hover:bg-slate-200 px-4 py-2.5 text-base flex-1">
                Cancel
              </a>
            <?php endif; ?>

            <button
              type="submit"
              class="inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-amber-100 disabled:opacity-50 disabled:pointer-events-none px-4 py-2.5 text-base flex-1 shadow-md
                <?= $isEditing ? "bg-yellow-500 hover:bg-yellow-600 text-black shadow-yellow-200" : "bg-yellow-500 hover:bg-yellow-600 text-black shadow-yellow-200" ?>">
              <?php if ($isEditing): ?>
                Update Code
              <?php else: ?>
                Save Code
              <?php endif; ?>
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>

  <!-- RIGHT: ExistingCodesList.tsx (match 1:1) -->
  <div class="bg-white rounded-xl border border-slate-200 shadow-sm h-full flex flex-col lg:col-span-8">
    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">Existing Codes</h2>
        <p class="text-sm text-slate-500 mt-1">
          Showing up to <?= (int)max(500, count($rows)) ?> records.
        </p>
      </div>
    </div>

    <div class="flex-1 overflow-auto custom-scrollbar">
      <?php if (count($rows) === 0): ?>
        <div class="flex flex-col items-center justify-center h-64 sm:h-96 text-center px-4">
          <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4">
            <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M7 7h.01M7 3h10a2 2 0 012 2v4a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 10h10a2 2 0 012 2v4a2 2 0 01-2 2H7a2 2 0 01-2-2v-4a2 2 0 012-2z" />
            </svg>
          </div>
          <h3 class="text-sm font-medium text-slate-900">No discount codes found</h3>
          <p class="text-sm text-slate-500 mt-1 max-w-xs">
            Create your first discount code using the form on the left to get started.
          </p>
        </div>
      <?php else: ?>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 sticky top-0 z-10 border-b border-slate-200">
            <tr>
              <th class="px-6 py-3 font-medium text-slate-700">Code</th>
              <th class="px-6 py-3 font-medium text-slate-700">Product</th>
              <th class="px-6 py-3 font-medium text-slate-700">Discount</th>
              <th class="px-6 py-3 font-medium text-slate-700">Lifecycle</th>
              <th class="px-6 py-3 font-medium text-slate-700">Restriction</th>
              <th class="px-6 py-3 font-medium text-slate-700">Expiry</th>
              <th class="px-6 py-3 font-medium text-slate-700">Status</th>
              <th class="px-6 py-3 font-medium text-slate-700 text-right">Redeemed</th>
              <th class="px-6 py-3 font-medium text-slate-700"></th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php foreach ($rows as $r): ?>
              <?php
              $rid = (string)($r["id"] ?? "");
              $rcode = (string)($r["code"] ?? "");
              $rtype = ((string)($r["discount_type"] ?? "percent") === "fixed") ? "fixed" : "percent";
              $rval  = (float)($r["discount_value"] ?? 0);
              $rprodId = (string)($r["product_id"] ?? "");
              $rprodName = (string)($r["product_name"] ?? "");
              $rcatId = (string)($r["category_id"] ?? "");
              $rowProductIsSubscription = ((int)($r["product_is_subscription"] ?? 0) === 1);
              $rowCategoryIsSubscription = ($r["category_is_subscription"] === null || $r["category_is_subscription"] === "")
                ? null
                : (((int)$r["category_is_subscription"] === 1) ? "1" : "0");
              $rowIsSubscription = false;
              if ($rprodId !== "" && $rprodId !== "all") {
                $rowIsSubscription = $rowProductIsSubscription;
                if ($rcatId !== "" && $rcatId !== "all" && $rowCategoryIsSubscription !== null) {
                  $rowIsSubscription = ($rowCategoryIsSubscription === "1");
                }
              }
              $retentionType = norm_discount_type((string)($r["retention_discount_type"] ?? ""), true);
              $retentionValue = ($r["retention_discount_value"] === null || $r["retention_discount_value"] === "") ? null : (float)$r["retention_discount_value"];
              $remailsRaw = (string)($r["allowed_email"] ?? "");
              $emailsList = $remailsRaw ? parse_email_list($remailsRaw) : [];
              $firstEmail = $emailsList[0] ?? "";

              $timerLabel = "Never";
              $untilRaw = (string)($r["valid_until"] ?? "");
              if ($untilRaw !== "") {
                $ts = strtotime($untilRaw);
                if ($ts !== false) {
                  // contoh output: 10:30 PM (23/2/2026)
                  $timerLabel = date("g:i A (j/n/Y)", $ts);
                }
              }

              $discLabel = ($rtype === "fixed")
                ? ("RM" . number_format($rval, 2, ".", ""))
                : (rtrim(rtrim(number_format($rval, 2, ".", ""), '0'), '.') . "%");
              $retentionLabel = ($retentionValue === null)
                ? ""
                : (($retentionType === "fixed")
                  ? ("RM" . number_format($retentionValue, 2, ".", ""))
                  : (rtrim(rtrim(number_format($retentionValue, 2, ".", ""), '0'), '.') . "%"));

              $status = ((string)($r["status"] ?? "active") === "inactive") ? "inactive" : "active";

              $redeemed = (int)($r["redeemed_paid"] ?? 0);
              $mr = $r["max_redemptions"] ?? null;
              $mrLabel = ($mr === null || $mr === "") ? "Unlimited" : (string)(int)$mr;

              $editUrl = "/admin/payment/discount-form.php?id=" . urlencode($rid);
              ?>
              <tr class="hover:bg-slate-50 transition-colors group">
                <td class="px-6 py-4 font-medium text-slate-900"><?= h($rcode) ?></td>

                <td class="px-6 py-4 text-slate-600">
                  <div class="flex items-center gap-2">
                    <?php if ($rprodId === "" || $rprodId === "all"): ?>
                      <svg class="w-4 h-4 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0l-8 4-8-4m16 0v6a2 2 0 01-2 2H6a2 2 0 01-2-2v-6" />
                      </svg>
                      <span class="text-yellow-800 font-medium text-xs bg-yellow-50 px-2 py-0.5 rounded border border-yellow-200">
                        All Products
                      </span>
                    <?php else: ?>
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate max-w-[160px] text-xs" title="<?= h($rprodName ?: $rprodId) ?>">
                          <?= h($rprodName ?: $rprodId) ?>
                        </span>
                        <?php if ($rowIsSubscription): ?>
                          <span class="inline-flex items-center rounded border border-yellow-200 bg-yellow-50 px-2 py-0.5 text-[11px] font-medium text-yellow-800">
                            Subscription
                          </span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>

                <td class="px-6 py-4 text-slate-600"><?= h($discLabel) ?></td>

                <td class="px-6 py-4 text-slate-500">
                  <div class="flex flex-wrap gap-1.5">
                    <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                      Initial
                    </span>
                    <?php if ($retentionLabel !== ""): ?>
                      <span class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800" title="Retention auto discount <?= h($retentionLabel) ?>">
                        Retention
                      </span>
                    <?php endif; ?>
                  </div>
                </td>

                <td class="px-6 py-4 text-slate-500">
                  <?php if (!empty($emailsList)): ?>
                    <div class="flex items-start gap-2">
                      <svg class="w-4 h-4 text-slate-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h7m3-4a4 4 0 11-8 0 4 4 0 018 0zm6 4a3 3 0 10-6 0 3 3 0 006 0z" />
                      </svg>
                      <div class="flex flex-col">
                        <span class="truncate max-w-[140px] block font-medium text-slate-700" title="<?= h($firstEmail) ?>">
                          <?= h($firstEmail) ?>
                        </span>
                        <?php if (count($emailsList) > 1): ?>
                          <span class="text-xs text-slate-400">+<?= (int)(count($emailsList) - 1) ?> more users</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <span class="text-slate-400">Any Customer</span>
                  <?php endif; ?>
                </td>

                <td class="px-6 py-4 text-slate-500"><?= h($timerLabel) ?></td>

                <td class="px-6 py-4">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border
                    <?= ($status === "active")
                      ? "bg-green-50 text-green-700 border-green-200"
                      : "bg-slate-100 text-slate-600 border-slate-200" ?>">
                    <?= h($status) ?>
                  </span>
                </td>

                <td class="px-6 py-4 text-right text-slate-600">
                  <?= (int)$redeemed ?> <span class="text-slate-400">/ <?= h($mrLabel) ?></span>
                </td>

                <td class="px-6 py-4 text-right">
                  <div class="inline-flex items-center justify-end gap-2">
                    <!-- Edit -->
                    <a
                      href="<?= h($editUrl) ?>"
                      class="p-1.5 text-slate-400 hover:text-yellow-700 hover:bg-yellow-50 rounded transition-all inline-flex"
                      title="Edit Code">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M11 5h2M12 3v2m7.5 4.5l-9.9 9.9H6v-3.7l9.9-9.9a2 2 0 012.8 0l.8.8a2 2 0 010 2.8z" />
                      </svg>
                    </a>

                    <!-- Delete -->
                    <form method="POST"
                      action="/admin/payment/discount-form.php"
                      class="inline"
                      data-confirm="Delete this discount code?"
                      data-confirm-desc="This will remove the code permanently. This action cannot be undone."
                      data-confirm-ok="Delete">
                      <?php if ($csrfToken !== ""): ?>
                        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                      <?php endif; ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= h($rid) ?>">

                      <button
                        type="submit"
                        class="p-1.5 text-slate-400 hover:text-red-700 hover:bg-red-50 rounded transition-all inline-flex"
                        title="Delete Code">
                        <!-- trash icon -->
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-7 0V5a2 2 0 012-2h4a2 2 0 012 2v2" />
                        </svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="p-4 border-t border-slate-100 bg-slate-50/50">
      <p class="text-xs text-slate-400 text-center">
        Note: "Redeemed" counts only <strong>paid</strong> records in Discount_Redemptions.
      </p>
    </div>
  </div>

</div>

<!-- SDC Confirm Modal (same style as admin-products) -->
<div id="sdcConfirm" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
  <!-- backdrop -->
  <div data-sdc-confirm-close class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>

  <!-- panel -->
  <div
    id="sdcConfirmPanel"
    class="relative w-full max-w-md rounded-[2rem] bg-white border border-slate-100 shadow-2xl shadow-slate-900/20
           transform transition-all duration-150 scale-95 opacity-0"
    role="dialog" aria-modal="true" aria-labelledby="sdcConfirmTitle" aria-describedby="sdcConfirmDesc">
    <div class="p-6">
      <div class="flex items-start gap-4">
        <div class="shrink-0 w-11 h-11 rounded-2xl bg-red-50 border border-red-100 flex items-center justify-center text-red-600">
          <!-- trash icon -->
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 7l-1 14H6L5 7m3 0V5a2 2 0 012-2h4a2 2 0 012 2v2m-9 0h10" />
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
  /**
   * Product + Category dropdown (contents.php style) + dynamic categories fetch
   */
  (() => {
    // PRODUCT
    const prodBtn = document.getElementById("dcProductBtn");
    const prodPanel = document.getElementById("dcProductPanel");
    const prodInput = document.getElementById("dcProductInput");
    const prodLabel = document.getElementById("dcProductLabel");
    const prodSelected = document.getElementById("dcProductSelected");
    const prodSearch = document.getElementById("dcProductSearch");
    const prodList = document.getElementById("dcProductList");

    // CATEGORY
    const catBtn = document.getElementById("dcCategoryBtn");
    const catPanel = document.getElementById("dcCategoryPanel");
    const catInput = document.getElementById("dcCategoryInput");
    const catLabel = document.getElementById("dcCategoryLabel");
    const catList = document.getElementById("dcCategoryList");
    const catHelp = document.getElementById("dcCatHelp");
    const lifecycleSection = document.getElementById("dcLifecycleSection");

    if (!prodBtn || !prodPanel || !prodInput || !prodLabel || !prodSearch || !prodList) return;
    if (!catBtn || !catPanel || !catInput || !catLabel || !catList) return;

    function openPanelWithSearch(panel, search, list) {
      panel.classList.remove("hidden");
      search.value = "";
      filterList(list, "");
      setTimeout(() => search.focus(), 0);
    }

    function openPanel(panel) {
      panel.classList.remove("hidden");
    }

    function closePanel(panel) {
      panel.classList.add("hidden");
    }

    function filterList(list, q) {
      const qq = (q || "").toLowerCase();
      list.querySelectorAll("button").forEach(btn => {
        const txt = (btn.textContent || "").toLowerCase();
        btn.style.display = txt.includes(qq) ? "" : "none";
      });
    }

    function setCatDisabled(msg) {
      catBtn.disabled = true;
      catBtn.classList.add("opacity-50", "cursor-not-allowed");
      closePanel(catPanel);
      if (catHelp) catHelp.textContent = msg || "Select a product to enable variant restriction.";
    }

    function setCatEnabled(msg) {
      catBtn.disabled = false;
      catBtn.classList.remove("opacity-50", "cursor-not-allowed");
      if (catHelp) catHelp.textContent = msg || "Leave default to apply to all variants.";
    }

    function setCategory(value, labelText) {
      catInput.value = value || "all";
      catLabel.textContent = (labelText || "— All variants —").trim();
      closePanel(catPanel);
      syncLifecycleVisibility();
    }

    function rebuildCategoryList(items) {
      catList.innerHTML = "";

      const allBtn = document.createElement("button");
      allBtn.type = "button";
      allBtn.className = "dcCatItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900";
      allBtn.dataset.value = "all";
      allBtn.dataset.label = "— All variants —";
      allBtn.dataset.isSubscription = "";
      allBtn.innerHTML = `<span class="block truncate">— All variants —</span>`;
      catList.appendChild(allBtn);

      (items || []).forEach(c => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "dcCatItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 text-sm text-slate-900";
        b.dataset.value = String(c.id);
        b.dataset.label = String(c.name);
        b.dataset.isSubscription = (c && c.is_subscription !== null && c.is_subscription !== undefined) ? String(c.is_subscription) : "";
        b.innerHTML = `<span class="block truncate">${String(c.name)}</span>`;
        catList.appendChild(b);
      });
    }

    function syncLifecycleVisibility() {
      if (!lifecycleSection) return;

      const productValue = prodInput.value || "all";
      if (!productValue || productValue === "all") {
        lifecycleSection.classList.add("hidden");
        return;
      }

      const categoryValue = catInput.value || "all";
      const productIsSub = (prodLabel?.dataset?.isSubscription || "0") === "1";
      const categoryFlag = (catLabel?.dataset?.isSubscription || "").trim();

      let show = false;
      if (categoryValue === "all") {
        // If "All variants", show if product itself is sub OR has sub variants
        show = productIsSub;
      } else {
        // If specific variant, show ONLY if that variant is a sub
        show = (categoryFlag === "1");
      }

      lifecycleSection.classList.toggle("hidden", !show);
    }

    // keepExisting=true penting untuk EDIT mode (jangan reset category_id)
    async function loadCats(productId, hasCatsFlag, keepExisting = false) {
      if (!productId || productId === "all") {
        catLabel.dataset.isSubscription = "";
        setCategory("all", "— All variants —");
        rebuildCategoryList([]);
        setCatDisabled("Select a product to enable variant restriction.");
        return;
      }

      if (hasCatsFlag === "0") {
        catLabel.dataset.isSubscription = "";
        setCategory("all", "— All variants —");
        rebuildCategoryList([]);
        setCatDisabled("This product has no variants.");
        return;
      }

      const prevVal = keepExisting ? (catInput.value || "all") : "all";
      if (!keepExisting) setCategory("all", "— All variants —");

      const u = new URL(window.location.href);
      u.searchParams.set("ajax", "categories");
      u.searchParams.set("product_id", productId);

      const res = await fetch(u.pathname + "?" + u.searchParams.toString(), {
        headers: {
          "Accept": "application/json"
        }
      });

      const data = await res.json().catch(() => null);
      const list = (data && data.ok && Array.isArray(data.categories)) ? data.categories : [];

      if (!list.length) {
        catLabel.dataset.isSubscription = "";
        setCategory("all", "— All variants —");
        rebuildCategoryList([]);
        setCatDisabled("This product has no variants.");
        return;
      }

      rebuildCategoryList(list);
      setCatEnabled("Leave default to apply to all variants.");

      // restore category (edit mode)
      if (prevVal && prevVal !== "all") {
        const btn = [...catList.querySelectorAll(".dcCatItem")].find(x => x.dataset.value === prevVal);
        if (btn) {
          catLabel.dataset.isSubscription = btn.dataset.isSubscription || "";
          setCategory(prevVal, btn.dataset.label || btn.textContent);
        } else {
          catLabel.dataset.isSubscription = "";
          setCategory("all", "— All variants —");
        }
      }
      syncLifecycleVisibility();
    }

    function setProduct(value, labelText, hasCatsFlag, isSubscriptionFlag) {
      prodInput.value = value || "all";
      const lbl = (labelText || "— All Products —").trim();
      prodLabel.textContent = lbl;
      prodLabel.dataset.isSubscription = isSubscriptionFlag || "0";
      if (prodSelected) prodSelected.textContent = lbl;

      closePanel(prodPanel);
      loadCats(prodInput.value, hasCatsFlag || "1", false);
      syncLifecycleVisibility();
    }

    // Product: toggle + search
    prodBtn.addEventListener("click", (e) => {
      e.preventDefault();
      prodPanel.classList.contains("hidden") ?
        openPanelWithSearch(prodPanel, prodSearch, prodList) :
        closePanel(prodPanel);
    });

    prodSearch.addEventListener("input", () => filterList(prodList, prodSearch.value));

    prodList.addEventListener("click", (e) => {
      const t = e.target.closest(".dcProdItem");
      if (!t) return;
      setProduct(
        t.dataset.value || "all",
        t.dataset.label || t.textContent,
        t.dataset.hasCats || "1",
        t.dataset.isSubscription || "0"
      );
    });

    // Category: toggle (NO search)
    catBtn.addEventListener("click", (e) => {
      e.preventDefault();
      if (catBtn.disabled) return;
      catPanel.classList.contains("hidden") ? openPanel(catPanel) : closePanel(catPanel);
    });

    catList.addEventListener("click", (e) => {
      const t = e.target.closest(".dcCatItem");
      if (!t) return;
      catLabel.dataset.isSubscription = t.dataset.isSubscription || "";
      setCategory(t.dataset.value || "all", t.dataset.label || t.textContent);
    });

    // click outside close
    document.addEventListener("click", (e) => {
      if (!prodPanel.contains(e.target) && !prodBtn.contains(e.target)) closePanel(prodPanel);
      if (!catPanel.contains(e.target) && !catBtn.contains(e.target)) closePanel(catPanel);
    });

    // ESC close
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        closePanel(prodPanel);
        closePanel(catPanel);
      }
    });

    // INIT: jangan reset category kalau edit mode
    const initProdVal = (prodInput.value || "all");
    const initHasCats = (prodLabel?.dataset?.hasCats || "1");
    loadCats(initProdVal, initHasCats, true);
    syncLifecycleVisibility();
  })();

  (() => {
    function toggle(panel) {
      panel.classList.contains("hidden") ? panel.classList.remove("hidden") : panel.classList.add("hidden");
    }

    function close(panel) {
      panel.classList.add("hidden");
    }

    const dropdowns = [{
        btn: document.getElementById("dcDiscountTypeBtn"),
        panel: document.getElementById("dcDiscountTypePanel"),
        input: document.getElementById("dcDiscountTypeInput"),
        label: document.getElementById("dcDiscountTypeLabel"),
        itemClass: ".dcDTypeItem",
        defaultValue: "percent",
        defaultLabel: "Percent (%)",
      },
      {
        btn: document.getElementById("dcRetentionTypeBtn"),
        panel: document.getElementById("dcRetentionTypePanel"),
        input: document.getElementById("dcRetentionTypeInput"),
        label: document.getElementById("dcRetentionTypeLabel"),
        itemClass: ".dcRetentionTypeItem",
        defaultValue: "",
        defaultLabel: "Select type",
      },
      {
        btn: document.getElementById("dcStatusBtn"),
        panel: document.getElementById("dcStatusPanel"),
        input: document.getElementById("dcStatusInput"),
        label: document.getElementById("dcStatusLabel"),
        itemClass: ".dcStatusItem",
        defaultValue: "active",
        defaultLabel: "Active",
      }
    ];

    dropdowns.forEach((cfg) => {
      cfg.btn?.addEventListener("click", (e) => {
        e.preventDefault();
        if (cfg.panel) toggle(cfg.panel);
      });

      cfg.panel?.addEventListener("click", (e) => {
        const t = e.target.closest(cfg.itemClass);
        if (!t || !cfg.input || !cfg.label || !cfg.panel) return;
        const v = Object.prototype.hasOwnProperty.call(t.dataset, "value") ? (t.dataset.value || "") : cfg.defaultValue;
        const l = t.dataset.label || t.textContent || cfg.defaultLabel;
        cfg.input.value = v;
        cfg.label.textContent = l.trim();
        close(cfg.panel);
      });
    });

    document.addEventListener("click", (e) => {
      dropdowns.forEach((cfg) => {
        if (cfg.panel && cfg.btn && !cfg.panel.contains(e.target) && !cfg.btn.contains(e.target)) close(cfg.panel);
      });
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        dropdowns.forEach((cfg) => {
          if (cfg.panel) close(cfg.panel);
        });
      }
    });
  })();

  /**
   * Multi-email tag input (match TSX)
   * Stores comma-separated emails in hidden input "allowed_email".
   */
  (() => {
    const hidden = document.getElementById("allowedEmailHidden");
    const input = document.getElementById("emailInput");
    const wrap = document.getElementById("emailTagsWrap");

    if (!hidden || !input || !wrap) return;

    let emails = [];
    const raw = (hidden.value || "").trim();
    if (raw) {
      emails = raw.split(/[,\s;]+/).map(s => s.trim().toLowerCase()).filter(Boolean);
      emails = Array.from(new Set(emails));
    }

    function isEmail(str) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(str);
    }

    function syncHidden() {
      hidden.value = emails.join(",");
    }

    function render() {
      [...wrap.querySelectorAll("[data-email-tag]")].forEach(n => n.remove());
      input.placeholder = emails.length ? "" : "user@example.com";

      emails.forEach((e) => {
        const tag = document.createElement("span");
        tag.setAttribute("data-email-tag", "1");
        tag.className =
          "inline-flex items-center max-w-full min-w-0 px-2 py-0.5 rounded text-xs font-medium " +
          "bg-yellow-50 text-yellow-800 border border-yellow-200";
        tag.title = e; // hover nampak full email

        const text = document.createElement("span");
        text.className = "truncate min-w-0 max-w-[220px] sm:max-w-[280px]";
        text.textContent = e;

        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "ml-1.5 shrink-0 text-yellow-500 hover:text-yellow-700 focus:outline-none";
        btn.innerHTML = `
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      `;
        btn.addEventListener("click", () => {
          emails = emails.filter(x => x !== e);
          syncHidden();
          render();
        });

        tag.appendChild(text);
        tag.appendChild(btn);
        wrap.insertBefore(tag, input);
      });
    }

    function addFromInput() {
      const val = (input.value || "").trim().replace(/,$/, "");
      if (!val) return;

      const parts = val.split(/[,\s;]+/).map(s => s.trim().toLowerCase()).filter(Boolean);
      let changed = false;

      parts.forEach(p => {
        if (!isEmail(p)) return;
        if (!emails.includes(p)) {
          emails.push(p);
          changed = true;
        }
      });

      if (changed) {
        syncHidden();
        render();
      }
      input.value = "";
    }

    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === ",") {
        e.preventDefault();
        addFromInput();
      }
      if (e.key === "Backspace" && !input.value && emails.length) {
        emails.pop();
        syncHidden();
        render();
      }
    });

    input.addEventListener("blur", () => addFromInput());

    render();
  })();

  (() => {
    const modal = document.getElementById("sdcConfirm");
    const panel = document.getElementById("sdcConfirmPanel");
    if (!modal || !panel) return;

    const titleEl = document.getElementById("sdcConfirmTitle");
    const descEl = document.getElementById("sdcConfirmDesc");
    const btnOk = modal.querySelector("[data-sdc-confirm-ok]");
    const btnCancel = modal.querySelector("[data-sdc-confirm-cancel]");

    let pendingForm = null;
    let lastActive = null;

    const open = (form) => {
      pendingForm = form;
      lastActive = document.activeElement;

      const t = form.getAttribute("data-confirm") || "Confirm";
      const d = form.getAttribute("data-confirm-desc") || "Are you sure?";
      const okLbl = form.getAttribute("data-confirm-ok") || "Confirm";

      titleEl.textContent = t;
      descEl.textContent = d;
      btnOk.textContent = okLbl;

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

    // Intercept submit for forms with data-confirm
    document.addEventListener("submit", (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.hasAttribute("data-confirm")) return;

      // allow confirmed submission to pass once
      if (form.dataset.sdcConfirmPass === "1") {
        delete form.dataset.sdcConfirmPass;
        return;
      }

      e.preventDefault();
      open(form);
    }, true);

    // OK
    btnOk.addEventListener("click", () => {
      if (!pendingForm) return;
      const f = pendingForm;
      f.dataset.sdcConfirmPass = "1";
      close();
      setTimeout(() => {
        if (typeof f.requestSubmit === "function") f.requestSubmit();
        else f.submit();
      }, 80);
    });

    // Cancel + backdrop + ESC
    btnCancel.addEventListener("click", close);
    modal.addEventListener("click", (e) => {
      if (e.target && e.target.hasAttribute("data-sdc-confirm-close")) close();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
    });
  })();
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>