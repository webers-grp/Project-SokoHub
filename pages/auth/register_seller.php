<?php
/**
 * SokoHub Seller Registration (Upgraded from Main/sellers.page.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$businessTypes = get_business_types();
$errors = [];
$old = [
    'full_name'         => '',
    'business_name'     => '',
    'business_type'     => '',
    'town'              => 'Kutus',
    'stall_location'    => '',
    'operating_hours'   => '8:00 AM - 8:00 PM',
    'whatsapp_phone'    => '',
    'email'             => '',
    'phone'             => '',
    'description'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['business_name'] = trim((string) ($_POST['business_name'] ?? ''));
        $old['business_type'] = (string) ($_POST['business_type'] ?? '');
        $old['town'] = trim((string) ($_POST['town'] ?? 'Kutus'));
        $old['stall_location'] = trim((string) ($_POST['stall_location'] ?? ''));
        $old['operating_hours'] = trim((string) ($_POST['operating_hours'] ?? '8:00 AM - 8:00 PM'));
        $old['whatsapp_phone'] = trim((string) ($_POST['whatsapp_phone'] ?? ''));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $old['description'] = trim((string) ($_POST['description'] ?? ''));

        $errors = validate_registration_fields($_POST);

        if (mb_strlen($old['business_name']) < 3) {
            $errors[] = 'Business name must be at least 3 characters long.';
        }
        if (!array_key_exists($old['business_type'], $businessTypes)) {
            $errors[] = 'Please choose a valid business category.';
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
                    $hash = password_hash((string) $_POST['password'], PASSWORD_BCRYPT, ['cost' => 10]);
                    $uStmt = $pdo->prepare(
                        "INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
                         VALUES (:full_name, :email, :phone, :hash, 'seller', 'pending_verification', NOW())"
                    );
                    $uStmt->execute([
                        ':full_name' => $old['full_name'],
                        ':email'     => $old['email'],
                        ':phone'     => $old['phone'],
                        ':hash'      => $hash,
                    ]);
                    $userId = (int) $pdo->lastInsertId();

                    $sStmt = $pdo->prepare(
                        'INSERT INTO seller_profiles (
                            user_id, business_name, business_type, town,
                            stall_location, operating_hours, whatsapp_phone, description
                         ) VALUES (
                            :user_id, :business_name, :business_type, :town,
                            :stall_location, :operating_hours, :whatsapp_phone, :description
                         )'
                    );
                    $sStmt->execute([
                        ':user_id'           => $userId,
                        ':business_name'     => $old['business_name'],
                        ':business_type'     => $old['business_type'],
                        ':town'              => $old['town'] !== '' ? $old['town'] : 'Kutus',
                        ':stall_location'    => $old['stall_location'] !== '' ? $old['stall_location'] : null,
                        ':operating_hours'   => $old['operating_hours'] !== '' ? $old['operating_hours'] : '8:00 AM - 8:00 PM',
                        ':whatsapp_phone'    => $old['whatsapp_phone'] !== '' ? $old['whatsapp_phone'] : null,
                        ':description'       => $old['description'],
                    ]);
                    $pdo->commit();

                    $otpCode = Security::generateOtp($userId, 'registration', 600);
                    Security::enqueueJob($pdo, 'send_email', [
                        'to'       => $old['email'],
                        'template' => 'otp_registration',
                        'code'     => $otpCode,
                        'subject'  => 'Welcome to SokoHub - Verify Your Vendor Account',
                    ]);
                    Security::logNotification($pdo, $userId, 'email', 'otp_registration', $old['email']);

                    $_SESSION['pending_otp_user_id'] = $userId;
                    $_SESSION['pending_otp_email'] = $old['email'];
                    $_SESSION['pending_otp_purpose'] = 'registration';

                    set_flash('success', 'Vendor account created! Please enter the 6-digit verification code sent to ' . htmlspecialchars($old['email']));
                    redirect('pages/auth/verify_otp.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Registration failed due to a database error. Please try again.';
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
            <p style="margin-top: 0.75rem;">Showcase products to thousands of local shoppers, track inventory levels, and monitor sales performance.</p>
        </div>

        <div class="auth-body">
            <h1>Create Seller Account</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Tell us about your business to start listing on SokoHub.</p>

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

                <!-- Section 1: Store Owner & Login Credentials -->
                <div class="auth-card-section">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; color: #15803d; font-weight: 700; font-size: 1.05rem;">
                        <?= icon('user-check', 'lucide-sm') ?>
                        <span>1. Store Owner &amp; Login Security</span>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="full_name">Owner Full Name *</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required data-minlength="3" placeholder="e.g. Kennedy Kalawa">
                        </div>

                        <div class="form-group">
                            <label for="email">Official Email Address *</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="vendor@example.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Primary Phone Number (Safaricom) *</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($old['phone']) ?>" required placeholder="0712 345 678 or +254 712 345 678">
                        <span class="small muted">Used for direct buyer contact and SMS dispatch alerts.</span>
                    </div>

                    <div class="form-grid-2" style="margin-top: 0.75rem;">
                        <div class="form-group">
                            <label for="password">Create Password *</label>
                            <input type="password" id="password" name="password" class="form-control" required data-minlength="8" placeholder="Minimum 8 characters">
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required data-match="password" placeholder="Re-enter password">
                        </div>
                    </div>

                    <!-- Password Strength Meter Widget -->
                    <div style="margin: 0.5rem 0 0.25rem;">
                        <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                            <div id="sellerStrengthBar" style="height: 100%; width: 0%; background: #ef4444; transition: width 0.3s ease, background-color 0.3s ease;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem;">
                            <span class="small muted">Strength: <strong id="sellerStrengthLabel" style="color: #ef4444;">Too weak</strong></span>
                            <div id="sellerMatchNotice" class="small" style="font-weight: 600; display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Store Identity & Campus Location Details -->
                <div class="auth-card-section">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; color: #15803d; font-weight: 700; font-size: 1.05rem;">
                        <?= icon('store', 'lucide-sm') ?>
                        <span>2. Store Identity &amp; Campus Location</span>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="business_name">Store / Business Name *</label>
                            <input type="text" id="business_name" name="business_name" class="form-control" value="<?= e($old['business_name']) ?>" required data-minlength="3" placeholder="e.g. Soko Fresh Grocers">
                        </div>

                        <div class="form-group">
                            <label for="business_type">Store Category *</label>
                            <select id="business_type" name="business_type" class="form-control" required>
                                <option value="">Select a category</option>
                                <?php foreach ($businessTypes as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $old['business_type'] === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="town">Primary Town / Market *</label>
                            <input type="text" id="town" name="town" class="form-control" value="<?= e($old['town']) ?>" required placeholder="e.g. Kutus, Kerugoya, Kagio">
                        </div>

                        <div class="form-group">
                            <label for="stall_location">Campus / Stall / Street Location</label>
                            <input type="text" id="stall_location" name="stall_location" class="form-control" value="<?= e($old['stall_location']) ?>" placeholder="e.g. KyU Gate B Stall 4, Hostel 3 Complex">
                            <span class="small muted">Helps delivery riders and campus shoppers find your store.</span>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="operating_hours">Daily Operating Hours</label>
                            <input type="text" id="operating_hours" name="operating_hours" class="form-control" value="<?= e($old['operating_hours']) ?>" placeholder="e.g. 8:00 AM - 8:00 PM">
                        </div>

                        <div class="form-group">
                            <label for="whatsapp_phone">WhatsApp Business Line (Optional)</label>
                            <input type="tel" id="whatsapp_phone" name="whatsapp_phone" class="form-control" value="<?= e($old['whatsapp_phone']) ?>" placeholder="e.g. 0712 345 678">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="description">Short Business Description (Optional)</label>
                        <input type="text" id="description" name="description" class="form-control" value="<?= e($old['description']) ?>" placeholder="Tell shoppers what products or services you specialize in...">
                    </div>
                </div>

                <!-- KYC & Payouts Post-Registration Notice -->
                <div style="background: rgba(56, 189, 248, 0.1); border: 1px dashed rgba(56, 189, 248, 0.4); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                    <?= icon('shield-check', 'lucide-sm', 'blue') ?>
                    <div class="small muted" style="line-height: 1.4;">
                        <strong>KYC Verification &amp; Payouts:</strong> To keep registration quick, official KYC verification (National ID upload and M-Pesa Payout Account) will be completed from your Seller Dashboard after account activation.
                    </div>
                </div>

                <div class="checkbox-row">
                    <input id="terms" name="terms" type="checkbox" value="1" required>
                    <label for="terms" style="margin: 0;">I agree to the SokoHub Terms of Service and Seller Guidelines.</label>
                </div>

                <button type="submit" class="btn btn-amber btn-block" style="padding: 0.8rem; font-size: 1.05rem;">
                    <?= icon('store') ?>
                    <span>Register Seller Account</span>
                </button>
            </form>

            <p class="small muted" style="margin-top: 1rem;">
                Already have a seller account? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In Here</a>
            </p>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pwd = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    const bar = document.getElementById('sellerStrengthBar');
    const label = document.getElementById('sellerStrengthLabel');
    const matchNotice = document.getElementById('sellerMatchNotice');

    if (!pwd || !confirm) return;

    function checkStrength(val) {
        let score = 0;
        if (!val) return 0;
        if (val.length >= 8) score += 25;
        if (val.length >= 12) score += 15;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score += 25;
        if (/[0-9]/.test(val)) score += 20;
        if (/[^a-zA-Z0-9]/.test(val)) score += 15;
        return Math.min(100, score);
    }

    pwd.addEventListener('input', function () {
        const score = checkStrength(this.value);
        bar.style.width = score + '%';

        if (score < 40) {
            bar.style.backgroundColor = '#ef4444';
            label.textContent = 'Weak';
            label.style.color = '#ef4444';
        } else if (score < 75) {
            bar.style.backgroundColor = '#f59e0b';
            label.textContent = 'Medium';
            label.style.color = '#d97706';
        } else {
            bar.style.backgroundColor = '#10b981';
            label.textContent = 'Strong';
            label.style.color = '#059669';
        }
        validateMatch();
    });

    function validateMatch() {
        if (!confirm.value) {
            matchNotice.style.display = 'none';
            return;
        }
        matchNotice.style.display = 'block';
        if (pwd.value === confirm.value) {
            matchNotice.textContent = 'Passwords match';
            matchNotice.style.color = '#059669';
            confirm.style.borderColor = '#10b981';
        } else {
            matchNotice.textContent = 'Passwords do not match';
            matchNotice.style.color = '#ef4444';
            confirm.style.borderColor = '#ef4444';
        }
    }

    confirm.addEventListener('input', validateMatch);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
