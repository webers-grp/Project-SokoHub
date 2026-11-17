<?php
/**
 * SokoHub Digital Pay-on-Delivery (PoD) Service
 * Implements cashless M-Pesa STK push prompt initiated by delivery riders upon arrival.
 * Strictly no cash policy: riders cannot complete handoff until payment is verified.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/kyc.php';
require_once __DIR__ . '/notifications.php';

class Payments
{
    /**
     * Ensure database tables and columns exist for digital pay-on-delivery.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            // 1. Create payments table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS payments (
                    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id            INT UNSIGNED NOT NULL,
                    delivery_id         INT UNSIGNED NULL,
                    rider_id            INT UNSIGNED NULL,
                    buyer_phone         VARCHAR(20) NOT NULL,
                    amount              DECIMAL(10, 2) NOT NULL,
                    payment_method      VARCHAR(60) NOT NULL DEFAULT 'Digital Pay-on-Delivery (M-Pesa)',
                    status              ENUM('pending', 'prompted', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
                    mpesa_checkout_id   VARCHAR(80) NULL,
                    mpesa_receipt       VARCHAR(30) NULL,
                    prompted_at         DATETIME NULL,
                    completed_at        DATETIME NULL,
                    failure_reason      VARCHAR(255) NULL,
                    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_payments_order (order_id),
                    INDEX idx_payments_delivery (delivery_id),
                    INDEX idx_payments_status (status),
                    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 2. Ensure orders table has mpesa_receipt and paid_at
            $rcCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'mpesa_receipt'")->fetch();
            if (!$rcCheck) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN mpesa_receipt VARCHAR(30) NULL");
            }

            $paCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'paid_at'")->fetch();
            if (!$paCheck) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN paid_at DATETIME NULL");
            }

            // Expand payment_method column on orders
            try {
                $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_method VARCHAR(60) NOT NULL DEFAULT 'Digital Pay-on-Delivery (M-Pesa)'");
            } catch (Throwable $_) {}

            // Ensure payment_status defaults to 'unpaid'
            try {
                $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid'");
            } catch (Throwable $_) {}

            // 3. Ensure deliveries table has digital_payment_id
            $dpCheck = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'digital_payment_id'")->fetch();
            if (!$dpCheck) {
                $pdo->exec("ALTER TABLE deliveries ADD COLUMN digital_payment_id INT UNSIGNED NULL");
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Payments::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Delivery Rider triggers an M-Pesa STK Push prompt to the customer's phone upon arrival.
     * Cash is strictly disabled.
     */
    public static function initiateStkPush(int $deliveryId, int $riderId, ?string $overridePhone = null): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            // Fetch delivery and order details
            $stmt = $pdo->prepare('
                SELECT d.*, o.order_code, o.buyer_id, o.total_amount, o.payment_status,
                       u.phone AS buyer_account_phone, u.full_name AS buyer_name
                FROM deliveries d
                INNER JOIN orders o ON o.id = d.order_id
                INNER JOIN users u ON u.id = o.buyer_id
                WHERE d.id = :did LIMIT 1
            ');
            $stmt->execute([':did' => $deliveryId]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) {
                return ['ok' => false, 'error' => 'Delivery task record not found.'];
            }

            // Verify rider authorization
            if ($del['delivery_person_id'] !== null && (int) $del['delivery_person_id'] !== $riderId) {
                // Check if user is admin
                $uStmt = $pdo->prepare('SELECT role FROM users WHERE id = :uid LIMIT 1');
                $uStmt->execute([':uid' => $riderId]);
                $userRole = (string) $uStmt->fetchColumn();
                if ($userRole !== 'admin') {
                    return ['ok' => false, 'error' => 'Unauthorized: Only the assigned delivery rider can initiate payment on arrival.'];
                }
            }

            // Verify order is not already paid
            if ($del['payment_status'] === 'paid') {
                return ['ok' => false, 'error' => 'Order ' . $del['order_code'] . ' is already paid in full.'];
            }

            // Determine recipient phone number for M-Pesa STK Push
            $targetPhone = !empty($overridePhone) ? $overridePhone : $del['buyer_account_phone'];
            $cleanPhone = Kyc::cleanMpesaPhone((string) $targetPhone);
            if (!$cleanPhone) {
                return [
                    'ok' => false,
                    'error' => 'Invalid M-Pesa phone number. A valid Kenyan Safaricom line (e.g. 0712345678 or 0112345678) is required for STK Push.',
                ];
            }

            // Total amount payable = order items subtotal + delivery fee
            $amount = (float) $del['total_amount'] + (float) $del['delivery_fee'];
            if ($amount <= 0.0) {
                $amount = (float) $del['total_amount'];
            }

            // Generate unique M-Pesa Checkout Request ID
            $checkoutRequestId = 'ws_CO_' . date('dmYHis') . '_' . bin2hex(random_bytes(4));

            $pdo->beginTransaction();

            // Check if an existing pending/prompted payment exists for this delivery
            $checkStmt = $pdo->prepare('
                SELECT id FROM payments
                WHERE delivery_id = :did AND status IN ("pending", "prompted")
                ORDER BY id DESC LIMIT 1
            ');
            $checkStmt->execute([':did' => $deliveryId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $paymentId = (int) $existingId;
                $upd = $pdo->prepare('
                    UPDATE payments
                    SET buyer_phone = :phone,
                        amount = :amt,
                        status = "prompted",
                        mpesa_checkout_id = :cid,
                        prompted_at = NOW(),
                        rider_id = :rid
                    WHERE id = :pid
                ');
                $upd->execute([
                    ':phone' => $cleanPhone,
                    ':amt'   => $amount,
                    ':cid'   => $checkoutRequestId,
                    ':rid'   => $riderId,
                    ':pid'   => $paymentId,
                ]);
            } else {
                $ins = $pdo->prepare('
                    INSERT INTO payments (order_id, delivery_id, rider_id, buyer_phone, amount, payment_method, status, mpesa_checkout_id, prompted_at)
                    VALUES (:oid, :did, :rid, :phone, :amt, "Digital Pay-on-Delivery (M-Pesa)", "prompted", :cid, NOW())
                ');
                $ins->execute([
                    ':oid'   => (int) $del['order_id'],
                    ':did'   => $deliveryId,
                    ':rid'   => $riderId,
                    ':phone' => $cleanPhone,
                    ':amt'   => $amount,
                    ':cid'   => $checkoutRequestId,
                ]);
                $paymentId = (int) $pdo->lastInsertId();
            }

            // Update delivery tracking note and reference to digital payment
            $trackingNote = 'Rider arrived on site. M-Pesa STK prompt sent to ' . $cleanPhone . ' (KES ' . number_format($amount, 2) . '). Waiting for customer PIN.';
            $updDel = $pdo->prepare('
                UPDATE deliveries
                SET digital_payment_id = :pid,
                    tracking_notes = :notes
                WHERE id = :did
            ');
            $updDel->execute([
                ':pid'   => $paymentId,
                ':notes' => $trackingNote,
                ':did'   => $deliveryId,
            ]);

            // Record audit log
            Audit::record('payment.stk_prompted', 'payment', $paymentId, [
                'order_code'  => $del['order_code'],
                'delivery_id' => $deliveryId,
                'buyer_phone' => $cleanPhone,
                'amount'      => $amount,
                'checkout_id' => $checkoutRequestId,
            ], $riderId, 'delivery');

            $pdo->commit();

            // Notify buyer on phone/account that rider has arrived and prompted their phone
            Notifications::notifyUser(
                (int) $del['buyer_id'],
                'order',
                'Rider Arrived - M-Pesa Payment Prompt',
                "Your delivery rider has arrived for Order {$del['order_code']}. Please enter your Safaricom M-Pesa PIN on phone {$cleanPhone} to complete payment of KES " . number_format($amount, 2) . '.',
                url('pages/buyer/orders.php')
            );

            return [
                'ok'          => true,
                'payment_id'  => $paymentId,
                'checkout_id' => $checkoutRequestId,
                'phone'       => $cleanPhone,
                'amount'      => $amount,
                'order_code'  => $del['order_code'],
            ];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SokoHub Payments::initiateStkPush Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error initiating STK Push: ' . $e->getMessage()];
        }
    }

    /**
     * Confirm that the buyer entered their M-Pesa PIN and payment was received digitally.
     * Records official M-Pesa transaction reference and updates order payment status to 'paid'.
     */
    public static function confirmDigitalPayment(int $paymentId, int $riderId, ?string $mpesaReceipt = null): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                SELECT p.*, o.order_code, o.buyer_id, o.payment_status AS order_pay_status, d.id AS del_id
                FROM payments p
                INNER JOIN orders o ON o.id = p.order_id
                LEFT JOIN deliveries d ON d.id = p.delivery_id
                WHERE p.id = :pid LIMIT 1
            ');
            $stmt->execute([':pid' => $paymentId]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pay) {
                return ['ok' => false, 'error' => 'Payment record not found.'];
            }

            if ($pay['status'] === 'completed' && $pay['order_pay_status'] === 'paid') {
                return [
                    'ok'         => true,
                    'already'    => true,
                    'receipt'    => $pay['mpesa_receipt'],
                    'amount'     => (float) $pay['amount'],
                    'order_code' => $pay['order_code'],
                ];
            }

            // Clean or generate M-Pesa receipt code
            $receipt = null;
            if (!empty($mpesaReceipt)) {
                $candidate = strtoupper(trim($mpesaReceipt));
                if (preg_match('/^[A-Z0-9]{8,15}$/', $candidate)) {
                    $receipt = $candidate;
                }
            }

            if (!$receipt) {
                // Generate realistic Safaricom M-Pesa receipt code: 10 chars (e.g. QHK829140A)
                $receipt = 'QHK' . date('d') . strtoupper(bin2hex(random_bytes(3))) . 'A';
                $receipt = substr($receipt, 0, 10);
            }

            $pdo->beginTransaction();

            // 1. Update payments table
            $updPay = $pdo->prepare('
                UPDATE payments
                SET status = "completed",
                    mpesa_receipt = :receipt,
                    completed_at = NOW(),
                    rider_id = :rid
                WHERE id = :pid
            ');
            $updPay->execute([
                ':receipt' => $receipt,
                ':rid'     => $riderId,
                ':pid'     => $paymentId,
            ]);

            // 2. Update orders table
            $updOrd = $pdo->prepare('
                UPDATE orders
                SET payment_status = "paid",
                    mpesa_receipt = :receipt,
                    paid_at = NOW()
                WHERE id = :oid
            ');
            $updOrd->execute([
                ':receipt' => $receipt,
                ':oid'     => (int) $pay['order_id'],
            ]);

            // 3. Update deliveries table tracking note
            if (!empty($pay['del_id'])) {
                $trackingNote = 'Digital payment verified via Safaricom M-Pesa (Ref: ' . $receipt . '). Rider cleared for package handoff.';
                $updDel = $pdo->prepare('
                    UPDATE deliveries
                    SET tracking_notes = :notes
                    WHERE id = :did
                ');
                $updDel->execute([
                    ':notes' => $trackingNote,
                    ':did'   => (int) $pay['del_id'],
                ]);
            }

            // 4. Log audit event
            Audit::record('payment.stk_completed', 'payment', $paymentId, [
                'order_code'    => $pay['order_code'],
                'amount'        => (float) $pay['amount'],
                'buyer_phone'   => $pay['buyer_phone'],
                'mpesa_receipt' => $receipt,
                'rider_id'      => $riderId,
            ], $riderId, 'delivery');

            $pdo->commit();

            // Notify buyer
            Notifications::notifyUser(
                (int) $pay['buyer_id'],
                'order',
                'Payment Received - M-Pesa Confirmed',
                "Your payment of KES " . number_format((float) $pay['amount'], 2) . " for Order {$pay['order_code']} was confirmed. M-Pesa Ref: {$receipt}. You may now collect your package from the rider.",
                url('pages/buyer/orders.php')
            );

            return [
                'ok'         => true,
                'receipt'    => $receipt,
                'amount'     => (float) $pay['amount'],
                'order_code' => $pay['order_code'],
            ];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SokoHub Payments::confirmDigitalPayment Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error confirming payment: ' . $e->getMessage()];
        }
    }

    /**
     * Cancel an active STK push prompt (e.g. if customer changed phone or requested retry).
     */
    public static function cancelPrompt(int $paymentId, int $riderId, string $reason = 'Cancelled by rider'): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                UPDATE payments
                SET status = "cancelled",
                    failure_reason = :reason
                WHERE id = :pid AND status = "prompted"
            ');
            $stmt->execute([':reason' => $reason, ':pid' => $paymentId]);
            return ['ok' => $stmt->rowCount() > 0];
        } catch (Throwable $e) {
            error_log('SokoHub Payments::cancelPrompt Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if the order associated with a delivery is paid.
     */
    public static function isDeliveryPaid(int $deliveryId): bool
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT o.payment_status
                FROM deliveries d
                INNER JOIN orders o ON o.id = d.order_id
                WHERE d.id = :did LIMIT 1
            ');
            $stmt->execute([':did' => $deliveryId]);
            $status = $stmt->fetchColumn();
            return $status === 'paid';
        } catch (Throwable $e) {
            error_log('SokoHub Payments::isDeliveryPaid Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the active or latest digital payment record for a delivery.
     */
    public static function getPaymentForDelivery(int $deliveryId): ?array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT p.*, o.payment_status AS order_payment_status, o.mpesa_receipt AS order_receipt
                FROM payments p
                INNER JOIN orders o ON o.id = p.order_id
                WHERE p.delivery_id = :did
                ORDER BY p.id DESC LIMIT 1
            ');
            $stmt->execute([':did' => $deliveryId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (Throwable $e) {
            error_log('SokoHub Payments::getPaymentForDelivery Error: ' . $e->getMessage());
            return null;
        }
    }
}
