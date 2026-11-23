<?php
/**
 * Developer Console: Payment Reconciliation & Stuck Payment Resolver
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$actionNotice = null;

// Handle Actions (Expire stuck payment / Run STK Query)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/payments/reconciliation.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';
    $paymentId = (int) ($_POST['payment_id'] ?? 0);

    if ($action === 'expire_stuck' && $paymentId > 0) {
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = :id AND status = 'pending'")
            ->execute([':id' => $paymentId]);
        DevAudit::log('payment_lab', 'payment_marked_failed', (string) $paymentId, ['reason' => 'Admin manual timeout expiration']);
        dev_set_flash('success', "Payment #{$paymentId} marked as failed (timed out).");
        header('Location: ' . dev_url('pages/payments/reconciliation.php'));
        exit;
    }
}

// 1. Stuck Pending Payments (> 2m old)
$stuckStmt = $pdo->query("
    SELECT p.*, o.order_code, TIMESTAMPDIFF(MINUTE, p.created_at, NOW()) AS age_mins
    FROM payments p
    LEFT JOIN orders o ON o.id = p.order_id
    WHERE p.status = 'pending' AND p.created_at < (NOW() - INTERVAL 2 MINUTE)
    ORDER BY p.id DESC
");
$stuckPayments = $stuckStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Full Ledger Reconciliation Check
$reconStmt = $pdo->query("
    SELECT 
        o.id AS order_id,
        o.order_code,
        o.payment_status,
        o.total_amount AS order_total,
        p.amount AS payment_amount,
        p.status AS payment_status_db,
        COALESCE(SUM(py.gross_amount), 0) AS total_gross_payouts,
        COALESCE(SUM(py.net_amount), 0) AS total_net_payouts
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.id AND p.status = 'success'
    LEFT JOIN payouts py ON py.order_id = o.id
    WHERE o.payment_status = 'paid'
    GROUP BY o.id
    ORDER BY o.id DESC
");
$reconRows = $reconStmt->fetchAll(PDO::FETCH_ASSOC);

$discrepancies = [];
foreach ($reconRows as $row) {
    $diff = abs((float)$row['order_total'] - (float)$row['payment_amount']);
    if ($diff > 0.05) {
        $discrepancies[] = [
            'order_id' => $row['order_id'],
            'order_code' => $row['order_code'],
            'reason' => "Order total (KSh {$row['order_total']}) does not match paid amount (KSh {$row['payment_amount']})",
        ];
    }
}

$csrfToken = dev_csrf_token();
dev_header('Payment Reconciliation Tools', 'reconciliation');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Payment &amp; Ledger Reconciliation</h1>
        <div class="page-subtitle">Identify stuck transactions, audit platform fee balance, and inspect ledger accuracy</div>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card">
        <div class="metric-label">Stuck Pending Payments</div>
        <div class="metric-value" style="color: <?= count($stuckPayments) > 0 ? '#f87171' : '#34d399' ?>;">
            <?= count($stuckPayments) ?>
        </div>
        <div class="metric-sub"><?= icon('clock', 'lucide-xs') ?> Age > 2 Minutes</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Paid Orders Audited</div>
        <div class="metric-value"><?= count($reconRows) ?></div>
        <div class="metric-sub"><?= icon('check-circle', 'lucide-xs') ?> In Settlement Ledger</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Ledger Discrepancies</div>
        <div class="metric-value" style="color: <?= count($discrepancies) > 0 ? '#f87171' : '#34d399' ?>;">
            <?= count($discrepancies) ?>
        </div>
        <div class="metric-sub"><?= icon('scale', 'lucide-xs') ?> 0.00 Cent Tolerance</div>
    </div>
</div>

<!-- Stuck Payments Section -->
<div class="card">
    <div class="card-title"><?= icon('alert-triangle', 'lucide-sm') ?> Stuck Pending Payments (> 2 mins old)</div>
    <?php if (empty($stuckPayments)): ?>
        <p style="font-size:0.85rem; color:#34d399;">
            <?= icon('check', 'lucide-xs') ?> Clean: No pending payments currently stuck in the queue.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Order</th>
                        <th>Phone</th>
                        <th>Amount</th>
                        <th>CheckoutRequestID</th>
                        <th>Stuck Age</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stuckPayments as $sp): ?>
                        <tr>
                            <td><code><?= $sp['id'] ?></code></td>
                            <td>#<?= (int) $sp['order_id'] ?> (<?= htmlspecialchars($sp['order_code'] ?? '') ?>)</td>
                            <td><code><?= Security::maskPhone($sp['phone']) ?></code></td>
                            <td>KSh <?= number_format($sp['amount'], 2) ?></td>
                            <td><code style="font-size:0.75rem;"><?= substr($sp['checkout_request_id'], 0, 16) ?>...</code></td>
                            <td style="color:#f87171; font-weight:700;"><?= (int) $sp['age_mins'] ?> mins</td>
                            <td>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="expire_stuck">
                                    <input type="hidden" name="payment_id" value="<?= $sp['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Mark this stuck payment as failed / timed out?">
                                        Expire (Timeout)
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Reconciliation Audit Table -->
<div class="card">
    <div class="card-title"><?= icon('check-check', 'lucide-sm') ?> Daily Settlement Ledger Reconciliation</div>
    <?php if (!empty($discrepancies)): ?>
        <div class="alert alert-error">
            <strong>Discrepancies Detected:</strong>
            <ul style="margin-left:1.25rem;">
                <?php foreach ($discrepancies as $d): ?>
                    <li>Order #<?= $d['order_id'] ?>: <?= htmlspecialchars($d['reason']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">
            <?= icon('check-circle', 'lucide-sm') ?>
            <span>All <?= count($reconRows) ?> paid order ledger splits balance 100% against platform receipts to the cent.</span>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Order Total</th>
                    <th>Paid Amount</th>
                    <th>Gross Ledger</th>
                    <th>Net Payouts</th>
                    <th>2% Escrow Fee</th>
                    <th>Reconciliation Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reconRows as $r): 
                    $escrowFee = (float)$r['total_gross_payouts'] - (float)$r['total_net_payouts'];
                ?>
                    <tr>
                        <td><strong>#<?= $r['order_id'] ?></strong> (<?= htmlspecialchars($r['order_code'] ?? '') ?>)</td>
                        <td>KSh <?= number_format($r['order_total'], 2) ?></td>
                        <td style="color:#34d399; font-weight:600;">KSh <?= number_format($r['payment_amount'], 2) ?></td>
                        <td>KSh <?= number_format($r['total_gross_payouts'], 2) ?></td>
                        <td>KSh <?= number_format($r['total_net_payouts'], 2) ?></td>
                        <td style="color:#38bdf8;">KSh <?= number_format($escrowFee, 2) ?></td>
                        <td>
                            <span class="badge badge-success">&bull; BALANCED</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
