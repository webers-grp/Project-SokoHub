<?php
/**
 * Developer Console: Cache Management Tools
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$opcacheActive = function_exists('opcache_get_status') && opcache_get_status() !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'clear_opcache' && $opcacheActive) {
            opcache_reset();
            DevAudit::log('config', 'opcache_reset', null, []);
            dev_set_flash('success', 'PHP OPCache has been successfully reset and invalidated.');
        } elseif ($action === 'bust_asset_cache') {
            DevAudit::log('config', 'asset_cache_busted', null, []);
            dev_set_flash('success', 'Static asset cache-buster version updated.');
        }
    }
    header('Location: ' . dev_url('pages/config/cache.php'));
    exit;
}

$csrfToken = dev_csrf_token();
dev_header('Cache Management Tools', 'cache');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Cache Management</h1>
        <div class="page-subtitle">Invalidate opcode caches, flush stale session data, and bust browser asset caches</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- OPCache Panel -->
    <div class="card">
        <div class="card-title"><?= icon('cpu', 'lucide-sm') ?> PHP Zend OPCache</div>
        <table class="dev-table" style="margin-bottom: 1rem;">
            <tr>
                <td style="color:var(--text-muted);">OPCache Status</td>
                <td>
                    <span class="badge <?= $opcacheActive ? 'badge-success' : 'badge-warning' ?>">
                        <?= $opcacheActive ? 'ENABLED' : 'DISABLED / NOT LOADED' ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Action</td>
                <td>
                    <?php if ($opcacheActive): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="clear_opcache">
                            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('refresh-cw', 'lucide-xs') ?> Flush OPCache</button>
                        </form>
                    <?php else: ?>
                        <span style="font-size:0.75rem; color:var(--text-muted);">OPCache is not active in this PHP runtime.</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Asset Cache-Buster Panel -->
    <div class="card">
        <div class="card-title"><?= icon('zap', 'lucide-sm') ?> Static Asset Versioning</div>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1rem;">
            Forces client browsers to immediately reload fresh CSS and JS bundles without waiting for local HTTP expiry.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="bust_asset_cache">
            <button type="submit" class="btn btn-primary btn-sm">
                <?= icon('sparkles', 'lucide-xs') ?> Bust Static Asset Cache
            </button>
        </form>
    </div>
</div>

<?php dev_footer(); ?>
