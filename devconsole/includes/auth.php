<?php
/**
 * Developer Console Authentication & Session Security Guard
 * Uses separate session cookie `SOKOHUB_DEV_SESSID` completely isolated from storefront users.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/audit.php';

const DEV_SESSION_NAME = 'SOKOHUB_DEV_SESSID';
const DEV_IDLE_TIMEOUT = 900;     // 15 minutes
const DEV_MAX_LIFETIME = 14400;   // 4 hours
const DEV_REAUTH_WINDOW = 600;    // 10 minutes for dangerous actions

function dev_session_init(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== DEV_SESSION_NAME) {
            session_write_close();
        } else {
            dev_check_session_lifetime();
            return;
        }
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    ini_set('session.name', DEV_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/devconsole',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
    dev_check_session_lifetime();
}

function dev_check_session_lifetime(): void
{
    $now = time();

    // Check absolute timeout
    if (isset($_SESSION['dev_created_at']) && ($now - $_SESSION['dev_created_at']) > DEV_MAX_LIFETIME) {
        dev_logout('Session expired (maximum 4-hour lifetime reached). Please sign in again.');
        return;
    }

    // Check idle timeout
    if (isset($_SESSION['dev_last_activity']) && ($now - $_SESSION['dev_last_activity']) > DEV_IDLE_TIMEOUT) {
        dev_logout('Session expired due to 15 minutes of inactivity. Please sign in again.');
        return;
    }

    $_SESSION['dev_last_activity'] = $now;
}

function dev_current_user(): ?array
{
    return $_SESSION['dev_user'] ?? null;
}

function dev_is_authenticated(): bool
{
    return !empty($_SESSION['dev_user']) && !empty($_SESSION['dev_2fa_passed']);
}

function dev_require_auth(): void
{
    dev_session_init();

    if (empty($_SESSION['dev_user'])) {
        header('Location: ' . dev_url('login.php'));
        exit;
    }

    // Check 2FA requirement
    if (empty($_SESSION['dev_2fa_passed'])) {
        if (empty($_SESSION['dev_user']['totp_enabled'])) {
            header('Location: ' . dev_url('setup_2fa.php'));
        } else {
            header('Location: ' . dev_url('verify_2fa.php'));
        }
        exit;
    }
}

function dev_has_role(string $userRole, string|array $allowedRoles): bool
{
    $hierarchy = [
        'owner'     => ['owner', 'developer', 'viewer'],
        'developer' => ['developer', 'viewer'],
        'viewer'    => ['viewer'],
    ];

    $allowed = (array) $allowedRoles;
    $userPermissions = $hierarchy[$userRole] ?? [];

    foreach ($allowed as $role) {
        if (in_array($role, $userPermissions, true)) {
            return true;
        }
    }
    return false;
}

function dev_require_role(string|array $allowedRoles): void
{
    dev_require_auth();
    $user = dev_current_user();
    $roles = (array) $allowedRoles;

    if (!$user || !dev_has_role($user['role'] ?? '', $roles)) {
        DevAudit::log('security', 'access_denied', $_SERVER['REQUEST_URI'] ?? '', [
            'required_roles' => $roles,
            'current_role'   => $user['role'] ?? 'none',
        ], 'blocked');

        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;padding:3rem;text-align:center;">'
           . '<h2>403 Forbidden</h2><p>Your developer role (' . htmlspecialchars($user['role'] ?? 'none') . ') does not have permission to access this resource.</p>'
           . '<p><a href="' . dev_url('dashboard.php') . '">&larr; Return to Dashboard</a></p></body></html>';
        exit;
    }
}

function dev_reauth_valid(): bool
{
    return isset($_SESSION['dev_reauth_until']) && $_SESSION['dev_reauth_until'] > time();
}

function dev_require_reauth(string $returnUrl): void
{
    if (!dev_reauth_valid()) {
        header('Location: ' . dev_url('reauth.php?return=' . urlencode($returnUrl)));
        exit;
    }
}

function dev_csrf_token(): string
{
    if (empty($_SESSION['dev_csrf_token'])) {
        $_SESSION['dev_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['dev_csrf_token'];
}

function dev_verify_csrf(?string $token): bool
{
    if (!$token || empty($_SESSION['dev_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['dev_csrf_token'], $token);
}

function dev_set_flash(string $type, string $message): void
{
    $_SESSION['dev_flash'] = ['type' => $type, 'message' => $message];
}

function dev_get_flash(): ?array
{
    if (!empty($_SESSION['dev_flash'])) {
        $flash = $_SESSION['dev_flash'];
        unset($_SESSION['dev_flash']);
        return $flash;
    }
    return null;
}

function dev_logout(?string $notice = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['dev_user'])) {
            DevAudit::log('auth', 'logout', null, ['reason' => $notice ?: 'User manual sign out']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                DEV_SESSION_NAME,
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    if ($notice) {
        session_name(DEV_SESSION_NAME);
        session_start();
        dev_set_flash('info', $notice);
    }

    header('Location: ' . dev_url('login.php'));
    exit;
}
