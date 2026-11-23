<?php
/**
 * Developer Console: Environment Inspector & Production Readiness Checklist
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$envKeys = [
    'APP_ENV'                  => 'Application Environment (production / development)',
    'MPESA_ENV'                => 'M-Pesa Gateway Mode (sandbox / production)',
    'MPESA_CONSUMER_KEY'       => 'Daraja OAuth Consumer Key',
    'MPESA_CONSUMER_SECRET'    => 'Daraja OAuth Consumer Secret',
    'MPESA_PASSKEY'            => 'Daraja STK Push Passkey',
    'MPESA_SHORTCODE'          => 'Daraja Business Shortcode (e.g. 174379)',
    'MPESA_TILL_NUMBER'        => 'Platform Receiving Till (e.g. 1648753)',
    'MPESA_CALLBACK_URL'       => 'Public HTTPS Callback URL for Daraja Webhooks',
    'MPESA_B2C_INITIATOR_NAME' => 'M-Pesa B2C Initiator Account Name',
    'PLATFORM_FEE_PERCENT'     => 'Marketplace Escrow Platform Fee % (e.g. 2)',
    'DEVCONSOLE_ENABLED'       => 'Developer Console Master Enable Switch',
    'DEVCONSOLE_ALLOWED_IPS'   => 'Restricted IP Allowlist for Developer Console',
];

$envAudit = [];
foreach ($envKeys as $key => $desc) {
    $val = getenv($key);
    $isSet = ($val !== false && $val !== '');
    $envAudit[] = [
        'key'         => $key,
        'description' => $desc,
        'is_set'      => $isSet,
        'masked_val'  => $isSet ? (strlen($val) > 6 ? substr($val, 0, 3) . '••••••••' . substr($val, -2) : '••••••') : 'NOT SET',
    ];
}

// Writable folders check
$folders = [
    'assets/img/uploads'     => APP_ROOT . '/assets/img/uploads',
    'assets/img/uploads/kyc' => APP_ROOT . '/assets/img/uploads/kyc',
    'backups'                => APP_ROOT . '/backups',
    'logs'                   => APP_ROOT . '/logs',
];

$folderChecks = [];
foreach ($folders as $name => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $folderChecks[] = [
        'name'     => $name,
        'path'     => $path,
        'exists'   => $exists,
        'writable' => $writable,
    ];
}

// Production Readiness Checks
$readiness = [];
$isProd = (defined('APP_ENV') && APP_ENV === 'production');
$isMpesaProd = (strtoupper(getenv('MPESA_ENV') ?: '') === 'PRODUCTION');

$readiness[] = [
    'item'   => 'Environment Isolation',
    'passed' => $isProd ? ($isMpesaProd) : true,
    'detail' => $isProd ? ($isMpesaProd ? 'Production app with production M-Pesa keys.' : 'MISMATCH: Production app is using sandbox M-Pesa.') : 'Development environment active.',
];

$readiness[] = [
    'item'   => 'Display Errors Disabled in Production',
    'passed' => $isProd ? (ini_get('display_errors') === '0' || ini_get('display_errors') === '') : true,
    'detail' => 'display_errors=' . ini_get('display_errors'),
];

$readiness[] = [
    'item'   => 'HTTPS / SSL Configuration',
    'passed' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || PHP_SAPI === 'cli',
    'detail' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Serving over secure TLS/HTTPS' : 'Non-HTTPS connection detected (Required in live production)',
];

$readiness[] = [
    'item'   => 'KYC Direct Storage Lock (.htaccess)',
    'passed' => file_exists(APP_ROOT . '/assets/img/uploads/kyc/.htaccess'),
    'detail' => 'Direct HTTP access to citizen KYC documents blocked at web server layer',
];

$readiness[] = [
    'item'   => 'Uploads PHP Execution Lock (.htaccess)',
    'passed' => file_exists(APP_ROOT . '/assets/img/uploads/.htaccess'),
    'detail' => 'PHP script execution blocked in uploads directories',
];

dev_header('Environment &amp; Pre-Launch Readiness', 'env');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Environment &amp; Pre-Launch Inspector</h1>
        <div class="page-subtitle">Ground-truth validation of environment variables, directory permissions, and go-live security criteria</div>
    </div>
</div>

<!-- Production Readiness Checklist -->
<div class="card">
    <div class="card-title"><?= icon('shield-check', 'lucide-sm') ?> Go-Live Production Readiness Checklist</div>
    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>Verification Criteria</th>
                    <th>Status</th>
                    <th>Audit Findings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($readiness as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['item']) ?></strong></td>
                        <td>
                            <span class="badge <?= $r['passed'] ? 'badge-success' : 'badge-danger' ?>">
                                <?= $r['passed'] ? 'READY / PASS' : 'ACTION REQUIRED' ?>
                            </span>
                        </td>
                        <td style="color:var(--text-secondary);"><?= htmlspecialchars($r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- Env Vars Table -->
    <div class="card">
        <div class="card-title"><?= icon('sliders', 'lucide-sm') ?> Environment Variables (.env)</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>State</th>
                        <th>Masked Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($envAudit as $ea): ?>
                        <tr>
                            <td>
                                <code><?= htmlspecialchars($ea['key']) ?></code>
                                <div style="font-size:0.7rem; color:var(--text-muted);"><?= htmlspecialchars($ea['description']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $ea['is_set'] ? 'badge-success' : 'badge-warning' ?>" style="font-size:0.65rem;">
                                    <?= $ea['is_set'] ? 'SET' : 'MISSING' ?>
                                </span>
                            </td>
                            <td><code style="font-size:0.75rem;"><?= htmlspecialchars($ea['masked_val']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Writable Folders Check -->
    <div class="card">
        <div class="card-title"><?= icon('folder', 'lucide-sm') ?> Writable Directories &amp; Storage Paths</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Directory</th>
                        <th>Status</th>
                        <th>Permissions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($folderChecks as $fc): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($fc['name']) ?></code></td>
                            <td>
                                <span class="badge <?= $fc['writable'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.65rem;">
                                    <?= $fc['writable'] ? 'WRITABLE' : 'UNWRITABLE' ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted); font-size:0.75rem;">
                                <?= $fc['exists'] ? substr(sprintf('%o', fileperms($fc['path'])), -4) : 'Missing' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-title" style="margin-top:1.5rem;"><?= icon('cpu', 'lucide-sm') ?> PHP Core Limits</div>
        <table class="dev-table">
            <tr><td style="color:var(--text-muted);">memory_limit</td><td><code><?= ini_get('memory_limit') ?></code></td></tr>
            <tr><td style="color:var(--text-muted);">upload_max_filesize</td><td><code><?= ini_get('upload_max_filesize') ?></code></td></tr>
            <tr><td style="color:var(--text-muted);">post_max_size</td><td><code><?= ini_get('post_max_size') ?></code></td></tr>
            <tr><td style="color:var(--text-muted);">max_execution_time</td><td><code><?= ini_get('max_execution_time') ?>s</code></td></tr>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
