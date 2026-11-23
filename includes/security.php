<?php
/**
 * SokoHub Identity, Security, Access Control & Background Infrastructure
 * Manages OTP generation/verification, brute-force login throttling, Admin 2FA,
 * password reset tokens, asynchronous job queueing, and notification logging.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

class Security
{
    /**
     * Ensure all required database tables and columns exist.
     * Safe to run repeatedly (idempotent).
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            // 0. Ensure users table exists
            $usersCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
            if (!$usersCheck) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        full_name VARCHAR(120) NOT NULL,
                        email VARCHAR(160) NOT NULL UNIQUE,
                        phone VARCHAR(30) NOT NULL,
                        password_hash VARCHAR(255) NOT NULL,
                        role ENUM('buyer', 'seller', 'delivery', 'admin') NOT NULL DEFAULT 'buyer',
                        status ENUM('pending_verification', 'active', 'suspended') NOT NULL DEFAULT 'active',
                        email_verified_at DATETIME NULL,
                        two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
                        failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
                        locked_until DATETIME NULL,
                        last_login_at DATETIME NULL,
                        last_seen_at DATETIME NULL,
                        offline_alert_preference ENUM('both', 'sms', 'call', 'none') NOT NULL DEFAULT 'both',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            // 1. Alter users table if needed
            $statusCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();
            if ($statusCol && !str_contains((string) $statusCol['Type'], 'pending_verification')) {
                try {
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('pending_verification', 'active', 'suspended') NOT NULL DEFAULT 'active'");
                } catch (Throwable $_) {}
            }

            $userCols = [
                'email_verified_at'  => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL",
                'two_factor_enabled' => "ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0",
                'failed_login_count' => "ALTER TABLE users ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0",
                'locked_until'       => "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL",
                'last_login_at'      => "ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL",
            ];

            foreach ($userCols as $colName => $alterSql) {
                $check = $pdo->query("SHOW COLUMNS FROM users LIKE '{$colName}'")->fetch();
                if (!$check) {
                    try {
                        $pdo->exec($alterSql);
                    } catch (Throwable $_) {}
                }
            }

            // 2. Table: otp_codes
            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Backward compatibility: ensure used_at exists if consumed_at was used
            $hasUsedAt = $pdo->query("SHOW COLUMNS FROM otp_codes LIKE 'used_at'")->fetch();
            if (!$hasUsedAt) {
                try {
                    $pdo->exec("ALTER TABLE otp_codes ADD COLUMN used_at DATETIME NULL AFTER expires_at");
                } catch (Throwable $_) {}
            }

            // 3. Table: password_reset_tokens
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT UNSIGNED NOT NULL,
                    token_hash  CHAR(64) NOT NULL UNIQUE,
                    expires_at  DATETIME NOT NULL,
                    used_at     DATETIME NULL,
                    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reset_user (user_id),
                    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 4. Table: login_attempts
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email        VARCHAR(160) NOT NULL,
                    ip_address   VARCHAR(45) NOT NULL,
                    user_agent   VARCHAR(255) NULL,
                    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    success      TINYINT(1) NOT NULL DEFAULT 0,
                    INDEX idx_attempts_email (email, attempted_at),
                    INDEX idx_attempts_ip (ip_address, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $hasUa = $pdo->query("SHOW COLUMNS FROM login_attempts LIKE 'user_agent'")->fetch();
            if (!$hasUa) {
                try {
                    $pdo->exec("ALTER TABLE login_attempts ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address");
                } catch (Throwable $_) {}
            }

            // 5. Table: jobs
            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 6. Table: notification_logs
            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $hasSentAt = $pdo->query("SHOW COLUMNS FROM notification_logs LIKE 'sent_at'")->fetch();
            if (!$hasSentAt) {
                try {
                    $pdo->exec("ALTER TABLE notification_logs ADD COLUMN sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER recipient");
                } catch (Throwable $_) {}
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('Security::ensureSchema failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate and store a secure 6-digit OTP code.
     * Uses random_int(100000, 999999) and hash_hmac('sha256', $code, APP_SECRET).
     * Validity: 10 minutes.
     */
    public static function generateOtp(int $userId, string $purpose, int $validitySeconds = 600): string
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $code = (string) random_int(100000, 999999);
        $secret = defined('APP_SECRET') ? APP_SECRET : 'sokohub_default_secret_key_2026';
        $codeHash = hash_hmac('sha256', $code, $secret);
        $expiresAt = date('Y-m-d H:i:s', time() + $validitySeconds);

        // Invalidate prior unused OTPs for this user & purpose
        $stmtCancel = $pdo->prepare("
            UPDATE otp_codes
            SET used_at = NOW()
            WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL
        ");
        $stmtCancel->execute([':user_id' => $userId, ':purpose' => $purpose]);

        // Insert new OTP record
        $stmt = $pdo->prepare("
            INSERT INTO otp_codes (user_id, purpose, code_hash, expires_at, used_at, attempts, created_at)
            VALUES (:user_id, :purpose, :code_hash, :expires_at, NULL, 0, NOW())
        ");
        $stmt->execute([
            ':user_id'    => $userId,
            ':purpose'    => $purpose,
            ':code_hash'  => $codeHash,
            ':expires_at' => $expiresAt,
        ]);

        return $code;
    }

    /**
     * Verify a submitted 6-digit OTP code against the database.
     */
    public static function verifyOtp(int $userId, string $purpose, string $code): array
    {
        self::ensureSchema();
        $pdo = get_db_connection();

        $cleanCode = trim($code);
        if (!preg_match('/^\d{6}$/', $cleanCode)) {
            return ['ok' => false, 'error' => 'Please enter a valid 6-digit code.'];
        }

        $stmt = $pdo->prepare("
            SELECT id, code_hash, expires_at, attempts, used_at
            FROM otp_codes
            WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':purpose' => $purpose]);
        $record = $stmt->fetch();

        if (!$record) {
            return ['ok' => false, 'error' => 'No active verification code found or it has already been used. Please request a new one.'];
        }

        // Increment attempts
        $pdo->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id")->execute([':id' => $record['id']]);

        if (strtotime($record['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'Verification code has expired. Please request a new code.'];
        }

        if ((int) $record['attempts'] >= 5) {
            $pdo->prepare("UPDATE otp_codes SET used_at = NOW() WHERE id = :id")->execute([':id' => $record['id']]);
            return ['ok' => false, 'error' => 'Too many failed attempts. Code has been invalidated. Please request a new code.'];
        }

        $secret = defined('APP_SECRET') ? APP_SECRET : 'sokohub_default_secret_key_2026';
        $expectedHash = hash_hmac('sha256', $cleanCode, $secret);

        if (!hash_equals($record['code_hash'], $expectedHash)) {
            return ['ok' => false, 'error' => 'Invalid verification code. Please check and try again.'];
        }

        // Mark as used
        $pdo->prepare("UPDATE otp_codes SET used_at = NOW() WHERE id = :id")->execute([':id' => $record['id']]);

        // If registration verification, activate the user and record email_verified_at
        if ($purpose === 'registration' || $purpose === 'signup') {
            $pdo->prepare("
                UPDATE users
                SET status = 'active', email_verified_at = NOW(), failed_login_count = 0, locked_until = NULL
                WHERE id = :id
            ")->execute([':id' => $userId]);
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Check brute-force login throttling:
     * - >= 5 failures on an email within 15 minutes OR
     * - >= 20 failures from an IP within 10 minutes OR
     * - user locked_until > NOW()
     *
     * Returns ['throttled' => bool, 'message' => ?string].
     */
    public static function checkLoginThrottling(PDO $pdo, string $email, string $ip): array
    {
        self::ensureSchema();

        $genericError = 'Too many attempts, please try again in 15 minutes.';

        // 1. Check IP failures in past 10 minutes
        $stmtIp = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip_address = :ip AND success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmtIp->execute([':ip' => $ip]);
        if ((int) $stmtIp->fetchColumn() >= 20) {
            return ['throttled' => true, 'message' => $genericError];
        }

        // 2. Check user locked_until directly
        $stmtUser = $pdo->prepare("SELECT id, locked_until, failed_login_count FROM users WHERE email = :email LIMIT 1");
        $stmtUser->execute([':email' => $email]);
        $user = $stmtUser->fetch();

        if ($user && !empty($user['locked_until'])) {
            if (strtotime((string) $user['locked_until']) > time()) {
                return ['throttled' => true, 'message' => $genericError];
            }
        }

        // 3. Check Email failures in past 15 minutes
        $stmtEmail = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE email = :email AND success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmtEmail->execute([':email' => $email]);
        $emailFails = (int) $stmtEmail->fetchColumn();

        if ($emailFails >= 5) {
            if ($user) {
                $pdo->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = :id")
                    ->execute([':id' => (int) $user['id']]);
            }
            return ['throttled' => true, 'message' => $genericError];
        }

        return ['throttled' => false, 'message' => null];
    }

    /**
     * Log every login attempt to login_attempts table.
     */
    public static function logLoginAttempt(PDO $pdo, string $email, string $ip, ?string $userAgent, bool $success): void
    {
        self::ensureSchema();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (email, ip_address, user_agent, attempted_at, success)
                VALUES (:email, :ip, :ua, NOW(), :success)
            ");
            $stmt->execute([
                ':email'   => strtolower(trim($email)),
                ':ip'      => $ip,
                ':ua'      => mb_substr((string) $userAgent, 0, 255),
                ':success' => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log login attempt: ' . $e->getMessage());
        }
    }

    /**
     * Increment failure count on user and lock if threshold reached.
     */
    public static function handleLoginFailure(PDO $pdo, string $email): void
    {
        self::ensureSchema();
        $stmt = $pdo->prepare("SELECT id, failed_login_count FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $newCount = (int) $user['failed_login_count'] + 1;
            if ($newCount >= 5) {
                $lockStmt = $pdo->prepare("
                    UPDATE users
                    SET failed_login_count = :count, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                    WHERE id = :id
                ");
                $lockStmt->execute([':count' => $newCount, ':id' => (int) $user['id']]);
            } else {
                $upStmt = $pdo->prepare("UPDATE users SET failed_login_count = :count WHERE id = :id");
                $upStmt->execute([':count' => $newCount, ':id' => (int) $user['id']]);
            }
        }
    }

    /**
     * Reset failure count, unlock, and touch last_login_at.
     */
    public static function handleLoginSuccess(PDO $pdo, int $userId): void
    {
        self::ensureSchema();
        $stmt = $pdo->prepare("
            UPDATE users
            SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $userId]);
    }

    public static function handleSuccessfulLogin(PDO $pdo, int $userId): void
    {
        self::handleLoginSuccess($pdo, $userId);
    }

    /**
     * Generate 32-byte cryptographic password reset token (bin2hex(random_bytes(32))).
     * Stores SHA-256 hash in password_reset_tokens. Valid for 30 minutes.
     * Returns the raw token string for the email link.
     */
    public static function createPasswordResetToken(PDO $pdo, int $userId): string
    {
        self::ensureSchema();

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 60));

        // Invalidate previous reset tokens for this user
        $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
            ->execute([':uid' => $userId]);

        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, created_at)
            VALUES (:uid, :thash, :exp, NULL, NOW())
        ");
        $stmt->execute([
            ':uid'   => $userId,
            ':thash' => $tokenHash,
            ':exp'   => $expiresAt,
        ]);

        return $rawToken;
    }

    /**
     * Validate a raw password reset token.
     * Returns array record if valid, or null if expired/used/invalid.
     */
    public static function verifyPasswordResetToken(PDO $pdo, string $rawToken): ?array
    {
        self::ensureSchema();

        $tokenHash = hash('sha256', trim($rawToken));
        $stmt = $pdo->prepare("
            SELECT prt.id, prt.user_id, prt.token_hash, prt.expires_at, prt.used_at,
                   u.email, u.full_name
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token_hash = :thash AND prt.used_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':thash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * Consume password reset token and update user password.
     */
    public static function consumePasswordResetToken(PDO $pdo, string $rawToken, string $newPassword): bool
    {
        self::ensureSchema();

        $tokenRow = self::verifyPasswordResetToken($pdo, $rawToken);
        if (!$tokenRow) {
            return false;
        }

        $userId = (int) $tokenRow['user_id'];
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        // Update password and clear lockouts
        $up = $pdo->prepare("
            UPDATE users
            SET password_hash = :pwd, failed_login_count = 0, locked_until = NULL
            WHERE id = :uid
        ");
        $up->execute([':pwd' => $hash, ':uid' => $userId]);

        // Mark token used
        $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id")
            ->execute([':id' => $tokenRow['id']]);

        return true;
    }

    /**
     * Enqueue a background job in jobs table.
     */
    public static function enqueueJob(PDO $pdo, string $jobType, array $payload, ?string $availableAt = null): int
    {
        self::ensureSchema();

        $stmt = $pdo->prepare("
            INSERT INTO jobs (job_type, payload, status, attempts, max_attempts, available_at, created_at)
            VALUES (:type, :payload, 'pending', 0, 5, COALESCE(:avail, NOW()), NOW())
        ");
        $stmt->execute([
            ':type'    => $jobType,
            ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':avail'   => $availableAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Log a sent or attempted notification in notification_logs table.
     */
    public static function logNotification(PDO $pdo, ?int $userId, string $channel, string $template, string $recipient): int
    {
        self::ensureSchema();

        $stmt = $pdo->prepare("
            INSERT INTO notification_logs (user_id, channel, template, recipient, sent_at, created_at)
            VALUES (:uid, :channel, :template, :recipient, NOW(), NOW())
        ");
        $stmt->execute([
            ':uid'       => $userId,
            ':channel'   => $channel,
            ':template'  => $template,
            ':recipient' => $recipient,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
