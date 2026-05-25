<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$conn instanceof mysqli) { http_response_code(500); exit('Database connection unavailable.'); }
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
campaign_ensure_schema($conn);

$groupId = (int)($_GET['group_id'] ?? 0);
if ($groupId <= 0) {
    header('Location: /admin/email/audience-groups.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, group_name FROM `email_audience_groups` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    header('Location: /admin/email/audience-groups.php');
    exit;
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowed      = ['active', 'unsubscribed', 'bounced', 'invalid'];
$whereExtra   = '';
if ($statusFilter !== '' && in_array($statusFilter, $allowed, true)) {
    $safe       = $conn->real_escape_string($statusFilter);
    $whereExtra = " AND status = '{$safe}'";
}

$res = $conn->query(
    "SELECT m.name, m.email, m.phone, m.source_table, m.source_id, m.status, m.added_at,
            g.group_name
     FROM `email_audience_group_members` m
     JOIN `email_audience_groups` g ON g.id = m.group_id
     WHERE m.group_id = {$groupId}{$whereExtra}
     ORDER BY m.added_at ASC"
);

$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$group['group_name']);
$filename = 'audience_group_' . $safeName . '_' . date('Ymd_His') . '.csv';

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel compatibility
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['group_name', 'name', 'email', 'phone', 'source_table', 'source_id', 'status', 'added_at']);

while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['group_name']  ?? '',
        $row['name']        ?? '',
        $row['email']       ?? '',
        $row['phone']       ?? '',
        $row['source_table'] ?? '',
        $row['source_id']   ?? '',
        $row['status']      ?? '',
        $row['added_at']    ?? '',
    ]);
}

fclose($out);
exit;
