<?php
/**
 * SokoHub Database-Backed Wishlist / Save-for-Later Manager
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

class Wishlist
{
    /**
     * Check if a product is in the user's wishlist.
     */
    public static function isInWishlist(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT id FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1');
            $stmt->execute([':uid' => $userId, ':pid' => $productId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Wishlist::isInWishlist Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle wishlist item (add if absent, remove if present).
     * Returns ['ok' => bool, 'action' => 'added'|'removed', 'count' => int]
     */
    public static function toggle(int $userId, int $productId): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Please log in to save items to your wishlist.'];
        }

        try {
            $pdo = get_db_connection();
            $check = $pdo->prepare('SELECT id FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1');
            $check->execute([':uid' => $userId, ':pid' => $productId]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $del = $pdo->prepare('DELETE FROM wishlists WHERE id = :id');
                $del->execute([':id' => $existingId]);
                $action = 'removed';
            } else {
                $ins = $pdo->prepare('INSERT INTO wishlists (user_id, product_id, created_at) VALUES (:uid, :pid, NOW())');
                $ins->execute([':uid' => $userId, ':pid' => $productId]);
                $action = 'added';
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :uid');
            $countStmt->execute([':uid' => $userId]);
            $count = (int) $countStmt->fetchColumn();

            return ['ok' => true, 'action' => $action, 'count' => $count];
        } catch (Throwable $e) {
            error_log('Wishlist::toggle Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to update wishlist: ' . $e->getMessage()];
        }
    }

    /**
     * Get all wishlist items for a user.
     */
    public static function getUserWishlist(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT 
                    w.id AS wishlist_id,
                    w.created_at AS saved_at,
                    p.*,
                    c.name AS category_name,
                    COALESCE(sp.business_name, u.full_name) AS seller_name
                FROM wishlists w
                INNER JOIN products p ON p.id = w.product_id
                INNER JOIN categories c ON c.id = p.category_id
                INNER JOIN users u ON u.id = p.seller_id
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE w.user_id = :uid
                ORDER BY w.created_at DESC
            ');
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Wishlist::getUserWishlist Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count items in user's wishlist.
     */
    public static function count(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Wishlist::count Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Move an item from wishlist to active cart and remove from wishlist.
     */
    public static function moveToCart(int $userId, int $productId, int $quantity = 1): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Please log in to manage your cart.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id AND status = "active" LIMIT 1');
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return ['ok' => false, 'error' => 'Product is currently unavailable.'];
            }

            if ((int) $product['stock_qty'] <= 0) {
                return ['ok' => false, 'error' => 'This product is currently out of stock.'];
            }

            // Add to session cart
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $currentQty = $_SESSION['cart'][$productId] ?? 0;
            $newQty = min((int) $product['stock_qty'], $currentQty + max(1, $quantity));
            $_SESSION['cart'][$productId] = $newQty;

            // Remove from wishlist
            $del = $pdo->prepare('DELETE FROM wishlists WHERE user_id = :uid AND product_id = :pid');
            $del->execute([':uid' => $userId, ':pid' => $productId]);

            return [
                'ok'         => true,
                'message'    => "Moved {$product['name']} to your cart.",
                'product_id' => $productId,
                'cart_qty'   => $newQty,
            ];
        } catch (Throwable $e) {
            error_log('Wishlist::moveToCart Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to move product to cart: ' . $e->getMessage()];
        }
    }
}
