-- =============================================================================
-- DEMO SCHEMA SKELETON
-- =============================================================================
-- Inferred from PROJECT_STRUCTURE.md and module file names.
-- CLAUDE CODE: verify every column against the actual queries in the codebase
-- before relying on this. Add/rename columns as needed. Marked TODO where
-- I had to guess.
--
-- Designed for MySQL 5.7+ (compatible with InfinityFree which still runs 5.7).
-- No JSON_TABLE, no window functions, no MySQL 8-only syntax.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- ADMIN / AUTH
-- -----------------------------------------------------------------------------

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- PAYMENT MODULE
-- -----------------------------------------------------------------------------

CREATE TABLE `products` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                          VARCHAR(190) NOT NULL,
    `slug`                          VARCHAR(190) NULL,
    `description`                   TEXT NULL,
    `type`                          ENUM('one_time','installment','subscription') NOT NULL DEFAULT 'one_time',
    `full_price`                    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `first_month_price`             DECIMAL(10,2) NULL,
    `retention_first_month_price`   DECIMAL(10,2) NULL,  -- per RUNBOOK note: exists but not yet wired to checkout
    `installment_months`            TINYINT UNSIGNED NULL,
    `monthly_price`                 DECIMAL(10,2) NULL,
    `poster_url`                    VARCHAR(500) NULL,
    `status`                        ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    `created_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `transactions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`          VARCHAR(64) NOT NULL,
    `product_id`        INT UNSIGNED NULL,
    `customer_name`     VARCHAR(190) NULL,
    `customer_email`    VARCHAR(190) NULL,
    `customer_phone`    VARCHAR(32) NULL,
    `amount`            DECIMAL(10,2) NOT NULL DEFAULT 0,
    `currency`          VARCHAR(8) NOT NULL DEFAULT 'MYR',
    `gateway`           ENUM('senangpay','stripe','manual') NOT NULL DEFAULT 'senangpay',
    `gateway_ref`       VARCHAR(190) NULL,
    `status`            ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    `is_test`           TINYINT(1) NOT NULL DEFAULT 0,  -- ENV_PAY_WHERE filters on this
    `discount_code`     VARCHAR(64) NULL,
    `discount_amount`   DECIMAL(10,2) NULL,
    `notes`             TEXT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at`           DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_order_id` (`order_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`),
    KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `discounts` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(64) NOT NULL,
    `type`          ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `amount`        DECIMAL(10,2) NOT NULL,
    `product_id`    INT UNSIGNED NULL,
    `max_uses`      INT UNSIGNED NULL,
    `used_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`    DATETIME NULL,
    `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- SUBSCRIPTION MODULE
-- -----------------------------------------------------------------------------

