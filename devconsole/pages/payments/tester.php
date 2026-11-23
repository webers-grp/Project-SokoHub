<?php
/**
 * Developer Console: Daraja Connection & Token Tester
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../../includes/mpesa.php';

dev_require_auth();

$testResult = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $start = microtime(true);
        $res = mpesa_get_access_token();
        $latency = round((microtime(true) - $start) * 1000, 2);

        $testResult = [
            'ok'        => $res['ok'] ?? false,
            'message'   => $res['message'] ?? '',
            'latency'   => $latency,
            'expires'   => !empty($res['token']) ? 'Valid (Cached / Active)' : 'Unavailable',
        ];

        DevAudit::log('payment_lab', 'daraja_token_test', null, [
            'ok'      => $res['ok'] ?? false,
            'latency' => $latency,
        ]);
    }
}

$csrfToken = dev_csrf_token();
dev_header('Daraja API Connection Tester', 'daraja_tester');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Daraja Connection &amp; Token Tester</h1>
        <div class="page-subtitle">Verify Safaricom Daraja OAuth handshake and latency without printing credentials</div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= icon('alert-circle', 'lucide-sm') ?>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($testResult): ?>
    <div class="card" style="border-color: <?= $testResult['ok'] ? '#059669' : '#dc2626' ?>;">
        <div class="card-title" style="color: <?= $testResult['ok'] ? '#34d399' : '#f87171' ?>;">
            <?= icon($testResult['ok'] ? 'check-circle' : 'x-circle', 'lucide-sm') ?>
            <?= $testResult['ok'] ? 'Daraja OAuth Handshake Succeeded' : 'Daraja Connection Failed' ?>
        </div>
        <table class="dev-table">
            <tr>
                <td style="width:30%; color:var(--text-muted);">Result Message</td>
                <td><strong><?= htmlspecialchars($testResult['message']) ?></strong></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Roundtrip Latency</td>
                <td><code><?= $testResult['latency'] ?> ms</code></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Token Status</td>
                <td><?= htmlspecialchars($testResult['expires']) ?></td>
            </tr>
        </table>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 600px;">
    <div class="card-title"><?= icon('wifi', 'lucide-sm') ?> Test OAuth Token Generation</div>
    <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:1.25rem;">
        Clicking the button below sends an authenticated OAuth request to Safaricom's token endpoint using the credentials configured in <code>.env</code>.
    </p>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem;">
            <?= icon('zap', 'lucide-xs') ?> Query Daraja API Token
        </button>
    </form>
</div>

<?php dev_footer(); ?>
