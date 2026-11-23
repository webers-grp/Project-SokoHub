-- SokoHub Developer Console Migration 005
-- Dedicated developer accounts, 2FA, append-only console audit logs, and system settings

-- 1. Dedicated Developer Accounts Table
CREATE TABLE IF NOT EXISTS `developers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(160) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('viewer', 'developer', 'owner') NOT NULL DEFAULT 'viewer',
    `totp_secret` VARCHAR(64) NULL,
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME NULL,
    `last_ip` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_dev_role` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Console Audit Log (Append-Only)
CREATE TABLE IF NOT EXISTS `devconsole_audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `developer_id` INT UNSIGNED NULL,
    `username` VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `category` VARCHAR(40) NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `target` VARCHAR(120) NULL,
    `details` TEXT NULL,
    `result` ENUM('success', 'failed', 'blocked') NOT NULL DEFAULT 'success',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_dev_audit_dev` (`developer_id`),
    INDEX `idx_dev_audit_cat` (`category`, `created_at`),
    INDEX `idx_dev_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. System Settings / Feature Flags Table
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `setting_value` TEXT NULL,
    `setting_type` ENUM('boolean', 'string', 'integer', 'json') NOT NULL DEFAULT 'string',
    `updated_by` VARCHAR(50) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default feature flags and maintenance settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `updated_by`)
VALUES
    ('maintenance_mode', '0', 'boolean', 'system'),
    ('maintenance_allowed_ips', '', 'string', 'system'),
    ('maintenance_message', 'SokoHub is undergoing routine system maintenance. Please check back shortly.', 'string', 'system'),
    ('allow_user_registration', '1', 'boolean', 'system'),
    ('allow_cod_checkout', '1', 'boolean', 'system'),
    ('allow_mpesa_checkout', '1', 'boolean', 'system')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
