<?php
/**
 * Developer Console: Telephony & Notification Tester
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../../includes/telephony.php';

dev_require_auth();

$pdo = get_db_connection();
$resultMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
    } else {
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $type = $_POST['type'] ?? 'sms';
        $message = trim((string) ($_POST['message'] ?? 'SokoHub Ops Test Message'));

        if ($type === 'call') {
            $ok = Telephony::makeVoiceCall($phone, $message);
            $resultMsg = "Simulated voice call dispatched to {$phone}. Result: " . ($ok ? 'Logged Successfully' : 'Failed');
        } else {
            $ok = Telephony::sendSms($phone, $message);
            $resultMsg = "SMS alert dispatched to {$phone}. Result: " . ($ok ? 'Logged Successfully' : 'Failed');
        }

        DevAudit::log('config', 'telephony_test_sent', $phone, ['type' => $type, 'ok' => $ok]);
    }
}

// Fetch Recent Telephony Logs
$logsStmt = $pdo->query('SELECT * FROM telephony_logs ORDER BY id DESC LIMIT 20');
$telephonyLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = dev_csrf_token();
dev_header('Notification &amp; Telephony Tester', 'notifications_tester');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Notification &amp; Telephony Tester</h1>
        <div class="page-subtitle">Test SMS gateways, automated voice alerts, and inspect delivery telemetry</div>
    </div>
</div>

<?php if ($resultMsg): ?>
    <div class="alert alert-success">
        <?= icon('check-circle', 'lucide-sm') ?>
        <span><?= htmlspecialchars($resultMsg) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 380px 1fr; gap: 1.25rem;">
    <!-- Dispatch Form -->
    <div class="card">
        <div class="card-title"><?= icon('send', 'lucide-sm') ?> Dispatch Test Notification</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="phone">Recipient Phone (Kenya 07XX / +254)</label>
                <input type="text" id="phone" name="phone" class="form-input" required placeholder="0712345678" value="254712345678">
            </div>

            <div class="form-group">
                <label class="form-label" for="type">Alert Channel</label>
                <select id="type" name="type" class="form-select">
                    <option value="sms">SMS Text Alert</option>
                    <option value="call">Automated Voice Call (IVR)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="message">Test Message Body</label>
                <textarea id="message" name="message" class="form-textarea" rows="3">SokoHub DevConsole Test Notification: System alerts pipeline operational.</textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                <?= icon('bell', 'lucide-xs') ?> Send Test Alert
            </button>
        </form>
    </div>

    <!-- Recent Telephony Logs -->
    <div class="card">
        <div class="card-title"><?= icon('list', 'lucide-sm') ?> Recent Telephony Outbox (telephony_logs)</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Dispatched</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($telephonyLogs)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:1.25rem; color:var(--text-muted);">No telephony logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($telephonyLogs as $tl): ?>
                            <tr>
                                <td><span class="badge badge-dev"><?= strtoupper($tl['type']) ?></span></td>
                                <td><code><?= Security::maskPhone($tl['phone']) ?></code></td>
                                <td><span style="font-size:0.75rem; color:var(--text-secondary);"><?= htmlspecialchars(substr($tl['message'] ?? '', 0, 45)) ?>...</span></td>
                                <td><span class="badge badge-success"><?= strtoupper($tl['status']) ?></span></td>
                                <td><?= htmlspecialchars($tl['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php dev_footer(); ?>
