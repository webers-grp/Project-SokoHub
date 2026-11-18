<?php
/**
 * SokoHub Buyer Notification Service
 * Manages automated alerts when new sellers onboard or when products are newly added/restocked.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class Notifications
{
    /**
     * Notify all buyers when a new seller is onboarded and approved.
     */
    public static function notifyNewSeller(int $sellerId, string $businessName, string $town): int
    {
        try {
            $pdo = get_db_connection();

            $title   = "New Seller Alert: {$businessName}!";
            $message = "A new local vendor, {$businessName} from {$town}, has opened on SokoHub. Check out their catalog for fresh arrivals!";
            $link    = 'pages/public/products.php?seller=' . $sellerId;

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, data, is_read, created_at)
                VALUES (NULL, "new_seller", :title, :msg, :data, 0, NOW())
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':data'  => json_encode(['link_url' => $link, 'seller_id' => $sellerId]),
            ]);

            $notifId = (int) $pdo->lastInsertId();

            Audit::record('notification.broadcast_new_seller', 'seller', $sellerId, [
                'business_name'   => $businessName,
                'town'            => $town,
                'notification_id' => $notifId,
            ], null, 'system');

            return $notifId;
        } catch (Throwable $e) {
            error_log('Notifications::notifyNewSeller Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Broadcast notification when a product is newly published or created.
     */
    public static function notifyNewStock(int $productId, string $productName, float $price, string $sellerName, string $categoryName): int
    {
        try {
            $pdo = get_db_connection();

            $priceStr = number_format($price, 2);
            $title    = "New Arrival: {$productName}!";
            $message  = "{$productName} has just been added under {$categoryName} by {$sellerName} for only KSh {$priceStr}. Tap to view specifications and order!";
            $link     = 'pages/public/product_details.php?id=' . $productId;

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, data, is_read, created_at)
                VALUES (NULL, "new_stock", :title, :msg, :data, 0, NOW())
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':data'  => json_encode(['link_url' => $link, 'product_id' => $productId]),
            ]);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('Notifications::notifyNewStock Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Broadcast notification when a product is replenished after being low or out of stock.
     */
    public static function notifyRestock(int $productId, string $productName, int $newStock, string $sellerName): int
    {
        try {
            $pdo = get_db_connection();

            $title   = "Back in Stock: {$productName}!";
            $message = "Great news! {$productName} from {$sellerName} is restocked with {$newStock} unit(s) available. Order before stock runs out!";
            $link    = 'pages/public/product_details.php?id=' . $productId;

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, data, is_read, created_at)
                VALUES (NULL, "restock", :title, :msg, :data, 0, NOW())
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':data'  => json_encode(['link_url' => $link, 'product_id' => $productId]),
            ]);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('Notifications::notifyRestock Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve notifications for a specific user.
     */
    public static function getUserNotifications(int $userId, int $limit = 20): array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT n.*
                FROM notifications n
                WHERE (n.user_id = :uid OR n.user_id IS NULL)
                ORDER BY n.created_at DESC
                LIMIT :lim
            ');
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode link_url from data
            foreach ($rows as &$r) {
                $d = !empty($r['data']) ? json_decode($r['data'], true) : [];
                $r['link_url'] = $d['link_url'] ?? null;
            }

            return $rows;
        } catch (Throwable $e) {
            error_log('Notifications::getUserNotifications Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count unread notifications for a specific user.
     */
    public static function getUnreadCount(int $userId): int
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM notifications
                WHERE (user_id = :uid OR user_id IS NULL)
                  AND is_read = 0
            ');
            $stmt->execute([':uid' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Notifications::getUnreadCount Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark a specific notification as read by a user.
     */
    public static function markAsRead(int $notificationId, int $userId): bool
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                UPDATE notifications 
                SET is_read = 1 
                WHERE id = :nid AND (user_id = :uid OR user_id IS NULL)
            ');
            return $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);
        } catch (Throwable $e) {
            error_log('Notifications::markAsRead Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all active notifications as read for a user.
     */
    public static function markAllAsRead(int $userId): bool
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                UPDATE notifications 
                SET is_read = 1 
                WHERE (user_id = :uid OR user_id IS NULL)
            ');
            return $stmt->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            error_log('Notifications::markAllAsRead Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify a specific user directly (e.g. order update, refund, system alert).
     */
    public static function notifyUser(int $userId, string $type, string $title, string $message, ?string $linkUrl = null): int
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, data, is_read, created_at)
                VALUES (:uid, :type, :title, :msg, :data, 0, NOW())
            ');
            $stmt->execute([
                ':uid'   => $userId,
                ':type'  => $type,
                ':title' => $title,
                ':msg'   => $message,
                ':data'  => json_encode(['link_url' => $linkUrl]),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('Notifications::notifyUser Error: ' . $e->getMessage());
            return 0;
        }
    }
}
