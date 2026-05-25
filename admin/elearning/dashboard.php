<?php
declare(strict_types=1);
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";

// quick stats
$courseCount = (int)$conn->query("SELECT COUNT(*) c FROM courses")->fetch_assoc()["c"];
$videoCount  = (int)$conn->query("SELECT COUNT(*) c FROM course_videos")->fetch_assoc()["c"];
$ebookCount  = (int)$conn->query("SELECT COUNT(*) c FROM course_ebooks")->fetch_assoc()["c"];
$wbCount     = (int)$conn->query("SELECT COUNT(*) c FROM course_workbooks")->fetch_assoc()["c"];

// ===== KPI (REAL DATA) =====

$activeLearners = 0;
$courseRevenue  = 0.0;
$revLabel       = 'RM0.00';

$hasOrderProducts = false;
$chkOP = $conn->query("SHOW TABLES LIKE 'order_products'");
if ($chkOP && $chkOP->num_rows > 0) $hasOrderProducts = true;

// Use EL_EMAIL_FILTER and EL_OP_EMAIL_FILTER from db.php (included via bootstrap -> db_router)
// Actually, admin/elearning/dashboard.php uses auth.php which may not include db.php directly.
// But courses.php does include db.php.
require_once __DIR__ . '/db.php';

if ($hasOrderProducts) {

  // Active learners = unique users yang ada at least 1 completed order
  $sqlActive = "
    SELECT COUNT(DISTINCT u.id) AS c
    FROM `user` u
    INNER JOIN `order_products` op
      ON LOWER(TRIM(op.customer_email)) = LOWER(TRIM(u.email))
    WHERE LOWER(op.status) = 'completed'
      AND (LOWER(op.product_type) = 'elearning_course' OR op.product_type IS NULL OR op.product_type = '')
      AND u.usertype = 0
      AND " . EL_OP_EMAIL_FILTER . "
  ";
  $activeLearners = (int)($conn->query($sqlActive)->fetch_assoc()['c'] ?? 0);

  // Revenue = sum of completed orders
  $sqlRevenue = "
    SELECT COALESCE(SUM(amount), 0) AS s
    FROM `order_products`
    WHERE LOWER(status) = 'completed'
      AND (LOWER(product_type) = 'elearning_course' OR product_type IS NULL OR product_type = '')
      AND " . EL_OP_EMAIL_FILTER . "
  ";
  $courseRevenue = (float)($conn->query($sqlRevenue)->fetch_assoc()['s'] ?? 0.0);

  $revLabel = $courseRevenue >= 1000
    ? 'RM' . number_format($courseRevenue / 1000, 1) . 'k'
    : 'RM' . number_format($courseRevenue, 2);
}

// Avg completion = only possible if progress table exists
$avgCompletion = null;
$chk = $conn->query("SHOW TABLES LIKE 'user_progress'");
if ($chk && $chk->num_rows > 0) {

  $sqlAvg = "
    SELECT ROUND(AVG(pct)) AS avg_pct
    FROM (
      SELECT up.user_id, up.course_id,
        (SUM(CASE WHEN up.completed=1 THEN 1 ELSE 0 END) /
         NULLIF(
           (SELECT COUNT(*) FROM course_videos    cv WHERE cv.course_id=up.course_id) +
           (SELECT COUNT(*) FROM course_ebooks    ce WHERE ce.course_id=up.course_id) +
           (SELECT COUNT(*) FROM course_workbooks cw WHERE cw.course_id=up.course_id)
         ,0)
        ) * 100 AS pct
      FROM user_progress up
      INNER JOIN `user` u ON u.id = up.user_id
      WHERE u.usertype = 0
        AND " . EL_EMAIL_FILTER . "
      GROUP BY up.user_id, up.course_id
    ) t
  ";

  $row = $conn->query($sqlAvg)->fetch_assoc();
  $avgCompletion = ($row && $row["avg_pct"] !== null) ? (int)$row["avg_pct"] : 0;
}

$title = "e-Learning Dashboard";

$pageTitle = "Dashboard";
$pageDesc  = "Manage courses & learning content.";

// Desktop actions (akan duduk belah kanan header desktop)
$headerActionsHtmlDesktop = '
  <a href="courses.php"
     class="inline-flex items-center gap-2 px-4 py-2 bg-yellow-500 text-white font-bold rounded-2xl shadow-lg shadow-yellow-100 hover:bg-yellow-600 transition">
     <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14M5 12h14"/>
     </svg>
     Add / Edit Courses
  </a>

  <a href="contents.php"
     class="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 text-white font-bold rounded-2xl hover:bg-slate-800 transition">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
    </svg>
     Manage Content
  </a>
';

// Mobile actions (icon buttons supaya muat sebelah bell)
$headerActionsHtmlMobile = '
  <a href="courses.php"
     class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white font-black shadow-sm">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14M5 12h14"/>
    </svg>
  </a>
  <a href="contents.php"
     class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
    </svg>
  </a>
';

include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="max-w-7xl mx-auto">
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
  <!-- KPI Overview -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10 min-w-0">
    <div class="bg-white p-6 sm:p-8 rounded-[2rem] border border-slate-100 shadow-sm flex items-center justify-between min-w-0">
      <div class="min-w-0">
        <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-1">Active Learners</p>
        <p class="text-4xl font-black text-slate-900"><?= number_format($activeLearners) ?></p>
      </div>
      <div class="w-14 h-14 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
    </div>

    <div class="bg-white p-6 sm:p-8 rounded-[2rem] border border-slate-100 shadow-sm flex items-center justify-between min-w-0">
      <div class="min-w-0">
        <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-1">Avg. Completion</p>
        <p class="text-4xl font-black text-slate-900">
          <?= $avgCompletion === null ? '—' : ((int)$avgCompletion . '%') ?>
        </p>
      </div>
      <div class="w-14 h-14 bg-amber-500 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
    </div>

    <div class="bg-white p-6 sm:p-8 rounded-[2rem] border border-slate-100 shadow-sm flex items-center justify-between min-w-0">
      <div class="min-w-0">
        <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-1">Course Revenue</p>
        <p class="text-4xl font-black text-slate-900"><?= $revLabel ?></p>
      </div>
      <div class="w-14 h-14 bg-emerald-500 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
    </div>
  </div>

  <div class="grid md:grid-cols-4 gap-6">
    <div class="bg-white rounded-3xl p-8 border border-slate-100 shadow-xl shadow-slate-200/40">
      <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Courses</div>
      <div class="text-4xl font-black text-yellow-500"><?= $courseCount ?></div>
    </div>
    <div class="bg-white rounded-3xl p-8 border border-slate-100 shadow-xl shadow-slate-200/40">
      <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Videos</div>
      <div class="text-4xl font-black text-yellow-500"><?= $videoCount ?></div>
    </div>
    <div class="bg-white rounded-3xl p-8 border border-slate-100 shadow-xl shadow-slate-200/40">
      <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Ebooks</div>
      <div class="text-4xl font-black text-yellow-500"><?= $ebookCount ?></div>
    </div>
    <div class="bg-white rounded-3xl p-8 border border-slate-100 shadow-xl shadow-slate-200/40">
      <div class="text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Workbooks</div>
      <div class="text-4xl font-black text-yellow-500"><?= $wbCount ?></div>
    </div>
  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>