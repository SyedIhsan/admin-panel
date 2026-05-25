<?php
declare(strict_types=1);
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";

function extract_google_sheet_id(string $input): string {
  $s = trim($input);

  // if user just pastes ID
  if (preg_match('~^[a-zA-Z0-9_-]{20,}$~', $s)) {
    return $s;
  }

  $patterns = [
    // Google Sheets standard
    '~https?://docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)~',
    // Drive file link
    '~https?://drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~',
    // open?id=...
    '~[?&]id=([a-zA-Z0-9_-]+)~',
  ];

  foreach ($patterns as $p) {
    if (preg_match($p, $s, $m)) return $m[1];
  }

  return "";
}

$courseId = (string)($_GET["course_id"] ?? "");

// Fetch courses for dropdown
$resCourses = $conn->query("SELECT id, title, level FROM courses ORDER BY created_at DESC");
$allCourses = $resCourses ? $resCourses->fetch_all(MYSQLI_ASSOC) : [];

function must_course(string $courseId): array {
  global $conn;
  if ($courseId === "") return [];
  $stmt = $conn->prepare("SELECT * FROM courses WHERE id=? LIMIT 1");
  $stmt->bind_param("s", $courseId);
  $stmt->execute();
  $c = $stmt->get_result()->fetch_assoc();
  return $c ?: [];
}

function clip_text(string $s, int $max): string {
  $s = trim($s);
  if ($s === "") return "";
  $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
  if ($len <= $max) return $s;
  $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
  return $cut . "…";
}

