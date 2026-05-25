<?php

/**
 * campaign-helpers.php
 * 
 * Idempotent campaign schema creation and helper functions.
 * Safe to require multiple times.
 */

if (!defined('CAMPAIGN_HELPERS_VERSION')) {
    define('CAMPAIGN_HELPERS_VERSION', '1.3.0');
}

// ============================================================================
// DATABASE SCHEMA HELPERS
// ============================================================================

if (!function_exists('campaign_table_exists')) {
    function campaign_table_exists(mysqli $conn, string $tableName): bool
    {
        $res = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('campaign_column_exists')) {
    function campaign_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $res = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('campaign_index_exists')) {
    function campaign_index_exists(mysqli $conn, string $tableName, string $indexName): bool
    {
        $res = $conn->query("SHOW INDEX FROM `{$tableName}` WHERE Key_name = '{$indexName}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('campaign_repair_campaign_uids')) {
    function campaign_repair_campaign_uids(mysqli $conn): bool
    {
        try {
            // Find rows with NULL or blank campaign_uid
            $stmt = $conn->prepare("
                SELECT id FROM `email_campaigns` 
                WHERE campaign_uid IS NULL OR TRIM(campaign_uid) = ''
                ORDER BY id ASC
            ");
            if (!$stmt) {
                error_log("campaign_repair_campaign_uids: Failed to prepare select: " . $conn->error);
                return false;
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $rowsToRepair = [];
            while ($row = $res->fetch_assoc()) {
                $rowsToRepair[] = (int)$row['id'];
            }
            $stmt->close();

            // Backfill each blank/NULL with unique UID
            foreach ($rowsToRepair as $campaignId) {
                $newUid = campaign_generate_unique_uid($conn, 'camp');
                $updateStmt = $conn->prepare("UPDATE `email_campaigns` SET campaign_uid = ? WHERE id = ?");
                if (!$updateStmt) return false;
                $updateStmt->bind_param('si', $newUid, $campaignId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            return true;
        } catch (Exception $e) {
            error_log("campaign_repair_campaign_uids error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('campaign_repair_campaign_names')) {
    function campaign_repair_campaign_names(mysqli $conn): bool
    {
        try {
            $stmt = $conn->prepare("SELECT id FROM `email_campaigns` WHERE campaign_name IS NULL OR TRIM(campaign_name) = ''");
            if (!$stmt) return false;
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) $rows[] = (int)$row['id'];
            $stmt->close();

            foreach ($rows as $id) {
                $name = 'Campaign #' . $id;
                $up = $conn->prepare("UPDATE `email_campaigns` SET campaign_name = ? WHERE id = ?");
                $up->bind_param('si', $name, $id);
                $up->execute();
                $up->close();
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('campaign_get_blank_count')) {
    function campaign_get_blank_count(mysqli $conn, string $table, string $column): int
    {
        $safeTable = $conn->real_escape_string($table);
        $safeCol = $conn->real_escape_string($column);
        $res = $conn->query("SELECT COUNT(*) as cnt FROM `{$safeTable}` WHERE {$safeCol} IS NULL OR TRIM({$safeCol}) = ''");
        return ($res && $row = $res->fetch_assoc()) ? (int)$row['cnt'] : 0;
    }
}

if (!function_exists('campaign_get_duplicate_count')) {
    function campaign_get_duplicate_count(mysqli $conn, string $table, string $column): int
    {
        $safeTable = $conn->real_escape_string($table);
        $safeCol = $conn->real_escape_string($column);
        $res = $conn->query("SELECT COUNT(*) as cnt FROM (SELECT {$safeCol}, COUNT(*) as d FROM `{$safeTable}` WHERE {$safeCol} IS NOT NULL AND TRIM({$safeCol}) != '' GROUP BY {$safeCol} HAVING d > 1) as x");
        return ($res && $row = $res->fetch_assoc()) ? (int)$row['cnt'] : 0;
    }
}

if (!function_exists('campaign_ensure_schema')) {
    function campaign_ensure_schema(mysqli $conn): bool
    {
        try {
            $conn->set_charset('utf8mb4');
            $conn->query("SET time_zone = '+08:00'");

            // EMAIL_CAMPAIGNS
            if (!campaign_table_exists($conn, 'email_campaigns')) {
                $sql = "CREATE TABLE `email_campaigns` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    campaign_uid VARCHAR(64) NOT NULL UNIQUE,
                    campaign_name VARCHAR(255) NOT NULL,
                    subject VARCHAR(255) NULL,
                    template_id BIGINT UNSIGNED NULL,
                    campaign_type ENUM('manual_blast','automatic_trigger','targeted_campaign') NOT NULL DEFAULT 'manual_blast',
                    target_filter LONGTEXT NULL,
                    status ENUM('draft','scheduled','queued','sending','sent','partial_failed','failed','cancelled') NOT NULL DEFAULT 'draft',
                    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
                    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
                    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                    delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
                    bounced_count INT UNSIGNED NOT NULL DEFAULT 0,
                    complaint_count INT UNSIGNED NOT NULL DEFAULT 0,
                    opened_count INT UNSIGNED NOT NULL DEFAULT 0,
                    clicked_count INT UNSIGNED NOT NULL DEFAULT 0,
                    total_open_count INT UNSIGNED NOT NULL DEFAULT 0,
                    total_click_count INT UNSIGNED NOT NULL DEFAULT 0,
                    open_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    click_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    created_by VARCHAR(190) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL,
                    scheduled_for DATETIME NULL,
                    KEY idx_campaign_status(status),
                    KEY idx_campaign_name(campaign_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            // New Content Columns
            $contentCols = [
                'preheader' => "VARCHAR(255) NULL",
                'email_body' => "MEDIUMTEXT NULL",
                'button_text' => "VARCHAR(120) NULL",
                'button_url' => "TEXT NULL",
                'closing_text' => "TEXT NULL",
                'brand_name' => "VARCHAR(190) NULL",
                'support_email' => "VARCHAR(190) NULL",
                'footer_note' => "TEXT NULL",
                'content_updated_at' => "DATETIME NULL",
                'content_updated_by' => "VARCHAR(190) NULL"
            ];
            foreach ($contentCols as $col => $def) {
                if (!campaign_column_exists($conn, 'email_campaigns', $col)) {
                    $conn->query("ALTER TABLE `email_campaigns` ADD COLUMN `{$col}` {$def}");
                }
            }

            // RECIPIENTS
            if (!campaign_table_exists($conn, 'email_campaign_recipients')) {
                $sql = "CREATE TABLE `email_campaign_recipients` (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    campaign_id BIGINT UNSIGNED NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    recipient_name VARCHAR(255) NULL,
                    recipient_phone VARCHAR(80) NULL,
                    recipient_id VARCHAR(100) NULL,
                    email_log_id BIGINT UNSIGNED NULL,
                    tracking_token VARCHAR(128) NOT NULL UNIQUE,
                    delivery_status ENUM('pending','queued','sent','failed','delivered','bounced','spam','complained','unsubscribed') NOT NULL DEFAULT 'pending',
                    failed_reason TEXT NULL,
                    opened TINYINT(1) NOT NULL DEFAULT 0,
                    first_open_at DATETIME NULL,
                    last_open_at DATETIME NULL,
                    open_count INT UNSIGNED NOT NULL DEFAULT 0,
                    clicked TINYINT(1) NOT NULL DEFAULT 0,
                    first_click_at DATETIME NULL,
                    last_click_at DATETIME NULL,
                    click_count INT UNSIGNED NOT NULL DEFAULT 0,
                    provider_message_id VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_campaign_recipient_email(campaign_id, recipient_email),
                    KEY idx_tracking_token(tracking_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            // LINK CLICKS
            if (!campaign_table_exists($conn, 'email_campaign_link_clicks')) {
                $sql = "CREATE TABLE `email_campaign_link_clicks` (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    campaign_id BIGINT UNSIGNED NOT NULL,
                    recipient_id BIGINT UNSIGNED NOT NULL,
                    link_url TEXT NOT NULL,
                    link_hash VARCHAR(64) NOT NULL,
                    link_position INT UNSIGNED NULL,
                    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    user_agent TEXT NULL,
                    ip_hash VARCHAR(128) NULL,
                    meta_json LONGTEXT NULL,
                    KEY idx_click_recipient(recipient_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            // IMPORTS
            if (!campaign_table_exists($conn, 'email_campaign_imports')) {
                $sql = "CREATE TABLE `email_campaign_imports` (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    campaign_id BIGINT UNSIGNED NULL,
                    imported_by VARCHAR(190) NULL,
                    import_file_hash VARCHAR(64) NULL,
                    original_filename VARCHAR(255) NULL,
                    source_name VARCHAR(190) NULL,
                    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    error_message TEXT NULL,
                    status ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            campaign_ensure_send_batch_schema($conn);
            campaign_ensure_queue_schema($conn);
            campaign_ensure_audience_schema($conn);
            campaign_ensure_schedule_schema($conn);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ============================================================================
// UID / TOKEN GENERATION
// ============================================================================

if (!function_exists('campaign_generate_uid')) {
    function campaign_generate_uid(string $prefix = 'camp'): string
    {
        return strtolower($prefix . '_' . substr((string)time(), -6) . '_' . bin2hex(random_bytes(4)));
    }
}

if (!function_exists('campaign_generate_unique_uid')) {
    function campaign_generate_unique_uid(mysqli $conn, string $prefix = 'camp'): string
    {
        for ($i=0; $i<10; $i++) {
            $uid = campaign_generate_uid($prefix);
            $res = $conn->query("SELECT 1 FROM `email_campaigns` WHERE campaign_uid = '{$conn->real_escape_string($uid)}' LIMIT 1");
            if ($res && $res->num_rows === 0) return $uid;
        }
        return campaign_generate_uid($prefix) . '_' . uniqid();
    }
}

if (!function_exists('campaign_generate_tracking_token')) {
    function campaign_generate_tracking_token(): string
    {
        return bin2hex(random_bytes(32)) . bin2hex(random_bytes(32));
    }
}

// ============================================================================
// HASHING / ENCODING
// ============================================================================

if (!function_exists('campaign_hash_ip')) {
    function campaign_hash_ip(?string $ip = null): string
    {
        $ip = trim($ip ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return hash('sha256', $ip . (defined('CAMPAIGN_IP_SALT') ? CAMPAIGN_IP_SALT : ''));
    }
}

if (!function_exists('campaign_base64url_encode')) {
    function campaign_base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('campaign_base64url_decode')) {
    function campaign_base64url_decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - (strlen($value) % 4)) % 4);
        return (string)base64_decode($value, true);
    }
}

// ============================================================================
// ADMIN / SESSION HELPERS
// ============================================================================

if (!function_exists('campaign_safe_admin_label')) {
    function campaign_safe_admin_label(): string
    {
        return trim((string)($_SESSION['admin_email'] ?? $_SESSION['admin_username'] ?? 'admin')) ?: 'admin';
    }
}

// ============================================================================
// METRICS RECALCULATION
// ============================================================================

if (!function_exists('campaign_recalculate_metrics')) {
    function campaign_recalculate_metrics(mysqli $conn, int $campaignId): bool
    {
        try {
            $conn->query("UPDATE `email_campaigns` SET 
                total_recipients = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId}),
                sent_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND delivery_status = 'sent'),
                failed_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND delivery_status = 'failed'),
                delivered_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND delivery_status = 'delivered'),
                bounced_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND delivery_status = 'bounced'),
                opened_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND opened = 1),
                clicked_count = (SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND clicked = 1),
                total_open_count = (SELECT IFNULL(SUM(open_count),0) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId}),
                total_click_count = (SELECT IFNULL(SUM(click_count),0) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId}),
                open_rate = IF(sent_count > 0, (opened_count / sent_count * 100), 0.00),
                click_rate = IF(sent_count > 0, (clicked_count / sent_count * 100), 0.00),
                updated_at = NOW()
                WHERE id = {$campaignId}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ============================================================================
// HTML INJECTION HELPERS
// ============================================================================

if (!function_exists('campaign_base_url')) {
    function campaign_base_url(bool $public = false): string
    {
        if ($public) return 'https://demo.local';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        return rtrim($proto . '://' . $host, '/');
    }
}

if (!function_exists('campaign_build_tracking_open_url')) {
    function campaign_build_tracking_open_url(string $token, bool $public = false): string
    {
        return campaign_base_url($public) . '/api/mail/campaign-track-open.php?t=' . urlencode($token);
    }
}

if (!function_exists('campaign_build_tracking_click_url')) {
    function campaign_build_tracking_click_url(string $token, string $url, bool $public = false): string
    {
        return campaign_base_url($public) . '/api/mail/campaign-track-click.php?t=' . urlencode($token) . '&u=' . campaign_base64url_encode($url);
    }
}

if (!function_exists('campaign_is_trackable_href')) {
    function campaign_is_trackable_href(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || !preg_match('~^https?://~i', $href)) return false;
        if (stripos($href, 'campaign-track-') !== false) return false;
        if (stripos($href, 'unsubscribe.php') !== false || stripos($href, 'manage-preferences.php') !== false) return false;
        return true;
    }
}

if (!function_exists('campaign_rewrite_links_to_tracking')) {
    function campaign_rewrite_links_to_tracking(string $html, string $token, bool $public = false): string
    {
        if (trim($token) === '') return $html;
        return preg_replace_callback('/<a\b([^>]*?)href=(["\'])(.*?)\2([^>]*?)>/is', function ($m) use ($token, $public) {
            $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (campaign_is_trackable_href($url)) {
                return '<a' . $m[1] . 'href=' . $m[2] . htmlspecialchars(campaign_build_tracking_click_url($token, $url, $public), ENT_QUOTES, 'UTF-8') . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }
}

if (!function_exists('campaign_append_tracking_pixel')) {
    function campaign_append_tracking_pixel(string $html, string $token, bool $public = false): string
    {
        if (trim($token) === '' || strpos($html, 'campaign_tracking_pixel') !== false) return $html;
        $px = "\n<!-- campaign_tracking_pixel -->\n<img src=\"" . htmlspecialchars(campaign_build_tracking_open_url($token, $public), ENT_QUOTES, 'UTF-8') . "\" width=\"1\" height=\"1\" alt=\"\" style=\"width:1px;height:1px;max-width:1px;max-height:1px;border:0;outline:none;text-decoration:none;opacity:0;\" />\n";
        return (stripos($html, '</body>') !== false) ? preg_replace('/(<\/body>)/i', $px . '$1', $html) : $html . $px;
    }
}

if (!function_exists('campaign_apply_tracking_to_html')) {
    function campaign_apply_tracking_to_html(string $html, string $token, bool $public = false): string
    {
        if (trim($token) === '') return $html;
        if (strpos($html, 'campaign-track-click.php') !== false && strpos($html, 'campaign_tracking_pixel') !== false && (!$public || strpos($html, 'localhost') === false)) return $html;
        return campaign_append_tracking_pixel(campaign_rewrite_links_to_tracking($html, $token, $public), $token, $public);
    }
}

// ============================================================================
// CONTENT & TOKEN HELPERS
// ============================================================================

if (!function_exists('campaign_replace_tokens')) {
    function campaign_replace_tokens(string $text, array $recipient, array $campaign): string
    {
        $email = (string)($recipient['recipient_email'] ?? '');
        $phone = (string)($recipient['recipient_phone'] ?? $recipient['phone'] ?? '');
        $tokens = [
            '{{name}}'                   => (string)(($recipient['recipient_name'] ?? $recipient['name'] ?? '') ?: 'Customer'),
            '{{email}}'                  => $email,
            '{{phone}}'                  => $phone,
            '{{campaign_name}}'          => (string)$campaign['campaign_name'],
            '{{unsubscribe_url}}'        => campaign_base_url(true) . '/unsubscribe.php?email=' . urlencode($email),
            '{{manage_preferences_url}}' => campaign_base_url(true) . '/manage-preferences.php?email=' . urlencode($email)
        ];
        return str_replace(array_keys($tokens), array_values($tokens), $text);
    }
}

if (!function_exists('campaign_format_email_body')) {
    /**
     * Format plain-text or HTML body for email.
     * Strips unsafe tags, inlines basic styles, sanitises <a> hrefs.
     * Used by both preview and real-send path so output is identical.
     */
    function campaign_format_email_body(string $raw): string
    {
        if (preg_match('/<[a-z][\s\S]*>/i', $raw)) {
            $allowed = '<p><br><strong><em><u><ul><ol><li><a>';
            $html = strip_tags($raw, $allowed);
            $html = preg_replace('/<p>/i', '<p style="margin:0 0 14px 0;line-height:1.72;">', $html);
            $html = preg_replace('/<ul>/i', '<ul style="margin:0 0 14px 18px;padding:0;line-height:1.72;">', $html);
            $html = preg_replace('/<ol>/i', '<ol style="margin:0 0 14px 18px;padding:0;line-height:1.72;">', $html);
            $html = preg_replace('/<li>/i', '<li style="margin:0 0 4px 0;">', $html);
            $html = (string)preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', static function (array $m): string {
                $attrs = $m[1];
                $label = strip_tags($m[2]);
                $href  = '';
                if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $attrs, $hm)) {
                    $href = trim(html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if (!preg_match('~^(https?://|mailto:)~i', $href)) {
                    $href = '#';
                }
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                    . '" style="color:#f59e0b;" target="_blank" rel="noopener noreferrer">'
                    . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</a>';
            }, $html);
            return is_string($html) ? $html : '';
        }
        return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}

if (!function_exists('campaign_build_campaign_email_html')) {
    /**
     * Build the complete HTML email for a campaign recipient.
     * Used by BOTH the live preview endpoint and the real send flow.
     *
     * $public = true  → tracking/unsubscribe links use https://demo.local
     * $public = false → links use the current HTTP_HOST (local dev)
     */
    function campaign_build_campaign_email_html(array $campaign, array $recipient, bool $public = true): string
    {
        $recipientEmail = (string)($recipient['recipient_email'] ?? '');

        $subject  = campaign_replace_tokens(trim((string)($campaign['subject']      ?: 'Update from Demo Company')), $recipient, $campaign);
        $preheader= campaign_replace_tokens(trim((string)($campaign['preheader']    ?: 'A quick update from Demo Company.')), $recipient, $campaign);
        $body     = campaign_replace_tokens(trim((string)($campaign['email_body']   ?: "Hi {{name}},\n\nThis is a campaign email from Demo Company.")), $recipient, $campaign);
        $closing  = campaign_replace_tokens(trim((string)($campaign['closing_text'] ?: "Best regards,\nDemo Team")), $recipient, $campaign);
        $footer   = campaign_replace_tokens(trim((string)($campaign['footer_note']  ?: 'You are receiving this email because you joined our mailing list.')), $recipient, $campaign);
        $btnText  = trim((string)($campaign['button_text'] ?: 'Visit Demo'));
        $btnUrl   = campaign_replace_tokens(trim((string)($campaign['button_url']   ?: 'https://demo.local')), $recipient, $campaign);

        if (!function_exists('buildMailLayout')) {
            require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/layout.php';
        }

        // ── Build body HTML ────────────────────────────────────────────────────
        $bodyHtml = '<div style="margin:0 0 20px 0;color:#ffffff;">'
            . campaign_format_email_body($body)
            . '</div>';

        // Button — only rendered when both text and a valid http/https URL are present
        if ($btnText !== '' && preg_match('~^https?://~i', $btnUrl)) {
            $bodyHtml .= '<div style="margin:0 0 28px 0;">'
                . '<a href="' . htmlspecialchars($btnUrl, ENT_QUOTES, 'UTF-8') . '"'
                . ' style="display:inline-block;padding:14px 28px;border-radius:14px;'
                . 'background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#111827;'
                . 'text-decoration:none;font-size:15px;line-height:1.1;font-weight:800;'
                . 'box-shadow:0 14px 28px rgba(245,158,11,.28);">'
                . htmlspecialchars($btnText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</a></div>';
        }

        $bodyHtml .= '<div style="margin:0 0 18px 0;color:#a1a1aa;line-height:1.6;">'
            . campaign_format_email_body($closing)
            . '</div>';

        // Footer note HTML — uses the correct key expected by buildMailLayout()
        $footerNoteHtml = '<div style="font-size:12px;line-height:1.6;color:#52525b;">'
            . htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';

        $html = buildMailLayout([
            'subject'                => $subject,
            'preheader'              => $preheader,
            'body_html'              => $bodyHtml,
            'brand_name' => 'Demo',
            'brand_email'            => (string)($campaign['support_email'] ?: 'support@demo.local'),
            'support_email'          => (string)($campaign['support_email'] ?: 'support@demo.local'),
            'footer_note_html'       => $footerNoteHtml,
            'recipient_email'        => $recipientEmail,
            'unsubscribe_url'        => campaign_base_url($public) . '/unsubscribe.php?email='          . urlencode($recipientEmail),
            'manage_preferences_url' => campaign_base_url($public) . '/manage-preferences.php?email='  . urlencode($recipientEmail),
            'tracking_token'         => '', // tracking applied explicitly below
        ]);

        // Apply click-tracking and open pixel (no-op when token is empty, e.g. preview)
        return campaign_apply_tracking_to_html($html, (string)($recipient['tracking_token'] ?? ''), $public);
    }
}

if (!function_exists('campaign_campaign_content_ready')) {
    function campaign_campaign_content_ready(array $campaign): bool
    {
        return !empty(trim((string)($campaign['subject'] ?? ''))) && !empty(trim((string)($campaign['email_body'] ?? '')));
    }
}

// ============================================================================
// SENDING & STATUS HELPERS
// ============================================================================

if (!function_exists('campaign_count_remaining_recipients')) {
    function campaign_count_remaining_recipients(mysqli $conn, int $campaignId): int
    {
        $res = $conn->query("SELECT COUNT(*) FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND delivery_status IN ('pending', 'queued', 'failed')");
        return $res ? (int)$res->fetch_row()[0] : 0;
    }
}

if (!function_exists('campaign_get_or_create_tracking_token')) {
    function campaign_get_or_create_tracking_token(mysqli $conn, int $recipientId): string
    {
        $res = $conn->query("SELECT tracking_token FROM `email_campaign_recipients` WHERE id = {$recipientId}");
        $row = $res ? $res->fetch_assoc() : null;
        if (!empty($row['tracking_token'])) return $row['tracking_token'];
        $token = campaign_generate_tracking_token();
        $conn->query("UPDATE `email_campaign_recipients` SET tracking_token = '{$conn->real_escape_string($token)}' WHERE id = {$recipientId}");
        return $token;
    }
}

if (!function_exists('campaign_send_email_via_existing_sender')) {
    function campaign_send_email_via_existing_sender(string $to, string $name, string $subject, string $html): array
    {
        if (function_exists('sendSES')) $ok = sendSES($to, $name, $subject, $html);
        elseif (function_exists('sendBrevo')) $ok = sendBrevo($to, $name, $subject, $html);
        else return ['ok' => false, 'error' => 'No sender found'];
        return ['ok' => $ok, 'message_id' => null, 'error' => $ok ? null : 'Sender error'];
    }
}

if (!function_exists('campaign_mark_recipient_status')) {
    function campaign_mark_recipient_status(mysqli $conn, int $recipientId, string $status, ?string $msgId = null, ?string $err = null, ?int $logId = null): bool
    {
        $updates = ["delivery_status = '{$conn->real_escape_string($status)}'"];
        if ($status === 'sent') $updates[] = "failed_reason = NULL, provider_message_id = " . ($msgId ? "'{$conn->real_escape_string($msgId)}'" : "NULL");
        elseif ($status === 'failed') $updates[] = "failed_reason = " . ($err ? "'{$conn->real_escape_string($err)}'" : "NULL");
        if ($logId && campaign_column_exists($conn, 'email_campaign_recipients', 'email_log_id')) $updates[] = "email_log_id = {$logId}";
        return $conn->query("UPDATE `email_campaign_recipients` SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = {$recipientId}");
    }
}

if (!function_exists('campaign_recipient_exists')) {
    function campaign_recipient_exists(mysqli $conn, int $campaignId, string $email): bool
    {
        $res = $conn->query("SELECT 1 FROM `email_campaign_recipients` WHERE campaign_id = {$campaignId} AND LOWER(TRIM(recipient_email)) = LOWER(TRIM('{$conn->real_escape_string($email)}')) LIMIT 1");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('campaign_insert_recipient_with_retry')) {
    function campaign_insert_recipient_with_retry(mysqli $conn, int $campaignId, string $email, ?string $name, ?string $phone, string &$errorReason = ''): int
    {
        if (campaign_recipient_exists($conn, $campaignId, $email)) { $errorReason = "Duplicate email"; return 0; }
        for ($i=0; $i<3; $i++) {
            $token = campaign_generate_tracking_token();
            $sql = "INSERT INTO `email_campaign_recipients` (campaign_id, recipient_email, recipient_name, recipient_phone, tracking_token, delivery_status) VALUES (?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('issss', $campaignId, $email, $name, $phone, $token);
            if ($stmt->execute()) { $id = (int)$conn->insert_id; $stmt->close(); return $id; }
            if ($stmt->errno === 1062 && campaign_recipient_exists($conn, $campaignId, $email)) { $stmt->close(); return 0; }
            $stmt->close();
        }
        return -1;
    }
}

if (!function_exists('campaign_normalize_csv_header')) {
    function campaign_normalize_csv_header(string $header): string
    {
        $header = str_replace("\xEF\xBB\xBF", '', $header);
        return trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', strtolower(trim($header)))));
    }
}

// ============================================================================
// SEND BATCH SCHEMA & HELPERS (Phase 1H)
// ============================================================================

if (!function_exists('campaign_ensure_send_batch_schema')) {
    function campaign_ensure_send_batch_schema(mysqli $conn): bool
    {
        try {
            if (!campaign_table_exists($conn, 'email_campaign_send_batches')) {
                $sql = "CREATE TABLE `email_campaign_send_batches` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    batch_uid VARCHAR(64) NOT NULL UNIQUE,
                    campaign_id BIGINT UNSIGNED NOT NULL,
                    send_mode ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
                    initiated_by VARCHAR(190) NULL,
                    batch_size INT UNSIGNED NOT NULL DEFAULT 25,
                    attempted_count INT UNSIGNED NOT NULL DEFAULT 0,
                    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
                    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
                    remaining_count INT UNSIGNED NOT NULL DEFAULT 0,
                    status ENUM('started','completed','partial_failed','failed') NOT NULL DEFAULT 'started',
                    error_message TEXT NULL,
                    meta_json LONGTEXT NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME NULL,
                    KEY idx_batch_campaign_id(campaign_id),
                    KEY idx_batch_status(status),
                    KEY idx_batch_started_at(started_at),
                    KEY idx_batch_send_mode(send_mode)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) {
                    error_log('campaign_ensure_send_batch_schema CREATE failed: ' . $conn->error);
                    return false;
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('campaign_ensure_send_batch_schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('campaign_create_send_batch')) {
    function campaign_create_send_batch(
        mysqli $conn,
        int    $campaignId,
        string $sendMode,
        string $initiatedBy,
        int    $batchSize
    ): array {
        $batchUid = '';
        for ($i = 0; $i < 3; $i++) {
            $uid  = campaign_generate_uid('batch');
            $stmt = $conn->prepare(
                "INSERT INTO `email_campaign_send_batches`
                 (batch_uid, campaign_id, send_mode, initiated_by, batch_size, status, started_at)
                 VALUES (?, ?, ?, ?, ?, 'started', NOW())"
            );
            if (!$stmt) break;
            $stmt->bind_param('sissi', $uid, $campaignId, $sendMode, $initiatedBy, $batchSize);
            if ($stmt->execute()) {
                $id = (int)$conn->insert_id;
                $stmt->close();
                return ['id' => $id, 'uid' => $uid];
            }
            $stmt->close();
            $batchUid = $uid;
        }
        return ['id' => 0, 'uid' => $batchUid];
    }
}

if (!function_exists('campaign_update_send_batch')) {
    function campaign_update_send_batch(mysqli $conn, int $batchId, array $data): bool
    {
        if ($batchId <= 0) return false;
        $colTypes = [
            'attempted_count' => 'i',
            'sent_count'      => 'i',
            'failed_count'    => 'i',
            'skipped_count'   => 'i',
            'remaining_count' => 'i',
            'status'          => 's',
            'error_message'   => 's',
            'meta_json'       => 's',
            'completed_at'    => 's',
        ];
        $sets   = [];
        $types  = '';
        $values = [];
        foreach ($colTypes as $col => $type) {
            if (!array_key_exists($col, $data)) continue;
            $sets[]   = "`{$col}` = ?";
            $types   .= $type;
            $values[] = $data[$col];
        }
        if (empty($sets)) return false;
        if (isset($data['status'])
            && in_array($data['status'], ['completed', 'partial_failed', 'failed'], true)
            && !isset($data['completed_at'])) {
            $sets[] = '`completed_at` = NOW()';
        }
        $values[] = $batchId;
        $types   .= 'i';
        $stmt = $conn->prepare(
            'UPDATE `email_campaign_send_batches` SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$values);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('campaign_count_recipients_by_status')) {
    function campaign_count_recipients_by_status(mysqli $conn, int $campaignId): array
    {
        $result = [
            'pending' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0,
            'delivered' => 0, 'bounced' => 0, 'spam' => 0, 'complained' => 0,
            'unsubscribed' => 0, 'total' => 0,
        ];
        try {
            $stmt = $conn->prepare(
                "SELECT delivery_status, COUNT(*) AS cnt
                 FROM `email_campaign_recipients`
                 WHERE campaign_id = ?
                 GROUP BY delivery_status"
            );
            if (!$stmt) return $result;
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $s = (string)$row['delivery_status'];
                if (array_key_exists($s, $result)) {
                    $result[$s] = (int)$row['cnt'];
                }
                $result['total'] += (int)$row['cnt'];
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log('campaign_count_recipients_by_status error: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('campaign_count_remaining_for_mode')) {
    function campaign_count_remaining_for_mode(mysqli $conn, int $campaignId, string $sendMode): int
    {
        $where = match ($sendMode) {
            'failed'             => "delivery_status = 'failed'",
            'pending_and_failed' => "delivery_status IN ('pending','queued','failed')",
            default              => "delivery_status IN ('pending','queued')",
        };
        $res = $conn->query(
            "SELECT COUNT(*) FROM `email_campaign_recipients`
             WHERE campaign_id = {$campaignId} AND {$where}"
        );
        return $res ? (int)$res->fetch_row()[0] : 0;
    }
}

// ============================================================================
// QUEUE SCHEMA & HELPERS (Phase 1I)
// ============================================================================

if (!function_exists('campaign_ensure_queue_schema')) {
    function campaign_ensure_queue_schema(mysqli $conn): bool
    {
        try {
            // Add queue columns to email_campaigns if missing
            $queueCols = [
                'queue_status'        => "ENUM('none','queued','processing','paused','completed','cancelled','error') NOT NULL DEFAULT 'none'",
                'queue_started_at'    => 'DATETIME NULL',
                'queue_completed_at'  => 'DATETIME NULL',
                'queue_last_run_at'   => 'DATETIME NULL',
                'queue_error_message' => 'TEXT NULL',
            ];
            foreach ($queueCols as $col => $def) {
                if (!campaign_column_exists($conn, 'email_campaigns', $col)) {
                    if (!$conn->query("ALTER TABLE `email_campaigns` ADD COLUMN `{$col}` {$def}")) {
                        error_log("campaign_ensure_queue_schema: Failed to add {$col}: " . $conn->error);
                    }
                }
            }

            // Create email_campaign_queue_jobs if missing
            if (!campaign_table_exists($conn, 'email_campaign_queue_jobs')) {
                $sql = "CREATE TABLE `email_campaign_queue_jobs` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    job_uid VARCHAR(64) NOT NULL UNIQUE,
                    campaign_id BIGINT UNSIGNED NOT NULL,
                    send_mode ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
                    status ENUM('queued','processing','completed','paused','cancelled','error') NOT NULL DEFAULT 'queued',
                    batch_size INT UNSIGNED NOT NULL DEFAULT 25,
                    max_per_run INT UNSIGNED NOT NULL DEFAULT 100,
                    initiated_by VARCHAR(190) NULL,
                    total_target_count INT UNSIGNED NOT NULL DEFAULT 0,
                    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
                    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
                    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
                    remaining_count INT UNSIGNED NOT NULL DEFAULT 0,
                    locked_at DATETIME NULL,
                    locked_by VARCHAR(190) NULL,
                    last_run_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    error_message TEXT NULL,
                    meta_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_queue_campaign_id(campaign_id),
                    KEY idx_queue_status(status),
                    KEY idx_queue_last_run_at(last_run_at),
                    KEY idx_queue_locked_at(locked_at),
                    KEY idx_queue_send_mode(send_mode)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) {
                    error_log('campaign_ensure_queue_schema CREATE failed: ' . $conn->error);
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            error_log('campaign_ensure_queue_schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('campaign_create_queue_job')) {
    function campaign_create_queue_job(
        mysqli $conn,
        int    $campaignId,
        string $sendMode,
        string $initiatedBy,
        int    $batchSize,
        int    $maxPerRun
    ): array {
        $allowedModes = ['pending', 'failed', 'pending_and_failed'];
        if (!in_array($sendMode, $allowedModes, true)) $sendMode = 'pending';
        $batchSize   = max(1, min(500, $batchSize));
        $maxPerRun   = max(1, min(1000, $maxPerRun));
        $totalTarget = campaign_count_remaining_for_mode($conn, $campaignId, $sendMode);

        for ($i = 0; $i < 3; $i++) {
            $uid  = campaign_generate_uid('qjob');
            $stmt = $conn->prepare(
                "INSERT INTO `email_campaign_queue_jobs`
                 (job_uid, campaign_id, send_mode, status, batch_size, max_per_run,
                  initiated_by, total_target_count, remaining_count)
                 VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?)"
            );
            if (!$stmt) break;
            // types: s i s i i s i i  (8 params)
            $stmt->bind_param('sisiisii',
                $uid, $campaignId, $sendMode,
                $batchSize, $maxPerRun,
                $initiatedBy, $totalTarget, $totalTarget
            );
            if ($stmt->execute()) {
                $id = (int)$conn->insert_id;
                $stmt->close();
                return ['id' => $id, 'uid' => $uid];
            }
            $stmt->close();
        }
        return ['id' => 0, 'uid' => ''];
    }
}

if (!function_exists('campaign_get_active_queue_job')) {
    function campaign_get_active_queue_job(mysqli $conn, int $campaignId): ?array
    {
        if (!campaign_table_exists($conn, 'email_campaign_queue_jobs')) return null;
        $stmt = $conn->prepare(
            "SELECT * FROM `email_campaign_queue_jobs`
             WHERE campaign_id = ? AND status IN ('queued','processing','paused')
             ORDER BY created_at DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('campaign_lock_queue_job')) {
    function campaign_lock_queue_job(mysqli $conn, int $jobId, string $lockId): bool
    {
        $safeLock = $conn->real_escape_string(substr($lockId, 0, 190));
        $conn->query(
            "UPDATE `email_campaign_queue_jobs`
             SET status = 'processing', locked_at = NOW(), locked_by = '{$safeLock}'
             WHERE id = {$jobId}
               AND status IN ('queued','processing')
               AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))"
        );
        return $conn->affected_rows > 0;
    }
}

if (!function_exists('campaign_queue_job_set_error')) {
    function campaign_queue_job_set_error(mysqli $conn, int $jobId, string $error): void
    {
        $safeErr = $conn->real_escape_string(substr($error, 0, 500));
        $conn->query(
            "UPDATE `email_campaign_queue_jobs`
             SET status = 'error', error_message = '{$safeErr}',
                 locked_at = NULL, locked_by = NULL
             WHERE id = {$jobId}"
        );
    }
}

if (!function_exists('campaign_update_campaign_queue_status')) {
    function campaign_update_campaign_queue_status(mysqli $conn, int $campaignId): bool
    {
        if (!campaign_table_exists($conn, 'email_campaign_queue_jobs')) return false;
        $res = $conn->query(
            "SELECT status FROM `email_campaign_queue_jobs`
             WHERE campaign_id = {$campaignId}
             ORDER BY created_at DESC LIMIT 1"
        );
        if (!$res) return false;
        $row = $res->fetch_assoc();
        $queueStatus = $row ? match ((string)$row['status']) {
            'queued'     => 'queued',
            'processing' => 'processing',
            'completed'  => 'completed',
            'paused'     => 'paused',
            'cancelled'  => 'cancelled',
            'error'      => 'error',
            default      => 'none',
        } : 'none';
        $safe = $conn->real_escape_string($queueStatus);
        $conn->query(
            "UPDATE `email_campaigns`
             SET queue_status = '{$safe}', queue_last_run_at = NOW()
             WHERE id = {$campaignId}"
        );
        return true;
    }
}

if (!function_exists('campaign_update_queue_counts')) {
    function campaign_update_queue_counts(mysqli $conn, int $jobId): bool
    {
        $stmt = $conn->prepare(
            "SELECT campaign_id, send_mode FROM `email_campaign_queue_jobs` WHERE id = ? LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;
        $remaining = campaign_count_remaining_for_mode($conn, (int)$row['campaign_id'], (string)$row['send_mode']);
        $conn->query(
            "UPDATE `email_campaign_queue_jobs` SET remaining_count = {$remaining} WHERE id = {$jobId}"
        );
        return true;
    }
}

if (!function_exists('campaign_count_queue_remaining')) {
    function campaign_count_queue_remaining(mysqli $conn, int $campaignId, string $sendMode): int
    {
        return campaign_count_remaining_for_mode($conn, $campaignId, $sendMode);
    }
}

if (!function_exists('campaign_process_queue_job')) {
    function campaign_process_queue_job(mysqli $conn, int $jobId, int $limit = 0): array
    {
        $result = [
            'ok' => false, 'job_uid' => '', 'campaign_id' => 0,
            'attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0,
            'remaining' => 0, 'status' => '', 'error' => '',
        ];

        // Load job
        $jStmt = $conn->prepare("SELECT * FROM `email_campaign_queue_jobs` WHERE id = ? LIMIT 1");
        if (!$jStmt) { $result['error'] = 'DB prepare failed (job)'; return $result; }
        $jStmt->bind_param('i', $jobId);
        $jStmt->execute();
        $job = $jStmt->get_result()->fetch_assoc();
        $jStmt->close();

        if (!$job) { $result['error'] = 'Job not found'; return $result; }
        if (!in_array((string)$job['status'], ['queued', 'processing'], true)) {
            $result['status'] = (string)$job['status'];
            $result['error']  = 'Job not runnable: ' . $job['status'];
            return $result;
        }

        $result['job_uid']     = (string)$job['job_uid'];
        $result['campaign_id'] = (int)$job['campaign_id'];

        $lockId = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
        if (!campaign_lock_queue_job($conn, $jobId, $lockId)) {
            $result['error'] = 'Could not acquire lock';
            return $result;
        }

        $campaignId    = (int)$job['campaign_id'];
        $sendMode      = (string)$job['send_mode'];
        $maxPerRun     = max(1, (int)$job['max_per_run']);
        $effectiveLimit = $limit > 0 ? min($limit, $maxPerRun) : $maxPerRun;

        // Load campaign
        $cStmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
        if (!$cStmt) {
            campaign_queue_job_set_error($conn, $jobId, 'DB prepare failed (campaign)');
            $result['error'] = 'DB prepare failed (campaign)';
            return $result;
        }
        $cStmt->bind_param('i', $campaignId);
        $cStmt->execute();
        $campaign = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if (!$campaign) {
            campaign_queue_job_set_error($conn, $jobId, 'Campaign not found');
            $result['error'] = 'Campaign not found';
            return $result;
        }
        if ((string)$campaign['status'] === 'cancelled') {
            campaign_queue_job_set_error($conn, $jobId, 'Campaign cancelled');
            $result['error'] = 'Campaign cancelled';
            return $result;
        }
        if (!campaign_campaign_content_ready($campaign)) {
            campaign_queue_job_set_error($conn, $jobId, 'Campaign content not ready');
            $result['error'] = 'Campaign content not ready';
            return $result;
        }

        // Lazy-load layout + sender (needed in CLI / cron context)
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        if (!function_exists('buildMailLayout')) {
            $lp = $docRoot . '/api/mail/layout.php';
            if (file_exists($lp)) require_once $lp;
        }
        if (!function_exists('sendSES') && !function_exists('sendBrevo')) {
            $sp = $docRoot . '/api/ses-config.php';
            if (file_exists($sp)) @require_once $sp;
        }

        $statusWhere = match ($sendMode) {
            'failed'             => "delivery_status = 'failed'",
            'pending_and_failed' => "delivery_status IN ('pending','queued','failed')",
            default              => "delivery_status IN ('pending','queued')",
        };

        // Select & lock recipients in a transaction
        $conn->begin_transaction();
        $rStmt = $conn->prepare(
            "SELECT id, recipient_email, recipient_name, recipient_phone, tracking_token
             FROM `email_campaign_recipients`
             WHERE campaign_id = ? AND {$statusWhere}
             LIMIT ? FOR UPDATE"
        );
        if (!$rStmt) {
            $conn->rollback();
            campaign_queue_job_set_error($conn, $jobId, 'DB prepare failed (recipients)');
            $result['error'] = 'DB prepare failed (recipients)';
            return $result;
        }
        $rStmt->bind_param('ii', $campaignId, $effectiveLimit);
        $rStmt->execute();
        $rRes       = $rStmt->get_result();
        $recipients = [];
        while ($row = $rRes->fetch_assoc()) $recipients[] = $row;
        $rStmt->close();

        if (empty($recipients)) {
            $conn->commit();
            $remaining = campaign_count_remaining_for_mode($conn, $campaignId, $sendMode);
            $conn->query(
                "UPDATE `email_campaign_queue_jobs`
                 SET status = 'completed', completed_at = NOW(),
                     remaining_count = {$remaining}, last_run_at = NOW(),
                     locked_at = NULL, locked_by = NULL
                 WHERE id = {$jobId}"
            );
            campaign_update_campaign_queue_status($conn, $campaignId);
            $result['ok'] = true; $result['status'] = 'completed'; $result['remaining'] = $remaining;
            return $result;
        }

        // Lock selected recipients to 'queued'
        $recipientIds = array_column($recipients, 'id');
        $ph = implode(',', array_fill(0, count($recipientIds), '?'));
        $lkStmt = $conn->prepare(
            "UPDATE `email_campaign_recipients` SET delivery_status = 'queued' WHERE id IN ({$ph})"
        );
        $lkStmt->bind_param(str_repeat('i', count($recipientIds)), ...$recipientIds);
        $lkStmt->execute();
        $lkStmt->close();
        $conn->commit();

        // Set campaign to 'sending'
        $conn->query(
            "UPDATE `email_campaigns` SET status = 'sending'
             WHERE id = {$campaignId} AND status NOT IN ('sending','cancelled')"
        );

        $batchInfo = campaign_create_send_batch(
            $conn, $campaignId, $sendMode,
            (string)($job['initiated_by'] ?? 'queue'), $effectiveLimit
        );
        $batchDbId = $batchInfo['id'];

        $sentCount   = 0;
        $failedCount = 0;
        $lastError   = '';

        foreach ($recipients as $rec) {
            $rId   = (int)$rec['id'];
            $email = (string)$rec['recipient_email'];
            $name  = (string)($rec['recipient_name'] ?: 'Customer');
            $token = (string)$rec['tracking_token'];
            if ($token === '') $token = campaign_get_or_create_tracking_token($conn, $rId);

            $fullHtml = campaign_build_campaign_email_html(
                $campaign,
                array_merge($rec, ['tracking_token' => $token]),
                true
            );
            $subject = campaign_replace_tokens(
                (string)($campaign['subject'] ?: 'Update from Demo Company'),
                $rec, $campaign
            );

            if (strpos($fullHtml, 'localhost') !== false) {
                $errMsg = 'Tracking URL contains localhost';
                error_log("QUEUE SEND ABORTED [rec={$rId}]: {$errMsg}");
                campaign_mark_recipient_status($conn, $rId, 'failed', null, $errMsg);
                $failedCount++; $lastError = $errMsg;
                continue;
            }

            $sr = campaign_send_email_via_existing_sender($email, $name, $subject, $fullHtml);
            if ($sr['ok']) {
                campaign_mark_recipient_status($conn, $rId, 'sent', $sr['message_id']);
                $sentCount++;
            } else {
                $err = $sr['error'] ?: 'Unknown error';
                campaign_mark_recipient_status($conn, $rId, 'failed', null, $err);
                $failedCount++; $lastError = $err;
            }
        }

        $attemptedCount = $sentCount + $failedCount;
        campaign_recalculate_metrics($conn, $campaignId);

        $remaining      = campaign_count_remaining_for_mode($conn, $campaignId, $sendMode);
        $totalProcessed = (int)($job['processed_count'] ?? 0) + $attemptedCount;
        $totalSent      = (int)($job['sent_count'] ?? 0)      + $sentCount;
        $totalFailed    = (int)($job['failed_count'] ?? 0)    + $failedCount;

        $batchStatus = $failedCount === 0 ? 'completed'
            : ($sentCount > 0 ? 'partial_failed' : 'failed');

        campaign_update_send_batch($conn, $batchDbId, [
            'attempted_count' => $attemptedCount,
            'sent_count'      => $sentCount,
            'failed_count'    => $failedCount,
            'skipped_count'   => 0,
            'remaining_count' => $remaining,
            'status'          => $batchStatus,
            'error_message'   => $lastError !== '' ? $lastError : null,
        ]);

        $newJobStatus = $remaining === 0 ? 'completed' : 'queued';
        $safeJobStatus = $conn->real_escape_string($newJobStatus);
        $conn->query(
            "UPDATE `email_campaign_queue_jobs`
             SET status = '{$safeJobStatus}',
                 processed_count = {$totalProcessed},
                 sent_count      = {$totalSent},
                 failed_count    = {$totalFailed},
                 remaining_count = {$remaining},
                 last_run_at     = NOW(),
                 locked_at       = NULL,
                 locked_by       = NULL"
             . ($newJobStatus === 'completed' ? ', completed_at = NOW()' : '')
             . " WHERE id = {$jobId}"
        );

        campaign_update_campaign_queue_status($conn, $campaignId);

        // Update campaign status if no pending/queued remain
        $pendingQueued = campaign_count_remaining_for_mode($conn, $campaignId, 'pending');
        if ($pendingQueued === 0) {
            $mRes = $conn->query(
                "SELECT failed_count FROM `email_campaigns` WHERE id = {$campaignId}"
            );
            $totalFailedCamp = $mRes ? (int)$mRes->fetch_row()[0] : 0;
            $finalCampStatus = $totalFailedCamp > 0 ? 'partial_failed' : 'sent';
            $conn->query(
                "UPDATE `email_campaigns`
                 SET status = '{$finalCampStatus}', sent_at = IF(sent_at IS NULL, NOW(), sent_at)
                 WHERE id = {$campaignId}"
            );
        }

        $result['ok']        = true;
        $result['attempted'] = $attemptedCount;
        $result['sent']      = $sentCount;
        $result['failed']    = $failedCount;
        $result['remaining'] = $remaining;
        $result['status']    = $newJobStatus;
        return $result;
    }
}

// ============================================================================
// TRACKING ENDPOINT HELPERS
// ============================================================================

if (!function_exists('campaign_output_tracking_pixel')) {
    function campaign_output_tracking_pixel(): void
    {
        // 1x1 transparent GIF (35 bytes)
        $gif = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00"
             . "\xFF\xFF\xFF\x00\x00\x00\x21\xF9\x04\x00\x00\x00\x00\x00"
             . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00"
             . "\x3B";
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $gif;
    }
}

if (!function_exists('campaign_normalize_tracking_url')) {
    function campaign_normalize_tracking_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        // Must start with http:// or https://
        if (!preg_match('#^https?://#i', $url)) return '';
        return $url;
    }
}

if (!function_exists('campaign_is_safe_redirect_url')) {
    function campaign_is_safe_redirect_url(string $url): bool
    {
        if ($url === '') return false;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}

if (!function_exists('campaign_get_client_ip_for_hash')) {
    function campaign_get_client_ip_for_hash(): string
    {
        // Prefer forwarded IP (behind proxy/load balancer); take only first entry
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}

if (!function_exists('campaign_redirect_safely')) {
    function campaign_redirect_safely(string $url): never
    {
        if ($url !== '' && campaign_is_safe_redirect_url($url)) {
            header('Location: ' . $url, true, 302);
        } else {
            header('Location: https://demo.local', true, 302);
        }
        exit;
    }
}

// ============================================================================
// AUDIENCE GROUP SCHEMA & HELPERS (Phase 1K)
// ============================================================================

if (!function_exists('campaign_allowed_lead_source_tables')) {
    function campaign_allowed_lead_source_tables(): array
    {
        return ['tiktok_leads', 'telegram_leads', 'meta_leads', 'webinar_leads'];
    }
}

if (!function_exists('campaign_detect_lead_table_columns')) {
    function campaign_detect_lead_table_columns(mysqli $conn, string $table): array
    {
        if (!in_array($table, campaign_allowed_lead_source_tables(), true)) return [];
        $res = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if (!$res) return [];
        $cols = [];
        while ($row = $res->fetch_assoc()) {
            $cols[] = (string)$row['Field'];
        }
        return $cols;
    }
}

if (!function_exists('campaign_ensure_audience_schema')) {
    function campaign_ensure_audience_schema(mysqli $conn): bool
    {
        try {
            if (!campaign_table_exists($conn, 'email_audience_groups')) {
                $sql = "CREATE TABLE `email_audience_groups` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    group_uid VARCHAR(64) NOT NULL UNIQUE,
                    group_name VARCHAR(190) NOT NULL,
                    description TEXT NULL,
                    source_type ENUM('manual','csv_import','lead_table','mixed') NOT NULL DEFAULT 'manual',
                    total_members INT UNSIGNED NOT NULL DEFAULT 0,
                    active_members INT UNSIGNED NOT NULL DEFAULT 0,
                    unsubscribed_members INT UNSIGNED NOT NULL DEFAULT 0,
                    created_by VARCHAR(190) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_group_name(group_name),
                    KEY idx_group_source_type(source_type),
                    KEY idx_group_created_at(created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            if (!campaign_table_exists($conn, 'email_audience_group_members')) {
                $sql = "CREATE TABLE `email_audience_group_members` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    group_id BIGINT UNSIGNED NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    name VARCHAR(190) NULL,
                    phone VARCHAR(80) NULL,
                    source_table VARCHAR(120) NULL,
                    source_id VARCHAR(120) NULL,
                    status ENUM('active','unsubscribed','bounced','invalid') NOT NULL DEFAULT 'active',
                    added_by VARCHAR(190) NULL,
                    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_group_email(group_id, email),
                    KEY idx_group_member_group(group_id),
                    KEY idx_group_member_email(email),
                    KEY idx_group_member_status(status),
                    KEY idx_group_member_source(source_table, source_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            // Migration: add created_at if missing.
            // The original CREATE TABLE used added_at; campaign-schema-test.php expects created_at.
            if (!campaign_column_exists($conn, 'email_audience_group_members', 'created_at')) {
                $conn->query(
                    "ALTER TABLE `email_audience_group_members`
                     ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     AFTER `added_by`"
                );
            }

            // Migration: ensure uq_group_email unique key exists.
            // Required by INSERT IGNORE for O(1) duplicate prevention on large imports.
            $uqIdxRes = $conn->query(
                "SHOW INDEX FROM `email_audience_group_members` WHERE Key_name = 'uq_group_email'"
            );
            if ($uqIdxRes && $uqIdxRes->num_rows === 0) {
                // Check for existing (group_id, email) duplicates before adding a UNIQUE key.
                $uqDupRes = $conn->query(
                    "SELECT COUNT(*) AS cnt FROM (
                         SELECT 1 FROM `email_audience_group_members`
                         GROUP BY group_id, LOWER(TRIM(email))
                         HAVING COUNT(*) > 1
                     ) AS dupes"
                );
                $uqHasDups = $uqDupRes && (int)($uqDupRes->fetch_assoc()['cnt'] ?? 0) > 0;
                if (!$uqHasDups) {
                    $conn->query(
                        "ALTER TABLE `email_audience_group_members`
                         ADD UNIQUE KEY `uq_group_email` (group_id, email)"
                    );
                } else {
                    // Duplicates exist — add a non-unique index for query performance instead.
                    $uqFallback = $conn->query(
                        "SHOW INDEX FROM `email_audience_group_members` WHERE Key_name = 'idx_group_email'"
                    );
                    if ($uqFallback && $uqFallback->num_rows === 0) {
                        $conn->query(
                            "ALTER TABLE `email_audience_group_members`
                             ADD KEY `idx_group_email` (group_id, email)"
                        );
                    }
                }
            }

            if (!campaign_table_exists($conn, 'email_audience_group_imports')) {
                $sql = "CREATE TABLE `email_audience_group_imports` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    group_id BIGINT UNSIGNED NOT NULL,
                    import_uid VARCHAR(64) NOT NULL UNIQUE,
                    source_type ENUM('csv','lead_table') NOT NULL DEFAULT 'csv',
                    source_name VARCHAR(190) NULL,
                    original_filename VARCHAR(255) NULL,
                    import_file_hash VARCHAR(64) NULL,
                    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
                    imported_by VARCHAR(190) NULL,
                    status ENUM('completed','partial_failed','failed') NOT NULL DEFAULT 'completed',
                    error_message TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_group_import_group(group_id),
                    KEY idx_group_import_source(source_type),
                    KEY idx_group_import_created_at(created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) return false;
            }

            return true;
        } catch (Exception $e) {
            error_log("campaign_ensure_audience_schema error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('campaign_recalculate_group_counts')) {
    function campaign_recalculate_group_counts(mysqli $conn, int $groupId): bool
    {
        try {
            $conn->query(
                "UPDATE `email_audience_groups` SET
                 total_members        = (SELECT COUNT(*) FROM `email_audience_group_members` WHERE group_id = {$groupId}),
                 active_members       = (SELECT COUNT(*) FROM `email_audience_group_members` WHERE group_id = {$groupId} AND status = 'active'),
                 unsubscribed_members = (SELECT COUNT(*) FROM `email_audience_group_members` WHERE group_id = {$groupId} AND status = 'unsubscribed'),
                 updated_at           = NOW()
                 WHERE id = {$groupId}"
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('campaign_group_uid_exists')) {
    function campaign_group_uid_exists(mysqli $conn, string $uid): bool
    {
        $safe = $conn->real_escape_string($uid);
        $res = $conn->query("SELECT 1 FROM `email_audience_groups` WHERE group_uid = '{$safe}' LIMIT 1");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('campaign_generate_unique_group_uid')) {
    function campaign_generate_unique_group_uid(mysqli $conn): string
    {
        for ($i = 0; $i < 10; $i++) {
            $uid = 'grp_' . substr((string)time(), -6) . '_' . bin2hex(random_bytes(4));
            if (!campaign_group_uid_exists($conn, $uid)) return $uid;
        }
        return 'grp_' . bin2hex(random_bytes(8)) . '_' . uniqid();
    }
}

if (!function_exists('campaign_insert_group_member_with_duplicate_handling')) {
    function campaign_insert_group_member_with_duplicate_handling(
        mysqli $conn,
        int $groupId,
        string $email,
        ?string $name,
        ?string $phone,
        ?string $sourceTable,
        ?string $sourceId,
        string $addedBy
    ): int {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO `email_audience_group_members`
             (group_id, email, name, phone, source_table, source_id, status, added_by)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        if (!$stmt) return -1;
        $stmt->bind_param('issssss', $groupId, $email, $name, $phone, $sourceTable, $sourceId, $addedBy);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $newId = (int)$conn->insert_id;
        $stmt->close();
        if (!$ok) return -1;
        return $affected === 0 ? 0 : $newId;
    }
}

if (!function_exists('campaign_copy_group_to_campaign_recipients')) {
    function campaign_copy_group_to_campaign_recipients(mysqli $conn, int $groupId, int $campaignId): array
    {
        $result = ['ok' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'error' => ''];
        try {
            $stmt = $conn->prepare(
                "SELECT email, name, phone FROM `email_audience_group_members`
                 WHERE group_id = ? AND status = 'active'"
            );
            if (!$stmt) { $result['error'] = 'Query prepare failed'; return $result; }
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $email = strtolower(trim((string)($row['email'] ?? '')));
                $name  = $row['name'] !== '' ? $row['name'] : null;
                $phone = $row['phone'] !== '' ? $row['phone'] : null;
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result['failed']++;
                    continue;
                }
                $errorReason = '';
                $res = campaign_insert_recipient_with_retry($conn, $campaignId, $email, $name, $phone, $errorReason);
                if ($res > 0) $result['imported']++;
                elseif ($res === 0) $result['skipped']++;
                else $result['failed']++;
            }

            campaign_recalculate_metrics($conn, $campaignId);
            $result['ok'] = true;
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }
}

// ============================================================================
// SCHEDULED CAMPAIGN HELPERS (Phase 1L)
// ============================================================================

if (!function_exists('campaign_ensure_schedule_schema')) {
    function campaign_ensure_schedule_schema(mysqli $conn): bool
    {
        try {
            if (!campaign_table_exists($conn, 'email_campaign_schedules')) {
                $sql = "CREATE TABLE `email_campaign_schedules` (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    schedule_uid VARCHAR(64) NOT NULL UNIQUE,
                    campaign_id BIGINT UNSIGNED NOT NULL,
                    schedule_name VARCHAR(190) NULL,
                    scheduled_at DATETIME NOT NULL,
                    timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
                    send_mode ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
                    batch_size INT UNSIGNED NOT NULL DEFAULT 25,
                    max_per_run INT UNSIGNED NOT NULL DEFAULT 100,
                    status ENUM('scheduled','queued','processing','completed','cancelled','missed','error') NOT NULL DEFAULT 'scheduled',
                    queue_job_id BIGINT UNSIGNED NULL,
                    created_by VARCHAR(190) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    queued_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    cancelled_at DATETIME NULL,
                    error_message TEXT NULL,
                    meta_json LONGTEXT NULL,
                    KEY idx_schedule_campaign_id(campaign_id),
                    KEY idx_schedule_status(status),
                    KEY idx_schedule_scheduled_at(scheduled_at),
                    KEY idx_schedule_queue_job_id(queue_job_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if (!$conn->query($sql)) {
                    error_log('campaign_ensure_schedule_schema CREATE failed: ' . $conn->error);
                    return false;
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('campaign_ensure_schedule_schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('campaign_create_schedule')) {
    function campaign_create_schedule(
        mysqli $conn,
        int    $campaignId,
        string $scheduleName,
        string $scheduledAt,
        string $timezone,
        string $sendMode,
        int    $batchSize,
        int    $maxPerRun,
        string $createdBy
    ): array {
        $allowed = ['pending', 'failed', 'pending_and_failed'];
        if (!in_array($sendMode, $allowed, true)) $sendMode = 'pending';
        $batchSize = max(1, min(500, $batchSize));
        $maxPerRun = max(1, min(1000, $maxPerRun));

        for ($i = 0; $i < 5; $i++) {
            $uid  = 'sched_' . substr((string)time(), -6) . '_' . bin2hex(random_bytes(4));
            $stmt = $conn->prepare(
                "INSERT INTO `email_campaign_schedules`
                 (schedule_uid, campaign_id, schedule_name, scheduled_at, timezone,
                  send_mode, batch_size, max_per_run, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)"
            );
            if (!$stmt) break;
            $stmt->bind_param('sississis',
                $uid, $campaignId, $scheduleName, $scheduledAt, $timezone,
                $sendMode, $batchSize, $maxPerRun, $createdBy
            );
            if ($stmt->execute()) {
                $id = (int)$conn->insert_id;
                $stmt->close();
                return ['id' => $id, 'uid' => $uid];
            }
            if ($stmt->errno !== 1062) { $stmt->close(); break; }
            $stmt->close();
        }
        return ['id' => 0, 'uid' => ''];
    }
}

if (!function_exists('campaign_create_schedules_for_weekdays')) {
    function campaign_create_schedules_for_weekdays(
        mysqli $conn,
        int    $campaignId,
        string $startDate,
        string $endDate,
        array  $weekdays,
        string $sendTime,
        string $timezone,
        string $sendMode,
        int    $batchSize,
        int    $maxPerRun,
        string $createdBy
    ): array {
        $results = [];
        try {
            $start   = new DateTime($startDate . ' 00:00:00');
            $end     = new DateTime($endDate   . ' 23:59:59');
            $current = clone $start;

            while ($current <= $end) {
                $dow = (int)$current->format('N'); // 1=Mon … 7=Sun
                if (in_array($dow, $weekdays, true)) {
                    [$h, $m] = array_pad(explode(':', $sendTime, 2), 2, '00');
                    $dt = clone $current;
                    $dt->setTime((int)$h, (int)$m, 0);
                    $scheduledAt = $dt->format('Y-m-d H:i:s');
                    $name        = $dt->format('D, d M Y') . ' · ' . sprintf('%02d:%02d', (int)$h, (int)$m);
                    $r = campaign_create_schedule(
                        $conn, $campaignId, $name, $scheduledAt, $timezone,
                        $sendMode, $batchSize, $maxPerRun, $createdBy
                    );
                    $results[] = ['date' => $scheduledAt, 'id' => $r['id'], 'ok' => $r['id'] > 0];
                }
                $current->modify('+1 day');
            }
        } catch (Exception $e) {
            error_log('campaign_create_schedules_for_weekdays error: ' . $e->getMessage());
        }
        return $results;
    }
}

if (!function_exists('campaign_queue_due_schedules')) {
    function campaign_queue_due_schedules(mysqli $conn): array
    {
        $queued = [];
        $errors = [];

        if (!campaign_table_exists($conn, 'email_campaign_schedules')) return compact('queued', 'errors');

        try {
            $conn->begin_transaction();

            $res = $conn->query(
                "SELECT s.id, s.campaign_id, s.send_mode, s.batch_size, s.max_per_run,
                        c.email_body, c.template_id
                 FROM `email_campaign_schedules` s
                 INNER JOIN `email_campaigns` c ON c.id = s.campaign_id
                 WHERE s.status = 'scheduled' AND s.scheduled_at <= NOW()
                 ORDER BY s.scheduled_at ASC
                 FOR UPDATE"
            );
            if (!$res) {
                $conn->rollback();
                return compact('queued', 'errors');
            }

            $rows = [];
            while ($sched = $res->fetch_assoc()) $rows[] = $sched;

            foreach ($rows as $sched) {
                $schedId  = (int)$sched['id'];
                $campId   = (int)$sched['campaign_id'];
                $sendMode = (string)$sched['send_mode'];

                // Content check
                $hasContent = (!empty($sched['email_body']) && trim($sched['email_body']) !== '')
                           || (!empty($sched['template_id']) && (int)$sched['template_id'] > 0);
                if (!$hasContent) {
                    $conn->query(
                        "UPDATE `email_campaign_schedules`
                         SET status='error',
                             error_message='Campaign content not ready — no email body or template assigned.',
                             updated_at=NOW()
                         WHERE id={$schedId}"
                    );
                    $errors[] = ['schedule_id' => $schedId, 'campaign_id' => $campId, 'error' => 'No content'];
                    continue;
                }

                // Recipient check
                $eligible = campaign_count_remaining_for_mode($conn, $campId, $sendMode);
                if ($eligible === 0) {
                    // Safe: mark completed — nothing to send, not an error
                    $conn->query(
                        "UPDATE `email_campaign_schedules`
                         SET status='completed',
                             completed_at=NOW(),
                             error_message='No eligible recipients for send_mode={$sendMode}.',
                             updated_at=NOW()
                         WHERE id={$schedId}"
                    );
                    $queued[] = ['schedule_id' => $schedId, 'note' => 'Completed — no eligible recipients'];
                    continue;
                }

                // Create queue job
                $job = campaign_create_queue_job(
                    $conn, $campId, $sendMode, 'cron-scheduler',
                    (int)$sched['batch_size'], (int)$sched['max_per_run']
                );
                if ($job['id'] <= 0) {
                    $conn->query(
                        "UPDATE `email_campaign_schedules`
                         SET status='error',
                             error_message='Failed to create queue job.',
                             updated_at=NOW()
                         WHERE id={$schedId}"
                    );
                    $errors[] = ['schedule_id' => $schedId, 'campaign_id' => $campId, 'error' => 'Queue job create failed'];
                    continue;
                }

                $jobId = (int)$job['id'];
                $conn->query(
                    "UPDATE `email_campaign_schedules`
                     SET status='queued', queue_job_id={$jobId}, queued_at=NOW(), updated_at=NOW()
                     WHERE id={$schedId}"
                );
                campaign_update_campaign_queue_status($conn, $campId);

                $queued[] = ['schedule_id' => $schedId, 'campaign_id' => $campId, 'job_id' => $jobId, 'eligible' => $eligible];
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('campaign_queue_due_schedules transaction error: ' . $e->getMessage());
        }

        return compact('queued', 'errors');
    }
}

if (!function_exists('campaign_update_schedule_status_from_queue')) {
    function campaign_update_schedule_status_from_queue(mysqli $conn, int $jobId, string $jobStatus): void
    {
        if (!campaign_table_exists($conn, 'email_campaign_schedules')) return;
        if ($jobId <= 0) return;

        if ($jobStatus === 'completed') {
            $conn->query(
                "UPDATE `email_campaign_schedules`
                 SET status='completed', completed_at=NOW(), updated_at=NOW()
                 WHERE queue_job_id={$jobId} AND status IN ('queued','processing')"
            );
        } elseif ($jobStatus === 'error') {
            $conn->query(
                "UPDATE `email_campaign_schedules`
                 SET status='error', updated_at=NOW()
                 WHERE queue_job_id={$jobId} AND status IN ('queued','processing')"
            );
        }
    }
}

if (!function_exists('campaign_get_next_schedule_for_campaign')) {
    function campaign_get_next_schedule_for_campaign(mysqli $conn, int $campaignId): ?array
    {
        if (!campaign_table_exists($conn, 'email_campaign_schedules')) return null;
        $res = $conn->query(
            "SELECT id, schedule_uid, schedule_name, scheduled_at, status, send_mode
             FROM `email_campaign_schedules`
             WHERE campaign_id = {$campaignId}
               AND status = 'scheduled'
               AND scheduled_at >= NOW()
             ORDER BY scheduled_at ASC
             LIMIT 1"
        );
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}
