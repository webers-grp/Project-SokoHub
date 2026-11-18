<?php
/**
 * SokoHub Merchant & Delivery Agent Payout Statements & Settlement Ledger
 *
 * Source of truth: The transactional `payouts` table from the payments settlement ledger
 * (98% seller net, 2% platform fee, triggered upon order delivery / confirmation).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_role(['admin', 'seller', 'delivery']);
$role = $user['role'];
$isAdmin = ($role === 'admin');
$userId = (int) $user['id'];
$pdo = get_db_connection();

// ---------------------------------------------------------
// 1. CSV EXPORT HANDLER
// ---------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $statusFilter = trim((string) ($_GET['status'] ?? ''));

    $sql = '
        SELECT 
            p.*,
            o.order_code,
            u.full_name AS recipient_name,
            u.email AS recipient_email,
            u.phone AS recipient_phone,
            sp.business_name
        FROM payouts p
        INNER JOIN orders o ON o.id = p.order_id
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN seller_profiles sp ON sp.user_id = u.id
    ';
    $params = [];

    if (!$isAdmin) {
        $sql .= ' WHERE p.user_id = :uid';
        $params[':uid'] = $userId;
        if ($statusFilter !== '') {
            $sql .= ' AND p.status = :st';
            $params[':st'] = $statusFilter;
        }
    } else {
        if ($statusFilter !== '') {
            $sql .= ' AND p.status = :st';
            $params[':st'] = $statusFilter;
        }
    }

    $sql .= ' ORDER BY p.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'sokohub_payout_ledger_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, [
        'Payout ID',
        'Order Code',
        'Recipient Type',
        'Recipient Name / Business',
        'Gross Amount (KES)',
        'Platform Fee (2%) (KES)',
        'Net Payout (KES)',
        'Destination Type',
        'Destination Account',
        'Account Reference',
        'Status',
        'M-Pesa Payout Receipt',
        'Created At',
        'Disbursed At',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            'SKH-PAY-' . str_pad($r['id'], 6, '0', STR_PAD_LEFT),
            $r['order_code'],
            strtoupper($r['recipient_type']),
            $r['business_name'] ?: $r['recipient_name'],
            number_format((float) $r['gross_amount'], 2, '.', ''),
            number_format((float) $r['platform_fee_amount'], 2, '.', ''),
            number_format((float) $r['net_amount'], 2, '.', ''),
            strtoupper($r['payout_destination_type']),
            $r['payout_account'],
            $r['payout_account_reference'] ?? '',
            strtoupper($r['status']),
            $r['transaction_id'] ?? 'PENDING',
            $r['created_at'],
            $r['paid_at'] ?? 'N/A',
        ]);
    }

    fclose($out);
    exit;
}

// ---------------------------------------------------------
// 2. POST ACTIONS (Admin Only)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        set_flash('error', 'Unauthorized action.');
        redirect('pages/dashboard/payouts.php');
    }

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/payouts.php');
    }

    $action   = (string) ($_POST['action'] ?? '');
    $payoutId = (int) ($_POST['payout_id'] ?? 0);

    if ($action === 'mark_paid') {
        $mpesaRef = strtoupper(trim((string) ($_POST['mpesa_reference'] ?? '')));
        if (empty($mpesaRef)) {
            set_flash('error', 'Please provide the Safaricom M-Pesa transaction reference.');
            redirect('pages/dashboard/payouts.php');
        }

        $stmt = $pdo->prepare('SELECT p.*, o.order_code FROM payouts p INNER JOIN orders o ON o.id = p.order_id WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $payoutId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            set_flash('error', 'Payout record not found.');
            redirect('pages/dashboard/payouts.php');
        }

        $upd = $pdo->prepare("
            UPDATE payouts
            SET status = 'succeeded',
                transaction_id = :ref,
                paid_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':ref' => $mpesaRef, ':id' => $payoutId]);

        Notifications::notifyUser(
            (int) $p['user_id'],
            'system',
            '💰 Payout Disbursed: KES ' . number_format((float) $p['net_amount'], 2),
            "Your payout for Order #{$p['order_code']} has been disbursed to {$p['payout_destination_type']} {$p['payout_account']}. Ref: {$mpesaRef}.",
            'pages/dashboard/payouts.php'
        );

        Audit::record('payout.manual_disbursed', 'payout', $payoutId, [
            'order_code' => $p['order_code'],
            'amount'     => $p['net_amount'],
            'receipt'    => $mpesaRef,
        ], $userId, 'admin');

        set_flash('success', "Payout #{$payoutId} marked as disbursed with receipt {$mpesaRef}.");
        redirect('pages/dashboard/payouts.php');
    }

    if ($action === 'execute_b2c') {
        $res = mpesa_execute_single_payout($pdo, $payoutId);
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', 'B2C Payout failed: ' . ($res['message'] ?? 'Unknown error'));
        }
        redirect('pages/dashboard/payouts.php');
    }
}

// ---------------------------------------------------------
// 3. FETCH PAYOUTS
// ---------------------------------------------------------
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$sql = '
    SELECT 
        p.*,
        o.order_code,
        o.payment_method,
        o.order_status,
        u.full_name AS recipient_name,
        u.phone AS recipient_phone,
        sp.business_name
    FROM payouts p
    INNER JOIN orders o ON o.id = p.order_id
    INNER JOIN users u ON u.id = p.user_id
    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
';
$params = [];

if (!$isAdmin) {
    $sql .= ' WHERE p.user_id = :uid';
    $params[':uid'] = $userId;
    if ($statusFilter !== '') {
        $sql .= ' AND p.status = :st';
        $params[':st'] = $statusFilter;
    }
} else {
    if ($statusFilter !== '') {
        $sql .= ' AND p.status = :st';
        $params[':st'] = $statusFilter;
    }
}

$sql .= ' ORDER BY p.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$metricSql = 'SELECT status, COUNT(*) AS cnt, SUM(gross_amount) AS total_gross, SUM(platform_fee_amount) AS total_fee, SUM(net_amount) AS total_net FROM payouts';
if (!$isAdmin) {
    $metricSql .= ' WHERE user_id = ' . $userId;
}
$metricSql .= ' GROUP BY status';
$mRows = $pdo->query($metricSql)->fetchAll(PDO::FETCH_ASSOC);

$totalPaidNet    = 0.0;
$totalPendingNet = 0.0;
$totalFeeEarned  = 0.0;

foreach ($mRows as $mr) {
    if ($mr['status'] === 'succeeded') {
        $totalPaidNet += (float) $mr['total_net'];
    } elseif ($mr['status'] === 'pending' || $mr['status'] === 'processing') {
        $totalPendingNet += (float) $mr['total_net'];
    }
    $totalFeeEarned += (float) $mr['total_fee'];
}

$page_title = 'Payout Settlement Ledger - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Head Bar -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <nav style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
                <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" style="color: #64748b; text-decoration: none;">Dashboard</a> &rsaquo;
                <span style="color: #0f172a; font-weight: 600;">Payouts &amp; Settlements</span>
            </nav>
            <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0;">
                💰 <?= $isAdmin ? 'Platform Settlement Ledger' : 'My Payout Statements' ?>
            </h1>
            <p style="color: #64748b; margin: 0.25rem 0 0; font-size: 0.9rem;">
                Platform model: 2% service fee retained by SokoHub Till 1648753; 98% net disbursed upon successful order delivery.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="?export=csv<?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>" class="btn btn-outline" style="font-size: 0.85rem;">
                📥 Export CSV
            </a>
            <?php if (!$isAdmin): ?>
                <a href="<?= e(url('pages/auth/register_seller.php?manage_payout=1')) ?>" class="btn btn-outline" style="font-size: 0.85rem;">
                    ⚙️ Payout Destination Settings
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem;">
            <span style="font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase;">
                <?= $isAdmin ? 'Disbursed To Date' : 'Total Earnings Paid' ?>
            </span>
            <div style="font-size: 1.75rem; font-weight: 800; color: #15803d; margin: 0.25rem 0;">
                KES <?= number_format($totalPaidNet, 2) ?>
            </div>
            <span style="font-size: 0.75rem; color: #16a34a; font-weight: 600;">Completed Disbursals</span>
        </div>

        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem;">
            <span style="font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase;">
                Pending Escrow Settlement
            </span>
            <div style="font-size: 1.75rem; font-weight: 800; color: #b45309; margin: 0.25rem 0;">
                KES <?= number_format($totalPendingNet, 2) ?>
            </div>
            <span style="font-size: 0.75rem; color: #d97706; font-weight: 600;">Awaiting delivery completion</span>
        </div>

        <?php if ($isAdmin): ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase;">
                    Total SokoHub 2% Fees
                </span>
                <div style="font-size: 1.75rem; font-weight: 800; color: #4338ca; margin: 0.25rem 0;">
                    KES <?= number_format($totalFeeEarned, 2) ?>
                </div>
                <span style="font-size: 0.75rem; color: #6366f1; font-weight: 600;">Platform revenue</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filters & Table -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="?status=" class="btn btn-sm <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 0.8rem;">All</a>
                <a href="?status=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 0.8rem;">Pending</a>
                <a href="?status=succeeded" class="btn btn-sm <?= $statusFilter === 'succeeded' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 0.8rem;">Disbursed / Paid</a>
                <a href="?status=cancelled" class="btn btn-sm <?= $statusFilter === 'cancelled' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 0.8rem;">Cancelled</a>
            </div>
            <div style="font-size: 0.85rem; color: #64748b;">
                Showing <?= count($payouts) ?> transaction ledger records
            </div>
        </div>

        <?php if (empty($payouts)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📄</div>
                <p style="font-weight: 600; margin: 0 0 0.25rem;">No payout records found matching your selection.</p>
                <p style="font-size: 0.85rem; margin: 0;">Payout records are generated automatically when customer orders are placed and confirmed.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.75rem;">ID / Order</th>
                            <?php if ($isAdmin): ?>
                                <th style="padding: 0.75rem;">Recipient</th>
                            <?php endif; ?>
                            <th style="padding: 0.75rem;">Gross (KES)</th>
                            <th style="padding: 0.75rem;">2% Fee</th>
                            <th style="padding: 0.75rem;">Net Payout (KES)</th>
                            <th style="padding: 0.75rem;">Payout Destination</th>
                            <th style="padding: 0.75rem;">Status</th>
                            <th style="padding: 0.75rem;">M-Pesa Receipt</th>
                            <th style="padding: 0.75rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $p): ?>
                            <?php
                            $st = $p['status'];
                            $badgeBg = '#f1f5f9';
                            $badgeFg = '#475569';
                            if ($st === 'succeeded') {
                                $badgeBg = '#dcfce7'; $badgeFg = '#15803d';
                            } elseif ($st === 'pending' || $st === 'processing') {
                                $badgeBg = '#fef3c7'; $badgeFg = '#b45309';
                            } elseif ($st === 'cancelled') {
                                $badgeBg = '#fee2e2'; $badgeFg = '#b91c1c';
                            }
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.75rem;">
                                    <div style="font-weight: 700; color: #0f172a;">SKH-PAY-<?= str_pad($p['id'], 6, '0', STR_PAD_LEFT) ?></div>
                                    <div style="font-size: 0.75rem; color: #64748b;">Order: #<?= e($p['order_code']) ?></div>
                                </td>
                                <?php if ($isAdmin): ?>
                                    <td style="padding: 0.75rem;">
                                        <div style="font-weight: 600; color: #0f172a;"><?= e($p['business_name'] ?: $p['recipient_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b;"><?= ucfirst($p['recipient_type']) ?> &bull; <?= e($p['recipient_phone']) ?></div>
                                    </td>
                                <?php endif; ?>
                                <td style="padding: 0.75rem; font-family: monospace;">
                                    <?= number_format((float) $p['gross_amount'], 2) ?>
                                </td>
                                <td style="padding: 0.75rem; font-family: monospace; color: #64748b;">
                                    -<?= number_format((float) $p['platform_fee_amount'], 2) ?>
                                </td>
                                <td style="padding: 0.75rem; font-family: monospace; font-weight: 700; color: #15803d;">
                                    KES <?= number_format((float) $p['net_amount'], 2) ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <div style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; color: #334155;">
                                        <?= e($p['payout_destination_type']) ?>
                                    </div>
                                    <div style="font-size: 0.8rem; font-family: monospace; color: #0f172a;">
                                        <?= e($p['payout_account']) ?>
                                    </div>
                                    <?php if (!empty($p['payout_account_reference'])): ?>
                                        <div style="font-size: 0.7rem; color: #64748b;">Ref: <?= e($p['payout_account_reference']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <span style="background: <?= $badgeBg ?>; color: <?= $badgeFg ?>; padding: 0.2rem 0.5rem; border-radius: 9999px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase;">
                                        <?= e($st === 'succeeded' ? 'paid' : $st) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; font-family: monospace; font-size: 0.8rem;">
                                    <?= e($p['transaction_id'] ?: '—') ?>
                                </td>
                                <td style="padding: 0.75rem; text-align: right;">
                                    <?php if ($isAdmin && $st !== 'succeeded' && $st !== 'cancelled'): ?>
                                        <details style="position: relative; display: inline-block;">
                                            <summary class="btn btn-primary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;">
                                                💸 Disburse
                                            </summary>
                                            <div style="position: absolute; right: 0; z-index: 100; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); width: 280px; text-align: left;">
                                                <div style="font-weight: 700; font-size: 0.85rem; margin-bottom: 0.5rem; color: #0f172a;">
                                                    Disburse KES <?= number_format((float) $p['net_amount'], 2) ?>
                                                </div>
                                                <form method="POST" action="">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="mark_paid">
                                                    <input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">

                                                    <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">
                                                        M-Pesa Transaction Receipt:
                                                    </label>
                                                    <input type="text" name="mpesa_reference" placeholder="e.g. QJD782394A" class="form-control" style="font-family: monospace; font-size: 0.8rem; text-transform: uppercase; width: 100%; margin-bottom: 0.75rem;" required>

                                                    <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; font-size: 0.8rem;">
                                                        Confirm Disbursal
                                                    </button>
                                                </form>

                                                <hr style="margin: 0.75rem 0; border: none; border-top: 1px solid #e2e8f0;">

                                                <form method="POST" action="">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="execute_b2c">
                                                    <input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">
                                                    <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; font-size: 0.75rem;" onclick="return confirm('Initiate automated Daraja B2C payout?');">
                                                        ⚡ Automated B2C Push
                                                    </button>
                                                </form>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: #94a3b8;">
                                            <?= $st === 'succeeded' ? 'Completed &check;' : 'N/A' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
