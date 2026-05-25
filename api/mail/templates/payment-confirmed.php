<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

if (!function_exists('buildPaymentConfirmedEmail')) {
  function buildPaymentConfirmedEmail(array $data): string {
    $name      = (string)($data['name'] ?? 'Customer');
    $orderId   = (string)($data['order_id'] ?? '');
    $txnRef    = (string)($data['transaction_ref'] ?? '');
    $price     = (string)($data['price'] ?? '0.00');
    $originalPrice = (string)($data['original_price'] ?? '');
    $discountAmount = (string)($data['discount_amount'] ?? '');
    $discountCode = (string)($data['discount_code'] ?? '');
    $product   = (string)($data['product_name'] ?? '');
    $package   = (string)($data['package'] ?? '');
    $dateLabel = (string)($data['date_label'] ?? date('d M Y, h:i A'));
    $recipientEmail = trim((string)($data['recipient_email'] ?? $data['email'] ?? $data['customer_email'] ?? ''));

    $fullProduct = $product;
    if ($package !== '') {
      $fullProduct .= ' (' . $package . ')';
    }

    $subject = 'Payment Confirmed - ' . ($product !== '' ? $product : 'Demo Company');
    $preheader = 'Your payment has been successfully processed.';

    $bodyHtml = '
      <div style="font-size:38px;line-height:1.10;font-weight:800;letter-spacing:-0.6px;margin:0 0 14px 0;color:#ffffff;">
        Payment Confirmed,<br>
        <span style="color:#fbbf24;">' . mail_h($name) . '</span>
      </div>

      <div style="font-size:16px;line-height:1.75;color:#a1a1aa;margin:0 0 28px 0;max-width:620px;">
        Your payment has been successfully processed. Thank you for your purchase.
      </div>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
        style="background:linear-gradient(135deg, rgba(255,255,255,.03), rgba(255,255,255,0));
        background-color:#0b0b0e;border:1px solid rgba(39,39,42,1);border-radius:18px;overflow:hidden;box-shadow:0 18px 38px rgba(0,0,0,.55);">
        <tr>
          <td style="padding:26px 26px;">
            <div style="font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#f59e0b;margin:0 0 14px 0;">
              Payment Receipt
            </div>

            <div style="font-size:24px;font-weight:900;letter-spacing:-0.3px;color:#ffffff;margin:0 0 10px 0;">
              ' . mail_h($fullProduct !== '' ? $fullProduct : 'Order Completed') . '
            </div>

            <div style="font-size:14px;line-height:1.8;color:#a1a1aa;">
              <strong style="color:#ffffff;">Order ID:</strong> ' . mail_h($orderId) . '<br>
              <strong style="color:#ffffff;">Transaction Ref:</strong> ' . mail_h($txnRef) . '<br>' .
              ($discountCode !== '' ? '
              <strong style="color:#ffffff;">Coupon used:</strong> ' . mail_h($discountCode) . '<br>' : '') .
              ($originalPrice !== '' ? '
              <strong style="color:#ffffff;">Original Price:</strong> RM ' . mail_h($originalPrice) . '<br>' : '') .
              ($discountAmount !== '' ? '
              <strong style="color:#ffffff;">Discount Deduction:</strong> -RM ' . mail_h($discountAmount) . '<br>' : '') . '
              <strong style="color:#ffffff;">Amount Paid:</strong> RM ' . mail_h($price) . '<br>
              <strong style="color:#ffffff;">Date:</strong> ' . mail_h($dateLabel) . '
            </div>
          </td>
        </tr>
      </table>
    ';

    return buildMailLayout([
      'subject'     => $subject,
      'preheader'   => $preheader,
      'body_html'   => $bodyHtml,
      'recipient_email' => $recipientEmail,
      'brand_name'  => 'Demo Company',
      'brand_email' => 'support@demo.local',
      'year'        => date('Y'),
      'badge_text'  => 'Payment Confirmed',
    ]);
  }
}
