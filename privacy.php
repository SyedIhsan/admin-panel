<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
$year = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Privacy Policy — Demo Company</title>
  <link href="/img/demo_logo.svg" rel="icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <style>body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen">
  <div class="max-w-3xl mx-auto px-4 py-16">

    <a href="javascript:history.back()" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-slate-700 mb-8 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
      Back
    </a>

    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-xl p-8 md:p-12 prose prose-slate max-w-none">
      <div class="flex items-center gap-4 mb-8 not-prose">
        <div class="w-12 h-12 rounded-2xl bg-yellow-500 flex items-center justify-center shrink-0">
          <img src="/img/demo_logo_white.svg" width="28" height="28" alt="Demo">
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Demo Company</p>
          <h1 class="text-2xl font-black text-slate-900 mt-0.5">Privacy Policy</h1>
        </div>
      </div>
      <p class="text-xs text-slate-400 mb-8 not-prose">Last updated: <?= $year ?> &middot; Effective for all purchases</p>

      <h2>1. Information We Collect</h2>
      <p>When you make a purchase, we collect your full name, email address, phone number, and payment information necessary to process your order. Payment card information is processed securely by our payment provider (SenangPay) and is never stored on our servers.</p>

      <h2>2. How We Use Your Information</h2>
      <p>We use your information to:</p>
      <ul>
        <li>Process and confirm your purchase</li>
        <li>Deliver your digital product and access credentials</li>
        <li>Send order-related communications and receipts</li>
        <li>Provide customer support</li>
        <li>Comply with applicable tax obligations including Service Tax (SST)</li>
      </ul>

      <h2>3. Service Tax (SST)</h2>
      <p>Purchases are subject to an 8% Service Tax (SST) as required under Malaysian law. SST is included in the displayed price and itemised on your receipt.</p>

      <h2>4. Data Sharing</h2>
      <p>We do not sell or rent your personal information to third parties. We may share data with service providers who assist us in operating our platform (e.g. payment processors, email delivery services) under strict confidentiality agreements.</p>

      <h2>5. Data Retention</h2>
      <p>We retain your personal information for as long as necessary to fulfil your purchase, comply with legal obligations, and resolve disputes. Transaction records are kept for a minimum of 7 years in accordance with Malaysian tax regulations.</p>

      <h2>6. Your Rights</h2>
      <p>You may request access to, correction of, or deletion of your personal data by contacting us at the email below. We will respond within 14 business days.</p>

      <h2>7. Contact</h2>
      <p>For privacy-related enquiries, contact us at: <a href="mailto:privacy@demo.local">privacy@demo.local</a></p>
    </div>

    <p class="text-center text-xs text-slate-400 mt-8">&copy; <?= $year ?> Demo Company. All rights reserved. &middot; <a href="/terms.php" class="hover:text-slate-700 underline">Terms &amp; Conditions</a></p>
  </div>
</body>
</html>
