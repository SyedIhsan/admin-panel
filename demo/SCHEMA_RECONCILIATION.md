# Schema Reconciliation — Phase 3 Step 2
Generated: 2026-05-22

Source of truth: codebase grep + `api/mail/campaign-helpers.php` DDL + `payment/start-payment.php` ALTER history.
Skeleton reference: `starter-kit/demo/schema.skeleton.sql` (starting guess only).

---

## Tables in skeleton but unused in code → DROP

| Table (skeleton name) | Reason |
|---|---|
| `admin_notifications` | The notification bell (`admin/api/notifications.php`) computes live counts by querying `Payment` and `user_progress` directly. No `admin_notifications` table is referenced anywhere in PHP. |

---

## Tables in skeleton with wrong names → RENAME to code name

The skeleton uses simplified/generic names; the actual codebase uses these exact identifiers.

| Skeleton name | Code name | Notes |
|---|---|---|
| `products` | `Products` | Capital P |
| `transactions` | `Payment` | Entirely different name — the transaction log table is called `Payment` |
| `discounts` | `Discount_Codes` | Different name entirely |
| `subscriptions` | `Subscriptions` | Capital S |
| `subscription_billing_history` | `Subscription_Billing_History` | Title case |
| `webinars` | `sdc_webinars` | `sdc_` prefix |
| `webinar_registrations` | `sdc_webinar_registrations` | `sdc_` prefix |
| `webinar_reminder_logs` | `sdc_webinar_reminders` | Different suffix |
| `webinar_marketing_campaigns` | `sdc_webinar_marketing_emails` | Different name |
| `students` | `user` | Entirely different name for the e-learning user table |
| `student_progress` | `user_progress` | Different name |
| `email_audience_members` | `email_audience_group_members` | `_group_` inserted |

---

## Tables used in code but missing from skeleton → ADD

| Table | Inferred from |
|---|---|
| `Orders` | `payment/start-payment.php` — INSERT with 19 base cols + 13 optional cols added via ALTER; queried by `process-renewal.php` / `process-retention.php` for discount snapshots |
| `Product_Categories` | `admin/payment/product-form.php`, `admin/payment/admin-products.php`, `payment/start-payment.php` — product variant/pricing system; every product has 1+ categories |
| `Discount_Redemptions` | `payment/start-payment.php` — INSERT on each code use; SELECT with `discount_code_id`, `email`, `status` |
| `Subscription_Action_Tokens` | `payment/subscription-pay.php` — SELECT by `token_hash`; cols: `token_hash`, `subscription_id`, `action_type`, `expires_at`, `used_at` |
| `course_certificates` | `admin/elearning/progress.php` — INSERT/SELECT; cols: `user_id`, `course_id`, `cert_no`, `issued_at`, `sent_at`, `sent_to` |
| `course_waitlist` | `admin/elearning/waitlist_notify_lib.php`, `admin/elearning/notify_waitlist.php` — email/level subscription list |
| `course_notify_jobs` | `admin/elearning/waitlist_notify_lib.php` — queue table for waitlist notifications |
| `user_workbooks` | `admin/elearning/progress.php` — referenced in FROM clause; likely tracks student workbook submissions |
| `sdc_webinar_marketing_emails` | `admin/webinar/marketing-form.php` — email sequence per webinar |
| `sdc_webinar_marketing_logs` | `admin/webinar/*.php` — log of sent marketing emails |
| `email_campaign_link_clicks` | `api/mail/campaign-helpers.php` DDL (line 221) |
| `email_campaign_imports` | `api/mail/campaign-helpers.php` DDL (line 239) |
| `email_campaign_send_batches` | `api/mail/campaign-helpers.php` DDL (line 658) |
| `email_campaign_queue_jobs` | `api/mail/campaign-helpers.php` DDL (line 842) |
| `email_campaign_recipients` | `api/mail/campaign-helpers.php` DDL (line 191) |
| `email_audience_group_imports` | `api/mail/campaign-helpers.php` DDL (line 1458) |

---

## Per-table column changes

Only tables where skeleton differs materially from code usage. Tables well-matched (email_logs, email_templates, course_videos, course_ebooks, course_workbooks) are noted as ✓ OK.

