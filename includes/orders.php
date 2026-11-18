<?php
/**
 * SokoHub Order Fulfillment, Stock Restoration & Refunds Engine
 *
 * Provides transactional methods for:
 * - Cancelling orders with atomic stock restoration
 * - Managing refund requests, approvals, and payout reversals
 * - Recording order status history and audit events
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

/**
 * Record an entry in order_status_history.
 */
function record_order_status_history(
    PDO $pdo,
    int $orderId,
    string $toStatus,
    ?string $fromStatus = null,
    ?int $changedBy = null,
    ?string $location = null,
    ?string $notes = null
): void {
    try {
        if ($fromStatus === null) {
            $stmt = $pdo->prepare('SELECT order_status FROM orders WHERE id = :id');
            $stmt->execute([':id' => $orderId]);
            $fromStatus = $stmt->fetchColumn() ?: null;
        }

        $ins = $pdo->prepare('
            INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, location_note, notes, created_at)
            VALUES (:oid, :from_s, :to_s, :uid, :loc, :notes, NOW())
        ');
        $ins->execute([
            ':oid'    => $orderId,
            ':from_s' => $fromStatus,
            ':to_s'   => $toStatus,
            ':uid'    => $changedBy,
            ':loc'    => $location,
            ':notes'  => $notes,
        ]);
    } catch (Throwable $e) {
        error_log('record_order_status_history error: ' . $e->getMessage());
    }
}

/**
 * Get order status history timeline.
 */
function get_order_status_history(PDO $pdo, int $orderId): array
{
    try {
        $stmt = $pdo->prepare('
            SELECT osh.*, u.full_name AS changed_by_name, u.role AS changed_by_role
            FROM order_status_history osh
            LEFT JOIN users u ON u.id = osh.changed_by
            WHERE osh.order_id = :oid
            ORDER BY osh.created_at ASC, osh.id ASC
        ');
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_order_status_history error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Cancels an order, restocks inventory with stock_movements ledger entries,
 * creates refund request if paid, and reverses/cancels any pending payouts.
 */
function cancel_order(PDO $pdo, int $orderId, int $actorUserId, string $actorRole, string $reason = 'Customer requested cancellation'): array
{
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            if ($ownsTx) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        // Authorization check: buyer can only cancel their own order; seller/admin can cancel
        if ($actorRole === 'buyer' && (int) $order['buyer_id'] !== $actorUserId) {
            if ($ownsTx) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Unauthorized: You can only cancel your own orders.'];
        }

        if (in_array($order['order_status'], ['delivered', 'cancelled'], true)) {
            if ($ownsTx) $pdo->rollBack();
            return ['ok' => false, 'message' => "Order cannot be cancelled because it is already {$order['order_status']}."];
        }

        $prevStatus = $order['order_status'];

        // 1. Restore product stock atomically
        $itemStmt = $pdo->prepare('
            SELECT oi.product_id, oi.quantity, p.name AS product_name, p.stock_qty
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = :oid
            FOR UPDATE
        ');
        $itemStmt->execute([':oid' => $orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $restoredCount = 0;
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $qty = (int) $item['quantity'];
            $newBalance = (int) $item['stock_qty'] + $qty;

            $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid')
                ->execute([':qty' => $qty, ':pid' => $pid]);

            $pdo->prepare('
                INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by, created_at)
                VALUES (:pid, :oid, :qty, "release", :bal, :uid, NOW())
            ')->execute([
                ':pid' => $pid,
                ':oid' => $orderId,
                ':qty' => $qty,
                ':bal' => $newBalance,
                ':uid' => $actorUserId,
            ]);
            $restoredCount++;
        }

        // 2. Reverse/cancel pending payouts for this order
        $pdo->prepare("
            UPDATE payouts
            SET status = 'cancelled',
                error_message = :reason
            WHERE order_id = :oid AND status IN ('pending', 'processing')
        ")->execute([
            ':reason' => 'Order cancelled: ' . $reason,
            ':oid'    => $orderId,
        ]);

        // 3. Update order status
        $isPaid = in_array($order['payment_status'], ['paid', 'succeeded'], true);
        $newPaymentStatus = $isPaid ? 'paid' : 'failed';

        $pdo->prepare("
            UPDATE orders
            SET order_status = 'cancelled',
                payment_status = :pstatus,
                cancel_reason = :reason,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ':pstatus' => $newPaymentStatus,
            ':reason'  => $reason,
            ':id'      => $orderId,
        ]);

        // Record status history
        record_order_status_history($pdo, $orderId, 'cancelled', $prevStatus, $actorUserId, null, $reason);

        // 4. Create refund record if customer had paid
        $refundRequested = false;
        if ($isPaid) {
            $payStmt = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND status = 'succeeded' ORDER BY id DESC LIMIT 1");
            $payStmt->execute([':oid' => $orderId]);
            $paymentId = $payStmt->fetchColumn() ?: null;

            $pdo->prepare('
                INSERT INTO refunds (order_id, payment_id, amount, reason, status, requested_by, created_at)
                VALUES (:oid, :pid, :amt, :reason, "requested", :uid, NOW())
            ')->execute([
                ':oid'    => $orderId,
                ':pid'    => $paymentId,
                ':amt'    => $order['total_amount'],
                ':reason' => $reason,
                ':uid'    => $actorUserId,
            ]);
            $refundRequested = true;

            Notifications::notifyUser(
                (int) $order['buyer_id'],
                'order',
                'Refund Requested: Order #' . $order['order_code'],
                'Your cancellation for order #' . $order['order_code'] . ' has been received. A refund of KES ' . number_format($order['total_amount'], 2) . ' is being processed.',
                'pages/buyer/orders.php?order_code=' . urlencode($order['order_code'])
            );
        }

        Audit::record('order.cancelled', 'order', $orderId, [
            'order_code'       => $order['order_code'],
            'previous_status'  => $prevStatus,
            'reason'           => $reason,
            'restored_items'   => $restoredCount,
            'refund_requested' => $refundRequested,
        ], $actorUserId, $actorRole);

        if ($ownsTx) {
            $pdo->commit();
        }

        return [
            'ok'               => true,
            'message'          => 'Order ' . $order['order_code'] . ' cancelled successfully. ' . $restoredCount . ' product(s) restored.',
            'order_code'       => $order['order_code'],
            'restored_items'   => $restoredCount,
            'refund_requested' => $refundRequested,
        ];
    } catch (Throwable $e) {
        if ($ownsTx) {
            $pdo->rollBack();
        }
        error_log('cancel_order error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to cancel order: ' . $e->getMessage()];
    }
}

