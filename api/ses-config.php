<?php
/**
 * ses-config.php — DEMO STUB
 * Replaces AWS SES + PHPMailer. No emails leave the server.
 * All sends are logged to demo/mail-outbox/ as preview HTML files.
 */

declare(strict_types=1);

// Keep these defined so any file that checks them doesn't crash
if (!defined('SES_SENDER_EMAIL')) define('SES_SENDER_EMAIL', 'demo@example.test');
if (!defined('SES_SENDER_NAME'))  define('SES_SENDER_NAME',  'Demo Admin');

function sendBrevo($toEmail, $toName, $subject, $html, $attachments = []): bool {
    _demo_ses_log((string)$toEmail, (string)$subject, (string)$html);
    return true;
}

function sendSES($toEmail, $toName, $subject, $html, $attachments = []): bool {
    _demo_ses_log((string)$toEmail, (string)$subject, (string)$html);
    return true;
}

function _demo_ses_log(string $to, string $subject, string $html): void {
    $dir = dirname(__DIR__) . '/demo/mail-outbox';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '_', $to) . '.html';
    $preview = "<!doctype html><html><head><meta charset='utf-8'><title>[DEMO] {$subject}</title></head><body>"
        . "<div style='background:#fff3cd;padding:12px;border-bottom:2px solid #ffc107;font-family:sans-serif'>"
        . "<b>DEMO PREVIEW</b> &mdash; To: " . htmlspecialchars($to) . " &middot; Subject: " . htmlspecialchars($subject)
        . "</div>{$html}</body></html>";
    @file_put_contents($file, $preview);
}
