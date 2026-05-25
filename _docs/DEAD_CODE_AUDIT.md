# Dead Code Audit — Phase 2.5
Generated: 2026-05-22

---

## Methodology

1. Used `admin/partials/nav.php` as the canonical source of truth for which pages the demo needs to serve.
2. Traced `require_once` / `require` / `include` chains outward from each nav-linked page, plus the payment flow entry points (`payment/start-payment.php`, `payment/payment.php`, `payment/process-*.php`, `payment/subscription-pay.php`) and the elearning entry points (`admin/elearning/*.php`).
3. Cross-checked API files for PHP `require` calls, JavaScript `fetch`/AJAX calls, and HTML form `action` attributes.
4. Verified "webinar_reminder" matches were DB table references (`sdc_webinar_reminders`), not file references.
5. Checked all assets for code-level references (hardcoded paths, variable-path DB reads).

---

## Entry points used for reachability analysis

| Entry point | Why included |
|---|---|
| `admin/partials/nav.php` | Source of truth — every link in the sidebar |
| `admin/index.php` | Root `/admin/` redirect |
| `admin/login.php` | Auth entry point |
| `payment/start-payment.php` | Payment flow start |
| `payment/payment.php` | Payment UI |
| `payment/process-renewal.php` | Payment callback |
| `payment/process-retention.php` | Payment callback |
| `payment/subscription-pay.php` | Token-based payment entry |
| `demo-gateway.php` | Demo payment landing |

---

## DEFINITELY DEAD (zero references, safe to delete)

| Path | Size | Reason |
|---|---|---|
| `api/mail/webinar-marketing-helpers.php` | 10,922 B | Marketing email helper; its only consumer (`api/mail/webinar-marketing-emails.php`) was deleted in Phase 2. Zero `require` references remain. |
| `api/webinar_reminder.php` | 6,668 B | Standalone cron/webhook endpoint. Not required or `fetch`'d by any PHP or JS file. Matches for "webinar_reminder" in `admin/webinar/*.php` are to the DB table `sdc_webinar_reminders`, not this file. |
| `api/webinar_register.php` | 5,254 B | Public registration endpoint. Not required or fetched from any admin page. No nav link. Only self-reference in its own header comment. |
| `admin/elearning/lib/google_api_stub.php` | 4,950 B | Placed in Phase 2 to replace deleted `google_oauth.php` / `drive_api.php` / `sheets_api.php`. The original callers were already deleted; no file currently requires this stub. |
| `api/payment_gateway_stub.php` | 4,406 B | Copied from `starter-kit/stubs/` per PLACEMENT_GUIDE, but never wired up — no file contains `require_once … payment_gateway_stub`. Payment gateway is handled by `demo-gateway.php` via redirect instead. |
| `api/sendmail.php` | 583 B | Phase 2 stub. Not required by any admin or payment page. The only "reference" is the file's own header comment. |
| `api/adduser.php` | 292 B | Phase 2 stub (rewrote from deleted `dbwebinar.php` dependency). Not linked from any admin page or required by any live file. |
| `api/registerSDC.php` | 247 B | Phase 2 stub (rewrote from deleted `dbi.php` dependency). Not linked from any admin page or required by any live file. |
| `api/register.php` | 246 B | Phase 2 stub (rewrote from deleted `dbi.php` dependency). Not linked from any admin page or required by any live file. |

**Total: ~27,862 B (~27 KB)**

---

## LIKELY DEAD (referenced only by other dead files)

None identified. No dead-to-dead reference chains found.

---

## ORPHANED ASSETS

Image files with no current code path (no hardcoded path, no variable-path PHP reference). They are production webinar poster images; code reads `poster_url` from DB rows and renders as `<img src>` — but there are no DB rows yet. See KEEP section for disposition note.

| Path | Size | Note |
|---|---|---|
| `payment/storage/posters/poster_c3baefc9140732a0.png` | 1,852,150 B | Production webinar poster |
| `payment/storage/posters/poster_d581b35a80ebfd8e.png` | 1,197,976 B | Production webinar poster |
| `payment/storage/posters/poster_1d05c051053494b7.png` | 1,112,436 B | Production webinar poster |
| `payment/storage/posters/poster_dcbf94d4d857eb9b.png` | 938,578 B | Production webinar poster |

**Total: ~5.1 MB** — Recommend keeping as demo seed images for Phase 3 (DB seed rows will reference them by their current filenames).

---

## KEEP — looks unused but isn't (false positives)

| Path | Size | Why actually used |
|---|---|---|
| `api/env.php` | 430 B | `loadEnv()` is a no-op stub, but the file is `require_once`'d via variable path in `payment/process-renewal.php`, `payment/process-retention.php`, and `payment/start-payment.php` (`$envLoader = realpath(...)` + `require_once $envLoader`). Deleting it causes a fatal error on any payment page load. |
| `admin/index.php` | 106 B | Zero nav links point here, but it is the `/admin/` root entry point — it authenticates and redirects to `payment/dashboard.php`. Without it, navigating to `/admin/` returns a 404. |
| `admin/elearning/assets/cert_template.png` | 6,399,671 B | Referenced by `admin/elearning/progress.php` as the background image for PDF certificate generation. Required for the cert-send feature. |
| `admin/elearning/assets/admin.css` | 556 B | Loaded by elearning header partial. Removal breaks elearning page layout. |
| `starter-kit/` (entire directory) | — | Scaffolding for Phases 3–7. `schema.skeleton.sql` → Phase 3, `reset.php` → Phase 3, `demo-banner.php` → Phase 4, `README.template.md` → Phase 5, `demo/.htaccess` → Phase 6. Not dead — not yet consumed. |
| `payment/storage/posters/*.png` (×4) | ~5.1 MB | See ORPHANED ASSETS above. Code reads filenames from DB `poster_url` column. Keep as ready-made demo seed images for Phase 3. |

---

## UNSURE — needs your decision

None. All ambiguous files were resolved above.
