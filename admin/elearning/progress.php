<?php
declare(strict_types=1);
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";

// --- Brevo bootstrap (admin/elearning/progress.php) ---
$publicRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? (__DIR__ . '/../../..'))
  ?: dirname(__DIR__, 3);

// 1) Load .env payment (kalau kau simpan key kat situ)
$envFile = $publicRoot . "/payment/.env";
$envLoader = $publicRoot . "/api/env.php";
if (file_exists($envLoader)) {
  require_once $envLoader;
  if (function_exists("loadEnv") && file_exists($envFile)) {
    loadEnv($envFile);
  }
}

// 2) Include ses-config.php (tempat sendBrevo defined)
$brevoConfig = $publicRoot . "/api/ses-config.php";
if (!file_exists($brevoConfig)) {
  throw new RuntimeException("Missing Brevo config: {$brevoConfig}");
}
require_once $brevoConfig;

require_once $publicRoot . "/api/mail/templates/certificate-issued.php";

// 3) Optional: hard guard
$brevoReady =
  defined("BREVO_API_KEY") && trim((string)BREVO_API_KEY) !== "" &&
  defined("BREVO_SENDER_EMAIL") && trim((string)BREVO_SENDER_EMAIL) !== "";

require_once __DIR__ . '/db.php';

$q = trim((string)($_GET["q"] ?? ""));
$export = (string)($_GET["export"] ?? "") === "1";

$level = strtolower(trim((string)($_GET["level"] ?? "")));
$allowedLevels = ["", "beginner", "intermediate", "advanced"];
if (!in_array($level, $allowedLevels, true)) $level = "";

$levelLabel = match ($level) {
  "beginner" => "Beginner",
  "intermediate" => "Intermediate",
  "advanced" => "Advanced",
  default => "All",
};

// Use EL_OP_EMAIL_FILTER and EL_EMAIL_FILTER from db.php
$opEmailFilter = " AND " . EL_OP_EMAIL_FILTER;
$userEmailFilter = " AND u.usertype = 0 AND " . EL_EMAIL_FILTER;

function table_exists(mysqli $conn, string $name): bool {
  $sql = "SELECT 1
          FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param("s", $name);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
  $refs = [];
  $refs[] = &$types;
  foreach ($params as $k => $v) {
    $refs[] = &$params[$k];
  }
  call_user_func_array([$stmt, "bind_param"], $refs);
}

function k(string $s): string {
  return strtolower(trim($s));
}

function time_ago(?string $dt): string {
  if (!$dt) return "—";
  $ts = strtotime($dt);
  if (!$ts) return "—";
  $diff = time() - $ts;
  if ($diff < 60) return "just now";
  if ($diff < 3600) return floor($diff / 60) . " mins ago";
  if ($diff < 86400) return floor($diff / 3600) . " hours ago";
  if ($diff < 2592000) return floor($diff / 86400) . " days ago";
  return date("Y-m-d", $ts);
}

function pct(int $done, int $total): int {
  if ($total <= 0) return 0;
  $p = (int)round(($done / $total) * 100);
  if ($p < 0) return 0;
  if ($p > 100) return 100;
  return $p;
}


/* ===============================
   Certificate (PDF + Email)
   - Uses cert_template.png exported from cert.pdf
   - Requires FPDF (drop-in lib)
   =============================== */

$CERT_TEMPLATE_PNG = __DIR__ . "/assets/cert_template.png"; // <-- upload template image here
$FPDF_PATH         = __DIR__ . "/lib/fpdf/fpdf.php";        // <-- put fpdf.php here
// preload FPDF (avoid "Class FPDF not found" when file exists but not loaded / wrong lib)
$FPDF_PATH_REAL = realpath($FPDF_PATH) ?: $FPDF_PATH;
$FPDF_PATH = $FPDF_PATH_REAL;

// NOTE: FPDF tak diperlukan (kita generate PDF secara "raw").
// Kalau kau nak balik guna FPDF/TCPDF nanti, kau boleh require kat sini.

$hasCertTable = table_exists($conn, "course_certificates");



// --- collation guard (fix "Illegal mix of collations" when tables differ, e.g. utf8mb4_unicode_ci vs utf8mb4_0900_ai_ci)
$COLL = "utf8mb4_unicode_ci";
// Flash message (PRG pattern)
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

function set_flash(string $type, string $msg): void {
  $_SESSION["flash"] = ["type" => $type, "msg" => $msg];
}

