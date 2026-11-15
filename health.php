<?php
/**
 * Week-1 Deployment Diagnostic ("Hello World + DB Connection" Check)
 * Verifies PHP version, session/cookie support, PDO MySQL connectivity, and schema tables.
 */
require_once __DIR__ . '/includes/auth.php';
init_session();

$dbStatus = test_db_connection();
$pageTitle = 'SokoHub | System Health Check';
require_once __DIR__ . '/includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>System Environment &amp; Database Health Check</h1>
            <p class="muted">Early-deployment diagnostic verifying PHP runtime, sessions, cookies, and MySQL PDO connectivity.</p>
        </div>
        <a href="<?= e(url('index.php')) ?>" class="btn btn-primary">Back to Marketplace</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">PHP Runtime</span>
            <strong class="stat-value">PHP <?= e(PHP_VERSION) ?></strong>
            <span class="badge badge-success">Active</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Session Engine</span>
            <strong class="stat-value"><?= session_status() === PHP_SESSION_ACTIVE ? 'Running' : 'Inactive' ?></strong>
            <span class="badge badge-success">session_start() OK</span>
        </div>

        <div class="stat-card">
            <span class="stat-label">MySQL / MariaDB (PDO)</span>
            <strong class="stat-value"><?= $dbStatus['ok'] ? 'Connected' : 'Error' ?></strong>
            <span class="badge <?= $dbStatus['ok'] ? 'badge-success' : 'badge-danger' ?>">
                <?= $dbStatus['ok'] ? e($dbStatus['version']) : 'Check config/db.local.php' ?>
            </span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Database Tables</span>
            <strong class="stat-value"><?= count($dbStatus['tables']) ?> / 9</strong>
            <span class="badge <?= count($dbStatus['tables']) >= 9 ? 'badge-success' : 'badge-warning' ?>">
                <?= count($dbStatus['tables']) >= 9 ? 'Schema Ready' : 'Import schema.sql' ?>
            </span>
        </div>
    </div>

    <div class="card">
        <h2>Detected Database Tables</h2>
        <?php if ($dbStatus['ok'] && !empty($dbStatus['tables'])): ?>
            <div class="pill-list">
                <?php foreach ($dbStatus['tables'] as $tbl): ?>
                    <span class="badge badge-info"><?= e($tbl) ?></span>
                <?php endforeach; ?>
            </div>
        <?php elseif ($dbStatus['ok']): ?>
            <p class="muted">Connected to database, but no tables exist yet. Import <code>database/schema.sql</code> and <code>database/seed.sql</code> in phpMyAdmin.</p>
        <?php else: ?>
            <div class="alert alert-error">
                <span><?= e($dbStatus['error']) ?></span>
            </div>
            <p class="muted">On InfinityFree or 000webhost, copy <code>config/db.local.php.example</code> to <code>config/db.local.php</code> and update the host, database name, username, and password from your control panel.</p>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
