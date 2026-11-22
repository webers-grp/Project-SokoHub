-- SokoHub Security Hardening & Performance Migration 004
-- Idempotent schema upgrades for rate limiting, KDPA privacy, and performance indexes

-- 1. Rate Limiting Table
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `rate_key` VARCHAR(128) NOT NULL PRIMARY KEY,
    `action` VARCHAR(32) NOT NULL,
    `identifier` VARCHAR(64) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `reset_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rate_action` (`action`, `reset_at`),
    INDEX `idx_rate_identifier` (`identifier`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Account Deletions / Anonymization Requests (KDPA)
CREATE TABLE IF NOT EXISTS `account_deletions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `email_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('pending', 'processed', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL,
    `notes` TEXT NULL,
    INDEX `idx_del_user` (`user_id`),
    INDEX `idx_del_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Performance Indexes
-- (Managed idempotently via run_migration_004.php or ALTER IGNORE statements)