/**
 * Buyer requests a refund for a delivered or paid order.
 */
function request_order_refund(PDO $pdo, int $orderId, float $amount, string $reason, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        if ((int) $order['buyer_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Unauthorized order access.'];
        }

        // Check if an existing open refund request already exists
        $chk = $pdo->prepare("SELECT id FROM refunds WHERE order_id = :oid AND status IN ('requested', 'approved', 'processing') LIMIT 1");
        $chk->execute([':oid' => $orderId]);
        if ($chk->fetch()) {
            return ['ok' => false, 'message' => 'A refund request is already pending for this order.'];
        }

        $payStmt = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND status = 'succeeded' ORDER BY id DESC LIMIT 1");
        $payStmt->execute([':oid' => $orderId]);
        $paymentId = $payStmt->fetchColumn() ?: null;

        $ins = $pdo->prepare('
            INSERT INTO refunds (order_id, payment_id, amount, reason, status, requested_by, created_at)
            VALUES (:oid, :pid, :amt, :reason, "requested", :uid, NOW())
        ');
        $ins->execute([
            ':oid'    => $orderId,
            ':pid'    => $paymentId,
            ':amt'    => $amount > 0 ? $amount : $order['total_amount'],
            ':reason' => trim($reason),
            ':uid'    => $userId,
        ]);
        $refundId = (int) $pdo->lastInsertId();

        Audit::record('refund.requested', 'refund', $refundId, [
            'order_id'   => $orderId,
            'order_code' => $order['order_code'],
            'amount'     => $amount,
            'reason'     => $reason,
        ], $userId, 'buyer');

        return ['ok' => true, 'refund_id' => $refundId, 'message' => 'Refund request submitted successfully.'];
    } catch (Throwable $e) {
        error_log('request_order_refund error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to request refund: ' . $e->getMessage()];
    }
}

/**
 * Admin processes an existing refund request (approves, completes with reversal, or rejects).
 * Reverses payouts and restores inventory if specified.
 */
