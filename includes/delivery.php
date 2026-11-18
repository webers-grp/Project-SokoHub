<?php
/**
 * SokoHub Delivery Zones & Dynamic Pricing Engine
 *
 * Resolves delivery fees dynamically based on destination town/zone, base fee,
 * and free delivery thresholds.
 */

require_once __DIR__ . '/../config/db.php';

class Delivery
{
    /**
     * Get all active delivery zones.
     */
    public static function getActiveZones(): array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query('
                SELECT * FROM delivery_zones
                WHERE is_active = 1
                ORDER BY town_name ASC
            ');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Delivery::getActiveZones Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get delivery fee for a specific town and cart subtotal.
     * If cart subtotal exceeds the free delivery threshold (KES 3,000), fee is 0.00.
     */
    public static function calculateFee(string $town, float $subtotal = 0.0): array
    {
        $town = trim($town);
        $freeThreshold = 3000.00;

        if ($town === '') {
            $fee = ($subtotal >= $freeThreshold) ? 0.0 : 100.0;
            return [
                'ok'            => true,
                'zone_name'     => 'Kutus',
                'fee'           => $fee,
                'base_fee'      => 100.0,
                'is_free'       => ($fee === 0.0),
                'threshold'     => $freeThreshold,
            ];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT * FROM delivery_zones
                WHERE (LOWER(town_name) = LOWER(:town) OR LOWER(zone_name) LIKE LOWER(:term)) AND is_active = 1
                LIMIT 1
            ');
            $stmt->execute([
                ':town' => $town,
                ':term' => '%' . $town . '%',
            ]);
            $zone = $stmt->fetch(PDO::FETCH_ASSOC);

            $baseFee  = $zone ? (float) $zone['delivery_fee'] : 150.0;
            $zoneName = $zone ? $zone['town_name'] : $town;

            $isFree = ($subtotal >= $freeThreshold);
            $fee = $isFree ? 0.0 : $baseFee;

            return [
                'ok'        => true,
                'zone_name' => $zoneName,
                'fee'       => $fee,
                'base_fee'  => $baseFee,
                'is_free'   => $isFree,
                'threshold' => $freeThreshold,
            ];
        } catch (Throwable $e) {
            error_log('Delivery::calculateFee Error: ' . $e->getMessage());
            $fee = ($subtotal >= $freeThreshold) ? 0.0 : 100.0;
            return [
                'ok'        => true,
                'zone_name' => $town,
                'fee'       => $fee,
                'base_fee'  => 100.0,
                'is_free'   => ($fee === 0.0),
                'threshold' => $freeThreshold,
            ];
        }
    }
}
