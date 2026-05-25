<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kuala_Lumpur');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

session_start();

/**
 * =========================
 * DB BOOTSTRAP
 * =========================
 * - Payment DB  : /api/db.php
 * - eLearning DB: /admin/elearning/db.php
 */

require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db.php';
$connPay = $conn ?? null;

if (!$connPay instanceof mysqli) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Payment DB unavailable"]);
  exit;
}

$connEL = null;
$elearnDbFile = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/admin/elearning/db.php';
if (file_exists($elearnDbFile)) {
  require $elearnDbFile; // usually sets $conn
  if (isset($conn) && $conn instanceof mysqli) {
    $connEL = $conn;
  }
}

// restore generic $conn to payment connection (avoid accidental overwrite later)
$conn = $connPay;

$COLL = "utf8mb4_unicode_ci";

// Only completed, verified, non-zero-price payments appear in the bell.
// Mirrors $REAL_PAY_WHERE logic in admin/payment/dashboard.php.
$COMPLETED_WHERE = "LOWER(TRIM(COALESCE(`status`,''))) = 'completed'"
                 . " AND COALESCE(`verified`,0) = 1"
                 . " AND COALESCE(`price`,0) > 0";

/**
 * =========================
 * HELPERS
 * =========================
 */

function timeAgo(string $ts): string {
  try {
    $tz = new DateTimeZone("Asia/Kuala_Lumpur");
    $now = new DateTime("now", $tz);
    $dt  = new DateTime($ts, $tz);
    $diff = $now->getTimestamp() - $dt->getTimestamp();

    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff / 60) . " min ago";
    if ($diff < 86400) return floor($diff / 3600) . " hours ago";
    return floor($diff / 86400) . " days ago";
  } catch (Throwable $e) {
    return $ts;
  }
}

function money(float $n): string {
  return "RM" . number_format($n, 2);
}

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

/**
 * Build subquery for total content per course.
 */
function build_totals_subquery(?mysqli $conn, string $table, string $alias, string $coll): string {
  if (!$conn || !table_exists($conn, $table) || !column_exists($conn, $table, "course_id")) {
    return "SELECT '' AS course_key, 0 AS {$alias} WHERE 1=0";
  }

  return "
    SELECT
      (LOWER(TRIM(course_id)) COLLATE {$coll}) AS course_key,
      COUNT(*) AS {$alias}
    FROM {$table}
    GROUP BY (LOWER(TRIM(course_id)) COLLATE {$coll})
  ";
}

/**
 * =========================
 * INPUT
 * =========================
 */

$scope = strtolower((string)($_GET["scope"] ?? "payment")); // payment|elearning|all
$allowedScopes = ["payment", "elearning", "all"];
if (!in_array($scope, $allowedScopes, true)) $scope = "payment";

$mode = (string)($_GET["mode"] ?? "list"); // list|count|mark_seen
$allowedModes = ["list", "count", "mark_seen"];
if (!in_array($mode, $allowedModes, true)) $mode = "list";

/**
 * =========================
 * SESSION SEEN-AT
 * =========================
 */

$tz = new DateTimeZone("Asia/Kuala_Lumpur");

if (!isset($_SESSION["notif_seen_at_payment"])) {
  $_SESSION["notif_seen_at_payment"] = (new DateTime("today 00:00:00", $tz))->format("Y-m-d H:i:s");
}
if (!isset($_SESSION["notif_seen_at_elearning"])) {
  $_SESSION["notif_seen_at_elearning"] = (new DateTime("today 00:00:00", $tz))->format("Y-m-d H:i:s");
}

$seenPay = (string)$_SESSION["notif_seen_at_payment"];
$seenEL  = (string)$_SESSION["notif_seen_at_elearning"];

/**
 * =========================
 * MARK SEEN
 * =========================
 */

if ($mode === "mark_seen") {
  $now = (new DateTime("now", $tz))->format("Y-m-d H:i:s");

  if ($scope === "payment" || $scope === "all") {
    $_SESSION["notif_seen_at_payment"] = $now;
  }
  if ($scope === "elearning" || $scope === "all") {
    $_SESSION["notif_seen_at_elearning"] = $now;
  }

  echo json_encode(["ok" => true]);
  exit;
}