/** returns totals + done for user/course (server-side verification) */
function get_course_completion(mysqli $conn, int $userId, string $courseId): array {
  global $COLL;
  $courseId = trim($courseId);
  if ($userId <= 0 || $courseId === "") {
    return [
      "ok" => false,
      "total_v" => 0, "total_e" => 0, "total_w" => 0,
      "done_v" => 0, "done_e" => 0, "done_w" => 0,
    ];
  }

  // totals
  $stmt = $conn->prepare("
    SELECT
      (SELECT COUNT(*) FROM course_videos   WHERE (course_id COLLATE {$COLL}) = (? COLLATE {$COLL})) AS total_v,
      (SELECT COUNT(*) FROM course_ebooks   WHERE (course_id COLLATE {$COLL}) = (? COLLATE {$COLL})) AS total_e,
      (SELECT COUNT(*) FROM course_workbooks WHERE (course_id COLLATE {$COLL}) = (? COLLATE {$COLL})) AS total_w
  ");
  if (!$stmt) return ["ok" => false] + ["total_v"=>0,"total_e"=>0,"total_w"=>0,"done_v"=>0,"done_e"=>0,"done_w"=>0];
  $stmt->bind_param("sss", $courseId, $courseId, $courseId);
  $stmt->execute();
  $tot = $stmt->get_result()?->fetch_assoc() ?: [];
  $stmt->close();

  $totalV = (int)($tot["total_v"] ?? 0);
  $totalE = (int)($tot["total_e"] ?? 0);
  $totalW = (int)($tot["total_w"] ?? 0);

  // done (case-insensitive course_id)
  $stmt2 = $conn->prepare("
    SELECT
      SUM(CASE WHEN completed=1 AND content_type='video'    THEN 1 ELSE 0 END) AS done_v,
      SUM(CASE WHEN completed=1 AND content_type='ebook'    THEN 1 ELSE 0 END) AS done_e,
      SUM(CASE WHEN completed=1 AND content_type='workbook' THEN 1 ELSE 0 END) AS done_w
    FROM user_progress
    WHERE user_id = ?
      AND (course_id COLLATE {$COLL}) = (? COLLATE {$COLL})
  ");
  if (!$stmt2) return ["ok" => false] + ["total_v"=>$totalV,"total_e"=>$totalE,"total_w"=>$totalW,"done_v"=>0,"done_e"=>0,"done_w"=>0];
  $stmt2->bind_param("is", $userId, $courseId);
  $stmt2->execute();
  $don = $stmt2->get_result()?->fetch_assoc() ?: [];
  $stmt2->close();

  return [
    "ok" => true,
    "total_v" => $totalV, "total_e" => $totalE, "total_w" => $totalW,
    "done_v" => (int)($don["done_v"] ?? 0),
    "done_e" => (int)($don["done_e"] ?? 0),
    "done_w" => (int)($don["done_w"] ?? 0),
  ];
}

function gen_cert_no(): string {
  // CERT-XXXXXX
  $n = random_int(100000, 999999);
  return "CERT-" . $n;
}

/**
 * Generate PDF bytes using template background image (A4)
 * NOTE: template should be "clean". If template ada placeholder text, kita "mask" area dengan putih.
 */
function generate_certificate_pdf_bytes(
  string $fpdfPath,
  string $templatePngPath,
  string $studentName,
  string $courseTitle,
  string $certNo,
  string $issuedOn
): string {
  if (!file_exists($templatePngPath)) {
    throw new RuntimeException("Missing certificate template image: " . $templatePngPath);
  }
  if (!function_exists("imagecreatefrompng")) {
    throw new RuntimeException("GD extension required (imagecreatefrompng not found). Enable PHP-GD.");
  }

  $studentName = trim($studentName);
  $courseTitle = trim($courseTitle);
  $courseTitleClean = rtrim($courseTitle, " .");

  // --- Convert template PNG -> JPEG bytes (DCTDecode easier for PDF embed) ---
  $im = @imagecreatefrompng($templatePngPath);
  if (!$im) throw new RuntimeException("Failed to load template PNG: " . $templatePngPath);
  $imgW = imagesx($im);
  $imgH = imagesy($im);

  ob_start();
  imagejpeg($im, null, 92);
  $jpeg = ob_get_clean();
  imagedestroy($im);
  if ($jpeg === false || $jpeg === "") throw new RuntimeException("Failed to convert template to JPEG bytes.");

  // --- Minimal PDF builder (A4 landscape) ---
  $mm2pt = fn($mm) => $mm * 72.0 / 25.4;
  $W = 842.0; // A4 landscape width (pt)
  $H = 595.0; // A4 landscape height (pt)

  $pdf_escape = function(string $s): string {
    $s = str_replace("\\", "\\\\", $s);
    $s = str_replace("(", "\\(", $s);
    $s = str_replace(")", "\\)", $s);
    return preg_replace("/\\r?\\n/", " ", $s);
  };

  // Approximate width for Times fonts (good enough for fitting)
  $approx_width_pt = function(string $s, float $fontSize, bool $bold): float {
    $plain = preg_replace('/[^\x20-\x7E]/', '', $s); // ASCII for estimate
    $len = max(0, strlen($plain));
    $k = $bold ? 0.52 : 0.50;
    return $len * $fontSize * $k;
  };

  // Auto-fit font size to a max width
  $fit_font = function(string $txt, float $start, float $min, float $maxWpt, bool $bold) use ($approx_width_pt) : float {
    $sz = $start;
    while ($sz > $min && $approx_width_pt($txt, $sz, $bold) > $maxWpt) {
      $sz -= 1.0;
    }
    return $sz;
  };

  // ===== TUNED POSITIONS (mm from top) =====
  // Based on template placement (so tak lari macam output kau sekarang)
  $NAME_CENTER_X_MM   = 155.8;
  $COURSE_CENTER_X_MM = 176.8;

  $CERT_VALUE_X_MM    = 180.1; // value area (kanan sikit dari label)
  $DATE_VALUE_X_MM    = 180.1;

  $NAME_Y_MM   = 96.0;
  $COURSE_Y_MM = 122.0;
  $CERT_Y_MM   = 142.0;
  $DATE_Y_MM   = 148.0;

  // ===== Font sizing =====
  $nameLen = mb_strlen($studentName);

  // Start size ikut panjang nama (rule kau: >20 char kecilkan)
  $nameSize = 34.0;
  if ($nameLen > 20) $nameSize = 30.0;
  if ($nameLen > 28) $nameSize = 26.0;
  if ($nameLen > 36) $nameSize = 22.0;

  // Max widths (mm) for fitting
  $maxNameWpt   = $mm2pt(150.0); // lebar selamat supaya tak masuk ribbon kiri
  $maxCourseWpt = $mm2pt(160.0);
  $maxMetaWpt   = $mm2pt(90.0);

  // Fit down if still too long
  $nameSize = $fit_font($studentName, $nameSize, 18.0, $maxNameWpt, true);

  $courseSize = 18.0;
  $courseSize = $fit_font($courseTitleClean, $courseSize, 12.0, $maxCourseWpt, false);

  $metaSize = 12.0;
  $metaSize = $fit_font($certNo, $metaSize, 10.0, $maxMetaWpt, false);

  // Convert coords to PDF points
  $y_from_top_mm_to_pt = function(float $yTopMm) use ($H, $mm2pt) {
    return $H - $mm2pt($yTopMm);
  };

  // Centers in pt
  $nameCenterXpt   = $mm2pt($NAME_CENTER_X_MM);
  $courseCenterXpt = $mm2pt($COURSE_CENTER_X_MM);
  $certCenterXpt   = $mm2pt($CERT_VALUE_X_MM);
  $dateCenterXpt   = $mm2pt($DATE_VALUE_X_MM);

  // Baselines in pt
  $nameYpt   = $y_from_top_mm_to_pt($NAME_Y_MM);
  $courseYpt = $y_from_top_mm_to_pt($COURSE_Y_MM);
  $certYpt   = $y_from_top_mm_to_pt($CERT_Y_MM);
  $dateYpt   = $y_from_top_mm_to_pt($DATE_Y_MM);

  // X positions (centered)
  $nameXpt   = $nameCenterXpt   - ($approx_width_pt($studentName, $nameSize, true) / 2.0);
  $courseXpt = $courseCenterXpt - ($approx_width_pt($courseTitleClean, $courseSize, false) / 2.0);
  $certXpt   = $certCenterXpt   - ($approx_width_pt($certNo, $metaSize, false) / 2.0);
  $dateXpt   = $dateCenterXpt   - ($approx_width_pt($issuedOn, $metaSize, false) / 2.0);

  // Colors
  $rgName = sprintf("%.3f %.3f %.3f rg\n", 10/255, 55/255, 85/255);   // navy
  $rgBody = sprintf("%.3f %.3f %.3f rg\n", 35/255, 35/255, 35/255);   // dark grey

  // ===== PDF content stream =====
  $content = "";
  $content .= "q {$W} 0 0 {$H} 0 0 cm /Im0 Do Q\n"; // background image

  $content .= "BT\n";

  // Name (Times-Bold)
  $content .= $rgName;
  $content .= "/F1 {$nameSize} Tf\n";
  $content .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $nameXpt, $nameYpt);
  $content .= "(" . $pdf_escape($studentName) . ") Tj\n";

  // Course + meta (Times-Roman)
  $content .= $rgBody;

  $content .= "/F2 {$courseSize} Tf\n";
  $content .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $courseXpt, $courseYpt);
  $content .= "(" . $pdf_escape($courseTitleClean) . ") Tj\n";

  $content .= "/F2 {$metaSize} Tf\n";
  $content .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $certXpt, $certYpt);
  $content .= "(" . $pdf_escape($certNo) . ") Tj\n";

  $content .= sprintf("1 0 0 1 %.2f %.2f Tm\n", $dateXpt, $dateYpt);
  $content .= "(" . $pdf_escape($issuedOn) . ") Tj\n";

  $content .= "ET\n";

  // --- Build PDF objects ---
  $objects = [];
  $addObj = function(string $obj) use (&$objects): int {
    $objects[] = $obj;
    return count($objects);
  };

  $imgObj   = $addObj("");
  $font1Obj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold >>");
  $font2Obj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>");
  $contObj  = $addObj("");
  $pageObj  = $addObj("");
  $pagesObj = $addObj("");
  $catObj   = $addObj("");

  $objects[$imgObj-1] =
    "<< /Type /XObject /Subtype /Image"
    . " /Width {$imgW} /Height {$imgH}"
    . " /ColorSpace /DeviceRGB /BitsPerComponent 8"
    . " /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\n"
    . "stream\n" . $jpeg . "\nendstream";

  $objects[$contObj-1] =
    "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

  $resources =
    "<< /ProcSet [/PDF /Text /ImageC]"
    . " /Font << /F1 {$font1Obj} 0 R /F2 {$font2Obj} 0 R >>"
    . " /XObject << /Im0 {$imgObj} 0 R >>"
    . " >>";

  $objects[$pageObj-1] =
    "<< /Type /Page /Parent {$pagesObj} 0 R"
    . " /MediaBox [0 0 {$W} {$H}]"
    . " /Resources {$resources}"
    . " /Contents {$contObj} 0 R"
    . " >>";

  $objects[$pagesObj-1] =
    "<< /Type /Pages /Kids [{$pageObj} 0 R] /Count 1 >>";

  $objects[$catObj-1] =
    "<< /Type /Catalog /Pages {$pagesObj} 0 R >>";

  $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = [0];
  for ($i = 0; $i < count($objects); $i++) {
    $offsets[] = strlen($out);
    $n = $i + 1;
    $out .= "{$n} 0 obj\n" . $objects[$i] . "\nendobj\n";
  }

  $xrefPos = strlen($out);
  $out .= "xref\n0 " . (count($objects) + 1) . "\n";
  $out .= "0000000000 65535 f \n";
  for ($i = 1; $i <= count($objects); $i++) {
    $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catObj} 0 R >>\n";
  $out .= "startxref\n{$xrefPos}\n%%EOF";

  return $out;
}

