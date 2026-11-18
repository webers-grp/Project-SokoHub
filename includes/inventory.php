<?php
/**
 * SokoHub Low-Stock Automated Alerts Engine
 * Monitors product stock levels against configurable per-product thresholds,
 * flags automated warnings for merchants and admins, and logs inventory audit trails.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class Inventory
{
    /**
     * Retrieve all products where stock_qty <= low_stock_threshold.
     */
    public static function getLowStockProducts(?int $sellerId = null, bool $activeOnly = true): array
    {
        try {
            $pdo = get_db_connection();

            $sql = '
                SELECT 
                    p.id,
                    p.seller_id,
                    p.category_id,
                    p.name,
                    p.description,
                    p.price,
                    p.original_price,
                    p.stock_qty,
                    p.low_stock_threshold,
                    p.low_stock_alerted_at,
                    p.condition_type,
                    p.image_path,
                    p.status,
                    c.name AS category_name,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    u.phone AS seller_phone,
                    sp.business_name,
                    (p.low_stock_threshold - p.stock_qty) AS stock_deficit,
                    (p.stock_qty <= 0) AS is_out_of_stock
                FROM products p
                INNER JOIN categories c ON p.category_id = c.id
                INNER JOIN users u ON p.seller_id = u.id
                LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                WHERE p.stock_qty <= p.low_stock_threshold
            ';

            $params = [];
            if ($activeOnly) {
                $sql .= " AND p.status = 'active'";
            }
            if ($sellerId !== null) {
                $sql .= ' AND p.seller_id = :sid';
                $params[':sid'] = $sellerId;
            }

            $sql .= ' ORDER BY (p.stock_qty <= 0) DESC, p.stock_qty ASC, p.name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Inventory::getLowStockProducts Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count products currently at or below low stock threshold.
     */
    public static function getLowStockCount(?int $sellerId = null): int
    {
        try {
            $pdo = get_db_connection();
            $sql = "SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_qty <= low_stock_threshold";
            $params = [];
            if ($sellerId !== null) {
                $sql .= ' AND seller_id = :sid';
                $params[':sid'] = $sellerId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Inventory::getLowStockCount Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if a single product has breached its threshold, triggers automated alert timestamp,
     * and records an audit log entry.
     */
    public static function checkProductLowStock(int $productId, ?string $triggerReason = null): ?array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT p.*, u.full_name AS seller_name, u.email AS seller_email, sp.business_name
                FROM products p
                INNER JOIN users u ON p.seller_id = u.id
                LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                WHERE p.id = :id LIMIT 1
            ');
            $stmt->execute([':id' => $productId]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                return null;
            }

            $currentStock = (int) $prod['stock_qty'];
            $threshold    = (int) ($prod['low_stock_threshold'] ?? 5);

            if ($currentStock <= $threshold) {
                if ($prod['low_stock_alerted_at'] === null) {
                    $pdo->prepare('UPDATE products SET low_stock_alerted_at = NOW() WHERE id = :id')
                        ->execute([':id' => $productId]);

                    Audit::record('inventory.low_stock_alert', 'product', $productId, [
                        'product_name'   => $prod['name'],
                        'current_stock'  => $currentStock,
                        'threshold'      => $threshold,
                        'trigger_reason' => $triggerReason ?? 'Stock decrement below threshold',
                        'seller_id'      => (int) $prod['seller_id'],
                    ], (int) $prod['seller_id'], 'system');

                    $sellerName = $prod['business_name'] ?: $prod['seller_name'];
                    Notifications::notifyUser(
                        (int) $prod['seller_id'],
                        'system',
                        'Low Stock Warning: ' . $prod['name'],
                        "Your item '{$prod['name']}' has only {$currentStock} unit(s) remaining (threshold: {$threshold}). Please restock soon.",
                        'pages/dashboard/manage_products.php'
                    );
                }

                return [
                    'product_id'    => $productId,
                    'name'          => $prod['name'],
                    'current_stock' => $currentStock,
                    'threshold'     => $threshold,
                    'is_out'        => ($currentStock <= 0),
                ];
            } else {
                if ($prod['low_stock_alerted_at'] !== null) {
                    $pdo->prepare('UPDATE products SET low_stock_alerted_at = NULL WHERE id = :id')
                        ->execute([':id' => $productId]);
                }
                return null;
            }
        } catch (Throwable $e) {
            error_log('Inventory::checkProductLowStock Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Replenish stock quantity for a product, automatically clearing low-stock alerts.
     */
    public static function replenishStock(int $productId, int $newQuantity, int $actorUserId, string $actorRole = 'seller'): array
    {
        if ($newQuantity < 0) {
            return ['ok' => false, 'error' => 'Stock quantity cannot be negative.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $productId]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                return ['ok' => false, 'error' => 'Product not found.'];
            }

            $oldStock  = (int) $prod['stock_qty'];
            $threshold = (int) ($prod['low_stock_threshold'] ?? 5);
            $clearedAlert = false;

            if ($newQuantity > $threshold) {
                $upd = $pdo->prepare('UPDATE products SET stock_qty = :qty, low_stock_alerted_at = NULL WHERE id = :id');
                $clearedAlert = true;
            } else {
                $upd = $pdo->prepare('UPDATE products SET stock_qty = :qty WHERE id = :id');
            }

            $upd->execute([':qty' => $newQuantity, ':id' => $productId]);

            // Record movement
            $diff = $newQuantity - $oldStock;
            $pdo->prepare('
                INSERT INTO stock_movements (product_id, change_qty, reason, balance_after, created_by, created_at)
                VALUES (:pid, :diff, "adjustment", :bal, :uid, NOW())
            ')->execute([
                ':pid'  => $productId,
                ':diff' => $diff,
                ':bal'  => $newQuantity,
                ':uid'  => $actorUserId,
            ]);

            Audit::record('inventory.replenished', 'product', $productId, [
                'product_name' => $prod['name'],
                'previous_qty' => $oldStock,
                'new_qty'      => $newQuantity,
                'threshold'    => $threshold,
                'alert_cleared'=> $clearedAlert,
            ], $actorUserId, $actorRole);

            if ($newQuantity > $oldStock) {
                $sStmt = $pdo->prepare('
                    SELECT COALESCE(sp.business_name, u.full_name) AS seller_name
                    FROM users u
                    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                    WHERE u.id = :sid LIMIT 1
                ');
                $sStmt->execute([':sid' => $prod['seller_id']]);
                $sellerName = $sStmt->fetchColumn() ?: 'Local Vendor';
                Notifications::notifyRestock($productId, $prod['name'], $newQuantity, (string) $sellerName);
            }

            return [
                'ok'            => true,
                'previous_qty'  => $oldStock,
                'new_qty'       => $newQuantity,
                'alert_cleared' => $clearedAlert,
            ];
        } catch (Throwable $e) {
            error_log('Inventory::replenishStock Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error while replenishing stock: ' . $e->getMessage()];
        }
    }

    /**
     * Update the low-stock alert threshold for a specific product.
     */
    public static function updateThreshold(int $productId, int $newThreshold, int $actorUserId, string $actorRole = 'seller'): array
    {
        if ($newThreshold < 1) {
            return ['ok' => false, 'error' => 'Low-stock threshold must be at least 1 unit.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $productId]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                return ['ok' => false, 'error' => 'Product not found.'];
            }

            $oldThreshold = (int) ($prod['low_stock_threshold'] ?? 5);

            $pdo->prepare('UPDATE products SET low_stock_threshold = :th WHERE id = :id')
                ->execute([':th' => $newThreshold, ':id' => $productId]);

            Audit::record('inventory.threshold_changed', 'product', $productId, [
                'product_name'  => $prod['name'],
                'old_threshold' => $oldThreshold,
                'new_threshold' => $newThreshold,
            ], $actorUserId, $actorRole);

            self::checkProductLowStock($productId, 'Threshold changed to ' . $newThreshold);

            return ['ok' => true, 'new_threshold' => $newThreshold];
        } catch (Throwable $e) {
            error_log('Inventory::updateThreshold Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error updating threshold.'];
        }
    }
}
