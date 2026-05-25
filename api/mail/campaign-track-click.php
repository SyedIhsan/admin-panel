<?php
declare(strict_types=1);

/**
 * campaign-track-click.php
 * Public endpoint to track link clicks and redirect to destination.
 */

// Load database and helpers
require_once __DIR__ . '/../db_router.php';
require_once __DIR__ . '/campaign-helpers.php';

// Get mysqli connection (Main/Billing DB)
$conn = getBillingConn();

$destinationUrl = '';
$token = trim((string)($_GET['t'] ?? ''));
$encodedUrl = trim((string)($_GET['u'] ?? ''));

// Decode destination URL
if ($encodedUrl !== '') {
    $destinationUrl = campaign_normalize_tracking_url(campaign_base64url_decode($encodedUrl));
}

if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    
    if ($token !== '' && $destinationUrl !== '' && campaign_is_safe_redirect_url($destinationUrl)) {
        try {
            // Find recipient by token
            $stmt = $conn->prepare("SELECT id, campaign_id FROM `email_campaign_recipients` WHERE tracking_token = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $res = $stmt->get_result();
                $recipient = $res->fetch_assoc();
                $stmt->close();
                
                if ($recipient) {
                    $recipientId = (int)$recipient['id'];
                    $campaignId = (int)$recipient['campaign_id'];
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                    $ipHash = campaign_hash_ip(campaign_get_client_ip_for_hash());
                    $linkHash = hash('sha256', $destinationUrl);
                    
                    // 1. Record the click
                    $clickStmt = $conn->prepare("
                        INSERT INTO `email_campaign_link_clicks` 
                        (campaign_id, recipient_id, link_url, link_hash, clicked_at, user_agent, ip_hash)
                        VALUES (?, ?, ?, ?, NOW(), ?, ?)
                    ");
                    
                    if ($clickStmt) {
                        $clickStmt->bind_param('iissss', $campaignId, $recipientId, $destinationUrl, $linkHash, $userAgent, $ipHash);
                        $clickStmt->execute();
                        $clickStmt->close();
                    }
                    
                    // 2. Update recipient engagement
                    $updateStmt = $conn->prepare("
                        UPDATE `email_campaign_recipients` 
                        SET clicked = 1,
                            first_click_at = IF(first_click_at IS NULL, NOW(), first_click_at),
                            last_click_at = NOW(),
                            click_count = click_count + 1
                        WHERE id = ?
                    ");
                    
                    if ($updateStmt) {
                        $updateStmt->bind_param('i', $recipientId);
                        $updateStmt->execute();
                        $updateStmt->close();
                        
                        // Recalculate campaign metrics
                        campaign_recalculate_metrics($conn, $campaignId);
                    }
                }
            }
        } catch (Exception $e) {
            // Log error silently, do not break redirect
            error_log("campaign-track-click.php error: " . $e->getMessage());
        }
    }
}

// Redirect to destination or fallback
campaign_redirect_safely($destinationUrl);
