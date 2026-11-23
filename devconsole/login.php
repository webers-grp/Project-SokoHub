<?php
/**
 * Developer Console Dedicated Sign In
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';

dev_session_init();

if (dev_is_authenticated()) {
    header('Location: ' . dev_url('dashboard.php'));
    exit;
}

$pdo = get_db_connection();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $clientIp = Security::getClientIp();

        // Rate Limiter Check (5 attempts / 15 mins)
        $rateKey = 'dev_login:' . $username;
        $rate = RateLimiter::check($rateKey, $clientIp, 5, 900);
        if (!$rate['allowed']) {
            $mins = ceil($rate['retry_after'] / 60);
            $error = "Too many failed developer login attempts. Locked out for {$mins} minute(s).";
            DevAudit::log('security', 'dev_login_lockout', $username, ['ip' => $clientIp], 'blocked');
        } elseif ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM developers WHERE (username = :u OR email = :e) LIMIT 1');
            $stmt->execute([':u' => $username, ':e' => $username]);
            $dev = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dev || !password_verify($password, $dev['password_hash'])) {
                $error = 'Invalid developer credentials.';
                DevAudit::log('auth', 'dev_login_failed', $username, ['ip' => $clientIp], 'failed');
            } elseif ($dev['status'] !== 'active') {
                $error = 'This developer account has been suspended.';
                DevAudit::log('auth', 'dev_login_suspended', $username, ['ip' => $clientIp], 'blocked');
            } else {
                // Successful initial credentials validation
                $_SESSION['dev_user'] = [
                    'id'           => (int) $dev['id'],
                    'username'     => $dev['username'],
                    'email'        => $dev['email'],
                    'role'         => $dev['role'],
                    'totp_secret'  => $dev['totp_secret'],
                    'totp_enabled' => (bool) $dev['totp_enabled'],
                ];
                $_SESSION['dev_created_at']    = time();
                $_SESSION['dev_last_activity'] = time();
                $_SESSION['dev_2fa_passed']    = false;

                // Update last login IP
                $pdo->prepare('UPDATE developers SET last_login_at = NOW(), last_ip = :ip WHERE id = :id')
                    ->execute([':ip' => $clientIp, ':id' => $dev['id']]);

                if (!$dev['totp_enabled']) {
                    header('Location: ' . dev_url('setup_2fa.php'));
                } else {
                    header('Location: ' . dev_url('verify_2fa.php'));
                }
                exit;
            }
        }
    }
}

$csrfToken = dev_csrf_token();
$mpesaEnv = strtoupper(getenv('MPESA_ENV') ?: 'SANDBOX');
$isLive = ($mpesaEnv === 'PRODUCTION');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Sign In | SokoHub Ops Console</title>
    <link rel="stylesheet" href="<?= dev_url('assets/css/console.css?v=' . time()) ?>">
    <script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
    <script src="<?= dev_url('assets/js/console.js?v=' . time()) ?>" defer></script>
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #090d16; }
        .login-box { width: 100%; max-width: 400px; padding: 2rem; background: #1e293b; border: 1px solid #334155; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
    </style>
</head>
<body>
<div class="login-box">
    <div style="text-align: center; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: center; margin-bottom: 0.5rem; color: #3b82f6;">
            <?= icon('terminal', 'lucide-xl') ?>
        </div>
        <h1 style="font-size: 1.25rem; font-weight: 700; color: #fff;">SokoHub Developer Console</h1>
        <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">Restricted Internal Operations Gateway</p>
        <div style="margin-top: 0.75rem;">
            <span class="badge <?= $isLive ? 'badge-production' : 'badge-sandbox' ?>">
                &bull; <?= $isLive ? 'MPESA PRODUCTION' : 'MPESA SANDBOX' ?>
            </span>
        </div>
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
            <label class="form-label" for="username">Developer Username / Email</label>
            <input type="text" id="username" name="username" class="form-input" required autocomplete="username" autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password" class="form-input" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 0.5rem; padding: 0.65rem;">
            <?= icon('lock', 'lucide-xs') ?> Authenticate
        </button>
    </form>

    <div style="margin-top: 1.5rem; border-top: 1px solid #334155; padding-top: 1rem; font-size: 0.72rem; color: #64748b; text-align: center; line-height: 1.4;">
        Security Notice: All actions, IP addresses, and authorization attempts are logged to an immutable audit ledger.
    </div>
</div>
</body>
</html>
