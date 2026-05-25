# Branding Scan — Phase 4
Generated: 2026-05-25

Incremental scan after Phase 3.1 rounds 1–6 (+3.1p installment product +3.1q mobile nav fix).
All patterns from the original Phase 3 scan were re-checked.

---

## Executive Summary

Phase 2.5 successfully eliminated all HIGH-severity branding leaks:
`sdc.cx`, `Six Digit Club`, `Wisetech Digital`, `noreply@sdc.cx`, `support@sdc.cx`,
`SDC Admin` page titles, `sdc_logo.png`, and the real office address are **no longer present**
in any `.php` file.

**No new HIGH findings from Phase 3.1 changes.**

Remaining items are LOW (comments / internal DB table names) or already-approved INTENTIONAL.

---

## Findings

| File | Line | Snippet | Severity | Suggested replacement |
|---|---|---|---|---|
| `payment/start-payment.php` | 331 | `// SDC variants` (comment) | LOW | `// membership variants` |
| `payment/start-payment.php` | 333 | `return "SDC";` (membership type code value) | LOW | Keep — internal-only; see note 1 |
| `payment/start-payment.php` | 352 | `// SDC1234 / GC1234` (comment) | LOW | `// TIER11234 / TIER21234` |
| `payment/start-payment.php` | 971 | `// SID = Member Code (SDC1234 / GC1234)` (comment) | LOW | `// SID = Member Code (TIER11234 / TIER21234)` |
| `payment/start-payment.php` | 975 | `in_array($t, ['SDC', 'GC'], true)` (whitelist) | LOW | Keep — internal logic; see note 1 |
| `payment/start-payment.php` | 326–327 | `"GOLDEN CIRCLE"` / `/\bGC\b/` in code-generation logic | LOW | Keep — internal logic; see note 1 |
| `admin/payment/dashboard.php` | 298 | `// SDC Yellow` (JS comment) | LOW | `// Brand Yellow` |
| `admin/webinar/*.php` (multiple) | various | `sdc_webinars`, `sdc_webinar_registrations`, `sdc_webinar_marketing_*` in SQL strings | LOW | Keep — DB table names; see note 2 |
| `admin/webinar/actions.php` | 51, 65 | `"uploads/SDC_webinars/"` (file path) | LOW | `"uploads/webinars/"` — see note 3 |
| `admin/webinar/form.php` | 65 | `"uploads/SDC_webinars/"` (file path) | LOW | `"uploads/webinars/"` — see note 3 |
| `admin/email/audience-group-import.php` | 25–26 | `ali@example.com`, `siti@example.com` in sample CSV download | LOW | `ali@example.test`, `siti@example.test` |
| `admin/email/campaign-preview.php` | 16 | `$sampleEmail = 'jane@example.com'` | LOW | `'jane@example.test'` |
| `admin/email/preview.php` | 302 | `'{{email}}' => 'jane@example.com'` | LOW | `'jane@example.test'` |

---

## Intentional — no action needed

| File | Line | Snippet | Reason |
|---|---|---|---|
| `admin/partials/nav.php` | 237, 331, 549 | `syedihsan.github.io/e-Learning/` | **Open Student Site link** — intentional per Phase 3 scan approval |
| `payment-result.php` | 32–33 | `syedihsan.github.io/e-Learning/` | Same student site redirect — intentional |
| `admin/partials/demo-banner.php` | 24 | `github.com/SyedIhsan/admin-panel` — "View Source" link | **Portfolio intent** — demo banner deliberately points to the repo so recruiters can view source |

---

## Notes

**Note 1 — SDC / GC membership type codes**

These strings (`"SDC"`, `"GC"`, `"GOLDEN CIRCLE"`) are stored values and logic in `payment/start-payment.php` that drive member-code generation (e.g. `SDC1234`). They are **never shown to end users in the UI** — they exist in the DB `membership_type` column and internal code-generation logic only.

The original Phase 3 scan recommended keeping internal code values unchanged while renaming UI labels (`SDC Membership` → label already fixed). The UI labels are gone. These LOW items are internal plumbing.

If you want to rename: change `"SDC"` → `"TIER1"`, `"GC"` → `"TIER2"` everywhere in start-payment.php and update seed data. Not required for demo quality.

**Note 2 — `sdc_webinar*` table names**

Database table names are defined in `demo/schema.sql`. They are never rendered to users — they appear only inside PHP SQL query strings. A recruiter browsing the live demo will never see them. Renaming requires altering schema.sql, seed.sql, and all query strings in ~8 files — high effort, zero visible impact.

**Note 3 — `uploads/SDC_webinars/` directory**

This file-upload subdirectory path would only be visible to someone with server access. On InfinityFree or any public host the `uploads/` folder is above the webroot or .htaccess-protected. The demo seed data does not include actual uploaded webinar files, so this path is never traversed in the demo.

---

## Phase 3.1 additions — clean

Files added/modified in Phase 3.1 rounds were checked and contain **no new branding issues**:
- `payment-result.php` — uses `demo.local`, `syedihsan.github.io/e-Learning/` (intentional)
- `demo/seed.sql` — no SDC/GC/sdc.cx in data values; `sdc_webinar*` table names are pre-existing schema
- `admin/partials/footer.php` — `© Demo Admin.` ✓
- `admin/partials/nav.php` — `Demo Admin` ✓, `syedihsan.github.io/e-Learning/` ✓ (intentional)
- `demo-gateway.php` — `Demo Payment Gateway` ✓

---

## Recommendation

**Nothing to fix before committing.** All HIGH items are gone. The LOW items are:
- Internal DB table names (rename = high effort / zero visible impact)
- PHP comments referencing old codes
- A few `@example.com` → `@example.test` cosmetic normalizations

If you want a clean sweep, the only items worth touching before Phase 6 deployment are:
1. `uploads/SDC_webinars/` → `uploads/webinars/` (2 files, 2 lines) — avoids exposing SDC path on server
2. `ali@example.com` / `siti@example.com` / `jane@example.com` → `@example.test` (3 files, 4 lines)
3. `// SDC Yellow` comment in dashboard.php (1 line cosmetic)

Skip 1–3 for now — they don't affect demo presentation.
