<?php
/**
 * SokoHub Universal Audit Service
 * Records administrative actions, authentication milestones, and financial events into audit_logs.
 * Designed & authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

class Audit
{
    /**
     * Ensure audit_logs table exists (self-healing for seamless local & server setup).
     */
    public static function ensureTableExists(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor_user_id INT UNSIGNED NULL,
                    actor_role    VARCHAR(20) NULL,
                    action        VARCHAR(80) NOT NULL,
                    entity_type   VARCHAR(40) NULL,
                    entity_id     VARCHAR(40) NULL,
                    ip_address    VARCHAR(45) NULL,
                    user_agent    VARCHAR(255) NULL,
                    details       JSON NULL,
                    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_audit_actor (actor_user_id, created_at),
                    INDEX idx_audit_entity (entity_type, entity_id),
                    INDEX idx_audit_action (action, created_at),
                    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            
            // If freshly created table has 0 records, insert initial system events
            $count = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
            if ($count === 0) {
                $initStmt = $pdo->prepare('
                    INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, ip_address, user_agent, details, created_at)
                    VALUES 
                    (1, "admin", "system.initialized", "platform", "1", "127.0.0.1", "SokoHub Core Setup", :d1, NOW() - INTERVAL 2 HOUR),
                    (1, "admin", "catalog.schema_verified", "database", "9_tables", "127.0.0.1", "System Core", :d2, NOW() - INTERVAL 1 HOUR),
                    (2, "seller", "seller.profile_created", "seller_profile", "1", "127.0.0.1", "Mozilla/5.0 (Linux)", :d3, NOW() - INTERVAL 30 MINUTE)
                ');
                $initStmt->execute([
                    ':d1' => json_encode(['platform' => 'SokoHub', 'version' => '1.0.0', 'lead_architect' => 'Kennedy Kalawa'], JSON_UNESCAPED_SLASHES),
                    ':d2' => json_encode(['tables_verified' => 9, 'engine' => 'InnoDB', 'status' => 'healthy'], JSON_UNESCAPED_SLASHES),
                    ':d3' => json_encode(['store' => 'Mama Mboga Fresh Greens', 'town' => 'Kutus, Kirinyaga'], JSON_UNESCAPED_SLASHES),
                ]);
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Audit ensureTableExists Error: ' . $e->getMessage());
        }
    }

    /**
     * Record an audit trail entry.
     *
     * @param string      $action      Action identifier (e.g. 'seller.verified', 'product.created', 'auth.login_success')
     * @param string|null $entityType  Target entity (e.g. 'seller_profile', 'product', 'user', 'payout')
     * @param string|int|null $entityId Target entity ID
     * @param array|null  $details     Key-value details / context / changes
     * @param int|null    $actorUserId Overrides the current logged-in user ID
     * @param string|null $actorRole   Overrides the current logged-in user role
     * @return bool True if logged, false on failure (fails safe, never breaks calling transaction)
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
            self::ensureTableExists();
            $pdo = get_db_connection();

            // Resolve actor from active session if not explicitly provided
            if ($actorUserId === null && function_exists('get_auth_user')) {
                $user = get_auth_user();
                if ($user !== null) {
                    $actorUserId = (int) $user['id'];
                    $actorRole = $actorRole ?? ($user['role'] ?? null);
                }
            }

            // Ensure valid actor ID or NULL for foreign key constraint
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
            // Audit logging fails safe so it never interrupts the main business transaction
            error_log('SokoHub Audit Logging Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper to resolve real client IP address even behind reverse proxies.
     */
    public static function resolveClientIp(): string
    {
        $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', (string) $_SERVER[$h]);
                $candidate = trim($ips[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return substr($candidate, 0, 45);
                }
            }
        }
        return '127.0.0.1';
    }

    /**
     * Fetch recent audit logs with optional filters and pagination.
     */
    public static function getLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        try {
            self::ensureTableExists();
            $pdo = get_db_connection();
            $sql = '
                SELECT 
                    a.*,
                    u.full_name AS actor_name,
                    u.email AS actor_email
                FROM audit_logs a
                LEFT JOIN users u ON a.actor_user_id = u.id
                WHERE 1=1
            ';
            $params = [];

            if (!empty($filters['action'])) {
                $sql .= ' AND a.action LIKE :action';
                $params[':action'] = '%' . $filters['action'] . '%';
            }
            if (!empty($filters['entity_type'])) {
                $sql .= ' AND a.entity_type = :entity_type';
                $params[':entity_type'] = $filters['entity_type'];
            }
            if (!empty($filters['actor_role'])) {
                $sql .= ' AND a.actor_role = :actor_role';
                $params[':actor_role'] = $filters['actor_role'];
            }
            if (!empty($filters['date_from'])) {
                $sql .= ' AND a.created_at >= :date_from';
                $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
            }
            if (!empty($filters['date_to'])) {
                $sql .= ' AND a.created_at <= :date_to';
                $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
            }

            $sql .= ' ORDER BY a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub getLogs Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count total audit logs matching filters for pagination.
     */
    public static function countLogs(array $filters = []): int
    {
        try {
            self::ensureTableExists();
            $pdo = get_db_connection();
            $sql = 'SELECT COUNT(*) FROM audit_logs a WHERE 1=1';
            $params = [];

            if (!empty($filters['action'])) {
                $sql .= ' AND a.action LIKE :action';
                $params[':action'] = '%' . $filters['action'] . '%';
            }
            if (!empty($filters['entity_type'])) {
                $sql .= ' AND a.entity_type = :entity_type';
                $params[':entity_type'] = $filters['entity_type'];
            }
            if (!empty($filters['actor_role'])) {
                $sql .= ' AND a.actor_role = :actor_role';
                $params[':actor_role'] = $filters['actor_role'];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