/**
 * =========================
 * COUNT MODE
 * =========================
 */

if ($mode === "count") {
  $count = 0;

  // -------------------------
  // Payment count
  // -------------------------
  if ($scope === "payment" || $scope === "all") {
    if (table_exists($connPay, "Payment") && column_exists($connPay, "Payment", "timestamp")) {
      $stmt = $connPay->prepare("SELECT COUNT(*) AS c FROM `Payment` WHERE `timestamp` > ? AND (" . ENV_PAY_WHERE . ") AND " . $COMPLETED_WHERE);
      if ($stmt) {
        $stmt->bind_param("s", $seenPay);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? [];
        $count += (int)($row["c"] ?? 0);
        $stmt->close();
      }
    }
  }

  // -------------------------
  // e-Learning count (COURSE COMPLETED)
  // -------------------------
  if (($scope === "elearning" || $scope === "all") && $connEL instanceof mysqli) {
    if (table_exists($connEL, "user_progress")) {
      $tsCol = column_exists($connEL, "user_progress", "updated_at")
        ? "updated_at"
        : (column_exists($connEL, "user_progress", "created_at") ? "created_at" : null);

      $hasCompleted = column_exists($connEL, "user_progress", "completed");
      $hasType      = column_exists($connEL, "user_progress", "content_type");
      $hasCourseId  = column_exists($connEL, "user_progress", "course_id");
      $hasUserId    = column_exists($connEL, "user_progress", "user_id");

      if ($tsCol && $hasCompleted && $hasType && $hasCourseId && $hasUserId) {
        $tvSub = build_totals_subquery($connEL, "course_videos", "total_v", $COLL);
        $teSub = build_totals_subquery($connEL, "course_ebooks", "total_e", $COLL);
        $twSub = build_totals_subquery($connEL, "course_workbooks", "total_w", $COLL);

        $hasUserTable = table_exists($connEL, "user");
        $userFilter = ($hasUserTable && defined('EL_EMAIL_FILTER')) 
          ? "INNER JOIN `user` u ON u.id = up.user_id WHERE up.completed = 1 AND " . EL_EMAIL_FILTER
          : "WHERE up.completed = 1";

        $sql = "
          SELECT COUNT(*) AS c
          FROM (
            SELECT
              up.user_id,
              (LOWER(TRIM(up.course_id)) COLLATE {$COLL}) AS course_key,
              MAX(up.`{$tsCol}`) AS ts,
              SUM(CASE WHEN up.completed = 1 AND up.content_type = 'video' THEN 1 ELSE 0 END) AS done_v,
              SUM(CASE WHEN up.completed = 1 AND up.content_type = 'ebook' THEN 1 ELSE 0 END) AS done_e,
              SUM(CASE WHEN up.completed = 1 AND up.content_type = 'workbook' THEN 1 ELSE 0 END) AS done_w
            FROM user_progress up
            {$userFilter}
            GROUP BY
              up.user_id,
              (LOWER(TRIM(up.course_id)) COLLATE {$COLL})
          ) p
          LEFT JOIN ({$tvSub}) tv ON tv.course_key = p.course_key
          LEFT JOIN ({$teSub}) te ON te.course_key = p.course_key
          LEFT JOIN ({$twSub}) tw ON tw.course_key = p.course_key
          WHERE p.ts > ?
            AND (COALESCE(tv.total_v,0) = 0 OR p.done_v >= tv.total_v)
            AND (COALESCE(te.total_e,0) = 0 OR p.done_e >= te.total_e)
            AND (COALESCE(tw.total_w,0) = 0 OR p.done_w >= tw.total_w)
            AND (COALESCE(tv.total_v,0) + COALESCE(te.total_e,0) + COALESCE(tw.total_w,0) > 0)
        ";

        $stmt = $connEL->prepare($sql);
        if ($stmt) {
          $stmt->bind_param("s", $seenEL);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc() ?? [];
          $count += (int)($row["c"] ?? 0);
          $stmt->close();
        }
      }
    }
  }

  echo json_encode([
    "ok"    => true,
    "count" => $count,
  ]);
  exit;
}

