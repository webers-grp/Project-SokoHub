<?php
/**
 * SokoHub User Management & Administration Service
 * Provides secure administrative operations for inspecting, filtering,
 * unlocking, suspending, verifying, and removing users with complete audit logging.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class UserManagement
{
    /**
     * Retrieve aggregated user statistics for admin KPI dashboard.
     *
     * @return array<string, int>
     */
    public static function getStats(): array
    {
        $pdo = get_db_connection();

        $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $activeBuyers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'buyer' AND status = 'active'")->fetchColumn();
        $totalSellers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller'")->fetchColumn();
        $totalRiders = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'delivery'")->fetchColumn();
        $suspendedUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
        $pendingVerification = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending_verification'")->fetchColumn();
        $lockedAccounts = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()")->fetchColumn();

        return [
            'total'                => $totalUsers,
            'buyers_active'        => $activeBuyers,
            'sellers'              => $totalSellers,
            'riders'               => $totalRiders,
            'suspended'            => $suspendedUsers,
            'pending_verification' => $pendingVerification,
            'locked'               => $lockedAccounts,
        ];
    }

    /**
     * Count users matching filter criteria.
     *
     * @param array $filters [q, role, status, locked]
     */
    public static function countUsers(array $filters = []): int
    {
        $pdo = get_db_connection();
        [$whereSql, $params] = self::buildFilterClauses($filters);

        $sql = "SELECT COUNT(DISTINCT u.id) 
                FROM users u 
                LEFT JOIN seller_profiles sp ON u.id = sp.user_id 
                LEFT JOIN delivery_profiles dp ON u.id = dp.user_id 
                $whereSql";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Retrieve paginated list of users matching filters.
     *
     * @param array $filters [q, role, status, locked]
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getUsers(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $pdo = get_db_connection();
        [$whereSql, $params] = self::buildFilterClauses($filters);

        $sql = "SELECT 
                    u.id,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.role,
                    u.status,
                    u.created_at,
                    u.last_seen_at,
                    u.last_login_at,
                    u.email_verified_at,
                    u.failed_login_count,
                    u.locked_until,
                    u.two_factor_enabled,
                    sp.business_name,
                    sp.town,
                    sp.stall_location,
                    sp.verification_status AS seller_kyc_status,
                    dp.transport_method,
                    dp.vehicle_plate_number,
                    dp.is_available AS rider_is_available,
                    dp.verification_status AS rider_kyc_status,
                    (SELECT COUNT(*) FROM orders o WHERE o.buyer_id = u.id) AS buyer_order_count,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.seller_id = u.id) AS seller_sales_count
                FROM users u 
                LEFT JOIN seller_profiles sp ON u.id = sp.user_id 
                LEFT JOIN delivery_profiles dp ON u.id = dp.user_id 
                $whereSql
                ORDER BY u.id DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get single user full details.
     */
    public static function getUserDetails(int $id): ?array
    {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT 
                u.*,
                sp.business_name,
                sp.business_type,
                sp.town,
                sp.stall_location,
                sp.operating_hours,
                sp.whatsapp_phone,
                sp.verification_status AS seller_kyc_status,
                dp.transport_method,
                dp.vehicle_plate_number,
                dp.vehicle_model,
                dp.license_number,
                dp.service_area,
                dp.is_available AS rider_is_available,
                dp.verification_status AS rider_kyc_status,
                (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) AS buyer_order_count,
                (SELECT COUNT(*) FROM order_items WHERE seller_id = u.id) AS seller_item_count
            FROM users u
            LEFT JOIN seller_profiles sp ON u.id = sp.user_id
            LEFT JOIN delivery_profiles dp ON u.id = dp.user_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Suspend user account with an administrative note.
     */
    public static function suspendUser(int $targetUserId, int $adminUserId, string $reason = ''): bool
    {
        if ($targetUserId === $adminUserId) {
            return false;
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id");
        $stmt->execute([':id' => $targetUserId]);

        Audit::record('USER_SUSPENDED', 'users', $targetUserId, [
            'reason' => $reason ?: 'Suspended by admin',
            'action_timestamp' => date('Y-m-d H:i:s'),
        ], $adminUserId, 'admin');

        return true;
    }

    /**
     * Reactivate a suspended or inactive user account.
     */
    public static function reactivateUser(int $targetUserId, int $adminUserId): bool
    {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("UPDATE users SET status = 'active', locked_until = NULL, failed_login_count = 0 WHERE id = :id");
        $stmt->execute([':id' => $targetUserId]);

        Audit::record('USER_REACTIVATED', 'users', $targetUserId, [
            'action_timestamp' => date('Y-m-d H:i:s'),
        ], $adminUserId, 'admin');

        return true;
    }

    /**
     * Unlock an account locked by brute-force protection.
     */
    public static function unlockAccount(int $targetUserId, int $adminUserId): bool
    {
        $pdo = get_db_connection();
        $user = self::getUserDetails($targetUserId);
        if (!$user) {
            return false;
        }

        $stmt = $pdo->prepare("
            UPDATE users 
            SET locked_until = NULL, 
                failed_login_count = 0 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $targetUserId]);

        // Clean up recent failed attempts for this email
        try {
            $pdo->prepare("DELETE FROM login_attempts WHERE email = :email AND success = 0")->execute([':email' => $user['email']]);
        } catch (Throwable $_) {}

        Audit::record('USER_UNLOCKED', 'users', $targetUserId, [
            'email' => $user['email'],
            'previous_failures' => $user['failed_login_count'],
        ], $adminUserId, 'admin');

        return true;
    }

    /**
     * Manually mark user's email as verified and activate account.
     */
    public static function verifyUserEmail(int $targetUserId, int $adminUserId): bool
    {
        $pdo = get_db_connection();
        $user = self::getUserDetails($targetUserId);
        if (!$user) {
            return false;
        }

        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = 'active', 
                email_verified_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $targetUserId]);

        // Invalidate active OTPs
        try {
            $pdo->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE user_id = :id AND consumed_at IS NULL")
                ->execute([':id' => $targetUserId]);
        } catch (Throwable $_) {}

        Audit::record('USER_MANUALLY_VERIFIED', 'users', $targetUserId, [
            'email' => $user['email'],
        ], $adminUserId, 'admin');

        return true;
    }

    /**
     * Update user role (buyer, seller, delivery, admin).
     */
    public static function updateRole(int $targetUserId, string $newRole, int $adminUserId): bool
    {
        if (!in_array($newRole, ['buyer', 'seller', 'delivery', 'admin'], true)) {
            return false;
        }

        $pdo = get_db_connection();
        $user = self::getUserDetails($targetUserId);
        if (!$user) {
            return false;
        }

        // Prevent demoting the last active administrator
        if ($user['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($adminCount <= 1) {
                return false;
            }
        }

        $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
        $stmt->execute([':role' => $newRole, ':id' => $targetUserId]);

        // If newly designated seller, ensure seller_profiles record exists
        if ($newRole === 'seller' && empty($user['business_name'])) {
            $spStmt = $pdo->prepare("
                INSERT IGNORE INTO seller_profiles (user_id, business_name, business_type, town)
                VALUES (:uid, :bname, 'retail', 'Kutus')
            ");
            $spStmt->execute([':uid' => $targetUserId, ':bname' => $user['full_name'] . "'s Store"]);
        }

        // If newly designated delivery, ensure delivery_profiles record exists
        if ($newRole === 'delivery' && empty($user['license_number'])) {
            $dpStmt = $pdo->prepare("
                INSERT IGNORE INTO delivery_profiles (user_id, transport_method, license_number)
                VALUES (:uid, 'motorbike', 'PENDING-LIC')
            ");
            $dpStmt->execute([':uid' => $targetUserId]);
        }

        Audit::record('USER_ROLE_CHANGED', 'users', $targetUserId, [
            'old_role' => $user['role'],
            'new_role' => $newRole,
        ], $adminUserId, 'admin');

        return true;
    }

    /**
     * Delete or safely anonymize a user.
     *
     * If user has no financial orders/order_items: permanently hard-deletes the user
     * and cascades associated profiles, wishlists, addresses, tokens, and logs.
     *
     * If user has existing orders: soft-deletes and anonymizes personal data to preserve
     * financial transactions and delivery audit compliance.
     *
     * @param int $targetUserId
     * @param int $adminUserId
     * @param string|null $errorMsg
     * @param string|null $mode 'hard_deleted' or 'archived_anonymized'
     * @return bool
     */
    public static function deleteUser(int $targetUserId, int $adminUserId, ?string &$errorMsg = null, ?string &$mode = null): bool
    {
        if ($targetUserId === $adminUserId) {
            $errorMsg = 'Self-deletion is blocked. You cannot delete your own active administrator account.';
            return false;
        }

        $pdo = get_db_connection();
        $target = self::getUserDetails($targetUserId);
        if (!$target) {
            $errorMsg = 'Target user does not exist.';
            return false;
        }

        // Check if target is the last administrator
        if ($target['role'] === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($adminCount <= 1) {
                $errorMsg = 'Cannot delete the only remaining active administrator on the platform.';
                return false;
            }
        }

        // Check for existing orders
        $orderCount = (int) $pdo->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id = :id")->execute([':id' => $targetUserId])
            ? (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE buyer_id = " . (int) $targetUserId)->fetchColumn()
            : 0;

        $salesCount = (int) $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE seller_id = :id")->execute([':id' => $targetUserId])
            ? (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE seller_id = " . (int) $targetUserId)->fetchColumn()
            : 0;

        $hasFinancialHistory = ($orderCount > 0 || $salesCount > 0);

        $pdo->beginTransaction();
        try {
            if (!$hasFinancialHistory) {
                // Scenario A: Clean permanent hard delete
                // 1. Clean up child records referencing users
                $pdo->prepare("DELETE FROM seller_documents WHERE seller_user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM seller_payouts WHERE seller_user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM bulk_imports WHERE seller_user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM notification_reads WHERE user_id = :id")->execute([':id' => $targetUserId]);
                try {
                    $pdo->prepare("DELETE FROM notifications WHERE user_id = :id")->execute([':id' => $targetUserId]);
                } catch (Throwable $_) {}
                $pdo->prepare("DELETE FROM otp_codes WHERE user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("UPDATE deliveries SET delivery_person_id = NULL WHERE delivery_person_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("UPDATE deliveries SET assigned_by_seller_id = NULL WHERE assigned_by_seller_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("UPDATE stock_movements SET created_by = NULL WHERE created_by = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("UPDATE audit_logs SET actor_user_id = NULL WHERE actor_user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM products WHERE seller_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $targetUserId]);

                $mode = 'hard_deleted';
            } else {
                // Scenario B: User has orders. Anonymize and suspend to prevent orphan foreign key breaches.
                $anonEmail = sprintf('archived_%d_%s@anonymized.sokohub', $targetUserId, bin2hex(random_bytes(3)));
                $anonName = sprintf('Archived User #%d', $targetUserId);
                $anonPhone = '+254000000000';

                $upStmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = :name,
                        email = :email,
                        phone = :phone,
                        password_hash = 'ACCOUNT_REMOVED_BY_ADMIN',
                        status = 'suspended',
                        locked_until = '2099-12-31 23:59:59',
                        failed_login_count = 999
                    WHERE id = :id
                ");
                $upStmt->execute([
                    ':name'  => $anonName,
                    ':email' => $anonEmail,
                    ':phone' => $anonPhone,
                    ':id'    => $targetUserId,
                ]);

                // Clear tokens & active listings
                $pdo->prepare("DELETE FROM otp_codes WHERE user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = :id")->execute([':id' => $targetUserId]);
                $pdo->prepare("UPDATE products SET status = 'inactive' WHERE seller_id = :id")->execute([':id' => $targetUserId]);

                $mode = 'archived_anonymized';
            }

            $pdo->commit();

            Audit::record('USER_REMOVED', 'users', $targetUserId, [
                'target_name'  => $target['full_name'],
                'target_email' => $target['email'],
                'target_role'  => $target['role'],
                'mode'         => $mode,
                'orders_count' => $orderCount,
                'sales_count'  => $salesCount,
            ], $adminUserId, 'admin');

            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errorMsg = 'Database operation failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Helper to construct WHERE SQL and parameters for user queries.
     */
    private static function buildFilterClauses(array $filters): array
    {
        $clauses = [];
        $params  = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = "(u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.phone LIKE :q3 OR sp.business_name LIKE :q4 OR dp.vehicle_plate_number LIKE :q5)";
            $qVal = '%' . $q . '%';
            $params[':q1'] = $qVal;
            $params[':q2'] = $qVal;
            $params[':q3'] = $qVal;
            $params[':q4'] = $qVal;
            $params[':q5'] = $qVal;
        }

        $role = trim((string) ($filters['role'] ?? ''));
        if ($role !== '' && in_array($role, ['buyer', 'seller', 'delivery', 'admin'], true)) {
            $clauses[] = "u.role = :role";
            $params[':role'] = $role;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, ['active', 'suspended', 'pending_verification'], true)) {
            $clauses[] = "u.status = :status";
            $params[':status'] = $status;
        }

        $locked = (string) ($filters['locked'] ?? '');
        if ($locked === '1' || $locked === 'true') {
            $clauses[] = "u.locked_until IS NOT NULL AND u.locked_until > NOW()";
        }

        $whereSql = !empty($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$whereSql, $params];
    }
}