$course = $courseId ? must_course($courseId) : [];
$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_validate();
  $action = (string)($_POST["action"] ?? "");
  $courseId = (string)($_POST["course_id"] ?? $courseId);

  if ($courseId === "") $errors[] = "Pick a course first.";
  else {
    if ($action === "add_video") {
      $title = trim((string)($_POST["title"] ?? ""));
      $url = trim((string)($_POST["url"] ?? ""));
      $desc = trim((string)($_POST["description"] ?? ""));
      if ($title===""||$url==="") $errors[] = "Video title & URL required.";
      if (!$errors) {
        $stmt = $conn->prepare("INSERT INTO course_videos (course_id,title,url,description) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $courseId, $title, $url, $desc);
        $stmt->execute();
        $success = "Video added.";
      }
    }

    if ($action === "add_ebook") {
      $title = trim((string)($_POST["title"] ?? ""));
      $content = (string)($_POST["content"] ?? "");
      if ($title===""||trim($content)==="") $errors[] = "Ebook title & HTML content required.";
      if (!$errors) {
        $stmt = $conn->prepare("INSERT INTO course_ebooks (course_id,title,content) VALUES (?,?,?)");
        $stmt->bind_param("sss", $courseId, $title, $content);
        $stmt->execute();
        $success = "Ebook added.";
      }
    }

    if ($action === "add_workbook") {
      $title = trim((string)($_POST["title"] ?? ""));
      $contentId = trim((string)($_POST["content_id"] ?? ""));
      $urlInput = trim((string)($_POST["url"] ?? ""));

      if ($title === "" || $urlInput === "") {
        $errors[] = "Workbook title & Google Sheet URL required.";
      }

      $templateId = extract_google_sheet_id($urlInput);
      if (!$errors && $templateId === "") {
        $errors[] = "Cannot extract Google Sheet File ID. Make sure link is like: https://docs.google.com/spreadsheets/d/<FILE_ID>/edit";
      }

      function next_workbook_content_id(mysqli $conn, string $courseId): string {
        // Ambil nombor paling besar dari content_id format: <courseId>-<n>
        $stmt = $conn->prepare("
          SELECT MAX(CAST(SUBSTRING_INDEX(content_id, '-', -1) AS UNSIGNED)) AS mx
          FROM course_workbooks
          WHERE course_id=? AND content_id LIKE CONCAT(?, '-%')
        ");
        $stmt->bind_param("ss", $courseId, $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mx = (int)($row["mx"] ?? 0);

        return $courseId . "-" . ($mx + 1);
      }

      if (!$errors) {
        // normalize url to be consistent
$normalizedUrl = "https://docs.google.com/spreadsheets/d/" . $templateId . "/edit";

// Auto-generate content_id kalau kosong: beg-101-1, beg-101-2, ...
$cid = trim($contentId);
if ($cid === "") {
  $cid = next_workbook_content_id($conn, $courseId);
}

try {
  // retry sikit kalau collision (rare, tapi solid)
  for ($i = 0; $i < 3; $i++) {
    try {
      $stmt = $conn->prepare("INSERT INTO course_workbooks (course_id,content_id,title,url,template_file_id) VALUES (?,?,?,?,?)");
      $stmt->bind_param("sssss", $courseId, $cid, $title, $normalizedUrl, $templateId);
      $stmt->execute();
      break;
    } catch (mysqli_sql_exception $e) {
      // duplicate content_id (1062) => regen kalau auto
      if ($e->getCode() == 1062 && trim($contentId) === "") {
        $cid = next_workbook_content_id($conn, $courseId);
        continue;
      }
      throw $e;
    }
  }
} catch (Throwable $e) {
  // fallback kalau column content_id belum wujud
  $stmt = $conn->prepare("INSERT INTO course_workbooks (course_id,title,url,template_file_id) VALUES (?,?,?,?)");
  $stmt->bind_param("ssss", $courseId, $title, $normalizedUrl, $templateId);
  $stmt->execute();
}

$success = "Workbook template added (File ID captured).";
      }
    }

    if ($action === "del_video") {
      $id = (int)($_POST["id"] ?? 0);
      $stmt = $conn->prepare("DELETE FROM course_videos WHERE id=? AND course_id=?");
      $stmt->bind_param("is", $id, $courseId);
      $stmt->execute();
      $success = "Video deleted.";
    }

    if ($action === "del_ebook") {
      $id = (int)($_POST["id"] ?? 0);
      $stmt = $conn->prepare("DELETE FROM course_ebooks WHERE id=? AND course_id=?");
      $stmt->bind_param("is", $id, $courseId);
      $stmt->execute();
      $success = "Ebook deleted.";
    }

    if ($action === "del_workbook") {
      $id = (int)($_POST["id"] ?? 0);
      $stmt = $conn->prepare("DELETE FROM course_workbooks WHERE id=? AND course_id=?");
      $stmt->bind_param("is", $id, $courseId);
      $stmt->execute();
      $success = "Workbook deleted.";
    }

    // redirect to keep GET course_id consistent
    redirect("contents.php?course_id=" . urlencode($courseId));
  }
}

$course = $courseId ? must_course($courseId) : [];

$videos = [];
$ebooks = [];
$workbooks = [];

if ($courseId) {
  $stmt = $conn->prepare("SELECT * FROM course_videos WHERE course_id=? ORDER BY id ASC");
  $stmt->bind_param("s", $courseId);
  $stmt->execute();
  $videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $stmt = $conn->prepare("SELECT * FROM course_ebooks WHERE course_id=? ORDER BY id ASC");
  $stmt->bind_param("s", $courseId);
  $stmt->execute();
  $ebooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $stmt = $conn->prepare("SELECT * FROM course_workbooks WHERE course_id=? ORDER BY id ASC");
  $stmt->bind_param("s", $courseId);
  $stmt->execute();
  $workbooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = "Content";
$pageDesc  = "Manage videos / ebooks / workbooks. Make sure URL follows a format that student page iframe can load.";

$backUrl = "courses.php";

// Desktop: back link style (kemas, tak berat)
$headerActionsHtmlDesktop = '
  <a href="'.e($backUrl).'"
     class="inline-flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
    <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back to Courses
  </a>
';

$title = "Manage Content";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>

<div class="max-w-7xl mx-auto w-full py-6 min-w-0">
  <!-- Mobile title/desc in page -->
  <div class="md:hidden mb-8">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">
          <?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, "UTF-8") ?>
        </h1>
        <?php if (trim((string)$pageDesc) !== ""): ?>
          <p class="mt-2 text-sm font-semibold text-slate-500">
            <?= htmlspecialchars((string)$pageDesc, ENT_QUOTES, "UTF-8") ?>
          </p>
        <?php endif; ?>
      </div>

      <a href="<?= e($backUrl) ?>"
        class="inline-flex items-center gap-2 px-5 py-3
              text-base font-extrabold text-slate-700 hover:text-slate-900
              transition-all group whitespace-nowrap">
        <svg class="w-6 h-6 transition-transform group-hover:-translate-x-1"
            fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        <span>Courses</span>
      </a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="mb-6 bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-2xl">
      <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php
    // label untuk selected display
    $selectedLabel = "Select a course";
    if ($courseId !== "") {
      foreach ($allCourses as $c) {
        if ((string)$c["id"] === $courseId) {
          $selectedLabel =
            (string)$c["level"] . " • " . (string)$c["id"] . " • " . clip_text((string)$c["title"], 28);
          break;
        }
      }
    }
  ?>

  <div class="bg-white rounded-3xl border border-slate-100 shadow-xl shadow-slate-200/40 p-6 mb-8">
    <form id="coursePickerForm" method="GET" class="flex flex-col md:flex-row gap-3 items-stretch md:items-center min-w-0">

      <div class="relative flex-1 min-w-0">
        <input type="hidden" name="course_id" id="courseIdInput" value="<?= e($courseId) ?>">

        <!-- Button (acts like select) -->
        <button type="button" id="courseBtn"
          class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-left font-semibold text-slate-900 flex items-center justify-between gap-3">
          <span id="courseBtnLabel" class="truncate">
            <?= e($selectedLabel) ?>
          </span>
          <svg class="w-5 h-5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
          </svg>
        </button>

        <!-- Dropdown panel (bounded to card width) -->
        <div id="coursePanel" class="hidden absolute left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-50">
          <div class="p-3 border-b border-slate-100">
            <input id="courseSearch" type="text"
              placeholder="Search course..."
              class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none focus:ring-2 focus:ring-yellow-400">
          </div>

          <div id="courseList" class="max-h-64 overflow-y-auto p-2">
            <?php foreach ($allCourses as $c): ?>
              <?php
                $label = (string)$c["level"] . " • " . (string)$c["id"] . " • " . clip_text((string)$c["title"], 40);
              ?>
              <button type="button"
                data-course="<?= e((string)$c["id"]) ?>"
                data-label="<?= e($label) ?>"
                class="courseItem w-full text-left px-3 py-2 rounded-xl hover:bg-slate-50 font-semibold text-slate-900">
                <span class="block truncate"><?= e($label) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- optional: keep Load button kalau kau nak manual submit -->
      <button class="px-6 py-3 bg-slate-900 text-white font-bold rounded-2xl hover:bg-slate-800 transition">
        Load
      </button>
    </form>
  </div>

  <script>
  (function () {
    const btn   = document.getElementById("courseBtn");
    const panel = document.getElementById("coursePanel");
    const input = document.getElementById("courseIdInput");
    const label = document.getElementById("courseBtnLabel");
    const form  = document.getElementById("coursePickerForm");
    const search = document.getElementById("courseSearch");
    const list   = document.getElementById("courseList");

    if (!btn || !panel || !input || !label || !form || !search || !list) return;

    function openPanel() {
      panel.classList.remove("hidden");
      search.value = "";
      filter("");
      setTimeout(() => search.focus(), 0);
    }

    function closePanel() {
      panel.classList.add("hidden");
    }

    function togglePanel() {
      panel.classList.contains("hidden") ? openPanel() : closePanel();
    }

    function filter(q) {
      const qq = (q || "").toLowerCase();
      list.querySelectorAll(".courseItem").forEach(el => {
        const txt = (el.textContent || "").toLowerCase();
        el.style.display = txt.includes(qq) ? "" : "none";
      });
    }

    btn.addEventListener("click", (e) => {
      e.preventDefault();
      togglePanel();
    });

    search.addEventListener("input", () => filter(search.value));

    list.addEventListener("click", (e) => {
      const t = e.target.closest(".courseItem");
      if (!t) return;

      const course = t.getAttribute("data-course") || "";
      const lbl = t.getAttribute("data-label") || t.textContent || "Select a course";

      input.value = course;
      label.textContent = lbl.trim();

      closePanel();
      form.submit();
    });

    document.addEventListener("click", (e) => {
      if (!panel.contains(e.target) && !btn.contains(e.target)) closePanel();
    });

    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closePanel();
    });
  })();
  </script>

  <?php if (!$courseId): ?>
    <div class="bg-white rounded-[3rem] p-16 text-center border border-dashed border-slate-200">
      <h2 class="text-2xl font-bold text-slate-900 mb-2">Pick a course</h2>
      <p class="text-slate-500">After selecting, you can add video/ebook/workbook.</p>
    </div>
    <?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; exit; ?>
  <?php endif; ?>

  <div class="bg-slate-900 rounded-[3rem] p-10 text-white mb-10 relative overflow-hidden">
    <div class="relative z-10">
      <div class="text-xs font-black uppercase tracking-widest text-slate-300 mb-2"><?= e($course["level"]) ?> • <?= e($course["id"]) ?></div>
      <h2 class="text-2xl md:text-3xl font-black"><?= e($course["title"]) ?></h2>
      <p class="text-slate-300 mt-2">Student UI will reflect this content when you migrate fetch later.</p>
    </div>
    <div class="absolute top-0 right-0 w-64 h-64 bg-yellow-500/10 rounded-full blur-[80px] -mr-32 -mt-32"></div>
  </div>

  <div class="grid lg:grid-cols-3 gap-8 min-w-0">
    <!-- Add forms -->
    <div class="lg:col-span-1 space-y-8 min-w-0">
      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <h3 class="text-lg font-black mb-4">Add Video</h3>
        <form method="POST" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_video">
          <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
          <input name="title" placeholder="Video title" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <input name="url" placeholder="YouTube embed URL (https://www.youtube.com/embed/...)" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <textarea name="description" rows="3" placeholder="Optional description" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl"></textarea>
          <button class="w-full py-3 bg-yellow-500 text-white font-bold rounded-2xl hover:bg-yellow-600 transition">Add Video</button>
        </form>
      </div>

      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <h3 class="text-lg font-black mb-4">Add Ebook</h3>
        <form method="POST" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_ebook">
          <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
          <input name="title" placeholder="Ebook title" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <textarea name="content" rows="8" placeholder="HTML content (e.g. <h2>Title</h2><p>...</p>)" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl font-mono text-sm"></textarea>
          <button class="w-full py-3 bg-yellow-500 text-white font-bold rounded-2xl hover:bg-yellow-600 transition">Add Ebook</button>
        </form>
        <p class="text-xs text-slate-400 mt-3">Student page renders ebook content as HTML (same pattern as current data).</p>
      </div>

      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <h3 class="text-lg font-black mb-4">Add Workbook</h3>
        <form method="POST" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_workbook">
          <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
          <input name="content_id" placeholder="Workbook ID (e.g. beg-101-1)" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <input name="title" placeholder="Workbook title" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <input name="url" placeholder="Google Sheet share URL / embed URL" class="w-full min-w-0 px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <button class="w-full py-3 bg-yellow-500 text-white font-bold rounded-2xl hover:bg-yellow-600 transition">Add Workbook</button>
        </form>
      </div>
    </div>

    <!-- Lists -->
    <div class="lg:col-span-2 space-y-8 min-w-0">

      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-black">Videos</h3>
          <span class="text-xs font-black text-slate-400 uppercase tracking-widest"><?= count($videos) ?> items</span>
        </div>
        <?php if (!$videos): ?>
          <p class="text-slate-500">No videos yet.</p>
        <?php endif; ?>
        <div class="space-y-4">
          <?php foreach ($videos as $v): ?>
            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100 flex items-start justify-between gap-4 min-w-0 overflow-hidden">
              <div class="min-w-0">
                <div class="font-black text-slate-900 break-words"><?= e($v["title"]) ?></div>
                <div class="text-xs text-slate-500 break-all"><?= e($v["url"]) ?></div>
                <?php if (!empty($v["description"])): ?>
                  <div class="text-sm text-slate-600 mt-1 break-words"><?= e((string)$v["description"]) ?></div>
                <?php endif; ?>
              </div>

              <form method="POST" class="shrink-0" data-confirm="Delete this video?" data-confirm-desc="This action cannot be undone.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="del_video">
                <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
                <input type="hidden" name="id" value="<?= (int)$v["id"] ?>">

                <button type="submit"
                  class="px-4 py-2.5 bg-white border border-red-200 text-red-600 rounded-2xl font-black hover:bg-red-50 transition whitespace-nowrap">
                  Delete
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-black">Ebooks</h3>
          <span class="text-xs font-black text-slate-400 uppercase tracking-widest"><?= count($ebooks) ?> items</span>
        </div>
        <?php if (!$ebooks): ?>
          <p class="text-slate-500">No ebooks yet.</p>
        <?php endif; ?>
        <div class="space-y-4">
          <?php foreach ($ebooks as $e): ?>
            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100 flex items-start justify-between gap-4 min-w-0 overflow-hidden">
              <div class="min-w-0">
                <div class="font-black text-slate-900 break-words">
                  <?= e($e["title"]) ?>
                </div>
                <div class="text-xs text-slate-500 mt-1">
                  Stored as HTML (LONGTEXT).
                </div>
              </div>

              <form method="POST" class="shrink-0" data-confirm="Delete this ebook?" data-confirm-desc="This will remove the ebook record permanently.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="del_ebook">
                <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
                <input type="hidden" name="id" value="<?= (int)$e["id"] ?>">

                <button type="submit"
                  class="px-4 py-2.5 bg-white border border-red-200 text-red-600 rounded-2xl font-black hover:bg-red-50 transition whitespace-nowrap">
                  Delete
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-xl font-black">Workbooks</h3>
          <span class="text-xs font-black text-slate-400 uppercase tracking-widest"><?= count($workbooks) ?> items</span>
        </div>
        <?php if (!$workbooks): ?>
          <p class="text-slate-500">No workbooks yet.</p>
        <?php endif; ?>
        <div class="space-y-4">
          <?php foreach ($workbooks as $w): ?>
            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100 flex items-start justify-between gap-4 min-w-0 overflow-hidden">
              <div class="min-w-0">
                <div class="font-black text-slate-900 break-words">
                  <?= e($w["title"]) ?>
                </div>
                <div class="text-xs text-slate-500 break-all mt-1">
                  <?= e($w["url"]) ?>
                </div>

                <?php if (!empty($w["content_id"])): ?>
                  <div class="text-xs text-slate-400 font-semibold mt-1">
                    ID: <?= e((string)$w["content_id"]) ?>
                  </div>
                <?php endif; ?>
              </div>

              <form method="POST" class="shrink-0" data-confirm="Delete this workbook?" data-confirm-desc="This will remove the workbook record permanently.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="del_workbook">
                <input type="hidden" name="course_id" value="<?= e($courseId) ?>">
                <input type="hidden" name="id" value="<?= (int)$w["id"] ?>">

                <button type="submit"
                  class="px-4 py-2.5 bg-white border border-red-200 text-red-600 rounded-2xl font-black hover:bg-red-50 transition whitespace-nowrap">
                  Delete
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>
<!-- SDC Confirm Modal -->
<div id="sdcConfirm"
  class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
  <!-- backdrop -->
  <div data-sdc-confirm-close class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>

  <!-- panel -->
  <div
    class="relative w-full max-w-md rounded-[2rem] bg-white border border-slate-100 shadow-2xl shadow-slate-900/20
           transform transition-all duration-150 scale-95 opacity-0"
    role="dialog" aria-modal="true" aria-labelledby="sdcConfirmTitle" aria-describedby="sdcConfirmDesc"
    id="sdcConfirmPanel"
  >
    <div class="p-6">
      <div class="flex items-start gap-4">
        <div class="shrink-0 w-11 h-11 rounded-2xl bg-red-50 border border-red-100 flex items-center justify-center text-red-600">
          <!-- trash icon -->
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 7l-1 14H6L5 7m3 0V5a2 2 0 012-2h4a2 2 0 012 2v2m-9 0h10"/>
          </svg>
        </div>

        <div class="min-w-0">
          <h3 id="sdcConfirmTitle" class="text-lg font-black text-slate-900">
            Confirm
          </h3>
          <p id="sdcConfirmDesc" class="mt-1 text-sm font-semibold text-slate-500">
            Are you sure?
          </p>
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

  const titleEl = document.getElementById("sdcConfirmTitle");
  const descEl  = document.getElementById("sdcConfirmDesc");
  const btnOk   = modal.querySelector("[data-sdc-confirm-ok]");
  const btnCancel = modal.querySelector("[data-sdc-confirm-cancel]");

  let pendingForm = null;
  let lastActive = null;

  // ✅ Restore scroll (only when we stored it)
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
    lastActive = document.activeElement;

    const t = form.getAttribute("data-confirm") || "Confirm";
    const d = form.getAttribute("data-confirm-desc") || "Are you sure?";

    titleEl.textContent = t;
    descEl.textContent = d;

    modal.classList.remove("hidden");
    modal.classList.add("flex");
    document.documentElement.classList.add("overflow-hidden");

    requestAnimationFrame(() => {
      panel.classList.remove("opacity-0", "scale-95");
      panel.classList.add("opacity-100", "scale-100");
    });

    btnOk?.focus();
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

  // ✅ Intercept any form with data-confirm
  document.addEventListener("submit", (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute("data-confirm")) return;

    // ✅ Allow confirmed submission to pass through once
    if (form.dataset.sdcConfirmPass === "1") {
      delete form.dataset.sdcConfirmPass;
      return; // let it submit normally
    }

    e.preventDefault();
    open(form);
  }, true);

  // ✅ OK (confirm)
  btnOk?.addEventListener("click", () => {
    if (!pendingForm) return;

    // store scroll ONLY for delete actions
    try {
      const act = pendingForm.querySelector('input[name="action"]')?.value || "";
      if (act.startsWith("del_")) {
        sessionStorage.setItem("sdcScrollY", String(window.scrollY));
      }
    } catch (_) {}

    const f = pendingForm;

    // ✅ mark as confirmed so submit listener won't block it
    f.dataset.sdcConfirmPass = "1";

    close();

    setTimeout(() => {
      if (typeof f.requestSubmit === "function") f.requestSubmit();
      else f.submit();
    }, 80);
  });

  // Cancel + backdrop close
  btnCancel?.addEventListener("click", close);
  modal.addEventListener("click", (e) => {
    if (e.target && e.target.hasAttribute("data-sdc-confirm-close")) close();
  });

  // ESC close
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
  });
})();
</script>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>