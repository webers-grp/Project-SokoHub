<?php
/**
 * SokoHub Password Reset Token Validation & Submission Page
 *
 * Validates the single-use token against `password_reset_tokens`, updates the password,
 * marks the token as consumed, and clears existing sessions.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$pdo = get_db_connection();

$rawToken = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$tokenHash = hash('sha256', $rawToken);

$tokenValid = false;
$tokenUser = null;
$errorMsg = null;

if (!empty($rawToken)) {
    $stmt = $pdo->prepare('
        SELECT prt.*, u.id AS user_id, u.email, u.full_name, u.role
        FROM password_reset_tokens prt
        INNER JOIN users u ON u.id = prt.user_id
        WHERE prt.token_hash = :thash
          AND prt.consumed_at IS NULL
          AND prt.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([':thash' => $tokenHash]);
    $tokenUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tokenUser) {
        $tokenValid = true;
    } else {
        $errorMsg = 'This password reset link is invalid, expired, or has already been used.';
    }
} else {
    $errorMsg = 'No password reset token provided.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/auth/reset_password.php?token=' . urlencode($rawToken));
    }

    $newPassword     = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 8) {
        set_flash('error', 'Password must be at least 8 characters long.');
        redirect('pages/auth/reset_password.php?token=' . urlencode($rawToken));
    }

    if ($newPassword !== $confirmPassword) {
        set_flash('error', 'Passwords do not match.');
        redirect('pages/auth/reset_password.php?token=' . urlencode($rawToken));
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $userId  = (int) $tokenUser['user_id'];

    // 1. Update user password
    $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
        ->execute([':hash' => $newHash, ':id' => $userId]);

    // 2. Mark token consumed
    $pdo->prepare('UPDATE password_reset_tokens SET consumed_at = NOW() WHERE id = :id')
        ->execute([':id' => $tokenUser['id']]);

    // 3. Clear session
    session_unset();

    Audit::record('auth.password_reset_completed', 'user', $userId, [
        'email' => $tokenUser['email'],
    ], $userId, $tokenUser['role']);

    set_flash('success', 'Your password has been successfully reset! You can now log in.');
    redirect('pages/auth/login.php');
}

$page_title = 'Reset Password - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 480px; margin: 3rem auto; padding: 0 1rem;">
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="margin-bottom: 0.75rem; color: #ea580c; display: flex; justify-content: center;"><?= icon('shield-check', 'lucide-xl') ?></div>
            <h1 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">Set New Password</h1>
            <?php if ($tokenValid): ?>
                <p style="font-size: 0.85rem; color: #64748b; margin: 0;">
                    Resetting password for <strong><?= e($tokenUser['email']) ?></strong>.
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$tokenValid): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; line-height: 1.4;">
                <strong>Link Error:</strong> <?= e($errorMsg) ?>
            </div>
            <a href="<?= e(url('pages/auth/forgot_password.php')) ?>" class="btn btn-primary" style="display: block; text-align: center; text-decoration: none; padding: 0.65rem;">
                Request a New Reset Link &rarr;
            </a>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($rawToken) ?>">

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                        New Password (minimum 8 characters)
                    </label>
                    <input type="password" name="new_password" minlength="8" class="form-control" required style="width: 100%;">
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                        Confirm New Password
                    </label>
                    <input type="password" name="confirm_password" minlength="8" class="form-control" required style="width: 100%;">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-weight: 600;">
                    Confirm &amp; Reset Password
                </button>
            </form>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
