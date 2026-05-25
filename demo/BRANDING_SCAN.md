# Branding Scan — Phase 3 Step 1
Generated: 2026-05-22

Scanned for: SDC, Six Digit Club, Wisetech Digital, Cikgu Kripto, sdc.cx, @sdc.cx, GC / Golden Circle membership codes, company registration number, physical address, logo references.

**Note on `data-sdc-*` attributes** — `data-sdc-confirm-close`, `data-sdc-confirm-ok`, `data-sdc-confirm-cancel` appear in 5 files as JavaScript selector hooks for a custom confirm-dialog component. They are not user-visible text and carry no brand meaning. **No action needed.**

---

## Files containing potential production branding

| File | Line | Snippet | Suggested replacement |
|---|---|---|---|
| `admin/elearning/courses.php` | 45 | `"https://sdc.cx/e-Learning/#/course/"` | `"https://demo.local/e-Learning/#/course/"` |
| `admin/elearning/notify_waitlist.php` | 35 | `"https://sdc.cx/e-Learning/api/waitlist_unsubscribe.php?t="` | `"https://demo.local/api/waitlist_unsubscribe.php?t="` |
| `admin/elearning/progress.php` | 575 | `"Six Digit Club"` (BREVO_SENDER_NAME fallback) | `"Demo Admin"` |
| `admin/elearning/progress.php` | 597 | `'brand_name' => 'SDC E-Learning'` | `'brand_name' => 'Demo E-Learning'` |
| `admin/elearning/progress.php` | 598 | `'brand_email' => 'noreply@sdc.cx'` | `'brand_email' => 'noreply@demo.local'` |
| `admin/elearning/progress.php` | 599 | `'support_email' => 'support@sdc.cx'` | `'support_email' => 'support@demo.local'` |
| `admin/elearning/progress.php` | 600 | `'logo_url' => 'https://sdc.cx/img/sdc_logo.png'` | `'logo_url' => '/img/demo_logo.svg'` |
| `admin/elearning/progress.php` | 601 | `'privacy_url' => 'https://sdc.cx/privacy'` | `'privacy_url' => '#demo-privacy'` |
| `admin/email/audience-group-form.php` | 73 | `'... - SDC Admin'` (page title) | `'... - Demo Admin'` |
| `admin/email/audience-group-import.php` | 179 | `'Import to Audience Group - SDC Admin'` | `'Import to Audience Group - Demo Admin'` |
| `admin/email/audience-group-source-import.php` | 200 | `'Import from Lead Source - SDC Admin'` | `'Import from Lead Source - Demo Admin'` |
| `admin/email/audience-groups.php` | 33 | `'Audience Groups - SDC Admin'` | `'Audience Groups - Demo Admin'` |
| `admin/email/campaign-content.php` | 103 | `'Update from SDC'` (subject default) | `'Update from Demo Company'` |
| `admin/email/campaign-content.php` | 104 | `'A quick update from SDC.'` (preheader) | `'A quick update from Demo Company.'` |
| `admin/email/campaign-content.php` | 105 | `"This is a campaign email from SDC."` | `"This is a campaign email from Demo Company."` |
| `admin/email/campaign-content.php` | 106 | `'Visit SDC'` (button text) | `'Visit Demo'` |
| `admin/email/campaign-content.php` | 107 | `'https://sdc.cx'` (button URL) | `'https://demo.local'` |
| `admin/email/campaign-content.php` | 108 | `"Best regards,\nSDC Team"` | `"Best regards,\nDemo Team"` |
| `admin/email/campaign-content.php` | 109 | `'brand_name' => 'SDC'` | `'brand_name' => 'Demo'` |
| `admin/email/campaign-content.php` | 110 | `'support@sdc.cx'` | `'support@demo.local'` |
| `admin/email/campaign-content.php` | 115 | `'Campaign Content - SDC Admin'` | `'Campaign Content - Demo Admin'` |
| `admin/email/campaign-content.php` | 166 | `'Update from SDC'` (input value) | `'Update from Demo Company'` |
| `admin/email/campaign-content.php` | 171 | `'A quick update from SDC.'` (input) | `'A quick update from Demo Company.'` |
| `admin/email/campaign-content.php` | 176 | `'SDC'` (brand_name input) | `'Demo'` |
| `admin/email/campaign-content.php` | 189 | `"campaign email from SDC."` (textarea) | `"campaign email from Demo Company."` |
| `admin/email/campaign-content.php` | 196 | `'Visit SDC'` (button_text input) | `'Visit Demo'` |
| `admin/email/campaign-content.php` | 200 | `'https://sdc.cx'` (button_url input) | `'https://demo.local'` |
| `admin/email/campaign-content.php` | 206 | `"Best regards,\nSDC Team"` (closing textarea) | `"Best regards,\nDemo Team"` |
| `admin/email/campaign-content.php` | 212 | `'support@sdc.cx'` (support_email input) | `'support@demo.local'` |
| `admin/email/campaign-details.php` | 85 | `'Campaign Details - SDC Admin'` | `'Campaign Details - Demo Admin'` |
| `admin/email/campaign-details.php` | 880 | `'SDC'` (brand_name fallback), `'support@sdc.cx'` | `'Demo'`, `'support@demo.local'` |
| `admin/email/campaign-import.php` | 29–30 | `test1@sdc.cx`, `test2@sdc.cx` (sample CSV download) | `test1@example.test`, `test2@example.test` |
| `admin/email/campaign-import.php` | 433 | `'Import Campaign Recipients - SDC Admin'` | `'Import Campaign Recipients - Demo Admin'` |
| `admin/email/campaign-monitoring.php` | 251 | `'Campaign Monitoring - SDC Admin'` | `'Campaign Monitoring - Demo Admin'` |
| `admin/email/campaign-preview.php` | 22–29 | SDC defaults (subject, preheader, body, button, url, closing, brand, support) | Same pattern as campaign-content.php above |
| `admin/email/campaign-schedule-form.php` | 147 | `'New/Edit Schedule - SDC Admin'` | `'New/Edit Schedule - Demo Admin'` |
| `admin/email/campaign-schedules.php` | 111 | `'Campaign Schedules - SDC Admin'` | `'Campaign Schedules - Demo Admin'` |
| `admin/email/custom-email.php` | 33 | `'brand_name' => 'SDC'` | `'brand_name' => 'Demo'` |
| `admin/email/custom-email.php` | 34 | `'brand_email' => 'noreply@sdc.cx'` | `'brand_email' => 'noreply@demo.local'` |
| `admin/email/custom-email.php` | 35 | `'support_email' => 'support@sdc.cx'` | `'support_email' => 'support@demo.local'` |
| `admin/email/custom-email.php` | 89–91 | Same fallbacks (brand_name, brand_email, support_email) | Same replacements |
| `admin/email/custom-email.php` | 179–182 | `"Best regards,\nSDC Team"`, SDC brand defaults | `"Best regards,\nDemo Team"` + demo equivalents |
| `admin/email/custom-email.php` | 576–578 | `'SDC'`, `'noreply@sdc.cx'`, `'support@sdc.cx'` (POST defaults) | `'Demo'`, demo emails |
| `admin/email/email-logs.php` | 83 | `` `LIKE '%@sdc.cx'` `` (staff filter) | `` `LIKE '%@demo.local'` `` |
| `admin/email/email-logs.php` | 86 | `` `NOT LIKE '%@sdc.cx'` `` | `` `NOT LIKE '%@demo.local'` `` |
| `admin/email/preview.php` | 281–284 | `"Best regards,\nSDC Team"`, `'SDC'`, `'noreply@sdc.cx'`, `'support@sdc.cx'` | Demo equivalents |
| `admin/email/preview.php` | 307 | `'support@sdc.cx'` fallback | `'support@demo.local'` |
| `admin/login.php` | 78 | `src="../img/sdc_logo.png" alt="SDC"` | `src="../img/demo_logo.svg" alt="Demo Admin"` |
| `admin/partials/footer.php` | 8 | `© <?= date("Y") ?> SDC Admin.` | `© <?= date("Y") ?> Demo Admin.` |
| `admin/partials/header.php` | 4 | `$title ?? "SDC Admin"` | `$title ?? "Demo Admin"` |
| `admin/partials/header.php` | 12 | `<link href="/img/sdc_logo.png" rel="icon">` | `<link href="/img/demo_logo.svg" rel="icon">` |
| `admin/partials/nav.php` | 206 | `src="/img/sdc_logo.png" alt="SDC"` | `src="/img/demo_logo.svg" alt="Demo"` |
| `admin/partials/nav.php` | 279 | `SDC Admin` (sidebar label) | `Demo Admin` |
| `admin/partials/nav.php` | 310 | `<span ...>SDC</span>` (mobile header) | `<span ...>DEMO</span>` |
| `admin/payment/add-transaction.php` | 409 | `placeholder="e.g. SDC012345"` | `placeholder="e.g. DEMO0001"` |
| `admin/payment/dashboard.php` | 298 | `// SDC Yellow` (JS comment) | `// Demo Yellow` (cosmetic only) |
| `admin/payment/product-form.php` | 1228 | `placeholder="e.g. SDC VIP Access"` | `placeholder="e.g. Demo VIP Access"` |
| `admin/payment/product-form.php` | 1454–1458 | `id/name="membership_sdc"`, label `SDC Membership` | See **UNSURE** section below |
| `admin/payment/product-form.php` | 1468 | `GC Membership` label | See **UNSURE** section below |
| `admin/payment/product-form.php` | 1473 | `"Golden Circle → GC, SDC Community / SDC Basic / SDC Half → SDC"` example text | `"Premium → PREM, Standard → STD"` |
| `admin/payment/receipt.php` | 271 | `alt="SDC"` (logo img) | `alt="Demo"` |
| `admin/payment/receipt.php` | 280, 285 | `Wisetech Digital` (company name, address block) | `Demo Company` |
| `admin/payment/receipt.php` | 286–288 | `No. 60-1, Jalan Prima SG 2, Prima Seri Gombak, 68100 Batu Caves, Selangor` (real address) | `1 Demo Avenue, Demo City, 00000` |
| `admin/payment/receipt.php` | 393 | `"Thank you for choosing Wisetech Digital."` | `"Thank you for choosing Demo Company."` |
| `admin/payment/receipt.php` | 394 | `"Visit us at https://sdc.cx"` | `"Visit us at https://demo.local"` |
| `admin/subscription/index.php` | 23, 28 | `"%@sdc.cx"` (staff record filter) | `"%@demo.local"` |
| `admin/subscription/index.php` | 169 | `"Local mode: showing @sdc.cx test records only"` | `"Local mode: showing @demo.local test records only"` |
| `admin/subscription/index.php` | 171 | `"Production mode: staff @sdc.cx records are hidden"` | `"Production mode: staff @demo.local records are hidden"` |
| `admin/webinar/email.php` | 39 | `"Regards,\nSix Digit Club Team"` (email body template) | `"Regards,\nDemo Team"` |
| `admin/webinar/send-email.php` | 86 | `'preheader' => 'Webinar update from Six Digit Club'` | `'preheader' => 'Webinar update from Demo Company'` |
| `admin/webinar/send-email.php` | 89 | `'brand_name' => 'Six Digit Club'` | `'brand_name' => 'Demo Company'` |
| `admin/webinar/send-email.php` | 90 | `'support@sdc.cx'` fallback | `'support@demo.local'` |
| `api/mail/campaign-cron-send.php` | 56 | `$_SERVER['HTTP_HOST'] = 'sdc.cx'` | `$_SERVER['HTTP_HOST'] = 'demo.local'` |
| `api/mail/campaign-helpers.php` | 374 | `return 'https://sdc.cx'` (public base URL) | `return 'https://demo.local'` |
| `api/mail/campaign-helpers.php` | 513 | `'https://sdc.cx'` (button_url default) | `'https://demo.local'` |
| `api/mail/campaign-helpers.php` | 550–551 | `'support@sdc.cx'` (brand_email, support_email) | `'support@demo.local'` |
| `api/mail/campaign-helpers.php` | 1336 | `header('Location: https://sdc.cx', ...)` (unsubscribe redirect) | `header('Location: https://demo.local', ...)` |
| `api/mail/layout.php` | 81 | `'brand_name' ?? 'Wisetech Digital'` | `'Demo Company'` |
| `api/mail/layout.php` | 82 | `'brand_email' ?? 'noreply@sdc.cx'` | `'noreply@demo.local'` |
| `api/mail/layout.php` | 84 | `'support_email' ?? 'support@sdc.cx'` | `'support@demo.local'` |
| `api/mail/layout.php` | 85 | `'https://sdc.cx/img/sdc_logo.png'` (logo in email header) | `'/img/demo_logo.svg'` |
| `api/mail/layout.php` | 88 | `'https://sdc.cx/policy.html#pc'` (privacy link) | `'#demo-privacy'` |
| `api/mail/layout.php` | 94 | `'https://sdc.cx/unsubscribe.php...'` | `'#demo-unsubscribe'` |
| `api/mail/layout.php` | 95 | `'https://sdc.cx/manage-preferences.php...'` | `'#demo-preferences'` |
| `api/mail/retry-send-helper.php` | 56 | `'brand_name' ?? 'Wisetech Digital'` | `'Demo Company'` |
| `api/mail/retry-send-helper.php` | 57 | `'noreply@sdc.cx'` | `'noreply@demo.local'` |
| `api/mail/retry-send-helper.php` | 58 | `'support@sdc.cx'` | `'support@demo.local'` |
| `api/mail/templates/certificate-issued.php` | 16–19 | `noreply@sdc.cx`, `support@sdc.cx`, logo URL, privacy URL | Demo equivalents |
| `api/mail/templates/custom-product.php` | 12–14 | `'Six Digit Club'`, `noreply@sdc.cx`, `support@sdc.cx` | `'Demo Company'`, demo emails |
| `api/mail/templates/elearning-access.php` | 78–79 | `'Six Digit Club'`, `support@sdc.cx` | `'Demo Company'`, `support@demo.local` |
| `api/mail/templates/payment-confirmed.php` | 25 | `'Payment Confirmed - Six Digit Club'` (subject) | `'Payment Confirmed - Demo Company'` |
| `api/mail/templates/payment-confirmed.php` | 73–74 | `'Six Digit Club'`, `support@sdc.cx` | `'Demo Company'`, `support@demo.local` |
| `payment/payment.php` | 1177 | `© <?= date("Y") ?> Six Digit Club (SDC) Sdn. Bhd. (1578840-H). All rights reserved.` | `© <?= date("Y") ?> Demo Company. All rights reserved.` |
| `payment/payment.php` | 1027 | `placeholder="e.g. SDC012345"` | `placeholder="e.g. DEMO0001"` |
| `payment/process-renewal.php` | 38–39 | `https://sdc.cx/e-Learning/#/signin`, `#/forgot-password` | `https://demo.local/e-Learning/#/signin`, `#/forgot-password` |
| `payment/process-renewal.php` | 354 | `'Subscription Renewed - ' . ($productName ?: 'Wisetech Digital')` | `'Subscription Renewed - ' . ($productName ?: 'Demo Company')` |
| `payment/process-retention.php` | 38–39 | Same URLs as renewal | Same replacements |
| `payment/start-payment.php` | 331–357 | `"GOLDEN CIRCLE"`, `"GC"`, `"SDC"` in member-code-generation logic | See **UNSURE** below |

