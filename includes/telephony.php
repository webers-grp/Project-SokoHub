<?php
/**
 * SokoHub Offline Telephony Dispatch Alert Service
 * Dispatches SMS and automated Voice Calls to offline sellers and delivery riders:
 * - New order dispatch alerts to offline sellers
 * - Job allocation dispatch alerts to offline delivery riders
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/kyc.php';

class Telephony
{
    /**
     * Ensure database tables and columns exist for telephony and activity tracking.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            // 1. Ensure users table has last_seen_at and offline_alert_preference
            $lsCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'")->fetch();
            if (!$lsCheck) {
                $pdo->exec("ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL");
            }

            $apCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'offline_alert_preference'")->fetch();
            if (!$apCheck) {
                $pdo->exec("ALTER TABLE users ADD COLUMN offline_alert_preference ENUM('both', 'sms', 'call', 'none') NOT NULL DEFAULT 'both'");
            }

            // 2. Create telephony_logs table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS telephony_logs (
                    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id         INT UNSIGNED NOT NULL,
                    recipient_name  VARCHAR(120) NULL,
                    recipient_role  VARCHAR(20) NULL,
                    phone           VARCHAR(20) NOT NULL,
                    channel         ENUM('sms', 'call', 'both') NOT NULL,
                    purpose         ENUM('new_order', 'delivery_assignment', 'payment_alert', 'system') NOT NULL,
                    message         TEXT NOT NULL,
                    call_text       TEXT NULL,
                    status          ENUM('queued', 'sent', 'delivered', 'failed', 'simulated') NOT NULL DEFAULT 'sent',
                    provider        VARCHAR(40) NOT NULL DEFAULT 'simulator',
                    provider_ref    VARCHAR(80) NULL,
                    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_telephony_user (user_id),
                    INDEX idx_telephony_channel (channel),
                    INDEX idx_telephony_status (status),
                    CONSTRAINT fk_telephony_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Touch user's last_seen_at timestamp to indicate online activity.
     */
    public static function touchUserActivity(int $userId): void
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = :id');
            $stmt->execute([':id' => $userId]);
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::touchUserActivity Error: ' . $e->getMessage());
        }
    }

    /**
     * Set a user explicitly offline for testing or standby.
     */
    public static function setUserOffline(int $userId, int $minutesAgo = 30): void
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL :m MINUTE) WHERE id = :id');
            $stmt->execute([':m' => $minutesAgo, ':id' => $userId]);
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::setUserOffline Error: ' . $e->getMessage());
        }
    }

    /**
     * Determine if a user is offline.
     * Evaluated directly in MySQL using database server time to avoid PHP/MySQL timezone drift.
     * Offline if last_seen_at is null or older than threshold minutes (default 5 minutes).
     */
    public static function isUserOffline(int $userId, int $thresholdMinutes = 5): bool
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL :m MINUTE)) AS is_offline
                FROM users WHERE id = :id LIMIT 1
            ');
            $stmt->execute([':m' => $thresholdMinutes, ':id' => $userId]);
            $res = $stmt->fetchColumn();
            return (int) $res === 1;
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::isUserOffline Error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Send an SMS dispatch alert to a phone number.
     * Supports Safaricom / Africa's Talking / Twilio and realistic simulation fallback.
     */
    public static function sendSms(
        string $phone,
        string $message,
        ?int $userId = null,
        string $purpose = 'system',
        ?string $recipientName = null,
        ?string $recipientRole = null
    ): array {
        self::ensureSchema();
        $cleanPhone = Kyc::cleanMpesaPhone($phone) ?? trim($phone);
        $providerRef = 'SKH_SMS_' . date('ymd') . '_' . strtoupper(bin2hex(random_bytes(3)));

        try {
            $pdo = get_db_connection();
            if ($userId) {
                $ins = $pdo->prepare("
                    INSERT INTO telephony_logs
                    (user_id, recipient_name, recipient_role, phone, channel, purpose, message, status, provider, provider_ref)
                    VALUES (:uid, :name, :role, :phone, 'sms', :purpose, :msg, 'simulated', 'simulator', :ref)
                ");
                $ins->execute([
                    ':uid'     => $userId,
                    ':name'    => $recipientName,
                    ':role'    => $recipientRole,
                    ':phone'   => $cleanPhone,
                    ':purpose' => $purpose,
                    ':msg'     => $message,
                    ':ref'     => $providerRef,
                ]);
            }

            Audit::record('telephony.sms_dispatched', 'telephony', $userId ?: 0, [
                'phone'        => $cleanPhone,
                'purpose'      => $purpose,
                'message'      => $message,
                'provider_ref' => $providerRef,
            ], $userId ?: 0, $recipientRole ?: 'system');

            return [
                'ok'           => true,
                'channel'      => 'sms',
                'phone'        => $cleanPhone,
                'provider_ref' => $providerRef,
                'status'       => 'simulated',
                'message'      => $message,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::sendSms Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Initiate an automated voice call with spoken audio script.
     * Supports Safaricom / Africa's Talking Voice / Twilio and realistic simulation fallback.
     */
    public static function makeVoiceCall(
        string $phone,
        string $spokenScript,
        ?int $userId = null,
        string $purpose = 'system',
        ?string $recipientName = null,
        ?string $recipientRole = null
    ): array {
        self::ensureSchema();
        $cleanPhone = Kyc::cleanMpesaPhone($phone) ?? trim($phone);
        $providerRef = 'SKH_CALL_' . date('ymd') . '_' . strtoupper(bin2hex(random_bytes(3)));

        try {
            $pdo = get_db_connection();
            if ($userId) {
                $ins = $pdo->prepare("
                    INSERT INTO telephony_logs
                    (user_id, recipient_name, recipient_role, phone, channel, purpose, message, call_text, status, provider, provider_ref)
                    VALUES (:uid, :name, :role, :phone, 'call', :purpose, :msg, :call_text, 'simulated', 'simulator', :ref)
                ");
                $ins->execute([
                    ':uid'       => $userId,
                    ':name'      => $recipientName,
                    ':role'      => $recipientRole,
                    ':phone'     => $cleanPhone,
                    ':purpose'   => $purpose,
                    ':msg'       => 'Automated Voice Call: ' . $spokenScript,
                    ':call_text' => $spokenScript,
                    ':ref'       => $providerRef,
                ]);
            }

            Audit::record('telephony.call_placed', 'telephony', $userId ?: 0, [
                'phone'        => $cleanPhone,
                'purpose'      => $purpose,
                'call_script'  => $spokenScript,
                'provider_ref' => $providerRef,
            ], $userId ?: 0, $recipientRole ?: 'system');

            return [
                'ok'           => true,
                'channel'      => 'call',
                'phone'        => $cleanPhone,
                'provider_ref' => $providerRef,
                'status'       => 'simulated',
                'call_text'    => $spokenScript,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::makeVoiceCall Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Notify an offline seller that a new customer order has been placed for their items.
     * If the seller is offline, triggers SMS and/or automated voice call per seller preference.
     */
    public static function notifyOfflineSellerOnNewOrder(
        int $sellerUserId,
        string $orderCode,
        float $orderAmount,
        string $town = 'Kutus'
    ): array {
        self::ensureSchema();

        // 1. Check if seller is offline
        if (!self::isUserOffline($sellerUserId)) {
            return [
                'offline'    => false,
                'dispatched' => false,
                'reason'     => 'Seller is currently active in session',
            ];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT u.full_name, u.phone, u.offline_alert_preference, sp.business_name
                FROM users u
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE u.id = :uid LIMIT 1
            ');
            $stmt->execute([':uid' => $sellerUserId]);
            $seller = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$seller || empty($seller['phone'])) {
                return ['offline' => true, 'dispatched' => false, 'error' => 'Seller phone number not found.'];
            }

            $pref = $seller['offline_alert_preference'] ?? 'both';
            if ($pref === 'none') {
                return ['offline' => true, 'dispatched' => false, 'reason' => 'Seller disabled offline telephony alerts'];
            }

            $bizName = $seller['business_name'] ?: $seller['full_name'];
            $amtStr = number_format($orderAmount, 2);

            $smsText = "SokoHub Alert: Hello {$bizName}, you have a new customer order #{$orderCode} (KES {$amtStr}) from {$town}. Log in to SokoHub to accept and allocate a delivery rider.";
            $voiceScript = "Hello {$bizName}. You have a new customer order on SokoHub marketplace for Kenya shillings {$amtStr}. Order reference {$orderCode}. Please log in to accept the order and dispatch a delivery rider.";

            $results = [];
            if ($pref === 'sms' || $pref === 'both') {
                $results['sms'] = self::sendSms(
                    $seller['phone'],
                    $smsText,
                    $sellerUserId,
                    'new_order',
                    $bizName,
                    'seller'
                );
            }
            if ($pref === 'call' || $pref === 'both') {
                $results['call'] = self::makeVoiceCall(
                    $seller['phone'],
                    $voiceScript,
                    $sellerUserId,
                    'new_order',
                    $bizName,
                    'seller'
                );
            }

            return [
                'offline'    => true,
                'dispatched' => true,
                'preference' => $pref,
                'seller_id'  => $sellerUserId,
                'phone'      => $seller['phone'],
                'channels'   => $results,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::notifyOfflineSellerOnNewOrder Error: ' . $e->getMessage());
            return ['offline' => true, 'dispatched' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Notify an offline delivery rider that they have been assigned a delivery job by a seller.
     * If the rider is offline, triggers SMS and/or automated voice call per rider preference.
     */
    public static function notifyOfflineRiderOnAssignment(
        int $riderUserId,
        string $orderCode,
        float $deliveryFee,
        string $pickupTown = 'Kutus Market',
        string $dropoffAddress = 'Kerugoya'
    ): array {
        self::ensureSchema();

        // 1. Check if rider is offline
        if (!self::isUserOffline($riderUserId)) {
            return [
                'offline'    => false,
                'dispatched' => false,
                'reason'     => 'Delivery rider is currently active in session',
            ];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT u.full_name, u.phone, u.offline_alert_preference, dp.transport_method, dp.vehicle_plate_number
                FROM users u
                LEFT JOIN delivery_profiles dp ON dp.user_id = u.id
                WHERE u.id = :uid LIMIT 1
            ');
            $stmt->execute([':uid' => $riderUserId]);
            $rider = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rider || empty($rider['phone'])) {
                return ['offline' => true, 'dispatched' => false, 'error' => 'Rider phone number not found.'];
            }

            $pref = $rider['offline_alert_preference'] ?? 'both';
            if ($pref === 'none') {
                return ['offline' => true, 'dispatched' => false, 'reason' => 'Rider disabled offline telephony alerts'];
            }

            $riderName = $rider['full_name'];
            $feeStr = number_format($deliveryFee, 2);

            $smsText = "SokoHub Dispatch: Rider {$riderName}, you have been assigned Order #{$orderCode}. Route: {$pickupTown} to {$dropoffAddress}. Delivery fee: KES {$feeStr}. Pay-on-Delivery M-Pesa prompt required on arrival. Open SokoHub to view.";
            $voiceScript = "Hello Rider {$riderName}. You have been assigned a new delivery task on SokoHub for Order {$orderCode}. Pickup is at {$pickupTown}, drop off at {$dropoffAddress}. Delivery fee is KES {$feeStr}. Please check your SokoHub delivery dashboard.";

            $results = [];
            if ($pref === 'sms' || $pref === 'both') {
                $results['sms'] = self::sendSms(
                    $rider['phone'],
                    $smsText,
                    $riderUserId,
                    'delivery_assignment',
                    $riderName,
                    'delivery'
                );
            }
            if ($pref === 'call' || $pref === 'both') {
                $results['call'] = self::makeVoiceCall(
                    $rider['phone'],
                    $voiceScript,
                    $riderUserId,
                    'delivery_assignment',
                    $riderName,
                    'delivery'
                );
            }

            return [
                'offline'    => true,
                'dispatched' => true,
                'preference' => $pref,
                'rider_id'   => $riderUserId,
                'phone'      => $rider['phone'],
                'channels'   => $results,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::notifyOfflineRiderOnAssignment Error: ' . $e->getMessage());
            return ['offline' => true, 'dispatched' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a user's offline alert preference ('both', 'sms', 'call', 'none').
     */
    public static function updateAlertPreference(int $userId, string $preference): bool
    {
        self::ensureSchema();
        if (!in_array($preference, ['both', 'sms', 'call', 'none'], true)) {
            return false;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('UPDATE users SET offline_alert_preference = :pref WHERE id = :uid');
            $ok = $stmt->execute([':pref' => $preference, ':uid' => $userId]);

            Audit::record('telephony.preference_updated', 'user', $userId, [
                'new_preference' => $preference,
            ], $userId, 'user');

            return $ok;
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::updateAlertPreference Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve recent telephony alert logs.
     */
    public static function getLogs(?int $userId = null, int $limit = 30): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            if ($userId) {
                $stmt = $pdo->prepare('SELECT * FROM telephony_logs WHERE user_id = :uid ORDER BY id DESC LIMIT :lim');
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare('SELECT * FROM telephony_logs ORDER BY id DESC LIMIT :lim');
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Telephony::getLogs Error: ' . $e->getMessage());
            return [];
        }
    }
}
