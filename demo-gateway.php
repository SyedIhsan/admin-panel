<?php
/**
 * demo-gateway.php — fake payment gateway landing page
 * Simulates SenangPay checkout for portfolio demo.
 * No real card is charged. Buttons redirect to payment-result.php
 * with status=1 (success) or status=0 (failed).
 */
declare(strict_types=1);

$orderId = (string)($_GET['order_id'] ?? 'DEMO-ORDER');
$amount  = (string)($_GET['amount']   ?? '0.00');
$name    = (string)($_GET['name']     ?? 'Customer');
$email   = (string)($_GET['email']    ?? '');
$phone   = (string)($_GET['phone']    ?? '');
$type    = (string)($_GET['type']     ?? 'payment');

// Generate a transaction ID for this gateway session
$txnId = 'DEMO-TXN-' . strtoupper(bin2hex(random_bytes(4)));

$resultParams = http_build_query([
    'order_id'       => $orderId,
    'transaction_id' => $txnId,
    'name'           => $name,
    'email'          => $email,
    'phone'          => $phone,
    'amount'         => $amount,
]);

$successUrl = '/payment-result.php?status=1&' . $resultParams;
$failUrl    = '/payment-result.php?status=0&' . $resultParams;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Demo Payment Gateway</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center px-4">
  <div class="bg-white rounded-2xl shadow-xl p-8 max-w-md w-full">

    <div class="text-center mb-6">
      <div class="inline-flex items-center justify-center w-16 h-16 bg-amber-100 rounded-full mb-4">
        <span class="text-3xl">🎭</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">Demo Payment Gateway</h1>
      <p class="text-gray-500 mt-1 text-sm">No real card will be charged. Portfolio demo only.</p>
    </div>

    <div class="bg-gray-50 rounded-xl p-4 mb-6 text-sm text-gray-600 space-y-1">
      <?php if ($name !== 'Customer' && $name !== ''): ?>
      <div><span class="font-medium">Name:</span> <?= h($name) ?></div>
      <?php endif; ?>
      <?php if ($email !== ''): ?>
      <div><span class="font-medium">Email:</span> <?= h($email) ?></div>
      <?php endif; ?>
      <div><span class="font-medium">Order ID:</span> <?= h($orderId) ?></div>
      <div><span class="font-medium">Amount:</span> RM <?= h($amount) ?></div>
      <div><span class="font-medium">Txn Ref:</span> <?= h($txnId) ?></div>
    </div>

    <div class="space-y-3">
      <a href="<?= h($successUrl) ?>"
         class="block w-full bg-green-600 hover:bg-green-700 text-white text-center py-3 px-4 rounded-xl font-semibold transition-colors">
        ✓ Simulate Successful Payment
      </a>
      <a href="<?= h($failUrl) ?>"
         class="block w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-center py-3 px-4 rounded-xl font-semibold transition-colors">
        ✗ Simulate Failed Payment
      </a>
    </div>

    <p class="text-center text-xs text-gray-400 mt-6">
      This page replaces SenangPay in the portfolio demo.
    </p>
  </div>
</body>
</html>
