<?php
/**
 * SokoHub Authentication, Session & Cookie Manager
 * Demonstrates session_start(), password_hash(), password_verify(),
 * setcookie(), and role-based access control.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Start a hardened PHP session once per request and track visitor cookies.
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Record last visit timestamp in a standard HTTP cookie (7 days).
 */
function track_visit_cookie(): void
{
    if (headers_sent()) {
        return;
    }

    $now = date('Y-m-d H:i');
    setcookie('sokohub_last_visit', $now, [
        'expires'  => time() + (7 * 86400),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Save or clear the "Remember Me" email cookie.
 */
function set_remember_email_cookie(?string $email): void
{
    if (headers_sent()) {
        return;
    }

    if ($email !== null && $email !== '') {
        setcookie('sokohub_remember_email', $email, [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('sokohub_remember_email', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Remember the most recently filtered category slug in a cookie.
 */
function set_recent_category_cookie(string $slug): void
{
    if (headers_sent() || $slug === '') {
        return;
    }

    setcookie('sokohub_recent_category', $slug, [
        'expires'  => time() + (7 * 86400),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Retrieve authenticated user array from session or null.
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Check if a user is logged in.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

/**
 * Attempt user login using email + password_verify().
 * Returns ['ok' => bool, 'error' => ?string, 'user' => ?array].
 */
function login_user(PDO $pdo, string $email, string $password, bool $rememberMe = false): array
{
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, password_hash, role, status
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid email address or password.', 'user' => null];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Your account is currently suspended. Contact support.', 'user' => null];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user'] = [
        'id'        => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'phone'     => $user['phone'],
        'role'      => $user['role'],
    ];

    set_remember_email_cookie($rememberMe ? $user['email'] : null);

    return ['ok' => true, 'error' => null, 'user' => $_SESSION['user']];
}

/**
 * Destroy active session and clear session cookie cleanly.
 */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Require authentication before viewing a protected page.
 */
function require_login(): array
{
    if (!is_logged_in()) {
        set_flash('warning', 'Please sign in to access that page.');
        redirect('pages/auth/login.php');
    }

    $u = current_user();
    if (!empty($u['id'])) {
        // Throttled user activity touch for offline telephony detection
        if (empty($_SESSION['_last_seen_touch']) || $_SESSION['_last_seen_touch'] < (time() - 120)) {
            $_SESSION['_last_seen_touch'] = time();
            try {
                $pdo = get_db_connection();
                $pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = :id')->execute([':id' => (int) $u['id']]);
            } catch (Throwable $_) {}
        }
    }

    return $u;
}

/**
 * Require that the authenticated user holds one of the allowed roles.
 * Accepts either an array of allowed roles ['admin', 'seller'] or a single string 'admin'.
 *
 * @param array|string $allowedRoles
 * @return array The authenticated user data
 */
function require_role($allowedRoles): array
{
    $roles = is_array($allowedRoles) ? $allowedRoles : [(string) $allowedRoles];
    $user = require_login();

    if (!in_array($user['role'], $roles, true)) {
        set_flash('error', 'Access denied: your account role does not have permission for that section.');
        redirect('pages/dashboard/dashboard.php');
    }

    return $user;
}
