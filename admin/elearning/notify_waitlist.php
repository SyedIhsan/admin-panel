<?php
// /admin/elearning/notify_waitlist.php — DEMO STUB
// Sends waitlist notification emails (now routed through ses-config.php stub).
session_start();
if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/db.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/') . '/api/ses-config.php';

$level       = strtolower(trim($_POST['level']        ?? $_GET['level']        ?? ''));
$courseTitle = trim($_POST['course_title'] ?? $_GET['course_title'] ?? '');
$courseUrl   = trim($_POST['course_url']   ?? $_GET['course_url']   ?? '');
$courseKey   = trim($_POST['course_key']   ?? $_GET['course_key']   ?? '');

if (!in_array($level, ['beginner', 'intermediate', 'advanced'], true)) exit('Invalid level');
if ($courseTitle === '' || $courseUrl === '' || $courseKey === '') exit('Missing params');

$stmt = $conn->prepare("
    SELECT email, token
    FROM course_waitlist
    WHERE level=? AND status='subscribed'
      AND (last_notified_course_key IS NULL OR last_notified_course_key <> ?)
");
if (!$stmt) exit('DB prepare failed');
$stmt->bind_param("ss", $level, $courseKey);
$stmt->execute();
$res = $stmt->get_result();

$sent   = 0;
$failed = 0;

while ($row = $res->fetch_assoc()) {
    $email = $row['email'];
    $token = $row['token'];
    $unsub = "https://demo.local/e-Learning/api/waitlist_unsubscribe.php?t=" . $token;

    $subject = "New course is live: " . $courseTitle;
    $html    = "<div style='font-family:Arial,sans-serif;line-height:1.6'>"
        . "<h2>Good news — new course is live</h2>"
        . "<p><b>" . htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8') . "</b> is now available for the <b>{$level}</b> track.</p>"
        . "<p><a href='" . htmlspecialchars($courseUrl, ENT_QUOTES, 'UTF-8') . "' style='padding:12px 16px;border-radius:12px;background:#eab308;color:#111827;text-decoration:none;font-weight:700'>View course</a></p>"
        . "<p style='color:#6b7280;font-size:12px;margin-top:24px'>If you don't want these emails, <a href='" . htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8') . "'>unsubscribe</a>.</p>"
        . "</div>";

    if (sendBrevo($email, $email, $subject, $html)) {
        $u = $conn->prepare("UPDATE course_waitlist SET last_notified_at=NOW(), last_notified_course_key=? WHERE email=? AND level=? LIMIT 1");
        $u->bind_param("sss", $courseKey, $email, $level);
        $u->execute();
        $u->close();
        $sent++;
    } else {
        $failed++;
    }
}

$stmt->close();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed], JSON_UNESCAPED_UNICODE);
