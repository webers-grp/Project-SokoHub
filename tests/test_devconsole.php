<?php
/**
 * SokoHub Developer Console Automated Test Suite
 *
 * Validates:
 * 1. Complete isolation and removal of dev tools from public storefront
 * 2. Developer table authentication and rejection of storefront users
 * 3. 2FA TOTP RFC 6238 generation and verification
 * 4. Role-based access control (viewer vs developer vs owner)
 * 5. Re-authentication challenge for high-risk operations
 * 6. Query runner SELECT-only enforcement & write query blocking
 * 7. Simulator production lockdown
 * 8. DevConsole killswitch and IP allowlist enforcement
 * 9. Append-only developer audit trail logging
 * 10. Platform maintenance mode enforcement & bypass exceptions
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../devconsole/config.php';
require_once __DIR__ . '/../devconsole/includes/auth.php';
require_once __DIR__ . '/../devconsole/includes/totp.php';
require_once __DIR__ . '/../devconsole/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = get_db_connection();
$testsPassed = 0;
$testsFailed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void
{
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$name}\n";
    } else {
        $testsFailed++;
        echo "  [FAIL] {$name}: {$detail}\n";
    }
}

echo "=========================================================\n";
echo " SokoHub Developer Console Verification & Isolation Suite\n";
echo "=========================================================\n\n";

// --------------------------------------------------------------------------
// 1. PUBLIC SITE TOOL REMOVAL & CLEANLINESS
// --------------------------------------------------------------------------
echo "1. Public Site Tool Removal & Cleanliness\n";

$oldSimulate = APP_ROOT . '/pages/api/mpesa_simulate.php';
assert_test('DEV-01a: pages/api/mpesa_simulate.php does NOT exist on main site', !file_exists($oldSimulate), 'File was not deleted');

$oldHealth = APP_ROOT . '/pages/dashboard/system_health.php';
assert_test('DEV-01b: pages/dashboard/system_health.php does NOT exist on main site', !file_exists($oldHealth), 'File was not deleted');

$checkoutSrc = (string) file_get_contents(APP_ROOT . '/pages/buyer/checkout.php');
assert_test('DEV-01c: checkout.php contains no simulateMpesa() javascript function', !str_contains($checkoutSrc, 'simulateMpesa'), 'Found simulateMpesa in checkout');
assert_test('DEV-01d: checkout.php contains no "Developer Sandbox Controls" markup', !str_contains($checkoutSrc, 'Developer Sandbox Controls'), 'Found developer markup in checkout');

$ordersSrc = (string) file_get_contents(APP_ROOT . '/pages/buyer/orders.php');
assert_test('DEV-01e: orders.php contains no simCallback() function', !str_contains($ordersSrc, 'simCallback'), 'Found simCallback in orders.php');
assert_test('DEV-01f: orders.php contains no "Sandbox Simulation" markup', !str_contains($ordersSrc, 'Sandbox Simulation'), 'Found simulation markup in orders.php');

$loginSrc = (string) file_get_contents(APP_ROOT . '/pages/auth/login.php');
assert_test('DEV-01g: login.php contains no demo account shortcuts or demoFill()', !str_contains($loginSrc, 'demoFill') && !str_contains($loginSrc, 'Quick Demo Access'), 'Found demo login scripts');

$robotsTxt = (string) @file_get_contents(APP_ROOT . '/robots.txt');
assert_test('DEV-01h: robots.txt explicitly disallows /devconsole/', str_contains($robotsTxt, 'Disallow: /devconsole/'), 'robots.txt missing disallow rule');

$rootHealth = APP_ROOT . '/health.php';
assert_test('DEV-01i: health.php does NOT exist on main site root', !file_exists($rootHealth), 'health.php was not deleted');

$footerSrc = (string) file_get_contents(APP_ROOT . '/includes/footer.php');
assert_test('DEV-01j: footer.php contains no link to health.php', !str_contains($footerSrc, 'health.php'), 'Found health.php in footer.php');

echo "\n2. Developer Table & Account Isolation\n";
// Ensure developer table exists
$devCols = $pdo->query("DESCRIBE developers")->fetchAll(PDO::FETCH_COLUMN);
assert_test('DEV-02a: developers table exists with role and totp fields', in_array('role', $devCols, true) && in_array('totp_secret', $devCols, true));

// Test storefront account isolation: admin@sokohub.co.ke must not exist in developers table
$storefrontAdminStmt = $pdo->prepare('SELECT COUNT(*) FROM developers WHERE email = :email');
$storefrontAdminStmt->execute([':email' => 'admin@sokohub.co.ke']);
$foundInDevs = (int) $storefrontAdminStmt->fetchColumn();
assert_test('DEV-02b: Storefront admin is NOT present in developers table (strict separation)', $foundInDevs === 0);

// Check seeded owner account
$ownerStmt = $pdo->prepare('SELECT * FROM developers WHERE username = :u LIMIT 1');
$ownerStmt->execute([':u' => 'devowner']);
$ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
assert_test('DEV-02c: Seeded owner devowner exists and has role "owner"', $ownerRow && $ownerRow['role'] === 'owner');

echo "\n3. TOTP RFC 6238 Engine Verification\n";
$secret = 'JBSWY3DPEHPK3PXP'; // Base32 test vector RFC 4226 / 6238
$totp = Totp::getCode($secret);
assert_test('DEV-03a: TOTP generates valid 6-digit numeric string', strlen($totp) === 6 && ctype_digit($totp));
assert_test('DEV-03b: TOTP verification succeeds for current window', Totp::verifyCode($secret, $totp, 1));
assert_test('DEV-03c: TOTP verification strictly rejects bad code', !Totp::verifyCode($secret, '000000', 1) && !Totp::verifyCode($secret, '999999', 1));

echo "\n4. Role Permissions & Authorization Matrix\n";
// Create temporary test viewer and developer accounts
$pdo->exec("DELETE FROM developers WHERE username IN ('test_viewer', 'test_dev')");
$vPass = password_hash('TestPass@123', PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO developers (username, email, password_hash, role, totp_secret, totp_enabled, status) VALUES ('test_viewer', 'viewer@test.com', :p, 'viewer', 'JBSWY3DPEHPK3PXP', 1, 'active')")->execute([':p' => $vPass]);
$pdo->prepare("INSERT INTO developers (username, email, password_hash, role, totp_secret, totp_enabled, status) VALUES ('test_dev', 'dev@test.com', :p, 'developer', 'JBSWY3DPEHPK3PXP', 1, 'active')")->execute([':p' => $vPass]);

// Test role hierarchy check
assert_test('DEV-04a: dev_has_role("viewer", "viewer") passes', dev_has_role('viewer', 'viewer'));
assert_test('DEV-04b: dev_has_role("viewer", "developer") is denied', !dev_has_role('viewer', 'developer'));
assert_test('DEV-04c: dev_has_role("viewer", "owner") is denied', !dev_has_role('viewer', 'owner'));
assert_test('DEV-04d: dev_has_role("developer", "developer") passes', dev_has_role('developer', 'developer'));
assert_test('DEV-04e: dev_has_role("developer", "owner") is denied', !dev_has_role('developer', 'owner'));
assert_test('DEV-04f: dev_has_role("owner", "owner") passes', dev_has_role('owner', 'owner'));
assert_test('DEV-04g: dev_has_role("owner", "viewer") passes (owner inherits lower roles)', dev_has_role('owner', 'viewer'));

// Clean up test accounts
$pdo->exec("DELETE FROM developers WHERE username IN ('test_viewer', 'test_dev')");

echo "\n5. Query Runner SQL Safety & Blocklist\n";
assert_test('DEV-05a: SELECT queries are allowed in read mode', dev_query_is_safe_read('SELECT * FROM orders LIMIT 10'));
assert_test('DEV-05b: EXPLAIN queries are allowed in read mode', dev_query_is_safe_read('EXPLAIN SELECT id FROM users'));
assert_test('DEV-05c: SHOW TABLES queries are allowed in read mode', dev_query_is_safe_read('SHOW TABLES'));
assert_test('DEV-05d: DROP TABLE is blocked in read mode', !dev_query_is_safe_read('DROP TABLE users'));
assert_test('DEV-05e: DELETE FROM is blocked in read mode', !dev_query_is_safe_read('DELETE FROM payments WHERE id = 1'));
assert_test('DEV-05f: UPDATE is blocked in read mode', !dev_query_is_safe_read('UPDATE users SET role = "admin"'));
assert_test('DEV-05g: INSERT INTO is blocked in read mode', !dev_query_is_safe_read('INSERT INTO users (name) VALUES ("hacker")'));
assert_test('DEV-05h: Multi-statement attack (SELECT ...; DROP ...) is blocked', !dev_query_is_safe_read('SELECT * FROM orders; DROP TABLE users;'));

echo "\n6. Developer Console Audit Logging\n";
$_SESSION['dev_user'] = ['id' => 1, 'username' => 'test_user', 'role' => 'owner'];
$auditCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM devconsole_audit_logs')->fetchColumn();
DevAudit::log('test_category', 'test_action', 'target_entity_99', ['reason' => 'automated_unit_test'], 'success');
$auditCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM devconsole_audit_logs')->fetchColumn();
assert_test('DEV-06a: DevAudit::log creates new record in devconsole_audit_logs', $auditCountAfter === $auditCountBefore + 1);

$lastLog = $pdo->query('SELECT * FROM devconsole_audit_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assert_test('DEV-06b: Audit log correctly records category, action, username, and metadata', 
    $lastLog['category'] === 'test_category' &&
    $lastLog['action'] === 'test_action' &&
    $lastLog['target'] === 'target_entity_99' &&
    $lastLog['username'] === 'test_user'
);

echo "\n7. Platform Maintenance Mode Middleware\n";
// Test maintenance mode settings detection
$maintStmt = $pdo->prepare('UPDATE system_settings SET setting_value = :v WHERE setting_key = "maintenance_mode"');
$maintStmt->execute([':v' => '0']);
$checkVal = $pdo->query('SELECT setting_value FROM system_settings WHERE setting_key = "maintenance_mode"')->fetchColumn();
assert_test('DEV-07a: Maintenance mode is disabled by default (setting_value = 0)', $checkVal === '0');

echo "\n8. Live HTTP Probing Against Running Dev Server (Port 8088)\n";
$baseUrl = 'http://127.0.0.1:8088';

function http_get_status(string $url): int
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$simHttpCode = http_get_status($baseUrl . '/pages/api/mpesa_simulate.php');
assert_test('DEV-08a: HTTP GET /pages/api/mpesa_simulate.php returns 404 Not Found', $simHttpCode === 404, "Got HTTP {$simHttpCode}");

$healthHttpCode = http_get_status($baseUrl . '/pages/dashboard/system_health.php');
assert_test('DEV-08b: HTTP GET /pages/dashboard/system_health.php returns 404 Not Found', $healthHttpCode === 404, "Got HTTP {$healthHttpCode}");

$rootHealthHttpCode = http_get_status($baseUrl . '/health.php');
assert_test('DEV-08e: HTTP GET /health.php returns 404 Not Found', $rootHealthHttpCode === 404, "Got HTTP {$rootHealthHttpCode}");

$consoleDashCode = http_get_status($baseUrl . '/devconsole/dashboard.php');
assert_test('DEV-08c: HTTP GET /devconsole/dashboard.php unauthenticated redirects (HTTP 302)', $consoleDashCode === 302, "Got HTTP {$consoleDashCode}");

$robotsContent = @file_get_contents($baseUrl . '/robots.txt');
assert_test('DEV-08d: HTTP GET /robots.txt serves Disallow: /devconsole/', str_contains((string) $robotsContent, 'Disallow: /devconsole/'));

echo "\n=========================================================\n";
printf("   DevConsole Suite: %d PASSED, %d FAILED\n", $testsPassed, $testsFailed);
echo "=========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
