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

        $jobId = (int) $pdo->lastInsertId();

        // In web SAPI mode, automatically register shutdown flush to dispatch jobs immediately
        static $hookRegistered = false;
        if (!$hookRegistered && PHP_SAPI !== 'cli') {
            $hookRegistered = true;
            register_shutdown_function(function () use ($pdo) {
                try {
                    self::triggerOpportunisticDispatch($pdo, 3);
                } catch (Throwable $_) {}
            });
        }

        return $jobId;
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

    /**
     * Retrieve outbound SMTP configuration from config/db.local.php or environment variables.
     */
    public static function getSmtpConfig(): array
    {
        $cfg = [
            'host'         => getenv('SMTP_HOST') ?: '',
            'port'         => (int) (getenv('SMTP_PORT') ?: 587),
            'user'         => getenv('SMTP_USER') ?: '',
            'pass'         => getenv('SMTP_PASS') ?: '',
            'from'         => getenv('SMTP_FROM') ?: 'no-reply@sokohub.co.ke',
            'name'         => getenv('SMTP_NAME') ?: 'SokoHub',
            'encryption'   => getenv('SMTP_ENCRYPTION') ?: 'tls',
            'show_dev_otp' => getenv('SHOW_DEV_OTP') !== false ? filter_var(getenv('SHOW_DEV_OTP'), FILTER_VALIDATE_BOOLEAN) : null,
        ];

        // 1. Check dedicated config/mail.local.php
        $mailLocal = __DIR__ . '/../config/mail.local.php';
        if (file_exists($mailLocal)) {
            $m = require $mailLocal;
            if (is_array($m)) {
                $cfg['host'] = $m['host'] ?? $cfg['host'];
                $cfg['port'] = (int) ($m['port'] ?? $cfg['port']);
                $cfg['user'] = $m['user'] ?? $cfg['user'];
                $cfg['pass'] = $m['pass'] ?? $cfg['pass'];
                $cfg['from'] = $m['from'] ?? $cfg['from'];
                $cfg['name'] = $m['name'] ?? $cfg['name'];
                if (isset($m['encryption'])) {
                    $cfg['encryption'] = $m['encryption'];
                }
                if (isset($m['show_dev_otp'])) {
                    $cfg['show_dev_otp'] = (bool) $m['show_dev_otp'];
                }
            }
        }

        // 2. Check config/db.local.php for nested smtp array
        $localConfig = __DIR__ . '/../config/db.local.php';
        if (file_exists($localConfig)) {
            $local = require $localConfig;
            if (is_array($local) && !empty($local['smtp']) && is_array($local['smtp'])) {
                $cfg['host'] = $local['smtp']['host'] ?? $cfg['host'];
                $cfg['port'] = (int) ($local['smtp']['port'] ?? $cfg['port']);
                $cfg['user'] = $local['smtp']['user'] ?? $cfg['user'];
                $cfg['pass'] = $local['smtp']['pass'] ?? $cfg['pass'];
                $cfg['from'] = $local['smtp']['from'] ?? $cfg['from'];
                $cfg['name'] = $local['smtp']['name'] ?? $cfg['name'];
                if (isset($local['smtp']['encryption'])) {
                    $cfg['encryption'] = $local['smtp']['encryption'];
                }
                if (isset($local['smtp']['show_dev_otp'])) {
                    $cfg['show_dev_otp'] = (bool) $local['smtp']['show_dev_otp'];
                }
            }
        }

        $port = (int) ($cfg['port'] ?: 587);
        $cfg['port'] = $port;
        if (empty($cfg['encryption'])) {
            $cfg['encryption'] = ($port === 465) ? 'ssl' : 'tls';
        }
        $cfg['from_name'] = $cfg['name'] ?: 'SokoHub';
        $cfg['from_email'] = $cfg['from'] ?: 'no-reply@sokohub.co.ke';

        return $cfg;
    }

    /**
     * Check if outbound SMTP credentials are configured.
     */
    public static function hasSmtpConfigured(): bool
    {
        $cfg = self::getSmtpConfig();
        return !empty($cfg['host']) && !empty($cfg['user']) && !empty($cfg['pass']);
    }

    /**
     * Render a responsive, branded HTML email template for SokoHub transactional emails.
     */
    public static function buildHtmlEmail(string $title, string $greeting, string $contentHtml, ?string $calloutCode = null, ?string $buttonText = null, ?string $buttonUrl = null): string
    {
        $codeBlock = '';
        if ($calloutCode !== null && $calloutCode !== '') {
            $codeBlock = '
            <div style="background: #f0fdf4; border: 2px dashed #16a34a; border-radius: 8px; padding: 18px; text-align: center; margin: 24px 0;">
                <div style="font-size: 13px; color: #166534; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;">Your 6-Digit Verification PIN</div>
                <div style="font-family: Consolas, monospace, sans-serif; font-size: 32px; font-weight: 800; color: #15803d; letter-spacing: 6px;">' . htmlspecialchars($calloutCode, ENT_QUOTES, 'UTF-8') . '</div>
                <div style="font-size: 12px; color: #4b5563; margin-top: 6px;">Expires in 10 minutes &bull; Do not share this code with anyone</div>
            </div>';
        }

        $buttonBlock = '';
        if ($buttonText !== null && $buttonUrl !== null) {
            $buttonBlock = '
            <div style="text-align: center; margin: 24px 0;">
                <a href="' . htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8') . '" style="background: #16a34a; color: #ffffff; padding: 12px 28px; border-radius: 6px; font-weight: 700; text-decoration: none; display: inline-block; font-size: 15px;">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a>
            </div>';
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px 12px; color: #1f2937; line-height: 1.6;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
        <tr>
            <td style="background: #15803d; padding: 24px 32px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;">SokoHub</h1>
                <p style="color: #dcfce7; margin: 4px 0 0; font-size: 13px;">Kirinyaga University Campus &amp; Regional Marketplace</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px 28px;">
                <h2 style="margin: 0 0 16px; font-size: 18px; color: #0f172a;">' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</h2>
                <div style="font-size: 15px; color: #374151;">' . $contentHtml . '</div>
                ' . $codeBlock . '
                ' . $buttonBlock . '
                <p style="font-size: 13px; color: #6b7280; margin-top: 24px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                    If you did not initiate this request, you can safely disregard this email. Your SokoHub account remains secure.
                </p>
            </td>
        </tr>
        <tr>
            <td style="background: #f8fafc; padding: 18px 24px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;">
                &copy; ' . date('Y') . ' SokoHub &bull; Kirinyaga County, Kenya &bull; Powered by KyU SPI 2301
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Send email via direct SMTP socket.
     * Supports STARTTLS (587) and SSL/TLS (465) with HTML & plain text formatting.
     */
    public static function sendSmtpMail(string $to, string $subject, string $body, bool $isHtml = false, ?string &$errorOutput = null): bool
    {
        $cfg = self::getSmtpConfig();
        $host = $cfg['host'];
        $port = (int) ($cfg['port'] ?: 587);
        $user = $cfg['user'];
        $pass = $cfg['pass'];
        $from = $cfg['from'];
        $name = $cfg['name'] ?: 'SokoHub';
        $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));

        if (empty($host) || empty($user) || empty($pass)) {
            $errorOutput = 'SMTP host, username, or password not configured.';
            return false;
        }

        try {
            $timeout = 10;
            $isSsl = ($port === 465 || $encryption === 'ssl');
            $remote = ($isSsl ? 'ssl://' : '') . $host;
            $socket = @fsockopen($remote, $port, $errno, $errstr, $timeout);
            if (!$socket) {
                $errorOutput = "Connection to {$remote}:{$port} failed: {$errstr} ({$errno})";
                return false;
            }

            $read = function () use ($socket) {
                $res = '';
                while ($str = fgets($socket, 515)) {
                    $res .= $str;
                    if (substr($str, 3, 1) === ' ') break;
                }
                return $res;
            };
            $write = function ($cmd) use ($socket) {
                fputs($socket, $cmd . "\r\n");
            };

            $banner = $read();
            $write("EHLO localhost");
            $ehlo = $read();

            if ($port === 587 || $encryption === 'tls') {
                $write("STARTTLS");
                $starttls = $read();
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    $errorOutput = 'TLS handshake negotiation failed.';
                    return false;
                }
                $write("EHLO localhost");
                $read();
            }

            $write("AUTH LOGIN");
            $authReq = $read();
            $write(base64_encode($user));
            $userReq = $read();
            $cleanPass = str_replace([' ', "\t", "\r", "\n"], '', $pass);
            $write(base64_encode($cleanPass));
            $passRes = $read();

            if (!str_starts_with(trim($passRes), '235')) {
                fclose($socket);
                $errorOutput = 'SMTP Authentication failed: ' . trim($passRes);
                return false;
            }

            $write("MAIL FROM: <{$from}>");
            $mailFromRes = $read();
            $write("RCPT TO: <{$to}>");
            $rcptRes = $read();
            if (!str_starts_with(trim($rcptRes), '250') && !str_starts_with(trim($rcptRes), '251')) {
                fclose($socket);
                $errorOutput = 'Recipient rejected: ' . trim($rcptRes);
                return false;
            }

            $write("DATA");
            $dataReq = $read();

            $contentType = $isHtml ? "text/html; charset=UTF-8" : "text/plain; charset=UTF-8";
            $headers  = "From: {$name} <{$from}>\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date(DATE_RFC2822) . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: {$contentType}\r\n";
            $headers .= "X-Mailer: SokoHub/2.0\r\n";

            $write($headers . "\r\n" . $body . "\r\n.");
            $dataRes = $read();

            $write("QUIT");
            fclose($socket);

            return true;
        } catch (Throwable $e) {
            $errorOutput = $e->getMessage();
            return false;
        }
    }

    /**
     * Process an email job payload and dispatch via SMTP or fallback.
     */
    public static function executeEmailJob(PDO $pdo, int $jobId, array $payload, ?callable $logger = null): void
    {
        $log = $logger ?? function ($m) {};
        $to = $payload['to'] ?? ($payload['recipient'] ?? '');
        $subject = $payload['subject'] ?? 'SokoHub Notification';
        $template = $payload['template'] ?? 'general';
        $code = (string) ($payload['code'] ?? '');
        $resetUrl = (string) ($payload['reset_url'] ?? '');
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

        if (empty($to)) {
            throw new InvalidArgumentException("Missing email recipient ('to').");
        }

        // Build HTML & Plaintext Body
        $isHtml = true;
        if (!empty($payload['body'])) {
            $body = (string) $payload['body'];
            $isHtml = (str_contains($body, '<html') || str_contains($body, '<div') || str_contains($body, '<p'));
        } elseif ($code !== '') {
            $greeting = 'Verify Your SokoHub Account';
            $content = '<p>Thank you for using SokoHub. Use the 6-digit verification code below to confirm your account identity:</p>';
            $body = self::buildHtmlEmail($subject, $greeting, $content, $code);
        } elseif ($resetUrl !== '') {
            $greeting = 'Reset Your Password';
            $content = '<p>We received a request to reset your SokoHub account password. Click the button below to choose a new password:</p>';
            $body = self::buildHtmlEmail($subject, $greeting, $content, null, 'Reset My Password', $resetUrl);
        } else {
            $greeting = 'Account Notification';
            $content = '<p>You have a new update regarding your SokoHub account or orders.</p>';
            $body = self::buildHtmlEmail($subject, $greeting, $content);
        }

        $sentSmtp = false;
        $smtpErr = '';
        if (self::hasSmtpConfigured()) {
            $sentSmtp = self::sendSmtpMail($to, $subject, $body, $isHtml, $smtpErr);
            if (!$sentSmtp && !empty($smtpErr)) {
                error_log("SokoHub SMTP Error for recipient '{$to}': " . $smtpErr);
            }
        }

        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logLine = sprintf(
            "[%s] TO: %s | TEMPLATE: %s | CODE: %s | STATUS: %s%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $template,
            $code ?: '-',
            $sentSmtp ? 'SENT_VIA_SMTP' : 'LOCAL_SIMULATED',
            (!empty($smtpErr) ? " (Error: {$smtpErr})" : "")
        );
        @file_put_contents($logDir . '/outgoing_emails.log', $logLine, FILE_APPEND);

        if ($sentSmtp) {
            $log("Dispatched live SMTP email to '{$to}' (template: {$template}) ");
        } else {
            // Fallback local simulation / PHP mail
            $headers = "From: no-reply@sokohub.co.ke\r\nReply-To: support@sokohub.co.ke\r\nX-Mailer: PHP/" . phpversion();
            if (function_exists('mail') && !empty(ini_get('SMTP')) && ini_get('SMTP') !== 'localhost') {
                @mail($to, $subject, strip_tags($body), $headers);
            }
            $log("Processed email job for '{$to}' (template: {$template}) ");
        }

        self::logNotification($pdo, $userId, 'email', $template, $to);
    }

    /**
     * Process an SMS job payload and dispatch via Telephony.
     */
    public static function executeSmsJob(PDO $pdo, int $jobId, array $payload, ?callable $logger = null): void
    {
        $log = $logger ?? function ($m) {};
        $phone = $payload['phone'] ?? ($payload['recipient'] ?? ($payload['to'] ?? ''));
        $template = $payload['template'] ?? 'sms_alert';
        $message = (string) ($payload['message'] ?? '');
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

        if (empty($phone)) {
            throw new InvalidArgumentException("Missing phone recipient.");
        }

        if (class_exists('Telephony') && method_exists('Telephony', 'sendSms')) {
            try {
                \Telephony::sendSms($phone, $message);
            } catch (Throwable $_) {}
        }

        self::logNotification($pdo, $userId, 'sms', $template, $phone);
        $log("Dispatched SMS to '{$phone}' ");
    }

    /**
     * Process a batch of pending jobs from the jobs table.
     */
    public static function processJobsBatch(PDO $pdo, ?string $workerId = null, int $limit = 10, ?callable $logger = null): int
    {
        $log = $logger ?? function ($m) {};
        $workerId = $workerId ?: (gethostname() . ':' . getmypid());

        $stmtSelect = $pdo->prepare("
            SELECT id, job_type, payload, status, attempts, max_attempts, available_at
            FROM jobs
            WHERE status = 'pending' AND available_at <= NOW()
            ORDER BY available_at ASC, id ASC
            LIMIT :limit
        ");
        $stmtSelect->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtSelect->execute();
        $jobs = $stmtSelect->fetchAll();

        if (empty($jobs)) {
            return 0;
        }

        $processedCount = 0;

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $jobType = $job['job_type'];

            $stmtLock = $pdo->prepare("
                UPDATE jobs
                SET status = 'running', locked_by = :wid, locked_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $stmtLock->execute([':wid' => $workerId, ':id' => $jobId]);

            if ($stmtLock->rowCount() === 0) {
                continue;
            }

            $processedCount++;
            $log("[" . date('H:i:s') . "] [Job #{$jobId}] Claimed job_type='{$jobType}'... ");

            try {
                $payload = json_decode((string) $job['payload'], true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                switch ($jobType) {
                    case 'send_email':
                    case 'email':
                        self::executeEmailJob($pdo, $jobId, $payload, $log);
                        break;

                    case 'send_sms':
                    case 'sms':
                        self::executeSmsJob($pdo, $jobId, $payload, $log);
                        break;

                    default:
                        $log("Unknown type '{$jobType}', marked as completed. ");
                        break;
                }

                $stmtDone = $pdo->prepare("
                    UPDATE jobs
                    SET status = 'completed', finished_at = NOW(), locked_by = NULL, last_error = NULL
                    WHERE id = :id
                ");
                $stmtDone->execute([':id' => $jobId]);
                $log("-> COMPLETED\n");

            } catch (Throwable $e) {
                $errorMsg = mb_substr($e->getMessage(), 0, 450);
                $newAttempts = (int) $job['attempts'] + 1;
                $maxAttempts = (int) ($job['max_attempts'] ?: 5);
                $newStatus = ($newAttempts >= $maxAttempts) ? 'failed' : 'pending';

                $backoffSeconds = $newAttempts * 60;
                $nextAvailable = date('Y-m-d H:i:s', time() + $backoffSeconds);

                $stmtFail = $pdo->prepare("
                    UPDATE jobs
                    SET attempts = :att,
                        status = :st,
                        available_at = :avail,
                        last_error = :err,
                        locked_by = NULL
                    WHERE id = :id
                ");
                $stmtFail->execute([
                    ':att'   => $newAttempts,
                    ':st'    => $newStatus,
                    ':avail' => $nextAvailable,
                    ':err'   => $errorMsg,
                    ':id'    => $jobId,
                ]);

                $log("-> FAILED ({$newAttempts}/{$maxAttempts}): {$errorMsg}\n");
            }
        }

        return $processedCount;
    }

    /**
     * Opportunistically process up to $limit pending jobs in web requests.
     */
    public static function triggerOpportunisticDispatch(PDO $pdo, int $limit = 5): int
    {
        return self::processJobsBatch($pdo, 'web-auto:' . getmypid(), $limit);
    }
}
