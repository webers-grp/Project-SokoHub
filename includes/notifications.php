<?php
/**
 * SokoHub Buyer Notification Service
 * Manages automated alerts when new sellers onboard or when products are newly added/restocked.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class Notifications
{
    /**
     * Ensure notifications and notification_reads tables exist.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT UNSIGNED NULL,
                    type        ENUM('new_seller', 'new_stock', 'restock', 'order', 'system') NOT NULL DEFAULT 'system',
                    title       VARCHAR(150) NOT NULL,
                    message     TEXT NOT NULL,
                    link_url    VARCHAR(255) NULL,
                    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notif_user_created (user_id, created_at),
                    INDEX idx_notif_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notification_reads (
                    notification_id INT UNSIGNED NOT NULL,
                    user_id         INT UNSIGNED NOT NULL,
                    read_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (notification_id, user_id),
                    CONSTRAINT fk_notif_read_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
                    CONSTRAINT fk_notif_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Seed initial baseline notifications if table is completely empty
            $count = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
            if ($count === 0) {
                self::seedInitialNotifications($pdo);
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Seed baseline notifications for a lively marketplace experience.
     */
    private static function seedInitialNotifications(PDO $pdo): void
    {
        $seeds = [
            [
                'type'     => 'new_seller',
                'title'    => 'New Seller Alert: Kutus Fresh Farm Produce!',
                'message'  => 'Welcome Kutus Fresh Farm Produce from Kutus to SokoHub! Explore fresh organic greens, local honey, and produce directly from Kirinyaga farmers.',
                'link_url' => 'pages/public/products.php?search=Farm',
                'days_ago' => 3,
            ],
            [
                'type'     => 'restock',
                'title'    => 'Back in Stock: 6kg Cooking Gas Refill & Cylinder!',
                'message'  => 'Sealed 6kg local LPG gas cylinders have just been restocked with 24 fresh units. Order now for doorstep delivery in under 30 minutes!',
                'link_url' => 'pages/public/product_details.php?id=1',
                'days_ago' => 2,
            ],
            [
                'type'     => 'new_stock',
                'title'    => 'New Arrival: Smart LED Television 32-Inch!',
                'message'  => 'Crisp HD LED TV with HDMI/USB ports is now available from Mwangi Gas & Electronics for KSh 14,500. Includes warranty!',
                'link_url' => 'pages/public/product_details.php?id=7',
                'days_ago' => 1,
            ],
        ];

        $ins = $pdo->prepare('
            INSERT INTO notifications (user_id, type, title, message, link_url, created_at)
            VALUES (NULL, :type, :title, :message, :link, DATE_SUB(NOW(), INTERVAL :days DAY))
        ');

        foreach ($seeds as $s) {
            $ins->execute([
                ':type'    => $s['type'],
                ':title'   => $s['title'],
                ':message' => $s['message'],
                ':link'    => $s['link_url'],
                ':days'    => $s['days_ago'],
            ]);
        }
    }

    /**
     * Notify all buyers when a new seller is onboarded and approved.
     */
    public static function notifyNewSeller(int $sellerId, string $businessName, string $town): int
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $title   = "New Seller Alert: {$businessName}!";
            $message = "{$businessName} from {$town} has just opened their digital storefront on SokoHub. Check out their catalog and support local Kirinyaga enterprise!";
            $linkUrl = "pages/public/products.php?search=" . urlencode($businessName);

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, link_url)
                VALUES (NULL, "new_seller", :title, :msg, :link)
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':link'  => $linkUrl,
            ]);
            $notifId = (int) $pdo->lastInsertId();

            Audit::record('notification.new_seller_broadcast', 'seller_profile', $sellerId, [
                'business_name'   => $businessName,
                'town'            => $town,
                'notification_id' => $notifId,
            ], $sellerId, 'seller');

            return $notifId;
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::notifyNewSeller Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Notify all buyers when new product stock is added.
     */
    public static function notifyNewStock(int $productId, string $productName, float $price, string $sellerName): int
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $title   = "New Stock Arrival: {$productName}!";
            $priceFmt = number_format($price, 2);
            $message = "{$productName} has just been added to the marketplace by {$sellerName} for KSh {$priceFmt}. Order now for fast doorstep delivery!";
            $linkUrl = "pages/public/product_details.php?id={$productId}";

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, link_url)
                VALUES (NULL, "new_stock", :title, :msg, :link)
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':link'  => $linkUrl,
            ]);
            $notifId = (int) $pdo->lastInsertId();

            Audit::record('notification.new_stock_broadcast', 'product', $productId, [
                'product_name'    => $productName,
                'price'           => $price,
                'seller_name'     => $sellerName,
                'notification_id' => $notifId,
            ], null, 'system');

            return $notifId;
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::notifyNewStock Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Notify all buyers when an existing product has been restocked.
     */
    public static function notifyRestock(int $productId, string $productName, int $newUnits, string $sellerName): int
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $title   = "Back in Stock: {$productName}!";
            $message = "Good news! {$productName} from {$sellerName} has been replenished (+{$newUnits} fresh units in stock). Grab yours today before it sells out!";
            $linkUrl = "pages/public/product_details.php?id={$productId}";

            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, link_url)
                VALUES (NULL, "restock", :title, :msg, :link)
            ');
            $stmt->execute([
                ':title' => $title,
                ':msg'   => $message,
                ':link'  => $linkUrl,
            ]);
            $notifId = (int) $pdo->lastInsertId();

            Audit::record('notification.restock_broadcast', 'product', $productId, [
                'product_name'    => $productName,
                'stock_units'     => $newUnits,
                'seller_name'     => $sellerName,
                'notification_id' => $notifId,
            ], null, 'system');

            return $notifId;
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::notifyRestock Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve all notifications relevant to a buyer (broadcasts + direct messages).
     */
    public static function getBuyerNotifications(int $buyerUserId, ?string $typeFilter = null, int $limit = 30): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $sql = '
                SELECT 
                    n.*,
                    (nr.read_at IS NOT NULL) AS is_read,
                    nr.read_at
                FROM notifications n
                LEFT JOIN notification_reads nr 
                    ON n.id = nr.notification_id AND nr.user_id = :uid_read
                WHERE (n.user_id = :uid_owner OR n.user_id IS NULL)
            ';

            $params = [
                ':uid_read'  => $buyerUserId,
                ':uid_owner' => $buyerUserId,
            ];

            if ($typeFilter !== null && $typeFilter !== '') {
                $sql .= ' AND n.type = :type';
                $params[':type'] = $typeFilter;
            }

            $sql .= ' ORDER BY n.created_at DESC LIMIT ' . (int) $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::getBuyerNotifications Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count unread notifications for a specific buyer.
     */
    public static function getUnreadCount(int $buyerUserId): int
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM notifications n
                LEFT JOIN notification_reads nr 
                    ON n.id = nr.notification_id AND nr.user_id = :uid_read
                WHERE (n.user_id = :uid_owner OR n.user_id IS NULL)
                  AND nr.read_at IS NULL
            ');
            $stmt->execute([
                ':uid_read'  => $buyerUserId,
                ':uid_owner' => $buyerUserId,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::getUnreadCount Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark a specific notification as read by a buyer.
     */
    public static function markAsRead(int $notificationId, int $buyerUserId): bool
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
                VALUES (:nid, :uid, NOW())
            ');
            return $stmt->execute([':nid' => $notificationId, ':uid' => $buyerUserId]);
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::markAsRead Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all active notifications as read for a buyer.
     */
    public static function markAllAsRead(int $buyerUserId): bool
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
                SELECT n.id, :uid_insert, NOW()
                FROM notifications n
                WHERE (n.user_id = :uid_owner OR n.user_id IS NULL)
            ');
            return $stmt->execute([
                ':uid_insert' => $buyerUserId,
                ':uid_owner'  => $buyerUserId,
            ]);
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::markAllAsRead Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get recent public announcements for the storefront ticker on home page.
     */
    public static function getRecentAnnouncements(int $limit = 5): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT * FROM notifications
                WHERE user_id IS NULL
                ORDER BY created_at DESC
                LIMIT :lim
            ');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::getRecentAnnouncements Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Notify a specific user directly (e.g. seller on payout payment or system alert).
     */
    public static function notifyUser(int $userId, string $type, string $title, string $message, ?string $linkUrl = null): int
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, link_url, created_at)
                VALUES (:uid_target, :type_name, :title_text, :msg_body, :link_href, NOW())
            ');
            $stmt->execute([
                ':uid_target' => $userId,
                ':type_name'  => $type,
                ':title_text' => $title,
                ':msg_body'   => $message,
                ':link_href'  => $linkUrl,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('SokoHub Notifications::notifyUser Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Notify seller of disbursed payout via Safaricom M-Pesa.
     */
    public static function notifyPayoutDisbursed(int $sellerId, float $netAmount, string $mpesaRef, string $periodStart, string $periodEnd, int $payoutId): int
    {
        $title = '💰 M-Pesa Payout Disbursed';
        $formattedNet = number_format($netAmount, 2);
        $message = "Your monthly sales payout of KES {$formattedNet} for period {$periodStart} to {$periodEnd} has been disbursed to your Safaricom M-Pesa line. Transaction Ref: {$mpesaRef}.";
        $link = 'pages/dashboard/payouts.php?action=statement&id=' . $payoutId;

        return self::notifyUser($sellerId, 'system', $title, $message, $link);
    }
}
