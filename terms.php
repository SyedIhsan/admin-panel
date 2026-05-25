<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
$year = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Terms &amp; Conditions — Demo Company</title>
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
          <h1 class="text-2xl font-black text-slate-900 mt-0.5">Terms &amp; Conditions</h1>
        </div>
      </div>
      <p class="text-xs text-slate-400 mb-8 not-prose">Last updated: <?= $year ?> &middot; Applicable to all purchases</p>

      <h2>1. Eligibility</h2>
      <p>You must be at least 18 years of age to make a purchase. By completing checkout, you confirm that you meet this requirement.</p>

      <h2>2. Digital Products</h2>
      <p>All products sold are digital in nature and delivered electronically. Access is granted upon successful payment confirmation. We reserve the right to revoke access if a purchase is found to be fraudulent or in breach of these Terms.</p>

      <h2>3. Pricing and Service Tax (SST)</h2>
      <p>All prices are in Malaysian Ringgit (MYR) and include 8% Service Tax (SST) as required by Malaysian law. The SST breakdown is itemised on your receipt.</p>

      <h2>4. Payment</h2>
      <p>Payments are processed securely through SenangPay. We accept major credit/debit cards and FPX online banking. By submitting your payment, you authorise us to charge the total amount shown on the order summary.</p>

      <h2>5. Refund Policy</h2>
      <p>Due to the digital nature of our products, all sales are final and non-refundable once access has been granted. If you experience a technical issue accessing your product, please contact our support team within 7 days of purchase.</p>

      <h2>6. Subscription and Installment Payments</h2>
      <p>For subscription or instalment products, future payments are not automatically charged to your card. You will receive a secure payment link via email or WhatsApp when each subsequent payment is due. Non-payment may result in access suspension.</p>

      <h2>7. Intellectual Property</h2>
      <p>All content within purchased products is protected by copyright. You are granted a personal, non-transferable licence for your own use only. Redistribution, resale, or sharing of product content is strictly prohibited.</p>

      <h2>8. Governing Law</h2>
      <p>These Terms are governed by the laws of Malaysia. Any disputes shall be subject to the exclusive jurisdiction of Malaysian courts.</p>

      <h2>9. Contact</h2>
      <p>For questions about these Terms, contact us at: <a href="mailto:support@demo.local">support@demo.local</a></p>
    </div>

    <p class="text-center text-xs text-slate-400 mt-8">&copy; <?= $year ?> Demo Company. All rights reserved. &middot; <a href="/privacy.php" class="hover:text-slate-700 underline">Privacy Policy</a></p>
  </div>
</body>
</html>