### `admins` ✓ OK
Skeleton matches code. Required cols: `id`, `username`, `email`, `password_hash`, `role`, `last_login_at`, `created_at`.

### `Products` (skeleton: `products`)
| Skeleton column | Used in code? | Action |
|---|---|---|
| id | ✓ | keep |
| name | ✓ | keep |
| slug | ✓ | keep |
| description | ✓ | keep |
| type | ✓ (one-time / installment / subscription) | keep |
| full_price | ✓ | keep |
| first_month_price | ✓ | keep |
| monthly_price | ✓ | keep |
| installment_months | ✓ | keep |
| poster_url | ✓ (image for product listing) | keep |
| status | ✓ (active/inactive) | keep |
| created_at / updated_at | ✓ | keep |
| — | base_price, allow_full_payment, allow_installment, installment_count, installment_interval_unit, retention_first_month_price | **ADD** — all referenced in admin-products.php |

### `Product_Categories` (skeleton: missing)
Cols inferred from code: `id`, `product_id`, `price_modifier` DECIMAL, `variant_type` VARCHAR(20), `elearning_course_id` VARCHAR(60), `is_subscription` TINYINT(1), `allow_full_payment` TINYINT(1), `allow_installment` TINYINT(1), `installment_count` INT, `installment_interval_unit` VARCHAR(20), `status` VARCHAR(20), `created_at`.

### `Payment` (skeleton: `transactions`)
The skeleton's `transactions` is a simplified guess. Authoritative column list from `start-payment.php` INSERT (base) + ALTER (added):

| Column | Type | Source |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | implied |
| codeid | VARCHAR(60) | INSERT base |
| product_type | VARCHAR(60) | INSERT base (stores product_id) |
| product_category_id | VARCHAR(60) | INSERT base |
| variant_type | VARCHAR(20) | INSERT base |
| elearning_course_id | VARCHAR(60) | INSERT base |
| name | VARCHAR(190) | INSERT base |
| email | VARCHAR(190) | INSERT base |
| phone | VARCHAR(60) | INSERT base |
| item | VARCHAR(255) | INSERT base (product name) |
| package | VARCHAR(190) | INSERT base (variant/package name) |
| channel | VARCHAR(60) | INSERT base (payment channel) |
| price | DECIMAL(10,2) | INSERT base |
| transaction_id | VARCHAR(120) | INSERT base (unique, gateway ref) |
| sid | VARCHAR(60) | INSERT base (member code) |
| referred_by | VARCHAR(60) | INSERT base |
| status | VARCHAR(20) | INSERT base + ALTER |
| verified | TINYINT(1) | INSERT base + ALTER |
| discount_code | VARCHAR(64) | INSERT base + ALTER |
| discount_amount | DECIMAL(10,2) | INSERT base + ALTER |
| subtotal_before_discount | DECIMAL(10,2) | INSERT base + ALTER |
| transaction_ref | VARCHAR(120) | ALTER |
| subscription_id | BIGINT UNSIGNED | ALTER |
| subscription_mode | VARCHAR(20) | ALTER |
| is_subscription | TINYINT(1) | ALTER |
| duration_value | INT | ALTER |
| duration_unit | VARCHAR(20) | ALTER |
| first_month_price | DECIMAL(10,2) | ALTER |
| remaining_month_price | DECIMAL(10,2) | ALTER |
| retention_price | DECIMAL(10,2) | ALTER |
| discount_code_id | BIGINT UNSIGNED | ALTER |
| renewal_discount_type_snapshot | VARCHAR(20) | ALTER |
| renewal_discount_value_snapshot | DECIMAL(10,2) | ALTER |
| retention_discount_type_snapshot | VARCHAR(20) | ALTER |
| retention_discount_value_snapshot | DECIMAL(10,2) | ALTER |
| timestamp | DATETIME DEFAULT CURRENT_TIMESTAMP | base (ORDER BY timestamp seen in dashboard) |

