<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

/** @var mysqli $conn */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /admin/webinar/index.php");
    exit;
}

csrf_validate();

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: /admin/webinar/index.php?error=invalid_id");
    exit;
}

if ($action === 'toggle_status') {
    $newStatus = $_POST['status'] === 'active' ? 'active' : 'inactive';
    $stmt = $conn->prepare("UPDATE sdc_webinars SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $id);
    
    if ($stmt->execute()) {
        header("Location: /admin/webinar/index.php?success=status_updated");
    } else {
        header("Location: /admin/webinar/index.php?error=update_failed");
    }
    exit;
}

if ($action === 'delete') {
    // We only allow delete if verified safe. For now, let's keep it simple.
    // In a real scenario, we might want to check for registrations first.
    
    // Get poster URL to delete file
    $stmt = $conn->prepare("SELECT poster_url FROM sdc_webinars WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $webinar = $stmt->get_result()->fetch_assoc();
    
    if ($webinar) {
        $stmt = $conn->prepare("DELETE FROM sdc_webinars WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Optional: delete poster file if it's local
            $filename = basename($webinar['poster_url']);
            $UPLOAD_SUBDIR = "uploads/SDC_webinars/";
            $root = realpath(__DIR__ . "/../../");
            $poster_path = rtrim($root, '/') . "/" . $UPLOAD_SUBDIR . $filename;
            if (file_exists($poster_path)) {
                @unlink($poster_path);
            }
            header("Location: /admin/webinar/index.php?success=deleted");
        } else {
            header("Location: /admin/webinar/index.php?error=delete_failed");
        }
    } else {
        header("Location: /admin/webinar/index.php?error=not_found");
    }
    exit;
}

header("Location: /admin/webinar/index.php");
exit;
