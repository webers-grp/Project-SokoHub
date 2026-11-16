<?php
/**
 * SokoHub Order Fulfillment, Stock Restoration & Refunds Engine
 *
 * Provides transactional methods for:
 * - Cancelling orders with atomic stock restoration
 * - Managing refund requests and approvals
 * - Maintaining payment records, stock movement ledgers, and status history
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Ensures all required payment, refund, and ledger tables exist in the database.
 * Run automatically so the system is fully functional across any schema state.
 */
function ensure_order_schema_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    // 1. Payments table (source of truth for M-Pesa and card transactions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            method ENUM('mpesa', 'cash_on_delivery', 'bank_card') NOT NULL DEFAULT 'mpesa',
            amount DECIMAL(10, 2) NOT NULL,
            status ENUM('initiated', 'pending', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'initiated',
            phone VARCHAR(20) NULL,
            merchant_request_id VARCHAR(64) NULL,
            checkout_request_id VARCHAR(64) NULL,
            mpesa_receipt VARCHAR(30) NULL,
            result_code INT NULL,
            result_desc VARCHAR(255) NULL,
            attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payments_order (order_id),
            INDEX idx_payments_status (status, created_at),
            INDEX idx_payments_checkout (checkout_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2. Raw M-Pesa callbacks audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mpesa_callbacks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            checkout_request_id VARCHAR(64) NULL,
            result_code INT NULL,
            mpesa_receipt VARCHAR(30) NULL,
            payload JSON NOT NULL,
            dedupe_key CHAR(64) NOT NULL UNIQUE,
            received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_callbacks_checkout (checkout_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 3. Refunds table
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

    // 4. Stock movements ledger table
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

    // 5. Order status history audit table
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

    // Check if cancel_reason and cancelled_at columns exist on orders table
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM orders LIKE 'cancel_reason'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL, ADD COLUMN cancelled_at DATETIME NULL");
        }
    } catch (Throwable) {
        // Table or columns may already exist
    }

    $ensured = true;
}

/**
 * Cancels an order, releases reserved stock back to vendor inventory atomically,
 * and initiates an automatic refund request if payment was already collected.
 *
 * @param PDO    $pdo
 * @param int    $orderId
 * @param int    $actorUserId
 * @param string $actorRole
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

    if ($orderId <= 0) {
        return ['ok' => false, 'message' => 'Invalid order ID specified.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Order cancelled by ' . ucfirst($actorRole);
    }

    $pdo->beginTransaction();
    try {
        // 1. Lock the order row to prevent concurrent race conditions
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        // 2. Validate authorization and status rules
        $isBuyer = ($actorRole === 'buyer');
        $isSeller = ($actorRole === 'seller');
        $isAdmin = ($actorRole === 'admin');

        if ($isBuyer && (int) $order['buyer_id'] !== $actorUserId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Unauthorized: You can only cancel your own orders.'];
        }

        if ($isBuyer && !in_array($order['order_status'], ['pending', 'confirmed'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Orders that are already processing, shipped, or delivered cannot be cancelled online. Please contact support.'];
        }

        if ($order['order_status'] === 'cancelled') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This order is already cancelled.'];
        }

        if ($order['order_status'] === 'delivered') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Delivered orders cannot be cancelled. Use the return/refund process.'];
        }

        // If seller, check they have products in this order
        if ($isSeller) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND seller_id = :sid');
            $chk->execute([':oid' => $orderId, ':sid' => $actorUserId]);
            if ((int) $chk->fetchColumn() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Unauthorized: You can only cancel orders containing your own products.'];
            }
        }

        $oldStatus = $order['order_status'];

        // 3. Mark the order as cancelled
        $updOrder = $pdo->prepare(
            "UPDATE orders
             SET order_status = 'cancelled',
                 cancelled_at = NOW(),
                 cancel_reason = :reason
             WHERE id = :id"
        );
        $updOrder->execute([
            ':reason' => $reason,
            ':id'     => $orderId,
        ]);

        // 4. Update delivery dispatch record if present
        $updDelivery = $pdo->prepare(
            "UPDATE deliveries
             SET delivery_status = 'failed',
                 tracking_notes = CONCAT('Order cancelled: ', :reason)
             WHERE order_id = :oid AND delivery_status NOT IN ('delivered')"
        );
        $updDelivery->execute([
            ':reason' => $reason,
            ':oid'    => $orderId,
        ]);

        // 5. Atomic Stock Restoration: restore quantities for every item in this order
        $itemStmt = $pdo->prepare(
            'SELECT oi.product_id, oi.quantity, p.stock_qty, p.name AS product_name
             FROM order_items oi
             INNER JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :oid
             FOR UPDATE'
        );
        $itemStmt->execute([':oid' => $orderId]);
        $items = $itemStmt->fetchAll();

        $restockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid');
        $movementStmt = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by)
             VALUES (:pid, :oid, :qty, 'release', :bal, :uid)"
        );

        $restoredCount = 0;
        foreach ($items as $it) {
            $qty = (int) $it['quantity'];
            $pid = (int) $it['product_id'];
            $newBalance = (int) $it['stock_qty'] + $qty;

            $restockStmt->execute([':qty' => $qty, ':pid' => $pid]);

            try {
                $movementStmt->execute([
                    ':pid' => $pid,
                    ':oid' => $orderId,
                    ':qty' => $qty,
                    ':bal' => $newBalance,
                    ':uid' => $actorUserId,
                ]);
            } catch (Throwable) {
                // Non-fatal if stock_movements is unavailable
            }
            $restoredCount += $qty;
        }

        // 6. Handle Refund: If order was paid via M-Pesa or Card, register refund request
        $refundRequested = false;
        if ($order['payment_status'] === 'paid') {
            // Find latest payment record if available
            $payStmt = $pdo->prepare('SELECT id FROM payments WHERE order_id = :oid AND status = "succeeded" ORDER BY id DESC LIMIT 1');
            $payStmt->execute([':oid' => $orderId]);
            $payId = $payStmt->fetchColumn() ?: null;

            // Insert refund record
            $refStmt = $pdo->prepare(
                "INSERT INTO refunds (order_id, payment_id, amount, reason, status, requested_by)
                 VALUES (:oid, :pid, :amt, :reason, 'requested', :req_by)"
            );
            $refStmt->execute([
                ':oid'    => $orderId,
                ':pid'    => $payId,
                ':amt'    => (float) $order['total_amount'],
                ':reason' => 'Order cancelled: ' . $reason,
                ':req_by' => $actorUserId,
            ]);
            $refundRequested = true;
        }

        // 7. Audit log status history
        try {
            $pdo->prepare(
                'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
                 VALUES (:oid, :from, :to, :by, :note)'
            )->execute([
                ':oid'  => $orderId,
                ':from' => $oldStatus,
                ':to'   => 'cancelled',
                ':by'   => $actorUserId,
                ':note' => 'Stock restored (' . $restoredCount . ' units). ' . $reason,
            ]);
        } catch (Throwable) {
            // Non-fatal audit log
        }

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
 * Processes an existing refund request (approve, complete with M-Pesa reference, or reject).
 * Restricted to administrators and authorized vendors.
 *
 * @param PDO    $pdo
 * @param int    $refundId
 * @param int    $actorUserId
 * @param string $actorRole
 * @param string $action       'complete' | 'approve' | 'reject'
 * @param string|null $reference Transaction / M-Pesa reversal reference
 * @param string|null $notes
 * @return array ['ok' => bool, 'message' => string]
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
        $refund = $stmt->fetch();

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
                $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :oid AND status = 'succeeded'")->execute([':oid' => $orderId]);
            }

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
