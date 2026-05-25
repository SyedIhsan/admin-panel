<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/layout.php';

function cpv_get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? (string)$_GET[$key] : $default;
}

// Sample recipient shown in preview
$sampleName  = 'Jane Doe';
$sampleEmail = 'jane@example.com';
$samplePhone = '0123456789';

// Build campaign data from GET params — mirrors the columns stored in email_campaigns
$campaign = [
    'campaign_name' => cpv_get('campaign_name', 'Campaign Preview'),
    'subject'       => cpv_get('subject',        'Update from Demo Company'),
    'preheader'     => cpv_get('preheader',       'A quick update from Demo Company.'),
    'email_body'    => cpv_get('email_body',      "Hi {{name}},\n\nThis is a campaign email from Demo Company."),
    'button_text'   => cpv_get('button_text',     'Visit Demo'),
    'button_url'    => cpv_get('button_url',      'https://demo.local'),
    'closing_text'  => cpv_get('closing_text',    "Best regards,\nDemo Team"),
    'brand_name'    => cpv_get('brand_name', 'Demo'),
    'support_email' => cpv_get('support_email',   'support@demo.local'),
    'footer_note'   => cpv_get('footer_note',     'You are receiving this email because you joined our mailing list.'),
];

// Empty tracking_token → campaign_apply_tracking_to_html is a no-op (no tracking in preview)
$recipient = [
    'recipient_email' => $sampleEmail,
    'recipient_name'  => $sampleName,
    'recipient_phone' => $samplePhone,
    'tracking_token'  => '',
];

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// Uses the same shared helper as the real send flow — preview and sent email stay in sync
echo campaign_build_campaign_email_html($campaign, $recipient, true);
exit;
