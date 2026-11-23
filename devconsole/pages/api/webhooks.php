<?php
/**
 * Developer Console: Webhook & Callback History & Replay
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../../includes/mpesa.php';

dev_require_auth();

$pdo = get_db_connection();
$replayResult = null;
$error = null;

// Replay Webhook (Sandbox Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $mpesaEnv = strtolower(getenv('MPESA_ENV') ?: 'sandbox');
        if ($mpesaEnv === 'production') {
            $error = 'Webhook replay is strictly blocked in production.';
        } else {
            $rawPayload = trim((string) ($_POST['payload'] ?? ''));
            if ($rawPayload === '') {
                $error = 'Payload cannot be empty.';
            } else {
                $process = mpesa_process_callback($pdo, $rawPayload);
                $replayResult = $process;
                DevAudit::log('api', 'webhook_replayed', null, ['res' => $process['message'] ?? '']);
            }
        }
    }
}

// Fetch Recent Payments with Callbacks
$cbStmt = $pdo->query("
    SELECT * FROM payments 
    WHERE method = 'mpesa' AND status IN ('success', 'failed')
    ORDER BY updated_at DESC LIMIT 20
");
$recentCallbacks = $cbStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = dev_csrf_token();
dev_header('Webhook History &amp; Replay', 'webhooks');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Webhook History &amp; Replay</h1>
        <div class="page-subtitle">Inspect historical incoming Safaricom Daraja callbacks and safely replay payloads in sandbox</div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= icon('alert-circle', 'lucide-sm') ?>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($replayResult): ?>
    <div class="card" style="border-color: <?= $replayResult['ok'] ? '#059669' : '#dc2626' ?>;">
        <div class="card-title" style="color: <?= $replayResult['ok'] ? '#34d399' : '#f87171' ?>;">
            <?= icon($replayResult['ok'] ? 'check-circle' : 'x-circle', 'lucide-sm') ?>
            Callback Replay Result: <?= htmlspecialchars($replayResult['message']) ?>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- Replay Form -->
    <div class="card">
        <div class="card-title"><?= icon('repeat', 'lucide-sm') ?> Replay Callback Payload (Sandbox Only)</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="payload">JSON Callback Payload</label>
                <textarea id="payload" name="payload" class="form-textarea" rows="8" placeholder='{"Body": {"stkCallback": {...}}}'></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                <?= icon('send', 'lucide-xs') ?> Replay Callback to Pipeline
            </button>
        </form>
    </div>

    <!-- Recent Callbacks -->
    <div class="card">
        <div class="card-title"><?= icon('list', 'lucide-sm') ?> Historical M-Pesa Callbacks Processed</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>CheckoutID</th>
                        <th>Receipt</th>
                        <th>Status</th>
                        <th>Processed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentCallbacks)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:1.25rem; color:var(--text-muted);">No callbacks processed yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentCallbacks as $rc): ?>
                            <tr>
                                <td>#<?= (int) $rc['order_id'] ?></td>
                                <td><code style="font-size:0.75rem;"><?= substr($rc['checkout_request_id'], 0, 14) ?>...</code></td>
                                <td><code><?= htmlspecialchars($rc['mpesa_receipt_number'] ?: '—') ?></code></td>
                                <td><span class="badge <?= $rc['status'] === 'success' ? 'badge-success' : 'badge-danger' ?>"><?= strtoupper($rc['status']) ?></span></td>
                                <td><?= htmlspecialchars($rc['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php dev_footer(); ?>
