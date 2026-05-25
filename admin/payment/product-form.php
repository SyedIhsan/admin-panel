<?php

declare(strict_types=1);
require_once __DIR__ . "/_init.php";
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

/** @var mysqli|null $conn */
$conn = $conn ?? null;

// Ensure $conn is initialized
if (!($conn instanceof mysqli) && function_exists('getBillingConn')) {
  $conn = getBillingConn();
}

if (!($conn instanceof mysqli)) {
  http_response_code(500);
  exit("Database connection unavailable.");
}

function columnExists(mysqli $conn, string $table, string $col): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

function tableExists(mysqli $conn, string $table): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
  return $r && mysqli_num_rows($r) > 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $col, string $definition): bool
{
  if (!tableExists($conn, $table)) return false;
  if (columnExists($conn, $table, $col)) return true;

  $t = mysqli_real_escape_string($conn, $table);
  $ok = mysqli_query($conn, "ALTER TABLE `{$t}` ADD COLUMN `{$col}` {$definition}");
  if (!$ok) {
    error_log("product-form addColumnIfMissing failed for {$table}.{$col}: " . $conn->error);
  }

  return $ok && columnExists($conn, $table, $col);
}

function isAutoIncrement(mysqli $conn, string $table, string $col = "id"): bool
{
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW FULL COLUMNS FROM `{$t}` LIKE '{$c}'");
  if (!$r || mysqli_num_rows($r) === 0) return false;
  $row = mysqli_fetch_assoc($r);
  $extra = strtolower((string)($row["Extra"] ?? ""));
  return str_contains($extra, "auto_increment");
}

// bind_param dynamic (sebab fields optional)
function bindParams(mysqli_stmt $stmt, string $types, array $params): void
{
  if (strlen($types) !== count($params)) {
    throw new RuntimeException("Bind mismatch: types length=" . strlen($types) . " params count=" . count($params));
  }
  $refs = [];
  $refs[] = &$types;
  foreach ($params as $k => $v) $refs[] = &$params[$k];
  call_user_func_array([$stmt, "bind_param"], $refs);
}

