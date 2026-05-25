<?php
declare(strict_types=1);
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/auth.php";

$id = (string)($_GET["id"] ?? "");
if ($id === "") redirect("courses.php");

$stmt = $conn->prepare("SELECT * FROM courses WHERE id=? LIMIT 1");
$stmt->bind_param("s", $id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
if (!$course) redirect("courses.php");

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_validate();

  $lvl = (string)($_POST["level"] ?? "");
  $title = trim((string)($_POST["title"] ?? ""));
  $desc = trim((string)($_POST["description"] ?? ""));
  $price = trim((string)($_POST["price"] ?? ""));
  $original_price = trim((string)($_POST["original_price"] ?? ""));
  if ($original_price === "") $original_price = null;
  $duration = trim((string)($_POST["duration"] ?? ""));
  $instructor = trim((string)($_POST["instructor"] ?? ""));
  $image = trim((string)($_POST["image"] ?? ""));

  if (!in_array($lvl, ["Beginner","Intermediate","Advanced"], true)) $errors[] = "Invalid level.";
  if ($title===""||$desc===""||$price===""||$duration===""||$instructor===""||$image==="") $errors[] = "All fields are required.";

  if (!$errors) {
    $stmt = $conn->prepare("UPDATE courses SET level=?, title=?, description=?, price=?, original_price=?, duration=?, instructor=?, image=?, updated_at=NOW() WHERE id=?");
    $stmt->bind_param("sssssssss", $lvl, $title, $desc, $price, $original_price, $duration, $instructor, $image, $id);
    $stmt->execute();
    $success = "Saved.";
    // refresh
    $stmt = $conn->prepare("SELECT * FROM courses WHERE id=? LIMIT 1");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
  }
}

$pageTitle = "Edit Course";
$pageDesc  = "Update the details. Go to the Content page to manage the content.";

$manageUrl = "contents.php?course_id=" . urlencode((string)$course["id"]);

$headerActionsHtmlDesktop = '
  <a href="'.e($manageUrl).'"
     class="inline-flex items-center gap-2 px-4 py-2 bg-yellow-500 text-white font-bold rounded-2xl shadow-lg shadow-yellow-100 hover:bg-yellow-600 transition">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
    </svg>
    Manage Content
  </a>
';

$headerActionsHtmlMobile = '
  <a href="'.e($manageUrl).'"
     class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white font-black shadow-sm"
     aria-label="Manage Content" title="Manage Content">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
    </svg>
  </a>
';

$title = "Edit Course " . $id;
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/header.php";
include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/nav.php";
?>
<div class="max-w-4xl mx-auto pb-12">

  <!-- Mobile title/desc in page -->
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

  <?php if ($success): ?>
    <div class="mb-6 bg-emerald-50 border border-emerald-100 text-emerald-700 px-4 py-3 rounded-2xl font-semibold"><?= e($success) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="mb-6 bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-2xl">
      <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Meta card -->
  <div class="mb-10">
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm px-5 py-4 md:px-6 md:py-5">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-400">
            <span class="px-3 py-1.5 rounded-full bg-slate-50 border border-slate-200 text-slate-600">
              <?= e($course["level"]) ?>
            </span>
            <span class="text-slate-300">•</span>
            <span class="font-black text-slate-500">
              <?= e($course["id"]) ?>
            </span>
          </div>

          <div class="mt-3">
            <div class="text-[11px] md:text-sm font-black text-slate-400 uppercase tracking-widest">Editing</div>
            <div class="text-xl md:text-2xl font-black text-slate-900 leading-snug break-words">
              <?= e($course["title"]) ?>
            </div>
          </div>
        </div>

        <div class="shrink-0">
          <span class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-yellow-50 border border-yellow-100 text-yellow-700 text-xs md:text-sm font-black whitespace-nowrap">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/>
            </svg>
            Edit Mode
          </span>
        </div>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 p-8">
    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">Level</label>
        <select name="level" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
          <?php foreach (["Beginner","Intermediate","Advanced"] as $opt): ?>
            <option <?= $course["level"]===$opt ? "selected" : "" ?>><?= e($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">Title</label>
        <input name="title" value="<?= e($course["title"]) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
      </div>

      <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">Description</label>
        <textarea name="description" rows="5" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl"><?= e($course["description"]) ?></textarea>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-bold text-slate-700 mb-2">Price</label>
          <input name="price" value="<?= e($course["price"]) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 mb-2">Original Price</label>
          <input name="original_price" value="<?= e($course["original_price"] ?? "") ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 mb-2">Duration</label>
          <input name="duration" value="<?= e($course["duration"]) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
        </div>
      </div>

      <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">Instructor</label>
        <input name="instructor" value="<?= e($course["instructor"]) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
      </div>

      <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">Image URL</label>
        <input name="image" value="<?= e($course["image"]) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl">
      </div>

      <div class="flex justify-end gap-3 pt-6">
        <a href="courses.php"
          class="px-6 py-2.5 text-slate-500 text-sm font-bold rounded-xl hover:bg-slate-50 transition-all">
          Discard
        </a>

        <button type="submit"
          class="px-10 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-extrabold rounded-xl shadow-lg transition-all active:scale-95">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<?php include rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/admin/partials/footer.php"; ?>