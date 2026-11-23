<?php
/**
 * Developer Console: Feature Flags & Maintenance Mode
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dev = dev_current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dev_require_role('owner');
    dev_require_reauth(dev_url('pages/config/flags.php'));

    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
    } else {
        $maintMode = !empty($_POST['maintenance_mode']) ? '1' : '0';
        $maintMsg  = trim((string) ($_POST['maintenance_message'] ?? ''));
        $maintIps  = trim((string) ($_POST['maintenance_allowed_ips'] ?? ''));
        $regFlag   = !empty($_POST['allow_user_registration']) ? '1' : '0';
        $codFlag   = !empty($_POST['allow_cod_checkout']) ? '1' : '0';
        $mpesaFlag = !empty($_POST['allow_mpesa_checkout']) ? '1' : '0';

        $upd = $pdo->prepare('UPDATE system_settings SET setting_value = :v, updated_by = :by, updated_at = NOW() WHERE setting_key = :k');

        $upd->execute([':v' => $maintMode, ':by' => $dev['username'], ':k' => 'maintenance_mode']);
        $upd->execute([':v' => $maintMsg,  ':by' => $dev['username'], ':k' => 'maintenance_message']);
        $upd->execute([':v' => $maintIps,  ':by' => $dev['username'], ':k' => 'maintenance_allowed_ips']);
        $upd->execute([':v' => $regFlag,   ':by' => $dev['username'], ':k' => 'allow_user_registration']);
        $upd->execute([':v' => $codFlag,   ':by' => $dev['username'], ':k' => 'allow_cod_checkout']);
        $upd->execute([':v' => $mpesaFlag, ':by' => $dev['username'], ':k' => 'allow_mpesa_checkout']);

        DevAudit::log('config', 'feature_flags_updated', null, [
            'maintenance_mode' => $maintMode,
            'allow_registration' => $regFlag,
        ]);

        dev_set_flash('success', 'System settings and feature flags updated successfully.');
    }
    header('Location: ' . dev_url('pages/config/flags.php'));
    exit;
}

// Fetch all settings
$settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$csrfToken = dev_csrf_token();
dev_header('Feature Flags &amp; Maintenance Mode', 'flags');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Feature Flags &amp; Maintenance Control</h1>
        <div class="page-subtitle">Toggle core e-commerce capabilities dynamically and control public marketplace availability</div>
    </div>
</div>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
        <!-- Maintenance Mode Panel -->
        <div class="card" style="border-color: <?= ($settings['maintenance_mode'] ?? '0') === '1' ? '#dc2626' : '#334155' ?>;">
            <div class="card-title" style="color: <?= ($settings['maintenance_mode'] ?? '0') === '1' ? '#f87171' : '#fff' ?>;">
                <?= icon('power', 'lucide-sm') ?> Marketplace Maintenance Mode
            </div>
            <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1rem;">
                When active, all customer-facing storefront pages return a clean maintenance notice. The developer console and Daraja callback webhooks remain accessible.
            </p>

            <div class="form-group" style="display:flex; align-items:center; gap:0.5rem;">
                <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?> style="transform: scale(1.3);">
                <label for="maintenance_mode" style="font-weight:700; color:#fff; font-size:0.9rem;">Enable Public Maintenance Mode</label>
            </div>

            <div class="form-group">
                <label class="form-label" for="maintenance_message">Public Maintenance Message</label>
                <textarea id="maintenance_message" name="maintenance_message" class="form-textarea" rows="2"><?= htmlspecialchars($settings['maintenance_message'] ?? 'SokoHub is undergoing scheduled maintenance. Please check back shortly.') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="maintenance_allowed_ips">Bypass IP Allowlist (Comma-separated)</label>
                <input type="text" id="maintenance_allowed_ips" name="maintenance_allowed_ips" class="form-input" value="<?= htmlspecialchars($settings['maintenance_allowed_ips'] ?? '') ?>" placeholder="e.g. 197.237.1.10, 127.0.0.1">
            </div>
        </div>

        <!-- Operational Feature Flags -->
        <div class="card">
            <div class="card-title"><?= icon('flag', 'lucide-sm') ?> Marketplace Feature Switches</div>

            <div class="form-group" style="padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer;">
                    <input type="checkbox" name="allow_user_registration" value="1" <?= ($settings['allow_user_registration'] ?? '1') === '1' ? 'checked' : '' ?> style="transform: scale(1.2);">
                    <div>
                        <div style="font-weight:600; color:#fff;">Allow New User Registration</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Controls buyer and vendor sign up availability.</div>
                    </div>
                </label>
            </div>

            <div class="form-group" style="padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer;">
                    <input type="checkbox" name="allow_mpesa_checkout" value="1" <?= ($settings['allow_mpesa_checkout'] ?? '1') === '1' ? 'checked' : '' ?> style="transform: scale(1.2);">
                    <div>
                        <div style="font-weight:600; color:#fff;">Enable M-Pesa STK Push at Checkout</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Controls Safaricom Daraja STK Push availability.</div>
                    </div>
                </label>
            </div>

            <div class="form-group" style="padding: 0.75rem 0;">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer;">
                    <input type="checkbox" name="allow_cod_checkout" value="1" <?= ($settings['allow_cod_checkout'] ?? '1') === '1' ? 'checked' : '' ?> style="transform: scale(1.2);">
                    <div>
                        <div style="font-weight:600; color:#fff;">Enable Cash on Delivery (COD)</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Controls COD order option at checkout.</div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <?php if ($dev['role'] === 'owner'): ?>
        <button type="submit" class="btn btn-primary" style="margin-top:0.5rem; padding: 0.75rem 1.5rem;">
            <?= icon('save', 'lucide-xs') ?> Save Feature Settings
        </button>
    <?php else: ?>
        <p style="font-size:0.8rem; color:var(--text-muted); margin-top:0.5rem;">Viewing settings in read-only mode (Owner role required to modify).</p>
    <?php endif; ?>
</form>

<?php dev_footer(); ?>
