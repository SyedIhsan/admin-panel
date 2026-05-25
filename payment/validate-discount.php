<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const SST_RATE = 0;

require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db.php";

/** @var mysqli|null $conn */
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "status" => "error",
    "message" => "Database connection unavailable.",
    "messageType" => "error"
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

function jexit(array $out, int $code = 200): void {
  $ok = (bool)($out['ok'] ?? false);

  if (!isset($out['status'])) {
    $out['status'] = $ok ? 'success' : 'error';
  }

  if (!isset($out['messageType'])) {
    $out['messageType'] = $ok ? 'success' : 'error';
  }

  if (!isset($out['messageColor'])) {
    $out['messageColor'] = $ok ? 'green' : 'red';
  }

  if (!isset($out['messageClass'])) {
    $out['messageClass'] = $ok
      ? 'mt-1 text-xs text-emerald-600'
      : 'mt-1 text-xs text-red-500';
  }

  http_response_code($code);
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
  exit;
}

function norm_email(string $s): string { return strtolower(trim($s)); }
function norm_code(string $s): string {
  $s = strtoupper(trim($s));
  $s = preg_replace('/\s+/', '', $s); // buang space
  return $s;
}
function money(float $n): float { return (float)number_format($n, 2, '.', ''); }
function columnExists(mysqli $conn, string $table, string $col): bool {
  $t = mysqli_real_escape_string($conn, $table);
  $c = mysqli_real_escape_string($conn, $col);
  $r = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $r && mysqli_num_rows($r) > 0;
}

