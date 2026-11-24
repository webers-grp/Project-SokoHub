<?php
/**
 * SokoHub Administrative System Health & Operations Suite
 * Provides comprehensive observability across MySQL database metrics,
 * asynchronous background job processing, live SMTP email diagnostics,
 * server PHP runtime health, and brute-force security telemetry.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$adminUser = require_role(['admin']);
$adminId   = (int) $adminUser['id'];
$pdo       = get_db_connection();

// Ensure all identity and security tables exist
Security::ensureSchema();

// Handle Operations POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/system_health.php');
    }

    $opAction = trim((string) ($_POST['action'] ?? ''));

    if ($opAction === 'run_jobs') {
        $processed = Security::processJobsBatch($pdo, 'admin-manual-' . $adminId, 25);
        Audit::record('MANUAL_JOB_QUEUE_DISPATCH', 'jobs', 0, ['processed_count' => $processed], $adminId, 'admin');
        if ($processed > 0) {
            set_flash('success', "Asynchronous queue executed: {$processed} pending job(s) processed.");
        } else {
            set_flash('info', 'Queue is clean. No pending background jobs were awaiting execution.');
        }
    } elseif ($opAction === 'test_smtp') {
        $recipient = trim((string) ($_POST['test_email'] ?? $adminUser['email']));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Please provide a valid recipient email address for SMTP testing.');
        } else {
            $smtpConfig = Security::getSmtpConfig();
            $testSubject = 'SokoHub Diagnostic SMTP Ping [' . date('Y-m-d H:i:s') . ']';
            $testHtml = Security::buildHtmlEmail(
                'SMTP Diagnostic Ping',
                "<p>Hello <strong>" . e($adminUser['full_name']) . "</strong>,</p>
                 <p>This is a live diagnostic ping dispatched directly from the <strong>SokoHub System Health</strong> suite at Kirinyaga University marketplace.</p>
                 <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin: 16px 0; font-family: monospace; font-size: 13px;'>
                     <strong>Diagnostic Context:</strong><br>
                     &bull; SMTP Host: " . e($smtpConfig['host'] ?: 'Local Fallback') . ":" . e((string) $smtpConfig['port']) . "<br>
                     &bull; Encryption: " . e(strtoupper($smtpConfig['encryption'])) . "<br>
                     &bull; Admin Initiator: " . e($adminUser['email']) . "<br>
                     &bull; Server Time: " . date('r') . "<br>
                 </div>
                 <p>If you received this message in your inbox, your outbound email transport engine is fully functional and ready for customer OTPs, orders, and receipts.</p>",
                'Return to SokoHub',
                url('pages/dashboard/system_health.php')
            );

            $smtpError = null;
            $startPing = microtime(true);
            $dispatched = Security::sendSmtpMail($recipient, $testSubject, $testHtml, true, $smtpError);
            $durationMs = round((microtime(true) - $startPing) * 1000, 1);

            if ($dispatched) {
                Audit::record('SMTP_TEST_SUCCESS', 'security', 0, [
                    'recipient' => $recipient,
                    'duration_ms' => $durationMs,
                ], $adminId, 'admin');
                set_flash('success', "SMTP ping to {$recipient} succeeded in {$durationMs}ms! Transport handshake verified.");
            } else {
                Audit::record('SMTP_TEST_FAILED', 'security', 0, [
                    'recipient' => $recipient,
                    'error' => $smtpError,
                ], $adminId, 'admin');
                set_flash('error', "SMTP ping failed: " . ($smtpError ?: 'Unknown socket error. Check SMTP host/port credentials.'));
            }
        }
    } elseif ($opAction === 'ping_daraja') {
        $pingResult = mpesa_ping_gateway();
        Audit::record('DARAJA_DIAGNOSTIC_PING', 'payments', 0, [
            'ok'          => $pingResult['ok'],
            'latency_ms'  => $pingResult['latency_ms'] ?? 0,
            'http_code'   => $pingResult['http_code'] ?? 0,
            'environment' => $pingResult['environment'] ?? 'sandbox',
        ], $adminId, 'admin');

        if ($pingResult['ok']) {
            set_flash('success', "Daraja M-Pesa Gateway online! Latency: {$pingResult['latency_ms']}ms [HTTP {$pingResult['http_code']}] ({$pingResult['environment']} environment).");
        } else {
            set_flash('error', "Daraja M-Pesa Gateway check failed: {$pingResult['message']} (Latency: {$pingResult['latency_ms']}ms).");
        }
    } elseif ($opAction === 'unlock_all') {
        $stmt = $pdo->prepare("UPDATE users SET locked_until = NULL, failed_login_count = 0 WHERE locked_until IS NOT NULL AND locked_until > NOW()");
        $stmt->execute();
        $unlockedCount = $stmt->rowCount();
        Audit::record('BULK_UNLOCK_ACCOUNTS', 'users', 0, ['unlocked_count' => $unlockedCount], $adminId, 'admin');
        set_flash('success', "Cleared brute-force lockouts for {$unlockedCount} account(s).");
    } elseif ($opAction === 'purge_attempts') {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $purgedCount = $stmt->rowCount();
        Audit::record('PURGE_LOGIN_ATTEMPTS', 'security', 0, ['purged_count' => $purgedCount], $adminId, 'admin');
        set_flash('info', "Purged {$purgedCount} archived login attempt logs older than 30 days.");
    }

    redirect('pages/dashboard/system_health.php');
}

// 1. Database Metrics & Health
$dbStart = microtime(true);
$pdo->query("SELECT 1")->fetch();
$dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);

$dbVersion = (string) $pdo->query("SELECT VERSION()")->fetchColumn();
$dbName    = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();

// Table statistics from information_schema
$tableStatsStmt = $pdo->query("
    SELECT 
        table_name,
        table_rows,
        ROUND((data_length) / 1024, 1) AS data_kb,
        ROUND((index_length) / 1024, 1) AS index_kb,
        ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb,
        engine,
        table_collation
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
");
$tableStats = $tableStatsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalTables = count($tableStats);
$totalRows = 0;
$totalDbMb = 0.0;
foreach ($tableStats as $ts) {
    $totalRows += (int) $ts['table_rows'];
    $totalDbMb += (float) $ts['total_mb'];
}

// 2. Job Queue Observability
$jobsSummaryStmt = $pdo->query("
    SELECT 
        status, 
        COUNT(*) AS cnt 
    FROM jobs 
    GROUP BY status
");
$jobsCounts = [
    'pending'    => 0,
    'processing' => 0,
    'completed'  => 0,
    'failed'     => 0,
];
while ($row = $jobsSummaryStmt->fetch(PDO::FETCH_ASSOC)) {
    $st = strtolower((string) $row['status']);
    if (isset($jobsCounts[$st])) {
        $jobsCounts[$st] = (int) $row['cnt'];
    }
}
$totalJobs = array_sum($jobsCounts);

$recentJobsStmt = $pdo->query("
    SELECT * 
    FROM jobs 
    ORDER BY id DESC 
    LIMIT 10
");
$recentJobs = $recentJobsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Outbound SMTP & Notification Telemetry
$smtpConfig = Security::getSmtpConfig();
$hasLiveSmtp = Security::hasSmtpConfigured();

$totalNotificationsLogged = (int) $pdo->query("SELECT COUNT(*) FROM notification_logs")->fetchColumn();
$recentNotificationsLogged = (int) $pdo->query("SELECT COUNT(*) FROM notification_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

// 3b. M-Pesa Daraja Payment Telemetry
$mpesaCfg = mpesa_config();
$mpesaCallbacksCount = (int) $pdo->query("SELECT COUNT(*) FROM mpesa_callbacks")->fetchColumn();
$mpesaRecentPaid = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid' AND payment_method LIKE '%M-Pesa%'")->fetchColumn();

// 4. Security & Threat Landscape
$activeLockedUsersStmt = $pdo->query("
    SELECT id, full_name, email, role, failed_login_count, locked_until 
    FROM users 
    WHERE locked_until IS NOT NULL AND locked_until > NOW()
    ORDER BY locked_until DESC
");
$activeLockedUsers = $activeLockedUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$topFailedIpsStmt = $pdo->query("
    SELECT ip_address, COUNT(*) AS fail_count, MAX(attempted_at) AS last_attempt
    FROM login_attempts 
    WHERE success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY ip_address 
    ORDER BY fail_count DESC 
    LIMIT 5
");
$topFailedIps = $topFailedIpsStmt->fetchAll(PDO::FETCH_ASSOC);

// 5. PHP Runtime Health & Extensions
$requiredExtensions = [
    'pdo_mysql' => 'MySQL PDO Driver',
    'openssl'   => 'OpenSSL Cryptography & TLS',
    'curl'      => 'cURL HTTP & Gateway Client',
    'mbstring'  => 'Multibyte String Support',
    'json'      => 'JSON Serializer',
    'fileinfo'  => 'MIME File Inspection',
    'gd'        => 'Image Processing (GD)',
];

$extStatus = [];
foreach ($requiredExtensions as $ext => $label) {
    $extStatus[$ext] = [
        'label' => $label,
        'loaded' => extension_loaded($ext),
    ];
}

// Directory write permissions
$uploadPaths = [
    'assets/img/uploads/kyc' => __DIR__ . '/../../assets/img/uploads/kyc',
    'assets/img/uploads/products' => __DIR__ . '/../../assets/img/uploads/products',
];
$dirPermissions = [];
foreach ($uploadPaths as $rel => $abs) {
    if (!is_dir($abs)) {
        @mkdir($abs, 0777, true);
    }
    $dirPermissions[$rel] = is_writable($abs);
}

$pageTitle = 'SokoHub | System Health & Operations';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <!-- Header -->
    <div class="page-head">
        <div>
            <h1>System Health &amp; Infrastructure Operations</h1>
            <p class="muted">Live diagnostic telemetry covering MySQL performance, queue workers, SMTP delivery, and threat feeds.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/users.php')) ?>" class="btn btn-outline">
                <?= icon('users', 'lucide-xs') ?>
                <span>User Management</span>
            </a>
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-primary">
                <?= icon('arrow-left', 'lucide-xs', 'white') ?>
                <span>Back to Hub</span>
            </a>
        </div>
    </div>

    <!-- Quick Vital Cards -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <span class="stat-label">MySQL Database</span>
            <strong class="stat-value" style="color: #22c55e;">
                <?= $dbLatencyMs ?> ms
            </strong>
            <span class="badge badge-success"><?= e($dbName) ?> &bull; <?= e(substr($dbVersion, 0, 15)) ?></span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Database Storage</span>
            <strong class="stat-value"><?= number_format($totalDbMb, 2) ?> MB</strong>
            <span class="badge badge-primary"><?= $totalTables ?> Tables &bull; <?= number_format($totalRows) ?> Rows</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Background Job Queue</span>
            <strong class="stat-value" style="color: <?= $jobsCounts['pending'] > 0 ? '#f59e0b' : '#22c55e' ?>;">
                <?= $jobsCounts['pending'] ?> Pending
            </strong>
            <span class="badge <?= $jobsCounts['failed'] > 0 ? 'badge-danger' : 'badge-neutral' ?>">
                <?= $jobsCounts['completed'] ?> Done &bull; <?= $jobsCounts['failed'] ?> Failed
            </span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Outbound Mail Engine</span>
            <strong class="stat-value" style="color: <?= $hasLiveSmtp ? '#22c55e' : '#f59e0b' ?>;">
                <?= $hasLiveSmtp ? 'Live SMTP' : 'Log Fallback' ?>
            </strong>
            <span class="badge <?= $hasLiveSmtp ? 'badge-success' : 'badge-warning' ?>">
                <?= $recentNotificationsLogged ?> Sent (24h)
            </span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Active Lockouts</span>
            <strong class="stat-value" style="color: <?= count($activeLockedUsers) > 0 ? '#ef4444' : '#22c55e' ?>;">
                <?= count($activeLockedUsers) ?> Account(s)
            </strong>
            <span class="badge <?= count($activeLockedUsers) > 0 ? 'badge-danger' : 'badge-success' ?>">
                <?= count($activeLockedUsers) > 0 ? 'Throttled' : 'All Clear' ?>
            </span>
        </div>
    </div>

    <!-- Grid Section 1: Job Queue Runner & SMTP Diagnostic Ping -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Queue Worker Control Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                <div>
                    <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <?= icon('cpu', 'lucide-xs', 'emerald') ?>
                        <span>Asynchronous Job Queue</span>
                    </h3>
                    <p class="small muted" style="margin: 0;">Dispatches background verification emails, SMS alerts, and audit jobs.</p>
                </div>
                <form method="POST" action="<?= e(url('pages/dashboard/system_health.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="run_jobs">
                    <button type="submit" class="btn btn-primary" style="padding: 0.55rem 0.95rem; font-size: 0.85rem;">
                        <?= icon('play', 'lucide-xs') ?>
                        <span>Run Queue Now</span>
                    </button>
                </form>
            </div>

            <!-- Queue Status Summary -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; text-align: center; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem;">
                    <div class="small muted">Pending</div>
                    <strong style="font-size: 1.15rem; color: #f59e0b;"><?= $jobsCounts['pending'] ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem;">
                    <div class="small muted">Processing</div>
                    <strong style="font-size: 1.15rem; color: #3b82f6;"><?= $jobsCounts['processing'] ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem;">
                    <div class="small muted">Completed</div>
                    <strong style="font-size: 1.15rem; color: #22c55e;"><?= $jobsCounts['completed'] ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem;">
                    <div class="small muted">Failed</div>
                    <strong style="font-size: 1.15rem; color: #ef4444;"><?= $jobsCounts['failed'] ?></strong>
                </div>
            </div>

            <!-- Recent Jobs Snippet -->
            <div class="small" style="font-weight: 700; margin-bottom: 0.5rem;">Recent Background Jobs</div>
            <?php if (empty($recentJobs)): ?>
                <p class="small muted">No background jobs have been scheduled yet.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.45rem;">
                    <?php foreach ($recentJobs as $jb): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.82rem;">
                            <div>
                                <span style="font-family: monospace; font-weight: 700;">#<?= $jb['id'] ?></span>
                                <span style="margin: 0 0.25rem;">&bull;</span>
                                <strong><?= e($jb['job_type']) ?></strong>
                                <span class="small muted">(att: <?= $jb['attempts'] ?>/<?= $jb['max_attempts'] ?>)</span>
                            </div>
                            <div>
                                <?php if ($jb['status'] === 'completed'): ?>
                                    <span class="badge badge-success" style="font-size: 0.72rem;">Done</span>
                                <?php elseif ($jb['status'] === 'failed'): ?>
                                    <span class="badge badge-danger" style="font-size: 0.72rem;">Failed</span>
                                <?php elseif ($jb['status'] === 'pending'): ?>
                                    <span class="badge badge-warning" style="font-size: 0.72rem;">Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-info" style="font-size: 0.72rem;"><?= e($jb['status']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Outbound SMTP Gateway & Live Ping Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="margin-bottom: 1rem;">
                <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('mail', 'lucide-xs', 'blue') ?>
                    <span>Outbound Email Diagnostics</span>
                </h3>
                <p class="small muted" style="margin: 0;">Test live socket handshake, STARTTLS negotiation, and delivery to real inboxes.</p>
            </div>

            <!-- SMTP Config Review -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem; margin-bottom: 1.25rem; font-size: 0.84rem; line-height: 1.6;">
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Gateway Host:</span>
                    <strong><?= e($smtpConfig['host'] ?: 'None (Local Log Fallback)') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Port &amp; Security:</span>
                    <strong>Port <?= e((string) $smtpConfig['port']) ?> &bull; <?= strtoupper($smtpConfig['encryption']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">SMTP Account:</span>
                    <strong><?= e($smtpConfig['user'] ?: 'Not Configured') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Sender Identity:</span>
                    <strong><?= e($smtpConfig['from_name']) ?> &lt;<?= e($smtpConfig['from_email']) ?>&gt;</strong>
                </div>
            </div>

            <!-- Send Live Test Email Form -->
            <form method="POST" action="<?= e(url('pages/dashboard/system_health.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_smtp">

                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label for="test_email" class="small muted" style="font-weight: 600;">Destination Test Recipient</label>
                    <input type="email" id="test_email" name="test_email" class="form-control" value="<?= e($adminUser['email']) ?>" required placeholder="youremail@example.com">
                </div>

                <button type="submit" class="btn btn-outline btn-block" style="border-color: #3b82f6; color: #1d4ed8; padding: 0.65rem;">
                    <?= icon('send', 'lucide-xs') ?>
                    <span>Send Diagnostic Test Email</span>
                </button>
            </form>

            <div class="small muted" style="margin-top: 0.85rem; line-height: 1.4;">
                <?= icon('info', 'lucide-xs') ?>
                Note: In production or local XAMPP without live credentials, SokoHub writes outgoing emails safely to <code>logs/outgoing_emails.log</code>. Free Gmail App Password setup is documented in <code>config/db.local.php.example</code>.
            </div>
        </div>

        <!-- Safaricom Daraja M-Pesa Gateway Diagnostics Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="margin-bottom: 1rem;">
                <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('smartphone', 'lucide-xs', 'emerald') ?>
                    <span>Safaricom Daraja M-Pesa Gateway</span>
                </h3>
                <p class="small muted" style="margin: 0;">Validate OAuth tokens, STK Push endpoints, and live gateway ping latency.</p>
            </div>

            <!-- Daraja Config Review -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem; margin-bottom: 1.25rem; font-size: 0.84rem; line-height: 1.6;">
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Gateway Mode:</span>
                    <strong><span class="badge <?= $mpesaCfg['env'] === 'live' ? 'badge-success' : 'badge-amber' ?>" style="font-size: 0.72rem;"><?= strtoupper(e($mpesaCfg['env'])) ?></span></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Shortcode / Till:</span>
                    <strong><?= e($mpesaCfg['shortcode']) ?> &bull; Till: <?= e($mpesaCfg['till_number']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Platform Fee / Split:</span>
                    <strong><?= e((string) $mpesaCfg['platform_fee_percent']) ?>% Platform &bull; 98% Merchant</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Callback Telemetry:</span>
                    <strong><?= $mpesaCallbacksCount ?> Webhook(s) &bull; <?= $mpesaRecentPaid ?> Paid Orders</strong>
                </div>
            </div>

            <!-- Ping Daraja Gateway Form -->
            <form method="POST" action="<?= e(url('pages/dashboard/system_health.php')) ?>" style="margin-bottom: 0.85rem;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="ping_daraja">

                <button type="submit" class="btn btn-outline btn-block" style="border-color: #15803d; color: #15803d; padding: 0.65rem;">
                    <?= icon('activity', 'lucide-xs') ?>
                    <span>Test Safaricom Daraja Gateway</span>
                </button>
            </form>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.75rem;">
                <a href="<?= e(url('pages/dashboard/payments_admin.php')) ?>" class="btn btn-sm btn-outline" style="font-size: 0.8rem; width: 100%; text-align: center; border-color: #0284c7; color: #0284c7;">
                    <?= icon('credit-card', 'lucide-xs') ?>
                    <span>Open Payments &amp; Reconciliation Screen &rarr;</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Grid Section 2: Security Telemetry & Active Lockouts -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Active Lockouts Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                <div>
                    <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <?= icon('shield-alert', 'lucide-xs', 'rose') ?>
                        <span>Active Brute-Force Lockouts</span>
                    </h3>
                    <p class="small muted" style="margin: 0;">Accounts currently throttled after 5+ consecutive password failures.</p>
                </div>
                <?php if (!empty($activeLockedUsers)): ?>
                    <form method="POST" action="<?= e(url('pages/dashboard/system_health.php')) ?>" onsubmit="return confirm('Unlock all currently throttled accounts?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="unlock_all">
                        <button type="submit" class="btn btn-outline" style="padding: 0.45rem 0.85rem; font-size: 0.82rem; border-color: #ef4444; color: #ef4444;">
                            <?= icon('unlock', 'lucide-xs') ?> Unlock All
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($activeLockedUsers)): ?>
                <div style="text-align: center; padding: 2rem 1rem;" class="muted">
                    <?= icon('shield-check', 'lucide-lg', 'emerald') ?>
                    <p style="margin-top: 0.5rem; font-weight: 600; color: #22c55e;">No active account lockouts.</p>
                    <span class="small muted">Brute-force throttle engine is actively monitoring login endpoints.</span>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($activeLockedUsers as $lu): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 6px; padding: 0.65rem 0.85rem;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.9rem;"><?= e($lu['full_name']) ?></div>
                                <div class="small muted"><?= e($lu['email']) ?> &bull; <?= $lu['failed_login_count'] ?> failures</div>
                                <div class="small" style="color: #ef4444; font-size: 0.78rem;">
                                    Locked until: <?= date('H:i:s', strtotime($lu['locked_until'])) ?>
                                </div>
                            </div>
                            <form method="POST" action="<?= e(url('pages/dashboard/users.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="unlock">
                                <input type="hidden" name="user_id" value="<?= $lu['id'] ?>">
                                <button type="submit" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; border-color: #ef4444; color: #ef4444;">
                                    <?= icon('unlock', 'lucide-xs') ?> Unlock
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Threat Monitor & Top Failed IPs -->
        <div class="card" style="padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                <div>
                    <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <?= icon('radio', 'lucide-xs', 'amber') ?>
                        <span>Recent Failed Login Origins</span>
                    </h3>
                    <p class="small muted" style="margin: 0;">Top IP addresses with failed authentications in the last 24 hours.</p>
                </div>
                <form method="POST" action="<?= e(url('pages/dashboard/system_health.php')) ?>" onsubmit="return confirm('Purge authentication logs older than 30 days?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="purge_attempts">
                    <button type="submit" class="btn btn-outline" style="padding: 0.45rem 0.85rem; font-size: 0.82rem;" title="Purge attempt logs older than 30 days">
                        <?= icon('trash', 'lucide-xs') ?> Clean Old Logs
                    </button>
                </form>
            </div>

            <?php if (empty($topFailedIps)): ?>
                <div style="text-align: center; padding: 2rem 1rem;" class="muted">
                    <?= icon('check-circle', 'lucide-lg', 'emerald') ?>
                    <p style="margin-top: 0.5rem;">Zero failed login spikes in the last 24 hours.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" style="font-size: 0.86rem;">
                        <thead>
                            <tr>
                                <th>Client IP Address</th>
                                <th>Failures (24h)</th>
                                <th>Most Recent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topFailedIps as $ip): ?>
                                <tr>
                                    <td>
                                        <code style="font-weight: 700;"><?= e($ip['ip_address']) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge <?= $ip['fail_count'] >= 10 ? 'badge-danger' : 'badge-warning' ?>">
                                            <?= $ip['fail_count'] ?> attempts
                                        </span>
                                    </td>
                                    <td class="small muted">
                                        <?= date('M j, H:i', strtotime($ip['last_attempt'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Grid Section 3: PHP Environment & Database Table Inspector -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- PHP Runtime Environment Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="margin-bottom: 1rem;">
                <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('server', 'lucide-xs', 'emerald') ?>
                    <span>Server &amp; PHP Runtime Health</span>
                </h3>
                <p class="small muted" style="margin: 0;">Runtime extensions, limits, and server storage write access.</p>
            </div>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.65rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem;">
                    <span class="muted">PHP Version:</span><br>
                    <strong><?= PHP_VERSION ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem;">
                    <span class="muted">Memory Limit:</span><br>
                    <strong><?= ini_get('memory_limit') ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem;">
                    <span class="muted">Upload Max Size:</span><br>
                    <strong><?= ini_get('upload_max_filesize') ?></strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem;">
                    <span class="muted">Max Exec Time:</span><br>
                    <strong><?= ini_get('max_execution_time') ?>s</strong>
                </div>
            </div>

            <div class="small" style="font-weight: 700; margin-bottom: 0.5rem;">Required Extensions</div>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.4rem; font-size: 0.82rem; margin-bottom: 1.25rem;">
                <?php foreach ($extStatus as $ext => $info): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 0.4rem 0.6rem;">
                        <span><?= e($ext) ?></span>
                        <?php if ($info['loaded']): ?>
                            <span style="color: #22c55e; font-weight: 700;">&#10003; Active</span>
                        <?php else: ?>
                            <span style="color: #ef4444; font-weight: 700;">&#10007; Missing</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="small" style="font-weight: 700; margin-bottom: 0.5rem;">Storage Upload Folders</div>
            <div style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.82rem;">
                <?php foreach ($dirPermissions as $dir => $ok): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 0.4rem 0.6rem;">
                        <code><?= e($dir) ?></code>
                        <?php if ($ok): ?>
                            <span class="badge badge-success" style="font-size: 0.72rem;">Writable</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="font-size: 0.72rem;">Read-Only</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Database Tables & Storage Inspector Card -->
        <div class="card" style="padding: 1.5rem;">
            <div style="margin-bottom: 1rem;">
                <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('database', 'lucide-xs', 'amber') ?>
                    <span>Database Table Metrics (<?= $totalTables ?> Tables)</span>
                </h3>
                <p class="small muted" style="margin: 0;">MySQL physical data size, index size, and record counts.</p>
            </div>

            <div class="table-wrap" style="max-height: 380px; overflow-y: auto;">
                <table class="data-table" style="font-size: 0.82rem;">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Rows</th>
                            <th>Data (KB)</th>
                            <th>Index (KB)</th>
                            <th>Engine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableStats as $ts): ?>
                            <tr>
                                <td>
                                    <strong><?= e($ts['table_name']) ?></strong>
                                </td>
                                <td><?= number_format((int) $ts['table_rows']) ?></td>
                                <td><?= number_format((float) $ts['data_kb'], 1) ?></td>
                                <td><?= number_format((float) $ts['index_kb'], 1) ?></td>
                                <td><span class="badge badge-neutral" style="font-size: 0.7rem;"><?= e($ts['engine']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
