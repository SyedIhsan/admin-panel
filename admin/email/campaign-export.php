<?php
declare(strict_types=1);

/**
 * campaign-export.php
 * CSV download endpoint for campaign recipients by segment.
 */

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

// Get mysqli connection
$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// 1. Get and Validate Parameters
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$segment = trim((string)($_GET['segment'] ?? 'all'));

if ($campaignId <= 0) {
    http_response_code(400);
    exit('Missing or invalid campaign_id.');
}

try {
    // 2. Load Campaign Info
    $stmt = $conn->prepare("SELECT id, campaign_name, campaign_uid FROM `email_campaigns` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $campaign = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$campaign) {
        http_response_code(404);
        exit('Campaign not found.');
    }

    $campaignName = $campaign['campaign_name'];
    $campaignUid = $campaign['campaign_uid'];

    // 3. Build Segment Filter
    $where = "WHERE campaign_id = ?";
    $params = [$campaignId];
    $types = 'i';

    switch ($segment) {
        case 'opened':
            $where .= " AND opened = 1";
            break;
        case 'not_opened':
            $where .= " AND opened = 0";
            break;
        case 'clicked':
            $where .= " AND clicked = 1";
            break;
        case 'never_clicked':
            $where .= " AND clicked = 0";
            break;
        case 'failed':
            $where .= " AND delivery_status = 'failed'";
            break;
        case 'delivered':
            $where .= " AND delivery_status = 'delivered'";
            break;
        case 'bounced':
            $where .= " AND delivery_status = 'bounced'";
            break;
        case 'unsubscribed':
            $where .= " AND delivery_status = 'unsubscribed'";
            break;
        default:
            $segment = 'all'; // Default
            break;
    }

    // 4. Set Headers for CSV
    $filename = "campaign-{$campaignId}-{$segment}-" . date('Ymd-His') . ".csv";

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // BOM for Excel UTF-8 support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV Headers
    fputcsv($output, [
        'campaign_id',
        'campaign_name',
        'campaign_uid',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'delivery_status',
        'sent_at',
        'opened',
        'first_open_at',
        'last_open_at',
        'open_count',
        'clicked',
        'first_click_at',
        'last_click_at',
        'click_count',
        'failed_reason',
        'provider_message_id',
        'created_at'
    ]);

    // 5. Stream Rows
    $sql = "SELECT * FROM `email_campaign_recipients` {$where} ORDER BY id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        fputcsv($output, [
            $campaignId,
            $campaignName,
            $campaignUid,
            $row['recipient_name'],
            $row['recipient_email'],
            $row['recipient_phone'],
            $row['delivery_status'],
            $row['created_at'], // Using created_at as sent_at for now if real sent_at not separated
            $row['opened'],
            $row['first_open_at'],
            $row['last_open_at'],
            $row['open_count'],
            $row['clicked'],
            $row['first_click_at'],
            $row['last_click_at'],
            $row['click_count'],
            $row['failed_reason'],
            $row['provider_message_id'],
            $row['created_at']
        ]);
    }

    $stmt->close();
    fclose($output);
    exit;

} catch (Exception $e) {
    error_log("Campaign Export Error: " . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        exit('An error occurred during export.');
    }
}
