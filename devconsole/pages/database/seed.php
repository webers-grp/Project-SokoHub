<?php
/**
 * Developer Console: Seed & Test Data Tools
 * Strictly blocked in production mode.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$appEnv = strtolower(defined('APP_ENV') ? APP_ENV : 'development');
if ($appEnv === 'production') {
    DevAudit::log('security', 'seed_blocked_production', null, [], 'blocked');
    http_response_code(403);
    die('<div style="padding:2rem;color:red;font-family:sans-serif;"><h3>Security Exception</h3><p>Seed & Test Data Tools are permanently disabled in production mode.</p></div>');
}

$pdo = get_db_connection();
$dev = dev_current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/database/seed.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_test_order') {
        // Create a test order and pending M-Pesa payment
        $orderCode = 'TEST-' . strtoupper(bin2hex(random_bytes(4)));
        $chkId = 'ws_CO_' . date('dmYHis') . mt_rand(100, 999);
        $amount = 1500.00;

        $pdo->prepare('
            INSERT INTO orders (order_code, buyer_id, total_amount, payment_status, order_status, notes, created_at)
            VALUES (:ocode, 4, :amt, "pending", "pending", "DevConsole Simulated Test Order", NOW())
        ')->execute([':ocode' => $orderCode, ':amt' => $amount]);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO payments (order_id, method, amount, phone, status, checkout_request_id, merchant_request_id, created_at)
            VALUES (:oid, "mpesa", :amt, "254712345678", "pending", :cid, "MR_TEST", NOW())
        ')->execute([':oid' => $orderId, ':amt' => $amount, ':cid' => $chkId]);

        DevAudit::log('database', 'test_order_seeded', (string) $orderId, ['order_code' => $orderCode]);
        dev_set_flash('success', "Test order #{$orderId} ({$orderCode}) generated with pending M-Pesa CheckoutID: {$chkId}");
    } elseif ($action === 'purge_test_data') {
        dev_require_role('owner');
        // Only purge records clearly flagged as test
        $pdo->exec("DELETE FROM payments WHERE checkout_request_id LIKE 'ws_CO_%TEST%' OR merchant_request_id = 'MR_TEST'");
        $pdo->exec("DELETE FROM orders WHERE order_code LIKE 'TEST-%' OR notes LIKE '%Simulated Test Order%'");

        DevAudit::log('database', 'test_data_purged', null, []);
        dev_set_flash('info', 'Cleaned up all records created by the test data generator.');
    }

    header('Location: ' . dev_url('pages/database/seed.php'));
    exit;
}

$csrfToken = dev_csrf_token();
dev_header('Seed & Test Data Tools', 'seed');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Seed &amp; Test Data Tools</h1>
        <div class="page-subtitle">Generate synthetic test orders and payments for pipeline verification without polluting production</div>
    </div>
    <span class="badge badge-sandbox">&bull; NON-PRODUCTION ONLY</span>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- Generator Panel -->
    <div class="card">
        <div class="card-title"><?= icon('plus-circle', 'lucide-sm') ?> Generate Test Order &amp; STK Payment</div>
        <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Creates a synthetic test order with a linked pending M-Pesa payment record, ready for testing in the STK Push Simulator.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create_test_order">
            <button type="submit" class="btn btn-primary">
                <?= icon('zap', 'lucide-xs') ?> Generate Test Order (KSh 1,500)
            </button>
        </form>
    </div>

    <!-- Purge Panel -->
    <div class="card">
        <div class="card-title"><?= icon('trash-2', 'lucide-sm') ?> Purge Synthetic Test Data</div>
        <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Safely purges only synthetic records flagged with <code>TEST-</code> prefixes or simulator notes. Live orders are untouched.
        </p>
        <?php if ($dev['role'] === 'owner'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="purge_test_data">
                <button type="submit" class="btn btn-danger" data-confirm="Purge all generated test orders and payments?">
                    <?= icon('trash-2', 'lucide-xs') ?> Purge Test Data (Owner)
                </button>
            </form>
        <?php else: ?>
            <span style="font-size:0.75rem; color:var(--text-muted);">Purge requires Owner role</span>
        <?php endif; ?>
    </div>
</div>

<?php dev_footer(); ?>
