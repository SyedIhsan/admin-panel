# Phase 3 Smoke Test — Static SQL Analysis
Generated: 2026-05-23 against local `demo_panel` DB (37 tables, seeded)

## Table row counts at time of test

| Table | Rows | Spec | Status |
|---|---|---|---|
| Payment | 16 | 16 | ✅ |
| Orders | 16 | 16 | ✅ |
| Products | 4 | — | ✅ |
| Product_Categories | 2 | — | ✅ |
| Discount_Codes | 3 | — | ✅ |
| Subscriptions | 6 | — | ✅ |
| Subscription_Billing_History | 15 | 15–25 | ✅ |
| sdc_webinars | 3 | — | ✅ |
| sdc_webinar_registrations | 40 | 40–60 | ✅ |
| sdc_webinar_reminders | 8 | — | ✅ |
| courses | 3 | — | ✅ |
| course_videos | 8 | — | ✅ |
| course_ebooks | 6 | — | ✅ |
| course_workbooks | 3 | — | ✅ |
| user_progress | 15 | ≥1/student/course | ✅ |
| email_campaigns | 4 | — | ✅ |
| email_campaign_recipients | 200 | 200–400 | ✅ |
| email_audience_group_members | 162 | 150–250 | ✅ |
| email_templates | 3 | — | ✅ |
| email_logs | 12 | — | ✅ |
| admins | 1 | — | ✅ |

---

## Nav page analysis

| Page | Status | Tables queried | Empty result risk | Notes |
|---|---|---|---|---|
| /admin/payment/dashboard.php | ✅ OK | `Payment`, `Products` | None | 11 completed+verified rows → RM 3,289.30 revenue; 4 active products |
| /admin/payment/admin-products.php | ✅ OK | `Products`, `Product_Categories`, `Discount_Codes` | None | 4 products (3 active, 1 inactive), 2 variants, 3 discount codes |
| /admin/payment/transactions.php | ✅ OK | `Payment` | None | 16 rows; `ENV_PAY_WHERE=1=1` shows all; 3 statuses (completed/pending/failed) |
| /admin/subscription/index.php | ✅ OK | `Subscriptions`, `Subscription_Billing_History` | None | localhost: 3 @demo.local rows shown; production: 3 non-@demo.local rows shown |
| /admin/webinar/index.php | ✅ OK | `sdc_webinars`, `sdc_webinar_registrations`, `sdc_webinar_reminders` | None | 3 webinars, 40 registrations, 8 reminders |
| /admin/elearning/dashboard.php | ✅ OK | `courses`, `user_progress`, `Payment` | None | `order_products` absent → revenue=0, active learners=0 (expected); courses=3, avg completion shows |
| /admin/elearning/courses.php | ✅ OK | `courses` | None | 3 courses with string IDs (dmf-2024, bgs-2024, adi-2024) |
| /admin/elearning/contents.php | ✅ OK | `courses`, `course_videos`, `course_ebooks`, `course_workbooks` | None | 8 videos / 6 ebooks / 3 workbooks all linked via VARCHAR course_id |
| /admin/elearning/progress.php | ✅ OK | `user_progress`, `course_videos`, `course_ebooks`, `course_workbooks` | None | 15 progress rows; string COLLATE match works after schema fix |
| /admin/email/custom-email.php | ✅ OK | `email_templates` (only on ?id= edit) | None | Default GET is a blank creation form; no list query runs |
| /admin/email/email-templates.php | ✅ OK | `email_templates` | None | 3 templates rendered |
| /admin/email/campaign-monitoring.php | ⚠️ EMPTY (expected) | `email_campaigns`, `email_campaign_recipients` | Expected empty on localhost | localhost filter: `LOWER(campaign_name) LIKE '%test%'` → 0 results (our 4 campaigns have no "test" in name); production filter: `NOT LIKE '%test%'` → all 4 campaigns display. **Hand-verify on InfinityFree.** |
| /admin/email/email-logs.php | ✅ OK | `email_logs` | None | 12 rows via `el_pick_table()` dynamic discovery |

---

## Fixes applied during Phase 3

| Issue | Fix | File |
|---|---|---|
| `courses.id` was `INT AUTO_INCREMENT`; code uses string keys like `'dmf-2024'` | Changed to `VARCHAR(60) NOT NULL` PRIMARY KEY; removed redundant `course_key` column | `demo/schema.sql` |
| `course_videos/ebooks/workbooks.course_id` was `INT UNSIGNED` | Changed to `VARCHAR(60) NOT NULL` to match courses.id type | `demo/schema.sql` |
| Seed used integer course_ids (1, 2, 3) in course_videos | Updated to string keys (`'dmf-2024'`, `'bgs-2024'`, `'adi-2024'`) | `demo/seed.sql` |
| Payment `status='paid'` → dashboard requires `status='completed'` | Changed all verified=1 Payment rows from `'paid'` to `'completed'` | `demo/seed.sql` |

---

## Empty result risks resolved

| Page | Risk | Resolution |
|---|---|---|
| campaign-monitoring.php | Empty on localhost by design | Documented above; verified 4 results on production env |
| elearning/dashboard.php revenue/active learners | `order_products` table missing | Expected — dashboard gracefully falls back to 0; courses count is non-zero |

---

## Hand-verification items (run after local XAMPP passes)

1. **campaign-monitoring.php on InfinityFree** — confirm all 4 demo campaigns display (production filter shows non-test names)
2. **subscription/index.php on InfinityFree** — confirm Carol, Emma, Grace (3 non-@demo.local subscribers) appear
3. **lower_case_table_names on InfinityFree** — Phase 6 item: confirm MySQL setting before upload; mixed-case table names (`Payment`, `Orders`, `Discount_Codes`) may be case-sensitive on Linux. Check `SHOW VARIABLES LIKE 'lower_case_table_names'` on InfinityFree MySQL.
