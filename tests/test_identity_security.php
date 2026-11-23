<?php
/**
 * Test Suite: Identity, Security, Access Control & Background Infrastructure
 * Validates:
 * 1. Database schema and table structures
 * 2. Registration & 6-digit HMAC-SHA256 OTP verification
 * 3. Brute-force login throttling (email >= 5 in 15m, IP >= 20 in 10m)
 * 4. Admin 2FA challenge flow
 * 5. Password reset with 32-byte crypto token and 30m validity
 * 6. Background job queue worker and notification logs
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] {$label}\n";
    } else {
        $failed++;
        echo " [FAIL] {$label}\n";
    }
}

echo "=========================================================\n";
echo " SokoHub Identity & Security Infrastructure Test Suite\n";
echo "=========================================================\n";

$pdo = get_db_connection();
Security::ensureSchema();

// -----------------------------------------------------------------------------
// 1. Database Schema Verification
// -----------------------------------------------------------------------------
echo "\n--- 1. Testing Database Schema & Tables ---\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('users', $tables, true), 'Table `users` exists');
assert_true(in_array('otp_codes', $tables, true), 'Table `otp_codes` exists');
assert_true(in_array('password_reset_tokens', $tables, true), 'Table `password_reset_tokens` exists');
assert_true(in_array('login_attempts', $tables, true), 'Table `login_attempts` exists');
assert_true(in_array('jobs', $tables, true), 'Table `jobs` exists');
assert_true(in_array('notification_logs', $tables, true), 'Table `notification_logs` exists');

$userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('status', $userCols, true), 'Users table has `status` column');
assert_true(in_array('email_verified_at', $userCols, true), 'Users table has `email_verified_at` column');
assert_true(in_array('two_factor_enabled', $userCols, true), 'Users table has `two_factor_enabled` column');
assert_true(in_array('failed_login_count', $userCols, true), 'Users table has `failed_login_count` column');
assert_true(in_array('locked_until', $userCols, true), 'Users table has `locked_until` column');
assert_true(in_array('last_login_at', $userCols, true), 'Users table has `last_login_at` column');

$otpCols = $pdo->query("SHOW COLUMNS FROM otp_codes")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('code_hash', $otpCols, true) && in_array('expires_at', $otpCols, true) && in_array('used_at', $otpCols, true), 'otp_codes table has required columns');

$loginAttCols = $pdo->query("SHOW COLUMNS FROM login_attempts")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('ip_address', $loginAttCols, true) && in_array('user_agent', $loginAttCols, true) && in_array('success', $loginAttCols, true), 'login_attempts table has required columns');

// -----------------------------------------------------------------------------
// 2. Registration & OTP Flow Verification
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Registration & 6-Digit OTP Flow ---\n";

$testEmail = 'test_buyer_' . time() . '@sokohub.test';
$pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
    VALUES ('Test Buyer', :email, '+254711000111', :hash, 'buyer', 'pending_verification', NOW())
")->execute([':email' => $testEmail, ':hash' => password_hash('SecretPass@123', PASSWORD_BCRYPT)]);

$testUserId = (int) $pdo->lastInsertId();
assert_true($testUserId > 0, 'New user inserted with status pending_verification');

$otp = Security::generateOtp($testUserId, 'registration', 600);
assert_true(preg_match('/^\d{6}$/', $otp) === 1, "Generated 6-digit OTP: {$otp}");

// Verify code stored as HMAC-SHA256
$stmtOtp = $pdo->prepare("SELECT code_hash, expires_at, used_at FROM otp_codes WHERE user_id = :uid AND purpose = 'registration' ORDER BY id DESC LIMIT 1");
$stmtOtp->execute([':uid' => $testUserId]);
$storedOtp = $stmtOtp->fetch();

$expectedHash = hash_hmac('sha256', $otp, APP_SECRET);
assert_true(hash_equals($storedOtp['code_hash'], $expectedHash), 'Stored OTP matches HMAC-SHA256 with APP_SECRET');
assert_true(strtotime($storedOtp['expires_at']) > time(), 'OTP validity is in future (10 minutes)');

// Wrong code fails
$wrongResult = Security::verifyOtp($testUserId, 'registration', '000000');
assert_true($wrongResult['ok'] === false, 'Invalid OTP code correctly rejected');

// Correct code succeeds and activates account
$correctResult = Security::verifyOtp($testUserId, 'registration', $otp);
assert_true($correctResult['ok'] === true, 'Correct OTP successfully verified');

$stmtCheckUser = $pdo->prepare("SELECT status, email_verified_at FROM users WHERE id = :uid");
$stmtCheckUser->execute([':uid' => $testUserId]);
$updatedUser = $stmtCheckUser->fetch();
assert_true($updatedUser['status'] === 'active' && !empty($updatedUser['email_verified_at']), 'User status updated to `active` with timestamp in `email_verified_at`');

// -----------------------------------------------------------------------------
// 3. Brute-Force Login Throttling Verification
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Brute-Force Login Throttling ---\n";

$throttleEmail = 'brute_' . time() . '@sokohub.test';
$pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
    VALUES ('Brute Target', :email, '+254722000222', :hash, 'buyer', 'active', NOW())
")->execute([':email' => $throttleEmail, ':hash' => password_hash('CorrectPass@123', PASSWORD_BCRYPT)]);
$targetUid = (int) $pdo->lastInsertId();

$fakeIp = '192.168.100.50';

// Log 4 failed attempts - should not be throttled yet
for ($i = 1; $i <= 4; $i++) {
    Security::logLoginAttempt($pdo, $throttleEmail, $fakeIp, 'TestBrowser', false);
    Security::handleLoginFailure($pdo, $throttleEmail);
}
$throttleCheck1 = Security::checkLoginThrottling($pdo, $throttleEmail, $fakeIp);
assert_true($throttleCheck1['throttled'] === false, '4 failures: account not yet throttled');

// 5th failure triggers email throttling and 15-minute lock
Security::logLoginAttempt($pdo, $throttleEmail, $fakeIp, 'TestBrowser', false);
Security::handleLoginFailure($pdo, $throttleEmail);

$throttleCheck2 = Security::checkLoginThrottling($pdo, $throttleEmail, $fakeIp);
assert_true($throttleCheck2['throttled'] === true, '5th failure triggers throttling');
assert_true(str_contains($throttleCheck2['message'], 'Too many attempts, please try again in 15 minutes.'), 'Generic 15-minute throttling message returned');

// Verify user locked_until is set in database
$stmtLockCheck = $pdo->prepare("SELECT locked_until, failed_login_count FROM users WHERE id = :uid");
$stmtLockCheck->execute([':uid' => $targetUid]);
$lockedUser = $stmtLockCheck->fetch();
assert_true(!empty($lockedUser['locked_until']) && strtotime($lockedUser['locked_until']) > time(), 'User locked_until set into the future');

// Test IP throttling (>= 20 attempts from same IP within 10m)
$attackIp = '10.0.0.99';
for ($i = 1; $i <= 20; $i++) {
    Security::logLoginAttempt($pdo, 'random_' . $i . '@test.com', $attackIp, 'AttackerBot', false);
}
$ipThrottle = Security::checkLoginThrottling($pdo, 'unrelated@test.com', $attackIp);
assert_true($ipThrottle['throttled'] === true, '>= 20 failures from single IP triggers IP throttling');

// -----------------------------------------------------------------------------
// 4. Admin 2FA Challenge Flow Verification
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Admin 2FA Challenge Flow ---\n";

$adminEmail = 'secadmin_' . time() . '@sokohub.test';
$adminPass = 'AdminSecure@2026';
$pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status, two_factor_enabled, created_at)
    VALUES ('Sec Admin', :email, '+254733000333', :hash, 'admin', 'active', 1, NOW())
")->execute([':email' => $adminEmail, ':hash' => password_hash($adminPass, PASSWORD_BCRYPT)]);
$adminId = (int) $pdo->lastInsertId();

// When admin logs in with valid credentials, 2FA should intercept
$_SESSION = [];
$loginResult = login_user($pdo, $adminEmail, $adminPass, false);
assert_true(!empty($loginResult['requires_2fa']), 'Admin login intercepted by 2FA requirement');
assert_true(isset($_SESSION['2fa_user_id']) && (int) $_SESSION['2fa_user_id'] === $adminId, '$_SESSION["2fa_user_id"] set for 2FA challenge');

// Verify 6-digit 2FA code was created for admin
$stmt2fa = $pdo->prepare("SELECT id, code_hash FROM otp_codes WHERE user_id = :uid AND purpose = 'admin_2fa' AND used_at IS NULL");
$stmt2fa->execute([':uid' => $adminId]);
$adminOtp = $stmt2fa->fetch();
assert_true(!empty($adminOtp), '6-digit OTP code created in otp_codes with purpose admin_2fa');

// -----------------------------------------------------------------------------
// 5. Password Reset Flow Verification
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing Password Reset Flow ---\n";

$resetRawToken = Security::createPasswordResetToken($pdo, $testUserId);
assert_true(strlen($resetRawToken) === 64, 'Generated 32-byte crypto token (64 hex characters): ' . substr($resetRawToken, 0, 16) . '...');

$tokenRow = Security::verifyPasswordResetToken($pdo, $resetRawToken);
assert_true($tokenRow !== null && (int) $tokenRow['user_id'] === $testUserId, 'Raw reset token successfully resolved to user via SHA-256 hash');

// Consume token to update password
$newPass = 'NewBrandPass@2026!';
$consumed = Security::consumePasswordResetToken($pdo, $resetRawToken, $newPass);
assert_true($consumed === true, 'Token consumed and new password set');

// Verify old password fails, new password succeeds
$stmtNewPwd = $pdo->prepare("SELECT password_hash FROM users WHERE id = :uid");
$stmtNewPwd->execute([':uid' => $testUserId]);
$newHash = $stmtNewPwd->fetchColumn();
assert_true(password_verify($newPass, $newHash), 'New password hash verified');

// Token cannot be reused
$reused = Security::verifyPasswordResetToken($pdo, $resetRawToken);
assert_true($reused === null, 'Consumed password reset token cannot be reused');

// -----------------------------------------------------------------------------
// 6. Background Job Queue Worker Verification
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing Background Job Queue & Worker ---\n";

$jobPayload = [
    'to'       => 'recipient@sokohub.test',
    'subject'  => 'Test Email Dispatch',
    'template' => 'test_template',
    'code'     => '123456',
];
$jobId = Security::enqueueJob($pdo, 'send_email', $jobPayload);
assert_true($jobId > 0, "Background job enqueued with ID #{$jobId}");

// Check job is initially pending
$stmtJob = $pdo->prepare("SELECT status FROM jobs WHERE id = :id");
$stmtJob->execute([':id' => $jobId]);
assert_true($stmtJob->fetchColumn() === 'pending', 'Job status is initially `pending`');

// Run worker batch via subprocess
$workerOutput = shell_exec('C:\xampp\php\php.exe "' . __DIR__ . '/../cron/worker.php"');
assert_true(str_contains($workerOutput, 'Job #' . $jobId) && str_contains($workerOutput, 'COMPLETED'), 'cron/worker.php executed job to COMPLETED status');

// Verify job status updated in database
$stmtJob->execute([':id' => $jobId]);
assert_true($stmtJob->fetchColumn() === 'completed', 'Job marked `completed` in database');

// Verify dispatch recorded in notification_logs table
$stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notification_logs WHERE recipient = 'recipient@sokohub.test' AND template = 'test_template'");
$stmtNotif->execute();
assert_true((int) $stmtNotif->fetchColumn() >= 1, 'Dispatch recorded in `notification_logs` table');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=========================================================\n";
echo " Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