function process_order_refund(
    PDO $pdo,
    int $refundId,
    int $actorUserId,
    string $actorRole,
    string $action = 'complete',
    ?string $reference = null,
    ?string $notes = null,
    bool $restoreInventory = false
): array {
    if (!in_array($actorRole, ['admin', 'seller'], true)) {
        return ['ok' => false, 'message' => 'Access denied: only administrators or sellers can process refunds.'];
    }

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('
            SELECT r.*, o.order_code, o.payment_status, o.order_status, o.buyer_id
            FROM refunds r
            INNER JOIN orders o ON o.id = r.order_id
            WHERE r.id = :id
            FOR UPDATE
        ');
        $stmt->execute([':id' => $refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$refund) {
            if ($ownsTx) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Refund record not found.'];
        }

        if ($refund['status'] === 'completed') {
            if ($ownsTx) $pdo->rollBack();
            return ['ok' => false, 'message' => 'This refund has already been completed.'];
        }

        $orderId = (int) $refund['order_id'];

        if ($action === 'complete' || $action === 'approve') {
            $newStatus = ($action === 'complete') ? 'completed' : 'approved';

            $upd = $pdo->prepare('
                UPDATE refunds
                SET status = :st,
                    decided_by = :uid,
                    decided_at = NOW(),
                    provider_reference = :ref,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $upd->execute([
                ':st'    => $newStatus,
                ':uid'   => $actorUserId,
                ':ref'   => $reference ? trim($reference) : null,
                ':notes' => $notes ? trim($notes) : null,
                ':id'    => $refundId,
            ]);

            if ($newStatus === 'completed') {
                // Update order payment status
                $pdo->prepare("UPDATE orders SET payment_status = 'refunded', updated_at = NOW() WHERE id = :oid")
                    ->execute([':oid' => $orderId]);

                // Cancel payments
                $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :oid AND status = 'succeeded'")
                    ->execute([':oid' => $orderId]);

                // Cancel/reverse any seller payouts that have not yet been paid out
                $pdo->prepare("
                    UPDATE payouts
                    SET status = 'cancelled',
                        error_message = 'Order was refunded'
                    WHERE order_id = :oid AND status IN ('pending', 'processing')
                ")->execute([':oid' => $orderId]);

                // Record status history
                record_order_status_history($pdo, $orderId, 'refunded', $refund['order_status'], $actorUserId, null, 'Refund completed: ' . ($reference ?? ''));

                // Optional inventory restoration
                if ($restoreInventory) {
                    $itemStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :oid');
                    $itemStmt->execute([':oid' => $orderId]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($items as $item) {
                        $pid = (int) $item['product_id'];
                        $qty = (int) $item['quantity'];
                        $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid')
                            ->execute([':qty' => $qty, ':pid' => $pid]);
                        $bal = (int) $pdo->query("SELECT stock_qty FROM products WHERE id = {$pid}")->fetchColumn();
                        $pdo->prepare('
                            INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by, created_at)
                            VALUES (:pid, :oid, :qty, "release", :bal, :uid, NOW())
                        ')->execute([
                            ':pid' => $pid,
                            ':oid' => $orderId,
                            ':qty' => $qty,
                            ':bal' => $bal,
                            ':uid' => $actorUserId,
                        ]);
                    }
                }

                // Notify buyer
                Notifications::notifyUser(
                    (int) $refund['buyer_id'],
                    'order',
                    'Refund Completed: Order #' . $refund['order_code'],
                    'Your refund of KES ' . number_format($refund['amount'], 2) . ' for order #' . $refund['order_code'] . ' has been completed.' . ($reference ? ' Ref: ' . $reference : ''),
                    'pages/buyer/orders.php?order_code=' . urlencode($refund['order_code'])
                );
            }

            Audit::record('refund.' . $newStatus, 'refund', $refundId, [
                'order_id'   => $orderId,
                'order_code' => $refund['order_code'],
                'amount'     => $refund['amount'],
                'reference'  => $reference,
            ], $actorUserId, $actorRole);

            if ($ownsTx) {
                $pdo->commit();
            }

            return ['ok' => true, 'message' => 'Refund for order ' . $refund['order_code'] . ' marked as ' . ucfirst($newStatus) . '.'];
        }

        if ($action === 'reject') {
            $pdo->prepare('
                UPDATE refunds
                SET status = "rejected",
                    decided_by = :uid,
                    decided_at = NOW(),
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ')->execute([
                ':uid'   => $actorUserId,
                ':notes' => $notes ? trim($notes) : 'Rejected by ' . ucfirst($actorRole),
                ':id'    => $refundId,
            ]);

            Notifications::notifyUser(
                (int) $refund['buyer_id'],
                'order',
                'Refund Rejected: Order #' . $refund['order_code'],
                'Your refund request for order #' . $refund['order_code'] . ' was rejected. Notes: ' . ($notes ?: 'No details provided.'),
                'pages/buyer/orders.php?order_code=' . urlencode($refund['order_code'])
            );

            Audit::record('refund.rejected', 'refund', $refundId, [
                'order_id'   => $orderId,
                'order_code' => $refund['order_code'],
                'notes'      => $notes,
            ], $actorUserId, $actorRole);

            if ($ownsTx) {
                $pdo->commit();
            }

            return ['ok' => true, 'message' => 'Refund request for order ' . $refund['order_code'] . ' was rejected.'];
        }

        if ($ownsTx) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Invalid refund action specified.'];
    } catch (Throwable $e) {
        if ($ownsTx) {
            $pdo->rollBack();
        }
        error_log('process_order_refund error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to process refund: ' . $e->getMessage()];
    }
}