---

## Files that need a global rename

These patterns appear across many files and should be replaced with a single sed pass after approval:

| Pattern | Occurrences (approx.) | Files | Suggested replacement |
|---|---|---|---|
| `support@sdc.cx` | 18 | 12 files | `support@demo.local` |
| `noreply@sdc.cx` | 8 | 7 files | `noreply@demo.local` |
| `https://sdc.cx` (bare domain) | 12 | 8 files | `https://demo.local` |
| `SDC Admin` (page title suffix) | 11 | 9 files | `Demo Admin` |
| `'SDC'` / `"SDC"` (brand name string, **not** JS attributes) | ~20 | 8 files | `'Demo'` |
| `Six Digit Club` | 9 | 7 files | `Demo Company` |
| `Wisetech Digital` | 6 | 4 files | `Demo Company` |
| `/img/sdc_logo.png` | 8 | 6 files | `/img/demo_logo.svg` |

**New file needed:** `img/demo_logo.svg` — a minimal SVG (neutral color, text "DEMO") to replace all logo references. No `img/` directory currently exists; it must be created.

---

## UNSURE — needs your decision before replacement

**1. Membership type codes `SDC` and `GC`**

These are not just display strings — they are stored as values in the database (`membership_type` column) and drive logic in `payment/start-payment.php`:
- Products get tagged with membership type `SDC` or `GC` (admin checkbox in `product-form.php`)
- On payment, the system generates member codes like `SDC1234` or `GC1234`
- `start-payment.php` uses `preg_match('/^(SDC|GC)\d{4}$/', $code)` to validate format

**Options:**
- A. Leave as-is — they're internal codes the admin sees, not user-facing brand names. A recruiter viewing the demo admin panel seeing "SDC Membership" is acceptable since the whole panel is clearly a sanitized demo.
- B. Rename: `SDC` → `TIER1` / `GC` → `TIER2` (requires updating the regex in `start-payment.php` and all DB seed rows that store this value).

**My recommendation:** Option A for now — rename the UI labels (`SDC Membership` → `Primary Membership`, `GC Membership` → `Premium Membership`) but leave the internal code values (`SDC`, `GC`) unchanged in the logic. The seed data can use `TIER1`/`TIER2` as member code *prefixes* if you prefer.

---

**2. `sdc_logo.png` — the file doesn't exist**

The `img/` directory doesn't exist in the project. All 8 references to `/img/sdc_logo.png` are currently broken. The logo appears in: login page, nav sidebar, receipt header, email HTML, favicon `<link>` tags, and PDF cert emails.

Decision needed: Should I generate a minimal SVG placeholder (`img/demo_logo.svg`) and update all references, or would you prefer to provide a logo file?
