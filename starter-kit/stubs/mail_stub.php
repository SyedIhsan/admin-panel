<?php
declare(strict_types=1);

/**
 * DEMO STUB — Mail sender (replaces AWS SES + Brevo)
 *
 * In production: api/sendmail.php sent via AWS SES, api/mail/campaign-cron-send.php
 * sent batched campaigns via Brevo SDK.
 *
 * In demo: every send is logged to ./demo/mail-outbox/ as an HTML file you can
 * open in a browser to preview, and returns success. No emails leave the server.
 *
 * Drop in at: api/sendmail.php (replaces the real one)
 *             api/sdcmailer/brevo_stub.php (replaces the SDK include)
 */

function send_mail(string $to, string $subject, string $bodyHtml, array $opts = []): array {
    $logged = _demo_mail_log($to, $subject, $bodyHtml, 'transactional');
    return [
        'success' => true,
        'message_id' => 'demo_msg_' . bin2hex(random_bytes(6)),
        'preview_file' => $logged,
        'note' => '[DEMO] Email not actually sent. Preview saved to: ' . $logged,
    ];
}

function send_campaign_email(string $to, string $subject, string $bodyHtml, int $campaignId, array $opts = []): array {
    $logged = _demo_mail_log($to, $subject, $bodyHtml, 'campaign-' . $campaignId);
    return [
        'success' => true,
        'message_id' => 'demo_camp_' . bin2hex(random_bytes(6)),
        'preview_file' => $logged,
    ];
}

function send_batch_campaign(int $campaignId, array $recipients, string $subject, string $bodyHtml): array {
    $sent = 0;
    foreach ($recipients as $r) {
        _demo_mail_log($r['email'] ?? 'unknown@example.test', $subject, $bodyHtml, 'batch-' . $campaignId);
        $sent++;
    }
    return [
        'success' => true,
        'sent_count' => $sent,
        'failed_count' => 0,
        'note' => "[DEMO] {$sent} emails logged to ./demo/mail-outbox/, none actually sent.",
    ];
}

// -----------------------------------------------------------------------------
// Internal: write a preview HTML file
// -----------------------------------------------------------------------------

function _demo_mail_log(string $to, string $subject, string $bodyHtml, string $kind): string {
    $outboxDir = __DIR__ . '/../demo/mail-outbox';
    if (!is_dir($outboxDir)) {
        @mkdir($outboxDir, 0755, true);
    }
    $filename = sprintf(
        '%s_%s_%s.html',
        date('Ymd_His'),
        $kind,
        preg_replace('/[^a-z0-9]/i', '_', $to)
    );
    $filepath = $outboxDir . '/' . $filename;
    $wrapper = "<!doctype html><html><head><meta charset='utf-8'>
        <title>[DEMO] {$subject}</title></head><body>
        <div style='background:#fff3cd;padding:12px;border-bottom:2px solid #ffc107;font-family:sans-serif'>
            <b>🎭 DEMO PREVIEW</b> — To: {$to} · Subject: {$subject} · Kind: {$kind}
        </div>
        {$bodyHtml}
        </body></html>";
    @file_put_contents($filepath, $wrapper);
    return $filepath;
}
