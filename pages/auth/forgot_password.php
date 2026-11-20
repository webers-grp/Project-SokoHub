<?php
/**
 * SokoHub Forgot Password Request Page
 *
 * Generates single-use expiring password reset tokens stored safely as SHA-256 hashes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$pdo = get_db_connection();

$resetLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/auth/forgot_password.php');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

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

        $resetLink = url('pages/auth/reset_password.php?token=' . $rawToken);
        set_flash('success', 'A secure password reset link has been generated.');
    } else {
        // Anti-enumeration notice
        set_flash('success', 'If an account exists with that email, a password reset link has been sent.');
    }
}

$page_title = 'Forgot Password - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 480px; margin: 3rem auto; padding: 0 1rem;">
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="margin-bottom: 0.75rem; color: #ea580c; display: flex; justify-content: center;"><?= icon('key-round', 'lucide-xl') ?></div>
            <h1 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">Forgot Password?</h1>
            <p style="font-size: 0.85rem; color: #64748b; margin: 0;">
                Enter your account email and we'll generate a secure, 1-hour reset token for you.
            </p>
        </div>

        <?php if ($resetLink): ?>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                <p style="color: #166534; font-weight: 700; font-size: 0.85rem; margin: 0 0 0.5rem;">
                    Local Development / Simulated Email Dispatch:
                </p>
                <p style="font-size: 0.8rem; color: #334155; margin: 0 0 0.75rem;">
                    Click the button below or copy the link to reset your password:
                </p>
                <a href="<?= e($resetLink) ?>" class="btn btn-primary" style="display: block; text-align: center; font-size: 0.85rem; padding: 0.5rem; text-decoration: none;">
                    Reset Password Now &rarr;
                </a>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                    Your Account Email
                </label>
                <input type="email" name="email" placeholder="e.g. buyer@sokohub.local" class="form-control" required style="width: 100%;">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-weight: 600;">
                Send Reset Instructions
            </button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: #64748b;">
            Remembered your credentials? <a href="<?= e(url('pages/auth/login.php')) ?>" style="color: #16a34a; font-weight: 600; text-decoration: none;">Log in</a>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