function setInactive(mysqli $conn, int $dcId): void {
  if ($dcId <= 0) return;
  $st = $conn->prepare("UPDATE Discount_Codes SET status='inactive' WHERE id=? AND status='active' LIMIT 1");
  if ($st) {
    $st->bind_param("i", $dcId);
    $st->execute();
    $st->close();
  }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") jexit(["ok"=>false,"message"=>"Method not allowed"], 405);

$productId  = trim((string)($_POST["product_id"] ?? ""));
$categoryId = trim((string)($_POST["category_id"] ?? ""));
$email      = norm_email((string)($_POST["email"] ?? ""));
$code       = norm_code((string)($_POST["code"] ?? ""));
$paymentMethod = strtolower(trim((string)($_POST["payment_method"] ?? "full")));
if (!in_array($paymentMethod, ["full", "installment"], true)) {
  $paymentMethod = "full";
}

if ($paymentMethod === "installment") {
  jexit([
    "ok" => false,
    "message" => "Discount codes are only available for full payment.",
    "messageType" => "error"
  ], 200);
}

if ($productId === "") {
  jexit(["ok"=>false, "message"=>"Invalid product."], 200);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  jexit(["ok"=>false, "message"=>"Please enter a valid email first."], 200);
}

if ($code === "") {
  jexit(["ok"=>false, "message"=>"Please enter a discount code."], 200);
}

if ($paymentMethod === "installment") {
  jexit([
    "ok" => false,
    "message" => "Discount codes are only available for full payment.",
    "messageType" => "error"
  ], 200);
}

/* 1) Get full-payment pricing base only */
$stmt = $conn->prepare("
  SELECT base_price
  FROM Products
  WHERE id = ? AND status='active'
  LIMIT 1
");
$stmt->bind_param("s", $productId);
$stmt->execute();
$prod = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$prod) {
  jexit(["ok"=>false, "message"=>"Invalid product."], 404);
}

$basePrice = (float)$prod["base_price"];
$subTotal = $basePrice;

if ($categoryId !== "") {
  $stmt = $conn->prepare("
    SELECT price_modifier
    FROM Product_Categories
    WHERE id = ? AND product_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ss", $categoryId, $productId);
  $stmt->execute();
  $cat = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($cat) {
    $subTotal = $basePrice + (float)($cat["price_modifier"] ?? 0);
  }
}

/* 2) Load discount code */
$stmt = $conn->prepare("
  SELECT id, discount_type, discount_value, product_id, category_id, allowed_email, valid_from, valid_until,
         max_redemptions, per_email_limit, status
  FROM Discount_Codes
  WHERE code = ?
  LIMIT 1
");
$stmt->bind_param("s", $code);
$stmt->execute();
$dc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dc) {
  jexit(["ok"=>false, "message"=>"Invalid / expired discount code."], 200);
}

if (($dc["status"] ?? "") !== "active") {
  jexit(["ok"=>false, "message"=>"This discount code is inactive."], 200);
}

/* 3) Time window */
$now = new DateTime("now");
$vf  = $dc["valid_from"] ? new DateTime($dc["valid_from"]) : null;
$vu  = $dc["valid_until"] ? new DateTime($dc["valid_until"]) : null;

if ($vf && $now < $vf) jexit(["ok"=>false, "message"=>"Discount code not active yet."], 200);
if ($vu && $now > $vu) {
  setInactive($conn, (int)$dc["id"]);
  jexit(["ok"=>false, "message"=>"Invalid / expired discount code."], 200);
}

/* 4) Email restriction (specific user) */
$rawAllowed = trim((string)($dc["allowed_email"] ?? ""));

if ($rawAllowed !== "") {
  // support multiple emails: comma/space/semicolon separated
  $list = preg_split('/[,\s;]+/', strtolower($rawAllowed), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $list = array_values(array_unique(array_map('trim', $list)));

  if (!in_array($email, $list, true)) {
    jexit(["ok"=>false, "message"=>"Discount code not valid for this email."], 200);
  }
}

/* 5) Product/category restriction (optional) */
if (!empty($dc["product_id"]) && (string)$dc["product_id"] !== $productId) {
  jexit(["ok"=>false, "message"=>"Discount code not valid for this product."], 200);
}
if (!empty($dc["category_id"]) && (string)$dc["category_id"] !== $categoryId) {
  jexit(["ok"=>false, "message"=>"Discount code not valid for this package."], 200);
}

/* 6) Usage limits (count only PAID) */
$dcId = (int)$dc["id"];

if (!empty($dc["max_redemptions"])) {
  $max = (int)$dc["max_redemptions"];
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM Discount_Redemptions WHERE discount_code_id=? AND status='paid'");
  $stmt->bind_param("i", $dcId);
  $stmt->execute();
  $c = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
  $stmt->close();
  if ($c >= $max) {
    setInactive($conn, $dcId);
    jexit(["ok"=>false, "message"=>"Discount code already fully used."], 200);
    }
}

$perLimit = (int)($dc["per_email_limit"] ?? 1);
if ($perLimit > 0) {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM Discount_Redemptions WHERE discount_code_id=? AND email=? AND status='paid'");
  $stmt->bind_param("is", $dcId, $email);
  $stmt->execute();
  $c = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
  $stmt->close();
  if ($c >= $perLimit) jexit(["ok"=>false, "message"=>"Discount code already used for this email."], 200);
}

/* 7) Calculate discount (before SST) */
$type  = (string)$dc["discount_type"];
$val   = (float)$dc["discount_value"];
$disc  = 0.0;

if ($type === "percent") {
  $disc = $subTotal * ($val / 100.0);
} else { // fixed
  $disc = $val;
}
if ($disc < 0) $disc = 0.0;
if ($disc > $subTotal) $disc = $subTotal;

$newSub = $subTotal - $disc;
$sst = $newSub * SST_RATE;
$total = $newSub + $sst;

$label = ($type === "fixed")
  ? ('RM' . number_format($val, 2, '.', ''))
  : (rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '%');

jexit([
  "ok" => true,
  "message" => "Discount applied ({$label}).",
  "discountType" => $type,
  "discountValue" => $val,
  "discountAmount" => money($disc),
  "subTotal" => money($newSub),
  "sst" => money($sst),
  "total" => money($total),
  "validUntil" => $dc["valid_until"],
]);
