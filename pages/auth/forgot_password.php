<?php
/**
 * SokoHub Forgot Password Recovery
 * Generates random 32-byte cryptographic token (bin2hex(random_bytes(32))),
 * stores SHA-256 hash in password_reset_tokens, and emails a reset link valid for 30 minutes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$emailInput = '';
$errors = [];
$successMessage = '';
$devResetUrl = '';
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)
    || (PHP_SAPI === 'cli-server') || empty($_SERVER['SERVER_NAME']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $emailInput = strtolower(trim((string) ($_POST['email'] ?? '')));

        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $pdo = get_db_connection();
            Security::ensureSchema();

            $stmt = $pdo->prepare("SELECT id, full_name, email, status FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $emailInput]);
            $user = $stmt->fetch();

            if ($user && $user['status'] !== 'suspended') {
                $userId = (int) $user['id'];
                $rawToken = Security::createPasswordResetToken($pdo, $userId);

                $resetUrl = url('pages/auth/reset_password.php?token=' . urlencode($rawToken));
                if ($isLocal) {
                    $devResetUrl = $resetUrl;
                }

                // Enqueue background email dispatch
                Security::enqueueJob($pdo, 'send_email', [
                    'to'        => $user['email'],
                    'template'  => 'password_reset',
                    'reset_url' => $resetUrl,
                    'token'     => $rawToken,
                    'subject'   => 'Reset Your SokoHub Password',
                ]);

                Security::logNotification($pdo, $userId, 'email', 'password_reset', $user['email']);
                Audit::record('auth.password_reset_requested', 'user', $userId, ['email' => $user['email']]);
            }

            // Always show identical generic confirmation to prevent user enumeration
            $successMessage = 'If an account exists for ' . htmlspecialchars($emailInput) . ', a password reset link has been sent. The link expires in 30 minutes.';
        }
    }
}

$pageTitle = 'SokoHub | Forgot Password';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Forgot Your Password?</h2>
            <p style="margin-top: 0.75rem;">
                No worries! Enter your registered account email, and we'll send you a secure link to reset your password and regain access to SokoHub.
            </p>
        </div>

        <div class="auth-body">
            <h1>Reset Password</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">
                Enter your email address to receive password reset instructions.
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

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" style="background: #e8f5e9; border: 1px solid #c8e6c9; color: #1b5e20; padding: 1rem; border-radius: var(--radius); margin-bottom: 1.25rem;">
                    <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                        <?= icon('check-circle', 'lucide-md') ?>
                        <div>
                            <strong>Reset Link Sent!</strong>
                            <p style="margin: 0.25rem 0 0; font-size: 0.92rem;"><?= $successMessage ?></p>
                            <?php if (!empty($devResetUrl)): ?>
                                <div style="margin-top: 0.75rem; padding: 0.5rem 0.75rem; background: #ffffff; border: 1px dashed #2e7d32; border-radius: 6px;">
                                    <div class="small" style="font-weight: 700; color: #2e7d32; margin-bottom: 0.2rem;">
                                        <?= icon('link', 'lucide-xs') ?> Localhost Testing Direct Link:
                                    </div>
                                    <a href="<?= e($devResetUrl) ?>" style="word-break: break-all; font-size: 0.88rem; font-weight: 600;">
                                        <?= e($devResetUrl) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/forgot_password.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="recovery_email">Your Registered Email</label>
                    <input type="email"
                           id="recovery_email"
                           name="email"
                           class="form-control"
                           value="<?= e($emailInput) ?>"
                           required
                           placeholder="name@example.com">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding: 0.75rem;">
                    <?= icon('mail') ?>
                    <span>Send Password Reset Link</span>
                </button>
            </form>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                <a href="<?= e(url('pages/auth/login.php')) ?>" class="small">
                    <?= icon('arrow-left', 'lucide-xs') ?> Back to Sign In
                </a>
                <a href="<?= e(url('pages/auth/register.php')) ?>" class="small muted">
                    Need an account? Sign Up
                </a>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
