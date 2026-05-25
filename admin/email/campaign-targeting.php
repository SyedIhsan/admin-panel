<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/db_router.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/api/mail/campaign-helpers.php';

$conn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// ── GET params ────────────────────────────────────────────────────────────────
$sourceCampaignId = (int)($_GET['source_campaign_id'] ?? 0);
$segment          = trim($_GET['segment'] ?? 'clicked');
$includeUnsub     = isset($_GET['include_unsub']) && $_GET['include_unsub'] === '1';
$newCampaignName  = trim($_GET['new_campaign_name'] ?? '');
$newSubject       = trim($_GET['new_subject'] ?? '');
$copyContent      = isset($_GET['copy_content']) && $_GET['copy_content'] === '1';

$validSegments = [
    'clicked'       => 'Clicked any link',
    'opened'        => 'Opened (did not click)',
    'opened_any'    => 'Opened (including clicks)',
    'not_opened'    => 'Did not open',
    'not_clicked'   => 'Did not click (sent)',
    'sent'          => 'All sent (any engagement)',
    'delivered'     => 'Delivered',
    'failed'        => 'Failed to deliver',
    'bounced'       => 'Bounced',
    'complained'    => 'Marked as spam / complained',
    'unsubscribed'  => 'Unsubscribed',
];

if (!array_key_exists($segment, $validSegments)) {
    $segment = 'clicked';
}

// ── Load all campaigns for source dropdown ────────────────────────────────────
$allCampaigns = [];
$listRes = $conn->query(
    "SELECT id, campaign_name, campaign_uid, status, sent_count, opened_count, clicked_count
     FROM `email_campaigns`
     ORDER BY created_at DESC
     LIMIT 200"
);
while ($listRow = $listRes->fetch_assoc()) {
    $allCampaigns[] = $listRow;
}

// ── Load source campaign ──────────────────────────────────────────────────────
$sourceCampaign = null;
if ($sourceCampaignId > 0) {
    $stmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $sourceCampaignId);
    $stmt->execute();
    $sourceCampaign = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── Segment query builder ─────────────────────────────────────────────────────
function targeting_get_segment_where(string $segment, bool $includeUnsub): string
{
    $unsubClause = $includeUnsub ? '' : " AND delivery_status != 'unsubscribed'";
    return match ($segment) {
        'clicked'      => "clicked = 1" . $unsubClause,
        'opened'       => "opened = 1 AND clicked = 0" . $unsubClause,
        'opened_any'   => "opened = 1" . $unsubClause,
        'not_opened'   => "delivery_status = 'sent' AND opened = 0" . $unsubClause,
        'not_clicked'  => "delivery_status = 'sent' AND clicked = 0" . $unsubClause,
        'sent'         => "delivery_status = 'sent'" . $unsubClause,
        'delivered'    => "delivery_status IN ('sent','delivered')" . $unsubClause,
        'failed'       => "delivery_status = 'failed'",
        'bounced'      => "delivery_status = 'bounced'",
        'complained'   => "delivery_status IN ('spam','complained')",
        'unsubscribed' => "delivery_status = 'unsubscribed'",
        default        => "clicked = 1" . $unsubClause,
    };
}

// ── Preview count & sample rows ───────────────────────────────────────────────
$previewCount = 0;
$sampleRows   = [];

if ($sourceCampaign) {
    $cid = (int)$sourceCampaign['id'];
    $whereClause = targeting_get_segment_where($segment, $includeUnsub);

    $countRes = $conn->query(
        "SELECT COUNT(*) FROM `email_campaign_recipients`
         WHERE campaign_id = {$cid} AND {$whereClause}"
    );
    $previewCount = $countRes ? (int)$countRes->fetch_row()[0] : 0;

    $sampleRes = $conn->query(
        "SELECT recipient_email, recipient_name, delivery_status, opened, clicked
         FROM `email_campaign_recipients`
         WHERE campaign_id = {$cid} AND {$whereClause}
         ORDER BY id ASC
         LIMIT 20"
    );
    while ($sr = $sampleRes->fetch_assoc()) {
        $sampleRows[] = $sr;
    }
}

