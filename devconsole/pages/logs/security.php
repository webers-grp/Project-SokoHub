<?php
/**
 * Developer Console: Security Events & Threat Monitoring
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dev = dev_current_user();

// Handle Owner Unblock / Clear Rate Limit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dev_require_role('owner');
    dev_require_reauth(dev_url('pages/logs/security.php'));

    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'clear_all_rate_limits') {
            $pdo->exec('TRUNCATE TABLE rate_limits');
            DevAudit::log('security', 'rate_limits_flushed', null, []);
            dev_set_flash('success', 'All rate limit throttles and counters have been reset.');
        } elseif ($action === 'clear_lockouts') {
            $pdo->exec('DELETE FROM login_attempts WHERE success = 0');
            DevAudit::log('security', 'login_lockouts_cleared', null, []);
            dev_set_flash('success', 'All brute force login lockouts have been released.');
        }
    }
    header('Location: ' . dev_url('pages/logs/security.php'));
    exit;
}

// 1. Failed Login Attempts
$attemptsStmt = $pdo->query('
    SELECT * FROM login_attempts 
    WHERE success = 0 
    ORDER BY id DESC LIMIT 25
');
$failedAttempts = $attemptsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Active Rate Limits
$limitsStmt = $pdo->query('
    SELECT * FROM rate_limits 
    WHERE reset_at > NOW() 
    ORDER BY hits DESC LIMIT 25
');
$activeLimits = $limitsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Security Audit Logs
$secAuditStmt = $pdo->query("
    SELECT * FROM audit_logs 
    WHERE action LIKE '%auth%' OR action LIKE '%security%' OR action LIKE '%kyc%'
    ORDER BY id DESC LIMIT 25
");
$secAudits = $secAuditStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = dev_csrf_token();
dev_header('Security Events & Threat Monitoring', 'security_events');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Security Events &amp; Lockout Monitor</h1>
        <div class="page-subtitle">Track credential brute-force attacks, rate-limit throttles, and authorization anomalies</div>
    </div>
    <?php if ($dev['role'] === 'owner'): ?>
        <div style="display: flex; gap: 0.5rem;">
            <form method="POST" action="" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_lockouts">
                <button type="submit" class="btn btn-secondary btn-sm" data-confirm="Release all active login lockouts?">
                    Release Login Lockouts
                </button>
            </form>
            <form method="POST" action="" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_all_rate_limits">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Flush all active rate limits across the entire system?">
                    Reset Rate Limits
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <!-- Failed Logins Table -->
    <div class="card">
        <div class="card-title"><?= icon('shield-alert', 'lucide-sm') ?> Failed Login Attempts (Brute-Force Monitor)</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Target Account</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($failedAttempts)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:1.25rem; color:#34d399;">No failed login attempts recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($failedAttempts as $fa): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($fa['email']) ?></strong></td>
                                <td><code><?= htmlspecialchars($fa['ip_address']) ?></code></td>
                                <td><?= htmlspecialchars($fa['attempted_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Rate Limits -->
    <div class="card">
        <div class="card-title"><?= icon('gauge', 'lucide-sm') ?> Active Rate-Limit Windows</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Identifier / IP</th>
                        <th>Hits</th>
                        <th>Resets At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activeLimits)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:1.25rem; color:#34d399;">No active rate limit throttles.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activeLimits as $rl): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($rl['action']) ?></code></td>
                                <td><?= htmlspecialchars($rl['identifier']) ?></td>
                                <td><span class="badge badge-warning"><?= (int) $rl['hits'] ?></span></td>
                                <td><?= htmlspecialchars($rl['reset_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Security Audit Log Stream -->
<div class="card">
    <div class="card-title"><?= icon('activity', 'lucide-sm') ?> Security Audit Log Stream</div>
    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Action</th>
                    <th>Actor ID</th>
                    <th>Role</th>
                    <th>Details</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($secAudits)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:1.25rem; color:var(--text-muted);">No security audit events logged yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($secAudits as $sa): ?>
                        <tr>
                            <td><code><?= $sa['id'] ?></code></td>
                            <td><span class="badge badge-dev"><?= htmlspecialchars($sa['action']) ?></span></td>
                            <td><?= $sa['user_id'] ? '#' . $sa['user_id'] : 'system' ?></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($sa['user_role'] ?? 'guest') ?></span></td>
                            <td><code style="font-size:0.75rem;"><?= htmlspecialchars(substr($sa['details'] ?? '', 0, 100)) ?></code></td>
                            <td><?= htmlspecialchars($sa['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
