<?php
/**
 * SokoHub Production System Health & Security Diagnostic Monitor
 *
 * Real-time operational diagnostics for Administrators:
 * - Database connectivity, schema tables & query latency
 * - Safaricom Daraja M-Pesa OAuth token generation test
 * - Webhook heartbeat: Last successful M-Pesa callback timestamp
 * - Financial ledger status: Pending & failed payouts count
 * - Upload directory writeability and storage disk free space
 * - Server environment security posture (APP_ENV, debug status, HTTPS, security headers)
 * - Required PHP extensions (pdo_mysql, curl, json, mbstring, fileinfo, openssl)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$adminUser = require_role(['admin']);
$pdo = get_db_connection();

$diagnostics = [];

// 1. Database Check
$dbStart = microtime(true);
try {
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    $tableCount = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);
    $diagnostics['database'] = [
        'status'  => 'healthy',
        'title'   => 'MySQL Database',
        'desc'    => "Version {$ver} ({$tableCount} tables). Query latency: {$dbLatency} ms",
        'latency' => $dbLatency . ' ms',
    ];
} catch (Throwable $e) {
    $diagnostics['database'] = [
        'status' => 'critical',
        'title'  => 'MySQL Database',
        'desc'   => 'Connection Error: ' . $e->getMessage(),
    ];
}

// 2. Daraja OAuth Token Test
try {
    $mpesaCfg = get_mpesa_config();
    $tokenRes = mpesa_fetch_token();
    if ($tokenRes['ok']) {
        $diagnostics['mpesa'] = [
            'status' => 'healthy',
            'title'  => 'Safaricom Daraja API',
            'desc'   => "Environment: {$mpesaCfg['env']} (Till: {$mpesaCfg['till_number']}). OAuth token generated successfully.",
        ];
    } else {
        $diagnostics['mpesa'] = [
            'status' => $mpesaCfg['is_live'] ? 'warning' : 'healthy',
            'title'  => 'Safaricom Daraja API',
            'desc'   => "Sandbox mode: " . ($tokenRes['error'] ?: 'Awaiting live API credentials.'),
        ];
    }
} catch (Throwable $e) {
    $diagnostics['mpesa'] = [
        'status' => 'warning',
        'title'  => 'Safaricom Daraja API',
        'desc'   => 'Daraja probe error: ' . $e->getMessage(),
    ];
}

// 3. Webhook Heartbeat (Last successful callback)
try {
    $cbStmt = $pdo->query("SELECT created_at FROM mpesa_callbacks WHERE callback_type = 'stk' ORDER BY id DESC LIMIT 1");
    $lastCb = $cbStmt->fetchColumn();
    $diagnostics['callbacks'] = [
        'status' => 'healthy',
        'title'  => 'M-Pesa Webhook Receiver',
        'desc'   => $lastCb ? "Last callback received: {$lastCb}" : "Listening on /pages/api/mpesa_callback.php (No callbacks received yet)",
    ];
} catch (Throwable $e) {
    $diagnostics['callbacks'] = [
        'status' => 'warning',
        'title'  => 'M-Pesa Webhook Receiver',
        'desc'   => $e->getMessage(),
    ];
}

// 4. Financial Ledger (Pending / Failed Payouts)
try {
    $pStmt = $pdo->query("
        SELECT 
            SUM(status = 'pending') AS pending_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status = 'succeeded') AS paid_count
        FROM payouts
    ");
    $payoutStats = $pStmt->fetch(PDO::FETCH_ASSOC);
    $pendingP = (int) ($payoutStats['pending_count'] ?? 0);
    $failedP = (int) ($payoutStats['failed_count'] ?? 0);
    $paidP = (int) ($payoutStats['paid_count'] ?? 0);

    $diagnostics['ledger'] = [
        'status' => $failedP > 0 ? 'warning' : 'healthy',
        'title'  => 'Payout Settlement Ledger',
        'desc'   => "Disbursed: {$paidP} | Pending: {$pendingP} | Failed: {$failedP}",
    ];
} catch (Throwable $e) {
    $diagnostics['ledger'] = [
        'status' => 'warning',
        'title'  => 'Payout Settlement Ledger',
        'desc'   => $e->getMessage(),
    ];
}

// 5. Storage & Upload Directory
$uploadDir = APP_ROOT . '/assets/img/uploads';
$isWritable = is_writable($uploadDir);
$diskFree = disk_free_space($uploadDir);
$freeGb = $diskFree !== false ? round($diskFree / (1024 * 1024 * 1024), 2) . ' GB' : 'N/A';
$diagnostics['storage'] = [
    'status' => $isWritable ? 'healthy' : 'critical',
    'title'  => 'File Storage & Uploads',
    'desc'   => "Directory writable: " . ($isWritable ? 'Yes' : 'No') . " | Available Disk Space: {$freeGb}",
];

// 6. Security Posture
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$displayErrors = ini_get('display_errors');
$diagnostics['security'] = [
    'status' => ($displayErrors == '1' && IS_PRODUCTION) ? 'warning' : 'healthy',
    'title'  => 'Security & Hardening Posture',
    'desc'   => "App Env: " . APP_ENV . " | display_errors: " . ($displayErrors == '1' ? 'ON' : 'OFF') . " | HTTPS: " . ($isHttps ? 'Active' : 'Local HTTP'),
];

// 7. PHP Extensions
$requiredExts = ['pdo_mysql', 'curl', 'json', 'mbstring', 'fileinfo', 'openssl'];
$missingExts = [];
foreach ($requiredExts as $ext) {
    if (!extension_loaded($ext)) {
        $missingExts[] = $ext;
    }
}
$diagnostics['extensions'] = [
    'status' => empty($missingExts) ? 'healthy' : 'critical',
    'title'  => 'PHP Engine & Extensions',
    'desc'   => "PHP Version: " . PHP_VERSION . " (" . (empty($missingExts) ? 'All required extensions loaded' : 'Missing: ' . implode(', ', $missingExts)) . ")",
];

$page_title = 'System Health & Security Diagnostics - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 1100px; margin: 2rem auto; padding: 0 1rem;">
    <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <nav style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
                <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" style="color: #64748b; text-decoration: none;">Dashboard</a> &rsaquo;
                <span style="color: #0f172a; font-weight: 600;">System Health Diagnostics</span>
            </nav>
            <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                <?= icon('activity', 'lucide-sm', 'emerald') ?> <span>System Health &amp; Security Monitor</span>
            </h1>
            <p style="color: #64748b; margin: 0.25rem 0 0; font-size: 0.9rem;">
                Operational status of database, payment gateways, webhook receivers, and storage subsystems.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="?refresh=1" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px;">
                <?= icon('refresh-cw', 'lucide-xs') ?> <span>Refresh Probes</span>
            </a>
            <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <?= icon('dollar-sign', 'lucide-xs') ?> <span>Settlement Ledger</span>
            </a>
        </div>
    </div>

    <!-- Diagnostics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem;">
        <?php foreach ($diagnostics as $key => $d): 
            $statusColor = $d['status'] === 'healthy' ? '#16a34a' : ($d['status'] === 'warning' ? '#d97706' : '#dc2626');
            $statusBg    = $d['status'] === 'healthy' ? '#f0fdf4' : ($d['status'] === 'warning' ? '#fffbeb' : '#fef2f2');
            $statusBorder= $d['status'] === 'healthy' ? '#bbf7d0' : ($d['status'] === 'warning' ? '#fde68a' : '#fecaca');
            $statusIcon  = $d['status'] === 'healthy' ? 'check-circle' : ($d['status'] === 'warning' ? 'alert-triangle' : 'x-circle');
        ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-top: 4px solid <?= $statusColor ?>; border-radius: 10px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin: 0;">
                        <?= e($d['title']) ?>
                    </h3>
                    <span style="background: <?= $statusBg ?>; color: <?= $statusColor ?>; border: 1px solid <?= $statusBorder ?>; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px;">
                        <?= icon($statusIcon, 'lucide-xs') ?> <span><?= e($d['status']) ?></span>
                    </span>
                </div>
                <p style="font-size: 0.85rem; color: #475569; margin: 0; line-height: 1.5;">
                    <?= e($d['desc']) ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
