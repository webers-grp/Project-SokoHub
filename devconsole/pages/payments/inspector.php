<?php
/**
 * Developer Console: Payment & Ledger Inspector
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$query = trim((string) ($_GET['q'] ?? ($_GET['order_id'] ?? '')));
$payment = null;
$order = null;
$payouts = [];
$auditEvents = [];

if ($query !== '') {
    $stmt = $pdo->prepare('
        SELECT * FROM payments 
        WHERE order_id = :q1 
           OR checkout_request_id = :q2 
           OR mpesa_receipt = :q3
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([':q1' => $query, ':q2' => $query, ':q3' => $query]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        $orderId = (int) $payment['order_id'];

        // Fetch Order
        $oStmt = $pdo->prepare('SELECT * FROM orders WHERE id = :oid LIMIT 1');
        $oStmt->execute([':oid' => $orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch Ledger Splits (Payouts)
        $pStmt = $pdo->prepare('SELECT * FROM payouts WHERE order_id = :oid ORDER BY id ASC');
        $pStmt->execute([':oid' => $orderId]);
        $payouts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Audit Logs
        $aStmt = $pdo->prepare('SELECT * FROM audit_logs WHERE (entity_type = "order" AND entity_id = :oid) OR details LIKE :like ORDER BY id ASC');
        $aStmt->execute([':oid' => $orderId, ':like' => "%{$payment['checkout_request_id']}%"]);
        $auditEvents = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

dev_header('Payment & Ledger Inspector', 'inspector');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Payment &amp; Ledger Inspector</h1>
        <div class="page-subtitle">Deep dive into order payments, STK transactions, and settlement splits</div>
    </div>
</div>

<!-- Search Bar -->
<div class="card">
    <form method="GET" action="" style="display: flex; gap: 0.75rem;">
        <input type="text" name="q" class="form-input" value="<?= htmlspecialchars($query) ?>" placeholder="Enter Order ID (e.g. 6), CheckoutRequestID (ws_CO_...), or M-Pesa Receipt..." style="flex: 1;">
        <button type="submit" class="btn btn-primary">
            <?= icon('search', 'lucide-xs') ?> Inspect Payment
        </button>
    </form>
</div>

<?php if ($query !== '' && !$payment): ?>
    <div class="alert alert-warning">
        <?= icon('alert-circle', 'lucide-sm') ?>
        <span>No payment record found matching "<?= htmlspecialchars($query) ?>".</span>
    </div>
<?php elseif ($payment): ?>
    <!-- Overview Metrics -->
    <div class="metric-grid">
        <div class="metric-card">
            <div class="metric-label">Payment Status</div>
            <div class="metric-value">
                <span class="badge <?= $payment['status'] === 'success' ? 'badge-success' : ($payment['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>" style="font-size: 0.95rem;">
                    <?= strtoupper($payment['status']) ?>
                </span>
            </div>
            <div class="metric-sub"><?= icon('credit-card', 'lucide-xs') ?> Method: <?= strtoupper($payment['method']) ?></div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Transaction Amount</div>
            <div class="metric-value">KSh <?= number_format($payment['amount'], 2) ?></div>
            <div class="metric-sub"><?= icon('tag', 'lucide-xs') ?> Order #<?= $payment['order_id'] ?></div>
        </div>

        <div class="metric-card">
            <div class="metric-label">M-Pesa Receipt</div>
            <div class="metric-value" style="font-size: 1.15rem;"><?= htmlspecialchars($payment['mpesa_receipt'] ?? ($payment['mpesa_receipt_number'] ?? '—')) ?></div>
            <div class="metric-sub"><?= icon('hash', 'lucide-xs') ?> Verified Daraja Ref</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Settlement Splits</div>
            <div class="metric-value"><?= count($payouts) ?> Entries</div>
            <div class="metric-sub"><?= icon('split', 'lucide-xs') ?> 2% Platform Escrow</div>
        </div>
    </div>

    <!-- Payment & Order Details -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
        <div class="card">
            <div class="card-title"><?= icon('file-text', 'lucide-sm') ?> Raw Payment Record</div>
            <table class="dev-table">
                <tr><td style="color:var(--text-muted);">Payment ID</td><td><code><?= $payment['id'] ?></code></td></tr>
                <tr><td style="color:var(--text-muted);">Order ID</td><td><code>#<?= $payment['order_id'] ?></code></td></tr>
                <tr><td style="color:var(--text-muted);">CheckoutRequestID</td><td><code style="word-break:break-all;"><?= htmlspecialchars($payment['checkout_request_id']) ?></code></td></tr>
                <tr><td style="color:var(--text-muted);">MerchantRequestID</td><td><code><?= htmlspecialchars($payment['merchant_request_id']) ?></code></td></tr>
                <tr><td style="color:var(--text-muted);">Phone Number</td><td><code><?= Security::maskPhone($payment['phone']) ?></code></td></tr>
                <tr><td style="color:var(--text-muted);">Created At</td><td><?= $payment['created_at'] ?></td></tr>
                <tr><td style="color:var(--text-muted);">Updated At</td><td><?= $payment['updated_at'] ?></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title"><?= icon('package', 'lucide-sm') ?> Linked Order Summary</div>
            <?php if ($order): ?>
                <table class="dev-table">
                    <tr><td style="color:var(--text-muted);">Order Code</td><td><strong><?= htmlspecialchars($order['order_code']) ?></strong></td></tr>
                    <tr><td style="color:var(--text-muted);">Buyer ID</td><td><code><?= $order['buyer_id'] ?></code></td></tr>
                    <tr><td style="color:var(--text-muted);">Order Status</td><td><span class="badge badge-dev"><?= strtoupper($order['order_status']) ?></span></td></tr>
                    <tr><td style="color:var(--text-muted);">Payment Status</td><td><span class="badge <?= $order['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>"><?= strtoupper($order['payment_status']) ?></span></td></tr>
                    <tr><td style="color:var(--text-muted);">Delivery Fee</td><td>KSh <?= number_format($order['delivery_fee'] ?? 0, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Total Amount</td><td><strong>KSh <?= number_format($order['total_amount'], 2) ?></strong></td></tr>
                </table>
            <?php else: ?>
                <p style="font-size:0.85rem; color:var(--text-muted);">No order record linked.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Settlement Ledger Table -->
    <div class="card">
        <div class="card-title"><?= icon('scale', 'lucide-sm') ?> Settlement Ledger Breakdown (payouts table)</div>
        <?php if (empty($payouts)): ?>
            <p style="font-size:0.85rem; color:var(--text-muted);">No settlement split entries recorded for this order yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="dev-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Recipient Type</th>
                            <th>User ID</th>
                            <th>Gross Amount</th>
                            <th>2% Fee</th>
                            <th>Net Payout</th>
                            <th>Status</th>
                            <th>Disbursed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $p): ?>
                            <tr>
                                <td><code><?= $p['id'] ?></code></td>
                                <td><span class="badge badge-dev"><?= strtoupper($p['recipient_type']) ?></span></td>
                                <td><code><?= $p['user_id'] ?></code></td>
                                <td>KSh <?= number_format($p['gross_amount'], 2) ?></td>
                                <td style="color: #38bdf8;">KSh <?= number_format($p['gross_amount'] - $p['net_amount'], 2) ?></td>
                                <td style="font-weight:700; color:#34d399;">KSh <?= number_format($p['net_amount'], 2) ?></td>
                                <td><span class="badge <?= $p['status'] === 'succeeded' ? 'badge-success' : ($p['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>"><?= strtoupper($p['status']) ?></span></td>
                                <td><?= htmlspecialchars($p['paid_at'] ?? ($p['disbursed_at'] ?? 'Pending Delivery')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php dev_footer(); ?>
