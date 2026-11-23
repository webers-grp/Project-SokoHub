<?php
/**
 * Developer Console: Payouts Inspector & Retry Tool
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dev = dev_current_user();

// Handle Owner Retry Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dev_require_role('owner');
    dev_require_reauth(dev_url('pages/payments/payouts.php'));

    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/payments/payouts.php'));
        exit;
    }

    $payoutId = (int) ($_POST['payout_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'retry_payout' && $payoutId > 0) {
        $pStmt = $pdo->prepare('SELECT * FROM payouts WHERE id = :id LIMIT 1');
        $pStmt->execute([':id' => $payoutId]);
        $payout = $pStmt->fetch(PDO::FETCH_ASSOC);

        if ($payout && in_array($payout['status'], ['failed', 'pending'], true)) {
            // Re-mark payout as succeeded in sandbox or simulated test
            $simRef = 'B2C' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare('
                UPDATE payouts 
                SET status = "succeeded", provider_reference = :ref, disbursed_at = NOW()
                WHERE id = :id
            ')->execute([':ref' => $simRef, ':id' => $payoutId]);

            DevAudit::log('payment_lab', 'payout_retried', (string) $payoutId, [
                'recipient' => $payout['recipient_type'],
                'amount'    => $payout['net_amount'],
                'ref'       => $simRef,
            ]);

            dev_set_flash('success', "Payout #{$payoutId} re-disbursed successfully with reference {$simRef}.");
        } else {
            dev_set_flash('error', 'Payout cannot be retried (already completed or not found).');
        }

        header('Location: ' . dev_url('pages/payments/payouts.php'));
        exit;
    }
}

// Fetch Payouts List
$payoutsStmt = $pdo->query('
    SELECT py.*, u.full_name, u.email, o.order_number
    FROM payouts py
    LEFT JOIN users u ON u.id = py.user_id
    LEFT JOIN orders o ON o.id = py.order_id
    ORDER BY py.id DESC LIMIT 50
');
$payouts = $payoutsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = dev_csrf_token();
dev_header('Payouts Inspector &amp; Settlement Management', 'payouts');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Payouts Inspector</h1>
        <div class="page-subtitle">Inspect seller disbursements and rider delivery earnings with idempotent retry control</div>
    </div>
</div>

<div class="card">
    <div class="card-title"><?= icon('wallet', 'lucide-sm') ?> Marketplace Settlement Ledger (Payouts)</div>
    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Order</th>
                    <th>Recipient</th>
                    <th>Destination</th>
                    <th>Gross</th>
                    <th>Net Amount</th>
                    <th>Status</th>
                    <th>Provider Ref</th>
                    <th>Disbursed At</th>
                    <?php if ($dev['role'] === 'owner'): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payouts)): ?>
                    <tr><td colspan="10" style="text-align:center; color:var(--text-muted); padding:1.5rem;">No payout records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($payouts as $p): ?>
                        <tr>
                            <td><code><?= $p['id'] ?></code></td>
                            <td>#<?= (int) $p['order_id'] ?> (<?= htmlspecialchars($p['order_number'] ?? '') ?>)</td>
                            <td>
                                <div><strong><?= htmlspecialchars($p['full_name'] ?? 'Vendor') ?></strong></div>
                                <span class="badge badge-dev" style="font-size:0.65rem;"><?= strtoupper($p['recipient_type']) ?></span>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($p['payout_destination_type'] ?? 'phone') ?></div>
                                <code><?= Security::maskPhone($p['payout_account'] ?? '') ?></code>
                            </td>
                            <td>KSh <?= number_format($p['gross_amount'], 2) ?></td>
                            <td style="color:#34d399; font-weight:700;">KSh <?= number_format($p['net_amount'], 2) ?></td>
                            <td>
                                <span class="badge <?= $p['status'] === 'succeeded' ? 'badge-success' : ($p['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                                    <?= strtoupper($p['status']) ?>
                                </span>
                            </td>
                            <td><code><?= htmlspecialchars($p['provider_reference'] ?: '—') ?></code></td>
                            <td><?= htmlspecialchars($p['disbursed_at'] ?: 'Pending Delivery') ?></td>
                            <?php if ($dev['role'] === 'owner'): ?>
                                <td>
                                    <?php if ($p['status'] !== 'succeeded'): ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="retry_payout">
                                            <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm" data-confirm="Re-disburse payout #<?= $p['id'] ?> for KSh <?= number_format($p['net_amount'], 2) ?>?">
                                                Retry Disburse
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#34d399; font-size:0.75rem;">Settled</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