CREATE TABLE `subscriptions` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_email`        VARCHAR(190) NOT NULL,
    `customer_name`         VARCHAR(190) NULL,
    `product_id`            INT UNSIGNED NOT NULL,
    `status`                ENUM('active','paused','cancelled','past_due') NOT NULL DEFAULT 'active',
    `gateway`               ENUM('senangpay','stripe','manual') NOT NULL DEFAULT 'senangpay',
    `gateway_subscription_id` VARCHAR(190) NULL,
    `amount`                DECIMAL(10,2) NOT NULL,
    `start_date`            DATE NOT NULL,
    `next_billing_date`     DATE NULL,
    `cancelled_at`          DATETIME NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_next_billing` (`next_billing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `subscription_billing_history` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscription_id`   INT UNSIGNED NOT NULL,
    `transaction_id`    INT UNSIGNED NULL,
    `amount`            DECIMAL(10,2) NOT NULL,
    `status`            ENUM('success','failed','refunded') NOT NULL,
    `billed_at`         DATETIME NOT NULL,
    `notes`             TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_subscription` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- WEBINAR MODULE
-- -----------------------------------------------------------------------------

CREATE TABLE `webinars` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`             VARCHAR(190) NOT NULL,
    `slug`              VARCHAR(190) NULL,
    `description`       TEXT NULL,
    `scheduled_at`      DATETIME NOT NULL,
    `duration_minutes`  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `meeting_url`       VARCHAR(500) NULL,
    `recording_url`     VARCHAR(500) NULL,
    `status`            ENUM('draft','published','live','completed','cancelled') NOT NULL DEFAULT 'draft',
    `capacity`          INT UNSIGNED NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scheduled` (`scheduled_at`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `webinar_registrations` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`        INT UNSIGNED NOT NULL,
    `name`              VARCHAR(190) NOT NULL,
    `email`             VARCHAR(190) NOT NULL,
    `phone`             VARCHAR(32) NULL,
    `attended`          TINYINT(1) NOT NULL DEFAULT 0,
    `registered_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_webinar_email` (`webinar_id`, `email`),
    KEY `idx_webinar` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `webinar_reminder_logs` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`        INT UNSIGNED NOT NULL,
    `registration_id`   INT UNSIGNED NOT NULL,
    `reminder_type`     ENUM('24h','1h','15min') NOT NULL,
    `sent_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status`            ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    PRIMARY KEY (`id`),
    KEY `idx_webinar` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `webinar_marketing_campaigns` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webinar_id`        INT UNSIGNED NOT NULL,
    `name`              VARCHAR(190) NOT NULL,
    `subject`           VARCHAR(255) NULL,
    `body_html`         MEDIUMTEXT NULL,
    `audience_group_id` INT UNSIGNED NULL,
    `scheduled_at`      DATETIME NULL,
    `sent_at`           DATETIME NULL,
    `status`            ENUM('draft','scheduled','sending','sent','failed') NOT NULL DEFAULT 'draft',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_webinar` (`webinar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `webinar_marketing_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`   INT UNSIGNED NOT NULL,
    `recipient`     VARCHAR(190) NOT NULL,
    `status`        ENUM('sent','failed','opened','clicked') NOT NULL,
    `event_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- E-LEARNING MODULE  (originally in separate DB; collapsed into demo DB)
-- -----------------------------------------------------------------------------

CREATE TABLE `courses` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(190) NOT NULL,
    `slug`          VARCHAR(190) NULL,
    `description`   TEXT NULL,
    `cover_url`     VARCHAR(500) NULL,
    `status`        ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `course_videos` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`     INT UNSIGNED NOT NULL,
    `title`         VARCHAR(190) NOT NULL,
    `drive_file_id` VARCHAR(100) NULL,  -- demo: fake ID
    `duration_sec`  INT UNSIGNED NULL,
    `order_index`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `course_ebooks` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`     INT UNSIGNED NOT NULL,
    `title`         VARCHAR(190) NOT NULL,
    `drive_file_id` VARCHAR(100) NULL,
    `order_index`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `course_workbooks` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id`     INT UNSIGNED NOT NULL,
    `title`         VARCHAR(190) NOT NULL,
    `sheet_id`      VARCHAR(100) NULL,  -- demo: fake Google Sheets ID
    PRIMARY KEY (`id`),
    KEY `idx_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `students` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(190) NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `enrolled_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `student_enrollments` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED NOT NULL,
    `course_id`     INT UNSIGNED NOT NULL,
    `enrolled_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_student_course` (`student_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `student_progress` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED NOT NULL,
    `course_id`     INT UNSIGNED NOT NULL,
    `content_type`  ENUM('video','ebook','workbook') NOT NULL,
    `content_id`    INT UNSIGNED NOT NULL,
    `completed_at`  DATETIME NULL,
    `last_position` INT UNSIGNED NULL,  -- for video resume
    PRIMARY KEY (`id`),
    KEY `idx_student_course` (`student_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- EMAIL CAMPAIGN MODULE
-- -----------------------------------------------------------------------------

CREATE TABLE `email_campaigns` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(190) NOT NULL,
    `subject`           VARCHAR(255) NULL,
    `content_html`      MEDIUMTEXT NULL,
    `from_name`         VARCHAR(100) NULL,
    `from_email`        VARCHAR(190) NULL,
    `status`            ENUM('draft','scheduled','sending','sent','paused','failed') NOT NULL DEFAULT 'draft',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_campaign_schedules` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`   INT UNSIGNED NOT NULL,
    `scheduled_at`  DATETIME NOT NULL,
    `status`        ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    `executed_at`   DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_audience_groups` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(190) NOT NULL,
    `description`   TEXT NULL,
    `member_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_audience_members` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id`      INT UNSIGNED NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `name`          VARCHAR(190) NULL,
    `subscribed`    TINYINT(1) NOT NULL DEFAULT 1,
    `added_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_group_email` (`group_id`, `email`),
    KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_logs` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`       INT UNSIGNED NULL,
    `recipient_email`   VARCHAR(190) NOT NULL,
    `status`            ENUM('queued','sent','delivered','opened','clicked','bounced','failed') NOT NULL,
    `sent_at`           DATETIME NULL,
    `opened_at`         DATETIME NULL,
    `clicked_at`        DATETIME NULL,
    `bounce_reason`     VARCHAR(500) NULL,
    `tracking_pixel_id` VARCHAR(64) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_campaign` (`campaign_id`),
    KEY `idx_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_templates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(190) NOT NULL,
    `subject`       VARCHAR(255) NULL,
    `body_html`     MEDIUMTEXT NULL,
    `category`      VARCHAR(64) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- NOTIFICATIONS (for the bell icon in nav)
-- -----------------------------------------------------------------------------

CREATE TABLE `admin_notifications` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`          VARCHAR(64) NOT NULL,
    `title`         VARCHAR(190) NOT NULL,
    `body`          TEXT NULL,
    `link_url`      VARCHAR(500) NULL,
    `seen`          TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_seen_created` (`seen`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END OF SCHEMA SKELETON
-- =============================================================================
-- Claude Code: now run a sanity pass — grep the codebase for "FROM " and
-- "INSERT INTO" statements, list every table referenced, and compare to the
-- tables above. Add any missing tables, drop any unused ones.
-- =============================================================================
