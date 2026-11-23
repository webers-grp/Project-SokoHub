<?php
/**
 * SokoHub OTP Verification Page & 6-Digit PIN Widget
 * Verifies email activation and one-time codes with countdown timer (10:00) and Resend functionality.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$userId = (int) ($_SESSION['pending_otp_user_id'] ?? 0);
$email = (string) ($_SESSION['pending_otp_email'] ?? '');
$purpose = (string) ($_SESSION['pending_otp_purpose'] ?? 'registration');

$pdo = get_db_connection();
Security::ensureSchema();

// Fallback: if session expired but user passed email via query/cookie, check pending user
if ($userId === 0 && !empty($_GET['email'])) {
    $searchEmail = strtolower(trim((string) $_GET['email']));
    $stmt = $pdo->prepare("SELECT id, email, status FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $searchEmail]);
    $u = $stmt->fetch();
    if ($u && $u['status'] === 'pending_verification') {
        $userId = (int) $u['id'];
        $email = $u['email'];
        $_SESSION['pending_otp_user_id'] = $userId;
        $_SESSION['pending_otp_email'] = $email;
        $_SESSION['pending_otp_purpose'] = 'registration';
    }
}

if ($userId === 0) {
    set_flash('warning', 'Please sign in or register to verify your account.');
    redirect('pages/auth/login.php');
}

$errors = [];
$successMsg = '';

// Handle POST actions: verify or resend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'verify'));

        if ($action === 'resend') {
            $newCode = Security::generateOtp($userId, $purpose, 600);
            Security::enqueueJob($pdo, 'send_email', [
                'to'       => $email,
                'template' => 'otp_resend',
                'code'     => $newCode,
                'subject'  => 'Your New SokoHub Verification Code',
            ]);
            Security::logNotification($pdo, $userId, 'email', 'otp_resend', $email);
            set_flash('success', 'A new 6-digit code has been sent to ' . htmlspecialchars($email));
            redirect('pages/auth/verify_otp.php');
        } else {
            // Reconstruct 6-digit code from 6 separate box inputs or single code field
            $code = '';
            if (!empty($_POST['digit']) && is_array($_POST['digit'])) {
                $code = implode('', array_slice($_POST['digit'], 0, 6));
            } else {
                $code = trim((string) ($_POST['code'] ?? ''));
            }

            $verifyResult = Security::verifyOtp($userId, $purpose, $code);

            if ($verifyResult['ok']) {
                // Fetch updated user and log them in
                $stmtUser = $pdo->prepare("SELECT id, full_name, email, phone, role FROM users WHERE id = :id LIMIT 1");
                $stmtUser->execute([':id' => $userId]);
                $user = $stmtUser->fetch();

                if ($user) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id'        => (int) $user['id'],
                        'full_name' => $user['full_name'],
                        'email'     => $user['email'],
                        'phone'     => $user['phone'],
                        'role'      => $user['role'],
                    ];
                }

                unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_purpose']);
                Audit::record('auth.otp_verified', 'user', $userId, ['purpose' => $purpose, 'email' => $email]);

                set_flash('success', 'Email verified successfully! Welcome to SokoHub, ' . ($user['full_name'] ?? ''));
                redirect('pages/dashboard/dashboard.php');
            } else {
                $errors[] = $verifyResult['error'];
            }
        }
    }
}

// Opportunistically flush pending jobs so email is dispatched immediately without manual worker
try {
    Security::triggerOpportunisticDispatch($pdo, 2);
} catch (Throwable $_) {}

$hasSmtp = Security::hasSmtpConfigured();

// Mask email for display: e.g. j***n@example.com
$maskedEmail = $email;
if (str_contains($email, '@')) {
    [$namePart, $domainPart] = explode('@', $email, 2);
    $len = mb_strlen($namePart);
    if ($len <= 2) {
        $maskedEmail = substr($namePart, 0, 1) . '*@' . $domainPart;
    } else {
        $maskedEmail = substr($namePart, 0, 1) . str_repeat('*', min(5, $len - 2)) . substr($namePart, -1) . '@' . $domainPart;
    }
}

// In local development / XAMPP, retrieve the generated code for convenient testing
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)
    || (PHP_SAPI === 'cli-server') || empty($_SERVER['SERVER_NAME']);
$devOtpCode = null;
if ($isLocal && $userId > 0) {
    try {
        $stmtJob = $pdo->prepare("SELECT payload FROM jobs WHERE job_type IN ('send_email', 'email') ORDER BY id DESC LIMIT 10");
        $stmtJob->execute();
        while ($jRow = $stmtJob->fetch()) {
            $p = json_decode((string) $jRow['payload'], true);
            if (!empty($p['code']) && !empty($p['to']) && strtolower($p['to']) === strtolower($email)) {
                $devOtpCode = (string) $p['code'];
                break;
            }
        }
    } catch (Throwable $_) {}
}

$pageTitle = 'SokoHub | Verify 6-Digit Code';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space" style="max-width: 580px; margin: 0 auto;">
    <div class="card" style="padding: 2.25rem; border-radius: var(--radius); box-shadow: var(--shadow-md); background: var(--bg-surface);">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: #e8f5e9; color: var(--green-dark); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                <?= icon('shield-check', 'lucide-lg') ?>
            </div>
            <h1 style="font-size: 1.65rem; color: var(--green-dark); margin-bottom: 0.35rem;">Enter Verification Code</h1>
            <p class="muted" style="font-size: 0.95rem;">
                We sent a 6-digit confirmation code to <strong><?= e($maskedEmail) ?></strong>.
                Enter the code below to verify your account.
            </p>
        </div>

        <?php if ($hasSmtp): ?>
            <div class="alert alert-success" style="display: flex; align-items: flex-start; gap: 0.65rem; margin-bottom: 1.25rem; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.85rem 1rem;">
                <?= icon('mail', 'lucide-sm', 'emerald') ?>
                <div style="font-size: 0.9rem; color: #065f46; line-height: 1.4;">
                    <strong>Verification Email Sent!</strong> A confirmation email has been dispatched directly to <strong><?= e($email) ?></strong> via SMTP. Please check your inbox and spam folder.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                <div>
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- OTP 6-Digit PIN Input Form -->
        <form action="<?= e(url('pages/auth/verify_otp.php')) ?>" method="POST" id="otpForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="code" id="fullOtpCode" value="">

            <div style="margin: 1.75rem 0;">
                <label style="display: block; text-align: center; font-weight: 600; margin-bottom: 0.75rem; color: var(--text);">
                    Security PIN
                </label>
                <div class="otp-container" style="display: flex; gap: 0.6rem; justify-content: center;">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text"
                               name="digit[]"
                               class="otp-digit"
                               maxlength="1"
                               pattern="[0-9]"
                               inputmode="numeric"
                               autocomplete="off"
                               required
                               data-index="<?= $i ?>"
                               style="width: 50px; height: 58px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #cbd5e1; border-radius: 10px; background: #f8fafc; transition: all 0.2s ease;">
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Countdown Timer Display (10:00) -->
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div id="countdownBox" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.85rem; border-radius: 20px; background: #f1f5f9; color: var(--text); font-size: 0.9rem; font-weight: 600;">
                    <?= icon('clock', 'lucide-sm', 'amber') ?>
                    <span>Code expires in <span id="timerText">10:00</span></span>
                </div>
                <div id="expiredNotice" style="display: none; color: #dc2626; font-weight: 600; margin-top: 0.5rem; font-size: 0.9rem;">
                    Verification code has expired. Please click "Resend Code".
                </div>
            </div>

            <button type="submit" id="btnVerify" class="btn btn-primary btn-block" style="font-size: 1.05rem; padding: 0.75rem;">
                <?= icon('check-circle') ?>
                <span>Verify &amp; Activate Account</span>
            </button>
        </form>

        <!-- Resend OTP Form -->
        <div style="text-align: center; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e2e8f0;">
            <p class="muted small" style="margin-bottom: 0.6rem;">Didn't receive the verification email?</p>
            <form action="<?= e(url('pages/auth/verify_otp.php')) ?>" method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="resend">
                <button type="submit" id="btnResend" class="btn btn-outline btn-sm">
                    <?= icon('refresh-cw', 'lucide-xs') ?>
                    <span>Resend OTP</span>
                </button>
            </form>
            <div style="margin-top: 1rem;">
                <a href="<?= e(url('pages/auth/login.php')) ?>" class="small muted">
                    <?= icon('arrow-left', 'lucide-xs') ?> Return to Sign In
                </a>
            </div>
        </div>

        <?php if ($isLocal && !empty($devOtpCode)): ?>
            <?php if ($hasSmtp): ?>
                <details style="margin-top: 1.5rem; text-align: center;">
                    <summary style="font-size: 0.85rem; color: #64748b; cursor: pointer; font-weight: 600;">
                        <?= icon('help-circle', 'lucide-xs') ?> Trouble receiving the email? Click for local backup code
                    </summary>
                    <div class="demo-box" style="margin-top: 0.75rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 1rem; text-align: center;">
                        <p class="small muted" style="margin: 0 0 0.5rem; line-height: 1.4;">
                            Local dev backup code retrieved from background job queue:
                        </p>
                        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                            <code id="devOtpVal" style="font-size: 1.4rem; font-weight: 800; color: #b45309; letter-spacing: 3px; background: #ffffff; padding: 0.25rem 0.75rem; border-radius: 6px; border: 1px dashed #d97706;"><?= e($devOtpCode) ?></code>
                            <button type="button" class="btn btn-outline btn-xs" id="btnCopyOtp" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                                <?= icon('copy', 'lucide-xs') ?> Autofill
                            </button>
                        </div>
                    </div>
                </details>
            <?php else: ?>
                <div class="demo-box" style="margin-top: 1.5rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 1rem; text-align: center;">
                    <div style="font-size: 0.88rem; color: #92400e; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.4rem; margin-bottom: 0.35rem;">
                        <?= icon('info', 'lucide-xs', 'amber') ?>
                        <span>Local Development / Simulation Mode</span>
                    </div>
                    <p class="small muted" style="margin: 0 0 0.5rem; line-height: 1.4;">
                        Outbound SMTP is not configured in <code>config/db.local.php</code>. Your generated verification code is:
                    </p>
                    <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <code id="devOtpVal" style="font-size: 1.4rem; font-weight: 800; color: #b45309; letter-spacing: 3px; background: #ffffff; padding: 0.25rem 0.75rem; border-radius: 6px; border: 1px dashed #d97706;"><?= e($devOtpCode) ?></code>
                        <button type="button" class="btn btn-outline btn-xs" id="btnCopyOtp" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                            <?= icon('copy', 'lucide-xs') ?> Autofill
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            <script>
            document.getElementById('btnCopyOtp')?.addEventListener('click', function () {
                const code = <?= json_encode((string) $devOtpCode) ?>;
                const digits = document.querySelectorAll('.otp-digit');
                code.split('').forEach((c, idx) => {
                    if (digits[idx]) digits[idx].value = c;
                });
                document.getElementById('fullOtpCode').value = code;
                this.textContent = 'Autofilled!';
            });
            </script>
        <?php endif; ?>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const digits = document.querySelectorAll('.otp-digit');
    const form = document.getElementById('otpForm');
    const fullInput = document.getElementById('fullOtpCode');
    const timerText = document.getElementById('timerText');
    const expiredNotice = document.getElementById('expiredNotice');
    const btnVerify = document.getElementById('btnVerify');

    if (digits.length > 0) {
        digits[0].focus();
    }

    // Individual box navigation, paste handling & auto-advance
    digits.forEach((digit, index) => {
        digit.addEventListener('focus', function () {
            this.select();
            this.style.borderColor = 'var(--green-main, #1b871b)';
            this.style.background = '#ffffff';
            this.style.boxShadow = '0 0 0 3px rgba(27, 135, 27, 0.15)';
        });

        digit.addEventListener('blur', function () {
            this.style.borderColor = '#cbd5e1';
            this.style.background = '#f8fafc';
            this.style.boxShadow = 'none';
        });

        digit.addEventListener('input', function (e) {
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

        // Paste 6-digit code support
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

    // Countdown Timer (10:00 -> 600 seconds)
    const totalSeconds = 600;
    const storageKey = 'sokohub_otp_start_' + <?= json_encode((string) $userId) ?>;
    let startTime = parseInt(sessionStorage.getItem(storageKey) || '0', 10);

    const now = Math.floor(Date.now() / 1000);
    if (!startTime || (now - startTime) > totalSeconds) {
        startTime = now;
        sessionStorage.setItem(storageKey, startTime.toString());
    }

    function updateTimer() {
        const currentTime = Math.floor(Date.now() / 1000);
        const elapsed = currentTime - startTime;
        const remaining = Math.max(0, totalSeconds - elapsed);

        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        const fmt = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (timerText) {
            timerText.textContent = fmt;
        }

        if (remaining <= 0) {
            clearInterval(timerInterval);
            if (expiredNotice) expiredNotice.style.display = 'block';
            if (btnVerify) {
                btnVerify.disabled = true;
                btnVerify.style.opacity = '0.6';
            }
        }
    }

    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
