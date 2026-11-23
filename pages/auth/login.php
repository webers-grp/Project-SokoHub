<?php
/**
 * SokoHub Sign In Page (Upgraded from Main/Login&Signup/login.html)
 * Uses session_start(), password_verify(), and Remember-Me HTTP cookies.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$rememberedEmail = $_COOKIE['sokohub_remember_email'] ?? '';
$emailInput = $rememberedEmail;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $emailInput = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $rememberMe = !empty($_POST['remember_me']);

        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($password === '') {
            $errors[] = 'Please enter your password.';
        }

        if (empty($errors)) {
            $pdo = get_db_connection();
            $result = login_user($pdo, $emailInput, $password, $rememberMe);

            if ($result['ok']) {
                Audit::record('auth.login_success', 'user', (int) $result['user']['id'], ['email' => $emailInput, 'role' => $result['user']['role']]);
                set_flash('success', 'Welcome back, ' . $result['user']['full_name'] . '!');
                redirect('pages/dashboard/dashboard.php');
            } else {
                Audit::record('auth.login_failed', 'user', null, ['email' => $emailInput, 'reason' => $result['error']]);
                $errors[] = $result['error'];
            }
        }
    }
}

$pageTitle = 'SokoHub | Sign In';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Welcome Back to SokoHub</h2>
            <p style="margin-top: 0.75rem;">Sign in to manage your storefront, place orders, track deliveries, and view live marketplace analytics.</p>
        </div>

        <div class="auth-body">
            <h1>Sign In</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Continue to your SokoHub account</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/login.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="login_email">Email Address</label>
                    <input type="email" id="login_email" name="email" class="form-control" value="<?= e($emailInput) ?>" required placeholder="Enter your email">
                </div>

                <div class="form-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="password" class="form-control" required placeholder="Enter your password">
                </div>

                <div class="checkbox-row">
                    <input type="checkbox" id="remember_me" name="remember_me" value="1" <?= $rememberedEmail !== '' ? 'checked' : '' ?>>
                    <label for="remember_me" style="margin: 0;">Remember my email on this browser (Cookie)</label>
                </div>

                <button type="submit" class="btn btn-amber btn-block">
                    <?= icon('log-in') ?>
                    <span>Login to SokoHub</span>
                </button>
            </form>

            <p class="small muted" style="margin-top: 1rem;">
                Don't have an account? <a href="<?= e(url('pages/auth/register.php')) ?>"><?= icon('user-plus', 'lucide-sm') ?> Create one here</a>
            </p>

            <div class="demo-box">
                <strong class="small"><?= icon('key', 'lucide-sm', 'amber') ?> Quick Demo Accounts (Password: <code>SokoHub@2026</code>)</strong>
                <div class="demo-btns">
                    <button type="button" class="btn btn-outline btn-sm" data-demo-email="buyer@sokohub.co.ke" data-demo-pass="SokoHub@2026">
                        <?= icon('shopping-bag', 'lucide-xs', 'blue') ?>
                        <span>Buyer</span>
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-demo-email="seller@sokohub.co.ke" data-demo-pass="SokoHub@2026">
                        <?= icon('store', 'lucide-xs', 'amber') ?>
                        <span>Seller</span>
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-demo-email="rider@sokohub.co.ke" data-demo-pass="SokoHub@2026">
                        <?= icon('truck', 'lucide-xs', 'emerald') ?>
                        <span>Delivery</span>
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-demo-email="admin@sokohub.co.ke" data-demo-pass="SokoHub@2026">
                        <?= icon('shield-check', 'lucide-xs', 'purple') ?>
                        <span>Admin</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
