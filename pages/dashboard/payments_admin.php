<?php
/**
 * SokoHub Admin Payment Transactions & Gateway Monitoring
 *
 * Displays all customer M-Pesa STK push attempts, receipts, checkout request IDs,
 * callback audit trails, and reconciliation controls.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_role(['admin']);
$pdo = get_db_connection();

// Admin Manual STK Query Check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'query_status') {
    verify_csrf();

    $checkoutRequestId = trim((string) post('checkout_request_id'));
    if ($checkoutRequestId !== '') {
        $qRes = mpesa_query_stk_status($pdo, $checkoutRequestId);
        set_flash($qRes['ok'] ? 'success' : 'warning', 'STK Status Query: ' . ($qRes['message'] ?? 'Checked'));
    }
    redirect('pages/dashboard/payments_admin.php');
}

// Payment Statistics
$stats = $pdo->query(
    "SELECT COUNT(*) AS total_tx,
            COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) AS success_tx,
            COALESCE(SUM(CASE WHEN status = 'failed' OR status = 'cancelled' THEN 1 ELSE 0 END), 0) AS failed_tx,
            COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) AS total_collected
     FROM payments"
)->fetch();

// Fetch Payments with Order and Buyer details
$payments = $pdo->query(
    'SELECT p.*, o.order_code, o.payment_status AS order_pay_status, u.full_name AS buyer_name
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     INNER JOIN users u ON u.id = o.buyer_id
     ORDER BY p.id DESC
     LIMIT 100'
)->fetchAll();

$pageTitle = 'SokoHub | M-Pesa Gateway Transactions';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>M-Pesa Gateway Transactions</h1>
            <p class="muted">Live monitoring of customer STK Push transactions, Daraja receipts, and callback audit trails.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline">
                <?= icon('receipt', 'lucide-xs') ?>
                <span>Settlement Ledger</span>
            </a>
            <a href="<?= e(url('pages/dashboard/system_health.php')) ?>" class="btn btn-outline" style="border-color: #15803d; color: #15803d;">
                <?= icon('activity', 'lucide-xs') ?>
                <span>System Health</span>
            </a>
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">
                <?= icon('layout-dashboard', 'lucide-xs') ?>
                <span>Admin Dashboard</span>
            </a>
        </div>
    </div>

    <!-- Gateway Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">Total STK Transactions</span>
            <strong class="stat-value"><?= (int) $stats['total_tx'] ?></strong>
            <span class="badge badge-primary">Initiated</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Successful Payments</span>
            <strong class="stat-value" style="color: #059669;"><?= (int) $stats['success_tx'] ?></strong>
            <span class="badge badge-success">Verified</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Failed / Cancelled</span>
            <strong class="stat-value" style="color: #dc2626;"><?= (int) $stats['failed_tx'] ?></strong>
            <span class="badge badge-danger">Uncompleted</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Total M-Pesa Inflow</span>
            <strong class="stat-value" style="color: #047857;"><?= format_ksh($stats['total_collected']) ?></strong>
            <span class="badge badge-info">Till 1648753</span>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card" style="margin-top: 1.5rem;">
        <h2 style="margin-bottom: 1rem; color: #15803d; display: flex; align-items: center; gap: 0.5rem;">
            <?= icon('credit-card', 'lucide-sm') ?>
            <span>Customer STK Push Log</span>
        </h2>

        <?php if (empty($payments)): ?>
            <p class="muted">No M-Pesa payments recorded yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transaction Time</th>
                            <th>Order Code</th>
                            <th>Buyer</th>
                            <th>Phone</th>
                            <th>Amount (KSh)</th>
                            <th>Status</th>
                            <th>M-Pesa Receipt</th>
                            <th>CheckoutRequestID</th>
                            <th>Result Description</th>
                            <th>Reconcile</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <?php
                        $stBadge = match ($p['status']) {
                            'success'   => ['label' => 'Success',   'class' => 'badge-success'],
                            'pending'   => ['label' => 'Pending',   'class' => 'badge-warning'],
                            'cancelled' => ['label' => 'Cancelled', 'class' => 'badge-danger'],
                            'timeout'   => ['label' => 'Timeout',   'class' => 'badge-danger'],
                            default     => ['label' => ucfirst($p['status']), 'class' => 'badge-danger'],
                        };
                        ?>
                        <tr>
                            <td class="small muted">
                                <?= e(date('d M Y, H:i:s', strtotime($p['created_at']))) ?>
                            </td>
                            <td>
                                <strong><?= e($p['order_code']) ?></strong>
                            </td>
                            <td>
                                <?= e($p['buyer_name']) ?>
                            </td>
                            <td style="font-family: monospace;">
                                <?= e($p['phone'] ?: 'N/A') ?>
                            </td>
                            <td>
                                <strong><?= number_format((float) $p['amount'], 2) ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= e($stBadge['class']) ?>"><?= e($stBadge['label']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($p['mpesa_receipt'])): ?>
                                    <strong style="color: #059669; font-family: monospace;"><?= e($p['mpesa_receipt']) ?></strong>
                                <?php else: ?>
                                    <span class="muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="small" style="font-family: monospace; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($p['checkout_request_id']) ?>">
                                <?= e($p['checkout_request_id']) ?>
                            </td>
                            <td class="small muted" style="max-width: 140px;">
                                <?= e(($p['result_desc'] ?? '') ?: '&mdash;') ?>
                            </td>
                            <td>
                                <?php if ($p['status'] === 'pending' && !empty($p['checkout_request_id'])): ?>
                                    <form action="<?= e(url('pages/dashboard/payments_admin.php')) ?>" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="query_status">
                                        <input type="hidden" name="checkout_request_id" value="<?= e($p['checkout_request_id']) ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="Query Daraja STK Push Status">
                                            <?= icon('search', 'lucide-xs') ?>
                                            <span>Query</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="small muted">&mdash;</span>
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