// poster directory: guna yang _init dah prepare
$postersDir = $PAY_POSTERS_DIR ?? (rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/payment/storage/posters");
if (!is_dir($postersDir)) @mkdir($postersDir, 0755, true);

// Retention installment support. Safe to keep here while older DBs are being upgraded.
addColumnIfMissing(
  $conn,
  "Products",
  "retention_first_month_price",
  columnExists($conn, "Products", "retention_price")
    ? "DECIMAL(10,2) NULL AFTER `retention_price`"
    : "DECIMAL(10,2) NULL"
);
addColumnIfMissing(
  $conn,
  "Product_Categories",
  "retention_first_month_price",
  columnExists($conn, "Product_Categories", "retention_price")
    ? "DECIMAL(10,2) NULL AFTER `retention_price`"
    : "DECIMAL(10,2) NULL"
);

// optional columns (ikut schema DB kau)
$hasDescCol       = columnExists($conn, "Products", "description");
$hasPosterCol     = columnExists($conn, "Products", "poster");
$hasMembershipCol = columnExists($conn, "Products", "membership_types");
$hasHasCatsCol    = columnExists($conn, "Products", "has_categories");

$hasElearningCourseCol = columnExists($conn, "Products", "elearning_course_id");
$hasCatVariantTypeCol      = columnExists($conn, "Product_Categories", "variant_type");
$hasCatElearningCourseCol  = columnExists($conn, "Product_Categories", "elearning_course_id");

// subscription columns (defensive schema check)
$hasProdIsSubscriptionCol   = columnExists($conn, "Products", "is_subscription");
$hasProdDurationValueCol    = columnExists($conn, "Products", "duration_value");
$hasProdDurationUnitCol     = columnExists($conn, "Products", "duration_unit");
$hasProdFirstMonthPriceCol  = columnExists($conn, "Products", "first_month_price");
$hasProdRemainPriceCol      = columnExists($conn, "Products", "remaining_month_price");
$hasProdRetentionPriceCol   = columnExists($conn, "Products", "retention_price");
$hasProdRetentionFirstPriceCol = columnExists($conn, "Products", "retention_first_month_price");

$hasCatIsSubscriptionCol    = columnExists($conn, "Product_Categories", "is_subscription");
$hasCatDurationValueCol     = columnExists($conn, "Product_Categories", "duration_value");
$hasCatDurationUnitCol      = columnExists($conn, "Product_Categories", "duration_unit");
$hasCatFirstMonthPriceCol   = columnExists($conn, "Product_Categories", "first_month_price");
$hasCatRemainPriceCol       = columnExists($conn, "Product_Categories", "remaining_month_price");
$hasCatRetentionPriceCol    = columnExists($conn, "Product_Categories", "retention_price");
$hasCatRetentionFirstPriceCol = columnExists($conn, "Product_Categories", "retention_first_month_price");

// payment option columns (installment support only - full payment always enabled)
$hasProdAllowInstallmentCol    = columnExists($conn, "Products", "allow_installment");
$hasProdInstallmentCountCol    = columnExists($conn, "Products", "installment_count");
$hasProdInstallmentIntervalCol = columnExists($conn, "Products", "installment_interval_unit");

$elConn = null;
if (function_exists('getElearningConn')) {
  $tmpElConn = getElearningConn();
  if ($tmpElConn instanceof mysqli) {
    $elConn = $tmpElConn;
  }
}

$elearningCourses = [];
$courseTitleMap = [];

$groupedCourses = [
  'Beginner' => [],
  'Intermediate' => [],
  'Advanced' => [],
  'Others' => [],
];

if ($elConn instanceof mysqli && tableExists($elConn, 'courses')) {
  $resCourses = $elConn->query("
    SELECT id, title, level, price
    FROM courses
    ORDER BY
      CASE LOWER(TRIM(level))
        WHEN 'beginner' THEN 1
        WHEN 'intermediate' THEN 2
        WHEN 'advanced' THEN 3
        WHEN 'advance' THEN 3
        ELSE 4
      END,
      title ASC
  ");
  if ($resCourses) {
    $elearningCourses = $resCourses->fetch_all(MYSQLI_ASSOC);

    $groupedCourses = [
      'Beginner' => [],
      'Intermediate' => [],
      'Advanced' => [],
      'Others' => [],
    ];

    foreach ($elearningCourses as $course) {
      $cid = trim((string)($course['id'] ?? ''));
      if ($cid === '') continue;

      $title = trim((string)($course['title'] ?? $cid));
      $level = trim((string)($course['level'] ?? ''));
      $normalizedLevel = strtolower($level);

      $courseTitleMap[$cid] = [
        'id' => $cid,
        'title' => $title,
        'level' => $level,
        'price' => (string)($course['price'] ?? '0.00'),
      ];

      if ($normalizedLevel === 'beginner') {
        $groupedCourses['Beginner'][] = $course;
      } elseif ($normalizedLevel === 'intermediate') {
        $groupedCourses['Intermediate'][] = $course;
      } elseif ($normalizedLevel === 'advanced' || $normalizedLevel === 'advance') {
        $groupedCourses['Advanced'][] = $course;
      } else {
        $groupedCourses['Others'][] = $course;
      }
    }
  } else {
    error_log("product-form: failed to load e-learning courses = " . $elConn->error);
  }
}

// id types (support auto increment / string id)
$productIdAuto = isAutoIncrement($conn, "Products", "id");
$catIdAuto     = isAutoIncrement($conn, "Product_Categories", "id");

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function money(int|float|string|null $n): string
{
  return number_format((float)($n ?? 0), 2, ".", "");
}

function parseNullableInt(int|float|string|null $value): ?int
{
  $raw = trim((string)($value ?? ""));
  if ($raw === "") return null;
  return (int)$raw;
}

function parseNullableDecimal(int|float|string|null $value): ?string
{
  $raw = trim((string)($value ?? ""));
  if ($raw === "") return null;
  return money((float)$raw);
}

function sanitizeDescriptionHtml(string $html): string
{
  $html = trim($html);
  if ($html === "") return "";

  $allowed = "<p><br><strong><em><u><ul><ol><li>";
  $clean = strip_tags($html, $allowed);

  // keep only safe data-list on <li> (needed by Quill for bullet)
  $clean = preg_replace_callback('/<li\b([^>]*)>/i', function ($m) {
    $attrs = $m[1] ?? '';
    if (preg_match('/\sdata-list=("|\')?(bullet|ordered|checked|unchecked)\1?/i', $attrs, $mm)) {
      return '<li data-list="' . strtolower($mm[2]) . '">';
    }
    return '<li>';
  }, $clean);

  // strip attributes for other allowed tags
  $clean = preg_replace('/<(\/?)(p|br|strong|em|u|ul|ol)(\s[^>]*)?>/i', '<$1$2>', $clean);

  $clean = preg_replace('/<br\s*\/?>/i', '<br>', $clean);
  $clean = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $clean);

  return $clean;
}

$id = trim((string)($_GET["id"] ?? ""));
$initial = null;
$formCats = [];

if ($id !== "") {
  $sql = "SELECT id, name, base_price, status"
    . ($hasElearningCourseCol ? ", elearning_course_id" : "")
    . ($hasDescCol ? ", description" : "")
    . ($hasPosterCol ? ", poster" : "")
    . ($hasMembershipCol ? ", membership_types" : "")
    . ($hasHasCatsCol ? ", has_categories" : "")
    . ($hasProdIsSubscriptionCol ? ", is_subscription" : "")
    . ($hasProdDurationValueCol ? ", duration_value" : "")
    . ($hasProdDurationUnitCol ? ", duration_unit" : "")
    . ($hasProdFirstMonthPriceCol ? ", first_month_price" : "")
    . ($hasProdRemainPriceCol ? ", remaining_month_price" : "")
    . ($hasProdRetentionPriceCol ? ", retention_price" : "")
    . ($hasProdRetentionFirstPriceCol ? ", retention_first_month_price" : "")
    . ($hasProdAllowInstallmentCol ? ", allow_installment" : "")
    . ($hasProdInstallmentCountCol ? ", installment_count" : "")
    . ($hasProdInstallmentIntervalCol ? ", installment_interval_unit" : "")
    . " FROM `Products` WHERE id = ? LIMIT 1";

  $stmt = $conn->prepare($sql);
  if ($stmt) {
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $initial = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
  }

  // load categories (variants)
  if ($initial) {
    $sqlC = "SELECT "
      . ($catIdAuto ? "id" : "id")
      . ", name, price_modifier"
      . ($hasCatVariantTypeCol ? ", variant_type" : ", 'normal' AS variant_type")
      . ($hasCatElearningCourseCol ? ", elearning_course_id" : ", NULL AS elearning_course_id")
      . ($hasCatIsSubscriptionCol ? ", is_subscription" : ", NULL AS is_subscription")
      . ($hasCatDurationValueCol ? ", duration_value" : ", NULL AS duration_value")
      . ($hasCatDurationUnitCol ? ", duration_unit" : ", NULL AS duration_unit")
      . ($hasCatFirstMonthPriceCol ? ", first_month_price" : ", NULL AS first_month_price")
      . ($hasCatRemainPriceCol ? ", remaining_month_price" : ", NULL AS remaining_month_price")
      . ($hasCatRetentionPriceCol ? ", retention_price" : ", NULL AS retention_price")
      . ($hasCatRetentionFirstPriceCol ? ", retention_first_month_price" : ", NULL AS retention_first_month_price")
      . " FROM `Product_Categories`
          WHERE product_id = ?
          ORDER BY sort_order ASC, id ASC";
    $stmtC = $conn->prepare($sqlC);
    if ($stmtC) {
      $stmtC->bind_param("s", $id);
      $stmtC->execute();
      $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmtC->close();

      foreach ($rows as $r) {
        $formCats[] = [
          "id" => (string)($r["id"] ?? ""),
          "name" => (string)($r["name"] ?? ""),
          "priceModifier" => (string)($r["price_modifier"] ?? "0.00"),
          "variantType" => strtolower(trim((string)($r["variant_type"] ?? "normal"))),
          "elearningCourseId" => (string)($r["elearning_course_id"] ?? ""),
          "isSubscription" => ($r["is_subscription"] === null) ? null : (string)$r["is_subscription"],
          "durationValue" => ($r["duration_value"] === null) ? "" : (string)$r["duration_value"],
          "durationUnit" => (string)($r["duration_unit"] ?? ""),
          "firstMonthPrice" => ($r["first_month_price"] === null) ? "" : (string)$r["first_month_price"],
          "retentionPrice" => ($r["retention_price"] === null) ? "" : (string)$r["retention_price"],
          "retentionFirstMonthPrice" => ($r["retention_first_month_price"] === null) ? "" : (string)$r["retention_first_month_price"],
        ];
      }
    }
  }
}

$errors = [];

// Defaults for POST-derived variables reused later to rehydrate the form after validation/save errors.
// This also prevents Intelephense "possible undefined variable" warnings.
$productId = "";
$selectedCourseId = "";
$name = "";
$description = "";
$basePrice = 0.0;
$status = "active";
$isSubscription = 0;
$durationValue = null;
$durationUnit = "";
$firstMonthPrice = null;
$remainingMonthPrice = null;
$retentionPrice = null;
$retentionFirstMonthPrice = null;
$allowInstallment = 0;
$installmentCount = null;
$installmentIntervalUnit = "month";
$membershipTypes = [];
$poster = "";
$hasCategories = false;
$categories = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (function_exists("csrf_validate")) csrf_validate();

  $productId   = trim((string)($_POST["product_id"] ?? ""));
  $selectedCourseId = trim((string)($_POST["elearning_course_id"] ?? ""));
  $name        = trim((string)($_POST["name"] ?? ""));
  $description = sanitizeDescriptionHtml((string)($_POST["description"] ?? ""));
  $basePrice   = (float)($_POST["basePrice"] ?? 0);
  $status      = ((string)($_POST["status"] ?? "active") === "inactive") ? "inactive" : "active";

  // subscription (product-level)
  $isSubscription = isset($_POST["is_subscription"]) ? 1 : 0;
  $durationValue = parseNullableInt($_POST["duration_value"] ?? null);
  $durationUnit = strtolower(trim((string)($_POST["duration_unit"] ?? "")));
  if (!in_array($durationUnit, ["day", "month"], true)) $durationUnit = "";
  $firstMonthPrice = parseNullableDecimal($_POST["first_month_price"] ?? null);
  $remainingMonthPrice = null; // always calculated server-side, never from input
  $retentionPrice = parseNullableDecimal($_POST["retention_price"] ?? null);
  $retentionFirstMonthPrice = parseNullableDecimal($_POST["retention_first_month_price"] ?? null);

  // payment method options (product-level)
  // Note: Full payment is always allowed by default. Only allow_installment is configurable.
  $allowInstallment = isset($_POST["allow_installment"]) ? 1 : 0;
  $installmentCount = parseNullableInt($_POST["installment_count"] ?? null);
  $installmentIntervalUnit = strtolower(trim((string)($_POST["installment_interval_unit"] ?? "month")));
  if (!in_array($installmentIntervalUnit, ["day", "week", "month"], true)) {
    $installmentIntervalUnit = "month";
  }

  // IMPORTANT:
  // Must read variants/categories BEFORE installment validation.
  // Otherwise $hasCategories remains false and product-level first_month_price is wrongly required.
  $hasCategories = isset($_POST["hasCategories"]);
  $catIds       = $_POST["cat_id"] ?? [];
  $catNames     = $_POST["cat_name"] ?? [];
  $catPrices    = $_POST["cat_price"] ?? [];
  $catTypes     = $_POST["cat_type"] ?? [];
  $catCourseIds = $_POST["cat_course_id"] ?? [];
  $catSubFlags  = $_POST["cat_is_subscription"] ?? [];
  $catDurVals   = $_POST["cat_duration_value"] ?? [];
  $catDurUnits  = $_POST["cat_duration_unit"] ?? [];
  $catFirsts    = $_POST["cat_first_month_price"] ?? [];
  $catRetains   = $_POST["cat_retention_price"] ?? [];
  $catRetentionFirsts = $_POST["cat_retention_first_month_price"] ?? [];

  // Subscription fields are only required when product is subscription-based.
  if ($isSubscription === 1) {
    if (($durationValue ?? 0) <= 0) {
      $errors[] = "Duration value is required for subscription product.";
    }

    if ($durationUnit === "") {
      $errors[] = "Duration unit is required for subscription product.";
    }
  } else {
    // Non-subscription products should not keep subscription lifecycle fields.
    $durationValue = null;
    $durationUnit = "";
    $retentionPrice = null;
    $retentionFirstMonthPrice = null;
  }

  // Installment is flexible and can be used for subscription or one-time products.
  // If variants/categories are enabled, first payment price must be taken from each variant.
  if ($allowInstallment === 1) {
    if ($installmentCount === null || $installmentCount <= 0) {
      $errors[] = "Installment count is required and must be greater than 0 if installment payment is enabled.";
    }

    if ($hasCategories) {
      // Do not use product-level first payment when variants are enabled.
      $firstMonthPrice = null;
      $remainingMonthPrice = null;
    } else {
      if ($firstMonthPrice === null) {
        $errors[] = "First Payment Price is required if installment payment is enabled.";
      } elseif ($firstMonthPrice <= 0) {
        $errors[] = "First Payment Price must be greater than 0.";
      } elseif ($firstMonthPrice >= $basePrice) {
        $errors[] = "First Payment Price must be less than the Base Price.";
      }

      if (
        $firstMonthPrice !== null &&
        $firstMonthPrice > 0 &&
        $firstMonthPrice < $basePrice &&
        $installmentCount !== null &&
        $installmentCount > 0
      ) {
        $calculatedRemaining = ($basePrice - $firstMonthPrice) / $installmentCount;

        if ($calculatedRemaining <= 0) {
          $errors[] = "Calculated remaining installment price is invalid. Please check your values.";
        } else {
          $remainingMonthPrice = money($calculatedRemaining);
        }
      }

      if ($isSubscription === 1 && $retentionPrice !== null) {
        if ((float)$retentionPrice <= 0) {
          $errors[] = "Retention Offer Price must be greater than 0.";
        } elseif ($retentionFirstMonthPrice === null) {
          $errors[] = "Retention First Payment Price is required when installment payment is enabled and Retention Offer Price is set.";
        } elseif ((float)$retentionFirstMonthPrice <= 0) {
          $errors[] = "Retention First Payment Price must be greater than 0.";
        } elseif ((float)$retentionFirstMonthPrice >= (float)$retentionPrice) {
          $errors[] = "Retention First Payment Price must be less than the Retention Offer Price.";
        }
      } else {
        $retentionFirstMonthPrice = null;
      }
    }
  } else {
    $firstMonthPrice = null;
    $remainingMonthPrice = null;
    $retentionFirstMonthPrice = null;
    $installmentCount = null;
    $installmentIntervalUnit = "month";
  }

  // membership types
  $membershipTypes = [];
  if (isset($_POST["membership_demo"])) $membershipTypes[] = "DEMO";
  if (isset($_POST["membership_gm"]))  $membershipTypes[] = "GM";

  // poster
  $existingPoster = trim((string)($_POST["existing_poster"] ?? ""));
  $poster = $existingPoster;

  // categories / variants were already read above before installment validation.
  $isElearningLinked = ($selectedCourseId !== "");

  if ($isElearningLinked) {
    if (!isset($courseTitleMap[$selectedCourseId])) {
      $errors[] = "Selected e-Learning product is invalid.";
    } else {
      if ($name === "") {
        $name = $courseTitleMap[$selectedCourseId]['title'];
      }
    }
  }

  if ($name === "") $errors[] = "Product Name required.";

  // ✅ Build categories
  $categories = [];
  if ($hasCategories) {
    $n = max(
      count($catNames),
      count($catPrices),
      count($catIds),
      count($catTypes),
      count($catCourseIds),
      count($catSubFlags),
      count($catDurVals),
      count($catDurUnits),
      count($catFirsts),
      count($catRetains),
      count($catRetentionFirsts)
    );

    for ($i = 0; $i < $n; $i++) {
      $cname = trim((string)($catNames[$i] ?? ""));
      if ($cname === "") continue;

      $cid = trim((string)($catIds[$i] ?? ""));
      if ($cid === "") $cid = "c_" . bin2hex(random_bytes(4));

      $cprice = (float)($catPrices[$i] ?? 0);

      $ctype = strtolower(trim((string)($catTypes[$i] ?? "normal")));
      if (!in_array($ctype, ["normal", "elearning"], true)) {
        $ctype = "normal";
      }

      $ccourseId = trim((string)($catCourseIds[$i] ?? ""));
      if ($ctype !== "elearning") {
        $ccourseId = "";
      }

      if ($ctype === "elearning") {
        if (!$hasCatVariantTypeCol || !$hasCatElearningCourseCol) {
          $errors[] = "Please run DB migration for Product_Categories variant_type / elearning_course_id first.";
        } elseif ($ccourseId === "" || !isset($courseTitleMap[$ccourseId])) {
          $errors[] = "Variant '{$cname}' requires a valid e-Learning course.";
        }
      }

      // category-level subscription overrides
      $cSubRaw = strtolower(trim((string)($catSubFlags[$i] ?? "")));
      if ($cSubRaw === "1" || $cSubRaw === "yes" || $cSubRaw === "true" || $cSubRaw === "on") {
        $cIsSubscription = 1;
      } elseif ($cSubRaw === "0" || $cSubRaw === "no" || $cSubRaw === "false") {
        $cIsSubscription = 0;
      } else {
        $cIsSubscription = null; // inherit product-level
      }

      $cDurationValue = parseNullableInt($catDurVals[$i] ?? null);
      $cDurationUnit = strtolower(trim((string)($catDurUnits[$i] ?? "")));
      if (!in_array($cDurationUnit, ["day", "month"], true)) $cDurationUnit = "";
      $cFirstMonthPrice = parseNullableDecimal($catFirsts[$i] ?? null);
      $cRetentionPrice = parseNullableDecimal($catRetains[$i] ?? null);
      $cRetentionFirstMonthPrice = parseNullableDecimal($catRetentionFirsts[$i] ?? null);
      $cRemainingMonthPrice = null;

      // If installment is enabled and variants exist, each variant must provide its own first payment price.
      if ($allowInstallment === 1) {
        $variantFullPrice = $basePrice + $cprice;

        if ($cFirstMonthPrice === null) {
          $errors[] = "First Payment Price is required for variant '{$cname}' when installment payment is enabled.";
        } elseif ((float)$cFirstMonthPrice <= 0) {
          $errors[] = "First Payment Price for variant '{$cname}' must be greater than 0.";
        } elseif ((float)$cFirstMonthPrice >= $variantFullPrice) {
          $errors[] = "First Payment Price for variant '{$cname}' must be less than the variant full price.";
        } elseif ($installmentCount !== null && $installmentCount > 0) {
          $calculatedCatRemaining = ($variantFullPrice - (float)$cFirstMonthPrice) / $installmentCount;

          if ($calculatedCatRemaining <= 0) {
            $errors[] = "Calculated remaining installment price for variant '{$cname}' is invalid.";
          } else {
            $cRemainingMonthPrice = money($calculatedCatRemaining);
          }
        }

        if ($cRetentionPrice !== null) {
          if ((float)$cRetentionPrice <= 0) {
            $errors[] = "Retention Offer Price for variant '{$cname}' must be greater than 0.";
          } elseif ($cRetentionFirstMonthPrice === null) {
            $errors[] = "Retention First Payment Price is required for variant '{$cname}' when installment payment is enabled and Retention Offer Price is set.";
          } elseif ((float)$cRetentionFirstMonthPrice <= 0) {
            $errors[] = "Retention First Payment Price for variant '{$cname}' must be greater than 0.";
          } elseif ((float)$cRetentionFirstMonthPrice >= (float)$cRetentionPrice) {
            $errors[] = "Retention First Payment Price for variant '{$cname}' must be less than the Retention Offer Price.";
          }
        } else {
          $cRetentionFirstMonthPrice = null;
        }
      } else {
        $cRetentionFirstMonthPrice = null;
      }

      $order = count($categories);

      $categories[] = [
        "id" => $cid,
        "name" => $cname,
        "priceModifier" => money($cprice),
        "sortOrder" => $order,
        "variantType" => $ctype,
        "elearningCourseId" => $ccourseId,
        "isSubscription" => $cIsSubscription,
        "durationValue" => $cDurationValue,
        "durationUnit" => $cDurationUnit,
        "firstMonthPrice" => $cFirstMonthPrice,
        "remainingMonthPrice" => $cRemainingMonthPrice,
        "retentionPrice" => $cRetentionPrice,
        "retentionFirstMonthPrice" => $cRetentionFirstMonthPrice,
      ];
    }
  }

  // ✅ Poster upload (optional)
  if (isset($_FILES["poster"]) && $_FILES["poster"]["error"] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES["poster"]["error"] !== UPLOAD_ERR_OK) {
      $errors[] = "Poster upload failed. Please try again.";
    } else {
      $tmp  = $_FILES["poster"]["tmp_name"];
      $size = (int)($_FILES["poster"]["size"] ?? 0);

      if ($size > 5 * 1024 * 1024) {
        $errors[] = "Poster is too large. Maximum size is 5MB.";
      } else {
        $mime = @mime_content_type($tmp) ?: "";
        $allowed = [
          "image/jpeg" => "jpg",
          "image/png"  => "png",
          "image/webp" => "webp",
        ];

        if (!isset($allowed[$mime])) {
          $errors[] = "Poster must be JPG, PNG, or WEBP only.";
        } else {
          $ext = $allowed[$mime];
          $fileName = "poster_" . bin2hex(random_bytes(8)) . "." . $ext;
          $dest = $postersDir . "/" . $fileName;

          if (!@move_uploaded_file($tmp, $dest)) {
            $errors[] = "Failed to save the poster. Please try again.";
          } else {
            // URL path (ikut struktur /payment/storage/posters)
            $poster = "/payment/storage/posters/" . $fileName;
          }
        }
      }
    }
  }

  // ✅ Save if no error
  if (!$errors) {
    // membership_types simpan JSON (recommended)
    $membershipJson = json_encode(array_values($membershipTypes), JSON_UNESCAPED_SLASHES);

    // has_categories to int for DB
    $hasCatsDb = $hasCategories ? 1 : 0;

    $conn->begin_transaction();

    try {
      // ---------- UPSERT Products ----------
      if ($productId !== "") {
        // UPDATE
        $fields = ["name = ?", "base_price = ?", "status = ?"];
        $types  = "sds";
        $params = [$name, $basePrice, $status];

        if ($hasElearningCourseCol) {
          $fields[] = "elearning_course_id = ?";
          $types .= "s";
          $params[] = $selectedCourseId;
        }

        if ($hasDescCol) {
          $fields[] = "description = ?";
          $types .= "s";
          $params[] = $description;
        }
        if ($hasPosterCol) {
          $fields[] = "poster = ?";
          $types .= "s";
          $params[] = $poster;
        }
        if ($hasMembershipCol) {
          $fields[] = "membership_types = ?";
          $types .= "s";
          $params[] = $membershipJson;
        }
        if ($hasHasCatsCol) {
          $fields[] = "has_categories = ?";
          $types .= "i";
          $params[] = $hasCatsDb;
        }
        if ($hasProdIsSubscriptionCol) {
          $fields[] = "is_subscription = ?";
          $types .= "i";
          $params[] = $isSubscription;
        }
        if ($hasProdDurationValueCol) {
          $fields[] = "duration_value = ?";
          $types .= "s";
          $params[] = $durationValue === null ? null : (string)$durationValue;
        }
        if ($hasProdDurationUnitCol) {
          $fields[] = "duration_unit = ?";
          $types .= "s";
          $params[] = $durationUnit === "" ? null : $durationUnit;
        }
        if ($hasProdFirstMonthPriceCol) {
          $fields[] = "first_month_price = ?";
          $types .= "s";
          $params[] = $firstMonthPrice;
        }
        if ($hasProdRemainPriceCol) {
          $fields[] = "remaining_month_price = ?";
          $types .= "s";
          $params[] = $remainingMonthPrice;
        }
        if ($hasProdRetentionPriceCol) {
          $fields[] = "retention_price = ?";
          $types .= "s";
          $params[] = $retentionPrice;
        }
        if ($hasProdRetentionFirstPriceCol) {
          $fields[] = "retention_first_month_price = ?";
          $types .= "s";
          $params[] = $retentionFirstMonthPrice;
        }
        if ($hasProdAllowInstallmentCol) {
          $fields[] = "allow_installment = ?";
          $types .= "i";
          $params[] = $allowInstallment;
        }
        if ($hasProdInstallmentCountCol) {
          $fields[] = "installment_count = ?";
          $types .= "s";
          $params[] = ($installmentCount === null ? null : (string)$installmentCount);
        }
        if ($hasProdInstallmentIntervalCol) {
          $fields[] = "installment_interval_unit = ?";
          $types .= "s";
          $params[] = $installmentIntervalUnit;
        }

        $sqlU = "UPDATE `Products` SET " . implode(", ", $fields) . " WHERE id = ? LIMIT 1";
        $types .= "s";
        $params[] = $productId;

        $stmt = $conn->prepare($sqlU);
        if (!$stmt) throw new RuntimeException("Prepare UPDATE Products failed: " . $conn->error);
        bindParams($stmt, $types, $params);
        $stmt->execute();
        $stmt->close();

        $pidForCats = $productId;
      } else {
        // INSERT
        $cols = ["name", "base_price", "status"];
        $qs   = ["?", "?", "?"];
        $types = "sds";
        $params = [$name, $basePrice, $status];

        if ($hasElearningCourseCol) {
          $cols[] = "elearning_course_id";
          $qs[] = "?";
          $types .= "s";
          $params[] = $selectedCourseId;
        }

        if ($hasDescCol) {
          $cols[] = "description";
          $qs[] = "?";
          $types .= "s";
          $params[] = $description;
        }
        if ($hasPosterCol) {
          $cols[] = "poster";
          $qs[] = "?";
          $types .= "s";
          $params[] = $poster;
        }
        if ($hasMembershipCol) {
          $cols[] = "membership_types";
          $qs[] = "?";
          $types .= "s";
          $params[] = $membershipJson;
        }
        if ($hasHasCatsCol) {
          $cols[] = "has_categories";
          $qs[] = "?";
          $types .= "i";
          $params[] = $hasCatsDb;
        }
        if ($hasProdIsSubscriptionCol) {
          $cols[] = "is_subscription";
          $qs[] = "?";
          $types .= "i";
          $params[] = $isSubscription;
        }
        if ($hasProdDurationValueCol) {
          $cols[] = "duration_value";
          $qs[] = "?";
          $types .= "s";
          $params[] = $durationValue === null ? null : (string)$durationValue;
        }
        if ($hasProdDurationUnitCol) {
          $cols[] = "duration_unit";
          $qs[] = "?";
          $types .= "s";
          $params[] = $durationUnit === "" ? null : $durationUnit;
        }
        if ($hasProdFirstMonthPriceCol) {
          $cols[] = "first_month_price";
          $qs[] = "?";
          $types .= "s";
          $params[] = $firstMonthPrice;
        }
        if ($hasProdRemainPriceCol) {
          $cols[] = "remaining_month_price";
          $qs[] = "?";
          $types .= "s";
          $params[] = $remainingMonthPrice;
        }
        if ($hasProdRetentionPriceCol) {
          $cols[] = "retention_price";
          $qs[] = "?";
          $types .= "s";
          $params[] = $retentionPrice;
        }
        if ($hasProdRetentionFirstPriceCol) {
          $cols[] = "retention_first_month_price";
          $qs[] = "?";
          $types .= "s";
          $params[] = $retentionFirstMonthPrice;
        }
        if ($hasProdAllowInstallmentCol) {
          $cols[] = "allow_installment";
          $qs[] = "?";
          $types .= "i";
          $params[] = $allowInstallment;
        }
        if ($hasProdInstallmentCountCol) {
          $cols[] = "installment_count";
          $qs[] = "?";
          $types .= "s";
          $params[] = ($installmentCount === null ? null : (string)$installmentCount);
        }
        if ($hasProdInstallmentIntervalCol) {
          $cols[] = "installment_interval_unit";
          $qs[] = "?";
          $types .= "s";
          $params[] = $installmentIntervalUnit;
        }

        // kalau id bukan auto increment & kau nak kekalkan style "p_xxx"
        if (!$productIdAuto) {
          $newId = "p_" . bin2hex(random_bytes(4));
          $cols[] = "id";
          $qs[] = "?";
          $types .= "s";
          $params[] = $newId;
        }

        $sqlI = "INSERT INTO `Products` (`" . implode("`,`", $cols) . "`) VALUES (" . implode(",", $qs) . ")";
        $stmt = $conn->prepare($sqlI);
        if (!$stmt) throw new RuntimeException("Prepare INSERT Products failed: " . $conn->error);
        bindParams($stmt, $types, $params);
        $stmt->execute();
        $stmt->close();

        $pidForCats = $productIdAuto ? (string)$conn->insert_id : $newId;
      }

      // ---------- Sync categories ----------
      // delete all then insert current (simple & clean)
      $stmtD = $conn->prepare("DELETE FROM `Product_Categories` WHERE product_id = ?");
      if (!$stmtD) throw new RuntimeException("Prepare DELETE cats failed: " . $conn->error);
      $stmtD->bind_param("s", $pidForCats);
      $stmtD->execute();
      $stmtD->close();

      if ($hasCategories && !empty($categories)) {
        if ($catIdAuto) {
          $catCols = ["product_id", "sort_order", "name", "price_modifier"];
          if ($hasCatVariantTypeCol) $catCols[] = "variant_type";
          if ($hasCatElearningCourseCol) $catCols[] = "elearning_course_id";
          if ($hasCatIsSubscriptionCol) $catCols[] = "is_subscription";
          if ($hasCatDurationValueCol) $catCols[] = "duration_value";
          if ($hasCatDurationUnitCol) $catCols[] = "duration_unit";
          if ($hasCatFirstMonthPriceCol) $catCols[] = "first_month_price";
          if ($hasCatRemainPriceCol) $catCols[] = "remaining_month_price";
          if ($hasCatRetentionPriceCol) $catCols[] = "retention_price";
          if ($hasCatRetentionFirstPriceCol) $catCols[] = "retention_first_month_price";

          $stmtC = $conn->prepare("
            INSERT INTO `Product_Categories` (`" . implode("`,`", $catCols) . "`)
            VALUES (" . implode(",", array_fill(0, count($catCols), "?")) . ")
          ");
          if (!$stmtC) throw new RuntimeException("Prepare INSERT Product_Categories failed: " . $conn->error);

          foreach ($categories as $c) {
            $corder = (int)$c["sortOrder"];
            $cname  = (string)$c["name"];
            $cmod   = (float)$c["priceModifier"];
            $ctype  = (string)$c["variantType"];
            $ccid   = (string)$c["elearningCourseId"];
            $csub   = $c["isSubscription"];
            $cdurv  = $c["durationValue"];
            $cduru  = (string)$c["durationUnit"];
            $cfirst = $c["firstMonthPrice"];
            $cremain = $c["remainingMonthPrice"] ?? null;
            $cret   = $c["retentionPrice"];
            $cretFirst = $c["retentionFirstMonthPrice"] ?? null;

            $catTypes = "sisd";
            $catParams = [$pidForCats, $corder, $cname, $cmod];
            if ($hasCatVariantTypeCol) {
              $catTypes .= "s";
              $catParams[] = $ctype;
            }
            if ($hasCatElearningCourseCol) {
              $catTypes .= "s";
              $catParams[] = $ccid;
            }
            if ($hasCatIsSubscriptionCol) {
              $catTypes .= "s";
              $catParams[] = ($csub === null ? null : (string)$csub);
            }
            if ($hasCatDurationValueCol) {
              $catTypes .= "s";
              $catParams[] = ($cdurv === null ? null : (string)$cdurv);
            }
            if ($hasCatDurationUnitCol) {
              $catTypes .= "s";
              $catParams[] = ($cduru === "" ? null : $cduru);
            }
            if ($hasCatFirstMonthPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cfirst;
            }
            if ($hasCatRemainPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cremain;
            }
            if ($hasCatRetentionPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cret;
            }
            if ($hasCatRetentionFirstPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cretFirst;
            }

            bindParams($stmtC, $catTypes, $catParams);

            $stmtC->execute();
          }
          $stmtC->close();
        } else {
          $catCols = ["id", "product_id", "sort_order", "name", "price_modifier"];
          if ($hasCatVariantTypeCol) $catCols[] = "variant_type";
          if ($hasCatElearningCourseCol) $catCols[] = "elearning_course_id";
          if ($hasCatIsSubscriptionCol) $catCols[] = "is_subscription";
          if ($hasCatDurationValueCol) $catCols[] = "duration_value";
          if ($hasCatDurationUnitCol) $catCols[] = "duration_unit";
          if ($hasCatFirstMonthPriceCol) $catCols[] = "first_month_price";
          if ($hasCatRemainPriceCol) $catCols[] = "remaining_month_price";
          if ($hasCatRetentionPriceCol) $catCols[] = "retention_price";
          if ($hasCatRetentionFirstPriceCol) $catCols[] = "retention_first_month_price";

          $stmtC = $conn->prepare("
            INSERT INTO `Product_Categories` (`" . implode("`,`", $catCols) . "`)
            VALUES (" . implode(",", array_fill(0, count($catCols), "?")) . ")
          ");
          if (!$stmtC) throw new RuntimeException("Prepare INSERT Product_Categories failed: " . $conn->error);

          foreach ($categories as $c) {
            $cid    = (string)$c["id"];
            $corder = (int)$c["sortOrder"];
            $cname  = (string)$c["name"];
            $cmod   = (float)$c["priceModifier"];
            $ctype  = (string)$c["variantType"];
            $ccid   = (string)$c["elearningCourseId"];
            $csub   = $c["isSubscription"];
            $cdurv  = $c["durationValue"];
            $cduru  = (string)$c["durationUnit"];
            $cfirst = $c["firstMonthPrice"];
            $cremain = $c["remainingMonthPrice"] ?? null;
            $cret   = $c["retentionPrice"];
            $cretFirst = $c["retentionFirstMonthPrice"] ?? null;

            $catTypes = "ssisd";
            $catParams = [$cid, $pidForCats, $corder, $cname, $cmod];
            if ($hasCatVariantTypeCol) {
              $catTypes .= "s";
              $catParams[] = $ctype;
            }
            if ($hasCatElearningCourseCol) {
              $catTypes .= "s";
              $catParams[] = $ccid;
            }
            if ($hasCatIsSubscriptionCol) {
              $catTypes .= "s";
              $catParams[] = ($csub === null ? null : (string)$csub);
            }
            if ($hasCatDurationValueCol) {
              $catTypes .= "s";
              $catParams[] = ($cdurv === null ? null : (string)$cdurv);
            }
            if ($hasCatDurationUnitCol) {
              $catTypes .= "s";
              $catParams[] = ($cduru === "" ? null : $cduru);
            }
            if ($hasCatFirstMonthPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cfirst;
            }
            if ($hasCatRemainPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cremain;
            }
            if ($hasCatRetentionPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cret;
            }
            if ($hasCatRetentionFirstPriceCol) {
              $catTypes .= "s";
              $catParams[] = $cretFirst;
            }

            bindParams($stmtC, $catTypes, $catParams);

            $stmtC->execute();
          }
          $stmtC->close();
        }
      }

      $conn->commit();

      header("Location: /admin/payment/admin-products.php?ts=" . time(), true, 303);
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      error_log("product-form save failed: " . $e->getMessage());
      $errors[] = "Save failed. Please check error_log.";
    }
  }
}

// ---- form defaults ----
$formId = (string)($initial["id"] ?? "");
$formName = (string)($initial["name"] ?? "");
$formElearningCourseId = $hasElearningCourseCol ? (string)($initial["elearning_course_id"] ?? "") : "";
$formDesc = $hasDescCol ? (string)($initial["description"] ?? "") : "";
$formBase = (string)($initial["base_price"] ?? "0.00");
$formStatus = (string)($initial["status"] ?? "active");
$formHasCats = $hasHasCatsCol
  ? (bool)($initial["has_categories"] ?? false)
  : (count($formCats) > 0);
$formPoster = $hasPosterCol ? (string)($initial["poster"] ?? "") : "";
$formIsSubscription = $hasProdIsSubscriptionCol ? ((int)($initial["is_subscription"] ?? 0) === 1) : false;
$formDurationValue = $hasProdDurationValueCol ? (string)($initial["duration_value"] ?? "") : "";
$formDurationUnit = $hasProdDurationUnitCol ? (string)($initial["duration_unit"] ?? "") : "";
$formFirstMonthPrice = $hasProdFirstMonthPriceCol ? (string)($initial["first_month_price"] ?? "") : "";
$formRetentionPrice = $hasProdRetentionPriceCol ? (string)($initial["retention_price"] ?? "") : "";
$formRetentionFirstMonthPrice = $hasProdRetentionFirstPriceCol ? (string)($initial["retention_first_month_price"] ?? "") : "";
$formAllowInstallment = $hasProdAllowInstallmentCol ? ((int)($initial["allow_installment"] ?? 0) === 1) : false;
$formInstallmentCount = $hasProdInstallmentCountCol ? (string)($initial["installment_count"] ?? "") : "";
$formInstallmentIntervalUnit = $hasProdInstallmentIntervalCol ? (string)($initial["installment_interval_unit"] ?? "month") : "month";

$formMembershipTypes = [];
$mtRaw = (string)($initial["membership_types"] ?? "");
if ($mtRaw !== "") {
  $mtJson = json_decode($mtRaw, true);
  if (is_array($mtJson)) {
    $formMembershipTypes = $mtJson;
  } else {
    $formMembershipTypes = preg_split('/\s*,\s*/', $mtRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }
}
$formMembershipTypes = array_values(array_unique(array_map("strtoupper", $formMembershipTypes)));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $formId = $productId;
  $formName = $name;
  $formElearningCourseId = $selectedCourseId;
  $formDesc = $description;
  $formBase = money($basePrice);
  $formStatus = $status;
  $formHasCats = (bool)$hasCategories;
  $formCats = $categories;
  $formPoster = $poster;
  $formMembershipTypes = array_values(array_unique(array_map("strtoupper", $membershipTypes)));
  $formIsSubscription = ($isSubscription === 1);
  $formDurationValue = $durationValue === null ? "" : (string)$durationValue;
  $formDurationUnit = $durationUnit;
  $formFirstMonthPrice = $firstMonthPrice ?? "";
  $formRetentionPrice = $retentionPrice ?? "";
  $formRetentionFirstMonthPrice = $retentionFirstMonthPrice ?? "";
  $formAllowInstallment = ($allowInstallment === 1);
  $formInstallmentCount = ($installmentCount === null) ? "" : (string)$installmentCount;
  $formInstallmentIntervalUnit = $installmentIntervalUnit;
}

$formIsDemo = in_array("DEMO", $formMembershipTypes, true);
$formIsGm = in_array("GM", $formMembershipTypes, true);

// ---- header vars (layout standard) ----
$editing  = ($formId !== "");
$pageTitle = $editing ? "Edit Product" : "Add Product";
$pageDesc  = "Create products, variants, initial installments, and retention continuation offers.";

$backUrl   = "/admin/payment/admin-products.php";
$backLabel = "Back to Products";

$headerActionsHtmlDesktop = '
<a href="' . h($backUrl) . '"
   class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
  <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
  </svg>
  ' . $backLabel . '
</a>
';

$title = $pageTitle;

include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="pb-10">
  <div class="max-w-4xl mx-auto">
    <div class="md:hidden mb-8">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">
            <?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, "UTF-8") ?>
          </h1>

          <?php if (trim((string)$pageDesc) !== ""): ?>
            <p class="mt-2 text-sm font-semibold text-slate-500 break-words">
              <?= htmlspecialchars((string)$pageDesc, ENT_QUOTES, "UTF-8") ?>
            </p>
          <?php endif; ?>
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
          <span>Back</span>
        </a>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 p-4 rounded-xl border border-red-200 bg-red-50 text-red-700 text-sm">
        <div class="font-bold mb-1">Please fix the following:</div>
        <ul class="list-disc ml-5">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border p-5 sm:p-8 w-full">
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800"><?= $editing ? "Edit Product" : "Add New Product" ?></h2>
      </div>

      <form method="POST" enctype="multipart/form-data" class="space-y-6" id="productForm">
        <?php if (function_exists("csrf_token")): ?>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <?php endif; ?>

        <input type="hidden" name="product_id" value="<?= h($formId) ?>" />

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">e-Learning Product</label>
          <select
            name="elearning_course_id"
            id="elearningCourseSelect"
            class="w-full px-4 py-2 border rounded-xl bg-white outline-none focus:ring-2 focus:ring-yellow-400">
            <option value="">-- None / Normal Product --</option>

            <?php foreach ($groupedCourses as $groupLabel => $courses): ?>
              <?php if (empty($courses)) continue; ?>
              <optgroup label="<?= h($groupLabel) ?>">
                <?php foreach ($courses as $course): ?>
                  <?php
                  $cid = trim((string)($course['id'] ?? ''));
                  if ($cid === '') continue;

                  $title = trim((string)($course['title'] ?? $cid));
                  $label = $title;
                  ?>
                  <option
                    value="<?= h($cid) ?>"
                    data-title="<?= h($title) ?>"
                    <?= $formElearningCourseId === $cid ? "selected" : "" ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>

          <p class="text-xs text-gray-500 mt-2">
            If Product Name is left blank, the system will use the selected e-Learning product name.
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
          <input
            type="text"
            name="name"
            value="<?= h($formName) ?>"
            class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none transition-all"
            placeholder="e.g. Demo VIP Access" />
        </div>

        <div>
          <div class="flex justify-between items-center mb-1">
            <label class="block text-sm font-medium text-gray-700">Description</label>
          </div>

          <!-- initial value (safe in textarea, browser will decode entities) -->
          <textarea id="descInitial" class="hidden"><?= h((string)$formDesc) ?></textarea>

          <!-- actual field submitted -->
          <input type="hidden" name="description" id="descHtml" value="" />

          <div class="border rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400">
            <div id="descToolbar" class="border-b bg-slate-50">
              <span class="ql-formats">
                <button class="ql-bold"></button>
                <button class="ql-italic"></button>
                <button class="ql-underline"></button>
              </span>
              <span class="ql-formats">
                <button class="ql-list" value="ordered"></button>
                <button class="ql-list" value="bullet"></button>
              </span>
            </div>
            <div id="descEditor" class="min-h-[160px]"></div>
          </div>
        </div>

        <!-- ✅ Poster Upload + A4 Preview -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Poster (A4)</label>

          <input
            type="file"
            name="poster"
            accept="image/*"
            class="w-full px-4 py-2 border rounded-xl bg-white focus:ring-2 focus:ring-yellow-400 outline-none" />

          <input type="hidden" name="existing_poster" value="<?= h($formPoster) ?>">

          <p class="text-xs text-gray-500 mt-2">
            Recommended A4 portrait (e.g., 2480x3508). The preview below does not crop the image.
          </p>

          <?php if ($formPoster !== ""): ?>
            <div class="mt-4 w-full max-w-sm aspect-[210/297] rounded-2xl overflow-hidden border bg-slate-50">
              <img src="<?= h($formPoster) ?>" alt="Poster Preview" class="w-full h-full object-contain" />
            </div>
          <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Base Price (RM)
              <p class="inline mt-2 text-[11px] text-slate-400 font-medium"> *excluding SST</p>
            </label>
            <input
              type="number"
              step="0.01"
              min="0"
              name="basePrice"
              required
              value="<?= h($formBase) ?>"
              class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
            <select disabled class="w-full px-4 py-2 border rounded-xl bg-gray-50 text-gray-500 outline-none">
              <option>MYR (RM)</option>
            </select>
          </div>
        </div>

        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 sm:p-6">
          <div class="flex items-center space-x-2 mb-3">
            <input
              type="checkbox"
              id="isSubscription"
              name="is_subscription"
              <?= $formIsSubscription ? "checked" : "" ?>
              class="w-4 h-4 text-yellow-500 border-gray-300 rounded focus:ring-yellow-400" />
            <label for="isSubscription" class="text-sm font-semibold text-gray-800">
              Subscription Based
            </label>
          </div>

          <div id="subscriptionFields" class="<?= $formIsSubscription ? "" : "hidden" ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Duration Value</label>
                <input
                  type="number"
                  min="1"
                  step="1"
                  name="duration_value"
                  value="<?= h($formDurationValue) ?>"
                  class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none" />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Duration Unit</label>
                <select
                  name="duration_unit"
                  class="w-full px-4 py-2 border rounded-xl bg-white outline-none focus:ring-2 focus:ring-yellow-400">
                  <option value="">-- Select --</option>
                  <option value="day" <?= $formDurationUnit === "day" ? "selected" : "" ?>>day</option>
                  <option value="month" <?= $formDurationUnit === "month" ? "selected" : "" ?>>month</option>
                </select>
              </div>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
              <div class="mb-4">
                <div class="text-sm font-black text-slate-900 uppercase tracking-wide">Pricing Flow</div>
                <p class="mt-1 text-xs text-slate-500">Set the full joining price, the upfront installment amount, and the continuation offer separately.</p>
              </div>

              <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Initial Installment</div>
                  <label class="block text-sm font-bold text-gray-700 mb-1">First Payment Price (RM)</label>
                  <p class="text-xs text-gray-500 mb-2">Amount customer pays today when choosing installment for the first purchase.</p>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="first_month_price"
                    value="<?= h($formFirstMonthPrice) ?>"
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none bg-white" />
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                  <div class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-2">Retention Offer</div>
                  <label class="block text-sm font-bold text-gray-700 mb-1">Retention Offer Price (RM)</label>
                  <p class="text-xs text-gray-500 mb-2">Total continuation price shown to existing customers near expiry.</p>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="retention_price"
                    value="<?= h($formRetentionPrice) ?>"
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none bg-white" />
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                  <div class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-2">Retention Installment</div>
                  <label class="block text-sm font-bold text-gray-700 mb-1">Retention First Payment (RM)</label>
                  <p class="text-xs text-gray-500 mb-2">Amount customer pays first if the retention offer is paid by installment.</p>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="retention_first_month_price"
                    value="<?= h($formRetentionFirstMonthPrice) ?>"
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-yellow-400 outline-none bg-white" />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment Options Configuration -->
        <section class="bg-gradient-to-br from-slate-50 to-white p-6 sm:p-8 rounded-2xl border-2 border-slate-200 my-8" id="paymentOptionsSection">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
            <div>
              <h3 class="text-xl font-black text-slate-900">Payment Options</h3>
              <p class="mt-1 text-sm text-slate-500">Full payment is always available. Tick installment only when you want to split initial or retention payments.</p>
            </div>
          </div>

          <div class="space-y-5">
            <label for="allowInstallment" class="flex cursor-pointer items-start gap-4 rounded-2xl border border-slate-200 bg-white p-4 hover:border-yellow-300 hover:bg-yellow-50/40 transition-all">
              <input
                type="checkbox"
                id="allowInstallment"
                name="allow_installment"
                class="mt-1 h-5 w-5 text-yellow-500 border-gray-300 rounded focus:ring-yellow-400"
                <?= $formAllowInstallment ? "checked" : "" ?> />
              <div class="flex-1">
                <div class="font-black text-slate-900">Allow Installment Payment</div>
                <p class="mt-1 text-sm text-slate-600">Customers can pay an upfront amount first, then pay the remaining balance using the schedule below.</p>
              </div>
            </label>

            <div id="installmentConfig" class="<?= !$formAllowInstallment ? "hidden" : "" ?> rounded-2xl border-l-4 border-yellow-500 bg-yellow-50 p-4 sm:p-5">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-bold text-slate-700 mb-2">Number of Remaining Installments</label>
                  <input type="number" name="installment_count" min="1" placeholder="e.g., 5" value="<?= h($formInstallmentCount) ?>" class="w-full px-4 py-2 border rounded-xl bg-white focus:ring-2 focus:ring-yellow-400 outline-none" />
                  <p class="text-xs text-slate-500 mt-1">Example: 5 means customer pays 5 more times after the first payment.</p>
                </div>

                <div>
                  <label class="block text-sm font-bold text-slate-700 mb-2">Installment Interval</label>
                  <select name="installment_interval_unit" class="w-full px-4 py-2 border rounded-xl bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
                    <option value="month" <?= $formInstallmentIntervalUnit === "month" ? "selected" : "" ?>>Monthly</option>
                    <option value="week" <?= $formInstallmentIntervalUnit === "week" ? "selected" : "" ?>>Weekly</option>
                    <option value="day" <?= $formInstallmentIntervalUnit === "day" ? "selected" : "" ?>>Daily</option>
                  </select>
                </div>
              </div>

              <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3 text-xs">
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-blue-800">
                  <strong>Initial example:</strong> Base RM6,000, first payment RM3,000, remaining installments 5 → system calculates RM600 each.
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-800">
                  <strong>Retention example:</strong> Retention offer RM3,800, retention first payment RM1,900, remaining installments 5 → system calculates RM380 each.
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
          <div class="text-sm font-bold text-slate-800">Membership Types (Variant-Based)</div>
          <div class="text-xs text-slate-500 font-medium">
            Enable the membership types this product can generate. The actual code will be determined by the selected variant name.
          </div>

          <div class="mt-3 flex flex-col sm:flex-row gap-3 sm:gap-6">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
              <input
                type="checkbox"
                id="membership_demo"
                name="membership_demo"
                <?= $formIsDemo ? "checked" : "" ?>
                class="w-4 h-4 text-yellow-500 border-gray-300 rounded focus:ring-yellow-400" />
              Demo Membership
            </label>

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
              <input
                type="checkbox"
                id="membership_gm"
                name="membership_gm"
                <?= $formIsGm ? "checked" : "" ?>
                class="w-4 h-4 text-yellow-500 border-gray-300 rounded focus:ring-yellow-400" />
              Golden Membership
            </label>
          </div>

          <p class="mt-2 text-[11px] text-slate-400 font-medium">
            Example variants: <span class="font-mono">Golden Membership</span> → GM, <span class="font-mono">DEMO Community</span> → DEMO.
          </p>
        </div>

        <div class="bg-yellow-50 rounded-2xl p-4 sm:p-6 border border-yellow-100">
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-2">
              <input
                type="checkbox"
                id="hasCategories"
                name="hasCategories"
                <?= $formHasCats ? "checked" : "" ?>
                class="w-4 h-4 text-yellow-500 border-gray-300 rounded focus:ring-yellow-400" />
              <label for="hasCategories" class="text-sm font-semibold text-gray-800">
                Enable product variants/categories
              </label>
            </div>
          </div>

          <div id="catsWrap" class="<?= $formHasCats ? "" : "hidden" ?>">
            <p class="text-xs text-gray-500 mb-2">Use variants for different tiers (VIP / Diamond / General).</p>

            <?php
            $courseOptionsHtml = '<option value="">-- Select e-Learning Course --</option>';
            foreach ($groupedCourses as $groupLabel => $courses) {
              if (empty($courses)) continue;
              $courseOptionsHtml .= '<optgroup label="' . h($groupLabel) . '">';
              foreach ($courses as $course) {
                $cid = trim((string)($course['id'] ?? ''));
                if ($cid === '') continue;
                $title = trim((string)($course['title'] ?? $cid));
                $courseOptionsHtml .= '<option value="' . h($cid) . '">' . h($title) . '</option>';
              }
              $courseOptionsHtml .= '</optgroup>';
            }
            ?>

            <div class="space-y-3" id="catsList">
              <?php foreach ((array)$formCats as $cat): ?>
                <div class="grid grid-cols-1 sm:grid-cols-[1.2fr_9rem_10rem_1.2fr_auto] gap-2 items-start sm:items-center category-row">
                  <input type="hidden" name="cat_id[]" value="<?= h((string)($cat["id"] ?? "")) ?>" />

                  <input
                    type="text"
                    name="cat_name[]"
                    placeholder="Category name"
                    value="<?= h((string)($cat["name"] ?? "")) ?>"
                    class="w-full min-w-0 px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />

                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    inputmode="decimal"
                    name="cat_price[]"
                    placeholder="Price"
                    value="<?= ((float)($cat["priceModifier"] ?? 0) == 0.0) ? "" : h((string)$cat["priceModifier"]) ?>"
                    class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />

                  <select
                    name="cat_type[]"
                    class="cat-type w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
                    <option value="normal" <?= (($cat["variantType"] ?? "normal") === "normal") ? "selected" : "" ?>>Normal</option>
                    <option value="elearning" <?= (($cat["variantType"] ?? "") === "elearning") ? "selected" : "" ?>>e-Learning</option>
                  </select>

                  <select
                    name="cat_course_id[]"
                    class="cat-course w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
                    <?= str_replace(
                      'value="' . h((string)($cat["elearningCourseId"] ?? "")) . '"',
                      'value="' . h((string)($cat["elearningCourseId"] ?? "")) . '" selected',
                      $courseOptionsHtml
                    ) ?>
                  </select>

                  <button
                    type="button"
                    onclick="removeRow(this)"
                    class="sm:justify-self-end justify-self-start p-2 text-red-500 hover:bg-red-50 rounded-lg"
                    title="Remove category"
                    aria-label="Remove category">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>

                  <div class="sm:col-span-5 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Subscription Override</label>
                        <select
                          name="cat_is_subscription[]"
                          class="w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
                          <option value="" <?= (($cat["isSubscription"] ?? null) === null || (string)($cat["isSubscription"] ?? "") === "") ? "selected" : "" ?>>Inherit product</option>
                          <option value="1" <?= (string)($cat["isSubscription"] ?? "") === "1" ? "selected" : "" ?>>Subscription</option>
                          <option value="0" <?= (string)($cat["isSubscription"] ?? "") === "0" ? "selected" : "" ?>>One-off</option>
                        </select>
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Duration Value</label>
                        <input
                          type="number"
                          min="1"
                          step="1"
                          name="cat_duration_value[]"
                          value="<?= h((string)($cat["durationValue"] ?? "")) ?>"
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Duration Unit</label>
                        <select
                          name="cat_duration_unit[]"
                          class="w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
                          <option value="">Inherit</option>
                          <option value="day" <?= ((string)($cat["durationUnit"] ?? "") === "day") ? "selected" : "" ?>>day</option>
                          <option value="month" <?= ((string)($cat["durationUnit"] ?? "") === "month") ? "selected" : "" ?>>month</option>
                        </select>
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">First Payment Price (RM)</label>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          name="cat_first_month_price[]"
                          value="<?= h((string)($cat["firstMonthPrice"] ?? "")) ?>"
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Retention Offer Price (RM)</label>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          name="cat_retention_price[]"
                          value="<?= h((string)($cat["retentionPrice"] ?? "")) ?>"
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Retention First Payment (RM)</label>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          name="cat_retention_first_month_price[]"
                          value="<?= h((string)($cat["retentionFirstMonthPrice"] ?? "")) ?>"
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <button
              type="button"
              onclick="addCategoryRow('', '')"
              class="mt-4 w-full py-2 border-2 border-dashed border-yellow-200 rounded-lg text-yellow-700 hover:border-yellow-400 hover:bg-yellow-100/50 transition-all font-medium text-sm">
              + Add Category
            </button>
          </div>
        </div>

        <?php if ($editing): ?>
          <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 sm:p-6">
            <div class="flex items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="text-sm font-bold text-slate-800">Discount Codes</div>
                <div class="text-xs text-slate-500 font-medium">
                  Manage discount codes for this product. (Leave the timer blank for no expiry. Leave the email blank to apply the code to all users.)
                </div>
              </div>

              <a
                href="/admin/payment/discount-form.php?product_id=<?= h($formId) ?>"
                class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-500 hover:bg-yellow-600 text-black font-bold">
                Manage
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 sm:p-6">
            <div class="text-sm font-bold text-slate-800">Discount Codes</div>
            <div class="text-xs text-slate-500 font-medium">
              Create the product first. Once a Product ID is generated, you can create discount codes for it.
            </div>
          </div>
        <?php endif; ?>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select name="status" class="w-full px-4 py-2 border rounded-xl bg-white outline-none focus:ring-2 focus:ring-yellow-400">
            <option value="active" <?= $formStatus === "active" ? "selected" : "" ?>>active</option>
            <option value="inactive" <?= $formStatus === "inactive" ? "selected" : "" ?>>inactive</option>
          </select>
        </div>

        <div class="flex space-x-3 pt-4 border-t">
          <button
            type="submit"
            class="flex-1 bg-yellow-500 text-black py-3 rounded-xl font-bold hover:bg-yellow-600 transition-colors shadow-lg shadow-yellow-100">
            <?= $editing ? "Update Product" : "Create Product" ?>
          </button>

          <a href="/admin/payment/admin-products.php"
            class="px-6 py-3 border rounded-xl font-semibold text-gray-600 hover:bg-gray-50 transition-colors text-center">
            Discard
          </a>
        </div>

      </form>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>