/**
 * =========================
 * LIST MODE
 * =========================
 */

$items = [];

// -------------------------
// Payment list
// -------------------------
if ($scope === "payment" || $scope === "all") {
  if (
    table_exists($connPay, "Payment") &&
    column_exists($connPay, "Payment", "id") &&
    column_exists($connPay, "Payment", "transaction_id") &&
    column_exists($connPay, "Payment", "name") &&
    column_exists($connPay, "Payment", "item") &&
    column_exists($connPay, "Payment", "package") &&
    column_exists($connPay, "Payment", "channel") &&
    column_exists($connPay, "Payment", "price") &&
    column_exists($connPay, "Payment", "timestamp")
  ) {
    $stmt = $connPay->prepare("
      SELECT `id`, `transaction_id`, `name`, `item`, `package`, `channel`, `price`, `timestamp`
      FROM `Payment`
      WHERE (" . ENV_PAY_WHERE . ") AND " . $COMPLETED_WHERE . "
      ORDER BY `timestamp` DESC
      LIMIT 8
    ");

    if ($stmt) {
      $stmt->execute();
      $res = $stmt->get_result();

      while ($r = $res->fetch_assoc()) {
        $id = (int)($r["id"] ?? 0);
        $ts = (string)($r["timestamp"] ?? "");

        $items[] = [
          "scope"   => "payment",
          "title"   => "Payment received",
          "name"    => (string)($r["name"] ?? ""),
          "item"    => (string)($r["item"] ?? ""),
          "package" => (string)($r["package"] ?? ""),
          "channel" => (string)($r["channel"] ?? ""),
          "amount"  => money((float)($r["price"] ?? 0)),
          "timeAgo" => $ts ? timeAgo($ts) : "",
          "trx"     => (string)($r["transaction_id"] ?? ""),
          "id"      => $id,
          "url"     => "/admin/payment/transaction-detail.php?id=" . urlencode((string)$id),
          "_ts"     => $ts,
        ];
      }

      $stmt->close();
    }
  }
}

// -------------------------
// e-Learning COMPLETION list
// -------------------------
if (($scope === "elearning" || $scope === "all") && $connEL instanceof mysqli) {
  if (table_exists($connEL, "user_progress")) {
    $tsCol = column_exists($connEL, "user_progress", "updated_at")
      ? "updated_at"
      : (column_exists($connEL, "user_progress", "created_at") ? "created_at" : null);

    $hasCompleted = column_exists($connEL, "user_progress", "completed");
    $hasType      = column_exists($connEL, "user_progress", "content_type");
    $hasCourseId  = column_exists($connEL, "user_progress", "course_id");
    $hasUserId    = column_exists($connEL, "user_progress", "user_id");

    if ($tsCol && $hasCompleted && $hasType && $hasCourseId && $hasUserId) {
      $tvSub = build_totals_subquery($connEL, "course_videos", "total_v", $COLL);
      $teSub = build_totals_subquery($connEL, "course_ebooks", "total_e", $COLL);
      $twSub = build_totals_subquery($connEL, "course_workbooks", "total_w", $COLL);

      $hasUserTable   = table_exists($connEL, "user");
      $hasCoursesTable = table_exists($connEL, "courses");

      $joinUser = $hasUserTable
        ? "INNER JOIN `user` u ON u.id = p.user_id"
        : "";

      $joinCourse = $hasCoursesTable
        ? "LEFT JOIN courses c ON (LOWER(TRIM(c.id)) COLLATE {$COLL}) = p.course_key"
        : "";

      $selectUserName  = $hasUserTable && column_exists($connEL, "user", "name")  ? "u.name AS user_name"   : "'' AS user_name";
      $selectUserEmail = $hasUserTable && column_exists($connEL, "user", "email") ? "u.email AS user_email" : "'' AS user_email";
      $selectCourseTitle = $hasCoursesTable && column_exists($connEL, "courses", "title") ? "c.title AS course_title" : "'' AS course_title";

      $userProgressFilter = ($hasUserTable && defined('EL_EMAIL_FILTER'))
        ? "INNER JOIN `user` u ON u.id = up.user_id WHERE up.completed = 1 AND " . EL_EMAIL_FILTER
        : "WHERE up.completed = 1";

      $sql = "
        SELECT
          p.user_id,
          p.course_id,
          p.ts,
          {$selectUserName},
          {$selectUserEmail},
          {$selectCourseTitle}
        FROM (
          SELECT
            up.user_id,
            MAX(up.course_id) AS course_id,
            (LOWER(TRIM(up.course_id)) COLLATE {$COLL}) AS course_key,
            MAX(up.`{$tsCol}`) AS ts,
            SUM(CASE WHEN up.completed = 1 AND up.content_type = 'video' THEN 1 ELSE 0 END) AS done_v,
            SUM(CASE WHEN up.completed = 1 AND up.content_type = 'ebook' THEN 1 ELSE 0 END) AS done_e,
            SUM(CASE WHEN up.completed = 1 AND up.content_type = 'workbook' THEN 1 ELSE 0 END) AS done_w
          FROM user_progress up
          {$userProgressFilter}
          GROUP BY
            up.user_id,
            (LOWER(TRIM(up.course_id)) COLLATE {$COLL})
        ) p
        LEFT JOIN ({$tvSub}) tv ON tv.course_key = p.course_key
        LEFT JOIN ({$teSub}) te ON te.course_key = p.course_key
        LEFT JOIN ({$twSub}) tw ON tw.course_key = p.course_key
        {$joinUser}
        {$joinCourse}
        WHERE
          (COALESCE(tv.total_v,0) = 0 OR p.done_v >= tv.total_v)
          AND (COALESCE(te.total_e,0) = 0 OR p.done_e >= te.total_e)
          AND (COALESCE(tw.total_w,0) = 0 OR p.done_w >= tw.total_w)
          AND (COALESCE(tv.total_v,0) + COALESCE(te.total_e,0) + COALESCE(tw.total_w,0) > 0)
        ORDER BY p.ts DESC
        LIMIT 8
      ";

      $stmt = $connEL->prepare($sql);
      if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
          $ts          = (string)($r["ts"] ?? "");
          $email       = trim((string)($r["user_email"] ?? ""));
          $name        = trim((string)($r["user_name"] ?? ""));
          $courseTitle = trim((string)($r["course_title"] ?? ""));
          $courseId    = trim((string)($r["course_id"] ?? ""));

          $q = $email !== ""
            ? $email
            : ($name !== "" ? $name : $courseId);

          $items[] = [
            "scope"   => "elearning",
            "title"   => "Course completed",
            "name"    => $name !== "" ? $name : "Student",
            "item"    => $courseTitle !== "" ? $courseTitle : $courseId,
            "package" => "COMPLETED",
            "channel" => "e-Learning",
            "amount"  => "",
            "timeAgo" => $ts ? timeAgo($ts) : "",
            "trx"     => "",
            "id"      => 0,
            "url"     => "/admin/elearning/progress.php?q=" . urlencode($q),
            "_ts"     => $ts,
          ];
        }

        $stmt->close();
      }
    }
  }
}

// sort merged list by timestamp desc
usort($items, function ($a, $b) {
  $ta = strtotime((string)($a["_ts"] ?? "")) ?: 0;
  $tb = strtotime((string)($b["_ts"] ?? "")) ?: 0;
  return $tb <=> $ta;
});

// keep latest 8 after merge
$items = array_slice($items, 0, 8);

// remove private field
foreach ($items as &$it) {
  unset($it["_ts"]);
}
unset($it);

echo json_encode([
  "ok"     => true,
  "scope"  => $scope,
  "seenAt" => [
    "payment"   => $seenPay,
    "elearning" => $seenEL,
  ],
  "items"  => $items,
]);