<?php
/**
 * Developer Console: Dashboard & System Health
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dbStatus = test_db_connection();

// 1. DB Latency Benchmark
$startDb = microtime(true);
$pdo->query('SELECT 1')->fetch();
$dbLatencyMs = round((microtime(true) - $startDb) * 1000, 2);

// 2. Counts & Metrics
$pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
$stuckPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 2 MINUTE)")->fetchColumn();
$failedPayouts = (int) $pdo->query("SELECT COUNT(*) FROM payouts WHERE status = 'failed'")->fetchColumn();
$failedNotifs = 0; // Telephony or notifications

// 3. Last Callback Time
$lastCallback = $pdo->query("SELECT MAX(updated_at) FROM payments WHERE status IN ('success', 'failed') AND method = 'mpesa'")->fetchColumn();

// 4. Disk Usage Calculation
function get_dir_size(string $dir): int
{
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }
    return $size;
}

$uploadsBytes = get_dir_size(APP_ROOT . '/assets/img/uploads');
$backupsBytes = get_dir_size(APP_ROOT . '/backups');
$logsBytes    = get_dir_size(APP_ROOT . '/logs');

function format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// 5. Extensions Check
$requiredExts = ['pdo', 'pdo_mysql', 'curl', 'openssl', 'json', 'mbstring', 'fileinfo'];
$missingExts = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));

// 6. Recent Error Count from Log
$logFile = APP_ROOT . '/logs/app_error.log';
$recentErrors = 0;
if (file_exists($logFile)) {
    $lines = file($logFile);
    $recentErrors = count(array_filter($lines, fn($l) => str_contains($l, 'Fatal') || str_contains($l, 'Error')));
}

// System Health Rating
$healthStatus = 'healthy';
$healthMessages = [];
if ($stuckPayments > 0) {
    $healthStatus = 'warning';
    $healthMessages[] = "{$stuckPayments} payment(s) are stuck pending > 2 minutes.";
}
if ($failedPayouts > 0) {
    $healthStatus = 'warning';
    $healthMessages[] = "{$failedPayouts} payout(s) marked as failed.";
}
if ($dbLatencyMs > 100) {
    $healthStatus = 'warning';
    $healthMessages[] = "High database latency: {$dbLatencyMs} ms.";
}
if (!empty($missingExts)) {
    $healthStatus = 'failing';
    $healthMessages[] = "Missing critical PHP extensions: " . implode(', ', $missingExts);
}

dev_header('System Health & Operations Dashboard', 'dashboard');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">System Health &amp; Diagnostics</h1>
        <div class="page-subtitle">Real-time infrastructure health, payment pipelines, and ops telemetry</div>
    </div>
    <div>
        <?php if ($healthStatus === 'healthy'): ?>
            <span class="badge badge-success" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                <?= icon('check-circle', 'lucide-sm') ?> System Operational &amp; Healthy
            </span>
        <?php elseif ($healthStatus === 'warning'): ?>
            <span class="badge badge-warning" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                <?= icon('alert-triangle', 'lucide-sm') ?> System Notice / Warnings Active
            </span>
        <?php else: ?>
            <span class="badge badge-danger" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                <?= icon('x-circle', 'lucide-sm') ?> System Critical Fault Detected
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($healthMessages)): ?>
    <div class="alert alert-warning">
        <?= icon('alert-triangle', 'lucide-md') ?>
        <div>
            <strong>Operational Attention Needed:</strong>
            <ul style="margin-left: 1.25rem; margin-top: 0.25rem;">
                <?php foreach ($healthMessages as $msg): ?>
                    <li><?= htmlspecialchars($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<!-- Metric Cards Grid -->
<div class="metric-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));">
    <div class="metric-card">
        <div class="metric-label">PHP Runtime</div>
        <div class="metric-value">PHP <?= e(PHP_VERSION) ?></div>
        <div class="metric-sub"><?= icon('check-circle', 'lucide-xs') ?> Active &bull; <?= php_sapi_name() ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Session Engine</div>
        <div class="metric-value"><?= session_status() === PHP_SESSION_ACTIVE ? 'Running' : 'Inactive' ?></div>
        <div class="metric-sub"><?= icon('check-circle', 'lucide-xs') ?> SOKOHUB_DEV_SESSID</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">MySQL / MariaDB</div>
        <div class="metric-value" style="color: <?= $dbStatus['ok'] ? '#34d399' : '#f87171' ?>;">
            <?= $dbStatus['ok'] ? 'Connected' : 'Error' ?>
        </div>
        <div class="metric-sub"><?= icon('database', 'lucide-xs') ?> <?= $dbStatus['ok'] ? e($dbStatus['version']) : 'Connection Failed' ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Database Tables</div>
        <div class="metric-value" style="color: <?= count($dbStatus['tables']) >= 9 ? '#34d399' : '#f59e0b' ?>;">
            <?= count($dbStatus['tables']) ?> <span style="font-size: 0.9rem;">/ 9</span>
        </div>
        <div class="metric-sub"><?= icon('table', 'lucide-xs') ?> <?= count($dbStatus['tables']) >= 9 ? 'Schema Ready' : 'Incomplete' ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Database Latency</div>
        <div class="metric-value" style="color: <?= $dbLatencyMs < 20 ? '#34d399' : '#f59e0b' ?>;">
            <?= $dbLatencyMs ?> <span style="font-size: 0.9rem;">ms</span>
        </div>
        <div class="metric-sub"><?= icon('gauge', 'lucide-xs') ?> Query Response</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Stuck Payments (> 2m)</div>
        <div class="metric-value" style="color: <?= $stuckPayments > 0 ? '#f87171' : '#34d399' ?>;">
            <?= $stuckPayments ?>
        </div>
        <div class="metric-sub"><?= icon('clock', 'lucide-xs') ?> Total Pending: <?= $pendingPayments ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Failed Payouts</div>
        <div class="metric-value" style="color: <?= $failedPayouts > 0 ? '#f87171' : '#34d399' ?>;">
            <?= $failedPayouts ?>
        </div>
        <div class="metric-sub"><?= icon('wallet', 'lucide-xs') ?> Requires Review</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Disk Storage (Uploads)</div>
        <div class="metric-value"><?= format_bytes($uploadsBytes) ?></div>
        <div class="metric-sub"><?= icon('hard-drive', 'lucide-xs') ?> Backups: <?= format_bytes($backupsBytes) ?></div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- Infrastructure Details -->
    <div class="card">
        <div class="card-title"><?= icon('server', 'lucide-sm') ?> Runtime &amp; Environment</div>
        <table class="dev-table">
            <tr>
                <td style="width: 40%; color: var(--text-muted);">PHP Version</td>
                <td><code><?= phpversion() ?></code> (SAPI: <?= php_sapi_name() ?>)</td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Server Host &amp; Port</td>
                <td><code><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? php_uname('s')) ?></code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Database Name</td>
                <td><code><?= htmlspecialchars(getenv('DB_NAME') ?: 'sokohub_db') ?></code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">M-Pesa API Mode</td>
                <td>
                    <span class="badge <?= strtoupper(getenv('MPESA_ENV') ?: '') === 'PRODUCTION' ? 'badge-danger' : 'badge-success' ?>">
                        <?= strtoupper(getenv('MPESA_ENV') ?: 'SANDBOX') ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Required Extensions</td>
                <td>
                    <?php foreach ($requiredExts as $ext): ?>
                        <span class="badge <?= extension_loaded($ext) ? 'badge-success' : 'badge-danger' ?>" style="font-size: 0.65rem; margin-right: 0.2rem;">
                            <?= $ext ?>
                        </span>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Payments Telemetry -->
    <div class="card">
        <div class="card-title"><?= icon('credit-card', 'lucide-sm') ?> M-Pesa Pipeline Status</div>
        <table class="dev-table">
            <tr>
                <td style="width: 40%; color: var(--text-muted);">Shortcode</td>
                <td><code><?= htmlspecialchars(getenv('MPESA_SHORTCODE') ?: '174379') ?></code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Platform Till</td>
                <td><code><?= htmlspecialchars(getenv('MPESA_TILL_NUMBER') ?: '1648753') ?></code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Last STK Callback</td>
                <td>
                    <?= $lastCallback ? htmlspecialchars($lastCallback) : '<span style="color:var(--text-muted);">No callbacks recorded yet</span>' ?>
                </td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Platform Settlement Fee</td>
                <td><strong><?= htmlspecialchars(getenv('PLATFORM_FEE_PERCENT') ?: '2') ?>%</strong> (Escrow Model)</td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Quick Action</td>
                <td>
                    <a href="<?= dev_url('pages/payments/tester.php') ?>" class="btn btn-secondary btn-sm">
                        <?= icon('refresh-cw', 'lucide-xs') ?> Test Daraja Token
                    </a>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Detected Database Tables Diagnostic Card -->
<div class="card" style="margin-top: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
        <div class="card-title" style="margin-bottom: 0;">
            <?= icon('table', 'lucide-sm') ?> Detected Database Tables (<?= count($dbStatus['tables']) ?>)
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <a href="<?= dev_url('pages/database/schema.php') ?>" class="btn btn-secondary btn-sm">
                <?= icon('layers', 'lucide-xs') ?> Schema Browser
            </a>
            <a href="<?= dev_url('pages/database/browser.php') ?>" class="btn btn-secondary btn-sm">
                <?= icon('database', 'lucide-xs') ?> Data Browser
            </a>
        </div>
    </div>
    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0; margin-bottom: 0.75rem;">
        Active database schema verified via PDO probe. Click any table name to inspect its schema and rows.
    </p>
    <?php if ($dbStatus['ok'] && !empty($dbStatus['tables'])): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
            <?php foreach ($dbStatus['tables'] as $tbl): ?>
                <a href="<?= dev_url('pages/database/browser.php?table=' . urlencode($tbl)) ?>"
                   class="badge badge-neutral"
                   style="text-decoration: none; padding: 0.35rem 0.65rem; font-family: monospace; font-size: 0.8rem; border: 1px solid var(--border);"
                   title="Browse table <?= htmlspecialchars($tbl) ?>">
                    <?= htmlspecialchars($tbl) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <?= icon('alert-circle', 'lucide-sm') ?>
            <span>Database error: <?= htmlspecialchars($dbStatus['error'] ?? 'No tables found') ?></span>
        </div>
    <?php endif; ?>
</div>

<?php dev_footer(); ?>
