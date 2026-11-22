<?php
/**
 * SokoHub Forgot Password Request Page
 *
 * Generates single-use expiring password reset tokens stored safely as SHA-256 hashes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();
$pdo = get_db_connection();

$resetLink = null;
$notFoundEmail = null;
$isLocalOrDev = (!defined('APP_ENV') || APP_ENV !== 'production' || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8080', 'localhost:8088', '127.0.0.1:8088']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/auth/forgot_password.php');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    $clientIp = Security::getClientIp();
    $rate = RateLimiter::check('forgot_password', $clientIp, 10, 900);
    if (!$rate['allowed']) {
        $mins = (int) ceil($rate['retry_after'] / 60);
        set_flash('error', "Too many password reset requests from this network. Please retry in {$mins} minute(s).");
        redirect('pages/auth/forgot_password.php');
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Please provide a valid account email address.');
        redirect('pages/auth/forgot_password.php');
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE LOWER(email) = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($targetUser) {
        // Invalidate any existing active tokens for this user
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid')->execute([':uid' => $targetUser['id']]);

        // Generate high-entropy 64-hex-char token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        // Expire in 1 hour
        $pdo->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
            VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
        ')->execute([
            ':uid'   => $targetUser['id'],
            ':thash' => $tokenHash,
        ]);

        Audit::record('auth.password_reset_requested', 'user', (int) $targetUser['id'], [
            'email' => $email,
        ], (int) $targetUser['id'], 'user');

        $generatedLink = url('pages/auth/reset_password.php?token=' . $rawToken);
        if ($isLocalOrDev) {
            $resetLink = $generatedLink;
        } else {
            error_log("[SokoHub Security] Password reset generated for user ID {$targetUser['id']}");
        }
    } else {
        if ($isLocalOrDev) {
            $notFoundEmail = $email;
        }
    }

    // Uniform anti-enumeration message
    set_flash('success', 'If an account exists with that email address, password reset instructions have been sent.');
}

$page_title = 'Forgot Password - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 500px; margin: 3rem auto; padding: 0 1rem;">
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 2.25rem 2rem; box-shadow: 0 4px 16px rgba(0,0,0,0.08);">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="margin-bottom: 0.75rem; color: #E8192C; display: flex; justify-content: center;"><?= icon('key-round', 'lucide-xl') ?></div>
            <h1 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">Forgot Password?</h1>
            <p style="font-size: 0.88rem; color: #64748b; margin: 0; line-height: 1.5;">
                Enter your account email and we'll generate a secure, 1-hour password reset token for you.
            </p>
        </div>

        <?php if ($resetLink): ?>
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 1.25rem; margin-bottom: 1.5rem;">
                <p style="color: #166534; font-weight: 700; font-size: 0.88rem; margin: 0 0 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                    <?= icon('check-circle', 'lucide-xs', 'icon-emerald') ?> Password Reset Link Ready (Local / Dev Mode):
                </p>
                <p style="font-size: 0.82rem; color: #334155; margin: 0 0 0.75rem;">
                    Click the button below to proceed directly to setting your new password:
                </p>
                <a href="<?= e($resetLink) ?>" style="display: block; text-align: center; font-size: 0.88rem; font-weight: 700; padding: 0.65rem 1rem; text-decoration: none; background: #16a34a; color: #fff; border-radius: 4px;">
                    Reset My Password Now &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php if ($notFoundEmail): ?>
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: #92400e;">
                <p style="margin: 0 0 0.5rem; font-weight: 700;">
                    <?= icon('info', 'lucide-xs') ?> Note (Local / Dev): No account found matching <code><?= e($notFoundEmail) ?></code>
                </p>
                <p style="margin: 0 0 0.5rem; font-size: 0.82rem; color: #78350f;">
                    Click any demo account below to fill its email and request a reset:
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                    <button type="button" style="font-size: 0.75rem; padding: 0.25rem 0.55rem; border: 1px solid #d97706; background: #fff; color: #b45309; border-radius: 3px; cursor: pointer;" onclick="document.getElementById('email_input').value='buyer@sokohub.co.ke'">buyer@sokohub.co.ke</button>
                    <button type="button" style="font-size: 0.75rem; padding: 0.25rem 0.55rem; border: 1px solid #d97706; background: #fff; color: #b45309; border-radius: 3px; cursor: pointer;" onclick="document.getElementById('email_input').value='seller@sokohub.co.ke'">seller@sokohub.co.ke</button>
                    <button type="button" style="font-size: 0.75rem; padding: 0.25rem 0.55rem; border: 1px solid #d97706; background: #fff; color: #b45309; border-radius: 3px; cursor: pointer;" onclick="document.getElementById('email_input').value='rider@sokohub.co.ke'">rider@sokohub.co.ke</button>
                    <button type="button" style="font-size: 0.75rem; padding: 0.25rem 0.55rem; border: 1px solid #d97706; background: #fff; color: #b45309; border-radius: 3px; cursor: pointer;" onclick="document.getElementById('email_input').value='admin@sokohub.co.ke'">admin@sokohub.co.ke</button>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div style="margin-bottom: 1.25rem;">
                <label for="email_input" style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                    Your Account Email
                </label>
                <input type="email" id="email_input" name="email" placeholder="e.g. buyer@sokohub.co.ke" class="form-control" required style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.9rem;">
            </div>

            <button type="submit" style="width: 100%; padding: 0.75rem; font-weight: 700; font-size: 0.92rem; background: #E8192C; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                Send Reset Instructions
            </button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: #64748b;">
            Remembered your password? <a href="<?= e(url('pages/auth/login.php')) ?>" style="color: #E8192C; font-weight: 700; text-decoration: none;">Back to Sign In</a>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
