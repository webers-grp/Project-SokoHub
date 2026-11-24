<?php
/**
 * SokoHub Password Reset Page
 * Validates the 32-byte crypto reset token, renders client-side password strength meter,
 * updates the password_hash, marks token used, and redirects to login.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$errors = [];

$pdo = get_db_connection();
Security::ensureSchema();

$tokenRow = null;
if ($token !== '') {
    $tokenRow = Security::verifyPasswordResetToken($pdo, $token);
}

// Handle POST password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenRow) {
        $errors[] = 'Your password reset token is invalid or has expired. Please request a new link.';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $success = Security::consumePasswordResetToken($pdo, $token, $newPassword);

            if ($success) {
                Audit::record('auth.password_reset_success', 'user', (int) $tokenRow['user_id'], ['email' => $tokenRow['email']]);
                set_flash('success', 'Your password has been reset successfully! Please sign in with your new password.');
                redirect('pages/auth/login.php');
            } else {
                $errors[] = 'Unable to reset password. The link may have expired or already been used.';
            }
        }
    }
}

$pageTitle = 'SokoHub | Set New Password';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space" style="max-width: 580px; margin: 0 auto;">
    <div class="card" style="padding: 2.25rem; border-radius: 12px; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1); background: #ffffff;">

        <?php if (!$tokenRow && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <!-- Invalid / Expired Token State -->
            <div style="text-align: center;">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <?= icon('alert-triangle', 'lucide-lg') ?>
                </div>
                <h1 style="font-size: 1.6rem; color: #991b1b; margin-bottom: 0.5rem;">Reset Link Invalid or Expired</h1>
                <p class="muted" style="margin-bottom: 1.5rem; line-height: 1.5;">
                    This password reset link is invalid, has already been used, or has exceeded its 30-minute validity period.
                </p>
                <a href="<?= e(url('pages/auth/forgot_password.php')) ?>" class="btn btn-primary">
                    <?= icon('mail') ?>
                    <span>Request New Reset Link</span>
                </a>
                <div style="margin-top: 1.25rem;">
                    <a href="<?= e(url('pages/auth/login.php')) ?>" class="small muted">
                        <?= icon('arrow-left', 'lucide-xs') ?> Return to Sign In
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- Password Reset Form -->
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: #e8f5e9; color: #15803d; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                    <?= icon('lock', 'lucide-lg') ?>
                </div>
                <h1 style="font-size: 1.65rem; color: #15803d; margin-bottom: 0.35rem;">Create New Password</h1>
                <p class="muted" style="font-size: 0.95rem;">
                    Resetting password for <strong><?= e($tokenRow['email'] ?? '') ?></strong>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/reset_password.php')) ?>" method="POST" id="resetPasswordForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="form-group">
                    <label for="reset_password">New Password</label>
                    <input type="password"
                           id="reset_password"
                           name="password"
                           class="form-control"
                           required
                           minlength="8"
                           placeholder="Enter at least 8 characters">

                    <!-- Password Strength Meter Bar & Label -->
                    <div style="margin-top: 0.5rem;">
                        <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                            <div id="strengthBar" style="height: 100%; width: 0%; background: #ef4444; transition: width 0.3s ease, background-color 0.3s ease;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem;">
                            <span class="small muted">Strength: <strong id="strengthLabel">Too weak</strong></span>
                            <span class="small muted" id="strengthHint">Min 8 chars, mix numbers &amp; letters</span>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <label for="reset_confirm_password">Confirm New Password</label>
                    <input type="password"
                           id="reset_confirm_password"
                           name="confirm_password"
                           class="form-control"
                           required
                           placeholder="Re-enter your new password">
                    <div id="matchNotice" class="small" style="margin-top: 0.25rem; font-weight: 600; display: none;"></div>
                </div>

                <button type="submit" id="btnSubmitReset" class="btn btn-primary btn-block" style="padding: 0.75rem; margin-top: 1.25rem;">
                    <?= icon('check-circle') ?>
                    <span>Update Password &amp; Sign In</span>
                </button>
            </form>

            <div style="text-align: center; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                <a href="<?= e(url('pages/auth/login.php')) ?>" class="small muted">
                    <?= icon('arrow-left', 'lucide-xs') ?> Cancel and Return to Sign In
                </a>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pwd = document.getElementById('reset_password');
    const confirm = document.getElementById('reset_confirm_password');
    const bar = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    const matchNotice = document.getElementById('matchNotice');
    const btnSubmit = document.getElementById('btnSubmitReset');

    if (!pwd || !confirm) return;

    function checkStrength(val) {
        let score = 0;
        if (!val) return 0;
        if (val.length >= 8) score += 25;
        if (val.length >= 12) score += 15;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score += 20;
        if (/[0-9]/.test(val)) score += 20;
        if (/[^a-zA-Z0-9]/.test(val)) score += 20;
        return Math.min(100, score);
    }

    pwd.addEventListener('input', function () {
        const score = checkStrength(this.value);
        bar.style.width = score + '%';

        if (score < 40) {
            bar.style.backgroundColor = '#ef4444'; // Red
            label.textContent = 'Weak';
            label.style.color = '#ef4444';
        } else if (score < 75) {
            bar.style.backgroundColor = '#f59e0b'; // Amber
            label.textContent = 'Moderate';
            label.style.color = '#d97706';
        } else {
            bar.style.backgroundColor = '#10b981'; // Green
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
