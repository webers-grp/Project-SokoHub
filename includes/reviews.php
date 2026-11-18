<?php
/**
 * SokoHub Verified Product Reviews & Ratings Engine
 *
 * Rules:
 * - 1 to 5 stars, written review, optional title.
 * - Verified purchase only: buyer must have a delivered order containing the product.
 * - One review per buyer per product (unique constraint).
 * - Sellers cannot review their own products.
 * - Status moderation (approved by default or pending).
 * - Automatically recalculates and updates `rating_avg` and `rating_count` on products.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class Reviews
{
    /**
     * Determine if a user can review a specific product.
     * Returns:
     * ['can_review' => bool, 'reason' => string, 'order_id' => ?int, 'existing_review' => ?array]
     */
    public static function canUserReview(int $productId, int $userId): array
    {
        try {
            $pdo = get_db_connection();

            // 1. Check if product exists and user is not the seller
            $pStmt = $pdo->prepare('SELECT id, seller_id, name FROM products WHERE id = :pid LIMIT 1');
            $pStmt->execute([':pid' => $productId]);
            $product = $pStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return ['can_review' => false, 'reason' => 'Product not found.', 'order_id' => null];
            }

            if ((int) $product['seller_id'] === $userId) {
                return ['can_review' => false, 'reason' => 'You cannot review your own product.', 'order_id' => null];
            }

            // 2. Check if user already submitted a review
            $rStmt = $pdo->prepare('SELECT * FROM reviews WHERE product_id = :pid AND buyer_id = :uid LIMIT 1');
            $rStmt->execute([':pid' => $productId, ':uid' => $userId]);
            $existing = $rStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                return [
                    'can_review'      => false,
                    'reason'          => 'You have already reviewed this product.',
                    'order_id'        => (int) $existing['order_id'],
                    'existing_review' => $existing,
                ];
            }

            // 3. Check for a delivered order containing this product
            $oStmt = $pdo->prepare('
                SELECT o.id 
                FROM orders o
                INNER JOIN order_items oi ON oi.order_id = o.id
                WHERE o.buyer_id = :uid
                  AND oi.product_id = :pid
                  AND o.order_status = "delivered"
                ORDER BY o.id DESC
                LIMIT 1
            ');
            $oStmt->execute([':uid' => $userId, ':pid' => $productId]);
            $orderId = $oStmt->fetchColumn();

            if (!$orderId) {
                return [
                    'can_review' => false,
                    'reason'     => 'Only buyers with a delivered order for this product can write a review.',
                    'order_id'   => null,
                ];
            }

            return [
                'can_review' => true,
                'reason'     => 'Verified purchase confirmed.',
                'order_id'   => (int) $orderId,
            ];
        } catch (Throwable $e) {
            error_log('Reviews::canUserReview Error: ' . $e->getMessage());
            return ['can_review' => false, 'reason' => 'Error checking review eligibility.', 'order_id' => null];
        }
    }

    /**
     * Submit a new verified review.
     */
    public static function submitReview(
        int $productId,
        int $buyerUserId,
        int $rating,
        string $reviewText,
        ?string $reviewTitle = null
    ): array {
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'error' => 'Rating must be between 1 and 5 stars.'];
        }

        $reviewText = trim($reviewText);
        if (mb_strlen($reviewText) < 5) {
            return ['ok' => false, 'error' => 'Please provide a detailed review (at least 5 characters).'];
        }

        $eligibility = self::canUserReview($productId, $buyerUserId);
        if (!$eligibility['can_review']) {
            return ['ok' => false, 'error' => $eligibility['reason']];
        }

        $orderId = $eligibility['order_id'];

        try {
            $pdo = get_db_connection();
            $ins = $pdo->prepare('
                INSERT INTO reviews (
                    product_id, buyer_id, order_id, rating, review_title, review_text, status, created_at
                ) VALUES (
                    :pid, :bid, :oid, :rating, :title, :text, "approved", NOW()
                )
            ');
            $ins->execute([
                ':pid'    => $productId,
                ':bid'    => $buyerUserId,
                ':oid'    => $orderId,
                ':rating' => $rating,
                ':title'  => $reviewTitle ? trim($reviewTitle) : null,
                ':text'   => $reviewText,
            ]);

            $reviewId = (int) $pdo->lastInsertId();

            // Refresh rating aggregates on products table
            self::refreshProductRating($productId);

            Audit::record('review.submitted', 'review', $reviewId, [
                'product_id' => $productId,
                'rating'     => $rating,
                'order_id'   => $orderId,
            ], $buyerUserId, 'buyer');

            return ['ok' => true, 'review_id' => $reviewId, 'message' => 'Thank you! Your verified review has been published.'];
        } catch (Throwable $e) {
            error_log('Reviews::submitReview Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to save review: ' . $e->getMessage()];
        }
    }

    /**
     * Recalculates and updates `rating_avg` and `rating_count` on the products table.
     */
    public static function refreshProductRating(int $productId): void
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS total_count, COALESCE(AVG(rating), 0) AS avg_rating
                FROM reviews
                WHERE product_id = :pid AND status = "approved"
            ');
            $stmt->execute([':pid' => $productId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $count = (int) ($stats['total_count'] ?? 0);
            $avg   = round((float) ($stats['avg_rating'] ?? 0.0), 2);

            $upd = $pdo->prepare('
                UPDATE products
                SET rating_avg = :avg, rating_count = :cnt
                WHERE id = :pid
            ');
            $upd->execute([
                ':avg' => $avg,
                ':cnt' => $count,
                ':pid' => $productId,
            ]);
        } catch (Throwable $e) {
            error_log('Reviews::refreshProductRating Error: ' . $e->getMessage());
        }
    }

    /**
     * Get approved reviews for a product with buyer names.
     */
    public static function getProductReviews(int $productId, int $limit = 20): array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT r.*, u.full_name AS buyer_name
                FROM reviews r
                INNER JOIN users u ON u.id = r.buyer_id
                WHERE r.product_id = :pid AND r.status = "approved"
                ORDER BY r.created_at DESC
                LIMIT :lim
            ');
            $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Reviews::getProductReviews Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Admin moderation: approve or reject a review.
     */
    public static function moderateReview(int $reviewId, string $status, int $adminUserId): bool
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return false;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT product_id FROM reviews WHERE id = :id');
            $stmt->execute([':id' => $reviewId]);
            $prodId = $stmt->fetchColumn();

            if (!$prodId) {
                return false;
            }

            $pdo->prepare('UPDATE reviews SET status = :st WHERE id = :id')
                ->execute([':st' => $status, ':id' => $reviewId]);

            self::refreshProductRating((int) $prodId);

            Audit::record('review.moderated', 'review', $reviewId, [
                'status'     => $status,
                'product_id' => $prodId,
            ], $adminUserId, 'admin');

            return true;
        } catch (Throwable $e) {
            error_log('Reviews::moderateReview Error: ' . $e->getMessage());
            return false;
        }
    }
}
