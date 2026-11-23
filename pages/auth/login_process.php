<?php
/**
 * SokoHub Login Processing Controller
 * Handles login authentication, brute-force throttling, and Admin 2FA challenge initiation.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    redirect('pages/auth/login.php');
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $error = 'Invalid security token. Please refresh and try again.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    set_flash('error', $error);
    redirect('pages/auth/login.php');
}

$emailInput = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$rememberMe = !empty($_POST['remember_me']);

if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    set_flash('error', $error);
    redirect('pages/auth/login.php');
}

if ($password === '') {
    $error = 'Please enter your password.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    set_flash('error', $error);
    redirect('pages/auth/login.php');
}

$pdo = get_db_connection();
$result = login_user($pdo, $emailInput, $password, $rememberMe);

if (!empty($result['requires_2fa'])) {
    Audit::record('auth.login_2fa_prompted', 'user', (int) $_SESSION['2fa_user_id'], ['email' => $emailInput]);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'requires_2fa' => true, 'redirect' => url('pages/auth/admin_2fa.php')]);
        exit;
    }
    redirect('pages/auth/admin_2fa.php');
}

if (!empty($result['requires_verification'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'requires_verification' => true, 'error' => $result['error'], 'redirect' => url('pages/auth/verify_otp.php')]);
        exit;
    }
    set_flash('info', $result['error']);
    redirect('pages/auth/verify_otp.php');
}

if ($result['ok']) {
    Audit::record('auth.login_success', 'user', (int) $result['user']['id'], ['email' => $emailInput, 'role' => $result['user']['role']]);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => url('pages/dashboard/dashboard.php')]);
        exit;
    }
    set_flash('success', 'Welcome back, ' . $result['user']['full_name'] . '!');
    redirect('pages/dashboard/dashboard.php');
}

Audit::record('auth.login_failed', 'user', null, ['email' => $emailInput, 'reason' => $result['error']]);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

set_flash('error', $result['error']);
redirect('pages/auth/login.php');
