<?php
/**
 * SokoHub Seller "Receive Payments" Settings Page
 *
 * Allows sellers to configure and update their payout destination:
 * - Till Number (Buy Goods)
 * - Paybill (with account number)
 * - Pochi la Biashara or personal M-Pesa phone number
 *
 * Security: Requires re-authentication (current password verification) to prevent hijacking.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_role(['seller', 'admin']);
$pdo = get_db_connection();
$userId = (int) $user['id'];

// Fetch seller profile
$stmt = $pdo->prepare('SELECT sp.*, u.phone, u.password_hash FROM seller_profiles sp INNER JOIN users u ON u.id = sp.user_id WHERE sp.user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $userId]);
$seller = $stmt->fetch();

if (!$seller) {
    set_flash('error', 'Seller profile not found.');
    redirect('pages/dashboard/dashboard.php');
}

$errors = [];
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $payoutType = (string) ($_POST['payout_type'] ?? 'till');
        $payoutAccount = trim((string) ($_POST['payout_account'] ?? ''));
        $payoutRef = trim((string) ($_POST['payout_account_reference'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');

        // 1. Re-authentication check to prevent hijacking
        if ($currentPassword === '' || !password_verify($currentPassword, $seller['password_hash'])) {
            $errors[] = 'Re-authentication failed: You must enter your correct account password to change payout settings.';
        }

        // 2. Validate payout destination
        $validation = validate_payout_destination($payoutType, $payoutAccount, $payoutRef);
        if (!$validation['ok']) {
            $errors[] = $validation['error'];
        }

        if (empty($errors)) {
            $normalizedAccount = ($payoutType === 'phone' || $payoutType === 'pochi')
                ? mpesa_normalize_phone($payoutAccount)
                : $payoutAccount;

            $finalRef = ($payoutType === 'paybill') ? $payoutRef : null;

            $upd = $pdo->prepare(
                'UPDATE seller_profiles 
                 SET payout_type = :ptype, payout_account = :pacct, payout_account_reference = :pref 
                 WHERE user_id = :uid'
            );
            $upd->execute([
                ':ptype' => $payoutType,
                ':pacct' => $normalizedAccount,
                ':pref'  => $finalRef,
                ':uid'   => $userId,
            ]);

            set_flash('success', 'Payout destination updated successfully! Future sales will be disbursed to ' . $normalizedAccount . '.');
            redirect('pages/dashboard/seller_payout_settings.php');
        }
    }
}

$currentType = $seller['payout_type'] ?: 'till';
$currentAccount = $seller['payout_account'] ?: '';
$currentRef = $seller['payout_account_reference'] ?: '';
$codDue = (float) ($seller['cod_balance_due'] ?? 0.0);

$pageTitle = 'SokoHub | Receive Payments Settings';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Receive Payments Settings</h1>
            <p class="muted">Manage your M-Pesa payout channel for sales revenues and settlements.</p>
        </div>
        <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-outline">
            <?= icon('package', 'lucide-xs', 'emerald') ?>
            <span>Manage Products</span>
        </a>
    </div>

    <div class="split-grid" style="align-items: start;">
        <!-- Left Column: Settings Form -->
        <div class="card">
            <h2 style="color: var(--green-dark); margin-bottom: 0.5rem;">
                <?= icon('wallet', 'lucide-sm', 'emerald') ?>
                <span>Configure Payout Channel</span>
            </h2>
            <p class="small muted" style="margin-bottom: 1.25rem;">
                Customers pay to platform Till 1648753. Your net 98% share is automatically settled to your chosen account upon delivery.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/dashboard/seller_payout_settings.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="payout_type">Payout Channel</label>
                    <select id="payout_type" name="payout_type" class="form-control" required onchange="togglePayoutFields()">
                        <option value="till" <?= $currentType === 'till' ? 'selected' : '' ?>>Till Number (Buy Goods)</option>
                        <option value="paybill" <?= $currentType === 'paybill' ? 'selected' : '' ?>>Paybill (with Account Number)</option>
                        <option value="phone" <?= $currentType === 'phone' ? 'selected' : '' ?>>Pochi la Biashara / Personal M-Pesa</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="payout_account" id="payout_account_label">Till Number</label>
                    <input type="text" id="payout_account" name="payout_account" class="form-control" value="<?= e($currentAccount) ?>" required placeholder="e.g. 1648753">
                    <small class="muted" id="payout_help">Enter your 5-8 digit Buy Goods Till Number.</small>
                </div>

                <div class="form-group" id="paybill_ref_group" style="<?= $currentType === 'paybill' ? '' : 'display:none;' ?>">
                    <label for="payout_account_reference">Paybill Account Number</label>
                    <input type="text" id="payout_account_reference" name="payout_account_reference" class="form-control" value="<?= e($currentRef) ?>" placeholder="e.g. SHOP01 or Business Name">
                    <small class="muted">Enter your business identifier or invoice reference.</small>
                </div>

                <!-- Re-authentication security field -->
                <div class="card" style="background: #fffbeb; border: 1px solid #fde68a; margin: 1.25rem 0;">
                    <div style="display: flex; gap: 0.5rem; align-items: flex-start;">
                        <?= icon('shield-alert', 'lucide-sm', 'amber') ?>
                        <div>
                            <strong style="color: #92400e;">Security Re-Authentication Required</strong>
                            <p class="small muted" style="margin-top: 0.2rem;">To prevent account hijacking, enter your current account password to authorize changes to your payout destination.</p>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 0.75rem; margin-bottom: 0;">
                        <label for="current_password">Your Current SokoHub Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required placeholder="Enter password to confirm">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= icon('check-circle', 'lucide-sm', 'white') ?>
                    <span>Save Payout Destination</span>
                </button>
            </form>
        </div>

        <!-- Right Column: Status & Financial Overview -->
        <div>
            <div class="card" style="margin-bottom: 1.25rem;">
                <h3 style="margin-bottom: 0.75rem;">Current Settlement Status</h3>
                <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                    <div style="display:flex; justify-content:space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-color);">
                        <span class="muted">Payout Channel</span>
                        <strong><?= ucfirst($currentType) ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-color);">
                        <span class="muted">Destination Account</span>
                        <strong style="font-family: monospace; font-size: 1rem;"><?= e($currentAccount ?: 'Not configured yet') ?></strong>
                    </div>
                    <?php if ($currentType === 'paybill'): ?>
                        <div style="display:flex; justify-content:space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-color);">
                            <span class="muted">Account Reference</span>
                            <strong><?= e($currentRef) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-color);">
                        <span class="muted">Platform Commission</span>
                        <span class="badge badge-info">2% Platform Fee</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-color);">
                        <span class="muted">Seller Share</span>
                        <span class="badge badge-success">98% Net Revenue</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding: 0.45rem 0;">
                        <span class="muted">COD Platform Fee Due</span>
                        <strong style="color: <?= $codDue > 0 ? '#b91c1c' : 'inherit' ?>;"><?= format_ksh($codDue) ?></strong>
                    </div>
                </div>
            </div>

            <?php if ($codDue > 0): ?>
                <div class="alert alert-warning">
                    <?= icon('alert-triangle', 'lucide-sm', 'amber') ?>
                    <div>
                        <strong>COD Commission Receivable: <?= format_ksh($codDue) ?></strong>
                        <p class="small" style="margin-top: 0.2rem;">This amount represents the 2% platform fee for Cash on Delivery orders collected in cash. It will be automatically deducted from your next M-Pesa payout.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function togglePayoutFields() {
    const ptype = document.getElementById('payout_type').value;
    const label = document.getElementById('payout_account_label');
    const input = document.getElementById('payout_account');
    const help = document.getElementById('payout_help');
    const refGroup = document.getElementById('paybill_ref_group');

    if (ptype === 'till') {
        label.textContent = 'Buy Goods Till Number';
        input.placeholder = 'e.g. 584920';
        help.textContent = 'Enter your 5-8 digit Buy Goods Till Number.';
        refGroup.style.display = 'none';
    } else if (ptype === 'paybill') {
        label.textContent = 'Paybill Business Number';
        input.placeholder = 'e.g. 174379';
        help.textContent = 'Enter your 5-7 digit Paybill business number.';
        refGroup.style.display = 'block';
    } else {
        label.textContent = 'M-Pesa / Pochi Phone Number';
        input.placeholder = 'e.g. 0712345678';
        help.textContent = 'Enter your 10-digit mobile number registered for M-Pesa.';
        refGroup.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', togglePayoutFields);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
