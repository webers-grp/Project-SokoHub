<?php
/**
 * SokoHub Coupon & Promotional Discount Engine
 *
 * Supports percentage and fixed-amount discounts, minimum order requirements,
 * expiration dates, usage limits per coupon, and seller-scoped or sitewide coupons.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class Coupons
{
    /**
     * Validate a coupon code against an order subtotal and optional seller.
     */
    public static function validate(string $code, float $subtotal, ?int $userId = null, ?int $sellerId = null): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'error' => 'Please enter a coupon code.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT * FROM coupons
                WHERE UPPER(code) = :code
                LIMIT 1
            ');
            $stmt->execute([':code' => $code]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$coupon) {
                return ['ok' => false, 'error' => 'Invalid coupon code.'];
            }

            if (!(int) $coupon['is_active']) {
                return ['ok' => false, 'error' => 'This coupon has been disabled.'];
            }

            $now = date('Y-m-d H:i:s');
            if (!empty($coupon['starts_at']) && $now < $coupon['starts_at']) {
                return ['ok' => false, 'error' => 'This coupon is not active yet.'];
            }
            if (!empty($coupon['expires_at']) && $now > $coupon['expires_at']) {
                return ['ok' => false, 'error' => 'This coupon has expired.'];
            }

            if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
                return ['ok' => false, 'error' => 'This coupon has reached its maximum usage limit.'];
            }

            $minOrder = (float) $coupon['min_order_amount'];
            if ($subtotal < $minOrder) {
                return [
                    'ok'    => false,
                    'error' => 'Minimum order amount for this coupon is KES ' . number_format($minOrder, 2),
                ];
            }

            if ($coupon['seller_id'] !== null && $sellerId !== null && (int) $coupon['seller_id'] !== $sellerId) {
                return ['ok' => false, 'error' => 'This coupon is valid only for a specific vendor store.'];
            }

            // Calculate discount amount
            $discount = 0.0;
            if ($coupon['discount_type'] === 'percentage' || $coupon['discount_type'] === 'percent') {
                $discount = round(($subtotal * (float) $coupon['discount_value']) / 100, 2);
                if (!empty($coupon['max_discount_amount']) && (float) $coupon['max_discount_amount'] > 0) {
                    $discount = min($discount, (float) $coupon['max_discount_amount']);
                }
            } else {
                $discount = min($subtotal, (float) $coupon['discount_value']);
            }

            return [
                'ok'             => true,
                'coupon'         => $coupon,
                'code'           => $coupon['code'],
                'discount_type'  => $coupon['discount_type'],
                'discount_value' => (float) $coupon['discount_value'],
                'discount'       => $discount,
                'final_total'    => max(0.0, $subtotal - $discount),
            ];
        } catch (Throwable $e) {
            error_log('Coupons::validate Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error validating coupon: ' . $e->getMessage()];
        }
    }

    /**
     * Record usage of a coupon for a completed order.
     */
    public static function recordUsage(int $couponId, int $orderId, int $userId, float $discountAmount): bool
    {
        try {
            $pdo = get_db_connection();
            $pdo->prepare('
                INSERT INTO coupon_usages (coupon_id, order_id, user_id, discount_amount, used_at)
                VALUES (:cid, :oid, :uid, :amt, NOW())
            ')->execute([
                ':cid' => $couponId,
                ':oid' => $orderId,
                ':uid' => $userId,
                ':amt' => $discountAmount,
            ]);

            $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
                ->execute([':id' => $couponId]);

            return true;
        } catch (Throwable $e) {
            error_log('Coupons::recordUsage Error: ' . $e->getMessage());
            return false;
        }
    }
}
