-- =============================================================================
-- DEMO SCHEMA — Phase 3
-- =============================================================================
-- Single-file schema for the portfolio demo DB.
-- All ensure*() ALTER columns are folded in — no runtime migrations needed.
-- MySQL 5.7 compatible: utf8mb4, InnoDB, no JSON CHECK, no window functions.
-- No FK constraints (InfinityFree compatibility).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+08:00';

-- =============================================================================
-- ADMIN / AUTH
-- =============================================================================

CREATE TABLE `admins` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64) NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          VARCHAR(32) NOT NULL DEFAULT 'admin',
    `last_login_at` DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PAYMENT MODULE
-- =============================================================================

-- Products — base cols + ensure* ALTER cols folded in
CREATE TABLE `Products` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                          VARCHAR(190) NOT NULL,
    `base_price`                    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`                        VARCHAR(20) NOT NULL DEFAULT 'active',
    `has_categories`                TINYINT(1) NOT NULL DEFAULT 0,
    `description`                   TEXT NULL,
    `poster`                        VARCHAR(255) NULL,
    `membership_types`              VARCHAR(190) NULL,
    `elearning_course_id`           VARCHAR(60) NULL,
    `is_subscription`               TINYINT(1) NOT NULL DEFAULT 0,
    `duration_value`                INT NULL,
    `duration_unit`                 VARCHAR(20) NULL,
    `first_month_price`             DECIMAL(10,2) NULL,
    `remaining_month_price`         DECIMAL(10,2) NULL,
    `retention_price`               DECIMAL(10,2) NULL,
    `retention_first_month_price`   DECIMAL(10,2) NULL,
    `allow_full_payment`            TINYINT(1) NOT NULL DEFAULT 1,
    `allow_installment`             TINYINT(1) NOT NULL DEFAULT 0,
    `installment_count`             INT NULL,
    `installment_interval_unit`     VARCHAR(20) NULL DEFAULT 'month',
    `currency`                      VARCHAR(10) NOT NULL DEFAULT 'MYR',
    `created_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_products_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product_Categories — variants per product + ensure* ALTER cols folded in
