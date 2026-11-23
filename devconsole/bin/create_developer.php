<?php
/**
 * One-Time CLI Developer / Owner Account Provisioning Tool
 * Usage:
 *   php devconsole/bin/create_developer.php --username=adminops --email=ops@sokohub.test --role=owner [--password=SecretPass]
 */

if (PHP_SAPI !== 'cli') {
    die("This tool can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/totp.php';

$options = getopt('', ['username:', 'email:', 'role:', 'password::']);

$username = trim($options['username'] ?? '');
$email    = strtolower(trim($options['email'] ?? ''));
$role     = strtolower(trim($options['role'] ?? 'developer'));
$password = $options['password'] ?? null;

if (!$username || !$email) {
    echo "Usage: php devconsole/bin/create_developer.php --username=<name> --email=<email> [--role=owner|developer|viewer] [--password=<pass>]\n";
    exit(1);
}

if (!in_array($role, ['viewer', 'developer', 'owner'], true)) {
    die("Error: Invalid role. Allowed: viewer, developer, owner.\n");
}

if (!$password) {
    echo "Enter password for {$username}: ";
    $password = rtrim(fgets(STDIN), "\r\n");
}

if (strlen($password) < 10) {
    die("Error: Password must be at least 10 characters long for developer accounts.\n");
}

$pdo = get_db_connection();

// Check if username or email already exists
$stmt = $pdo->prepare('SELECT id FROM developers WHERE username = :u OR email = :e LIMIT 1');
$stmt->execute([':u' => $username, ':e' => $email]);
if ($stmt->fetch()) {
    die("Error: Developer with this username or email already exists.\n");
}

$totpSecret = Totp::generateSecret(16);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$ins = $pdo->prepare('
    INSERT INTO developers (username, email, password_hash, role, totp_secret, totp_enabled, status, created_at)
    VALUES (:u, :e, :h, :r, :s, 0, "active", NOW())
');
$ins->execute([
    ':u' => $username,
    ':e' => $email,
    ':h' => $passwordHash,
    ':r' => $role,
    ':s' => $totpSecret,
]);

$id = $pdo->lastInsertId();
$otpUri = Totp::getOtpAuthUri($username, $totpSecret, 'SokoHub Ops');

echo "\n======================================================\n";
echo "  [SUCCESS] Developer Account Created (ID: {$id})\n";
echo "======================================================\n";
echo "  Username:     {$username}\n";
echo "  Email:        {$email}\n";
echo "  Role:         {$role}\n";
echo "  Status:       active\n";
echo "  2FA Status:   Pending Setup on First Login\n";
echo "  TOTP Secret:  {$totpSecret}\n";
echo "  TOTP URI:     {$otpUri}\n";
echo "======================================================\n";
echo "You can now log in at /devconsole/login.php and complete\n";
echo "your 2FA registration using the secret above in your\n";
echo "authenticator app (e.g. Google Authenticator / Authy).\n\n";
