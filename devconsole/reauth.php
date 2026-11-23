<?php
/**
 * Developer Console Re-Authentication for Sensitive Operations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/audit.php';

dev_require_auth();

$dev = dev_current_user();
$returnUrl = $_GET['return'] ?? dev_url('dashboard.php');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $pdo = get_db_connection();
        $code = trim((string) ($_POST['totp_code'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $authOk = false;

        // Either valid TOTP code OR password
        if ($code !== '' && Totp::verifyCode($dev['totp_secret'], $code)) {
            $authOk = true;
        } elseif ($password !== '') {
            $stmt = $pdo->prepare('SELECT password_hash FROM developers WHERE id = :id');
            $stmt->execute([':id' => $dev['id']]);
            $hash = $stmt->fetchColumn();
            if ($hash && password_verify($password, $hash)) {
                $authOk = true;
            }
        }

        if ($authOk) {
            $_SESSION['dev_reauth_until'] = time() + DEV_REAUTH_WINDOW;
            DevAudit::log('security', 'reauth_success', $returnUrl, [], 'success');
            header('Location: ' . $returnUrl);
            exit;
        } else {
            $error = 'Invalid password or TOTP verification code.';
            DevAudit::log('security', 'reauth_failed', $returnUrl, [], 'failed');
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
    <title>Confirm Security Authorization | SokoHub Ops</title>
    <link rel="stylesheet" href="<?= dev_url('assets/css/console.css?v=' . time()) ?>">
    <script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #090d16; }
        .reauth-box { width: 100%; max-width: 440px; padding: 2rem; background: #1e293b; border: 1px solid #dc2626; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
    </style>
</head>
<body>
<div class="reauth-box">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: center; margin-bottom: 0.5rem; color: #ef4444;">
            <?= icon('shield-alert', 'lucide-xl') ?>
        </div>
        <h1 style="font-size: 1.25rem; font-weight: 700; color: #fff;">Confirm Authorization</h1>
        <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">
            The requested action requires elevated privileges. Please re-authenticate as <strong><?= htmlspecialchars($dev['username']) ?></strong>.
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
            <label class="form-label" for="password">Account Password</label>
            <input type="password" id="password" name="password" class="form-input" placeholder="Enter your developer password">
        </div>

        <div style="text-align: center; margin: 0.75rem 0; font-size: 0.75rem; color: #64748b;">— OR —</div>

        <div class="form-group">
            <label class="form-label" for="totp_code">Current 6-Digit TOTP Code</label>
            <input type="text" id="totp_code" name="totp_code" class="form-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" style="text-align:center; font-family:var(--font-mono); font-size:1.1rem; letter-spacing:0.2em;">
        </div>

        <button type="submit" class="btn btn-danger" style="width: 100%; justify-content: center; padding: 0.65rem;">
            <?= icon('shield-check', 'lucide-xs') ?> Verify &amp; Unlock Action
        </button>
    </form>

    <div style="text-align: center; margin-top: 1.25rem;">
        <a href="<?= dev_url('dashboard.php') ?>" style="font-size: 0.8rem; color: #64748b; text-decoration: none;">&larr; Cancel and Return to Dashboard</a>
    </div>
</div>
</body>
</html>
