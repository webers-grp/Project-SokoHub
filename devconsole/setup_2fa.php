<?php
/**
 * Developer Console Mandatory 2FA Enrollment
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

$pdo = get_db_connection();
$error = null;

// Ensure TOTP secret exists
if (empty($dev['totp_secret'])) {
    $dev['totp_secret'] = Totp::generateSecret(16);
    $pdo->prepare('UPDATE developers SET totp_secret = :s WHERE id = :id')
        ->execute([':s' => $dev['totp_secret'], ':id' => $dev['id']]);
    $_SESSION['dev_user']['totp_secret'] = $dev['totp_secret'];
}

$otpUri = Totp::getOtpAuthUri($dev['username'], $dev['totp_secret'], 'SokoHub Ops');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));
        if (Totp::verifyCode($dev['totp_secret'], $code)) {
            // Success: Enable 2FA
            $pdo->prepare('UPDATE developers SET totp_enabled = 1 WHERE id = :id')
                ->execute([':id' => $dev['id']]);
            $_SESSION['dev_user']['totp_enabled'] = true;
            $_SESSION['dev_2fa_passed'] = true;
            $_SESSION['dev_reauth_until'] = time() + DEV_REAUTH_WINDOW;

            DevAudit::log('auth', '2fa_enrolled', $dev['username'], [], 'success');
            dev_set_flash('success', 'Two-Factor Authentication successfully enrolled and active.');
            header('Location: ' . dev_url('dashboard.php'));
            exit;
        } else {
            $error = 'Invalid 6-digit TOTP code. Please ensure your device clock is synchronized.';
            DevAudit::log('auth', '2fa_enroll_failed', $dev['username'], [], 'failed');
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
    <title>Mandatory 2FA Enrollment | SokoHub Ops</title>
    <link rel="stylesheet" href="<?= dev_url('assets/css/console.css?v=' . time()) ?>">
    <script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
    <script src="<?= dev_url('assets/js/console.js?v=' . time()) ?>" defer></script>
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #090d16; }
        .setup-box { width: 100%; max-width: 480px; padding: 2rem; background: #1e293b; border: 1px solid #334155; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
    </style>
</head>
<body>
<div class="setup-box">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: center; margin-bottom: 0.5rem; color: #10b981;">
            <?= icon('shield-check', 'lucide-xl') ?>
        </div>
        <h1 style="font-size: 1.25rem; font-weight: 700; color: #fff;">Mandatory 2FA Enrollment</h1>
        <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">
            Developer accounts require RFC 6238 Time-Based One-Time Passwords (TOTP).
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= icon('alert-circle', 'lucide-sm') ?>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <div style="background: #090d16; border: 1px solid #334155; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem;">
        <div style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.5rem;">
            Step 1: Add to Authenticator App
        </div>
        <p style="font-size: 0.8rem; color: #cbd5e1; margin-bottom: 0.5rem;">
            Open Google Authenticator, Authy, or 1Password and enter this Secret Key manually:
        </p>
        <div style="display: flex; align-items: center; justify-content: space-between; background: #1e293b; padding: 0.5rem 0.75rem; border-radius: 4px; border: 1px dashed #475569;">
            <code style="font-size: 0.95rem; font-weight: 700; color: #38bdf8; letter-spacing: 0.1em;"><?= htmlspecialchars($dev['totp_secret']) ?></code>
            <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= htmlspecialchars($dev['totp_secret']) ?>">
                Copy
            </button>
        </div>
        <div style="margin-top: 0.75rem; font-size: 0.72rem; color: #64748b; word-break: break-all;">
            URI: <code><?= htmlspecialchars($otpUri) ?></code>
        </div>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <label class="form-label" for="code">Step 2: Enter 6-Digit Code from App</label>
            <input type="text" id="code" name="code" class="form-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" autofocus style="text-align: center; font-size: 1.25rem; letter-spacing: 0.3em; font-family: var(--font-mono);">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.65rem;">
            <?= icon('check', 'lucide-xs') ?> Verify &amp; Activate 2FA
        </button>
    </form>

    <div style="text-align: center; margin-top: 1.25rem;">
        <a href="<?= dev_url('logout.php') ?>" style="font-size: 0.8rem; color: #64748b; text-decoration: none;">&larr; Cancel and Sign Out</a>
    </div>
</div>
</body>
</html>
