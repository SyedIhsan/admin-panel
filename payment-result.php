<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kuala_Lumpur');

function param(string $key, string $default = ''): string {
  return (string)($_POST[$key] ?? $_GET[$key] ?? $default);
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$status         = param('status', param('status_id', '0'));
$order_id       = param('order_id', '');
$transaction_id = param('transaction_id', param('transaction_reference', ''));
$name           = param('name', 'Customer');
$email          = param('email', '');
$phone          = param('phone', '');
$amount         = param('price', param('amount', '0.00'));
$msg  = param('msg', param('txn_msg', ''));
$hash = param('hash', '');

$isSuccess = ((string)$status === '1');

$order_key = $order_id;
$pos = strpos($order_id, '-oid-');
if ($pos !== false) $order_key = substr($order_id, 0, $pos);

// e-Learning detection (same logic as your process-payment.php)
$isElearning = (preg_match('/^(beg|int|adv)-\d+$/i', $order_key) === 1);

$elearnLogin = 'https://syedihsan.github.io/e-Learning/#/signin';
$elearnHome  = 'https://syedihsan.github.io/e-Learning/';
$mainHome    = '/admin/payment/dashboard.php';

$pageTitle = 'Demo - Payment Result';

// Copywriting (English, different for e-Learning vs others)
if ($isSuccess) {
  if ($isElearning) {
    $headlineTop = 'Enrollment';
    $headlineBottom = '<span class="text-yellow-600">Confirmed!</span>';
    $message = "Success! Your learning path has been unlocked. We've sent your access credentials to your email address.";
  } else {
    $headlineTop = 'Payment';
    $headlineBottom = '<span class="text-yellow-600">Successful!</span>';
    $message = "Success! Your payment has been received. Please check your email for your purchase confirmation and next steps.";
  }
} else {
  if ($isElearning) {
    $headlineTop = 'Payment';
    $headlineBottom = '<span class="text-rose-600">Failed</span>';
    $message = "Your payment was not successful. Please try again. If you were charged, contact support@demo.local with your Transaction Reference.";
  } else {
    $headlineTop = 'Payment';
    $headlineBottom = '<span class="text-rose-600">Failed</span>';
    $message = "Your payment was not successful. Please try again. If you were charged, contact support@demo.local with your Transaction Reference.";
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h($pageTitle) ?></title>
  <link href="/img/demo_logo.svg" rel="icon">

  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50">
  <div class="min-h-[85vh] flex items-center justify-center bg-slate-50 px-4 py-20">
    <div class="max-w-xl w-full bg-white rounded-[3.5rem] p-10 md:p-16 text-center shadow-2xl shadow-slate-200 border border-slate-100 relative overflow-hidden">
      <!-- Background Accents -->
      <div class="absolute top-0 left-0 w-32 h-32 bg-yellow-50 rounded-full -ml-16 -mt-16"></div>
      <div class="absolute bottom-0 right-0 w-48 h-48 bg-yellow-100 rounded-full -mr-24 -mb-24 opacity-60"></div>

      <div class="relative z-10">
        <?php if ($isSuccess): ?>
          <div class="w-24 h-24 bg-emerald-100 rounded-[2rem] flex items-center justify-center mx-auto mb-10 rotate-12 shadow-lg shadow-emerald-50">
            <svg class="w-12 h-12 text-emerald-600 -rotate-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
            </svg>
          </div>
        <?php else: ?>
          <div class="w-24 h-24 bg-rose-100 rounded-[2rem] flex items-center justify-center mx-auto mb-10 rotate-12 shadow-lg shadow-rose-50">
            <svg class="w-12 h-12 text-rose-600 -rotate-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
        <?php endif; ?>

        <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-6 tracking-tight">
          <?= h($headlineTop) ?> <br />
          <?= $headlineBottom ?>
        </h1>

        <p class="text-slate-500 mb-12 text-lg leading-relaxed max-w-sm mx-auto font-medium">
          <?= h($message) ?>
        </p>

        <!-- Transaction Details -->
        <div class="mb-10 text-left bg-slate-50 border border-slate-100 rounded-2xl p-5">
          <div class="text-xs text-slate-400 uppercase tracking-widest font-black mb-3">Transaction Details</div>
          <div class="text-sm text-slate-700 space-y-1">
            <div><span class="font-bold text-slate-900">Name:</span> <?= h($name) ?></div>
            <?php if ($email !== ''): ?><div><span class="font-bold text-slate-900">Email:</span> <?= h($email) ?></div><?php endif; ?>
            <?php if ($phone !== ''): ?><div><span class="font-bold text-slate-900">Phone:</span> <?= h($phone) ?></div><?php endif; ?>
            <?php if ($order_id !== ''): ?><div><span class="font-bold text-slate-900">Order ID:</span> <?= h($order_id) ?></div><?php endif; ?>
            <?php if ($transaction_id !== ''): ?><div><span class="font-bold text-slate-900">Transaction Reference:</span> <?= h($transaction_id) ?></div><?php endif; ?>
            <div><span class="font-bold text-slate-900">Amount:</span> RM <?= h($amount) ?></div>
          </div>
        </div>

        <!-- Buttons -->
        <div class="flex flex-col space-y-4 items-center">
          <?php if ($isElearning): ?>
            <a
              href="<?= h($elearnLogin) ?>"
              class="w-full py-5 bg-yellow-500 text-white rounded-2xl font-black text-xl hover:bg-yellow-600 shadow-xl shadow-yellow-100 transition-all hover:scale-[1.02] active:scale-95 flex items-center justify-center space-x-3"
            >
              <span>Sign In to Platform</span>
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 16l-4-4m0 0l4-4m-4 4h14" />
              </svg>
            </a>

            <a
              href="<?= h($elearnHome) ?>"
              class="w-full py-5 bg-white text-slate-600 border border-slate-200 rounded-2xl font-bold text-lg hover:bg-slate-50 transition-all flex items-center justify-center"
            >
              Return Home
            </a>
          <?php else: ?>
            <a
              href="<?= h($mainHome) ?>"
              class="w-full py-5 bg-yellow-500 text-white rounded-2xl font-black text-xl hover:bg-yellow-600 shadow-xl shadow-yellow-100 transition-all hover:scale-[1.02] active:scale-95 flex items-center justify-center"
            >
              Return Home
            </a>
          <?php endif; ?>
        </div>

        <div class="mt-12 pt-8 border-t border-slate-50">
          <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">
            <?= $isElearning ? 'Digital Product Delivery — Portfolio Demo' : 'Secure Payment — Demo Panel' ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