CREATE TABLE `Product_Categories` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`                    INT UNSIGNED NOT NULL,
    `sort_order`                    INT UNSIGNED NOT NULL DEFAULT 0,
    `name`                          VARCHAR(190) NOT NULL,
    `price_modifier`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `variant_type`                  VARCHAR(20) NOT NULL DEFAULT 'normal',
    `elearning_course_id`           VARCHAR(60) NULL,
    `is_subscription`               TINYINT(1) NULL,
    `duration_value`                INT NULL,
    `duration_unit`                 VARCHAR(20) NULL,
    `first_month_price`             DECIMAL(10,2) NULL,
    `remaining_month_price`         DECIMAL(10,2) NULL,
    `retention_price`               DECIMAL(10,2) NULL,
    `retention_first_month_price`   DECIMAL(10,2) NULL,
    `allow_full_payment`            TINYINT(1) NULL,
    `allow_installment`             TINYINT(1) NULL,
    `installment_count`             INT NULL,
    `installment_interval_unit`     VARCHAR(20) NULL,
    `status`                        VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cat_product_id` (`product_id`),
    KEY `idx_cat_sort` (`product_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment — all base INSERT cols + all ensure* ALTER cols folded in (36 cols total)
-- CRITICAL: id is INT (not BIGINT) — payment callbacks use INSERT/SELECT on this type
CREATE TABLE `Payment` (
    `id`                                INT NOT NULL AUTO_INCREMENT,
    `codeid`                            VARCHAR(60) NOT NULL DEFAULT '',
    `product_type`                      VARCHAR(60) NULL,
    `product_category_id`               VARCHAR(60) NULL,
    `variant_type`                      VARCHAR(20) NOT NULL DEFAULT 'normal',
    `elearning_course_id`               VARCHAR(60) NULL,
    `name`                              VARCHAR(255) NOT NULL DEFAULT '',
    `email`                             VARCHAR(255) NOT NULL DEFAULT '',
    `phone`                             VARCHAR(60) NOT NULL DEFAULT '',
    `item`                              VARCHAR(255) NOT NULL DEFAULT '',
    `package`                           VARCHAR(190) NOT NULL DEFAULT '',
    `channel`                           VARCHAR(60) NOT NULL DEFAULT '',
    `price`                             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `transaction_id`                    VARCHAR(120) NOT NULL,
    `sid`                               VARCHAR(60) NOT NULL DEFAULT '',
    `referred_by`                       VARCHAR(60) NOT NULL DEFAULT '',
    `status`                            VARCHAR(20) NOT NULL DEFAULT 'pending',
    `verified`                          TINYINT(1) NOT NULL DEFAULT 0,
    `discount_code`                     VARCHAR(64) NULL,
    `discount_amount`                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `subtotal_before_discount`          DECIMAL(10,2) NULL,
    `transaction_ref`                   VARCHAR(120) NULL,
    `subscription_id`                   BIGINT UNSIGNED NULL,
    `subscription_mode`                 VARCHAR(20) NULL,
    `is_subscription`                   TINYINT(1) NOT NULL DEFAULT 0,
    `duration_value`                    INT NULL,
    `duration_unit`                     VARCHAR(20) NULL,
    `first_month_price`                 DECIMAL(10,2) NULL,
    `remaining_month_price`             DECIMAL(10,2) NULL,
    `retention_price`                   DECIMAL(10,2) NULL,
    `discount_code_id`                  INT UNSIGNED NULL,
    `renewal_discount_type_snapshot`    VARCHAR(20) NULL,
    `renewal_discount_value_snapshot`   DECIMAL(10,2) NULL,
    `retention_discount_type_snapshot`  VARCHAR(20) NULL,
    `retention_discount_value_snapshot` DECIMAL(10,2) NULL,
    `timestamp`                         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_transaction_id` (`transaction_id`),
    KEY `idx_payment_email` (`email`),
    KEY `idx_payment_status` (`status`),
    KEY `idx_payment_timestamp` (`timestamp`),
    KEY `idx_payment_subscription_id` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders — all base INSERT cols + meta JSON + all ensure* ALTER cols folded in (34 cols total)
-- CRITICAL: id is BIGINT UNSIGNED
CREATE TABLE `Orders` (
    `id`                                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`                          VARCHAR(120) NOT NULL,
    `codeid`                            VARCHAR(60) NOT NULL DEFAULT '',
    `product_id`                        VARCHAR(60) NOT NULL DEFAULT '',
    `category_id`                       VARCHAR(60) NULL,
    `product_name`                      VARCHAR(255) NOT NULL DEFAULT '',
    `variant`                           VARCHAR(190) NOT NULL DEFAULT '',
    `subtotal`                          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `amount`                            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `name`                              VARCHAR(190) NOT NULL DEFAULT '',
    `email`                             VARCHAR(190) NOT NULL DEFAULT '',
    `phone`                             VARCHAR(60) NOT NULL DEFAULT '',
    `mode`                              VARCHAR(20) NOT NULL DEFAULT 'live',
    `status`                            VARCHAR(20) NOT NULL DEFAULT 'pending',
    `referred_by`                       VARCHAR(60) NOT NULL DEFAULT '',
    `created_at`                        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `transaction_id`                    VARCHAR(120) NULL,
    `discount_code`                     VARCHAR(64) NULL,
    `discount_amount`                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `subtotal_before_discount`          DECIMAL(10,2) NULL,
    `meta`                              JSON NULL,
    `subscription_id`                   BIGINT UNSIGNED NULL,
    `subscription_mode`                 VARCHAR(20) NULL,
    `is_subscription`                   TINYINT(1) NOT NULL DEFAULT 0,
    `duration_value`                    INT NULL,
    `duration_unit`                     VARCHAR(20) NULL,
    `first_month_price`                 DECIMAL(10,2) NULL,
    `remaining_month_price`             DECIMAL(10,2) NULL,
    `retention_price`                   DECIMAL(10,2) NULL,
    `discount_code_id`                  INT UNSIGNED NULL,
    `renewal_discount_type_snapshot`    VARCHAR(20) NULL,
    `renewal_discount_value_snapshot`   DECIMAL(10,2) NULL,
    `retention_discount_type_snapshot`  VARCHAR(20) NULL,
    `retention_discount_value_snapshot` DECIMAL(10,2) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_order_id` (`order_id`),
    KEY `idx_orders_email` (`email`),
    KEY `idx_orders_status` (`status`),
    KEY `idx_orders_created_at` (`created_at`),
    KEY `idx_orders_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- order_products: compatibility table for progress.php (VIEW not available on shared hosts)
-- Populated from Orders during seed; product_type reserved for non-Orders sources.
CREATE TABLE `order_products` (
    `id`             BIGINT UNSIGNED NOT NULL,
    `customer_email` VARCHAR(190)    NULL,
    `status`         VARCHAR(20)     NULL,
    `product_name`   VARCHAR(255)    NULL,
    `amount`         DECIMAL(10,2)   NULL,
    `product_type`   VARCHAR(60)     NULL,
    `created_at`     DATETIME        NULL,
    PRIMARY KEY (`id`),
    KEY `idx_op_email`  (`customer_email`),
    KEY `idx_op_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discount_Codes — includes renewal/retention snapshot columns
CREATE TABLE `Discount_Codes` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`                      VARCHAR(64) NOT NULL,
    `discount_type`             VARCHAR(20) NOT NULL DEFAULT 'percent',
    `discount_value`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `product_id`                VARCHAR(60) NULL,
    `category_id`               VARCHAR(60) NULL,
    `allowed_email`             TEXT NULL,
    `valid_from`                DATETIME NULL,
    `valid_until`               DATETIME NULL,
    `max_redemptions`           INT UNSIGNED NULL,
    `per_email_limit`           INT UNSIGNED NOT NULL DEFAULT 1,
    `status`                    VARCHAR(20) NOT NULL DEFAULT 'active',
    `renewal_discount_type`     VARCHAR(20) NULL,
    `renewal_discount_value`    DECIMAL(10,2) NULL,
    `retention_discount_type`   VARCHAR(20) NULL,
    `retention_discount_value`  DECIMAL(10,2) NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_dc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discount_Redemptions
CREATE TABLE `Discount_Redemptions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `discount_code_id`  INT UNSIGNED NOT NULL,
    `email`             VARCHAR(190) NOT NULL,
    `order_id`          VARCHAR(120) NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dr_code_email` (`discount_code_id`, `email`),
    KEY `idx_dr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SUBSCRIPTION MODULE
-- =============================================================================

CREATE TABLE `Subscriptions` (
    `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscription_no`           VARCHAR(120) NULL,
    `customer_name`             VARCHAR(190) NOT NULL DEFAULT '',
    `customer_email`            VARCHAR(190) NOT NULL DEFAULT '',
    `product_id`                VARCHAR(60) NOT NULL DEFAULT '',
    `product_category_id`       VARCHAR(60) NULL,
    `status`                    VARCHAR(20) NOT NULL DEFAULT 'active',
    `amount`                    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `gateway`                   VARCHAR(40) NULL,
    `gateway_subscription_id`   VARCHAR(190) NULL,
    `start_date`                DATE NULL,
    `expiry_date`               DATE NULL,
    `next_renewal_date`         DATE NULL,
    `cancelled_at`              DATETIME NULL,
    `last_paid_at`              DATETIME NULL,
    `renewal_count`             INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_value`            INT NULL,
    `duration_unit`             VARCHAR(20) NULL DEFAULT 'month',
    `remaining_month_price`     DECIMAL(10,2) NULL,
    `retention_price`           DECIMAL(10,2) NULL,
    `product_name_snapshot`     VARCHAR(255) NULL,
    `variant_name_snapshot`     VARCHAR(190) NULL,
    `customer_phone`            VARCHAR(60) NULL,
    `first_month_price`         DECIMAL(10,2) NULL,
    `last_reminder_sent_at`     DATETIME NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sub_email` (`customer_email`),
    KEY `idx_sub_status` (`status`),
    KEY `idx_sub_expiry` (`expiry_date`),
    KEY `idx_sub_no` (`subscription_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Subscription_Action_Tokens` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash`        VARCHAR(64) NOT NULL,
    `subscription_id`   BIGINT UNSIGNED NOT NULL,
    `action_type`       VARCHAR(20) NOT NULL,
    `expires_at`        DATETIME NOT NULL,
    `used_at`           DATETIME NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token_hash` (`token_hash`),
    KEY `idx_sat_subscription_id` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Subscription_Billing_History` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscription_id`   BIGINT UNSIGNED NOT NULL,
    `payment_id`        BIGINT UNSIGNED NULL,
    `order_id`          VARCHAR(120) NULL,
    `transaction_ref`   VARCHAR(120) NULL,
    `amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'pending',
    `billing_type`      VARCHAR(30) NULL,
    `paid_at`           DATETIME NULL,
    `notes`             TEXT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sbh_subscription_id` (`subscription_id`),
    KEY `idx_sbh_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WEBINAR MODULE
-- =============================================================================

CREATE TABLE `sdc_webinars` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_title`     VARCHAR(255) NOT NULL DEFAULT '',
    `webinar_desc`      TEXT NULL,
    `start_datetime`    DATETIME NULL,
    `end_datetime`      DATETIME NULL,
    `timezone`          VARCHAR(60) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
    `poster_url`        VARCHAR(255) NULL,
    `zoom_join_url`     VARCHAR(255) NULL,
    `email_subject`     VARCHAR(255) NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'draft',
    `capacity`          INT UNSIGNED NULL,
    `recording_url`     VARCHAR(255) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_webinar_status` (`status`),
    KEY `idx_webinar_start` (`start_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sdc_webinar_registrations` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`    BIGINT UNSIGNED NOT NULL,
    `name`          VARCHAR(190) NOT NULL DEFAULT '',
    `email`         VARCHAR(190) NOT NULL DEFAULT '',
    `phone`         VARCHAR(60) NULL,
    `consent`       TINYINT(1) NOT NULL DEFAULT 0,
    `attended`      TINYINT(1) NOT NULL DEFAULT 0,
    `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_webinar_email` (`webinar_id`, `email`),
    KEY `idx_wreg_webinar_id` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sdc_webinar_reminders` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`        BIGINT UNSIGNED NOT NULL,
    `registration_id`   BIGINT UNSIGNED NOT NULL,
    `email`             VARCHAR(190) NOT NULL DEFAULT '',
    `reminder_type`     VARCHAR(30) NOT NULL DEFAULT '',
    `due_at`            DATETIME NULL,
    `sent_at`           DATETIME NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'pending',
    `error_message`     TEXT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wrem_webinar_id` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sdc_webinar_marketing_emails` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`                BIGINT UNSIGNED NOT NULL,
    `title`                     VARCHAR(255) NULL,
    `subject`                   VARCHAR(255) NULL,
    `body_html`                 MEDIUMTEXT NULL,
    `delay_value`               INT NULL,
    `delay_unit`                VARCHAR(20) NULL DEFAULT 'hours',
    `send_before_webinar_only`  TINYINT(1) NOT NULL DEFAULT 0,
    `apply_to_existing`         TINYINT(1) NOT NULL DEFAULT 0,
    `status`                    VARCHAR(20) NOT NULL DEFAULT 'active',
    `sort_order`                INT NOT NULL DEFAULT 0,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wme_webinar_id` (`webinar_id`),
    KEY `idx_wme_sort` (`webinar_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sdc_webinar_marketing_logs` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`            BIGINT UNSIGNED NOT NULL,
    `marketing_email_id`    BIGINT UNSIGNED NOT NULL,
    `recipient`             VARCHAR(190) NOT NULL DEFAULT '',
    `status`                VARCHAR(20) NOT NULL DEFAULT 'sent',
    `event_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wml_webinar_id` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- E-LEARNING MODULE
-- =============================================================================

-- courses.id is a VARCHAR admin-defined course key (e.g. 'dmf-2024') —
-- NOT auto_increment. Matches how contents.php, courses.php, and all
-- course_id VARCHAR(60) FK columns in user_progress / course_certificates / user_workbooks work.
CREATE TABLE `courses` (
    `id`                VARCHAR(60) NOT NULL,
    `title`             VARCHAR(255) NOT NULL DEFAULT '',
    `slug`              VARCHAR(255) NULL,
    `description`       TEXT NULL,
    `level`             VARCHAR(20) NULL,
    `price`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `original_price`    DECIMAL(10,2) NULL,
    `duration`          VARCHAR(60) NULL,
    `instructor`        VARCHAR(190) NULL,
    `image`             VARCHAR(255) NULL,
    `cover_url`         VARCHAR(255) NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'published',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_course_status` (`status`),
    KEY `idx_course_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_videos` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`     VARCHAR(60) NOT NULL,
    `title`         VARCHAR(190) NOT NULL DEFAULT '',
    `url`           TEXT NULL,
    `description`   TEXT NULL,
    `duration_sec`  INT UNSIGNED NULL,
    `order_index`   INT UNSIGNED NOT NULL DEFAULT 0,
    `drive_file_id` VARCHAR(255) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cv_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_ebooks` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`     VARCHAR(60) NOT NULL,
    `title`         VARCHAR(190) NOT NULL DEFAULT '',
    `content`       MEDIUMTEXT NULL,
    `drive_file_id` VARCHAR(255) NULL,
    `order_index`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ce_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_workbooks` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`         VARCHAR(60) NOT NULL,
    `title`             VARCHAR(190) NOT NULL DEFAULT '',
    `url`               TEXT NULL,
    `template_file_id`  VARCHAR(255) NULL,
    `sheet_id`          VARCHAR(255) NULL,
    `content_id`        VARCHAR(255) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cw_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_certificates` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `course_id`     VARCHAR(60) NOT NULL DEFAULT '',
    `cert_no`       VARCHAR(60) NOT NULL,
    `issued_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`       DATETIME NULL,
    `sent_to`       VARCHAR(190) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cert_no` (`cert_no`),
    KEY `idx_cc_user_id` (`user_id`),
    KEY `idx_cc_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_waitlist` (
    `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`                     VARCHAR(190) NOT NULL DEFAULT '',
    `level`                     VARCHAR(20) NOT NULL DEFAULT '',
    `token`                     VARCHAR(128) NOT NULL,
    `status`                    VARCHAR(20) NOT NULL DEFAULT 'subscribed',
    `last_notified_at`          DATETIME NULL,
    `last_notified_course_key`  VARCHAR(120) NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    UNIQUE KEY `uniq_email_level` (`email`, `level`),
    KEY `idx_cw_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_notify_jobs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `level`         VARCHAR(20) NOT NULL DEFAULT '',
    `course_key`    VARCHAR(120) NOT NULL DEFAULT '',
    `course_title`  VARCHAR(255) NULL,
    `course_url`    VARCHAR(255) NULL,
    `status`        VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_level_course_key` (`level`, `course_key`),
    KEY `idx_cnj_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user — e-learning student table (collapsed from separate DB in demo)
CREATE TABLE `user` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(190) NOT NULL DEFAULT '',
    `email`         VARCHAR(190) NOT NULL DEFAULT '',
    `usertype`      TINYINT(1) NOT NULL DEFAULT 0,
    `enrolled_at`   DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_email` (`email`),
    KEY `idx_user_usertype` (`usertype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_progress` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `course_id`     VARCHAR(60) NOT NULL DEFAULT '',
    `product_name`  VARCHAR(255) NULL,
    `content_type`  VARCHAR(20) NULL,
    `completed`     TINYINT(1) NOT NULL DEFAULT 0,
    `completed_at`  DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_up_user_course` (`user_id`, `course_id`),
    KEY `idx_up_completed` (`completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_workbooks — stub; SELECT user_id, course_id, workbook_id, user_file_id
CREATE TABLE `user_workbooks` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `course_id`     VARCHAR(60) NOT NULL DEFAULT '',
    `workbook_id`   BIGINT UNSIGNED NOT NULL,
    `user_file_id`  VARCHAR(255) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uw_user_course` (`user_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- EMAIL CAMPAIGN MODULE
-- All tables taken verbatim from api/mail/campaign-helpers.php DDL.
-- ALTER columns from ensure_queue_schema and contentCols folded in here.
-- =============================================================================

CREATE TABLE `email_campaigns` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_uid`          VARCHAR(64) NOT NULL,
    `campaign_name`         VARCHAR(255) NOT NULL,
    `subject`               VARCHAR(255) NULL,
    `template_id`           BIGINT UNSIGNED NULL,
    `campaign_type`         ENUM('manual_blast','automatic_trigger','targeted_campaign') NOT NULL DEFAULT 'manual_blast',
    `target_filter`         LONGTEXT NULL,
    `status`                ENUM('draft','scheduled','queued','sending','sent','partial_failed','failed','cancelled') NOT NULL DEFAULT 'draft',
    `total_recipients`      INT UNSIGNED NOT NULL DEFAULT 0,
    `sent_count`            INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `delivered_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `bounced_count`         INT UNSIGNED NOT NULL DEFAULT 0,
    `complaint_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `opened_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `clicked_count`         INT UNSIGNED NOT NULL DEFAULT 0,
    `total_open_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `total_click_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `open_rate`             DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `click_rate`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `created_by`            VARCHAR(190) NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `sent_at`               DATETIME NULL,
    `scheduled_for`         DATETIME NULL,
    -- content cols (from campaign_ensure_schema contentCols)
    `preheader`             VARCHAR(255) NULL,
    `email_body`            MEDIUMTEXT NULL,
    `button_text`           VARCHAR(120) NULL,
    `button_url`            TEXT NULL,
    `closing_text`          TEXT NULL,
    `brand_name`            VARCHAR(190) NULL,
    `support_email`         VARCHAR(190) NULL,
    `footer_note`           TEXT NULL,
    `content_updated_at`    DATETIME NULL,
    `content_updated_by`    VARCHAR(190) NULL,
    -- queue cols (from campaign_ensure_queue_schema)
    `queue_status`          ENUM('none','queued','processing','paused','completed','cancelled','error') NOT NULL DEFAULT 'none',
    `queue_started_at`      DATETIME NULL,
    `queue_completed_at`    DATETIME NULL,
    `queue_last_run_at`     DATETIME NULL,
    `queue_error_message`   TEXT NULL,
    UNIQUE KEY `uniq_campaign_uid` (`campaign_uid`),
    KEY `idx_campaign_status` (`status`),
    KEY `idx_campaign_name` (`campaign_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_recipients` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`           BIGINT UNSIGNED NOT NULL,
    `recipient_email`       VARCHAR(255) NOT NULL,
    `recipient_name`        VARCHAR(255) NULL,
    `recipient_phone`       VARCHAR(80) NULL,
    `recipient_id`          VARCHAR(100) NULL,
    `email_log_id`          BIGINT UNSIGNED NULL,
    `tracking_token`        VARCHAR(128) NOT NULL,
    `delivery_status`       ENUM('pending','queued','sent','failed','delivered','bounced','spam','complained','unsubscribed') NOT NULL DEFAULT 'pending',
    `failed_reason`         TEXT NULL,
    `opened`                TINYINT(1) NOT NULL DEFAULT 0,
    `first_open_at`         DATETIME NULL,
    `last_open_at`          DATETIME NULL,
    `open_count`            INT UNSIGNED NOT NULL DEFAULT 0,
    `clicked`               TINYINT(1) NOT NULL DEFAULT 0,
    `first_click_at`        DATETIME NULL,
    `last_click_at`         DATETIME NULL,
    `click_count`           INT UNSIGNED NOT NULL DEFAULT 0,
    `provider_message_id`   VARCHAR(255) NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_campaign_recipient_email` (`campaign_id`, `recipient_email`),
    UNIQUE KEY `uniq_tracking_token` (`tracking_token`),
    KEY `idx_tracking_token` (`tracking_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_link_clicks` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`   BIGINT UNSIGNED NOT NULL,
    `recipient_id`  BIGINT UNSIGNED NOT NULL,
    `link_url`      TEXT NOT NULL,
    `link_hash`     VARCHAR(64) NOT NULL,
    `link_position` INT UNSIGNED NULL,
    `clicked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_agent`    TEXT NULL,
    `ip_hash`       VARCHAR(128) NULL,
    `meta_json`     LONGTEXT NULL,
    KEY `idx_click_recipient` (`recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_imports` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`       BIGINT UNSIGNED NULL,
    `imported_by`       VARCHAR(190) NULL,
    `import_file_hash`  VARCHAR(64) NULL,
    `original_filename` VARCHAR(255) NULL,
    `source_name`       VARCHAR(190) NULL,
    `total_rows`        INT UNSIGNED NOT NULL DEFAULT 0,
    `imported_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
    `error_rows`        INT UNSIGNED NOT NULL DEFAULT 0,
    `error_message`     TEXT NULL,
    `status`            ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_send_batches` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_uid`         VARCHAR(64) NOT NULL,
    `campaign_id`       BIGINT UNSIGNED NOT NULL,
    `send_mode`         ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
    `initiated_by`      VARCHAR(190) NULL,
    `batch_size`        INT UNSIGNED NOT NULL DEFAULT 25,
    `attempted_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `sent_count`        INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `remaining_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `status`            ENUM('started','completed','partial_failed','failed') NOT NULL DEFAULT 'started',
    `error_message`     TEXT NULL,
    `meta_json`         LONGTEXT NULL,
    `started_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL,
    UNIQUE KEY `uniq_batch_uid` (`batch_uid`),
    KEY `idx_batch_campaign_id` (`campaign_id`),
    KEY `idx_batch_status` (`status`),
    KEY `idx_batch_started_at` (`started_at`),
    KEY `idx_batch_send_mode` (`send_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_queue_jobs` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_uid`               VARCHAR(64) NOT NULL,
    `campaign_id`           BIGINT UNSIGNED NOT NULL,
    `send_mode`             ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
    `status`                ENUM('queued','processing','completed','paused','cancelled','error') NOT NULL DEFAULT 'queued',
    `batch_size`            INT UNSIGNED NOT NULL DEFAULT 25,
    `max_per_run`           INT UNSIGNED NOT NULL DEFAULT 100,
    `initiated_by`          VARCHAR(190) NULL,
    `total_target_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `processed_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `sent_count`            INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_count`         INT UNSIGNED NOT NULL DEFAULT 0,
    `remaining_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_at`             DATETIME NULL,
    `locked_by`             VARCHAR(190) NULL,
    `last_run_at`           DATETIME NULL,
    `completed_at`          DATETIME NULL,
    `error_message`         TEXT NULL,
    `meta_json`             LONGTEXT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_job_uid` (`job_uid`),
    KEY `idx_queue_campaign_id` (`campaign_id`),
    KEY `idx_queue_status` (`status`),
    KEY `idx_queue_last_run_at` (`last_run_at`),
    KEY `idx_queue_locked_at` (`locked_at`),
    KEY `idx_queue_send_mode` (`send_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_campaign_schedules` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `schedule_uid`  VARCHAR(64) NOT NULL,
    `campaign_id`   BIGINT UNSIGNED NOT NULL,
    `schedule_name` VARCHAR(190) NULL,
    `scheduled_at`  DATETIME NOT NULL,
    `timezone`      VARCHAR(80) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
    `send_mode`     ENUM('pending','failed','pending_and_failed') NOT NULL DEFAULT 'pending',
    `batch_size`    INT UNSIGNED NOT NULL DEFAULT 25,
    `max_per_run`   INT UNSIGNED NOT NULL DEFAULT 100,
    `status`        ENUM('scheduled','queued','processing','completed','cancelled','missed','error') NOT NULL DEFAULT 'scheduled',
    `queue_job_id`  BIGINT UNSIGNED NULL,
    `created_by`    VARCHAR(190) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `queued_at`     DATETIME NULL,
    `completed_at`  DATETIME NULL,
    `cancelled_at`  DATETIME NULL,
    `error_message` TEXT NULL,
    `meta_json`     LONGTEXT NULL,
    UNIQUE KEY `uniq_schedule_uid` (`schedule_uid`),
    KEY `idx_schedule_campaign_id` (`campaign_id`),
    KEY `idx_schedule_status` (`status`),
    KEY `idx_schedule_scheduled_at` (`scheduled_at`),
    KEY `idx_schedule_queue_job_id` (`queue_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_audience_groups` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_uid`             VARCHAR(64) NOT NULL,
    `group_name`            VARCHAR(190) NOT NULL,
    `description`           TEXT NULL,
    `source_type`           ENUM('manual','csv_import','lead_table','mixed') NOT NULL DEFAULT 'manual',
    `total_members`         INT UNSIGNED NOT NULL DEFAULT 0,
    `active_members`        INT UNSIGNED NOT NULL DEFAULT 0,
    `unsubscribed_members`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_by`            VARCHAR(190) NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_group_uid` (`group_uid`),
    KEY `idx_group_name` (`group_name`),
    KEY `idx_group_source_type` (`source_type`),
    KEY `idx_group_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_audience_group_members` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`      BIGINT UNSIGNED NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `name`          VARCHAR(190) NULL,
    `phone`         VARCHAR(80) NULL,
    `source_table`  VARCHAR(120) NULL,
    `source_id`     VARCHAR(120) NULL,
    `status`        ENUM('active','unsubscribed','bounced','invalid') NOT NULL DEFAULT 'active',
    `added_by`      VARCHAR(190) NULL,
    `added_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_group_email` (`group_id`, `email`),
    KEY `idx_group_member_group` (`group_id`),
    KEY `idx_group_member_email` (`email`),
    KEY `idx_group_member_status` (`status`),
    KEY `idx_group_member_source` (`source_table`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_audience_group_imports` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `group_id`          BIGINT UNSIGNED NOT NULL,
    `import_uid`        VARCHAR(64) NOT NULL,
    `source_type`       ENUM('csv','lead_table') NOT NULL DEFAULT 'csv',
    `source_name`       VARCHAR(190) NULL,
    `original_filename` VARCHAR(255) NULL,
    `import_file_hash`  VARCHAR(64) NULL,
    `total_rows`        INT UNSIGNED NOT NULL DEFAULT 0,
    `imported_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
    `invalid_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_rows`       INT UNSIGNED NOT NULL DEFAULT 0,
    `imported_by`       VARCHAR(190) NULL,
    `status`            ENUM('completed','partial_failed','failed') NOT NULL DEFAULT 'completed',
    `error_message`     TEXT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_import_uid` (`import_uid`),
    KEY `idx_group_import_group` (`group_id`),
    KEY `idx_group_import_source` (`source_type`),
    KEY `idx_group_import_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- email_logs — simplified display table (queried by admin/email/email-logs.php)
CREATE TABLE `email_logs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`       BIGINT UNSIGNED NULL,
    `recipient_email`   VARCHAR(190) NOT NULL DEFAULT '',
    `status`            VARCHAR(30) NOT NULL DEFAULT 'sent',
    `sent_at`           DATETIME NULL,
    `opened_at`         DATETIME NULL,
    `clicked_at`        DATETIME NULL,
    `bounce_reason`     TEXT NULL,
    `tracking_pixel_id` VARCHAR(128) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_el_campaign_id` (`campaign_id`),
    KEY `idx_el_recipient` (`recipient_email`),
    KEY `idx_el_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_templates` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_type`          VARCHAR(60) NOT NULL DEFAULT '',
    `product_category_id`   VARCHAR(60) NOT NULL DEFAULT '',
    `target_scope`          ENUM('default','product','variant') NOT NULL DEFAULT 'product',
    `category`              VARCHAR(60) NULL,
    `product_name`          VARCHAR(255) NULL,
    `variant_name`          VARCHAR(150) NULL,
    `subject`               VARCHAR(255) NULL,
    `preheader`             VARCHAR(255) NULL,
    `badge_text`            VARCHAR(100) NULL,
    `greeting`              VARCHAR(255) NULL,
    `content`               MEDIUMTEXT NULL,
    `media_id`              VARCHAR(255) NULL,
    `button_link`           VARCHAR(500) NULL,
    `button_text`           VARCHAR(100) NULL DEFAULT 'Click Here',
    `closing`               TEXT NULL,
    `brand_name`            VARCHAR(100) NULL,
    `brand_email`           VARCHAR(190) NULL,
    `support_email`         VARCHAR(190) NULL,
    `footer_note`           TEXT NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `last_updated_by`       VARCHAR(100) NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_template_target` (`target_scope`, `product_type`, `product_category_id`),
    KEY `idx_et_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END OF SCHEMA
-- =============================================================================
