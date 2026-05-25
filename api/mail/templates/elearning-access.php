<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

if (!function_exists('buildElearningAccessEmail')) {
  function buildElearningAccessEmail(array $data): string {
    $name            = (string)($data['name'] ?? 'Customer');
    $courseLabel     = (string)($data['course_label'] ?? 'Course');
    $purchasedLabel  = (string)($data['purchased_label'] ?? '');
    $email           = (string)($data['email'] ?? '');
    $orderId         = (string)($data['order_id'] ?? '');
    $txnRef          = (string)($data['transaction_ref'] ?? '');
    $price           = (string)($data['price'] ?? '0.00');
    $discountCode    = (string)($data['discount_code'] ?? '');
    $loginUrl        = (string)($data['login_url'] ?? '');
    $forgotUrl       = (string)($data['forgot_url'] ?? '');
    $tempPassword    = (string)($data['temp_password'] ?? '');

    $subject   = 'Payment Successful - e-Learning Access (' . $courseLabel . ')';
    $preheader = 'Your payment was successful and your e-Learning access is ready.';

    $bodyHtml = '
      <div style="font-size:34px;line-height:1.10;font-weight:800;letter-spacing:-0.6px;margin:0 0 14px 0;color:#ffffff;">
        Payment Successful,<br>
        <span style="color:#fbbf24;">' . mail_h($name) . '</span>
      </div>

      <div style="font-size:16px;line-height:1.75;color:#a1a1aa;margin:0 0 28px 0;max-width:620px;">
        Your e-Learning order has been successfully processed.
      </div>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
        style="background:linear-gradient(135deg, rgba(255,255,255,.03), rgba(255,255,255,0));
        background-color:#0b0b0e;border:1px solid rgba(39,39,42,1);border-radius:18px;overflow:hidden;box-shadow:0 18px 38px rgba(0,0,0,.55);">
        <tr>
          <td style="padding:26px 26px;">
            <div style="font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#f59e0b;margin:0 0 14px 0;">
              Access Granted
            </div>

            <div style="font-size:24px;font-weight:900;letter-spacing:-0.3px;color:#ffffff;margin:0 0 10px 0;">
              ' . mail_h($courseLabel) . '
            </div>

            <div style="font-size:14px;line-height:1.8;color:#a1a1aa;">
              <strong style="color:#ffffff;">Purchased Product:</strong> ' . mail_h($purchasedLabel) . '<br>
              <strong style="color:#ffffff;">Login Email:</strong> ' . mail_h($email) . '<br>
              <strong style="color:#ffffff;">Order ID:</strong> ' . mail_h($orderId) . '<br>
              <strong style="color:#ffffff;">Transaction Ref:</strong> ' . mail_h($txnRef) . '<br>' .
              ($discountCode !== '' ? '
              <strong style="color:#ffffff;">Coupon used:</strong> ' . mail_h($discountCode) . '<br>' : '') . '
              <strong style="color:#ffffff;">Amount:</strong> RM ' . mail_h($price) . '
            </div>' .

            ($tempPassword !== '' ? '
            <div style="margin-top:18px;font-size:14px;line-height:1.8;color:#a1a1aa;">
              <strong style="color:#ffffff;">Temporary Password:</strong> ' . mail_h($tempPassword) . '<br>
              <span style="color:#fbbf24;">You must change your password on first login.</span>
            </div>' : '') . '

            <div style="margin-top:22px;font-size:14px;line-height:1.8;color:#a1a1aa;">
              <strong style="color:#ffffff;">Login:</strong>
              <a href="' . mail_h($loginUrl) . '" style="color:#fbbf24;text-decoration:none;">' . mail_h($loginUrl) . '</a><br>
              <strong style="color:#ffffff;">Forgot Password:</strong>
              <a href="' . mail_h($forgotUrl) . '" style="color:#fbbf24;text-decoration:none;">' . mail_h($forgotUrl) . '</a>
            </div>
          </td>
        </tr>
      </table>
    ';

    return buildMailLayout([
      'subject'     => $subject,
      'preheader'   => $preheader,
      'body_html'   => $bodyHtml,
      'recipient_email' => $email,
      'brand_name'  => 'Demo Company',
      'brand_email' => 'support@demo.local',
      'year'        => date('Y'),
      'badge_text'  => 'Access Granted',
    ]);
  }
}