### `Orders` (skeleton: missing)
Base cols from `start-payment.php` INSERT (always included):

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT |
| order_id | VARCHAR(120) UNIQUE (the DEMO-XXXX / gateway order ref) |
| codeid | VARCHAR(60) |
| product_id | VARCHAR(60) |
| category_id | VARCHAR(60) |
| product_name | VARCHAR(255) |
| variant | VARCHAR(190) |
| subtotal | DECIMAL(10,2) |
| amount | DECIMAL(10,2) |
| name | VARCHAR(190) |
| email | VARCHAR(190) |
| phone | VARCHAR(60) |
| mode | VARCHAR(20) |
| status | VARCHAR(20) |
| referred_by | VARCHAR(60) |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |
| transaction_id | VARCHAR(120) (FK to Payment.transaction_id) |
| discount_code | VARCHAR(64) |
| discount_amount | DECIMAL(10,2) |
| subtotal_before_discount | DECIMAL(10,2) |

Plus ALTER-added optional cols (same set as Payment): `subscription_id`, `subscription_mode`, `is_subscription`, `duration_value`, `duration_unit`, `first_month_price`, `remaining_month_price`, `retention_price`, `discount_code_id`, and four discount snapshot cols.

### `Discount_Codes` (skeleton: `discounts`)
| Skeleton column | Used in code? | Action |
|---|---|---|
| id | ✓ | keep |
| code | ✓ (UNIQUE) | keep |
| status | ✓ (active/inactive/expired) | keep |
| product_id | ✓ | keep |
| discount_type | ✓ (percent/fixed) | keep |
| discount_value | ✓ DECIMAL | keep |
| valid_from / valid_until | ✓ DATETIME | keep |
| max_redemptions | ✓ INT | keep |
| — | category_id, per_email_limit, allowed_email | **ADD** — referenced in payment/start-payment.php discount validation |

### `Discount_Redemptions` (skeleton: missing)
Cols: `id`, `discount_code_id` BIGINT UNSIGNED, `email` VARCHAR(190), `status` VARCHAR(20), `created_at`.

### `Subscriptions` (skeleton: `subscriptions`)
| Skeleton column | Used in code? | Action |
|---|---|---|
| id | ✓ | keep |
| customer_email | ✓ | keep |
| customer_name | ✓ | keep |
| product_id | ✓ | keep |
| status | ✓ (active/past_due/cancelled/paused) | keep |
| amount | ✓ DECIMAL | keep |
| start_date | ✓ DATE | keep |
| next_billing_date | ✓ DATE | keep |
| cancelled_at | ✓ DATETIME | keep |
| created_at | ✓ | keep |
| — | gateway, gateway_subscription_id, product_category_id, remaining_month_price, retention_price | **ADD** — all referenced in subscription detail page and billing logic |

### `Subscription_Billing_History` (skeleton: simplified)
Cols from code: `id`, `subscription_id`, `payment_id` BIGINT, `order_id` VARCHAR(120), `transaction_ref` VARCHAR(120), `amount` DECIMAL(10,2), `status` VARCHAR(20), `billed_at` DATETIME, `notes` TEXT, `created_at`.

### `Subscription_Action_Tokens` (skeleton: missing)
Cols: `id`, `token_hash` VARCHAR(64) UNIQUE, `subscription_id` BIGINT UNSIGNED, `action_type` VARCHAR(20), `expires_at` DATETIME, `used_at` DATETIME NULL, `created_at`.

### `sdc_webinars` (skeleton: `webinars`)
Cols from code: `id`, `webinar_title` VARCHAR(255), `webinar_desc` TEXT, `start_datetime` DATETIME, `end_datetime` DATETIME, `timezone` VARCHAR(60), `poster_url` VARCHAR(255), `zoom_join_url` VARCHAR(255), `email_subject` VARCHAR(255), `status` VARCHAR(20), `capacity` INT, `recording_url` VARCHAR(255), `created_at`.

### `sdc_webinar_registrations` (skeleton: `webinar_registrations`)
Cols: `id`, `webinar_id` BIGINT UNSIGNED, `name` VARCHAR(190), `email` VARCHAR(190), `phone` VARCHAR(60), `consent` TINYINT(1), `attended` TINYINT(1) DEFAULT 0, `registered_at` DATETIME. UNIQUE KEY `(webinar_id, email)`.

### `sdc_webinar_reminders` (skeleton: `webinar_reminder_logs`)
Cols: `id`, `webinar_id` BIGINT UNSIGNED, `registration_id` BIGINT UNSIGNED, `reminder_type` VARCHAR(30), `sent_at` DATETIME, `status` VARCHAR(20).

