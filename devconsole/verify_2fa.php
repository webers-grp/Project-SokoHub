<?php
/**
 * Developer Console 2FA Verification Challenge
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/audit.php';

dev_session_init();

$dev = $_SESSION['dev_user'] ?? null;
if (!$dev) {
    header('Location: ' . dev_url('login.php'));
    exit;
}

if (!empty($_SESSION['dev_2fa_passed'])) {
    header('Location: ' . dev_url('dashboard.php'));
    exit;
}

if (empty($dev['totp_enabled'])) {
    header('Location: ' . dev_url('setup_2fa.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));
        $clientIp = Security::getClientIp();

        // Rate check (5 tries / 10 mins)
        $rate = RateLimiter::check('dev_2fa:' . $dev['username'], $clientIp, 5, 600);
        if (!$rate['allowed']) {
            $mins = ceil($rate['retry_after'] / 60);
            $error = "Too many failed 2FA attempts. Locked out for {$mins} minute(s).";
            DevAudit::log('security', '2fa_lockout', $dev['username'], ['ip' => $clientIp], 'blocked');
        } elseif (Totp::verifyCode($dev['totp_secret'], $code)) {
            $_SESSION['dev_2fa_passed'] = true;
            $_SESSION['dev_reauth_until'] = time() + DEV_REAUTH_WINDOW;
            DevAudit::log('auth', '2fa_verified', $dev['username'], ['ip' => $clientIp], 'success');
            header('Location: ' . dev_url('dashboard.php'));
            exit;
        } else {
            $error = 'Invalid 6-digit TOTP code. Please try again.';
            DevAudit::log('auth', '2fa_failed', $dev['username'], ['ip' => $clientIp], 'failed');
        }
    }
}

$csrfToken = dev_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Two-Factor Authentication | SokoHub Ops</title>
    <link rel="stylesheet" href="<?= dev_url('assets/css/console.css?v=' . time()) ?>">
    <script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
    <script src="<?= dev_url('assets/js/console.js?v=' . time()) ?>" defer></script>
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #090d16; }
        .verify-box { width: 100%; max-width: 400px; padding: 2rem; background: #1e293b; border: 1px solid #334155; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
    </style>
</head>
<body>
<div class="verify-box">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: center; margin-bottom: 0.5rem; color: #3b82f6;">
            <?= icon('smartphone', 'lucide-xl') ?>
        </div>
        <h1 style="font-size: 1.25rem; font-weight: 700; color: #fff;">Two-Factor Challenge</h1>
        <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">
            Enter the 6-digit verification code from your authenticator application.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= icon('alert-circle', 'lucide-sm') ?>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <input type="text" id="code" name="code" class="form-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" autofocus style="text-align: center; font-size: 1.5rem; letter-spacing: 0.35em; font-family: var(--font-mono); padding: 0.75rem;">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.65rem;">
            <?= icon('check-circle', 'lucide-xs') ?> Verify Code
        </button>
    </form>

    <div style="text-align: center; margin-top: 1.25rem;">
        <a href="<?= dev_url('logout.php') ?>" style="font-size: 0.8rem; color: #64748b; text-decoration: none;">&larr; Switch Account / Sign Out</a>
    </div>
</div>
</body>
</html>
