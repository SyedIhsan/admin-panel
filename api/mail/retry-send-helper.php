<?php
declare(strict_types=1);

require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/api/ses-config.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/api/mail/templates/payment-confirmed.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/api/mail/templates/custom-product.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/api/mail/templates/elearning-access.php';

function send_email_from_log_payload(array $payload): array {
  $templateName = trim((string)($payload['template_name'] ?? ''));
  $email = strtolower(trim((string)($payload['recipient_email'] ?? '')));
  $name = trim((string)($payload['customer_name'] ?? 'Customer'));

  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return ['ok' => false, 'error' => 'Invalid recipient email.'];
  }

  if ($templateName === '') {
    return ['ok' => false, 'error' => 'Missing template_name in payload.'];
  }

  if ($templateName === 'payment_confirmed') {
    $subject = trim((string)($payload['subject'] ?? 'Payment Confirmed'));
    $html = buildPaymentConfirmedEmail([
      'name'            => (string)($payload['customer_name'] ?? 'Customer'),
      'order_id'        => (string)($payload['order_id'] ?? ''),
      'transaction_ref' => (string)($payload['transaction_ref'] ?? ''),
      'price'           => (string)($payload['price'] ?? '0.00'),
      'product_name'    => (string)($payload['product_name'] ?? ''),
      'package'         => (string)($payload['package'] ?? ''),
      'date_label'      => (string)($payload['date_label'] ?? date('d M Y, h:i A')),
    ]);

    $ok = sendBrevo($email, $name, $subject, $html);
    return [
      'ok' => $ok,
      'message_id' => '',
      'error' => $ok ? '' : 'Brevo send failed for payment_confirmed.',
    ];
  }

  if ($templateName === 'custom_product') {
    $subject = trim((string)($payload['subject'] ?? 'Your Product Update'));

    $html = buildCustomProductEmail([
      'subject'               => $subject,
      'preheader'             => (string)($payload['preheader'] ?? ''),
      'badge_text'            => (string)($payload['badge_text'] ?? ''),
      'name'                  => (string)($payload['customer_name'] ?? 'Customer'),
      'greeting_html'         => (string)($payload['greeting_html'] ?? ''),
      'content_html'          => (string)($payload['content_html'] ?? ''),
      'selected_product_html' => (string)($payload['selected_product_html'] ?? ''),
      'media_html'            => (string)($payload['media_html'] ?? ''),
      'button_html'           => (string)($payload['button_html'] ?? ''),
      'closing_html'          => (string)($payload['closing_html'] ?? ''),
      'brand_name'            => (string)($payload['brand_name'] ?? 'Demo Company'),
      'brand_email'           => (string)($payload['brand_email'] ?? 'noreply@demo.local'),
      'support_email'         => (string)($payload['support_email'] ?? 'support@demo.local'),
      'footer_note'           => (string)($payload['footer_note'] ?? ''),
    ]);

    $ok = sendBrevo($email, $name, $subject, $html);
    return [
      'ok' => $ok,
      'message_id' => '',
      'error' => $ok ? '' : 'Brevo send failed for custom_product.',
    ];
  }

  if ($templateName === 'elearning_access') {
    $subject = trim((string)($payload['subject'] ?? 'Payment Successful - e-Learning Access'));
    $html = buildElearningAccessEmail([
      'name'            => (string)($payload['customer_name'] ?? 'Customer'),
      'course_label'    => (string)($payload['course_label'] ?? ''),
      'purchased_label' => (string)($payload['purchased_label'] ?? ''),
      'email'           => $email,
      'order_id'        => (string)($payload['order_id'] ?? ''),
      'transaction_ref' => (string)($payload['transaction_ref'] ?? ''),
      'price'           => (string)($payload['price'] ?? '0.00'),
      'login_url'       => (string)($payload['login_url'] ?? ''),
      'forgot_url'      => (string)($payload['forgot_url'] ?? ''),
      'temp_password'   => (string)($payload['temp_password'] ?? ''),
    ]);

    $ok = sendBrevo($email, $name, $subject, $html);
    return [
      'ok' => $ok,
      'message_id' => '',
      'error' => $ok ? '' : 'Brevo send failed for elearning_access.',
    ];
  }

  return ['ok' => false, 'error' => 'Unsupported template_name: ' . $templateName];
}