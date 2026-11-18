<?php
/**
 * SokoHub Settlement Ledger & Payouts Dashboard
 *
 * Implements:
 * - Multi-seller order split accounting
 * - 2% Platform commission breakdown
 * - Net vendor payouts tracking (Till, Paybill, Pochi/Phone)
 * - Rider delivery fee payouts tracking
 * - COD fee receivable balance display
 * - Admin "Retry Payout" button for failed or pending transactions
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_role(['seller', 'admin']);
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];
$isAdmin = ($role === 'admin');

// Admin Retry Payout Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['action'] ?? '') === 'retry_payout') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('pages/dashboard/payouts.php');
    }

    $payoutId = (int) ($_POST['payout_id'] ?? 0);
    if ($payoutId > 0) {
        $res = mpesa_execute_single_payout($pdo, $payoutId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect('pages/dashboard/payouts.php');
}

// Fetch financial metrics
if (!$isAdmin) {
    // Seller specific metrics
    $grossStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(gross_amount), 0) AS total_gross,
                COALESCE(SUM(platform_fee_amount), 0) AS total_fee,
                COALESCE(SUM(cod_fee_deducted), 0) AS total_cod_deducted,
                COALESCE(SUM(CASE WHEN status = 'succeeded' THEN net_amount ELSE 0 END), 0) AS total_paid,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) AS total_pending
         FROM payouts
         WHERE user_id = :uid AND recipient_type = 'seller'"
    );
    $grossStmt->execute([':uid' => $userId]);
    $metrics = $grossStmt->fetch();

    $codDue = (float) $pdo->query("SELECT cod_balance_due FROM seller_profiles WHERE user_id = {$userId}")->fetchColumn();

    // Fetch seller payout rows
    $pStmt = $pdo->prepare(
        'SELECT p.*, o.order_code, o.payment_method, o.order_status, u.full_name AS recipient_name
         FROM payouts p
         INNER JOIN orders o ON o.id = p.order_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE p.user_id = :uid
         ORDER BY p.id DESC'
    );
    $pStmt->execute([':uid' => $userId]);
    $payouts = $pStmt->fetchAll();
} else {
    // Admin platform-wide metrics
    $metrics = $pdo->query(
        "SELECT COALESCE(SUM(gross_amount), 0) AS total_gross,
                COALESCE(SUM(platform_fee_amount), 0) AS total_fee,
                COALESCE(SUM(CASE WHEN status = 'succeeded' THEN net_amount ELSE 0 END), 0) AS total_paid,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) AS total_pending,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count
         FROM payouts"
    )->fetch();

    $codDue = (float) $pdo->query('SELECT COALESCE(SUM(cod_balance_due), 0) FROM seller_profiles')->fetchColumn();

    // Fetch all payouts
    $payouts = $pdo->query(
        'SELECT p.*, o.order_code, o.payment_method, o.order_status, u.full_name AS recipient_name, u.role AS user_role
         FROM payouts p
         INNER JOIN orders o ON o.id = p.order_id
         INNER JOIN users u ON u.id = p.user_id
         ORDER BY p.id DESC'
    )->fetchAll();
}

$pageTitle = 'SokoHub | Settlement Ledger & Payouts';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Settlement Ledger &amp; M-Pesa Payouts</h1>
            <p class="muted">
                Platform Till 1648753 settlement flow: 2% platform fee retained, 98% disbursed to vendors and delivery riders.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <?php if (!$isAdmin): ?>
                <a href="<?= e(url('pages/dashboard/seller_payout_settings.php')) ?>" class="btn btn-primary">
                    <?= icon('settings', 'lucide-xs', 'white') ?>
                    <span>Receive Payments Settings</span>
                </a>
            <?php else: ?>
                <a href="<?= e(url('pages/dashboard/payments_admin.php')) ?>" class="btn btn-primary">
                    <?= icon('credit-card', 'lucide-xs', 'white') ?>
                    <span>Customer STK Transactions</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Financial KPI Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label"><?= $isAdmin ? 'Gross Platform Sales' : 'Gross Sales Volume' ?></span>
            <strong class="stat-value"><?= format_ksh($metrics['total_gross']) ?></strong>
            <span class="badge badge-primary">100% Gross</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Platform Service Fee (2%)</span>
            <strong class="stat-value" style="color: #047857;"><?= format_ksh($metrics['total_fee']) ?></strong>
            <span class="badge badge-info">2% Retained</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Net Disbursed Revenue</span>
            <strong class="stat-value" style="color: #059669;"><?= format_ksh($metrics['total_paid']) ?></strong>
            <span class="badge badge-success">Paid via M-Pesa</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Pending Escrow Payouts</span>
            <strong class="stat-value" style="color: #d97706;"><?= format_ksh($metrics['total_pending']) ?></strong>
            <span class="badge badge-warning">Awaiting Delivery</span>
        </div>

        <?php if ($codDue > 0): ?>
            <div class="stat-card" style="border-left: 4px solid #ef4444;">
                <span class="stat-label"><?= $isAdmin ? 'Total COD Fees Due' : 'COD Fee Balance Due' ?></span>
                <strong class="stat-value" style="color: #b91c1c;"><?= format_ksh($codDue) ?></strong>
                <span class="badge badge-danger">Receivable</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payout Ledger Records Table -->
    <div class="card" style="margin-top: 1.5rem;">
        <h2 style="margin-bottom: 1rem; color: var(--green-dark);">
            <?= icon('receipt', 'lucide-sm', 'emerald') ?>
            <span>Disbursement Ledger Records</span>
        </h2>

        <?php if (empty($payouts)): ?>
            <p class="muted">No payout records generated yet. Payouts are generated upon order placement and finalized on delivery.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Recipient</th>
                            <th>Destination</th>
                            <th>Gross (KSh)</th>
                            <th>Fee (2%)</th>
                            <th>COD Deducted</th>
                            <th>Net Paid (KSh)</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>M-Pesa Receipt</th>
                            <?php if ($isAdmin): ?>
                                <th>Admin Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payouts as $p): ?>
                        <?php
                        $pBadge = match ($p['status']) {
                            'succeeded' => ['label' => 'Succeeded', 'class' => 'badge-success'],
                            'processing' => ['label' => 'Processing', 'class' => 'badge-info'],
                            'pending' => ['label' => 'Pending Delivery', 'class' => 'badge-warning'],
                            'failed' => ['label' => 'Failed', 'class' => 'badge-danger'],
                            default => ['label' => ucfirst($p['status']), 'class' => 'badge-neutral'],
                        };
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($p['order_code']) ?></strong>
                                <div class="small muted"><?= e(date('d M Y, H:i', strtotime($p['created_at']))) ?></div>
                            </td>
                            <td>
                                <strong><?= e($p['recipient_name']) ?></strong>
                                <div class="small muted"><?= ucfirst($p['recipient_type']) ?></div>
                            </td>
                            <td>
                                <strong><?= ucfirst($p['payout_destination_type']) ?></strong>
                                <div class="small" style="font-family: monospace;"><?= e($p['payout_account']) ?></div>
                                <?php if (!empty($p['payout_account_reference'])): ?>
                                    <div class="small muted">Ref: <?= e($p['payout_account_reference']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float) $p['gross_amount'], 2) ?></td>
                            <td style="color: #047857;"><?= number_format((float) $p['platform_fee_amount'], 2) ?></td>
                            <td style="color: <?= (float) $p['cod_fee_deducted'] > 0 ? '#b91c1c' : 'inherit' ?>;">
                                <?= number_format((float) $p['cod_fee_deducted'], 2) ?>
                            </td>
                            <td>
                                <strong style="color: #059669;"><?= number_format((float) $p['net_amount'], 2) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-neutral"><?= strtoupper($p['payout_method']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= e($pBadge['class']) ?>"><?= e($pBadge['label']) ?></span>
                                <?php if (!empty($p['error_message'])): ?>
                                    <div class="small" style="color: #dc2626; max-width: 140px;"><?= e($p['error_message']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['transaction_id'])): ?>
                                    <strong style="color: #047857; font-family: monospace; font-size: 0.85rem;"><?= e($p['transaction_id']) ?></strong>
                                <?php else: ?>
                                    <span class="muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td>
                                    <?php if ($p['status'] === 'failed' || $p['status'] === 'pending'): ?>
                                        <form action="<?= e(url('pages/dashboard/payouts.php')) ?>" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="retry_payout">
                                            <input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-amber btn-sm">
                                                <?= icon('refresh-cw', 'lucide-xs', 'white') ?>
                                                <span>Retry</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