/** Brevo transactional email with PDF attachment */
function brevo_send_cert_email(
  string $apiKey,
  string $fromEmail,
  string $fromName,
  string $toEmail,
  string $toName,
  string $subject,
  string $html,
  string $filename,
  string $pdfBytes
): array {
  $payload = [
    "sender" => ["name" => $fromName, "email" => $fromEmail],
    "to" => [["email" => $toEmail, "name" => $toName]],
    "subject" => $subject,
    "htmlContent" => $html,
    "attachment" => [[
      "content" => base64_encode($pdfBytes),
      "name" => $filename,
    ]],
  ];

  $ch = curl_init("https://api.brevo.com/v3/smtp/email");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "accept: application/json",
      "content-type: application/json",
      "api-key: " . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) {
    return ["ok" => false, "status" => 0, "body" => $err ?: "cURL error"];
  }

  return ["ok" => ($code >= 200 && $code < 300), "status" => $code, "body" => (string)$body];
}

/* Handle send/resend certificate */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cert_action"])) {
  if (defined('DEMO_MODE') && DEMO_MODE) {
    set_flash("success", "[Demo] Certificate email logged to demo/mail-outbox/ — not actually sent.");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }
  if (!$brevoReady) {
    set_flash("error", "Brevo env missing (BREVO_API_KEY + BREVO_SENDER_EMAIL).");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  $userId = (int)($_POST["user_id"] ?? 0);
  $courseId = trim((string)($_POST["course_id"] ?? ""));
  $action = (string)($_POST["cert_action"] ?? "send"); // send | resend

  // Pull student basic info
  $stmtU = $conn->prepare("SELECT id, name, email FROM `user` WHERE id = ? LIMIT 1");
  if (!$stmtU) {
    set_flash("error", "DB error: cannot load user.");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }
  $stmtU->bind_param("i", $userId);
  $stmtU->execute();
  $u = $stmtU->get_result()?->fetch_assoc() ?: null;
  $stmtU->close();

  if (!$u || $courseId === "") {
    set_flash("error", "Invalid request (missing user/course).");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  // Verify completion server-side
  $comp = get_course_completion($conn, $userId, $courseId);
  $totalAll = (int)$comp["total_v"] + (int)$comp["total_e"] + (int)$comp["total_w"];

  $complete =
    ($totalAll > 0)
    && ((int)$comp["total_v"] === 0 || (int)$comp["done_v"] >= (int)$comp["total_v"])
    && ((int)$comp["total_e"] === 0 || (int)$comp["done_e"] >= (int)$comp["total_e"])
    && ((int)$comp["total_w"] === 0 || (int)$comp["done_w"] >= (int)$comp["total_w"]);

  if (!$complete) {
    set_flash("error", "Student not eligible yet (progress belum 100%).");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  // Course title
  $courseTitle = $courseId;
  $stmtC = $conn->prepare("SELECT title FROM courses WHERE id = ? LIMIT 1");
  if ($stmtC) {
    $stmtC->bind_param("s", $courseId);
    $stmtC->execute();
    $c = $stmtC->get_result()?->fetch_assoc() ?: [];
    if (!empty($c["title"])) $courseTitle = (string)$c["title"];
    $stmtC->close();
  }

  // Keep existing cert_no/issued_at if exists
  $certNo = "";
  $issuedAt = "";
  $stmtCC = $conn->prepare("SELECT cert_no, issued_at FROM course_certificates WHERE user_id = ? AND (course_id COLLATE {$COLL}) = (? COLLATE {$COLL}) LIMIT 1");
  if ($stmtCC) {
    $stmtCC->bind_param("is", $userId, $courseId);
    $stmtCC->execute();
    $cc = $stmtCC->get_result()?->fetch_assoc() ?: [];
    $stmtCC->close();
    $certNo = (string)($cc["cert_no"] ?? "");
    $issuedAt = (string)($cc["issued_at"] ?? "");
  }

  if ($certNo === "") $certNo = gen_cert_no();
  if ($issuedAt === "") $issuedAt = date("Y-m-d H:i:s");

  $issuedLabel = date("d M Y", strtotime($issuedAt) ?: time());

  // Generate PDF bytes
  try {
    $pdfBytes = generate_certificate_pdf_bytes(
      $FPDF_PATH,
      $CERT_TEMPLATE_PNG,
      (string)$u["name"],
      $courseTitle,
      $certNo,
      $issuedLabel
    );
  } catch (Throwable $ex) {
    set_flash("error", "Certificate PDF failed: " . $ex->getMessage());
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  // Brevo creds (from ses-config.php constants)
  $apiKey    = defined("BREVO_API_KEY") ? trim((string)BREVO_API_KEY) : "";
  $fromEmail = defined("BREVO_SENDER_EMAIL") ? trim((string)BREVO_SENDER_EMAIL) : "";
  $fromName  = defined("BREVO_SENDER_NAME") ? trim((string)BREVO_SENDER_NAME) : "Demo Company";

  if ($apiKey === "" || $fromEmail === "") {
    set_flash("error", "Brevo env missing (BREVO_API_KEY + BREVO_SENDER_EMAIL).");
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  $toEmail = (string)$u["email"];
  $toName  = (string)$u["name"];

  $subject = "Certificate Issued — " . $courseTitle;

$preheader = "Your certificate for {$courseTitle} is attached as a PDF. Certificate No: {$certNo}.";

$html = buildCertificateIssuedEmail([
  'subject'       => $subject,
  'to_name'       => $toName,
  'course_title'  => $courseTitle,
  'cert_no'       => $certNo,
  'issued_label'  => $issuedLabel,
  'preheader'     => $preheader,
  'brand_name'    => 'Demo E-Learning',
  'brand_email'   => 'noreply@demo.local',
  'support_email' => 'support@demo.local',
  'logo_url'      => '/img/demo_logo.svg',
  'privacy_url'   => '#demo-privacy',
  'year'          => date('Y'),
]);

  $filename = "certificate-" . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $courseId)) . "-" . $certNo . ".pdf";

  $send = brevo_send_cert_email($apiKey, $fromEmail, $fromName, $toEmail, $toName, $subject, $html, $filename, $pdfBytes);

  if (!$send["ok"]) {
    set_flash("error", "Brevo send failed (HTTP {$send["status"]}): " . $send["body"]);
    header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
    exit;
  }

  // Upsert certificate record (keep cert_no + issued_at)
  $stmtUp = $conn->prepare("
    INSERT INTO course_certificates (user_id, course_id, cert_no, issued_at, sent_at, sent_to)
    VALUES (?, ?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE
      sent_at = NOW(),
      sent_to = VALUES(sent_to)
  ");
  if ($stmtUp) {
    $stmtUp->bind_param("issss", $userId, $courseId, $certNo, $issuedAt, $toEmail);
    $stmtUp->execute();
    $stmtUp->close();
  }

  set_flash("success", ($action === "resend" ? "Certificate resent" : "Certificate sent") . " to {$toEmail}.");
  header("Location: " . ($_SERVER["REQUEST_URI"] ?? "/admin/elearning/progress.php"));
  exit;
}


/** detect order_products table (e-Learning purchases) */
$ordersTable = null;
if (table_exists($conn, "order_products")) $ordersTable = "order_products";

$students = [];
$workbooksByCourse = [];
$stats = [
  "active_learners" => 0,
  "avg_completion"  => 0,
  "revenue"         => 0.0,
];

if ($ordersTable) {
  $hasAccessId = table_exists($conn, "user") && column_exists($conn, "user", "access_id");

  $hasUserProgress = table_exists($conn, "user_progress");
  $upTsCol = null;
  if ($hasUserProgress) {
    if (column_exists($conn, "user_progress", "updated_at")) $upTsCol = "updated_at";
    else if (column_exists($conn, "user_progress", "created_at")) $upTsCol = "created_at";
  }

  // progress subquery (optional)
  $upSelect = "0 AS v_done, 0 AS e_done, 0 AS w_done, NULL AS last_ts";
  $upJoin = "";
  if ($hasUserProgress) {
    $tsExpr = $upTsCol ? "MAX($upTsCol)" : "NULL";
    $upSelect = "COALESCE(up.v_done,0) AS v_done, COALESCE(up.e_done,0) AS e_done, COALESCE(up.w_done,0) AS w_done, up.last_ts";
    $upJoin = "
      LEFT JOIN (
        SELECT
          user_id,
          course_id,
          SUM(CASE WHEN completed=1 AND content_type='video' THEN 1 ELSE 0 END) AS v_done,
          SUM(CASE WHEN completed=1 AND content_type='ebook' THEN 1 ELSE 0 END) AS e_done,
          SUM(CASE WHEN completed=1 AND content_type='workbook' THEN 1 ELSE 0 END) AS w_done,
          $tsExpr AS last_ts
        FROM user_progress
        GROUP BY user_id, course_id
      ) up ON up.user_id = u.id AND (LOWER(up.course_id) COLLATE {$COLL}) = (LOWER(op.product_name) COLLATE {$COLL})
    ";
  }

  
  // certificates (optional)
  $certSelect = $hasCertTable
    ? "cc.cert_no AS cert_no, cc.issued_at AS cert_issued_at, cc.sent_at AS cert_sent_at"
    : "NULL AS cert_no, NULL AS cert_issued_at, NULL AS cert_sent_at";

  $certJoin = $hasCertTable
    ? "LEFT JOIN course_certificates cc ON cc.user_id = u.id AND (LOWER(cc.course_id) COLLATE {$COLL}) = (LOWER(op.product_name) COLLATE {$COLL})"
    : "";

// latest completed order per user email
  $sql = "
    SELECT
      u.id AS user_id,
      CONCAT('USER-', u.id) AS student_id,
      u.name,
      u.email,

      LOWER(op.product_name) AS course_id,
      op.created_at AS enrolled_at,
      op.amount AS paid_price,

      c.title AS course_title,
      c.level AS course_level,

      COALESCE(cv.total_videos, 0) AS total_videos,
      COALESCE(ce.total_ebooks, 0) AS total_ebooks,
      COALESCE(cw.total_workbooks, 0) AS total_workbooks,

      $upSelect,
      $certSelect
    FROM `user` u

    JOIN (
      SELECT
        LOWER(TRIM(customer_email)) AS em,
        LOWER(TRIM(product_name))   AS course_id,
        MAX(id)                     AS oid
      FROM `order_products`
      WHERE customer_email IS NOT NULL AND customer_email <> ''
        AND LOWER(status) = 'completed'
        AND (LOWER(product_type) = 'elearning_course' OR product_type IS NULL OR product_type = '')
        $opEmailFilter
      GROUP BY em, course_id
    ) pe ON (pe.em COLLATE {$COLL}) = (LOWER(TRIM(u.email)) COLLATE {$COLL})

    JOIN `order_products` op ON op.id = pe.oid

    LEFT JOIN courses c ON (c.id COLLATE {$COLL}) = (op.product_name COLLATE {$COLL})

    $certJoin

    LEFT JOIN (
      SELECT course_id, COUNT(*) AS total_videos
      FROM course_videos
      GROUP BY course_id
    ) cv ON (cv.course_id COLLATE {$COLL}) = (op.product_name COLLATE {$COLL})

    LEFT JOIN (
      SELECT course_id, COUNT(*) AS total_ebooks
      FROM course_ebooks
      GROUP BY course_id
    ) ce ON (ce.course_id COLLATE {$COLL}) = (op.product_name COLLATE {$COLL})

    LEFT JOIN (
      SELECT course_id, COUNT(*) AS total_workbooks
      FROM course_workbooks
      GROUP BY course_id
    ) cw ON (cw.course_id COLLATE {$COLL}) = (op.product_name COLLATE {$COLL})

    $upJoin

    WHERE 1=1
      $userEmailFilter
  ";

  $params = [];
  $types = "";

  $levelExpr = "LOWER(COALESCE(NULLIF(c.level,''), CASE
    WHEN LOWER(op.product_name) LIKE 'beg-%' THEN 'beginner'
    WHEN LOWER(op.product_name) LIKE 'int-%' THEN 'intermediate'
    WHEN LOWER(op.product_name) LIKE 'adv-%' THEN 'advanced'
    ELSE ''
  END))";

  if ($level !== "") {
    $sql .= " AND {$levelExpr} = ? ";
    $params[] = $level;
    $types .= "s";
  }

  if ($q !== "") {
    $like = "%" . $q . "%";
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? ";
    $params[] = $like; $types .= "s";
    $params[] = $like; $types .= "s";

    if ($hasAccessId) {
      $sql .= " OR u.access_id LIKE ? ";
      $params[] = $like; $types .= "s";
    }
    $sql .= ") ";
  }

  $sql .= " ORDER BY op.created_at DESC LIMIT 200 ";

  $stmt = $conn->prepare($sql);
  if ($stmt) {
    if ($types !== "") bind_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        $row["videos_pct"] = pct((int)$row["v_done"], (int)$row["total_videos"]);
        $row["ebooks_pct"] = pct((int)$row["e_done"], (int)$row["total_ebooks"]);
        $row["workbooks_pct"] = pct((int)$row["w_done"], (int)$row["total_workbooks"]);

        // completion check (for certificate eligibility)
        $tv = (int)$row["total_videos"];
        $te = (int)$row["total_ebooks"];
        $tw = (int)$row["total_workbooks"];
        $totalAll = $tv + $te + $tw;

        $row["overall_pct"] = $totalAll > 0
          ? pct((int)$row["v_done"] + (int)$row["e_done"] + (int)$row["w_done"], $totalAll)
          : 0;

        $row["is_complete"] =
          ($totalAll > 0)
          && ($tv === 0 || (int)$row["v_done"] >= $tv)
          && ($te === 0 || (int)$row["e_done"] >= $te)
          && ($tw === 0 || (int)$row["w_done"] >= $tw);


        $last = $row["last_ts"] ?: $row["enrolled_at"];
        $row["last_human"] = time_ago($last);

        $students[] = $row;
      }
    }
    $stmt->close();
  }

  // Workbooks monitor (fetch only for displayed courses)
  $userWorkbookFiles = []; // [student_id][course_id][workbook_id] => user_file_id

  if (table_exists($conn, "user_workbooks")) {
    $studentIds = [];
    $courseIds2 = [];

    foreach ($students as $s) {
      $studentIds[] = (string)(int)($s["user_id"] ?? 0);
      $courseIds2[] = (string)($s["course_id"] ?? "");
    }

    $studentIds = array_values(array_unique(array_filter($studentIds)));
    $courseIds2 = array_values(array_unique(array_filter($courseIds2)));

    if (count($studentIds) > 0 && count($courseIds2) > 0) {
      $inS = implode(",", array_fill(0, count($studentIds), "?"));
      $inC = implode(",", array_fill(0, count($courseIds2), "?"));

      // order DESC so latest created_at wins
      $sqlUW = "
        SELECT user_id, course_id, workbook_id, user_file_id, created_at
        FROM user_workbooks
        WHERE user_id IN ($inS) AND course_id IN ($inC)
        ORDER BY created_at DESC
      ";

      $stmtUW = $conn->prepare($sqlUW);
      if ($stmtUW) {
        $typesUW = str_repeat("s", count($studentIds) + count($courseIds2));
        $paramsUW = array_merge($studentIds, $courseIds2);
        bind_params($stmtUW, $typesUW, $paramsUW);

        $stmtUW->execute();
        $resUW = $stmtUW->get_result();
        if ($resUW instanceof mysqli_result) {
          while ($r = $resUW->fetch_assoc()) {
            $sidRaw = (string)($r["user_id"] ?? "");
            $cidRaw = (string)($r["course_id"] ?? "");
            $widRaw = (string)($r["workbook_id"] ?? "");
            $fid    = trim((string)($r["user_file_id"] ?? ""));

            $sid = k($sidRaw);
            $cid = k($cidRaw);
            $wid = k($widRaw);

            if ($sid === "" || $cid === "" || $wid === "" || $fid === "") continue;

            // store exact key
            if (!isset($userWorkbookFiles[$sid][$cid][$wid])) {
              $userWorkbookFiles[$sid][$cid][$wid] = $fid;
            }

            // fallback by-course (latest)
            if (!isset($userWorkbookFiles[$sid][$cid]["__any__"])) {
              $userWorkbookFiles[$sid][$cid]["__any__"] = $fid;
            }

            // also store numeric alt key (beg-101-1 => 1)
            if (preg_match('~^' . preg_quote($cid, '~') . '\-(\d+)$~', $wid, $m)) {
              $num = $m[1];
              if (!isset($userWorkbookFiles[$sid][$cid][$num])) {
                $userWorkbookFiles[$sid][$cid][$num] = $fid;
              }
            }
          }
        }
        $stmtUW->close();
      }
    }
  }

  // helper: build sheet url using template suffix (keeps gid)
  function build_sheet_url(string $fileIdOrUrl, string $templateUrl): string {
    $fileIdOrUrl = trim($fileIdOrUrl);
    if ($fileIdOrUrl === "") return $templateUrl;

    // if already a full url, return it
    if (stripos($fileIdOrUrl, "http://") === 0 || stripos($fileIdOrUrl, "https://") === 0) {
      return $fileIdOrUrl;
    }

    $suffix = "/edit?gid=0#gid=0";
    $pos = strpos($templateUrl, "/edit");
    if ($pos !== false) $suffix = substr($templateUrl, $pos);

    return "https://docs.google.com/spreadsheets/d/" . rawurlencode($fileIdOrUrl) . $suffix;
  }
  
  $courseIds = [];
  foreach ($students as $s) $courseIds[] = (string)$s["course_id"];
  $courseIds = array_values(array_unique(array_filter($courseIds)));

  $wbKeyCol = "id";
  if (column_exists($conn, "course_workbooks", "workbook_id")) $wbKeyCol = "workbook_id";
  else if (column_exists($conn, "course_workbooks", "slug")) $wbKeyCol = "slug";

  if (count($courseIds) > 0 && table_exists($conn, "course_workbooks")) {
    $in = implode(",", array_fill(0, count($courseIds), "?"));
    $stmtW = $conn->prepare("SELECT course_id, $wbKeyCol AS wb_key, id, title, url FROM course_workbooks WHERE course_id IN ($in) ORDER BY course_id, id ASC");
    if ($stmtW) {
      $t = str_repeat("s", count($courseIds));
      bind_params($stmtW, $t, $courseIds);
      $stmtW->execute();
      $resW = $stmtW->get_result();
      if ($resW instanceof mysqli_result) {
        $wbOrdinal = []; // course_id => running number

        while ($wb = $resW->fetch_assoc()) {
          $cid = (string)$wb["course_id"];
          if (!isset($workbooksByCourse[$cid])) $workbooksByCourse[$cid] = [];
          if (!isset($wbOrdinal[$cid])) $wbOrdinal[$cid] = 0;

          $wbOrdinal[$cid]++;

          // ✅ key ikut format user_workbooks: beg-101-1
          $wb["wb_key"] = $cid . "-" . $wbOrdinal[$cid];

          $workbooksByCourse[$cid][] = $wb;
        }
      }
      $stmtW->close();
    }
  }

  // Stats (based on displayed list)
  $stats["active_learners"] = count(array_unique(array_map(
    fn($s) => (string)($s["student_id"] ?? ""),
    $students
  )));

  $sumDone = 0; $sumTotal = 0; $sumRevenue = 0.0;
  foreach ($students as $s) {
    $done = (int)$s["v_done"] + (int)$s["e_done"] + (int)$s["w_done"];
    $total = (int)$s["total_videos"] + (int)$s["total_ebooks"] + (int)$s["total_workbooks"];
    $sumDone += $done;
    $sumTotal += $total;
    $sumRevenue += (float)($s["paid_price"] ?? 0);
  }
  $stats["avg_completion"] = $sumTotal > 0 ? (int)round(($sumDone / $sumTotal) * 100) : 0;
  $stats["revenue"] = $sumRevenue;

  // Export CSV
  if ($export) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=student_progress.csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["student_id","name","email","course_id","course_title","course_level","videos_pct","ebooks_pct","workbooks_pct","last_activity"]);
    foreach ($students as $s) {
      fputcsv($out, [
        (string)$s["student_id"],
        (string)($s["name"] ?? ""),
        (string)($s["email"] ?? ""),
        (string)($s["course_id"] ?? ""),
        (string)($s["course_title"] ?? ""),
        (string)($s["course_level"] ?? ""),
        (int)$s["videos_pct"],
        (int)$s["ebooks_pct"],
        (int)$s["workbooks_pct"],
        (string)($s["last_ts"] ?: $s["enrolled_at"] ?: ""),
      ]);
    }
    fclose($out);
    exit;
  }
}