<script>
  (() => {
    const hasCategoriesEl = document.getElementById("hasCategories");
    const catsWrap = document.getElementById("catsWrap");
    const catsList = document.getElementById("catsList");

    const escHtml = (s) => (s ?? "").toString().replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    } [m]));

    function toggleCats() {
      const on = !!hasCategoriesEl?.checked;
      catsWrap?.classList.toggle("hidden", !on);
    }

    function randomId() {
      return "c_" + Math.random().toString(36).slice(2, 10);
    }

    function removeRow(btn) {
      btn?.closest(".category-row")?.remove();
    }

    const courseOptionsHtml = <?= json_encode($courseOptionsHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function syncCategoryRow(row) {
      const typeEl = row.querySelector('.cat-type');
      const courseEl = row.querySelector('.cat-course');
      if (!typeEl || !courseEl) return;

      const isElearning = typeEl.value === 'elearning';
      courseEl.disabled = !isElearning;
      courseEl.classList.toggle('bg-slate-100', !isElearning);
      courseEl.classList.toggle('cursor-not-allowed', !isElearning);

      if (!isElearning) {
        courseEl.value = '';
      }
    }

    function addCategoryRow(
      name = '', price = '', type = 'normal', courseId = '',
      isSub = '', durationValue = '', durationUnit = '', firstMonth = '', retentionPrice = '', retentionFirstMonth = ''
    ) {
      const row = document.createElement("div");
      row.className = "grid grid-cols-1 sm:grid-cols-[1.2fr_9rem_10rem_1.2fr_auto] gap-2 items-start sm:items-center category-row";

      row.innerHTML = `
      <input type="hidden" name="cat_id[]" value="${randomId()}" />

      <input type="text" name="cat_name[]" placeholder="Category name"
        value="${escHtml(name)}"
        class="w-full min-w-0 px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />

      <input type="number" step="0.01" min="0" inputmode="decimal"
        name="cat_price[]" placeholder="Price"
        value="${escHtml((price === "0" || price === "0.00" || price === 0) ? "" : price)}"
        class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />

      <select name="cat_type[]" class="cat-type w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
        <option value="normal" ${type === 'normal' ? 'selected' : ''}>Normal</option>
        <option value="elearning" ${type === 'elearning' ? 'selected' : ''}>e-Learning</option>
      </select>

      <select name="cat_course_id[]" class="cat-course w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
        ${courseOptionsHtml}
      </select>

      <button type="button"
        class="sm:justify-self-end justify-self-start p-2 text-red-500 hover:bg-red-50 rounded-lg"
        title="Remove category" aria-label="Remove category">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
        </svg>
      </button>

      <div class="sm:col-span-5 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Subscription Override</label>
            <select name="cat_is_subscription[]" class="w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
              <option value="" ${isSub === '' ? 'selected' : ''}>Inherit product</option>
              <option value="1" ${isSub === '1' ? 'selected' : ''}>Subscription</option>
              <option value="0" ${isSub === '0' ? 'selected' : ''}>One-off</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Duration Value</label>
            <input type="number" min="1" step="1" name="cat_duration_value[]"
              value="${escHtml(durationValue)}"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Duration Unit</label>
            <select name="cat_duration_unit[]" class="w-full px-3 py-2 border rounded-lg bg-white focus:ring-2 focus:ring-yellow-400 outline-none">
              <option value="" ${durationUnit === '' ? 'selected' : ''}>Inherit</option>
              <option value="day" ${durationUnit === 'day' ? 'selected' : ''}>day</option>
              <option value="month" ${durationUnit === 'month' ? 'selected' : ''}>month</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">First Payment Price (RM)</label>
            <input type="number" step="0.01" min="0" name="cat_first_month_price[]"
              value="${escHtml(firstMonth)}"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Retention Offer Price (RM)</label>
            <input type="number" step="0.01" min="0" name="cat_retention_price[]"
              value="${escHtml(retentionPrice)}"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Retention First Payment (RM)</label>
            <input type="number" step="0.01" min="0" name="cat_retention_first_month_price[]"
              value="${escHtml(retentionFirstMonth)}"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" />
          </div>
        </div>
      </div>
    `;

      row.querySelector(".cat-course").value = courseId || "";
      row.querySelector("button")?.addEventListener("click", () => removeRow(row.querySelector("button")));
      row.querySelector(".cat-type")?.addEventListener("change", () => syncCategoryRow(row));

      catsList?.appendChild(row);
      syncCategoryRow(row);
    }

    // expose to inline handlers kalau kau nak kekalkan
    window.removeRow = removeRow;
    window.addCategoryRow = addCategoryRow;
    window.syncCategoryRow = syncCategoryRow;

    hasCategoriesEl?.addEventListener("change", toggleCats);

    toggleCats();
  })();

  (() => {
    const isSubscriptionEl = document.getElementById("isSubscription");
    const subscriptionFieldsEl = document.getElementById("subscriptionFields");
    const paymentOptionsSection = document.getElementById("paymentOptionsSection");
    const durationValueEl = document.querySelector('[name="duration_value"]');
    const durationUnitEl = document.querySelector('[name="duration_unit"]');
    const firstMonthEl = document.querySelector('[name="first_month_price"]');
    const retentionEl = document.querySelector('[name="retention_price"]');
    const retentionFirstEl = document.querySelector('[name="retention_first_month_price"]');

    if (!isSubscriptionEl || !subscriptionFieldsEl) return;

    function toggleSubscriptionFields() {
      const on = !!isSubscriptionEl.checked;
      const allowInstallmentEl = document.getElementById("allowInstallment");
      const installmentConfigEl = document.getElementById("installmentConfig");
      const installmentOn = !!allowInstallmentEl?.checked;

      // Subscription fields must only open when admin manually ticks Subscription Based.
      // Allow Installment Payment must not open this section.
      subscriptionFieldsEl.classList.toggle("hidden", !on);

      // Payment Options should remain visible in create/edit mode.
      paymentOptionsSection?.classList.remove("hidden");

      // Product-level subscription fields are only for subscription products.
      [durationValueEl, durationUnitEl, firstMonthEl, retentionEl, retentionFirstEl].forEach((el) => {
        if (!el) return;
        el.disabled = !on;
      });

      if (durationValueEl) durationValueEl.required = on;
      if (durationUnitEl) durationUnitEl.required = on;

      // First payment price for installment should use category/variant input when variants are enabled.
      if (firstMonthEl) firstMonthEl.required = false;

      if (installmentConfigEl) {
        installmentConfigEl.classList.toggle("hidden", !installmentOn);
      }
    }

    isSubscriptionEl.addEventListener("change", toggleSubscriptionFields);
    toggleSubscriptionFields();
  })();

  // --- Quill (Description editor) ---
  const descHtml = document.getElementById("descHtml");
  const descInitial = document.getElementById("descInitial");

  const quill = new Quill("#descEditor", {
    theme: "snow",
    modules: {
      toolbar: "#descToolbar"
    }
  });

  (function initDesc() {
    const initVal = (descInitial?.value || "").trim();

    // If looks like HTML, paste HTML. Else treat as plain text.
    if (initVal && /<\/?(p|br|strong|em|u|ul|ol|li)\b/i.test(initVal)) {
      quill.clipboard.dangerouslyPasteHTML(initVal);
    } else if (initVal) {
      quill.setText(initVal);
    }

    document.querySelectorAll('.category-row').forEach((row) => {
      row.querySelector('.cat-type')?.addEventListener('change', () => window.syncCategoryRow?.(row));
      window.syncCategoryRow?.(row);
    });

    syncDesc();
  })();

  function syncDesc() {
    const plain = quill.getText().trim();
    descHtml.value = plain ? quill.root.innerHTML : "";
  }

  quill.on("text-change", syncDesc);
  document.getElementById("productForm")?.addEventListener("submit", syncDesc);

  (() => {
    const courseSelect = document.getElementById("elearningCourseSelect");
    const nameInput = document.querySelector('input[name="name"]');
    const hasCategoriesEl = document.getElementById("hasCategories");
    const catsWrap = document.getElementById("catsWrap");

    if (!courseSelect || !nameInput || !hasCategoriesEl || !catsWrap) return;

    let nameTouched = nameInput.value.trim() !== "";

    nameInput.addEventListener("input", () => {
      nameTouched = nameInput.value.trim() !== "";
    });

    function syncElearningUI() {
      const isElearning = courseSelect.value !== "";

      nameInput.required = !isElearning;
    }
    courseSelect.addEventListener("change", syncElearningUI);
    syncElearningUI();
  })();

  const allowInstallmentCheckbox = document.getElementById('allowInstallment');
  const installmentConfig = document.getElementById('installmentConfig');

  allowInstallmentCheckbox?.addEventListener('change', function() {
    installmentConfig?.classList.toggle('hidden', !this.checked);

    // Refresh UI only. Do not auto-open or auto-enable Subscription Based.
    document.getElementById("isSubscription")?.dispatchEvent(new Event("change", {
      bubbles: true
    }));
  });

  // Trigger on page load if already checked
  if (allowInstallmentCheckbox?.checked) {
    installmentConfig?.classList.remove('hidden');
  }
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>