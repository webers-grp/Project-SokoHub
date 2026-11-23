<?php
/**
 * SokoHub Sign In Page (Kilimall-style redesign)
 * Uses session_start(), password_verify(), and Remember-Me HTTP cookies.
 */
require_once __DIR__ . '/../../includes/auth.php';
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
                set_flash('success', 'Welcome back, ' . $result['user']['full_name'] . '!');
                redirect('pages/dashboard/dashboard.php');
            } else {
                $errors[] = $result['error'];
            }
        }
    }
}

$pageTitle = 'SokoHub | Sign In';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.auth-page-wrap {
    min-height: calc(100vh - 160px);
    background: #F5F5F5;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}
.auth-container {
    display: grid;
    grid-template-columns: 380px 1fr;
    max-width: 900px;
    width: 100%;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}
.auth-brand-panel {
    background: #E8192C;
    padding: 3rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-align: center;
}
.auth-brand-panel img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border-radius: 50%;
    background: #fff;
    padding: 6px;
    margin-bottom: 1.25rem;
}
.auth-brand-panel h2 {
    font-size: 1.5rem;
    font-weight: 800;
    margin: 0 0 0.75rem;
    color: #fff;
}
.auth-brand-panel p {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.88);
    line-height: 1.6;
    margin: 0 0 2rem;
}
.trust-badges {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    width: 100%;
}
.trust-badge {
    background: rgba(255,255,255,0.12);
    border-radius: 4px;
    padding: 0.65rem 1rem;
    font-size: 0.82rem;
    color: rgba(255,255,255,0.95);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.auth-form-panel {
    background: #fff;
    padding: 2.5rem 2rem;
}
.auth-form-panel h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: #333;
    margin: 0 0 0.35rem;
}
.auth-form-panel .subtitle {
    font-size: 0.88rem;
    color: #666;
    margin: 0 0 1.5rem;
}
.kili-input-group {
    margin-bottom: 1.1rem;
}
.kili-input-group label {
    display: block;
    font-size: 0.84rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 0.3rem;
}
.kili-input-group input {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1px solid #E0E0E0;
    border-radius: 3px;
    font-size: 0.9rem;
    color: #333;
    background: #fff;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.kili-input-group input:focus {
    outline: none;
    border-color: #E8192C;
}
.kili-btn-primary {
    display: block;
    width: 100%;
    padding: 0.8rem;
    background: #E8192C;
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-align: center;
    margin-top: 1.25rem;
    transition: background 0.2s;
}
.kili-btn-primary:hover { background: #C0122A; }
.kili-checkbox-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}
.kili-checkbox-row label { font-size: 0.83rem; color: #666; margin: 0; }
.auth-error-box {
    background: #fff5f5;
    border: 1px solid #fecaca;
    border-left: 4px solid #E8192C;
    border-radius: 3px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    font-size: 0.84rem;
    color: #b91c1c;
}
.social-proof {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    margin-top: 1.5rem;
    font-size: 0.82rem;
    color: #999;
}
.social-proof strong { color: #E8192C; }
.demo-section {
    margin-top: 1.5rem;
    background: #FFF8F8;
    border: 1px dashed #E8192C;
    border-radius: 3px;
    padding: 1rem;
}
.demo-section .demo-title {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    font-weight: 700;
    color: #E8192C;
    margin-bottom: 0.5rem;
}
.demo-btns-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.demo-btn {
    font-size: 0.75rem;
    padding: 0.3rem 0.7rem;
    border: 1px solid #E8192C;
    background: #fff;
    color: #E8192C;
    border-radius: 3px;
    cursor: pointer;
    font-weight: 600;
}
.demo-btn:hover { background: #E8192C; color: #fff; }
.auth-links {
    margin-top: 1rem;
    font-size: 0.84rem;
    color: #666;
    text-align: center;
}
.auth-links a { color: #E8192C; font-weight: 600; text-decoration: none; }
@media (max-width: 700px) {
    .auth-container { grid-template-columns: 1fr; }
    .auth-brand-panel { padding: 2rem 1.5rem; }
}
</style>

<div class="auth-page-wrap">
    <div class="auth-container">
        <!-- Brand Panel -->
        <div class="auth-brand-panel">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Welcome Back to SokoHub</h2>
            <p>Your trusted local marketplace for fresh produce, gas refills, electronics, and more across Kirinyaga.</p>
            <div class="trust-badges">
                <div class="trust-badge"><?= icon('shield-check', 'lucide-xs') ?> <span>Secure M-Pesa Payments</span></div>
                <div class="trust-badge"><?= icon('map-pin', 'lucide-xs') ?> <span>Local Kirinyaga Vendors</span></div>
                <div class="trust-badge"><?= icon('truck', 'lucide-xs') ?> <span>Fast Doorstep Delivery</span></div>
                <div class="trust-badge"><?= icon('users', 'lucide-xs') ?> <span>10,000+ Buyers Trust SokoHub</span></div>
            </div>
        </div>

        <!-- Form Panel -->
        <div class="auth-form-panel">
            <h1>Sign In</h1>
            <p class="subtitle">Continue to your SokoHub account</p>

            <?php if (!empty($errors)): ?>
                <div class="auth-error-box">
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/login.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="kili-input-group">
                    <label for="login_email">Email Address</label>
                    <input type="email" id="login_email" name="email" value="<?= e($emailInput) ?>" required placeholder="Enter your email">
                </div>

                <div class="kili-input-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="password" required placeholder="Enter your password">
                </div>

                <div class="kili-checkbox-row">
                    <input type="checkbox" id="remember_me" name="remember_me" value="1" <?= $rememberedEmail !== '' ? 'checked' : '' ?>>
                    <label for="remember_me">Remember my email on this browser</label>
                </div>

                <div style="text-align:right; margin-top:0.25rem;">
                    <a href="<?= e(url('pages/auth/forgot_password.php')) ?>" style="font-size:0.82rem; color:#E8192C; text-decoration:none;">Forgot Password?</a>
                </div>

                <button type="submit" class="kili-btn-primary">Sign In to SokoHub</button>
            </form>

            <div class="social-proof">
                <?= icon('shopping-bag', 'lucide-xs') ?> <span>Join <strong>10,000+</strong> buyers already shopping on SokoHub</span>
            </div>

            <div class="auth-links">
                Don't have an account? <a href="<?= e(url('pages/auth/register.php')) ?>">Create one here</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
