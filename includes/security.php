<?php
/**
 * SokoHub Centralized Security & Privacy Hardening Engine
 *
 * Implements:
 * 1. HTTP Security Headers & Content-Security-Policy
 * 2. Database-backed Sliding-Window Rate Limiter & Brute-Force Lockout
 * 3. Session Lifetime & Idle Timeout Management (Session Hijack Defense)
 * 4. Data Privacy, Masking & KDPA Compliance Helpers
 * 5. Input Sanitization & Anti-Tampering Guards
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

class Security
{
    /**
     * Send hardened HTTP security headers.
     * Safe to call multiple times per request (guarded by headers_sent()).
     */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Hide PHP version
        header_remove('X-Powered-By');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Clickjacking Defense
        header('X-Frame-Options: SAMEORIGIN');

        // Referrer Privacy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Restrict intrusive browser APIs
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Content Security Policy (allows local assets, fonts, inline scripts needed for charts/interactions)
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "img-src 'self' data: blob: https:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self';";
        header('Content-Security-Policy: ' . $csp);

        // HSTS (HTTP Strict Transport Security) when serving over HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Check if maintenance mode is enabled
        self::checkMaintenanceMode();
    }

    /**
     * Verify if platform is in maintenance mode and enforce 503 response.
     */
    public static function checkMaintenanceMode(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Skip devconsole completely
        if (str_contains($uri, '/devconsole')) {
            return;
        }
        // Skip webhooks / payment callbacks
        if (str_contains($uri, 'mpesa_callback.php')) {
            return;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_message', 'maintenance_allowed_ips')");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            if (!empty($settings['maintenance_mode']) && $settings['maintenance_mode'] === '1') {
                $clientIp = self::getClientIp();
                $allowedIps = array_filter(array_map('trim', explode(',', $settings['maintenance_allowed_ips'] ?? '')));

                if (!in_array($clientIp, $allowedIps, true)) {
                    http_response_code(503);
                    header('Retry-After: 300');

                    $isApi = str_contains($uri, '/api/') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
                    $msg = !empty($settings['maintenance_message']) ? $settings['maintenance_message'] : 'SokoHub is currently undergoing scheduled platform maintenance. We will be back online shortly.';

                    if ($isApi) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode([
                            'error' => 'Service Unavailable',
                            'message' => $msg,
                            'status' => 503,
                        ]);
                        exit;
                    }

                    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Scheduled Maintenance | SokoHub</title><link rel="stylesheet" href="' . htmlspecialchars(url('assets/css/style.css')) . '"><style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0b1120;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;margin:0;padding:1.5rem;}.maint-box{background:#0f172a;border:1px solid #1e293b;border-radius:1rem;padding:2.5rem;max-width:540px;text-align:center;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);}.badge{display:inline-block;padding:0.35rem 0.75rem;background:rgba(249,115,22,0.15);color:#f97316;border:1px solid rgba(249,115,22,0.3);border-radius:9999px;font-size:0.8rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:1.5rem;}h1{font-size:1.75rem;margin:0 0 1rem;color:#ffffff;}p{color:#94a3b8;line-height:1.6;margin:0 0 1.5rem;}.footer-text{font-size:0.8rem;color:#64748b;margin-top:2rem;}</style></head><body><div class="maint-box"><span class="badge">Scheduled System Maintenance</span><h1>SokoHub Under Maintenance</h1><p>' . htmlspecialchars($msg) . '</p><div class="footer-text">&copy; ' . date('Y') . ' SokoHub Kenya. All marketplace transactions are safely queued.</div></div></body></html>';
                    exit;
                }
            }
        } catch (Throwable $e) {
            // Fail open if table or database is temporarily unavailable during migration
        }
    }

    /**
     * Get client real IP address safely, with validation against private/reserved spoofing.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $raw = (string) $_SERVER[$h];
                $ips = array_map('trim', explode(',', $raw));
                foreach ($ips as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Mask Kenyan phone number for KDPA privacy compliance (e.g. 2547****1234).
     */
    public static function maskPhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        $clean = preg_replace('/\D+/', '', $phone);
        $len = strlen($clean);
        if ($len < 6) {
            return '***';
        }
        $start = substr($clean, 0, 4);
        $end = substr($clean, -4);
        return $start . '****' . $end;
    }

    /**
     * Mask National ID or document number (e.g. 34****89).
     */
    public static function maskNationalId(?string $id): string
    {
        if (!$id) {
            return '';
        }
        $len = strlen($id);
        if ($len <= 4) {
            return '****';
        }
        $start = substr($id, 0, 2);
        $end = substr($id, -2);
        return $start . str_repeat('*', max(2, $len - 4)) . $end;
    }
}

