<?php
declare(strict_types=1);

/**
 * DEMO STUB — Google APIs (Drive, Sheets, OAuth2)
 *
 * Replaces the real google_oauth.php, drive_api.php, and sheets_api.php for the
 * portfolio demo. Returns realistic-looking fake data so the UI works without
 * any real Google credentials, OAuth flow, or network calls.
 *
 * In the original codebase these wrappers lived at:
 *   admin/elearning/lib/google_oauth.php
 *   admin/elearning/lib/drive_api.php
 *   admin/elearning/lib/sheets_api.php
 *
 * Drop this single file in their place and update the require paths in any
 * file that imported them (typically just: require_once __DIR__ . '/lib/google_api_stub.php';)
 */

// -----------------------------------------------------------------------------
// OAuth stubs
// -----------------------------------------------------------------------------

function google_oauth_get_auth_url(string $redirectUri = ''): string {
    return '#demo-google-oauth-disabled';
}

function google_oauth_handle_callback(string $code): array {
    return [
        'success' => true,
        'access_token' => 'demo_fake_token_' . bin2hex(random_bytes(8)),
        'refresh_token' => 'demo_fake_refresh_' . bin2hex(random_bytes(8)),
        'expires_in' => 3600,
        'email' => 'demo-admin@example.test',
    ];
}

function google_oauth_is_connected(): bool {
    return true; // pretend we're always connected in the demo
}

function google_oauth_disconnect(): bool {
    return true;
}

function google_oauth_get_account_info(): array {
    return [
        'email' => 'demo-admin@example.test',
        'name' => 'Demo Admin',
        'connected_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
    ];
}

// -----------------------------------------------------------------------------
// Drive API stubs
// -----------------------------------------------------------------------------

function drive_list_files(string $folderId = '', int $limit = 20): array {
    $files = [];
    $titles = [
        'Module 1 - Introduction.mp4',
        'Module 2 - Core Concepts.mp4',
        'Module 3 - Hands-on Practice.mp4',
        'Workbook - Week 1.pdf',
        'Workbook - Week 2.pdf',
        'Bonus - Q&A Session.mp4',
        'Resources Pack.zip',
        'Certificate Template.pdf',
    ];
    for ($i = 0; $i < min($limit, count($titles)); $i++) {
        $files[] = [
            'id' => 'demo_drive_' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
            'name' => $titles[$i],
            'mimeType' => str_ends_with($titles[$i], '.mp4') ? 'video/mp4'
                        : (str_ends_with($titles[$i], '.pdf') ? 'application/pdf' : 'application/zip'),
            'size' => rand(500_000, 500_000_000),
            'createdTime' => date('c', strtotime('-' . rand(1, 90) . ' days')),
            'webViewLink' => '#demo-drive-link',
        ];
    }
    return $files;
}

function drive_get_file(string $fileId): array {
    return [
        'id' => $fileId,
        'name' => 'Demo File.mp4',
        'mimeType' => 'video/mp4',
        'size' => 125_000_000,
        'webViewLink' => '#demo-drive-link',
        'thumbnailLink' => '#',
    ];
}

function drive_upload_file(string $localPath, string $folderId = '', ?string $name = null): array {
    return [
        'success' => true,
        'id' => 'demo_drive_' . bin2hex(random_bytes(6)),
        'name' => $name ?? basename($localPath),
        'webViewLink' => '#demo-drive-link',
    ];
}

function drive_delete_file(string $fileId): bool {
    return true;
}

function drive_get_streaming_url(string $fileId): string {
    // In production this returns a signed URL. For the demo, return a public sample.
    return 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';
}

// -----------------------------------------------------------------------------
// Sheets API stubs
// -----------------------------------------------------------------------------

function sheets_get_values(string $spreadsheetId, string $range): array {
    // Return a small realistic-looking student progress sheet
    return [
        ['Student Name', 'Email', 'Module 1', 'Module 2', 'Module 3', 'Completion %'],
        ['Alice Demo', 'alice@example.test', '✓', '✓', '✓', '100%'],
        ['Bob Demo',   'bob@example.test',   '✓', '✓', '',  '67%'],
        ['Carol Demo', 'carol@example.test', '✓', '',  '',  '33%'],
        ['Dave Demo',  'dave@example.test',  '✓', '✓', '✓', '100%'],
    ];
}

function sheets_append_row(string $spreadsheetId, string $range, array $row): bool {
    return true;
}

function sheets_update_cell(string $spreadsheetId, string $cell, string $value): bool {
    return true;
}

function sheets_create_spreadsheet(string $title): array {
    return [
        'success' => true,
        'spreadsheetId' => 'demo_sheet_' . bin2hex(random_bytes(8)),
        'title' => $title,
        'url' => '#demo-sheets-link',
    ];
}
