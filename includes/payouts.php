<?php
/**
 * SokoHub Seller Monthly Payout Statements & Calculator Engine
 * Calculates gross delivered sales, platform commissions (10%), refunds, and net payouts;
 * generates monthly statements, provides payroll exports, and tracks Safaricom M-Pesa disbursals.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/kyc.php';
require_once __DIR__ . '/notifications.php';

class Payouts
{
    public const DEFAULT_PLATFORM_FEE_RATE = 0.10; // 10% marketplace commission

    /**
     * Ensure seller_payouts table exists with all required columns and constraints.
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
                CREATE TABLE IF NOT EXISTS seller_payouts (
                    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    seller_user_id INT UNSIGNED NOT NULL,
                    period_start   DATE NOT NULL,
                    period_end     DATE NOT NULL,
                    gross_sales    DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                    platform_fee   DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                    refunds        DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                    net_amount     DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                    status         ENUM('draft', 'approved', 'paid') NOT NULL DEFAULT 'draft',
                    mpesa_reference VARCHAR(30) NULL,
                    paid_at        DATETIME NULL,
                    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_payout_period (seller_user_id, period_start, period_end),
                    INDEX idx_payout_status (status),
                    INDEX idx_payout_seller (seller_user_id),
                    CONSTRAINT fk_payout_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Calculate sales, 10% platform fee, refunds, and net payout for a seller over a date range.
     * Only orders with order_status = 'delivered' and payment_status <> 'unpaid' are included.
     */
    public static function calculatePeriodSales(int $sellerId, string $startDate, string $endDate, float $feeRate = self::DEFAULT_PLATFORM_FEE_RATE): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            // 1. Delivered Gross Sales
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(oi.quantity), 0) AS items_sold,
                    COALESCE(SUM(oi.line_total), 0) AS gross_sales
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE oi.seller_id = :seller_id
                  AND o.order_status = 'delivered'
                  AND o.payment_status <> 'refunded'
                  AND DATE(o.created_at) BETWEEN :p_start AND :p_end
            ");
            $stmt->execute([
                ':seller_id' => $sellerId,
                ':p_start'   => $startDate,
                ':p_end'     => $endDate,
            ]);
            $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $orderCount = (int) ($agg['order_count'] ?? 0);
            $itemsSold  = (int) ($agg['items_sold'] ?? 0);
            $grossSales = (float) ($agg['gross_sales'] ?? 0.0);

            // 2. Refunds (if any orders were marked refunded)
            $refStmt = $pdo->prepare("
                SELECT COALESCE(SUM(oi.line_total), 0) AS refund_total
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE oi.seller_id = :seller_id_ref
                  AND (o.payment_status = 'refunded' OR o.order_status = 'cancelled')
                  AND DATE(o.created_at) BETWEEN :p_start_ref AND :p_end_ref
            ");
            $refStmt->execute([
                ':seller_id_ref' => $sellerId,
                ':p_start_ref'   => $startDate,
                ':p_end_ref'     => $endDate,
            ]);
            $refunds = (float) ($refStmt->fetchColumn() ?: 0.0);

            $platformFee = round($grossSales * $feeRate, 2);
            $netAmount   = max(0.00, round($grossSales - $platformFee - $refunds, 2));

            // 3. Itemized list of delivered orders in period
            $itemStmt = $pdo->prepare("
                SELECT 
                    oi.id AS item_id,
                    o.id AS order_id,
                    o.order_code,
                    o.created_at AS order_date,
                    o.payment_method,
                    o.payment_status,
                    o.order_status,
                    p.name AS product_name,
                    oi.quantity,
                    oi.unit_price,
                    oi.line_total
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                INNER JOIN products p ON p.id = oi.product_id
                WHERE oi.seller_id = :seller_id_items
                  AND o.order_status = 'delivered'
                  AND DATE(o.created_at) BETWEEN :p_start_items AND :p_end_items
                ORDER BY o.created_at ASC, oi.id ASC
            ");
            $itemStmt->execute([
                ':seller_id_items' => $sellerId,
                ':p_start_items'   => $startDate,
                ':p_end_items'     => $endDate,
            ]);
            $lineItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'seller_user_id'    => $sellerId,
                'period_start'      => $startDate,
                'period_end'        => $endDate,
                'order_count'       => $orderCount,
                'items_sold'        => $itemsSold,
                'gross_sales'       => $grossSales,
                'platform_fee_rate' => $feeRate,
                'platform_fee'      => $platformFee,
                'refunds'           => $refunds,
                'net_amount'        => $netAmount,
                'line_items'        => $lineItems,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::calculatePeriodSales Error: ' . $e->getMessage());
            return [
                'seller_user_id'    => $sellerId,
                'period_start'      => $startDate,
                'period_end'        => $endDate,
                'order_count'       => 0,
                'items_sold'        => 0,
                'gross_sales'       => 0.0,
                'platform_fee_rate' => $feeRate,
                'platform_fee'      => 0.0,
                'refunds'           => 0.0,
                'net_amount'        => 0.0,
                'line_items'        => [],
            ];
        }
    }

    /**
     * Create or update a payout statement for a single seller.
     */
    public static function createOrUpdatePayout(
        int $sellerId,
        string $startDate,
        string $endDate,
        int $adminUserId,
        float $feeRate = self::DEFAULT_PLATFORM_FEE_RATE
    ): array {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $sales = self::calculatePeriodSales($sellerId, $startDate, $endDate, $feeRate);

            // Check existing
            $checkStmt = $pdo->prepare('
                SELECT id, status, mpesa_reference, paid_at
                FROM seller_payouts
                WHERE seller_user_id = :sid AND period_start = :p_start AND period_end = :p_end
            ');
            $checkStmt->execute([
                ':sid'     => $sellerId,
                ':p_start' => $startDate,
                ':p_end'   => $endDate,
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // If already paid, do not overwrite finalized financial records
                if ($existing['status'] === 'paid') {
                    return [
                        'ok'        => true,
                        'action'    => 'skipped',
                        'payout_id' => (int) $existing['id'],
                        'message'   => 'Statement already marked as paid. Skipped modification.',
                        'data'      => $sales,
                    ];
                }

                // Update amounts on draft or approved
                $upStmt = $pdo->prepare('
                    UPDATE seller_payouts
                    SET gross_sales  = :gross,
                        platform_fee = :fee,
                        refunds      = :ref,
                        net_amount   = :net
                    WHERE id = :id
                ');
                $upStmt->execute([
                    ':gross' => $sales['gross_sales'],
                    ':fee'   => $sales['platform_fee'],
                    ':ref'   => $sales['refunds'],
                    ':net'   => $sales['net_amount'],
                    ':id'    => $existing['id'],
                ]);

                Audit::record(
                    'payout.updated',
                    'seller_payouts',
                    (int) $existing['id'],
                    [
                        'seller_id'    => $sellerId,
                        'period_start' => $startDate,
                        'period_end'   => $endDate,
                        'gross_sales'  => $sales['gross_sales'],
                        'platform_fee' => $sales['platform_fee'],
                        'net_amount'   => $sales['net_amount'],
                    ],
                    $adminUserId,
                    'admin'
                );

                return [
                    'ok'        => true,
                    'action'    => 'updated',
                    'payout_id' => (int) $existing['id'],
                    'message'   => 'Payout statement recalculated and updated.',
                    'data'      => $sales,
                ];
            }

            // Insert new draft
            $inStmt = $pdo->prepare('
                INSERT INTO seller_payouts
                    (seller_user_id, period_start, period_end, gross_sales, platform_fee, refunds, net_amount, status)
                VALUES
                    (:sid, :p_start, :p_end, :gross, :fee, :ref, :net, "draft")
            ');
            $inStmt->execute([
                ':sid'     => $sellerId,
                ':p_start' => $startDate,
                ':p_end'   => $endDate,
                ':gross'   => $sales['gross_sales'],
                ':fee'     => $sales['platform_fee'],
                ':ref'     => $sales['refunds'],
                ':net'     => $sales['net_amount'],
            ]);
            $payoutId = (int) $pdo->lastInsertId();

            Audit::record(
                'payout.generated',
                'seller_payouts',
                $payoutId,
                [
                    'seller_id'    => $sellerId,
                    'period_start' => $startDate,
                    'period_end'   => $endDate,
                    'gross_sales'  => $sales['gross_sales'],
                    'platform_fee' => $sales['platform_fee'],
                    'net_amount'   => $sales['net_amount'],
                ],
                $adminUserId,
                'admin'
            );

            return [
                'ok'        => true,
                'action'    => 'created',
                'payout_id' => $payoutId,
                'message'   => 'New draft payout statement generated.',
                'data'      => $sales,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::createOrUpdatePayout Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate monthly statements for all active sellers for a given month (YYYY-MM).
     */
    public static function generateMonthlyPayouts(string $yearMonth, int $adminUserId, float $feeRate = self::DEFAULT_PLATFORM_FEE_RATE): array
    {
        self::ensureSchema();

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            $yearMonth = date('Y-m', strtotime('first day of last month'));
        }

        $startDate = $yearMonth . '-01';
        $endDate   = date('Y-m-t', strtotime($startDate));

        try {
            $pdo = get_db_connection();

            $sellerStmt = $pdo->query("
                SELECT u.id, u.full_name, u.email, sp.business_name
                FROM users u
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE u.role = 'seller'
                ORDER BY u.id ASC
            ");
            $sellers = $sellerStmt->fetchAll(PDO::FETCH_ASSOC);

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $totalGross   = 0.0;
            $totalNet     = 0.0;
            $payoutResults = [];

            foreach ($sellers as $seller) {
                $sellerId = (int) $seller['id'];
                $res = self::createOrUpdatePayout($sellerId, $startDate, $endDate, $adminUserId, $feeRate);
                if ($res['ok']) {
                    if ($res['action'] === 'created') {
                        $createdCount++;
                    } elseif ($res['action'] === 'updated') {
                        $updatedCount++;
                    } else {
                        $skippedCount++;
                    }
                    $salesData = $res['data'] ?? [];
                    $totalGross += (float) ($salesData['gross_sales'] ?? 0.0);
                    $totalNet   += (float) ($salesData['net_amount'] ?? 0.0);

                    $payoutResults[] = [
                        'seller_id'     => $sellerId,
                        'seller_name'   => $seller['full_name'],
                        'business_name' => $seller['business_name'] ?? $seller['full_name'],
                        'payout_id'     => $res['payout_id'],
                        'action'        => $res['action'],
                        'gross_sales'   => $salesData['gross_sales'] ?? 0.0,
                        'net_amount'    => $salesData['net_amount'] ?? 0.0,
                    ];
                }
            }

            return [
                'ok'            => true,
                'year_month'    => $yearMonth,
                'period_start'  => $startDate,
                'period_end'    => $endDate,
                'created'       => $createdCount,
                'updated'       => $updatedCount,
                'skipped'       => $skippedCount,
                'total_sellers' => count($sellers),
                'total_gross'   => round($totalGross, 2),
                'total_net'     => round($totalNet, 2),
                'payouts'       => $payoutResults,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::generateMonthlyPayouts Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approve a draft payout statement.
     */
    public static function approvePayout(int $payoutId, int $adminUserId): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('SELECT * FROM seller_payouts WHERE id = :id');
            $stmt->execute([':id' => $payoutId]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                return ['ok' => false, 'error' => 'Payout statement not found.'];
            }
            if ($payout['status'] === 'paid') {
                return ['ok' => false, 'error' => 'Payout is already marked as paid.'];
            }
            if ($payout['status'] === 'approved') {
                return ['ok' => true, 'message' => 'Payout is already approved.'];
            }

            $up = $pdo->prepare("UPDATE seller_payouts SET status = 'approved' WHERE id = :id");
            $up->execute([':id' => $payoutId]);

            Audit::record(
                'payout.approved',
                'seller_payouts',
                $payoutId,
                [
                    'seller_id'    => (int) $payout['seller_user_id'],
                    'period_start' => $payout['period_start'],
                    'period_end'   => $payout['period_end'],
                    'net_amount'   => (float) $payout['net_amount'],
                ],
                $adminUserId,
                'admin'
            );

            // Notify seller of approval
            Notifications::notifyUser(
                (int) $payout['seller_user_id'],
                'system',
                '✅ Payout Statement Approved',
                'Your payout statement of KES ' . number_format((float) $payout['net_amount'], 2) . ' for ' . $payout['period_start'] . ' to ' . $payout['period_end'] . ' has been approved by admin and queued for M-Pesa disbursement.',
                'pages/dashboard/payouts.php?action=statement&id=' . $payoutId
            );

            return ['ok' => true, 'message' => 'Payout statement approved successfully.'];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::approvePayout Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Mark a payout statement as paid with a valid Safaricom M-Pesa transaction reference.
     */
    public static function markPayoutPaid(int $payoutId, string $mpesaReference, int $adminUserId): array
    {
        self::ensureSchema();

        $cleanRef = strtoupper(trim($mpesaReference));
        if (!preg_match('/^[A-Z0-9]{6,25}$/', $cleanRef)) {
            return [
                'ok'    => false,
                'error' => 'Invalid M-Pesa transaction reference. Must be 6 to 25 uppercase alphanumeric characters (e.g. QJD782394A).',
            ];
        }

        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('SELECT * FROM seller_payouts WHERE id = :id');
            $stmt->execute([':id' => $payoutId]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                return ['ok' => false, 'error' => 'Payout statement not found.'];
            }
            if ($payout['status'] === 'paid') {
                return ['ok' => false, 'error' => 'Payout is already marked as paid.'];
            }

            $now = date('Y-m-d H:i:s');
            $up = $pdo->prepare("
                UPDATE seller_payouts
                SET status = 'paid',
                    mpesa_reference = :ref,
                    paid_at = :paid_dt
                WHERE id = :id
            ");
            $up->execute([
                ':ref'     => $cleanRef,
                ':paid_dt' => $now,
                ':id'      => $payoutId,
            ]);

            Audit::record(
                'payout.marked_paid',
                'seller_payouts',
                $payoutId,
                [
                    'seller_user_id'  => (int) $payout['seller_user_id'],
                    'mpesa_reference' => $cleanRef,
                    'gross_sales'     => (float) $payout['gross_sales'],
                    'platform_fee'    => (float) $payout['platform_fee'],
                    'net_amount'      => (float) $payout['net_amount'],
                    'period'          => $payout['period_start'] . ' to ' . $payout['period_end'],
                    'paid_at'         => $now,
                ],
                $adminUserId,
                'admin'
            );

            // Notify seller via Notifications
            Notifications::notifyPayoutDisbursed(
                (int) $payout['seller_user_id'],
                (float) $payout['net_amount'],
                $cleanRef,
                $payout['period_start'],
                $payout['period_end'],
                $payoutId
            );

            return [
                'ok'              => true,
                'payout_id'       => $payoutId,
                'mpesa_reference' => $cleanRef,
                'paid_at'         => $now,
                'message'         => "Payout marked as paid successfully. M-Pesa Ref: {$cleanRef}.",
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::markPayoutPaid Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve full payout details including merchant profile and itemized order lines.
     */
    public static function getPayoutDetails(int $payoutId): ?array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                SELECT 
                    sp.*,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    u.phone AS seller_phone,
                    sp_prof.business_name,
                    sp_prof.business_type,
                    sp_prof.town,
                    sp_prof.mpesa_phone,
                    sp_prof.national_id_no,
                    sp_prof.verification_status AS kyc_status
                FROM seller_payouts sp
                INNER JOIN users u ON u.id = sp.seller_user_id
                LEFT JOIN seller_profiles sp_prof ON sp_prof.user_id = u.id
                WHERE sp.id = :id
            ');
            $stmt->execute([':id' => $payoutId]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                return null;
            }

            // Fetch line items for this statement
            $itemStmt = $pdo->prepare("
                SELECT 
                    oi.id AS item_id,
                    o.id AS order_id,
                    o.order_code,
                    o.created_at AS order_date,
                    o.payment_method,
                    p.name AS product_name,
                    c.name AS category_name,
                    oi.quantity,
                    oi.unit_price,
                    oi.line_total
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                INNER JOIN products p ON p.id = oi.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE oi.seller_id = :seller_id
                  AND o.order_status = 'delivered'
                  AND DATE(o.created_at) BETWEEN :p_start AND :p_end
                ORDER BY o.created_at ASC, oi.id ASC
            ");
            $itemStmt->execute([
                ':seller_id' => $payout['seller_user_id'],
                ':p_start'   => $payout['period_start'],
                ':p_end'     => $payout['period_end'],
            ]);
            $payout['line_items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            return $payout;
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::getPayoutDetails Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve all payouts for a specific seller.
     */
    public static function getSellerPayouts(int $sellerId): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                SELECT 
                    sp.*,
                    sp_prof.business_name,
                    sp_prof.mpesa_phone,
                    (SELECT COUNT(DISTINCT oi.order_id)
                     FROM order_items oi
                     INNER JOIN orders o ON o.id = oi.order_id
                     WHERE oi.seller_id = sp.seller_user_id
                       AND o.order_status = "delivered"
                       AND DATE(o.created_at) BETWEEN sp.period_start AND sp.period_end
                    ) AS order_count
                FROM seller_payouts sp
                LEFT JOIN seller_profiles sp_prof ON sp_prof.user_id = sp.seller_user_id
                WHERE sp.seller_user_id = :sid
                ORDER BY sp.period_start DESC, sp.id DESC
            ');
            $stmt->execute([':sid' => $sellerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::getSellerPayouts Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Admin Payout Queue with filters (status, period YYYY-MM, search query).
     */
    public static function getAdminPayoutQueue(?string $statusFilter = null, ?string $periodFilter = null, ?string $search = null): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $where = [];
            $params = [];

            if ($statusFilter !== null && in_array($statusFilter, ['draft', 'approved', 'paid'], true)) {
                $where[] = 'sp.status = :status_f';
                $params[':status_f'] = $statusFilter;
            }

            if (!empty($periodFilter)) {
                $where[] = 'sp.period_start LIKE :period_f';
                $params[':period_f'] = $periodFilter . '%';
            }

            if (!empty($search)) {
                $where[] = '(u.full_name LIKE :q_name OR sp_prof.business_name LIKE :q_biz OR sp.mpesa_reference LIKE :q_ref)';
                $term = '%' . $search . '%';
                $params[':q_name'] = $term;
                $params[':q_biz']  = $term;
                $params[':q_ref']  = $term;
            }

            $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "
                SELECT 
                    sp.*,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    u.phone AS seller_phone,
                    sp_prof.business_name,
                    sp_prof.mpesa_phone,
                    sp_prof.verification_status AS kyc_status,
                    (SELECT COUNT(DISTINCT oi.order_id)
                     FROM order_items oi
                     INNER JOIN orders o ON o.id = oi.order_id
                     WHERE oi.seller_id = sp.seller_user_id
                       AND o.order_status = 'delivered'
                       AND DATE(o.created_at) BETWEEN sp.period_start AND sp.period_end
                    ) AS order_count
                FROM seller_payouts sp
                INNER JOIN users u ON u.id = sp.seller_user_id
                LEFT JOIN seller_profiles sp_prof ON sp_prof.user_id = u.id
                {$whereSql}
                ORDER BY sp.period_start DESC, sp.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::getAdminPayoutQueue Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Payout summary metrics (Total Gross, Fees, Disbursed, Pending).
     */
    public static function getPayoutSummary(?int $sellerId = null): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $whereSql = '';
            $params = [];
            if ($sellerId !== null && $sellerId > 0) {
                $whereSql = 'WHERE seller_user_id = :sid_sum';
                $params[':sid_sum'] = $sellerId;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) AS total_count,
                    COALESCE(SUM(gross_sales), 0) AS total_gross,
                    COALESCE(SUM(platform_fee), 0) AS total_fees,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) AS total_paid,
                    COALESCE(SUM(CASE WHEN status IN ('draft', 'approved') THEN net_amount ELSE 0 END), 0) AS total_pending,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS count_paid,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) AS count_approved,
                    COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS count_draft
                FROM seller_payouts
                {$whereSql}
            ");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'total_count'    => (int) ($row['total_count'] ?? 0),
                'total_gross'    => (float) ($row['total_gross'] ?? 0.0),
                'total_fees'     => (float) ($row['total_fees'] ?? 0.0),
                'total_paid'     => (float) ($row['total_paid'] ?? 0.0),
                'total_pending'  => (float) ($row['total_pending'] ?? 0.0),
                'count_paid'     => (int) ($row['count_paid'] ?? 0),
                'count_approved' => (int) ($row['count_approved'] ?? 0),
                'count_draft'    => (int) ($row['count_draft'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Payouts::getPayoutSummary Error: ' . $e->getMessage());
            return [
                'total_count'    => 0,
                'total_gross'    => 0.0,
                'total_fees'     => 0.0,
                'total_paid'     => 0.0,
                'total_pending'  => 0.0,
                'count_paid'     => 0,
                'count_approved' => 0,
                'count_draft'    => 0,
            ];
        }
    }
}