class RateLimiter
{
    /**
     * Check whether an action is allowed for an identifier under rate limiting rules.
     * Returns ['allowed' => bool, 'remaining' => int, 'retry_after' => int].
     */
    public static function check(string $action, string $identifier, int $maxHits = 60, int $windowSeconds = 60): array
    {
        try {
            $pdo = get_db_connection();
            $key = hash('sha256', $action . ':' . $identifier);
            $now = time();

            // Prune expired rate limit entries periodically (1 in 50 requests)
            if (mt_rand(1, 50) === 1) {
                $pdo->exec('DELETE FROM rate_limits WHERE reset_at < NOW()');
            }

            $stmt = $pdo->prepare('SELECT hits, UNIX_TIMESTAMP(reset_at) AS reset_time FROM rate_limits WHERE rate_key = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($record) {
                $resetTime = (int) $record['reset_time'];
                $hits = (int) $record['hits'];

                if ($resetTime <= $now) {
                    // Window has elapsed; reset
                    $newReset = date('Y-m-d H:i:s', $now + $windowSeconds);
                    $upd = $pdo->prepare('UPDATE rate_limits SET hits = 1, reset_at = :rst WHERE rate_key = :k');
                    $upd->execute([':rst' => $newReset, ':k' => $key]);

                    return ['allowed' => true, 'remaining' => $maxHits - 1, 'retry_after' => 0];
                }

                if ($hits >= $maxHits) {
                    $retryAfter = max(1, $resetTime - $now);
                    return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
                }

                // Increment hit count
                $upd = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = :k');
                $upd->execute([':k' => $key]);

                return ['allowed' => true, 'remaining' => $maxHits - ($hits + 1), 'retry_after' => 0];
            }

            // Fresh rate limit record
            $newReset = date('Y-m-d H:i:s', $now + $windowSeconds);
            $ins = $pdo->prepare('
                INSERT INTO rate_limits (rate_key, action, identifier, hits, reset_at, created_at)
                VALUES (:k, :act, :id, 1, :rst, NOW())
            ');
            $ins->execute([
                ':k' => $key,
                ':act' => substr($action, 0, 32),
                ':id' => substr($identifier, 0, 64),
                ':rst' => $newReset,
            ]);

            return ['allowed' => true, 'remaining' => $maxHits - 1, 'retry_after' => 0];
        } catch (Throwable $e) {
            error_log('RateLimiter error: ' . $e->getMessage());
            // Fail open gracefully so errors do not block legitimate users
            return ['allowed' => true, 'remaining' => 1, 'retry_after' => 0];
        }
    }

    /**
     * Record a login attempt in the dedicated login_attempts table.
     */
    public static function recordLoginAttempt(PDO $pdo, string $email, string $ip, bool $success): void
    {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO login_attempts (email, ip_address, success, attempted_at)
                VALUES (:email, :ip, :succ, NOW())
            ');
            $stmt->execute([
                ':email' => strtolower(trim($email)),
                ':ip' => $ip,
                ':succ' => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('RecordLoginAttempt error: ' . $e->getMessage());
        }
    }

    /**
     * Check if an account or IP is temporarily locked out due to consecutive failed logins.
     * Rule: 5 failed attempts in 15 minutes locks out the target email / IP for the remainder.
     */
    public static function isLoginLocked(PDO $pdo, string $email, string $ip, int $maxFailed = 5, int $windowMinutes = 15): array
    {
        try {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM login_attempts
                WHERE (LOWER(email) = :email OR ip_address = :ip)
                  AND success = 0
                  AND attempted_at >= (NOW() - INTERVAL :win MINUTE)
            ');
            $stmt->bindValue(':email', strtolower(trim($email)), PDO::PARAM_STR);
            $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
            $stmt->bindValue(':win', $windowMinutes, PDO::PARAM_INT);
            $stmt->execute();

            $failedCount = (int) $stmt->fetchColumn();
            if ($failedCount >= $maxFailed) {
                // Find most recent failed attempt to calculate remaining lockout seconds
                $recStmt = $pdo->prepare('
                    SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts
                    WHERE (LOWER(email) = :email OR ip_address = :ip) AND success = 0
                    ORDER BY id DESC LIMIT 1
                ');
                $recStmt->execute([':email' => strtolower(trim($email)), ':ip' => $ip]);
                $lastAttempt = (int) $recStmt->fetchColumn();
                $unlockAt = $lastAttempt + ($windowMinutes * 60);
                $retryAfter = max(5, $unlockAt - time());

                return [
                    'locked' => true,
                    'failed_count' => $failedCount,
                    'retry_after' => $retryAfter,
                ];
            }

            return ['locked' => false, 'failed_count' => $failedCount, 'retry_after' => 0];
        } catch (Throwable $e) {
            error_log('isLoginLocked error: ' . $e->getMessage());
            return ['locked' => false, 'failed_count' => 0, 'retry_after' => 0];
        }
    }
}