// ── Handle POST: Create targeted campaign ─────────────────────────────────────
$successCampaignId = null;
$postError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
    } catch (Exception $e) {
        $postError = 'CSRF validation failed. Please try again.';
    }

    if ($postError === '') {
        $postSourceId    = (int)($_POST['source_campaign_id'] ?? 0);
        $postSegment     = trim($_POST['segment'] ?? 'clicked');
        $postIncludeUnsub = (bool)($_POST['include_unsub'] ?? false);
        $postName        = trim($_POST['new_campaign_name'] ?? '');
        $postSubject     = trim($_POST['new_subject'] ?? '');
        $postCopyContent = (bool)($_POST['copy_content'] ?? false);

        if (!array_key_exists($postSegment, $validSegments)) $postSegment = 'clicked';

        if ($postSourceId <= 0) {
            $postError = 'Please select a source campaign.';
        } elseif ($postName === '') {
            $postError = 'Campaign name is required.';
        } elseif ($postSubject === '') {
            $postError = 'Subject line is required.';
        } else {
            // Load source campaign for content copy
            $srcStmt = $conn->prepare("SELECT * FROM `email_campaigns` WHERE id = ? LIMIT 1");
            $srcStmt->bind_param('i', $postSourceId);
            $srcStmt->execute();
            $srcCamp = $srcStmt->get_result()->fetch_assoc();
            $srcStmt->close();

            if (!$srcCamp) {
                $postError = 'Source campaign not found.';
            } else {
                $newUid = campaign_generate_unique_uid($conn, 'tgt');
                $adminLabel = campaign_safe_admin_label();
                $targetFilterJson = json_encode([
                    'source_campaign_id' => $postSourceId,
                    'segment'            => $postSegment,
                    'include_unsub'      => $postIncludeUnsub,
                ], JSON_UNESCAPED_UNICODE);

                $insertSql = "INSERT INTO `email_campaigns`
                    (campaign_uid, campaign_name, subject, campaign_type, target_filter, status, created_by)
                    VALUES (?, ?, ?, 'targeted_campaign', ?, 'draft', ?)";
                $insStmt = $conn->prepare($insertSql);
                $insStmt->bind_param('sssss', $newUid, $postName, $postSubject, $targetFilterJson, $adminLabel);

                if ($insStmt->execute()) {
                    $newCampaignDbId = (int)$conn->insert_id;
                    $insStmt->close();

                    // Copy content if requested
                    if ($postCopyContent) {
                        $updateSql = "UPDATE `email_campaigns` SET
                            preheader = ?, email_body = ?, button_text = ?, button_url = ?,
                            closing_text = ?, brand_name = ?, support_email = ?, footer_note = ?,
                            content_updated_at = NOW(), content_updated_by = ?
                            WHERE id = ?";
                        $updStmt = $conn->prepare($updateSql);
                        $preheader    = (string)($srcCamp['preheader'] ?? '');
                        $emailBody    = (string)($srcCamp['email_body'] ?? '');
                        $buttonText   = (string)($srcCamp['button_text'] ?? '');
                        $buttonUrl    = (string)($srcCamp['button_url'] ?? '');
                        $closingText  = (string)($srcCamp['closing_text'] ?? '');
                        $brandName    = (string)($srcCamp['brand_name'] ?? '');
                        $supportEmail = (string)($srcCamp['support_email'] ?? '');
                        $footerNote   = (string)($srcCamp['footer_note'] ?? '');
                        $updStmt->bind_param(
                            'sssssssssi',
                            $preheader, $emailBody, $buttonText, $buttonUrl,
                            $closingText, $brandName, $supportEmail, $footerNote,
                            $adminLabel, $newCampaignDbId
                        );
                        $updStmt->execute();
                        $updStmt->close();
                    }

                    // Copy recipients from source segment
                    $srcWhereClause = targeting_get_segment_where($postSegment, $postIncludeUnsub);
                    $recRes = $conn->query(
                        "SELECT recipient_email, recipient_name, recipient_phone
                         FROM `email_campaign_recipients`
                         WHERE campaign_id = {$postSourceId} AND {$srcWhereClause}
                         ORDER BY id ASC"
                    );

                    $inserted = 0;
                    $skipped  = 0;
                    $copiedEmails = [];

                    while ($recRow = $recRes->fetch_assoc()) {
                        $rEmail = strtolower(trim((string)$recRow['recipient_email']));
                        if ($rEmail === '' || isset($copiedEmails[$rEmail])) {
                            $skipped++;
                            continue;
                        }
                        $copiedEmails[$rEmail] = true;

                        $token = campaign_generate_tracking_token();
                        $rName  = (string)($recRow['recipient_name'] ?? '');
                        $rPhone = (string)($recRow['recipient_phone'] ?? '');

                        $recInsStmt = $conn->prepare(
                            "INSERT IGNORE INTO `email_campaign_recipients`
                             (campaign_id, recipient_email, recipient_name, recipient_phone, tracking_token, delivery_status)
                             VALUES (?, ?, ?, ?, ?, 'pending')"
                        );
                        $recInsStmt->bind_param('issss', $newCampaignDbId, $rEmail, $rName, $rPhone, $token);
                        if ($recInsStmt->execute() && $conn->affected_rows > 0) {
                            $inserted++;
                        } else {
                            $skipped++;
                        }
                        $recInsStmt->close();
                    }

                    campaign_recalculate_metrics($conn, $newCampaignDbId);
                    $successCampaignId = $newCampaignDbId;
                } else {
                    $insStmt->close();
                    $postError = 'Failed to create campaign: ' . $conn->error;
                }
            }
        }
    }
}

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';
?>

