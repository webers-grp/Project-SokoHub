<?php
/**
 * Developer Console Master Layout & Navigation
 */

require_once __DIR__ . '/auth.php';

function dev_header(string $title = 'Developer Console', string $activeNav = '', array $breadcrumbs = []): void
{
    $dev = dev_current_user();
    $flash = dev_get_flash();
    $mpesaEnv = strtoupper(getenv('MPESA_ENV') ?: 'SANDBOX');
    $isLive = ($mpesaEnv === 'PRODUCTION');
    $appEnv = strtoupper(defined('APP_ENV') ? APP_ENV : 'DEVELOPMENT');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($title) ?> | SokoHub Ops Console</title>
    <link rel="stylesheet" href="<?= dev_url('assets/css/console.css?v=' . time()) ?>">
    <script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
    <script src="<?= dev_url('assets/js/console.js?v=' . time()) ?>" defer></script>
</head>
<body>
<div class="dev-app">
    <!-- Sidebar Navigation -->
    <aside class="dev-sidebar">
        <div class="dev-sidebar-header">
            <div style="color: #3b82f6; display: flex;"><?= icon('terminal', 'lucide-md') ?></div>
            <div>
                <div class="dev-brand-title">SokoHub Ops</div>
                <span class="dev-brand-badge">Dev Console</span>
            </div>
        </div>

        <nav class="dev-sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="<?= dev_url('dashboard.php') ?>" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                <?= icon('activity', 'lucide-sm') ?> <span>System Health</span>
            </a>
            <a href="<?= dev_url('pages/api/audit_log.php') ?>" class="nav-item <?= $activeNav === 'audit_log' ? 'active' : '' ?>">
                <?= icon('shield-check', 'lucide-sm') ?> <span>Console Audit Log</span>
            </a>

            <div class="nav-section-title">Payments Lab</div>
            <a href="<?= dev_url('pages/payments/simulator.php') ?>" class="nav-item <?= $activeNav === 'simulator' ? 'active' : '' ?>">
                <?= icon('play-circle', 'lucide-sm') ?> <span>STK Push Simulator</span>
            </a>
            <a href="<?= dev_url('pages/payments/inspector.php') ?>" class="nav-item <?= $activeNav === 'inspector' ? 'active' : '' ?>">
                <?= icon('search', 'lucide-sm') ?> <span>Payment Inspector</span>
            </a>
            <a href="<?= dev_url('pages/payments/reconciliation.php') ?>" class="nav-item <?= $activeNav === 'reconciliation' ? 'active' : '' ?>">
                <?= icon('scale', 'lucide-sm') ?> <span>Reconciliation Tools</span>
            </a>
            <a href="<?= dev_url('pages/payments/payouts.php') ?>" class="nav-item <?= $activeNav === 'payouts' ? 'active' : '' ?>">
                <?= icon('wallet', 'lucide-sm') ?> <span>Payouts Inspector</span>
            </a>
            <a href="<?= dev_url('pages/payments/tester.php') ?>" class="nav-item <?= $activeNav === 'daraja_tester' ? 'active' : '' ?>">
                <?= icon('wifi', 'lucide-sm') ?> <span>Daraja Connection</span>
            </a>

            <div class="nav-section-title">Database Tools</div>
            <a href="<?= dev_url('pages/database/schema.php') ?>" class="nav-item <?= $activeNav === 'schema' ? 'active' : '' ?>">
                <?= icon('table', 'lucide-sm') ?> <span>Schema Browser</span>
            </a>
            <a href="<?= dev_url('pages/database/migrations.php') ?>" class="nav-item <?= $activeNav === 'migrations' ? 'active' : '' ?>">
                <?= icon('git-merge', 'lucide-sm') ?> <span>Migrations Manager</span>
            </a>
            <a href="<?= dev_url('pages/database/browser.php') ?>" class="nav-item <?= $activeNav === 'browser' ? 'active' : '' ?>">
                <?= icon('database', 'lucide-sm') ?> <span>Read-Only Browser</span>
            </a>
            <a href="<?= dev_url('pages/database/query.php') ?>" class="nav-item <?= $activeNav === 'query' ? 'active' : '' ?>">
                <?= icon('code', 'lucide-sm') ?> <span>Query Runner</span>
            </a>
            <a href="<?= dev_url('pages/database/backup.php') ?>" class="nav-item <?= $activeNav === 'backup' ? 'active' : '' ?>">
                <?= icon('archive', 'lucide-sm') ?> <span>Backup &amp; Restore</span>
            </a>
            <a href="<?= dev_url('pages/database/seed.php') ?>" class="nav-item <?= $activeNav === 'seed' ? 'active' : '' ?>">
                <?= icon('flask-conical', 'lucide-sm') ?> <span>Seed &amp; Test Data</span>
            </a>

            <div class="nav-section-title">Logs &amp; Monitoring</div>
            <a href="<?= dev_url('pages/logs/viewer.php') ?>" class="nav-item <?= $activeNav === 'logs' ? 'active' : '' ?>">
                <?= icon('file-text', 'lucide-sm') ?> <span>System &amp; Event Logs</span>
            </a>
            <a href="<?= dev_url('pages/logs/security.php') ?>" class="nav-item <?= $activeNav === 'security_events' ? 'active' : '' ?>">
                <?= icon('alert-triangle', 'lucide-sm') ?> <span>Security Events</span>
            </a>
            <a href="<?= dev_url('pages/logs/cron.php') ?>" class="nav-item <?= $activeNav === 'cron' ? 'active' : '' ?>">
                <?= icon('clock', 'lucide-sm') ?> <span>Scheduled Jobs / Cron</span>
            </a>

            <div class="nav-section-title">Config &amp; Flags</div>
            <a href="<?= dev_url('pages/config/env.php') ?>" class="nav-item <?= $activeNav === 'env' ? 'active' : '' ?>">
                <?= icon('sliders', 'lucide-sm') ?> <span>Environment &amp; Pre-Launch</span>
            </a>
            <a href="<?= dev_url('pages/config/flags.php') ?>" class="nav-item <?= $activeNav === 'flags' ? 'active' : '' ?>">
                <?= icon('flag', 'lucide-sm') ?> <span>Feature Flags &amp; Maint.</span>
            </a>
            <a href="<?= dev_url('pages/config/cache.php') ?>" class="nav-item <?= $activeNav === 'cache' ? 'active' : '' ?>">
                <?= icon('trash-2', 'lucide-sm') ?> <span>Cache Management</span>
            </a>
            <a href="<?= dev_url('pages/config/notifications.php') ?>" class="nav-item <?= $activeNav === 'notifications_tester' ? 'active' : '' ?>">
                <?= icon('bell', 'lucide-sm') ?> <span>Notification Tester</span>
            </a>

            <div class="nav-section-title">API &amp; Webhooks</div>
            <a href="<?= dev_url('pages/api/webhooks.php') ?>" class="nav-item <?= $activeNav === 'webhooks' ? 'active' : '' ?>">
                <?= icon('webhook', 'lucide-sm') ?> <span>Webhook History &amp; Replay</span>
            </a>
            <a href="<?= dev_url('pages/api/tester.php') ?>" class="nav-item <?= $activeNav === 'api_tester' ? 'active' : '' ?>">
                <?= icon('send', 'lucide-sm') ?> <span>API Request Tester</span>
            </a>
        </nav>
    </aside>

    <!-- Main Viewport -->
    <div class="dev-main">
        <!-- Topbar -->
        <header class="dev-topbar">
            <div class="dev-topbar-left">
                <!-- Environment Badges -->
                <span class="badge <?= $isLive ? 'badge-production' : 'badge-sandbox' ?>">
                    &bull; <?= $isLive ? 'MPESA PRODUCTION' : 'MPESA SANDBOX' ?>
                </span>
                <span class="badge badge-dev">
                    ENV: <?= htmlspecialchars($appEnv) ?>
                </span>
            </div>

            <div class="dev-topbar-right">
                <!-- User Profile & Role -->
                <?php if ($dev): ?>
                    <span class="badge badge-role-<?= htmlspecialchars($dev['role']) ?>">
                        <?= strtoupper(htmlspecialchars($dev['role'])) ?>
                    </span>
                    <span style="font-size: 0.85rem; font-weight: 600; color: #fff;">
                        <?= htmlspecialchars($dev['username']) ?>
                    </span>
                    <a href="<?= dev_url('logout.php') ?>" class="btn btn-secondary btn-sm" title="Sign Out">
                        <?= icon('log-out', 'lucide-xs') ?> Sign Out
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Flash Notice -->
        <div class="dev-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-auto-dismiss">
                    <?= icon('info', 'lucide-sm') ?>
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>
<?php
}

function dev_footer(): void
{
?>
        </div><!-- /.dev-content -->
    </div><!-- /.dev-main -->
</div><!-- /.dev-app -->
</body>
</html>
<?php
}
