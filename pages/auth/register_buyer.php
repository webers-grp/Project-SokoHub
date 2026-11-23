<?php
/**
 * SokoHub Buyer / Customer Registration (Upgraded from Main/Login&Signup/sign up.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$errors = [];
$old = ['full_name' => '', 'phone' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));

        $errors = validate_registration_fields($_POST);

        if (empty($errors)) {
            $pdo = get_db_connection();
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $exists->execute([':email' => $old['email']]);

            if ($exists->fetch()) {
                $errors[] = 'An account with this email address already exists.';
            } else {
                $hash = password_hash((string) $_POST['password'], PASSWORD_BCRYPT, ['cost' => 10]);
                $stmt = $pdo->prepare(
                    "INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
                     VALUES (:full_name, :email, :phone, :hash, 'buyer', 'pending_verification', NOW())"
                );
                $stmt->execute([
                    ':full_name' => $old['full_name'],
                    ':email'     => $old['email'],
                    ':phone'     => $old['phone'],
                    ':hash'      => $hash,
                ]);

                $userId = (int) $pdo->lastInsertId();
                $otpCode = Security::generateOtp($userId, 'registration', 600);
                Security::enqueueJob($pdo, 'send_email', [
                    'to'       => $old['email'],
                    'template' => 'otp_registration',
                    'code'     => $otpCode,
                    'subject'  => 'Welcome to SokoHub - Verify Your Account',
                ]);
                Security::logNotification($pdo, $userId, 'email', 'otp_registration', $old['email']);

                $_SESSION['pending_otp_user_id'] = $userId;
                $_SESSION['pending_otp_email'] = $old['email'];
                $_SESSION['pending_otp_purpose'] = 'registration';

                set_flash('success', 'Your buyer account has been created! Please enter the 6-digit verification code sent to ' . htmlspecialchars($old['email']));
                redirect('pages/auth/verify_otp.php');
            }
        }
    }
}

$pageTitle = 'SokoHub | Buyer Registration';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Welcome to SokoHub</h2>
            <p style="margin-top: 0.75rem;">Your trusted local marketplace — simple, fast, and reliable shopping across Kirinyaga.</p>
        </div>

        <div class="auth-body">
            <h1>Create Buyer Account</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Start ordering fresh produce, gas refills, electronics, and apparel.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/register_buyer.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required data-minlength="3" placeholder="e.g. Faith Muthoni">
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($old['phone']) ?>" required placeholder="e.g. +254 712 345 678">
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="you@example.com">
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

                <!-- Password Strength Meter Widget -->
                <div style="margin: 0.5rem 0 1rem;">
                    <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                        <div id="buyerStrengthBar" style="height: 100%; width: 0%; background: #ef4444; transition: width 0.3s ease, background-color 0.3s ease;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem;">
                        <span class="small muted">Strength: <strong id="buyerStrengthLabel" style="color: #ef4444;">Too weak</strong></span>
                        <div id="buyerMatchNotice" class="small" style="font-weight: 600; display: none;"></div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= icon('user-plus') ?>
                    <span>Create Buyer Account</span>
                </button>
            </form>

            <p class="small muted" style="margin-top: 1rem;">
                Already have an account? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In here</a> &bull; Want to sell? <a href="<?= e(url('pages/auth/register_seller.php')) ?>">Seller Sign Up</a>
            </p>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pwd = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    const bar = document.getElementById('buyerStrengthBar');
    const label = document.getElementById('buyerStrengthLabel');
    const matchNotice = document.getElementById('buyerMatchNotice');

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