### `sdc_webinar_marketing_emails` (skeleton: `webinar_marketing_campaigns`)
Cols from admin/webinar/marketing-form.php: `id`, `webinar_id` BIGINT, `title` VARCHAR(255), `subject` VARCHAR(255), `body_html` MEDIUMTEXT, `delay_value` INT, `delay_unit` VARCHAR(20), `send_before_webinar_only` TINYINT(1), `apply_to_existing` TINYINT(1), `status` VARCHAR(20), `sort_order` INT, `created_at`.

### `sdc_webinar_marketing_logs` ✓ skeleton-compatible
Cols: `id`, `webinar_id`, `marketing_email_id`, `recipient` VARCHAR(190), `status` VARCHAR(20), `event_at` DATETIME.

### `courses` (skeleton: `courses` ✓ name OK)
Cols from code: `id`, `title` VARCHAR(255), `slug` VARCHAR(255), `description` TEXT, `level` VARCHAR(20), `price` DECIMAL(10,2), `original_price` DECIMAL(10,2), `duration` VARCHAR(60), `instructor` VARCHAR(190), `image` VARCHAR(255), `cover_url` VARCHAR(255), `status` VARCHAR(20), `created_at`, `updated_at`. Note: code uses a `key` field for `course_key` (used in waitlist logic). **ADD** `course_key` VARCHAR(120) UNIQUE.

### `course_videos` ✓ OK
Cols: `id`, `course_id`, `title`, `url`, `description`, `duration_sec` INT, `order_index` INT, `drive_file_id` VARCHAR(255), `created_at`.

### `course_ebooks` ✓ OK
Cols: `id`, `course_id`, `title`, `content` MEDIUMTEXT, `drive_file_id` VARCHAR(255), `order_index` INT, `created_at`.

### `course_workbooks` ✓ OK
Cols: `id`, `course_id`, `title`, `url` TEXT, `template_file_id` VARCHAR(255), `sheet_id` VARCHAR(255), `content_id` VARCHAR(255), `created_at`.

### `course_certificates`
Cols: `id`, `user_id` BIGINT UNSIGNED, `course_id` VARCHAR(60), `cert_no` VARCHAR(60) UNIQUE, `issued_at` DATETIME, `sent_at` DATETIME NULL, `sent_to` VARCHAR(190) NULL.

### `course_waitlist`
Cols: `id`, `email` VARCHAR(190), `level` VARCHAR(20), `token` VARCHAR(128) UNIQUE, `status` VARCHAR(20) DEFAULT 'subscribed', `last_notified_at` DATETIME NULL, `last_notified_course_key` VARCHAR(120) NULL, `created_at`. UNIQUE KEY `(email, level)`.

### `course_notify_jobs`
Cols: `id`, `level` VARCHAR(20), `course_key` VARCHAR(120), `course_title` VARCHAR(255), `course_url` VARCHAR(255), `status` VARCHAR(20) DEFAULT 'pending', `created_at`. UNIQUE KEY `(level, course_key)`.

### `user` (skeleton: `students`)
Cols: `id` BIGINT UNSIGNED AUTO_INCREMENT, `name` VARCHAR(190), `email` VARCHAR(190) UNIQUE, `enrolled_at` DATETIME, `created_at`.

### `user_progress`
Cols: `id`, `user_id` BIGINT UNSIGNED, `course_id` VARCHAR(60), `product_name` VARCHAR(255), `completed` TINYINT(1) DEFAULT 0, `completed_at` DATETIME NULL, `created_at`. KEY `(user_id, course_id)`.

