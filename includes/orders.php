<?php
/**
 * SokoHub Order Fulfillment, Stock Restoration & Refunds Engine
 *
 * Provides transactional methods for:
 * - Cancelling orders with atomic stock restoration
 * - Managing refund requests and approvals
 * - Maintaining payment records, stock movement ledgers, and status history
 * - 1-Click buyer order repopulation (Re-order)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

/**
 * Ensures all required payment, refund, ledger, and commission columns/tables exist.
 */
function ensure_order_schema_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        // 1. Raw M-Pesa callbacks audit table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mpesa_callbacks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                callback_type VARCHAR(30) NOT NULL DEFAULT 'stk',
                request_id VARCHAR(64) NULL,
                payload_hash CHAR(64) NOT NULL UNIQUE,
                payload LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_callbacks_req (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. Refunds table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS refunds (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                payment_id INT UNSIGNED NULL,
                amount DECIMAL(10, 2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                status ENUM('requested', 'approved', 'rejected', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'requested',
                requested_by INT UNSIGNED NOT NULL,
                decided_by INT UNSIGNED NULL,
                decided_at DATETIME NULL,
                provider_reference VARCHAR(64) NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_refunds_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3. Stock movements ledger table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_movements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED NULL,
                change_qty INT NOT NULL,
                reason ENUM('reserve', 'release', 'adjustment', 'import') NOT NULL,
                balance_after INT NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_stock_product (product_id, created_at),
                INDEX idx_stock_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4. Order status history audit table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS order_status_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                from_status VARCHAR(20) NULL,
                to_status VARCHAR(20) NOT NULL,
                changed_by INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_history_order (order_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5. Ensure seller_profiles has till_number, payout_type, is_open
        $cols = $pdo->query("SHOW COLUMNS FROM seller_profiles")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('till_number', $cols, true)) {
            $pdo->exec("ALTER TABLE seller_profiles ADD COLUMN till_number VARCHAR(30) NOT NULL DEFAULT '1648753' AFTER town");
        }
        if (!in_array('payout_type', $cols, true)) {
            $pdo->exec("ALTER TABLE seller_profiles ADD COLUMN payout_type ENUM('till', 'paybill', 'pochi', 'phone') NOT NULL DEFAULT 'till' AFTER till_number");
        }
        if (!in_array('is_open', $cols, true)) {
            $pdo->exec("ALTER TABLE seller_profiles ADD COLUMN is_open TINYINT(1) NOT NULL DEFAULT 1 AFTER description");
        }

        // 5b. Ensure orders table has campus_landmark
        $ordCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('campus_landmark', $ordCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN campus_landmark VARCHAR(120) NULL AFTER delivery_town");
        }

        // 6. Ensure order_items has platform_fee & seller_earnings
        $itemCols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('platform_fee', $itemCols, true)) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN platform_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER line_total");
        }
        if (!in_array('seller_earnings', $itemCols, true)) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN seller_earnings DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER platform_fee");
        }

        // Backfill zero fees
        $pdo->exec("
            UPDATE order_items
            SET platform_fee = ROUND(line_total * 0.02, 2),
                seller_earnings = ROUND(line_total - (line_total * 0.02), 2)
            WHERE (platform_fee = 0.00 OR platform_fee IS NULL) AND line_total > 0
        ");

        // 7. Ensure orders has cancel_reason, cancelled_at, delivery_landmark
        $ordCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cancel_reason', $ordCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL");
        }
        if (!in_array('cancelled_at', $ordCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL");
        }
        if (!in_array('delivery_landmark', $ordCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_landmark VARCHAR(100) NULL AFTER delivery_town");
        }

        // 8. Ensure payments table has M-Pesa tracking columns
        $payCols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('checkout_request_id', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN checkout_request_id VARCHAR(64) NULL AFTER mpesa_checkout_id");
        }
        if (!in_array('merchant_request_id', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN merchant_request_id VARCHAR(64) NULL AFTER checkout_request_id");
        }
        if (!in_array('phone', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN phone VARCHAR(20) NULL AFTER buyer_phone");
        }
        if (!in_array('result_code', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN result_code INT NULL AFTER mpesa_receipt");
        }
        if (!in_array('result_desc', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN result_desc VARCHAR(255) NULL AFTER result_code");
        }
        if (!in_array('raw_callback', $payCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN raw_callback LONGTEXT NULL AFTER failure_reason");
        }

        $ensured = true;
    } catch (Throwable $e) {
        error_log('SokoHub Order Schema Initialization Note: ' . $e->getMessage());
    }
}

/**
 * Cancels an order with atomic stock restoration.
 *
 * @param PDO    $pdo
 * @param int    $orderId
 * @param int    $actorUserId
 * @param string $actorRole   'buyer' | 'seller' | 'admin'
 * @param string $reason
 * @return array ['ok' => bool, 'message' => string, 'restored_items' => int, 'refund_requested' => bool]
 */
function cancel_order_with_stock_restore(
    PDO $pdo,
    int $orderId,
    int $actorUserId,
    string $actorRole,
    string $reason = 'Cancelled by user'
): array {
    ensure_order_schema_tables($pdo);

    $pdo->beginTransaction();
    try {
        // Lock order row
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        // Authorization checks
        if ($actorRole === 'buyer' && (int) $order['buyer_id'] !== $actorUserId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Unauthorized order cancellation.'];
        }

        if ($actorRole === 'seller') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND seller_id = :sid');
            $check->execute([':oid' => $orderId, ':sid' => $actorUserId]);
            if ((int) $check->fetchColumn() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'You can only cancel orders that contain your items.'];
            }
        }

        // Guard order states: Cannot cancel already delivered or already cancelled orders
        if ($order['order_status'] === 'delivered') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Delivered orders cannot be cancelled. Please contact support or request a return.'];
        }

        if ($order['order_status'] === 'cancelled') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This order is already cancelled.'];
        }

        $fromStatus = $order['order_status'];

        // 1. Mark order as cancelled
        $updOrder = $pdo->prepare(
            'UPDATE orders
             SET order_status = :cancelled_status,
                 cancel_reason = :reason,
                 cancelled_at = NOW()
             WHERE id = :id'
        );
        $updOrder->execute([
            ':cancelled_status' => 'cancelled',
            ':reason'           => $reason,
            ':id'               => $orderId,
        ]);

        // 2. Mark related delivery as failed / cancelled
        $pdo->prepare(
            "UPDATE deliveries
             SET delivery_status = 'failed',
                 tracking_notes = :notes
             WHERE order_id = :id AND delivery_status <> 'delivered'"
        )->execute([
            ':notes' => 'Order cancelled: ' . $reason,
            ':id'    => $orderId,
        ]);

        // 3. Atomically restore stock for all line items
        $itemsStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :id');
        $itemsStmt->execute([':id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $restoredCount = 0;
        foreach ($items as $item) {
            $pId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];

            if ($qty > 0) {
                // Increment inventory
                $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :id')
                    ->execute([':qty' => $qty, ':id' => $pId]);

                // Retrieve updated balance
                $balStmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = :id');
                $balStmt->execute([':id' => $pId]);
                $newBal = (int) $balStmt->fetchColumn();

                // Record stock movement ledger
                $pdo->prepare(
                    'INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by)
                     VALUES (:pid, :oid, :qty, :reason, :bal, :uid)'
                )->execute([
                    ':pid'    => $pId,
                    ':oid'    => $orderId,
                    ':qty'    => $qty,
                    ':reason' => 'release',
                    ':bal'    => $newBal,
                    ':uid'    => $actorUserId,
                ]);

                $restoredCount++;
            }
        }

        // 4. Handle Refunds for Paid Orders
        $refundRequested = false;
        if ($order['payment_status'] === 'paid') {
            $pdo->prepare(
                'INSERT INTO refunds (order_id, amount, reason, status, requested_by)
                 VALUES (:oid, :amt, :reason, :st, :uid)'
            )->execute([
                ':oid'    => $orderId,
                ':amt'    => (float) $order['total_amount'],
                ':reason' => 'Order cancellation: ' . $reason,
                ':st'     => 'requested',
                ':uid'    => $actorUserId,
            ]);
            $refundRequested = true;
        }

        // 5. Record Order Status History
        record_order_status_history($pdo, $orderId, $fromStatus, 'cancelled', $actorUserId, $reason);

        // 6. Record Audit Log
        Audit::record('ORDER_CANCELLED', 'orders', $orderId, [
            'order_code'       => $order['order_code'],
            'restored_items'   => $restoredCount,
            'refund_requested' => $refundRequested,
            'reason'           => $reason,
        ], $actorUserId, $actorRole);

        $pdo->commit();

        $msg = 'Order ' . $order['order_code'] . ' cancelled successfully. ' . $restoredCount . ' product item(s) restored to inventory.';
        if ($refundRequested) {
            $msg .= ' A refund request for ' . format_ksh($order['total_amount']) . ' has been recorded for processing.';
        }

        return [
            'ok'               => true,
            'message'          => $msg,
            'order_code'       => $order['order_code'],
            'restored_items'   => $restoredCount,
            'refund_requested' => $refundRequested,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('SokoHub Order Cancellation Error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to cancel order: ' . $e->getMessage()];
    }
}

/**
 * Processes a refund request (complete with M-Pesa reversal reference, approve, or reject).
 */
function process_order_refund(
    PDO $pdo,
    int $refundId,
    int $actorUserId,
    string $actorRole,
    string $action = 'complete',
    ?string $reference = null,
    ?string $notes = null
): array {
    ensure_order_schema_tables($pdo);

    if (!in_array($actorRole, ['admin', 'seller'], true)) {
        return ['ok' => false, 'message' => 'Access denied: only sellers and administrators can process refunds.'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT r.*, o.order_code, o.payment_status FROM refunds r INNER JOIN orders o ON o.id = r.order_id WHERE r.id = :id FOR UPDATE');
        $stmt->execute([':id' => $refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$refund) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Refund record not found.'];
        }

        if ($refund['status'] === 'completed') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This refund has already been completed.'];
        }

        $orderId = (int) $refund['order_id'];

        if ($action === 'complete' || $action === 'approve') {
            $newStatus = ($action === 'complete') ? 'completed' : 'approved';

            $upd = $pdo->prepare(
                'UPDATE refunds
                 SET status = :st,
                     decided_by = :uid,
                     decided_at = NOW(),
                     provider_reference = :ref,
                     notes = :notes
                 WHERE id = :id'
            );
            $upd->execute([
                ':st'    => $newStatus,
                ':uid'   => $actorUserId,
                ':ref'   => $reference ? trim($reference) : null,
                ':notes' => $notes ? trim($notes) : null,
                ':id'    => $refundId,
            ]);

            if ($newStatus === 'completed') {
                $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = :oid")->execute([':oid' => $orderId]);
                $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :oid AND status IN ('completed', 'succeeded')")->execute([':oid' => $orderId]);
            }

            Audit::record('REFUND_PROCESSED', 'refunds', $refundId, [
                'order_id'   => $orderId,
                'status'     => $newStatus,
                'reference'  => $reference,
            ], $actorUserId, $actorRole);

            $pdo->commit();
            return [
                'ok'      => true,
                'message' => 'Refund for order ' . $refund['order_code'] . ' marked as ' . ucfirst($newStatus) . '.',
            ];
        }

        if ($action === 'reject') {
            $upd = $pdo->prepare(
                "UPDATE refunds
                 SET status = 'rejected',
                     decided_by = :uid,
                     decided_at = NOW(),
                     notes = :notes
                 WHERE id = :id"
            );
            $upd->execute([
                ':uid'   => $actorUserId,
                ':notes' => $notes ? trim($notes) : 'Rejected by ' . ucfirst($actorRole),
                ':id'    => $refundId,
            ]);

            Audit::record('REFUND_REJECTED', 'refunds', $refundId, [
                'order_id' => $orderId,
                'notes'    => $notes,
            ], $actorUserId, $actorRole);

            $pdo->commit();
            return [
                'ok'      => true,
                'message' => 'Refund request for order ' . $refund['order_code'] . ' was rejected.',
            ];
        }

        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Invalid refund action specified.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Failed to process refund: ' . $e->getMessage()];
    }
}

/**
 * Records an order status transition.
 */
function record_order_status_history(PDO $pdo, int $orderId, ?string $fromStatus, string $toStatus, ?int $changedBy, ?string $note): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
             VALUES (:oid, :from_st, :to_st, :uid, :note)'
        );
        $stmt->execute([
            ':oid'     => $orderId,
            ':from_st' => $fromStatus,
            ':to_st'   => $toStatus,
            ':uid'     => $changedBy,
            ':note'    => $note,
        ]);
    } catch (Throwable) {
        // Non-fatal status audit
    }
}

/**
 * Retrieves audit history for an order.
 */
function get_order_status_history(PDO $pdo, int $orderId): array
{
    ensure_order_schema_tables($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT h.*, u.full_name, u.role
             FROM order_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.order_id = :oid
             ORDER BY h.created_at ASC, h.id ASC'
        );
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * 1-Click Re-order: Adds items from a past order back into buyer cart.
 */
function reorder_order_items(int $orderId): array
{
    $pdo = get_db_connection();
    $stmt = $pdo->prepare(
        'SELECT oi.product_id, oi.quantity, p.name, p.price, p.stock_qty, p.status
         FROM order_items oi
         INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = :oid'
    );
    $stmt->execute([':oid' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return ['ok' => false, 'message' => 'No products found in this order.'];
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $addedCount = 0;
    foreach ($items as $item) {
        if ($item['status'] === 'active' && (int) $item['stock_qty'] > 0) {
            $pId = (int) $item['product_id'];
            $reqQty = (int) $item['quantity'];
            $maxQty = min($reqQty, (int) $item['stock_qty']);

            $_SESSION['cart'][$pId] = ($_SESSION['cart'][$pId] ?? 0) + $maxQty;
            $addedCount++;
        }
    }

    if ($addedCount === 0) {
        return ['ok' => false, 'message' => 'The items in this order are currently out of stock.'];
    }

    return [
        'ok'              => true,
        'message'         => "Added {$addedCount} item(s) to your cart.",
        'added_count'     => $addedCount,
        'reordered_count' => $addedCount,
    ];
}
