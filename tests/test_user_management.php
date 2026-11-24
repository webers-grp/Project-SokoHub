<?php
/**
 * Test Suite: SokoHub Admin User Management & System Health Operations
 * Tests UserManagement service operations: retrieval, filtering, unlocking,
 * suspending, verifying, and safe deletion/anonymization.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_management.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = get_db_connection();
Security::ensureSchema();
Audit::ensureTableExists();

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo " [FAIL] $name: $detail\n";
        $failed++;
    }
}

echo "=========================================================\n";
echo " SokoHub User Management & Admin Operations Test Suite\n";
echo "=========================================================\n\n";

// 1. Test getStats()
$stats = UserManagement::getStats();
assert_test('UserManagement::getStats() returns integer stats array', is_array($stats) && isset($stats['total'], $stats['buyers_active'], $stats['sellers'], $stats['riders']));
assert_test('UserManagement::getStats() reports at least 1 total user', $stats['total'] >= 1, "Got {$stats['total']}");

// 2. Setup a test admin and test user
$adminUser = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    $pdo->exec("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Master Admin', 'masteradmin@test.local', '+254700000001', 'hash', 'admin', 'active')");
    $adminId = (int) $pdo->lastInsertId();
} else {
    $adminId = (int) $adminUser['id'];
}

// Create a disposable user
$testEmail = 'testuser_' . bin2hex(random_bytes(4)) . '@test.local';
$insStmt = $pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status, failed_login_count, locked_until)
    VALUES ('Kelvin Test', :email, '+254711122233', 'dummyhash', 'buyer', 'active', 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
");
$insStmt->execute([':email' => $testEmail]);
$testUserId = (int) $pdo->lastInsertId();

assert_test('Test user created with locked status', $testUserId > 0);

// 3. Test Filtering & Listing
$lockedUsers = UserManagement::getUsers(['locked' => '1']);
$foundLocked = false;
foreach ($lockedUsers as $lu) {
    if ((int) $lu['id'] === $testUserId) {
        $foundLocked = true;
        break;
    }
}
assert_test('UserManagement::getUsers(locked=1) identifies throttled user', $foundLocked);

$searchUsers = UserManagement::getUsers(['q' => $testEmail]);
assert_test('UserManagement::getUsers(q=email) finds specific user', count($searchUsers) === 1 && (int) $searchUsers[0]['id'] === $testUserId);

// 4. Test Unlocking Account
$unlocked = UserManagement::unlockAccount($testUserId, $adminId);
$refreshed = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::unlockAccount() clears locked_until and failed_login_count', 
    $unlocked && empty($refreshed['locked_until']) && (int) $refreshed['failed_login_count'] === 0
);

// 5. Test Suspending User
$suspended = UserManagement::suspendUser($testUserId, $adminId, 'Test policy breach');
$refreshed = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::suspendUser() sets status to suspended', 
    $suspended && $refreshed['status'] === 'suspended'
);

// 6. Test Reactivating User
$reactivated = UserManagement::reactivateUser($testUserId, $adminId);
$refreshed = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::reactivateUser() restores status to active', 
    $reactivated && $refreshed['status'] === 'active'
);

// 7. Test Manual Email Verification
$pdo->prepare("UPDATE users SET status = 'pending_verification', email_verified_at = NULL WHERE id = :id")->execute([':id' => $testUserId]);
$verified = UserManagement::verifyUserEmail($testUserId, $adminId);
$refreshed = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::verifyUserEmail() activates user and records email_verified_at', 
    $verified && $refreshed['status'] === 'active' && !empty($refreshed['email_verified_at'])
);

// 8. Test Role Updating
$roleUpdated = UserManagement::updateRole($testUserId, 'seller', $adminId);
$refreshed = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::updateRole() changes role to seller and initializes seller profile', 
    $roleUpdated && $refreshed['role'] === 'seller' && !empty($refreshed['business_name'])
);

// 9. Test Self-Deletion Guard
$errMsg = null;
$mode = null;
$selfDel = UserManagement::deleteUser($adminId, $adminId, $errMsg, $mode);
assert_test('UserManagement::deleteUser() blocks administrator self-deletion', 
    !$selfDel && strpos($errMsg, 'Self-deletion is blocked') !== false
);

// 10. Test Clean Permanent Delete for User without Orders
$deleted = UserManagement::deleteUser($testUserId, $adminId, $errMsg, $mode);
$checkExists = UserManagement::getUserDetails($testUserId);
assert_test('UserManagement::deleteUser() performs clean hard-deletion on account without orders', 
    $deleted && $mode === 'hard_deleted' && $checkExists === null
);

// 11. Test Safe Archival/Anonymization for User with Orders
// Create another user and attach a dummy order
$orderUserEmail = 'orderuser_' . bin2hex(random_bytes(4)) . '@test.local';
$pdo->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role, status)
    VALUES ('Shopper With Orders', :email, '+254799887766', 'hash', 'buyer', 'active')
")->execute([':email' => $orderUserEmail]);
$orderUserId = (int) $pdo->lastInsertId();

// Insert dummy order referencing $orderUserId
$orderStmt = $pdo->prepare("
    INSERT INTO orders (order_code, buyer_id, total_amount, order_status, payment_status, delivery_address, delivery_town)
    VALUES (:code, :buyer_id, 1500.00, 'delivered', 'paid', 'Kutus Hall 4', 'Kutus')
");
$orderCode = 'ORD-TEST-' . bin2hex(random_bytes(3));
$orderStmt->execute([':code' => $orderCode, ':buyer_id' => $orderUserId]);
$dummyOrderId = (int) $pdo->lastInsertId();

$archived = UserManagement::deleteUser($orderUserId, $adminId, $errMsg, $mode);
$archivedUser = UserManagement::getUserDetails($orderUserId);

assert_test('UserManagement::deleteUser() preserves financial history via archival & anonymization', 
    $archived && $mode === 'archived_anonymized' && $archivedUser !== null && $archivedUser['status'] === 'suspended'
);
assert_test('Archived user has credentials and personal data sanitized', 
    strpos($archivedUser['full_name'], 'Archived User') === 0 &&
    strpos($archivedUser['email'], '@anonymized.sokohub') !== false &&
    $archivedUser['phone'] === '+254000000000'
);

// Clean up dummy order and user
try {
    $pdo->prepare("DELETE FROM orders WHERE id = :id")->execute([':id' => $dummyOrderId]);
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $orderUserId]);
} catch (Throwable $_) {}

// 12. Check Audit Logs
$recentAudits = Audit::getLogs(['action' => 'USER_REMOVED'], 5, 0);
assert_test('Audit::record() captures administrative user removal events', !empty($recentAudits));

echo "\n=========================================================\n";
echo " Test Results: $passed Passed, $failed Failed\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
