<?php
declare(strict_types=1);

/**
 * DEMO STUB — Payment Gateways (SenangPay + Stripe)
 *
 * Replaces real gateway integrations for the portfolio demo. All "charges"
 * succeed without contacting any real API. Use this in any file that originally
 * imported SenangPay or Stripe SDKs.
 *
 * Drop in at: api/payment_gateway_stub.php (or wherever your gateway lib lived)
 * Then: require_once __DIR__ . '/payment_gateway_stub.php';
 */

// -----------------------------------------------------------------------------
// SenangPay stubs
// -----------------------------------------------------------------------------

function senangpay_create_payment_url(array $params): string {
    // In production: builds signed URL to SenangPay checkout
    // In demo: returns a local "fake gateway" page that auto-redirects back
    $orderId = $params['order_id'] ?? 'demo_order_' . bin2hex(random_bytes(4));
    $amount = $params['amount'] ?? '0.00';
    return "/demo-gateway.php?gateway=senangpay&order_id={$orderId}&amount={$amount}";
}

function senangpay_verify_callback(array $postData): array {
    return [
        'valid' => true,
        'order_id' => $postData['order_id'] ?? 'demo_order',
        'transaction_id' => 'sp_demo_' . bin2hex(random_bytes(6)),
        'status' => 'success',
        'amount' => $postData['amount'] ?? '0.00',
        'paid_at' => date('Y-m-d H:i:s'),
    ];
}

function senangpay_charge_recurring(string $tokenizedCard, float $amount, string $orderId): array {
    return [
        'success' => true,
        'transaction_id' => 'sp_demo_rec_' . bin2hex(random_bytes(6)),
        'order_id' => $orderId,
        'amount' => $amount,
        'charged_at' => date('Y-m-d H:i:s'),
        'message' => '[DEMO] No real charge — stub returned success',
    ];
}

// -----------------------------------------------------------------------------
// Stripe stubs
// -----------------------------------------------------------------------------

function stripe_create_checkout_session(array $params): array {
    $sessionId = 'cs_demo_' . bin2hex(random_bytes(12));
    return [
        'id' => $sessionId,
        'url' => "/demo-gateway.php?gateway=stripe&session_id={$sessionId}&amount=" . ($params['amount'] ?? 0),
        'amount_total' => $params['amount'] ?? 0,
    ];
}

function stripe_retrieve_session(string $sessionId): array {
    return [
        'id' => $sessionId,
        'payment_status' => 'paid',
        'amount_total' => 9900,
        'currency' => 'myr',
        'customer_email' => 'demo-customer@example.test',
    ];
}

function stripe_verify_webhook(string $payload, string $sigHeader): bool {
    return true; // demo: always accept
}

function stripe_create_subscription(array $params): array {
    return [
        'id' => 'sub_demo_' . bin2hex(random_bytes(8)),
        'status' => 'active',
        'current_period_end' => time() + (30 * 86400),
    ];
}

function stripe_cancel_subscription(string $subscriptionId): bool {
    return true;
}

// -----------------------------------------------------------------------------
// Generic helper: a single "fake gateway" landing page contents.
// Save this as /demo-gateway.php at your web root so the redirect URLs above
// have somewhere to land.
// -----------------------------------------------------------------------------

function demo_gateway_landing_html(string $orderId, string $returnUrl): string {
    return <<<HTML
<!doctype html>
<html><head><title>Demo Payment Gateway</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
<div class="bg-white rounded-xl shadow-lg p-8 max-w-md w-full">
  <div class="text-center mb-6">
    <div class="text-5xl mb-2">🎭</div>
    <h1 class="text-2xl font-bold">Demo Payment Gateway</h1>
    <p class="text-gray-600 mt-2">No real card will be charged. This is a portfolio demo.</p>
  </div>
  <div class="bg-gray-50 rounded p-4 mb-6 text-sm">
    <div><b>Order:</b> {$orderId}</div>
  </div>
  <a href="{$returnUrl}" class="block w-full bg-green-600 text-white text-center py-3 rounded font-semibold hover:bg-green-700">
    Simulate Successful Payment
  </a>
  <a href="{$returnUrl}&status=failed" class="block w-full mt-2 bg-gray-200 text-gray-700 text-center py-3 rounded hover:bg-gray-300">
    Simulate Failed Payment
  </a>
</div></body></html>
HTML;
}
