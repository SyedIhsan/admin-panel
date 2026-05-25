<?php
declare(strict_types=1);
require_once __DIR__ . "/_init.php";
require_once __DIR__ . "/../../api/ses-config.php";
require_once __DIR__ . "/../../api/mail/layout.php";

/** @var mysqli $conn */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /admin/webinar/index.php");
    exit;
}

csrf_validate();

$webinar_id = (int)($_POST['webinar_id'] ?? 0);
$send_mode = $_POST['send_mode'] ?? '';
$subjectTpl = trim((string)($_POST['subject'] ?? ''));
$bodyTpl = trim((string)($_POST['body'] ?? ''));

if ($webinar_id <= 0 || $subjectTpl === '' || $bodyTpl === '') {
    header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&error=invalid_input&error_msg=" . urlencode("Please fill in all required fields."));
    exit;
}

// ── Fetch Webinar Info ──────────────────────────────────────────────────────
$stmtW = $conn->prepare("SELECT * FROM sdc_webinars WHERE id = ? LIMIT 1");
$stmtW->bind_param("i", $webinar_id);
$stmtW->execute();
$webinar = $stmtW->get_result()->fetch_assoc();

if (!$webinar) {
    header("Location: /admin/webinar/index.php?error=not_found");
    exit;
}

// ── Helpers ─────────────────────────────────────────────────────────────────

$renderEmail = function($subject, $bodyText, $vars) {
    $isHtml = preg_match('/<[^>]+>/', $bodyText) === 1;
    
    // We'll reuse the logic from dash/webinar.php but modernized
    if ($isHtml) {
        $fillHtml = function($tpl, $vars) {
            foreach ($vars as $k => $v) {
                $tpl = str_replace('{' . $k . '}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $tpl);
            }
            return $tpl;
        };
        $posterTag = '';
        if (!empty($vars['poster_url']) && filter_var($vars['poster_url'], FILTER_VALIDATE_URL)) {
            $posterTag = '<img src="'.htmlspecialchars($vars['poster_url'], ENT_QUOTES, 'UTF-8').'" style="width:100%;height:auto;border-radius:12px;display:block;">';
        }
        $bodyHtmlTpl = str_replace('{poster_image}', $posterTag, (string)$bodyText);
        $bodyHtml = $fillHtml($bodyHtmlTpl, $vars);
    } else {
        $fillText = function($tpl, $vars) {
            foreach ($vars as $k => $v) {
                $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
            }
            return $tpl;
        };
        $plain = $fillText($bodyText, $vars);
        
        // Simple plain to HTML conversion with basic webinar styling
        $msg = nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'));
        $zoom = (string)($vars['zoom_join_url'] ?? '');
        $zoomBtn = filter_var($zoom, FILTER_VALIDATE_URL) ? '<div style="margin-top:18px;text-align:center;"><a href="'.htmlspecialchars($zoom, ENT_QUOTES, 'UTF-8').'" style="display:inline-block;padding:12px 18px;background:#FFD700;color:#111;text-decoration:none;border-radius:12px;font-weight:700;">Join Webinar</a></div>' : '';
        
        $bodyHtml = '
            <div style="font-size:14px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#f59e0b;margin:0 0 12px 0;">SDC Webinar</div>
            <div style="font-size:24px;font-weight:900;letter-spacing:-0.3px;color:#ffffff;margin:0 0 14px 0;">'.htmlspecialchars($vars['webinar_title'], ENT_QUOTES, 'UTF-8').'</div>
            <div style="font-size:16px;line-height:1.72;color:#a1a1aa;margin-top:16px;">'.$msg.'</div>
            <div style="margin-top:16px;padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(255,255,255,.04);color:#e4e4e7;">
                <div style="font-weight:700;margin-bottom:8px;color:#ffffff;">Webinar Info</div>
                <div><b>Date:</b> '.$vars['webinar_date'].'</div>
                <div><b>Time:</b> '.$vars['webinar_time'].'</div>
            </div>
            '.$zoomBtn.'
            <div style="margin-top:18px;font-size:12px;color:#71717a;text-align:center;">You’re receiving this email because you registered for this webinar.</div>
        ';
    }

    return buildMailLayout([
        'subject'     => $subject,
        'preheader'   => 'Webinar update from Demo Company',
        'body_html'   => $bodyHtml,
        'recipient_email' => $vars['email'],
        'brand_name'  => 'Demo Company',
        'brand_email' => defined('SES_SENDER_EMAIL') ? (string)SES_SENDER_EMAIL : 'support@demo.local',
        'year'        => date('Y'),
        'badge_text'  => 'Webinar Update',
    ]);
};

$getVars = function($p, $w) {
    return [
        'name'  => $p['name'] ?? 'Guest',
        'email' => $p['email'] ?? '',
        'phone' => $p['phone'] ?? '',
        'webinar_title'  => $w['webinar_title'] ?? '',
        'zoom_join_url'  => $w['zoom_join_url'] ?? '',
        'webinar_date'   => date('d M Y', strtotime($w['start_datetime'])),
        'webinar_time'   => date('H:i', strtotime($w['start_datetime'])),
        'timezone'       => $w['timezone'] ?? 'Asia/Kuala_Lumpur',
        'poster_url'     => $w['poster_url'] ?? '',
    ];
};

$fillText = function($tpl, $vars) {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
};

// ── Sending Logic ───────────────────────────────────────────────────────────

set_time_limit(0);

if ($send_mode === 'test') {
    $testEmail = trim((string)($_POST['test_recipient'] ?? ''));
    if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&error=invalid_test_email&error_msg=" . urlencode("Please provide a valid test recipient email."));
        exit;
    }

    $vars = $getVars(['name' => 'Test User', 'email' => $testEmail, 'phone' => '0123456789'], $webinar);
    $subject = $fillText($subjectTpl, $vars);
    $html = $renderEmail($subject, $bodyTpl, $vars);

    if (sendSES($testEmail, 'Test User', $subject, $html)) {
        header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&status=test_sent");
    } else {
        header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&error=send_failed&error_msg=" . urlencode("Failed to send test email. Check error logs."));
    }
    exit;

} elseif ($send_mode === 'bulk') {
    if (empty($_POST['confirm_bulk'])) {
        header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&error=not_confirmed&error_msg=" . urlencode("Please confirm before sending bulk email."));
        exit;
    }

    $stmtP = $conn->prepare("SELECT name, email, phone, consent FROM sdc_webinar_registrations WHERE webinar_id = ? AND consent = 1");
    $stmtP->bind_param("i", $webinar_id);
    $stmtP->execute();
    $res = $stmtP->get_result();

    $attempted = 0;
    $sent = 0;
    $skipped = 0;
    $failed = 0;

    while ($p = $res->fetch_assoc()) {
        $attempted++;
        $email = trim((string)($p['email'] ?? ''));
        
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }

        $vars = $getVars($p, $webinar);
        $subject = $fillText($subjectTpl, $vars);
        $html = $renderEmail($subject, $bodyTpl, $vars);

        if (sendSES($email, ($p['name'] ?? ''), $subject, $html)) {
            $sent++;
        } else {
            $failed++;
        }
        
        // Minor throttle to respect SES limits if any
        usleep(100000); // 0.1s
    }

    header("Location: /admin/webinar/email.php?webinar_id=$webinar_id&status=success&attempted=$attempted&sent=$sent&skipped=$skipped&failed=$failed");
    exit;
}

header("Location: /admin/webinar/index.php");
exit;