### `user_workbooks`
Cols: inferred as `id`, `user_id` BIGINT UNSIGNED, `course_id` VARCHAR(60), `workbook_id` BIGINT UNSIGNED, `submission_url` TEXT, `submitted_at` DATETIME, `created_at`. (See UNSURE #3.)

### Email campaign tables — authoritative DDL from `campaign-helpers.php`
`email_campaigns`, `email_campaign_recipients`, `email_campaign_link_clicks`, `email_campaign_imports`, `email_campaign_send_batches`, `email_campaign_queue_jobs`, `email_campaign_schedules`, `email_audience_groups`, `email_audience_group_members`, `email_audience_group_imports` — all defined verbatim in `api/mail/campaign-helpers.php`. Use that DDL as-is.

### `email_logs` (simplified, for demo display only)
The `admin/email/email-logs.php` page queries this. Cols: `id`, `campaign_id` BIGINT UNSIGNED, `recipient_email` VARCHAR(190), `status` VARCHAR(30), `sent_at` DATETIME, `opened_at` DATETIME NULL, `clicked_at` DATETIME NULL, `bounce_reason` TEXT NULL, `tracking_pixel_id` VARCHAR(128), `created_at`.

### `email_templates`
Cols: `id`, `name` VARCHAR(255), `subject` VARCHAR(255), `body_html` MEDIUMTEXT, `category` VARCHAR(60), `product_category_id` VARCHAR(60) NULL, `created_at`, `updated_at`.

---

## Type inference notes

| Column | Inferred type | Reasoning |
|---|---|---|
| `Payment.price` | DECIMAL(10,2) | Arithmetic in dashboard SUM queries; RM currency |
| `Payment.timestamp` | DATETIME | ORDER BY timestamp, date comparisons in dashboard |
| `Payment.status` | VARCHAR(20) | LIKE '%completed%', equality checks; not ENUM (ALTER history adds values) |
| `Payment.verified` | TINYINT(1) | `COALESCE(verified,0) = 1` — boolean |
| `Orders.subtotal` | DECIMAL(10,2) | Currency arithmetic |
| `Subscriptions.start_date` | DATE | Date-only comparisons; no time component seen |
| `Subscriptions.next_billing_date` | DATE | Same |
| `course_waitlist.token` | VARCHAR(128) | Token for unsubscribe URL |
| `Subscription_Action_Tokens.token_hash` | VARCHAR(64) | `hash('sha256', $token)` → 64 hex chars |
| `Subscription_Action_Tokens.action_type` | VARCHAR(20) | Values: 'installment', 'retention', 'renewal' |
| `Product_Categories.variant_type` | VARCHAR(20) | Values: 'normal', 'elearning', 'subscription' seen in code |
| `email_logs.status` | VARCHAR(30) | Values: 'delivered', 'bounced', 'failed', 'opened', 'clicked' — VARCHAR not ENUM to allow future extension |

---

## UNSURE — needs your decision before writing schema

**1. `Payment` table — original base schema**
The `start-payment.php` ALTER history shows columns being added but never shows the original CREATE TABLE. The base INSERT always includes `codeid`, `product_type`, `product_category_id`, `variant_type`, `elearning_course_id`, `name`, `email`, `phone`, `item`, `package`, `channel`, `price`, `transaction_id`, `sid`, `referred_by`, `status`, `verified`. There is likely an `id` PK and a `timestamp` created_at. Are there any other columns in the production table I should know about? (If not, I'll define the full set from the ALTER history, which is authoritative enough.)

**2. `Orders` table — relationship to `Payment`**
`Orders` is inserted simultaneously with `Payment` (same transaction in start-payment.php). The `Orders.transaction_id` column appears to reference `Payment.transaction_id`. For the demo, should I seed `Orders` rows alongside `Payment` rows (1:1 relationship), or treat `Orders` as a legacy table that's rarely consulted? Code guards it with `tableExists()` checks, suggesting it may be optional.

**3. `user_workbooks` — column list**
The table is referenced in a `FROM user_workbooks` clause in `progress.php` but no INSERT or specific SELECT columns are grep-visible. If you can tell me the column list, I'll include it; otherwise I'll use a minimal stub (`id`, `user_id`, `course_id`, `workbook_id`, `submission_url TEXT`, `submitted_at DATETIME`, `created_at`).

**4. `Subscriptions` — `product_category_id` vs `product_id`**
Code references both `product_id` and (in some paths) `product_category_id` on the Subscriptions table. Should each subscription row track the specific category variant purchased, or just the parent product?

---

## Summary counts

| Category | Count |
|---|---|
| Tables DROP (unused) | 1 (`admin_notifications`) |
| Tables RENAME (wrong name in skeleton) | 12 |
| Tables ADD (missing from skeleton) | 16 |
| Tables ✓ OK (name + columns match) | 9 |
| **Total tables in final schema** | **37** |
