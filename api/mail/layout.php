<?php

declare(strict_types=1);

if (!function_exists('mail_h')) {
  function mail_h(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('mail_first_name')) {
  function mail_first_name(string $fullName): string
  {
    $displayName = trim($fullName);
    if ($displayName === '') return 'Customer';

    if (function_exists('mb_strtolower') && function_exists('mb_strpos') && function_exists('mb_substr')) {
      $lower = mb_strtolower($displayName);
      $posBin = mb_strpos($lower, ' bin ');
      $posBinti = mb_strpos($lower, ' binti ');

      $cutPos = null;
      if ($posBin !== false) $cutPos = $posBin;
      if ($posBinti !== false) $cutPos = ($cutPos === null) ? $posBinti : min($cutPos, $posBinti);

      if ($cutPos !== null) {
        $displayName = trim((string)mb_substr($displayName, 0, $cutPos));
      }
    }

    if ($displayName === '') {
      $displayName = trim($fullName);
    }

    if ($displayName !== '' && strpos($displayName, ' ') !== false) {
      $parts = preg_split('/\s+/', $displayName);
      if (is_array($parts) && !empty($parts[0])) {
        $displayName = trim((string)$parts[0]);
      }
    }

    return $displayName !== '' ? $displayName : 'Customer';
  }
}

if (!function_exists('buildMailLayout')) {
  /**
   * Generic reusable email layout.
   *
   * Supported keys in $data:
   * - subject
   * - preheader
   * - body_html (required)
   * - brand_name
   * - brand_email
   * - year
   * - badge_text
   * - support_email
   * - footer_note_html
   *
   * Theme override keys (optional):
   * - bg
   * - card
   * - card2
   * - hero_bg
   * - border
   * - border2
   * - text
   * - muted
   * - muted2
   * - muted3
   * - accent
   * - accent2
   */
  function buildMailLayout(array $data): string
  {
    $subject        = (string)($data['subject'] ?? 'Notification');
    $preheader      = (string)($data['preheader'] ?? '');
    $bodyHtml       = (string)($data['body_html'] ?? '');
    $brandName      = (string)($data['brand_name'] ?? 'Demo Company');
    $brandEmail     = (string)($data['brand_email'] ?? 'noreply@demo.local');

    $supportEmail   = (string)($data['support_email'] ?? 'support@demo.local');
    $logoUrl        = '/img/demo_logo.svg';
    $year           = (string)($data['year'] ?? date('Y'));
    $badgeText      = trim((string)($data['badge_text'] ?? 'Notification'));
    $privacyUrl     = '/privacy.php';
    $footerNoteHtml = (string)($data['footer_note_html'] ?? '');

    $recipientEmail = trim((string)($data['recipient_email'] ?? $data['email'] ?? ''));
    $emailQuery = $recipientEmail !== '' ? '?email=' . rawurlencode($recipientEmail) : '';

    $unsubscribeUrl = (string)($data['unsubscribe_url'] ?? ('#demo-unsubscribe' . $emailQuery));
    $managePreferencesUrl = (string)($data['manage_preferences_url'] ?? ('#demo-preferences' . $emailQuery));

    $trackingToken = (string)($data['tracking_token'] ?? '');

    // Theme
    $bg      = (string)($data['bg'] ?? '#09090b');
    $card    = (string)($data['card'] ?? '#18181b');
    $card2   = (string)($data['card2'] ?? '#111113');
    $heroBg  = (string)($data['hero_bg'] ?? '#0b0b0e');
    $border  = (string)($data['border'] ?? 'rgba(255,255,255,.06)');
    $border2 = (string)($data['border2'] ?? 'rgba(255,255,255,.10)');
    $text    = (string)($data['text'] ?? '#ffffff');
    $muted   = (string)($data['muted'] ?? '#a1a1aa');
    $muted2  = (string)($data['muted2'] ?? '#71717a');
    $muted3  = (string)($data['muted3'] ?? '#52525b');
    $accent  = (string)($data['accent'] ?? '#f59e0b');
    $accent2 = (string)($data['accent2'] ?? '#fbbf24');

    $badgeHtml = '';
    if ($badgeText !== '') {
      $badgeHtml = '
        <td valign="middle" align="right">
          <div class="mail-badge" style="display:inline-block;
                      color:' . mail_h($text) . ';
                      background:rgba(255,255,255,.08);
                      padding:8px 12px;border-radius:999px;
                      border:1px solid rgba(255,255,255,.12);">
            ' . mail_h($badgeText) . '
          </div>
        </td>';
    }

    $footerNoteBlock = '';
    if (trim($footerNoteHtml) !== '') {
      $footerNoteBlock = '
        <div class="mail-small" style="color:' . mail_h($muted3) . ';margin-bottom:14px;">
          ' . $footerNoteHtml . '
        </div>';
    } else {
      $footerNoteBlock = '
        <div class="mail-small" style="color:' . mail_h($muted3) . ';margin-bottom:14px;">
          If you have any questions, contact
          <a href="mailto:' . mail_h($supportEmail) . '" style="color:' . mail_h($muted) . ';text-decoration:underline;text-underline-offset:2px;">' . mail_h($supportEmail) . '</a>.
        </div>';
    }

    $privacyBlock = '';
    if ($privacyUrl !== '') {
      $privacyBlock = '
        <span style="display:inline-block;width:1px;height:10px;background:rgba(255,255,255,.12);margin:0 10px;vertical-align:middle;"></span>
        <a href="' . mail_h($privacyUrl) . '" style="color:' . mail_h($muted3) . ';text-decoration:none;">Privacy Policy</a>';
    }

    $html = '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . mail_h($subject) . '</title>
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap");

    body, table, td, a {
      -webkit-text-size-adjust: 100%;
      -ms-text-size-adjust: 100%;
    }

    body {
      margin: 0;
      padding: 0;
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif;
    }

    .mail-brand {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 14px !important;
      font-weight: 800 !important;
      letter-spacing: 1px !important;
      text-transform: uppercase !important;
      line-height: 1.25 !important;
    }

    .mail-brand-email {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 12px !important;
      line-height: 1.45 !important;
    }

    .mail-badge {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 11px !important;
      font-weight: 700 !important;
      line-height: 1.2 !important;
    }

    .mail-body {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 17px !important;
      line-height: 1.72 !important;
    }

    .mail-body h1 {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      margin: 0 0 16px !important;
      font-size: 40px !important;
      line-height: 1.12 !important;
      letter-spacing: -0.02em !important;
      font-weight: 800 !important;
    }

    .mail-body h2 {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      margin: 0 0 12px !important;
      font-size: 26px !important;
      line-height: 1.22 !important;
      font-weight: 800 !important;
    }

    .mail-body h3 {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      margin: 0 0 10px !important;
      font-size: 19px !important;
      line-height: 1.30 !important;
      font-weight: 800 !important;
    }

    .mail-body p,
    .mail-body li,
    .mail-body div {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 17px !important;
      line-height: 1.72 !important;
    }

    .mail-body strong,
    .mail-body b {
      font-weight: 700 !important;
    }

    .mail-meta {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 15px !important;
      line-height: 1.68 !important;
    }

    .mail-small {
      font-family: Inter, Roboto, Arial, Helvetica, sans-serif !important;
      font-size: 12px !important;
      line-height: 1.6 !important;
    }

    @media only screen and (max-width: 640px) {
      .mail-brand {
        font-size: 12px !important;
        letter-spacing: 0.8px !important;
        line-height: 1.2 !important;
      }

      .mail-brand-email {
        font-size: 10px !important;
        line-height: 1.4 !important;
      }

      .mail-badge {
        font-size: 10px !important;
        line-height: 1.15 !important;
      }

      .mail-body {
        font-size: 15px !important;
        line-height: 1.68 !important;
      }

      .mail-body h1 {
        margin: 0 0 14px !important;
        font-size: 32px !important;
        line-height: 1.14 !important;
        letter-spacing: -0.015em !important;
      }

      .mail-body h2 {
        margin: 0 0 10px !important;
        font-size: 21px !important;
        line-height: 1.24 !important;
      }

      .mail-body h3 {
        margin: 0 0 8px !important;
        font-size: 17px !important;
        line-height: 1.28 !important;
      }

      .mail-body p,
      .mail-body li,
      .mail-body div {
        font-size: 15px !important;
        line-height: 1.66 !important;
      }

      .mail-meta {
        font-size: 13px !important;
        line-height: 1.6 !important;
      }

      .mail-small {
        font-size: 11px !important;
        line-height: 1.55 !important;
      }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:' . mail_h($bg) . ';color:' . mail_h($text) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . mail_h($preheader) . '</div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mail_h($bg) . ';padding:18px 10px;">
    <tr>
      <td align="center">

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
          style="max-width:680px;border-radius:22px;overflow:hidden;border:1px solid ' . mail_h($border) . ';
                 background:' . mail_h($card) . ';
                 box-shadow:0 28px 70px rgba(0,0,0,.60);">

          <tr>
            <td style="height:8px;background:linear-gradient(90deg,' . mail_h($accent) . ', ' . mail_h($accent2) . ', ' . mail_h($accent) . ');
                      background-color:' . mail_h($accent) . ';"></td>
          </tr>

          <tr>
            <td style="padding:26px 28px;
                      background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,0));
                      border-bottom:1px solid ' . mail_h($border) . ';">

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td valign="middle">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                      <tr>
                        <td valign="middle" style="padding-right:12px;">
                          <div style="width:44px;height:44px;display:block;">
                            <img src="' . mail_h($logoUrl) . '" width="44" height="44" alt="' . mail_h($brandName) . '"
                                style="display:block;width:44px;height:44px;object-fit:contain;">
                          </div>
                        </td>

                        <td valign="middle">
                          <div class="mail-brand" style="color:' . mail_h($text) . ';">
                            ' . mail_h($brandName) . '
                          </div>
                          <div class="mail-brand-email" style="color:' . mail_h($muted2) . ';
                                      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
                                      margin-top:4px;">
                            ' . mail_h($brandEmail) . '
                          </div>
                        </td>
                      </tr>
                    </table>
                  </td>

                  ' . $badgeHtml . '
                </tr>
              </table>

            </td>
          </tr>

          <tr>
            <td style="padding:34px 28px 26px 28px;
                      background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,0));">
              <div class="mail-body">
                ' . $bodyHtml . '
              </div>
            </td>
          </tr>

          <tr>
            <td style="background:' . mail_h($bg) . ';padding:22px 28px;border-top:1px solid ' . mail_h($border) . ';text-align:center;">
              ' . $footerNoteBlock . '

              <div class="mail-small" style="color:' . mail_h($muted) . ';margin-bottom:12px;">
                <a href="' . mail_h($unsubscribeUrl) . '" style="color:' . mail_h($muted) . ';text-decoration:underline;text-underline-offset:2px;">Unsubscribe</a>
                <span style="display:inline-block;width:1px;height:10px;background:rgba(255,255,255,.12);margin:0 10px;vertical-align:middle;"></span>
                <a href="' . mail_h($managePreferencesUrl) . '" style="color:' . mail_h($muted) . ';text-decoration:underline;text-underline-offset:2px;">Manage preferences</a>
              </div>

              <div class="mail-small" style="color:' . mail_h($muted3) . ';">
                © ' . mail_h($year) . ' ' . mail_h($brandName) . '
                ' . $privacyBlock . '
              </div>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>
</body>
</html>';

    if ($trackingToken !== '' && function_exists('campaign_apply_tracking_to_html')) {
      $html = campaign_apply_tracking_to_html($html, $trackingToken);
    }

    return $html;
  }
}
