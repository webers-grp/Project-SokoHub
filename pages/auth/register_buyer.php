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
                    "INSERT INTO users (full_name, email, phone, password_hash, role, status)
                     VALUES (:full_name, :email, :phone, :hash, 'buyer', 'active')"
                );
                $stmt->execute([
                    ':full_name' => $old['full_name'],
                    ':email'     => $old['email'],
                    ':phone'     => $old['phone'],
                    ':hash'      => $hash,
                ]);

                login_user($pdo, $old['email'], (string) $_POST['password'], false);
                set_flash('success', 'Welcome to SokoHub, ' . $old['full_name'] . '! Your buyer account is ready.');
                redirect('pages/dashboard/dashboard.php');
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
