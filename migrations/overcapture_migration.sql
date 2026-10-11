-- ============================================================
-- DI PARMA | Over-Capture Migration
-- تاريخ: 2026-08-25
-- ============================================================

-- ─── 1) أعمدة جديدة في dp_holds ────────────────────────────
ALTER TABLE `dp_holds`
    ADD COLUMN `mode`             VARCHAR(20)  NOT NULL DEFAULT '2D'  AFTER `gateway`,
    ADD COLUMN `capture_count`    INT UNSIGNED NOT NULL DEFAULT 0      AFTER `captured_at`,
    ADD COLUMN `last_captured_at` DATETIME     DEFAULT NULL            AFTER `capture_count`,
    ADD COLUMN `overcaptured`     TINYINT(1)   NOT NULL DEFAULT 0      AFTER `last_captured_at`,
    ADD COLUMN `offline_sale`     TINYINT(1)   NOT NULL DEFAULT 0      AFTER `overcaptured`,
    MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'pending'
        COMMENT 'pending|authorized|partially_captured|captured|cancelled|expired';

-- ─── 2) جدول تدقيق Over-Capture ─────────────────────────────
CREATE TABLE IF NOT EXISTS `capture_overcaptures` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_intent_id` VARCHAR(100)  NOT NULL,
    `gateway`           VARCHAR(30)   NOT NULL,
    `authorized_amount` DECIMAL(12,2) NOT NULL,
    `total_captured`    DECIMAL(12,2) NOT NULL,
    `over_amount`       DECIMAL(12,2) NOT NULL,
    `mode`              VARCHAR(20)   DEFAULT '2D',
    `created_at`        DATETIME      NOT NULL,
    INDEX `idx_pi`      (`payment_intent_id`),
    INDEX `idx_gateway` (`gateway`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 3) جدول Offline Sales ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `offline_sales` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_intent_id` VARCHAR(100)  NOT NULL,
    `gateway`           VARCHAR(30)   NOT NULL,
    `reference`         VARCHAR(100)  NOT NULL,
    `amount`            DECIMAL(12,2) NOT NULL,
    `currency`          VARCHAR(10)   NOT NULL DEFAULT 'USD',
    `mode`              VARCHAR(20)   NOT NULL DEFAULT 'OFFLINE',
    `status`            VARCHAR(30)   NOT NULL DEFAULT 'pending',
    `captured_at`       DATETIME      DEFAULT NULL,
    `settled_at`        DATETIME      DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL,
    INDEX `idx_pi`      (`payment_intent_id`),
    INDEX `idx_gateway` (`gateway`),
    INDEX `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
