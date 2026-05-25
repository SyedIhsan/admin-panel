<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /admin/webinar/marketing.php");
    exit;
}

csrf_validate();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: /admin/webinar/marketing.php?error=invalid_id");
    exit;
}

$conn = getBillingConn();
if (!$conn) {
    header("Location: /admin/webinar/marketing.php?error=db");
    exit;
}

if ($action === 'toggle_status') {
    $stmt = $conn->prepare("SELECT status FROM sdc_webinar_marketing_emails WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        header("Location: /admin/webinar/marketing.php?error=not_found");
        exit;
    }

    $newStatus = ((string)$row['status'] === 'active') ? 'inactive' : 'active';
    $stmt = $conn->prepare("UPDATE sdc_webinar_marketing_emails SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $id);

    if ($stmt->execute()) {
        header("Location: /admin/webinar/marketing.php?success=status_updated");
    } else {
        header("Location: /admin/webinar/marketing.php?error=update_failed");
    }
    $stmt->close();
    exit;
}

if ($action === 'delete') {
    $stmt = $conn->prepare("DELETE FROM sdc_webinar_marketing_emails WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: /admin/webinar/marketing.php?success=deleted");
    } else {
        header("Location: /admin/webinar/marketing.php?error=delete_failed");
    }
    $stmt->close();
    exit;
}

header("Location: /admin/webinar/marketing.php");
exit;
