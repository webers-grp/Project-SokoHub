<?php
/**
 * Developer Console Append-Only Audit Logger
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/security.php';

class DevAudit
{
    public static function log(string $category, string $action, ?string $target = null, array $details = [], string $result = 'success'): void
    {
        try {
            $pdo = get_db_connection();
            $devId = $_SESSION['dev_user']['id'] ?? null;
            $username = $_SESSION['dev_user']['username'] ?? 'anonymous';
            $ip = Security::getClientIp();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);

            // Clean details (ensure no passwords or secrets logged)
            $cleanDetails = self::maskSensitiveDetails($details);

            $stmt = $pdo->prepare('
                INSERT INTO devconsole_audit_logs (developer_id, username, ip_address, user_agent, category, action, target, details, result, created_at)
                VALUES (:devid, :uname, :ip, :ua, :cat, :act, :tgt, :det, :res, NOW())
            ');
            $stmt->execute([
                ':devid' => $devId,
                ':uname' => substr($username, 0, 50),
                ':ip'    => $ip,
                ':ua'    => $ua,
                ':cat'   => substr($category, 0, 40),
                ':act'   => substr($action, 0, 64),
                ':tgt'   => $target ? substr($target, 0, 120) : null,
                ':det'   => !empty($cleanDetails) ? json_encode($cleanDetails, JSON_UNESCAPED_SLASHES) : null,
                ':res'   => in_array($result, ['success', 'failed', 'blocked'], true) ? $result : 'success',
            ]);
        } catch (Throwable $e) {
            error_log('[DevAudit Error] ' . $e->getMessage());
        }
    }

    private static function maskSensitiveDetails(array $data): array
    {
        $sensitiveKeys = ['password', 'password_hash', 'totp_secret', 'passkey', 'consumer_secret', 'security_credential'];
        $clean = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $clean[$k] = self::maskSensitiveDetails($v);
            } elseif (in_array(strtolower((string) $k), $sensitiveKeys, true)) {
                $clean[$k] = '********';
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }
}
