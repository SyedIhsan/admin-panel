<?php
declare(strict_types=1);

/**
 * campaign-track-open.php
 * Public endpoint to track email opens using a 1x1 transparent pixel.
 */

// Load database and helpers
require_once __DIR__ . '/../db_router.php';
require_once __DIR__ . '/campaign-helpers.php';

// Get mysqli connection (Main/Billing DB)
$conn = getBillingConn();

if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    
    // Get tracking token
    $token = trim((string)($_GET['t'] ?? ''));
    
    if ($token !== '') {
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
                    
                    // Update recipient engagement
                    $updateStmt = $conn->prepare("
                        UPDATE `email_campaign_recipients` 
                        SET opened = 1,
                            first_open_at = IF(first_open_at IS NULL, NOW(), first_open_at),
                            last_open_at = NOW(),
                            open_count = open_count + 1
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
            // Log error silently, do not break pixel output
            error_log("campaign-track-open.php error: " . $e->getMessage());
        }
    }
}

// Always output the 1x1 pixel
campaign_output_tracking_pixel();