$pageTitle = "Student Progress";
$pageDesc  = "Track student progress, monitor activity, and export reports to CSV.";

$headerActionsHtmlDesktop = '
  <form method="get" class="relative w-80">
    <input type="hidden" name="level" value="'.e($level).'">
    <input
      name="q"
      value="'.e($q).'"
      type="text"
      placeholder="Search Student ID, Name, Email..."
      class="w-full pl-12 pr-4 py-2.5 bg-white border border-slate-200 rounded-2xl
             focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400
             transition-all outline-none text-sm shadow-sm"
    />
    <svg class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2"
         fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
    </svg>
  </form>
';

$headerActionsHtmlMobile = '';

$title = "Student Progress";

include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="bg-slate-50 min-h-screen py-12">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <?php if (!empty($flash) && is_array($flash) && !empty($flash["msg"])): ?>
      <?php
        $isOk = (($flash["type"] ?? "") === "success");
        $box = $isOk
          ? "bg-emerald-50 border-emerald-200 text-emerald-800"
          : "bg-rose-50 border-rose-200 text-rose-800";
      ?>
      <div class="mb-6 rounded-2xl border px-5 py-4 font-bold text-sm <?= $box ?>">
        <?= e((string)$flash["msg"]) ?>
      </div>
    <?php endif; ?>


    <!-- Mobile title/desc + search in page -->
    <div class="md:hidden mb-8">
      <h1 class="text-3xl font-black text-slate-900 tracking-tight">
        <?= e((string)$pageTitle) ?>
      </h1>
      <p class="mt-2 text-sm font-semibold text-slate-500">
        <?= e((string)$pageDesc) ?>
      </p>

      <form method="get" class="relative mt-5">
        <input type="hidden" name="level" value="<?= e($level) ?>">
        <input
          name="q"
          value="<?= e($q) ?>"
          type="text"
          placeholder="Search Student ID, Name, Email..."
          class="w-full pl-12 pr-4 py-3 bg-white border border-slate-200 rounded-2xl
                focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400
                transition-all outline-none text-sm shadow-sm"
        />
        <svg class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2"
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
      </form>
      <div class="mt-4 md:hidden">
        <div class="bg-slate-200/50 p-1.5 rounded-2xl border border-slate-200 overflow-x-auto whitespace-nowrap">
          <?php
            $pill = [
              '' => 'All',
              'beginner' => 'Beginner',
              'intermediate' => 'Intermediate',
              'advanced' => 'Advanced',
            ];
            foreach ($pill as $lv => $lbl) {
              $active = ($level === $lv);
              $href = '?q=' . urlencode($q) . '&level=' . urlencode($lv);
              echo '<a href="'.$href.'" class="inline-flex items-center justify-center px-4 py-2 text-xs font-black rounded-xl transition-all mr-1 '.
                  ($active ? 'bg-white text-yellow-500 shadow-sm' : 'text-slate-500 hover:text-slate-900').
                  '">'.$lbl.'</a>';
            }
          ?>
        </div>
      </div>
    </div>

    <?php if (!$ordersTable): ?>
      <div class="bg-white rounded-3xl p-10 border border-slate-100 shadow-xl text-slate-700">
        order_products table not found. Expected <code>order_products</code>.
      </div>
      <?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; exit; ?>
    <?php endif; ?>

    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
      <div class="p-8 border-b border-slate-50 flex items-center justify-between">
        <h2 class="text-xl font-black text-slate-900">Progress Overview</h2>

        <div class="flex items-center gap-3">
          <!-- Desktop filter pills (style user dashboard) -->
          <div class="hidden md:flex bg-slate-200/50 p-1.5 rounded-2xl border border-slate-200 overflow-x-auto whitespace-nowrap">
            <?php
              $pill = [
                '' => 'All',
                'beginner' => 'Beginner',
                'intermediate' => 'Intermediate',
                'advanced' => 'Advanced',
              ];
              foreach ($pill as $lv => $lbl) {
                $active = ($level === $lv);
                $href = '?q=' . urlencode($q) . '&level=' . urlencode($lv);
                echo '<a href="'.$href.'" class="flex-shrink-0 min-w-max px-4 py-2 text-xs font-black rounded-xl transition-all '.
                    ($active ? 'bg-white text-yellow-500 shadow-sm' : 'text-slate-500 hover:text-slate-900').
                    '">'.$lbl.'</a>';
              }
            ?>
          </div>

          <a href="?export=1&q=<?= urlencode($q) ?>&level=<?= urlencode($level) ?>"
            class="text-xs font-black text-amber-600 uppercase tracking-widest hover:text-amber-700 transition-colors">
            Export CSV
          </a>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
              <th class="px-8 py-4">Student Details</th>
              <th class="px-8 py-4">Enrolled Course</th>
              <th class="px-8 py-4">Progress Matrix</th>
              <th class="px-8 py-4">Workbooks Monitor</th>
              <th class="px-8 py-4">Last Activity</th>
              <th class="px-8 py-4">Certificate</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-50">
            <?php foreach ($students as $s): ?>
              <?php
                $courseId = (string)($s["course_id"] ?? "");
                $wbs = $workbooksByCourse[$courseId] ?? [];
                $wbsShow = array_slice($wbs, 0, 3);
              ?>
              <tr class="hover:bg-slate-50/50 transition-colors align-top">
                <td class="px-8 py-6">
                  <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-500">
                      <?= e(mb_substr((string)($s["name"] ?? "U"), 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-black text-slate-900"><?= e((string)($s["name"] ?? "")) ?></div>
                      <div class="text-[10px] font-bold text-slate-400"><?= e((string)($s["student_id"] ?? "")) ?></div>
                    </div>
                  </div>
                </td>

                <td class="px-8 py-6">
                  <div class="text-xs font-bold text-slate-700"><?= e((string)($s["course_title"] ?: $courseId ?: "Unknown")) ?></div>
                  <?php if (!empty($s["course_level"])): ?>
                    <div class="text-[9px] font-black text-indigo-500 uppercase"><?= e((string)$s["course_level"]) ?></div>
                  <?php endif; ?>
                </td>

                <td class="px-8 py-6">
                  <div class="space-y-3 w-48">
                    <div>
                      <div class="flex justify-between text-[8px] font-black uppercase mb-1">
                        <span class="text-indigo-500">Videos</span><span><?= (int)$s["videos_pct"] ?>%</span>
                      </div>
                      <div class="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-500" style="width: <?= (int)$s["videos_pct"] ?>%"></div>
                      </div>
                    </div>
                    <div>
                      <div class="flex justify-between text-[8px] font-black uppercase mb-1">
                        <span class="text-emerald-500">E-books</span><span><?= (int)$s["ebooks_pct"] ?>%</span>
                      </div>
                      <div class="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500" style="width: <?= (int)$s["ebooks_pct"] ?>%"></div>
                      </div>
                    </div>
                    <div>
                      <div class="flex justify-between text-[8px] font-black uppercase mb-1">
                        <span class="text-amber-500">Workbook</span><span><?= (int)$s["workbooks_pct"] ?>%</span>
                      </div>
                      <div class="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-amber-500" style="width: <?= (int)$s["workbooks_pct"] ?>%"></div>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="px-8 py-6">
                  <div class="flex flex-col gap-2">
                    <?php if (!$wbs): ?>
                      <span class="text-[10px] text-slate-400 font-bold italic">No workbooks</span>
                    <?php else: ?>
                      <?php foreach ($wbsShow as $wb): ?>
                        <?php
                          $sid = k((string)(int)($s["user_id"] ?? 0));
                          $cid = k((string)$courseId);
                          $wid = k((string)($wb["wb_key"] ?? ""));

                          $studentFile = $userWorkbookFiles[$sid][$cid][$wid] ?? "";
                          if ($studentFile === "") {
                            $studentFile = $userWorkbookFiles[$sid][$cid]["__any__"] ?? "";
                          }
                          $finalUrl = $studentFile ? build_sheet_url($studentFile, (string)$wb["url"]) : (string)$wb["url"];
                        ?>
                        <a
                          href="<?= e($finalUrl) ?>"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="group inline-flex items-center justify-between px-3 py-2 bg-slate-50 border border-slate-100 text-slate-700 rounded-lg text-[9px] font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all w-full"
                        >
                          <span class="truncate max-w-[160px]"><?= e((string)$wb["title"]) ?></span>
                          <svg class="w-3 h-3 text-slate-400 group-hover:text-amber-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                          </svg>
                        </a>
                      <?php endforeach; ?>

                      <?php if (count($wbs) > 3): ?>
                        <span class="text-[10px] text-slate-400 font-bold">+<?= count($wbs) - 3 ?> more</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>

                <td class="px-8 py-6">
                  <div class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">
                    <?= e((string)$s["last_human"]) ?>
                  </div>
                </td>

                <td class="px-8 py-6">
                  <?php
                    $eligible = !empty($s["is_complete"]);
                    $certNo = (string)($s["cert_no"] ?? "");
                    $sentAt = (string)($s["cert_sent_at"] ?? "");
                  ?>
                  <?php if (!$eligible): ?>
                    <span class="inline-flex items-center px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-[10px] font-black uppercase tracking-widest">
                      Not eligible
                    </span>
                  <?php else: ?>
                    <div class="space-y-2">
                      <div class="text-[10px] font-black text-slate-500">
                        <?= $certNo ? "No: " . e($certNo) : "No: —" ?>
                      </div>

                      <?php if ($sentAt): ?>
                        <div class="text-[10px] font-black text-emerald-600 uppercase tracking-widest">
                          Sent
                        </div>
                      <?php else: ?>
                        <div class="text-[10px] font-black text-amber-600 uppercase tracking-widest">
                          Ready
                        </div>
                      <?php endif; ?>

                      <form method="post" class="inline-block">
                        <input type="hidden" name="user_id" value="<?= (int)($s["user_id"] ?? 0) ?>">
                        <input type="hidden" name="course_id" value="<?= e((string)$courseId) ?>">
                        <button
                          type="submit"
                          name="cert_action"
                          value="<?= $sentAt ? "resend" : "send" ?>"
                          class="inline-flex items-center justify-center px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest
                                 <?= $sentAt
                                    ? "bg-slate-900 text-white hover:bg-slate-800"
                                    : "bg-amber-500 text-white hover:bg-amber-600" ?>
                                 transition-all"
                        >
                          <?= $sentAt ? "Resend" : "Send Certificate" ?>
                        </button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (count($students) === 0): ?>
              <tr><td colspan="6" class="p-16 text-center text-slate-400 font-bold">No students found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>