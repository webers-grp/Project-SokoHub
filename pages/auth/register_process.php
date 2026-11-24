<?php
/**
 * SokoHub User Registration Controller
 * Processes registrations across buyer, seller, and delivery roles,
 * creates users with status 'pending_verification', generates a 6-digit OTP,
 * hashes it with HMAC-SHA256, enqueues the dispatch job, and redirects to verify_otp.php.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    redirect('pages/auth/register.php');
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $error = 'Invalid security token. Please refresh the page and try again.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    set_flash('error', $error);
    redirect('pages/auth/register.php');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$role = strtolower(trim((string) ($_POST['role'] ?? 'buyer')));

$validRoles = ['buyer', 'seller', 'delivery'];
if (!in_array($role, $validRoles, true)) {
    $role = 'buyer';
}

$errors = [];

// Field validations
if (mb_strlen($fullName) < 3) {
    $errors[] = 'Full name must be at least 3 characters long.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}
if (!preg_match('/^\+?[0-9\s\-]{9,20}$/', $phone)) {
    $errors[] = 'Phone number must contain 9 to 20 digits (e.g. +254 712 345 678).';
}
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}
if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!empty($errors)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
    set_flash('error', implode(' ', $errors));
    $backPage = match ($role) {
        'seller'   => 'pages/auth/register_seller.php',
        'delivery' => 'pages/auth/register_delivery.php',
        default    => 'pages/auth/register.php',
    };
    redirect($backPage);
}

Security::ensureSchema();
$pdo = get_db_connection();

// Check if email already registered
$stmt = $pdo->prepare('SELECT id, status, full_name FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['status'] === 'pending_verification') {
        // Resend OTP for existing unverified user
        $userId = (int) $existing['id'];
        $otpCode = Security::generateOtp($userId, 'registration', 600);

        Security::enqueueJob($pdo, 'send_email', [
            'to'       => $email,
            'template' => 'otp_registration',
            'code'     => $otpCode,
            'subject'  => 'SokoHub Account Verification Code',
        ]);
        Security::logNotification($pdo, $userId, 'email', 'otp_registration', $email);

        $_SESSION['pending_otp_user_id'] = $userId;
        $_SESSION['pending_otp_email'] = $email;
        $_SESSION['pending_otp_purpose'] = 'registration';

        set_flash('info', 'Your account is pending verification. We have sent a new 6-digit code to your email.');
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'redirect' => url('pages/auth/verify_otp.php')]);
            exit;
        }
        redirect('pages/auth/verify_otp.php');
    }

    $error = 'An account with this email address already exists. Please sign in instead.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    set_flash('error', $error);
    redirect('pages/auth/login.php');
}

// Insert new user with pending_verification
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

$stmtInsert = $pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
    VALUES (:name, :email, :phone, :hash, :role, 'pending_verification', NOW())
");
$stmtInsert->execute([
    ':name'  => $fullName,
    ':email' => $email,
    ':phone' => $phone,
    ':hash'  => $passwordHash,
    ':role'  => $role,
]);

$userId = (int) $pdo->lastInsertId();

// Role-specific profile initialization
if ($role === 'seller') {
    $businessName = trim((string) ($_POST['business_name'] ?? $fullName . ' Shop'));
    $businessType = trim((string) ($_POST['business_type'] ?? 'retail'));
    $town = trim((string) ($_POST['town'] ?? 'Kutus'));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $stall = trim((string) ($_POST['stall_location'] ?? ''));
    $isStudent = !empty($_POST['is_student_vendor']) ? 1 : 0;
    $studentReg = trim((string) ($_POST['student_reg_no'] ?? ''));
    $opHours = trim((string) ($_POST['operating_hours'] ?? '8:00 AM - 8:00 PM'));
    $waPhone = trim((string) ($_POST['whatsapp_phone'] ?? ''));

    $stmtSeller = $pdo->prepare("
        INSERT INTO seller_profiles (
            user_id, business_name, business_type, town,
            stall_location, is_student_vendor, student_reg_no,
            operating_hours, whatsapp_phone, description, created_at
        ) VALUES (
            :uid, :bname, :btype, :town,
            :stall, :is_std, :std_reg,
            :op_hrs, :wa, :desc, NOW()
        )
    ");
    $stmtSeller->execute([
        ':uid'     => $userId,
        ':bname'   => $businessName,
        ':btype'   => $businessType,
        ':town'    => $town,
        ':stall'   => $stall !== '' ? $stall : null,
        ':is_std'  => $isStudent,
        ':std_reg' => $isStudent && $studentReg !== '' ? $studentReg : null,
        ':op_hrs'  => $opHours !== '' ? $opHours : '8:00 AM - 8:00 PM',
        ':wa'      => $waPhone !== '' ? $waPhone : null,
        ':desc'    => $desc,
    ]);
} elseif ($role === 'delivery') {
    $transportMethod = trim((string) ($_POST['transport_method'] ?? 'motorbike'));
    $vehiclePlate = trim((string) ($_POST['vehicle_plate_number'] ?? ''));

    $stmtDelivery = $pdo->prepare("
        INSERT INTO delivery_profiles (user_id, transport_method, vehicle_plate_number)
        VALUES (:uid, :trans, :plate)
    ");
    $stmtDelivery->execute([
        ':uid'   => $userId,
        ':trans' => $transportMethod,
        ':plate' => $vehiclePlate,
    ]);
}

// Generate 6-digit OTP code using random_int(100000, 999999) & hash_hmac('sha256', $code, APP_SECRET)
$otpCode = Security::generateOtp($userId, 'registration', 600);

// Enqueue background email dispatch job and log notification
Security::enqueueJob($pdo, 'send_email', [
    'to'       => $email,
    'template' => 'otp_registration',
    'code'     => $otpCode,
    'subject'  => 'Welcome to SokoHub - Verify Your Account',
]);
Security::logNotification($pdo, $userId, 'email', 'otp_registration', $email);

Audit::record('auth.registered_pending', 'user', $userId, ['email' => $email, 'role' => $role]);

// Set verification session
$_SESSION['pending_otp_user_id'] = $userId;
$_SESSION['pending_otp_email'] = $email;
$_SESSION['pending_otp_purpose'] = 'registration';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'redirect' => url('pages/auth/verify_otp.php')]);
    exit;
}

set_flash('success', 'Your account has been created! Please enter the 6-digit verification code sent to ' . $email);
redirect('pages/auth/verify_otp.php');
