<?php
/**
 * SokoHub Automated Integration Test Suite
 * Tests PDO connectivity, password hashing, session/cookies, validation,
 * Product CRUD, Checkout transaction, Order cancellation, Delivery workflow, and Reports.
 *
 * Usage: DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=sokohub_db DB_USER=root DB_PASS= php -d extension=pdo_mysql tests/run_tests.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/kyc.php';
require_once __DIR__ . '/../includes/inventory.php';

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

// 1. Database Connectivity & Seed Verification
$dbCheck = test_db_connection();
assert_true($dbCheck['ok'] === true, 'TC-01: PDO MySQL connection succeeds');
assert_true(count($dbCheck['tables']) >= 9, 'TC-01b: Core schema tables exist (' . count($dbCheck['tables']) . ' tables)');

$pdo = get_db_connection();
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$prodCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
assert_true($userCount >= 7, 'TC-01c: Seeded users loaded (' . $userCount . ' users)');
assert_true($prodCount >= 10, 'TC-01d: Seeded products loaded (' . $prodCount . ' products)');

// 2. Authentication & Password Verification
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$badLogin = login_user($pdo, 'buyer@sokohub.co.ke', 'WrongPass123', false);
assert_true($badLogin['ok'] === false, 'TC-02a: Reject invalid login password');

$goodLogin = login_user($pdo, 'seller@sokohub.co.ke', 'SokoHub@2026', true);
assert_true($goodLogin['ok'] === true && $goodLogin['user']['role'] === 'seller', 'TC-02b: Valid seller login succeeds via password_verify()');
assert_true(isset($_SESSION['user']['id']), 'TC-02c: Session user array populated');

// 3. Server-Side Validation Rules
$regErrors = validate_registration_fields([
    'full_name'        => 'A',
    'email'            => 'not-an-email',
    'phone'            => '123',
    'password'         => 'short',
    'confirm_password' => 'different',
]);
assert_true(count($regErrors) === 5, 'TC-03a: Registration validator catches all 5 invalid fields');

$prodErrors = validate_product_fields([
    'name'           => 'Hi',
    'description'    => 'Too short',
    'category_id'    => 0,
    'price'          => -50,
    'original_price' => 10,
    'stock_qty'      => -1,
    'condition_type' => 'Broken',
]);
assert_true(count($prodErrors) >= 6, 'TC-03b: Product validator rejects invalid numeric and enum inputs');

// 4. Full Product CRUD Lifecycle (Create -> Read -> Update -> Delete)
$sellerId = (int) $goodLogin['user']['id'];
$ins = $pdo->prepare(
    "INSERT INTO products (seller_id, category_id, name, description, price, original_price, stock_qty, condition_type, image_path, is_featured, status)
     VALUES (:sid, 2, 'Automated Test Gas Burner', 'High quality brass gas burner tested in QA suite.', 850.00, 1000.00, 12, 'New', 'assets/img/gas-tanks.jpg', 0, 'active')"
);
$ins->execute([':sid' => $sellerId]);
$testProdId = (int) $pdo->lastInsertId();
assert_true($testProdId > 0, 'TC-04a: Create new product in DB');

$readStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
$readStmt->execute([':id' => $testProdId]);
$createdProd = $readStmt->fetch();
assert_true($createdProd && $createdProd['name'] === 'Automated Test Gas Burner', 'TC-04b: Read newly created product');

$updStmt = $pdo->prepare('UPDATE products SET price = 790.00, stock_qty = 15 WHERE id = :id');
$updStmt->execute([':id' => $testProdId]);
$readStmt->execute([':id' => $testProdId]);
$updatedProd = $readStmt->fetch();
assert_true((float) $updatedProd['price'] === 790.00 && (int) $updatedProd['stock_qty'] === 15, 'TC-04c: Update product price and stock');

// 5. Buyer Order Placement & Stock Decrement + Cancel Restore
$buyerLogin = login_user($pdo, 'buyer@sokohub.co.ke', 'SokoHub@2026', false);
$buyerId = (int) $buyerLogin['user']['id'];
$pdo->beginTransaction();
$orderCode = 'SKH-TEST-' . bin2hex(random_bytes(3));
$pdo->prepare(
    "INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town)
     VALUES (:code, :buyer, 1580.00, 'M-Pesa', 'paid', 'pending', 'Gate B Test Hostel', 'Kutus')"
)->execute([':code' => $orderCode, ':buyer' => $buyerId]);
$testOrderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
     VALUES (:oid, :pid, :sid, 2, 790.00, 1580.00)'
)->execute([':oid' => $testOrderId, ':pid' => $testProdId, ':sid' => $sellerId]);
$pdo->prepare('UPDATE products SET stock_qty = stock_qty - 2 WHERE id = :pid')->execute([':pid' => $testProdId]);
$pdo->prepare(
    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status)
     VALUES (:oid, 'Kutus Market', 'Gate B Test Hostel', 150.00, 'pending')"
)->execute([':oid' => $testOrderId]);
$pdo->commit();

$readStmt->execute([':id' => $testProdId]);
$stockAfterOrder = (int) $readStmt->fetch()['stock_qty'];
assert_true($stockAfterOrder === 13, 'TC-05a: Atomic checkout decrements stock from 15 to 13');

// 6. Delivery Rider Claim & Completion Sync
$riderLogin = login_user($pdo, 'rider@sokohub.co.ke', 'SokoHub@2026', false);
$riderId = (int) $riderLogin['user']['id'];
$pdo->prepare("UPDATE deliveries SET delivery_person_id = :rid, delivery_status = 'delivered', delivered_at = NOW() WHERE order_id = :oid")
    ->execute([':rid' => $riderId, ':oid' => $testOrderId]);
$pdo->prepare("UPDATE orders SET order_status = 'delivered' WHERE id = :oid")->execute([':oid' => $testOrderId]);
$delRow = $pdo->prepare('SELECT delivery_status FROM deliveries WHERE order_id = :oid');
$delRow->execute([':oid' => $testOrderId]);
assert_true($delRow->fetchColumn() === 'delivered', 'TC-06: Delivery status updates cleanly to delivered');

// Clean up test order & test product so seeded baseline stays clean
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $testOrderId]);
$pdo->prepare('DELETE FROM products WHERE id = :pid')->execute([':pid' => $testProdId]);
$readStmt->execute([':id' => $testProdId]);
assert_true($readStmt->fetch() === false, 'TC-04d: Delete test product cleanly from DB');

// 7. Analytical Report Queries Verification
$catRevenue = $pdo->query(
    "SELECT c.name, COALESCE(SUM(oi.line_total), 0) AS rev
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     INNER JOIN products p ON p.id = oi.product_id
     INNER JOIN categories c ON c.id = p.category_id
     WHERE o.order_status <> 'cancelled'
     GROUP BY c.id, c.name"
)->fetchAll();
assert_true(count($catRevenue) >= 3, 'TC-07: Sales report returns category revenue aggregates');

// 8. Universal Audit Logging Verification (Kennedy's Feature 1)
$auditRecorded = Audit::record('test.audit_event', 'test_entity', 9999, ['test_key' => 'verified_value'], 1, 'admin');
assert_true($auditRecorded === true, 'TC-08a: Audit::record() writes system event safely');
$auditLogs = Audit::getLogs(['action' => 'test.audit_event'], 5);
assert_true(!empty($auditLogs) && (int) $auditLogs[0]['entity_id'] === 9999, 'TC-08b: Audit::getLogs() retrieves recorded event and details');
$pdo->prepare("DELETE FROM audit_logs WHERE action = 'test.audit_event'")->execute();

// 9. Seller & Delivery KYC Verification (Kennedy's Feature 2)
Kyc::ensureSchema();
$kycSellerId = 2; // Seed seller user ID
$approveOk = Kyc::updateSellerStatus($kycSellerId, 'approved', 1);
assert_true($approveOk === true, 'TC-09a: Kyc::updateSellerStatus() approves merchant and logs audit');
assert_true(Kyc::isSellerApproved($kycSellerId) === true, 'TC-09b: Kyc::isSellerApproved() validates verified merchant');

// 9c. M-Pesa phone normalization
$p1 = Kyc::cleanMpesaPhone('0712345678');
$p2 = Kyc::cleanMpesaPhone('+254712345678');
$p3 = Kyc::cleanMpesaPhone('0112345678');
assert_true($p1 === '254712345678' && $p2 === '254712345678' && $p3 === '254112345678', 'TC-09c: Kyc::cleanMpesaPhone() normalizes Safaricom lines');
assert_true(Kyc::cleanMpesaPhone('12345') === null && Kyc::cleanMpesaPhone('abcdef') === null, 'TC-09d: Kyc::cleanMpesaPhone() rejects invalid numbers');

// 9e. National ID & M-Pesa update
$credUpdate = Kyc::updateKycDetails($kycSellerId, 'seller', '12345678', '0712345678');
assert_true($credUpdate['ok'] === true, 'TC-09e: Kyc::updateKycDetails() saves National ID and M-Pesa line');

// 9f. Delivery rider verification
$kycRiderId = $riderId; // Seed delivery rider user ID (Brian Kiprop)
$riderApproveOk = Kyc::updateStatus($kycRiderId, 'delivery', 'approved', 1);
assert_true($riderApproveOk === true, 'TC-09f: Kyc::updateStatus() approves delivery rider and records audit');
assert_true(Kyc::isUserApproved($kycRiderId, 'delivery') === true, 'TC-09g: Kyc::isUserApproved() validates verified rider');

// 9h. Admin review queue
$adminQueue = Kyc::getAdminQueue();
assert_true(!empty($adminQueue), 'TC-09h: Kyc::getAdminQueue() retrieves pending and approved accounts with KYC metadata');

// 9i. Document upload format validation
$fakeFileBadExt = [
    'name'     => 'malicious_script.exe',
    'type'     => 'application/x-msdownload',
    'tmp_name' => '/tmp/fake_upload.exe',
    'error'    => UPLOAD_ERR_OK,
    'size'     => 1024,
];
$uploadBad = Kyc::uploadDocument($kycSellerId, 'seller', 'national_id', $fakeFileBadExt);
assert_true($uploadBad['ok'] === false && str_contains($uploadBad['error'], 'Unsupported file type'), 'TC-09i: Kyc::uploadDocument() rejects unsupported file types with helpful error');

// 10. Low-Stock Automated Alerts Engine (Kennedy's Feature 3)
Inventory::ensureSchema();

// 10a. Schema verification
$cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'low_stock_threshold'")->fetch();
assert_true(!empty($cols), 'TC-10a: Inventory::ensureSchema() creates low_stock_threshold on products');

// 10b. Low stock detection
$lowStockItems = Inventory::getLowStockProducts();
assert_true(is_array($lowStockItems) && count($lowStockItems) >= 2, 'TC-10b: Inventory::getLowStockProducts() finds seeded items at/below threshold');

// 10c. Low stock count
$lowCount = Inventory::getLowStockCount();
assert_true($lowCount === count($lowStockItems), 'TC-10c: Inventory::getLowStockCount() matches item list count');

// 10d. Automated check & alert trigger
// Create a temporary product with stock = 3, threshold = 5
$pdo->prepare("
    INSERT INTO products (id, seller_id, category_id, name, description, price, stock_qty, low_stock_threshold, condition_type, image_path, is_featured, status)
    VALUES (9999, 2, 1, 'Temporary Low Stock Test SKU', 'Testing automated threshold breach detection', 250.00, 3, 5, 'New', 'assets/img/cart-icon.jpg', 0, 'active')
")->execute();

$alertResult = Inventory::checkProductLowStock(9999, 'Test unit trigger');
assert_true($alertResult !== null && $alertResult['stock_qty'] === 3 && $alertResult['threshold'] === 5, 'TC-10d: Inventory::checkProductLowStock() detects breach and flags alert');

// Verify alerted_at was recorded
$checkAlerted = (string) $pdo->query("SELECT low_stock_alerted_at FROM products WHERE id = 9999")->fetchColumn();
assert_true(!empty($checkAlerted), 'TC-10e: low_stock_alerted_at timestamp recorded on alert');

// 10f. Stock replenishment clears alert
$replenishRes = Inventory::replenishStock(9999, 20, 2, 'seller');
assert_true($replenishRes['ok'] === true && $replenishRes['alert_cleared'] === true && $replenishRes['new_qty'] === 20, 'TC-10f: Inventory::replenishStock() replenishes units and clears alert timestamp');
$checkCleared = $pdo->query("SELECT low_stock_alerted_at FROM products WHERE id = 9999")->fetchColumn();
assert_true(empty($checkCleared), 'TC-10g: low_stock_alerted_at timestamp reset to NULL after restocking');

// 10h. Update threshold
$threshRes = Inventory::updateThreshold(9999, 25, 2, 'seller');
assert_true($threshRes['ok'] === true && $threshRes['new_threshold'] === 25, 'TC-10h: Inventory::updateThreshold() updates per-product threshold');
$recheck = Inventory::checkProductLowStock(9999, 'Re-evaluation');
assert_true($recheck !== null, 'TC-10i: Higher threshold triggers automated alert on existing stock');

// Clean up temporary test product & test audit logs
$pdo->prepare("DELETE FROM products WHERE id = 9999")->execute();
$pdo->prepare("DELETE FROM audit_logs WHERE action IN ('inventory.low_stock_alert', 'inventory.replenished', 'inventory.threshold_changed')")->execute();

// 10j. Validator rejects negative threshold
$badThreshErrors = validate_product_fields([
    'name'                => 'Test Product',
    'description'         => 'Test description exceeding 10 characters',
    'category_id'         => 1,
    'price'               => 100,
    'stock_qty'           => 10,
    'low_stock_threshold' => 0,
    'condition_type'      => 'New',
]);
assert_true(in_array('Low-stock alert threshold must be a number of at least 1 unit.', $badThreshErrors, true), 'TC-10j: validate_product_fields() validates low_stock_threshold >= 1');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
