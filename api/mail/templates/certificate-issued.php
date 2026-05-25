<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

if (!function_exists('buildCertificateIssuedEmail')) {
  function buildCertificateIssuedEmail(array $data): string
  {
    $subject      = (string)($data['subject'] ?? 'Certificate Issued');
    $toName       = (string)($data['to_name'] ?? 'Student');
    $courseTitle  = trim((string)($data['course_title'] ?? ''));
    $certNo       = trim((string)($data['cert_no'] ?? ''));
    $issuedLabel  = trim((string)($data['issued_label'] ?? ''));
    $preheader    = (string)($data['preheader'] ?? '');
    $brandName    = (string)($data['brand_name'] ?? 'Demo E-Learning');
    $brandEmail   = (string)($data['brand_email'] ?? 'noreply@demo.local');
    $supportEmail = (string)($data['support_email'] ?? 'support@demo.local');
    $logoUrl      = (string)($data['logo_url'] ?? '/img/demo_logo.svg');
    $privacyUrl   = (string)($data['privacy_url'] ?? '#demo-privacy');
    $year         = (string)($data['year'] ?? date('Y'));
    $recipientEmail = trim((string)($data['recipient_email'] ?? $data['email'] ?? $data['to_email'] ?? ''));

    $displayName = mail_first_name($toName);

    $bodyHtml = '
      <div style="font-size:38px;line-height:1.10;font-weight:800;letter-spacing:-0.6px;margin:0 0 14px 0;color:#ffffff;">
        Congratulations,<br>
        <span style="color:#fbbf24;">' . mail_h($displayName) . '!</span>
      </div>

      <div style="font-size:16px;line-height:1.75;color:#a1a1aa;margin:0 0 28px 0;max-width:620px;">
        You have successfully completed the
        <strong style="color:#ffffff;font-weight:700;">' . mail_h($courseTitle) . '</strong>
        course. This achievement marks a significant step in your blockchain journey.
      </div>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
        style="background:radial-gradient(120% 140% at 85% 20%, rgba(245,158,11,.12), rgba(0,0,0,0) 55%),
                      linear-gradient(135deg, rgba(255,255,255,.03), rgba(255,255,255,0));
               background-color:#0b0b0e;
               border:1px solid rgba(39,39,42,1);
               border-radius:18px;overflow:hidden;
               box-shadow:0 18px 38px rgba(0,0,0,.55);">
        <tr>
          <td style="padding:26px 26px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td valign="top" style="padding-right:14px;">
                  <div style="font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#f59e0b;margin:0 0 14px 0;">
                    <span style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;
                                 border-radius:999px;border:1px solid rgba(245,158,11,.35);
                                 margin-right:8px;font-size:11px;">✓</span>
                    Verified Certificate
                  </div>

                  <div style="font-size:26px;font-weight:900;letter-spacing:-0.3px;color:#ffffff;margin:0 0 8px 0;">
                    ' . mail_h($courseTitle) . '
                  </div>

                  <div style="font-size:14px;line-height:1.6;color:#71717a;">
                    Mastering the fundamentals of blockchain.
                  </div>
                </td>

                <td valign="top" align="right" style="width:240px;">
                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                    style="background:rgba(255,255,255,.03);
                           border:1px solid rgba(39,39,42,1);
                           border-radius:14px;overflow:hidden;">
                    <tr>
                      <td style="padding:16px 16px;">
                        <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#71717a;
                                    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
                                    margin:0 0 8px 0;">
                          Certificate No
                        </div>
                        <div style="font-size:14px;font-weight:900;color:#ffffff;
                                    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
                                    letter-spacing:1px;margin:0 0 16px 0;">
                          ' . mail_h($certNo) . '
                        </div>

                        <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#71717a;
                                    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
                                    margin:0 0 8px 0;">
                          Issued On
                        </div>
                        <div style="font-size:14px;font-weight:900;color:#ffffff;
                                    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;">
                          ' . mail_h($issuedLabel) . '
                        </div>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <div style="margin-top:26px;padding-top:22px;border-top:1px solid rgba(255,255,255,.06);">
        <div style="font-size:13px;color:#71717a;text-align:center;font-style:italic;">
          “Keep learning and keep building momentum — you\'re doing great.”
        </div>
      </div>
    ';

    return buildMailLayout([
      'subject'       => $subject,
      'preheader'     => $preheader,
      'body_html'     => $bodyHtml,
      'recipient_email' => $recipientEmail,
      'brand_name'    => $brandName,
      'brand_email'   => $brandEmail,
      'support_email' => $supportEmail,
      'logo_url'      => $logoUrl,
      'privacy_url'   => $privacyUrl,
      'year'          => $year,
      'badge_text'    => 'Certificate Issued',
      'footer_note_html' => 'If you have any questions, contact
        <a href="mailto:' . mail_h($supportEmail) . '" style="color:#a1a1aa;text-decoration:underline;text-underline-offset:2px;">' . mail_h($supportEmail) . '</a>.',
    ]);
  }
}