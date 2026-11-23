<?php
/**
 * SokoHub Admin Two-Factor Authentication (2FA) Challenge
 * Required for users with role='admin' and two_factor_enabled=1.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$adminId = (int) ($_SESSION['2fa_user_id'] ?? 0);
$adminEmail = (string) ($_SESSION['2fa_email'] ?? '');
$rememberMe = !empty($_SESSION['2fa_remember']);

if ($adminId === 0) {
    set_flash('warning', 'Please sign in first to access the two-factor challenge.');
    redirect('pages/auth/login.php');
}

$pdo = get_db_connection();
Security::ensureSchema();

// Verify user exists and is an admin
$stmt = $pdo->prepare("SELECT id, full_name, email, phone, role, status FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $adminId]);
$adminUser = $stmt->fetch();

if (!$adminUser || $adminUser['role'] !== 'admin' || $adminUser['status'] !== 'active') {
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_email'], $_SESSION['2fa_remember']);
    set_flash('error', 'Authentication failed or account inactive.');
    redirect('pages/auth/login.php');
}

$adminEmail = $adminUser['email'];
$errors = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'verify'));

        if ($action === 'resend') {
            $newCode = Security::generateOtp($adminId, 'admin_2fa', 600);
            Security::enqueueJob($pdo, 'send_email', [
                'to'       => $adminEmail,
                'template' => 'admin_2fa_resend',
                'code'     => $newCode,
                'subject'  => 'SokoHub Admin 2FA Verification Code',
            ]);
            Security::logNotification($pdo, $adminId, 'email', 'admin_2fa_resend', $adminEmail);
            set_flash('success', 'A new 2FA code has been sent to ' . htmlspecialchars($adminEmail));
            redirect('pages/auth/admin_2fa.php');
        } elseif ($action === 'cancel') {
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_email'], $_SESSION['2fa_remember']);
            redirect('pages/auth/login.php');
        } else {
            // Reconstruct 6-digit code
            $code = '';
            if (!empty($_POST['digit']) && is_array($_POST['digit'])) {
                $code = implode('', array_slice($_POST['digit'], 0, 6));
            } else {
                $code = trim((string) ($_POST['code'] ?? ''));
            }

            $verifyResult = Security::verifyOtp($adminId, 'admin_2fa', $code);

            if ($verifyResult['ok']) {
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id'        => (int) $adminUser['id'],
                    'full_name' => $adminUser['full_name'],
                    'email'     => $adminUser['email'],
                    'phone'     => $adminUser['phone'],
                    'role'      => $adminUser['role'],
                ];

                set_remember_email_cookie($rememberMe ? $adminUser['email'] : null);
                unset($_SESSION['2fa_user_id'], $_SESSION['2fa_email'], $_SESSION['2fa_remember']);

                Security::handleSuccessfulLogin($pdo, $adminId);
                Audit::record('auth.admin_2fa_success', 'user', $adminId, ['email' => $adminEmail]);

                set_flash('success', 'Two-Factor Authentication verified. Welcome back, ' . $adminUser['full_name'] . '!');
                redirect('pages/dashboard/dashboard.php');
            } else {
                Audit::record('auth.admin_2fa_failed', 'user', $adminId, ['email' => $adminEmail, 'reason' => $verifyResult['error']]);
                $errors[] = $verifyResult['error'];
            }
        }
    }
}

// Mask admin email
$maskedEmail = $adminEmail;
if (str_contains($adminEmail, '@')) {
    [$namePart, $domainPart] = explode('@', $adminEmail, 2);
    $len = mb_strlen($namePart);
    $maskedEmail = substr($namePart, 0, 1) . str_repeat('*', max(3, $len - 2)) . substr($namePart, -1) . '@' . $domainPart;
}

$pageTitle = 'SokoHub | Admin Two-Factor Authentication';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space" style="max-width: 540px; margin: 0 auto;">
    <div class="card" style="padding: 2.25rem; border-radius: var(--radius); box-shadow: var(--shadow-lg); background: var(--bg-surface); border-top: 4px solid #7c3aed;">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="width: 68px; height: 68px; border-radius: 50%; background: #f3e8ff; color: #7c3aed; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                <?= icon('shield-check', 'lucide-lg') ?>
            </div>
            <div style="margin-bottom: 0.4rem;">
                <span class="badge" style="background: #f3e8ff; color: #6b21a8; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px;">
                    <?= icon('lock', 'lucide-xs') ?> Administrator Security Challenge
                </span>
            </div>
            <h1 style="font-size: 1.6rem; color: var(--green-dark); margin: 0.5rem 0 0.35rem;">Two-Factor Authentication</h1>
            <p class="muted" style="font-size: 0.95rem; line-height: 1.5;">
                Privileged access requires second-factor authorization. A 6-digit security code has been dispatched to <strong><?= e($maskedEmail) ?></strong>.
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

        <!-- 2FA Verification Form -->
        <form action="<?= e(url('pages/auth/admin_2fa.php')) ?>" method="POST" id="admin2faForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="code" id="adminFullCode" value="">

            <div style="margin: 1.5rem 0;">
                <label style="display: block; text-align: center; font-weight: 600; margin-bottom: 0.75rem; color: var(--text);">
                    Enter 6-Digit Challenge Code
                </label>
                <div class="otp-container" style="display: flex; gap: 0.6rem; justify-content: center;">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text"
                               name="digit[]"
                               class="admin-digit"
                               maxlength="1"
                               pattern="[0-9]"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               required
                               data-index="<?= $i ?>"
                               style="width: 50px; height: 58px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #cbd5e1; border-radius: 10px; background: #faf5ff; transition: all 0.2s ease;">
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Timer / Expiry Indicator -->
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.85rem; border-radius: 20px; background: #f5f3ff; color: #6b21a8; font-size: 0.88rem; font-weight: 600;">
                    <?= icon('clock', 'lucide-xs') ?>
                    <span>Challenge valid for 10 minutes</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="background: #7c3aed; border-color: #7c3aed; font-size: 1.05rem; padding: 0.75rem;">
                <?= icon('shield-check') ?>
                <span>Verify &amp; Continue to Admin Panel</span>
            </button>
        </form>

        <!-- Actions: Resend Code & Cancel -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e2e8f0;">
            <form action="<?= e(url('pages/auth/admin_2fa.php')) ?>" method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn btn-outline btn-sm">
                    <?= icon('refresh-cw', 'lucide-xs') ?>
                    <span>Resend Code</span>
                </button>
            </form>

            <form action="<?= e(url('pages/auth/admin_2fa.php')) ?>" method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-outline-danger btn-sm" style="color: #dc2626; border-color: #fca5a5; background: transparent;">
                    <?= icon('x', 'lucide-xs') ?>
                    <span>Cancel Login</span>
                </button>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const digits = document.querySelectorAll('.admin-digit');
    const form = document.getElementById('admin2faForm');
    const fullInput = document.getElementById('adminFullCode');

    if (digits.length > 0) {
        digits[0].focus();
    }

    digits.forEach((digit, index) => {
        digit.addEventListener('focus', function () {
            this.select();
            this.style.borderColor = '#7c3aed';
            this.style.background = '#ffffff';
            this.style.boxShadow = '0 0 0 3px rgba(124, 58, 237, 0.2)';
        });

        digit.addEventListener('blur', function () {
            this.style.borderColor = '#cbd5e1';
            this.style.background = '#faf5ff';
            this.style.boxShadow = 'none';
        });

        digit.addEventListener('input', function () {
            const val = this.value.replace(/[^0-9]/g, '');
            this.value = val ? val.charAt(val.length - 1) : '';

            if (this.value && index < digits.length - 1) {
                digits[index + 1].focus();
            }
            updateFullCode();
        });

        digit.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && index > 0) {
                digits[index - 1].focus();
                digits[index - 1].value = '';
                updateFullCode();
            } else if (e.key === 'ArrowLeft' && index > 0) {
                digits[index - 1].focus();
            } else if (e.key === 'ArrowRight' && index < digits.length - 1) {
                digits[index + 1].focus();
            }
        });

        digit.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim();
            const cleanDigits = pasteData.replace(/[^0-9]/g, '').slice(0, 6);

            cleanDigits.split('').forEach((char, i) => {
                if (digits[i]) {
                    digits[i].value = char;
                }
            });

            if (cleanDigits.length > 0) {
                const nextFocus = Math.min(cleanDigits.length, digits.length - 1);
                digits[nextFocus].focus();
            }
            updateFullCode();
        });
    });

    function updateFullCode() {
        let code = '';
        digits.forEach(d => { code += d.value; });
        if (fullInput) {
            fullInput.value = code;
        }
    }

    if (form) {
        form.addEventListener('submit', function () {
            updateFullCode();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
