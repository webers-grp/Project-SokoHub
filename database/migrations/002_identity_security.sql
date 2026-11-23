-- =============================================================================
-- Migration 002: Identity, Security, Access Control & Background Infrastructure
-- Target: MariaDB 10.6+ and MySQL 8+
-- =============================================================================
USE sokohub_db;

-- -----------------------------------------------------------------------------
-- 1. Alter users table for verification, 2FA, brute-force locking & login audit
-- -----------------------------------------------------------------------------
ALTER TABLE users
    MODIFY COLUMN status ENUM('pending_verification', 'active', 'suspended') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at,
    ADD COLUMN IF NOT EXISTS failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER two_factor_enabled,
    ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER failed_login_count,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER locked_until;

-- -----------------------------------------------------------------------------
-- 2. OTP Codes table: 6-digit codes for registration, login, 2FA, reset
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    purpose      VARCHAR(50) NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    attempts     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_user_purpose (user_id, purpose, used_at),
    INDEX idx_otp_expires (expires_at),
    CONSTRAINT fk_otp_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Password Reset Tokens table: 32-byte crypto token hashes, 30m expiry
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (user_id),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Login Attempts table: tracks every attempt for IP & email brute-force throttling
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(160) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    user_agent   VARCHAR(255) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_attempts_email (email, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Jobs table: asynchronous background job queue (emails, SMS, tasks)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type     VARCHAR(80) NOT NULL,
    payload      JSON NOT NULL,
    status       VARCHAR(40) NOT NULL DEFAULT 'pending',
    attempts     INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by    VARCHAR(64) NULL,
    locked_at    DATETIME NULL,
    last_error   TEXT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  DATETIME NULL,
    INDEX idx_jobs_status_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Notification Logs table: audit log of all emails & SMS sent
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    channel      VARCHAR(50) NOT NULL,
    template     VARCHAR(80) NOT NULL,
    recipient    VARCHAR(160) NOT NULL,
    sent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_channel (channel),
    CONSTRAINT fk_notif_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
