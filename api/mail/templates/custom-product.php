<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

if (!function_exists('buildCustomProductEmail')) {
  function buildCustomProductEmail(array $data): string {
    $subject = (string)($data['subject'] ?? 'Your Product Update');
    $preheader = (string)($data['preheader'] ?? '');
    $badgeText = (string)($data['badge_text'] ?? 'Customer Update');

    $brandName = (string)($data['brand_name'] ?? 'Demo Company');
    $brandEmail = (string)($data['brand_email'] ?? 'noreply@demo.local');
    $supportEmail = (string)($data['support_email'] ?? 'support@demo.local');
    $recipientEmail = trim((string)($data['recipient_email'] ?? $data['email'] ?? $data['customer_email'] ?? $data['to_email'] ?? ''));

    $greetingHtml = (string)($data['greeting_html'] ?? '');
    $contentHtml = (string)($data['content_html'] ?? '');
    $selectedProductHtml = (string)($data['selected_product_html'] ?? '');
    $mediaHtml = (string)($data['media_html'] ?? '');
    $buttonHtml = (string)($data['button_html'] ?? '');
    $closingHtml = (string)($data['closing_html'] ?? '');
    $footerNote = (string)($data['footer_note'] ?? '');

    $bodyHtml = '
      <div style="margin:0 0 18px 0;">' . $greetingHtml . '</div>
      <div style="margin:0 0 20px 0;">' . $contentHtml . '</div>
      ' . $selectedProductHtml . '
      ' . $mediaHtml . '
      ' . $buttonHtml . '
      <div style="margin:20px 0 0 0;">' . $closingHtml . '</div>
    ';

    $footerNoteHtml = $footerNote !== ''
      ? '<div style="font-size:12px;line-height:1.6;color:#71717a;">' . $footerNote . '</div>'
      : '';

    return buildMailLayout([
      'subject'          => $subject,
      'preheader'        => $preheader,
      'body_html'        => $bodyHtml,
      'recipient_email'  => $recipientEmail,
      'brand_name'       => $brandName,
      'brand_email'      => $brandEmail,
      'support_email'    => $supportEmail,
      'badge_text'       => $badgeText,
      'footer_note_html' => $footerNoteHtml,
    ]);
  }
}