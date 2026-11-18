<?php
/**
 * SokoHub Universal Audit Service
 * Records administrative actions, authentication milestones, and financial events into audit_logs.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

class Audit
{
    /**
     * Record an audit trail entry safely. Never breaks calling transaction on failure.
     *
     * @param string $action Action identifier (e.g. 'auth.login', 'payment.verified', 'payout.disbursed')
     * @param string|null $entityType Target entity (e.g. 'order', 'payment', 'payout', 'seller_profile', 'kyc')
     * @param string|int|null $entityId Target entity ID
     * @param array|null $details Contextual key-value details
     * @param int|null $actorUserId Override actor user ID (defaults to current user)
     * @param string|null $actorRole Override actor role
     * @return bool True if logged
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        $entityId = null,
        ?array $details = null,
        ?int $actorUserId = null,
        ?string $actorRole = null
    ): bool {
        try {
            $pdo = get_db_connection();

            if ($actorUserId === null && function_exists('current_user')) {
                $user = current_user();
                if ($user !== null) {
                    $actorUserId = (int) $user['id'];
                    $actorRole = $actorRole ?? ($user['role'] ?? null);
                }
            }

            $actorUserId = ($actorUserId !== null && $actorUserId > 0) ? (int) $actorUserId : null;
            $ip = self::resolveClientIp();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);
            $detailsJson = ($details !== null && !empty($details))
                ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;

            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (
                    actor_user_id,
                    actor_role,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    user_agent,
                    details
                ) VALUES (
                    :actor_user_id,
                    :actor_role,
                    :action,
                    :entity_type,
                    :entity_id,
                    :ip_address,
                    :user_agent,
                    :details
                )
            ');

            return $stmt->execute([
                ':actor_user_id' => $actorUserId,
                ':actor_role'    => $actorRole,
                ':action'        => substr($action, 0, 80),
                ':entity_type'   => $entityType !== null ? substr($entityType, 0, 40) : null,
                ':entity_id'     => $entityId !== null ? substr((string) $entityId, 0, 40) : null,
                ':ip_address'    => $ip,
                ':user_agent'    => $userAgent,
                ':details'       => $detailsJson,
            ]);
        } catch (Throwable $e) {
            error_log('SokoHub Audit Log Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve client IP safely considering proxies.
     */
    public static function resolveClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $raw = (string) $_SERVER[$h];
                $first = trim(explode(',', $raw)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return substr($first, 0, 45);
                }
            }
        }
        return '127.0.0.1';
    }
}
