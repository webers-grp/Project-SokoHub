<?php
/**
 * SokoHub Seller Registration
 * Includes M-Pesa Payout Destination configuration (Till, Paybill, or Pochi/Phone)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$businessTypes = get_business_types();
$errors = [];
$old = [
    'full_name'                => '',
    'business_name'            => '',
    'business_type'            => '',
    'town'                     => 'Kutus',
    'email'                    => '',
    'phone'                    => '',
    'payout_type'              => 'till',
    'payout_account'           => '',
    'payout_account_reference' => '',
    'description'              => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['business_name'] = trim((string) ($_POST['business_name'] ?? ''));
        $old['business_type'] = (string) ($_POST['business_type'] ?? '');
        $old['town'] = trim((string) ($_POST['town'] ?? 'Kutus'));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $old['payout_type'] = (string) ($_POST['payout_type'] ?? 'till');
        $old['payout_account'] = trim((string) ($_POST['payout_account'] ?? ''));
        $old['payout_account_reference'] = trim((string) ($_POST['payout_account_reference'] ?? ''));
        $old['description'] = trim((string) ($_POST['description'] ?? ''));

        $errors = validate_registration_fields($_POST);

        if (mb_strlen($old['business_name']) < 3) {
            $errors[] = 'Business name must be at least 3 characters long.';
        }
        if (!array_key_exists($old['business_type'], $businessTypes)) {
            $errors[] = 'Please choose a valid business category.';
        }

        // Validate payout destination
        $payoutVal = validate_payout_destination($old['payout_type'], $old['payout_account'], $old['payout_account_reference']);
        if (!$payoutVal['ok']) {
            $errors[] = $payoutVal['error'];
        }

        if (empty($_POST['terms'])) {
            $errors[] = 'You must agree to the SokoHub Terms of Service and Privacy Policy.';
        }

        if (empty($errors)) {
            $pdo = get_db_connection();
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $exists->execute([':email' => $old['email']]);

            if ($exists->fetch()) {
                $errors[] = 'An account with this email address already exists.';
            } else {
                $pdo->beginTransaction();
                try {
                    $normPhone = mpesa_normalize_phone($old['phone']);
                    $finalPhone = $normPhone !== '' ? $normPhone : $old['phone'];

                    $normPayoutAcct = ($old['payout_type'] === 'phone' || $old['payout_type'] === 'pochi')
                        ? mpesa_normalize_phone($old['payout_account'])
                        : $old['payout_account'];

                    $hash = password_hash((string) $_POST['password'], PASSWORD_BCRYPT, ['cost' => 10]);
                    $uStmt = $pdo->prepare(
                        "INSERT INTO users (full_name, email, phone, password_hash, role, status)
                         VALUES (:full_name, :email, :phone, :hash, 'seller', 'active')"
                    );
                    $uStmt->execute([
                        ':full_name' => $old['full_name'],
                        ':email'     => $old['email'],
                        ':phone'     => $finalPhone,
                        ':hash'      => $hash,
                    ]);
                    $userId = (int) $pdo->lastInsertId();

                    $sStmt = $pdo->prepare(
                        'INSERT INTO seller_profiles (user_id, business_name, business_type, town, description, payout_type, payout_account, payout_account_reference)
                         VALUES (:user_id, :business_name, :business_type, :town, :description, :ptype, :pacct, :pref)'
                    );
                    $sStmt->execute([
                        ':user_id'       => $userId,
                        ':business_name' => $old['business_name'],
                        ':business_type' => $old['business_type'],
                        ':town'          => $old['town'] !== '' ? $old['town'] : 'Kutus',
                        ':description'   => $old['description'],
                        ':ptype'         => $old['payout_type'],
                        ':pacct'         => $normPayoutAcct,
                        ':pref'          => $old['payout_type'] === 'paybill' ? $old['payout_account_reference'] : null,
                    ]);
                    $pdo->commit();

                    login_user($pdo, $old['email'], (string) $_POST['password'], false);
                    set_flash('success', 'Vendor account created! Welcome to SokoHub, ' . $old['business_name'] . '. Your payout destination is verified.');
                    redirect('pages/dashboard/manage_products.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Registration failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'SokoHub | Seller Registration';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Become a SokoHub Vendor</h2>
            <p style="margin-top: 0.75rem;">Showcase products to thousands of shoppers, receive direct M-Pesa settlements (98% net), and grow your digital business.</p>
            <div style="margin-top: 1.5rem; background: rgba(255,255,255,0.12); padding: 1rem; border-radius: 8px;">
                <h4 style="color: #fff; margin-bottom: 0.4rem;">Platform Payout Rules</h4>
                <p class="small" style="color: #e2e8f0; line-height: 1.5;">Customers pay to platform Till 1648753. We automatically disburse 98% directly to your Till, Paybill, or Pochi number.</p>
            </div>
        </div>

        <div class="auth-body">
            <h1>Create Seller Account</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Set up your store details and choose how you receive sales payouts.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/register_seller.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="full_name">Owner Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required data-minlength="3" placeholder="e.g. Ian Mwangi">
                    </div>

                    <div class="form-group">
                        <label for="business_name">Business Name</label>
                        <input type="text" id="business_name" name="business_name" class="form-control" value="<?= e($old['business_name']) ?>" required data-minlength="3" placeholder="e.g. Ian's Fresh Produce">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="business_type">Type of Business</label>
                        <select id="business_type" name="business_type" class="form-control" required>
                            <option value="">Select a category</option>
                            <?php foreach ($businessTypes as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $old['business_type'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="town">Primary Town / Market</label>
                        <input type="text" id="town" name="town" class="form-control" value="<?= e($old['town']) ?>" required placeholder="e.g. Kutus, Kerugoya">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="email">Official Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="business@example.com">
                    </div>

                    <div class="form-group">
                        <label for="phone">Contact Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($old['phone']) ?>" required placeholder="0712 345 678">
                    </div>
                </div>

                <!-- Payout Destination Section -->
                <div class="card" style="background: #f8fafc; border: 1px solid var(--border-color); margin: 1rem 0;">
                    <h3 style="color: var(--green-dark); margin-bottom: 0.35rem;">
                        <?= icon('wallet', 'lucide-xs', 'emerald') ?>
                        <span>Receive Payments (Payout Destination)</span>
                    </h3>
                    <p class="small muted" style="margin-bottom: 0.85rem;">Select exactly one payout destination where your 98% net sales revenue will be settled.</p>

                    <div class="form-group">
                        <label for="payout_type">Payout Channel</label>
                        <select id="payout_type" name="payout_type" class="form-control" required onchange="togglePayoutFields()">
                            <option value="till" <?= $old['payout_type'] === 'till' ? 'selected' : '' ?>>Till Number (Buy Goods)</option>
                            <option value="paybill" <?= $old['payout_type'] === 'paybill' ? 'selected' : '' ?>>Paybill (with Account Number)</option>
                            <option value="phone" <?= $old['payout_type'] === 'phone' ? 'selected' : '' ?>>Pochi la Biashara / Personal M-Pesa</option>
                        </select>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="payout_account" id="payout_account_label">Till Number</label>
                            <input type="text" id="payout_account" name="payout_account" class="form-control" value="<?= e($old['payout_account']) ?>" required placeholder="e.g. 584920">
                        </div>

                        <div class="form-group" id="paybill_ref_group" style="<?= $old['payout_type'] === 'paybill' ? '' : 'display:none;' ?>">
                            <label for="payout_account_reference">Paybill Account Number</label>
                            <input type="text" id="payout_account_reference" name="payout_account_reference" class="form-control" value="<?= e($old['payout_account_reference']) ?>" placeholder="e.g. SHOP01 or Store Name">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Short Store Bio / Description (Optional)</label>
                    <input type="text" id="description" name="description" class="form-control" value="<?= e($old['description']) ?>" placeholder="What do you sell?">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required data-minlength="8" placeholder="Minimum 8 characters">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required data-match="password" placeholder="Re-enter password">
                    </div>
                </div>

                <div class="checkbox-row">
                    <input type="checkbox" id="terms" name="terms" value="1" required>
                    <label for="terms">I agree to SokoHub's vendor agreement and 2% platform transaction commission.</label>
                </div>

                <button type="submit" class="btn btn-amber btn-block" style="margin-top: 1rem;">
                    <?= icon('store', 'lucide-sm', 'white') ?>
                    <span>Register Seller Account</span>
                </button>
            </form>

            <p class="muted small" style="text-align: center; margin-top: 1.25rem;">
                Already have a vendor account? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign in here</a>
            </p>
        </div>
    </div>
</main>

<script>
function togglePayoutFields() {
    const ptype = document.getElementById('payout_type').value;
    const label = document.getElementById('payout_account_label');
    const input = document.getElementById('payout_account');
    const refGroup = document.getElementById('paybill_ref_group');

    if (ptype === 'till') {
        label.textContent = 'Buy Goods Till Number';
        input.placeholder = 'e.g. 584920';
        refGroup.style.display = 'none';
    } else if (ptype === 'paybill') {
        label.textContent = 'Paybill Business Number';
        input.placeholder = 'e.g. 174379';
        refGroup.style.display = 'block';
    } else {
        label.textContent = 'M-Pesa / Pochi Phone Number';
        input.placeholder = 'e.g. 0712345678';
        refGroup.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', togglePayoutFields);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
