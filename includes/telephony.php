<?php
/**
 * SokoHub Offline Telephony Dispatch Alert Service
 * Dispatches SMS and simulated Voice Calls to offline sellers and delivery riders:
 * - New order dispatch alerts to offline sellers
 * - Job allocation dispatch alerts to offline delivery riders
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

class Telephony
{
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
            error_log('Telephony::touchUserActivity Error: ' . $e->getMessage());
        }
    }

    /**
     * Set a user explicitly offline for testing or standby.
     */
    public static function setUserOffline(int $userId, int $minutesAgo = 30): void
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL :m MINUTE) WHERE id = :id');
            $stmt->execute([':m' => $minutesAgo, ':id' => $userId]);
        } catch (Throwable $e) {
            error_log('Telephony::setUserOffline Error: ' . $e->getMessage());
        }
    }

    /**
     * Determine if a user is offline.
     * Offline if last_seen_at is null or older than threshold minutes (default 5 minutes).
     */
    public static function isUserOffline(int $userId, int $thresholdMinutes = 5): bool
    {
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
            error_log('Telephony::isUserOffline Error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Normalize a phone number to Kenyan international MSISDN format (2547XXXXXXXX or 2541XXXXXXXX).
     */
    public static function normalizePhone(string $phone): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '07') && strlen($clean) === 10) {
            return '254' . substr($clean, 1);
        }
        if (str_starts_with($clean, '01') && strlen($clean) === 10) {
            return '254' . substr($clean, 1);
        }
        if (str_starts_with($clean, '254') && (strlen($clean) === 12)) {
            return $clean;
        }
        if (str_starts_with($clean, '7') && strlen($clean) === 9) {
            return '254' . $clean;
        }
        if (str_starts_with($clean, '1') && strlen($clean) === 9) {
            return '254' . $clean;
        }
        return null;
    }

    /**
     * Log and dispatch an SMS alert.
     */
    public static function sendSms(
        string $rawPhone,
        string $message,
        int $userId,
        string $purpose = 'new_order',
        ?string $recipientName = null,
        ?string $recipientRole = null
    ): array {
        $normalizedPhone = self::normalizePhone($rawPhone);
        if (!$normalizedPhone) {
            return ['ok' => false, 'error' => 'Invalid Kenyan telephone number: ' . $rawPhone];
        }

        try {
            $pdo = get_db_connection();
            $providerRef = 'SMS-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

            $stmt = $pdo->prepare('
                INSERT INTO telephony_logs (
                    user_id, channel, phone, message, status, provider_response, created_at
                ) VALUES (
                    :uid, "sms", :phone, :msg, "sent", :resp, NOW()
                )
            ');
            $stmt->execute([
                ':uid'   => $userId,
                ':phone' => $normalizedPhone,
                ':msg'   => $message,
                ':resp'  => json_encode(['provider_ref' => $providerRef, 'purpose' => $purpose, 'recipient' => $recipientName]),
            ]);

            $logId = (int) $pdo->lastInsertId();

            Audit::record('telephony.sms_sent', 'user', $userId, [
                'log_id'   => $logId,
                'phone'    => $normalizedPhone,
                'purpose'  => $purpose,
            ], $userId, 'system');

            return [
                'ok'           => true,
                'channel'      => 'sms',
                'log_id'       => $logId,
                'phone'        => $normalizedPhone,
                'provider_ref' => $providerRef,
            ];
        } catch (Throwable $e) {
            error_log('Telephony::sendSms Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Log and simulate an automated voice call alert.
     */
    public static function makeVoiceCall(
        string $rawPhone,
        string $speechScript,
        int $userId,
        string $purpose = 'new_order',
        ?string $recipientName = null,
        ?string $recipientRole = null
    ): array {
        $normalizedPhone = self::normalizePhone($rawPhone);
        if (!$normalizedPhone) {
            return ['ok' => false, 'error' => 'Invalid Kenyan telephone number: ' . $rawPhone];
        }

        try {
            $pdo = get_db_connection();
            $providerRef = 'CALL-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

            $stmt = $pdo->prepare('
                INSERT INTO telephony_logs (
                    user_id, channel, phone, message, status, provider_response, created_at
                ) VALUES (
                    :uid, "call", :phone, :msg, "sent", :resp, NOW()
                )
            ');
            $stmt->execute([
                ':uid'   => $userId,
                ':phone' => $normalizedPhone,
                ':msg'   => 'Automated Voice Call: ' . mb_substr($speechScript, 0, 120),
                ':resp'  => json_encode(['provider_ref' => $providerRef, 'purpose' => $purpose, 'speech' => $speechScript]),
            ]);

            $logId = (int) $pdo->lastInsertId();

            Audit::record('telephony.call_initiated', 'user', $userId, [
                'log_id'   => $logId,
                'phone'    => $normalizedPhone,
                'purpose'  => $purpose,
            ], $userId, 'system');

            return [
                'ok'           => true,
                'channel'      => 'call',
                'log_id'       => $logId,
                'phone'        => $normalizedPhone,
                'provider_ref' => $providerRef,
            ];
        } catch (Throwable $e) {
            error_log('Telephony::makeVoiceCall Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if a seller is offline and trigger SMS/Voice calls if order items belong to them.
     */
    public static function notifyOfflineSellerOnOrder(
        int $sellerUserId,
        string $orderCode,
        string $itemsSummary,
        float $sellerTotal
    ): array {
        if (!self::isUserOffline($sellerUserId)) {
            return [
                'offline'    => false,
                'dispatched' => false,
                'reason'     => 'Seller is currently online in session',
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

            $sellerName = $seller['business_name'] ?: $seller['full_name'];
            $totalStr   = number_format($sellerTotal, 2);

            $smsText = "SokoHub Alert: Jambo {$sellerName}! You have a NEW Order #{$orderCode} worth KES {$totalStr}. Items: {$itemsSummary}. Please log in to your merchant dashboard to prepare for pickup.";
            $voiceScript = "Hello {$sellerName}. You have received a new purchase on SokoHub marketplace for Order {$orderCode}, total value KES {$totalStr}. Please prepare your products for dispatch.";

            $results = [];
            if ($pref === 'sms' || $pref === 'both') {
                $results['sms'] = self::sendSms(
                    $seller['phone'],
                    $smsText,
                    $sellerUserId,
                    'new_order',
                    $sellerName,
                    'seller'
                );
            }
            if ($pref === 'call' || $pref === 'both') {
                $results['call'] = self::makeVoiceCall(
                    $seller['phone'],
                    $voiceScript,
                    $sellerUserId,
                    'new_order',
                    $sellerName,
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
            error_log('Telephony::notifyOfflineSellerOnOrder Error: ' . $e->getMessage());
            return ['offline' => true, 'dispatched' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve recent telephony alert logs.
     */
    public static function getLogs(?int $userId = null, int $limit = 30): array
    {
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
            error_log('Telephony::getLogs Error: ' . $e->getMessage());
            return [];
        }
    }
}
