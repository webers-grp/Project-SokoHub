<?php
/**
 * Developer Console: Scheduled Jobs & Cron Monitor
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$jobOutput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/logs/cron.php'));
        exit;
    }

    $job = $_POST['job'] ?? '';

    if ($job === 'reconcile_payments') {
        ob_start();
        require APP_ROOT . '/scripts/reconcile_payments.php';
        $jobOutput = ob_get_clean();
        DevAudit::log('logs', 'cron_executed_manually', 'reconcile_payments', []);
        dev_set_flash('success', 'Payment Reconciliation job finished successfully.');
    } elseif ($job === 'cleanup_tokens') {
        $del = $pdo->exec('DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR consumed_at IS NOT NULL');
        $delRate = $pdo->exec('DELETE FROM rate_limits WHERE reset_at < NOW()');
        $jobOutput = "Cleaned {$del} expired password tokens and {$delRate} expired rate limits.";
        DevAudit::log('logs', 'cron_executed_manually', 'cleanup_tokens', ['tokens' => $del, 'rate_limits' => $delRate]);
        dev_set_flash('success', 'Token and Rate-limit cleanup completed.');
    }
}

$csrfToken = dev_csrf_token();
dev_header('Scheduled Jobs &amp; Cron Monitor', 'cron');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Scheduled Jobs &amp; Background Cron</h1>
        <div class="page-subtitle">Inspect scheduled automation schedules, inspect execution status, and trigger on-demand jobs</div>
    </div>
</div>

<?php if ($jobOutput): ?>
    <div class="card" style="border-color: #059669;">
        <div class="card-title" style="color:#34d399;"><?= icon('check-circle', 'lucide-sm') ?> Job Execution Output</div>
        <pre class="code-block"><?= htmlspecialchars($jobOutput) ?></pre>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-title"><?= icon('clock', 'lucide-sm') ?> Registered Background Tasks</div>
    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>Job Name</th>
                    <th>Schedule</th>
                    <th>Description</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Daily Payment Reconciliation</strong></td>
                    <td><code>0 0 * * *</code> (Daily midnight)</td>
                    <td>Reconciles M-Pesa payments, platform 2% fees, seller settlements, and rider payouts.</td>
                    <td>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="job" value="reconcile_payments">
                            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('play', 'lucide-xs') ?> Run Now</button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <td><strong>Expired Tokens &amp; Rate-Limit Pruning</strong></td>
                    <td><code>*/30 * * * *</code> (Every 30m)</td>
                    <td>Purges expired password reset tokens and elapsed rate-limit sliding windows.</td>
                    <td>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="job" value="cleanup_tokens">
                            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('play', 'lucide-xs') ?> Run Now</button>
                        </form>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