<div class="mx-auto px-4 py-8">
    <!-- Back button & heading -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="/admin/email/campaign-monitoring.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 transition mb-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Monitoring
            </a>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Targeted Campaign Builder</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Create a new campaign targeting a segment of an existing campaign's recipients.</p>
        </div>
    </div>

    <?php if ($successCampaignId): ?>
    <!-- Success card -->
    <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-8 shadow-sm mb-8">
        <div class="flex items-start gap-5">
            <div class="h-12 w-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div class="flex-1">
                <h3 class="text-xl font-black text-emerald-900 mb-1">Targeted Campaign Created!</h3>
                <p class="text-sm font-semibold text-emerald-700 mb-5">
                    <?= e((int)$inserted . ' recipient' . ($inserted !== 1 ? 's' : '') . ' copied') ?><?= $skipped > 0 ? ' (' . e((string)$skipped) . ' duplicate' . ($skipped !== 1 ? 's' : '') . ' skipped)' : '' ?>.
                    The campaign is in <strong>draft</strong> status — ready to edit content and send.
                </p>
                <div class="flex flex-wrap gap-3">
                    <a href="/admin/email/campaign-details.php?id=<?= $successCampaignId ?>"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-black hover:bg-emerald-500 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        View Campaign
                    </a>
                    <a href="/admin/email/campaign-content.php?id=<?= $successCampaignId ?>"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 text-white font-black hover:bg-slate-800 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Edit Content
                    </a>
                    <a href="/admin/email/campaign-monitoring.php"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-black hover:bg-slate-50 transition text-sm">
                        Back to Monitoring
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($postError !== ''): ?>
    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 flex items-center gap-3">
        <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span class="text-sm font-bold text-rose-800"><?= e($postError) ?></span>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-8">

        <!-- LEFT: Config panel (3 cols) -->
        <div class="xl:col-span-3 space-y-6">

            <!-- Step 1: Source Campaign -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <div class="mb-5">
                    <h3 class="text-lg font-black text-slate-900">Step 1 — Source Campaign</h3>
                    <p class="text-sm text-slate-500 font-semibold mt-0.5">Choose which campaign to pull recipients from.</p>
                </div>
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="copy_content" value="<?= e($copyContent ? '1' : '0') ?>">
                    <input type="hidden" name="new_campaign_name" value="<?= e($newCampaignName) ?>">
                    <input type="hidden" name="new_subject" value="<?= e($newSubject) ?>">
                    <select name="source_campaign_id" id="sourceCampaignId"
                        onchange="this.form.submit()"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 focus:border-yellow-400 focus:outline-none focus:ring-2 focus:ring-yellow-200">
                        <option value="">— Select a campaign —</option>
                        <?php foreach ($allCampaigns as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= $sourceCampaignId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e((string)$c['campaign_name']) ?>
                            (<?= e(ucfirst(str_replace('_', ' ', (string)$c['status']))) ?>
                            · <?= (int)$c['sent_count'] ?> sent)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($sourceCampaign): ?>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <?php
                    $metrics = [
                        ['Sent', (int)$sourceCampaign['sent_count'], 'text-slate-700'],
                        ['Opened', (int)$sourceCampaign['opened_count'], 'text-blue-700'],
                        ['Clicked', (int)$sourceCampaign['clicked_count'], 'text-yellow-700'],
                    ];
                    foreach ($metrics as [$label, $val, $textClass]): ?>
                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3 text-center">
                        <div class="text-2xl font-black <?= $textClass ?>"><?= $val ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-0.5"><?= $label ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($sourceCampaign): ?>
            <!-- Step 2: Segment -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <div class="mb-5">
                    <h3 class="text-lg font-black text-slate-900">Step 2 — Choose Segment</h3>
                    <p class="text-sm text-slate-500 font-semibold mt-0.5">Which recipients from the source campaign should be included?</p>
                </div>
                <form method="GET" action="" id="segmentForm">
                    <input type="hidden" name="source_campaign_id" value="<?= $sourceCampaignId ?>">
                    <input type="hidden" name="copy_content" value="<?= e($copyContent ? '1' : '0') ?>">
                    <input type="hidden" name="new_campaign_name" value="<?= e($newCampaignName) ?>">
                    <input type="hidden" name="new_subject" value="<?= e($newSubject) ?>">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                        <?php foreach ($validSegments as $seg => $label): ?>
                        <label class="flex items-center gap-3 rounded-xl border <?= $segment === $seg ? 'border-yellow-400 bg-yellow-50' : 'border-slate-200 bg-slate-50 hover:border-slate-300' ?> px-4 py-3 cursor-pointer transition">
                            <input type="radio" name="segment" value="<?= e($seg) ?>" <?= $segment === $seg ? 'checked' : '' ?>
                                onchange="document.getElementById('segmentForm').submit()"
                                class="accent-yellow-500 w-4 h-4 shrink-0">
                            <span class="text-sm font-bold text-slate-900"><?= e($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 hover:border-slate-300 px-4 py-3 cursor-pointer transition w-full">
                        <input type="checkbox" name="include_unsub" value="1"
                            <?= $includeUnsub ? 'checked' : '' ?>
                            onchange="document.getElementById('segmentForm').submit()"
                            class="accent-yellow-500 w-4 h-4 shrink-0">
                        <span class="text-sm font-bold text-slate-700">Include unsubscribed recipients</span>
                        <span class="ml-auto text-xs text-slate-400 font-semibold">Off by default</span>
                    </label>
                </form>
            </div>

            <!-- Step 3: Campaign Details -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <div class="mb-5">
                    <h3 class="text-lg font-black text-slate-900">Step 3 — New Campaign Details</h3>
                    <p class="text-sm text-slate-500 font-semibold mt-0.5">Name and subject for the targeted campaign you're creating.</p>
                </div>
                <form method="POST" action="" id="createForm">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="source_campaign_id" value="<?= $sourceCampaignId ?>">
                    <input type="hidden" name="segment" value="<?= e($segment) ?>">
                    <input type="hidden" name="include_unsub" value="<?= $includeUnsub ? '1' : '0' ?>">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-1.5" for="newCampaignName">Campaign Name <span class="text-rose-500">*</span></label>
                            <input type="text" id="newCampaignName" name="new_campaign_name"
                                value="<?= e($newCampaignName ?: ('Targeted — ' . (string)($sourceCampaign['campaign_name'] ?? ''))) ?>"
                                maxlength="255" required
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 focus:border-yellow-400 focus:outline-none focus:ring-2 focus:ring-yellow-200"
                                placeholder="e.g. Re-engagement — Clicked Segment">
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-1.5" for="newSubject">Subject Line <span class="text-rose-500">*</span></label>
                            <input type="text" id="newSubject" name="new_subject"
                                value="<?= e($newSubject ?: (string)($sourceCampaign['subject'] ?? '')) ?>"
                                maxlength="255" required
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 focus:border-yellow-400 focus:outline-none focus:ring-2 focus:ring-yellow-200"
                                placeholder="Subject line for this targeted send">
                        </div>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 hover:border-slate-300 px-4 py-3 cursor-pointer transition">
                            <input type="checkbox" name="copy_content" value="1" <?= $copyContent ? 'checked' : '' ?> class="accent-yellow-500 w-4 h-4 shrink-0">
                            <div>
                                <span class="text-sm font-bold text-slate-900">Copy email content from source campaign</span>
                                <p class="text-xs text-slate-500 mt-0.5">Body, button, closing, footer, brand settings — all copied. You can edit after creation.</p>
                            </div>
                        </label>
                    </div>

                    <div class="mt-6">
                        <?php if ($previewCount === 0): ?>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 mb-4 flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span class="text-xs font-bold text-amber-800">No recipients match this segment. Change your segment selection.</span>
                        </div>
                        <button type="submit" disabled
                            class="w-full rounded-xl bg-slate-200 text-slate-400 font-black py-3 px-6 text-sm cursor-not-allowed">
                            No Recipients — Cannot Create
                        </button>
                        <?php else: ?>
                        <button type="submit"
                            class="w-full rounded-xl bg-yellow-500 text-white font-black py-3 px-6 text-sm hover:bg-yellow-400 transition shadow-sm">
                            Create Targeted Campaign (<?= number_format($previewCount) ?> recipient<?= $previewCount !== 1 ? 's' : '' ?>)
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Preview panel (2 cols) -->
        <div class="xl:col-span-2 space-y-6">
            <?php if ($sourceCampaign): ?>
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm sticky top-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-black text-slate-900">Recipient Preview</h3>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-yellow-100 text-yellow-800 text-xs font-black">
                        <?= number_format($previewCount) ?> matched
                    </span>
                </div>

                <div class="mb-3 px-3 py-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-0.5">Segment</div>
                    <div class="text-sm font-bold text-slate-900"><?= e($validSegments[$segment]) ?></div>
                </div>

                <?php if ($previewCount === 0): ?>
                <div class="py-10 text-center">
                    <svg class="w-10 h-10 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
                    </svg>
                    <p class="text-sm font-bold text-slate-400">No recipients in this segment.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left px-3 py-2 font-black text-slate-500 uppercase tracking-widest">Email</th>
                                <th class="text-center px-2 py-2 font-black text-slate-500 uppercase tracking-widest">O</th>
                                <th class="text-center px-2 py-2 font-black text-slate-500 uppercase tracking-widest">C</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($sampleRows as $sr): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2 font-semibold text-slate-700 truncate max-w-[160px]">
                                    <?php if ($sr['recipient_name'] !== '' && $sr['recipient_name'] !== null): ?>
                                    <div class="text-[10px] text-slate-400"><?= e((string)$sr['recipient_name']) ?></div>
                                    <?php endif; ?>
                                    <?= e((string)$sr['recipient_email']) ?>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <?= $sr['opened'] ? '<span class="text-blue-500 font-black">✓</span>' : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <?= $sr['clicked'] ? '<span class="text-yellow-500 font-black">✓</span>' : '<span class="text-slate-300">—</span>' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($previewCount > 20): ?>
                <p class="mt-2 text-xs font-semibold text-slate-400 text-center">Showing 20 of <?= number_format($previewCount) ?> recipients</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 p-10 text-center">
                <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <p class="text-sm font-bold text-slate-400">Select a source campaign to preview recipients.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
