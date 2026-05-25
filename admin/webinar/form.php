<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

/** @var mysqli $conn */

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;

$webinar = [
    'webinar_title' => '',
    'webinar_desc' => '',
    'start_datetime' => date('Y-m-d\TH:i'),
    'end_datetime' => date('Y-m-d\TH:i', strtotime('+1 hour')),
    'timezone' => 'Asia/Kuala_Lumpur',
    'poster_url' => '',
    'zoom_join_url' => '',
    'email_subject' => 'Your Zoom Link - Webinar Registration',
    'status' => 'active'
];

if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM sdc_webinars WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $dbWebinar = $res->fetch_assoc();
    if (!$dbWebinar) {
        header("Location: /admin/webinar/index.php");
        exit;
    }
    $webinar = $dbWebinar;
    // Format for datetime-local input
    $webinar['start_datetime'] = date('Y-m-d\TH:i', strtotime($webinar['start_datetime']));
    $webinar['end_datetime'] = date('Y-m-d\TH:i', strtotime($webinar['end_datetime']));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $title = trim($_POST['webinar_title'] ?? '');
    $desc = trim($_POST['webinar_desc'] ?? '');
    $start = trim($_POST['start_datetime'] ?? '');
    $end = trim($_POST['end_datetime'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Asia/Kuala_Lumpur');
    $zoom = trim($_POST['zoom_join_url'] ?? '');
    $subject = trim($_POST['email_subject'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    // Basic Validation
    if ($title === '' || $start === '' || $end === '' || $zoom === '') {
        $error = "Please fill in all required fields.";
    } elseif (strtotime($end) <= strtotime($start)) {
        $error = "End time must be after start time.";
    }

    if (!$error) {
        $posterUrl = $webinar['poster_url'];

        // Handle File Upload
        if (isset($_FILES['poster_image']) && $_FILES['poster_image']['error'] === UPLOAD_ERR_OK) {
            $UPLOAD_SUBDIR = "uploads/SDC_webinars/";
            $root = realpath(__DIR__ . "/../../");
            $upload_dir = rtrim($root, '/') . "/" . $UPLOAD_SUBDIR;

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $tmp = $_FILES['poster_image']['tmp_name'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowed[$mime])) {
                $error = "Invalid image type. Use JPG/PNG/WebP only.";
            } elseif ($_FILES['poster_image']['size'] > 3000000) {
                $error = "Poster image too large (max 3MB).";
            } else {
                $ext = $allowed[$mime];
                $new_name = uniqid('webinar_', true) . "." . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                    $posterUrl = "https://" . $_SERVER['HTTP_HOST'] . "/" . $UPLOAD_SUBDIR . $new_name;
                } else {
                    $error = "Failed to upload image.";
                }
            }
        }

        if (!$error) {
            if ($isEdit) {
                $stmt = $conn->prepare("
                    UPDATE sdc_webinars 
                    SET webinar_title = ?, webinar_desc = ?, start_datetime = ?, end_datetime = ?, 
                        timezone = ?, poster_url = ?, zoom_join_url = ?, email_subject = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssssssi", 
                    $title, $desc, $start, $end, $timezone, $posterUrl, $zoom, $subject, $status, $id
                );
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO sdc_webinars 
                    (webinar_title, webinar_desc, start_datetime, end_datetime, timezone, poster_url, zoom_join_url, email_subject, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssssss", 
                    $title, $desc, $start, $end, $timezone, $posterUrl, $zoom, $subject, $status
                );
            }

            if ($stmt->execute()) {
                header("Location: /admin/webinar/index.php?success=" . ($isEdit ? 'updated' : 'created'));
                exit;
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}

$pageTitle = ($isEdit ? "Edit" : "Add") . " Webinar";
$pageDesc = $isEdit ? "Update existing webinar details." : "Create a new webinar campaign.";

include __DIR__ . "/../partials/header.php";
include __DIR__ . "/../partials/nav.php";
?>

<div class="max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="/admin/webinar/index.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-600 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Management
        </a>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700 font-bold"><?= h($error) ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data" class="space-y-8">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                <h2 class="text-lg font-bold text-slate-800">Basic Information</h2>
            </div>
            <div class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Webinar Title *</label>
                    <input type="text" name="webinar_title" value="<?= h($webinar['webinar_title']) ?>" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Description</label>
                    <textarea name="webinar_desc" rows="4"
                              class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800"><?= h((string)($webinar['webinar_desc'] ?? '')) ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Start Date & Time *</label>
                        <input type="datetime-local" name="start_datetime" value="<?= h((string)($webinar['start_datetime'] ?? '')) ?>" required
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">End Date & Time *</label>
                        <input type="datetime-local" name="end_datetime" value="<?= h((string)($webinar['end_datetime'] ?? '')) ?>" required
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Timezone</label>
                    <input type="text" name="timezone" value="<?= h($webinar['timezone']) ?>" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                <h2 class="text-lg font-bold text-slate-800">Registration & Email</h2>
            </div>
            <div class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Zoom Join URL *</label>
                    <input type="url" name="zoom_join_url" value="<?= h((string)($webinar['zoom_join_url'] ?? '')) ?>" required placeholder="https://zoom.us/..."
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Email Subject</label>
                    <input type="text" name="email_subject" value="<?= h((string)($webinar['email_subject'] ?? '')) ?>" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                <h2 class="text-lg font-bold text-slate-800">Media & Status</h2>
            </div>
            <div class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Webinar Poster (Image)</label>
                    <div class="flex items-start gap-6">
                        <?php if ($webinar['poster_url']): ?>
                            <div class="shrink-0">
                                <img src="<?= h($webinar['poster_url']) ?>" alt="Current Poster" class="w-32 h-20 object-cover rounded-xl border border-slate-200">
                                <p class="text-[10px] text-slate-400 mt-1 text-center font-bold uppercase tracking-tight">Current Poster</p>
                            </div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <input type="file" name="poster_image" accept="image/png,image/jpeg,image/webp"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white outline-none transition font-semibold text-slate-800 file:mr-4 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-yellow-50 file:text-yellow-700 hover:file:bg-yellow-100">
                            <p class="mt-2 text-xs text-slate-400 font-semibold">JPG, PNG or WebP. Max 3MB.</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Status</label>
                    <select name="status" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 focus:bg-white focus:border-yellow-500 focus:ring-1 focus:ring-yellow-500 outline-none transition font-semibold text-slate-800">
                        <option value="active" <?= $webinar['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $webinar['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-4">
            <a href="/admin/webinar/index.php" class="px-8 py-4 rounded-2xl text-slate-600 font-bold hover:bg-slate-100 transition">Cancel</a>
            <button type="submit" class="px-12 py-4 bg-yellow-500 text-white rounded-2xl font-black shadow-lg shadow-yellow-100 hover:bg-yellow-600 transition">
                <?= $isEdit ? "Update" : "Save" ?> Webinar
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . "/../partials/footer.php"; ?>
