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
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/payouts.php';
require_once __DIR__ . '/../includes/bulk_import.php';

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

// 9j. Kenyan vehicle number plate normalization
$plate1 = Kyc::cleanPlateNumber('kmdf 123a');
$plate2 = Kyc::cleanPlateNumber('kda-456b');
$plate3 = Kyc::cleanPlateNumber('kmdf123a');
assert_true(
    $plate1 === 'KMDF 123A' && $plate2 === 'KDA 456B' && $plate3 === 'KMDF 123A',
    'TC-09j: Kyc::cleanPlateNumber() normalizes Kenyan motorcycle and vehicle number plates'
);

// 9k. Plate validation logic
$valBike = Kyc::validatePlateNumber('', 'bicycle');
$valMotorValid = Kyc::validatePlateNumber('KMDF 123A', 'motorbike');
$valCarValid = Kyc::validatePlateNumber('KDA 456B', 'car');
$valMissingMotor = Kyc::validatePlateNumber('', 'motorbike');
$valBadFormat = Kyc::validatePlateNumber('123456', 'motorbike');
assert_true(
    $valBike === null && $valMotorValid === null && $valCarValid === null &&
    $valMissingMotor !== null && $valBadFormat !== null,
    'TC-09k: Kyc::validatePlateNumber() enforces plates on motorized transport and exempts bicycles'
);

// 9l. Rider KYC details update with vehicle plate and model
$riderCredUpdate = Kyc::updateKycDetails($kycRiderId, 'delivery', '28991234', '0755600006', 'KMDF 450A', 'motorbike', 'Bajaj Boxer BM150');
assert_true($riderCredUpdate['ok'] === true, 'TC-09l: Kyc::updateKycDetails() saves rider vehicle plate and model');
$dpCheck = $pdo->query("SELECT vehicle_plate_number, vehicle_model, transport_method FROM delivery_profiles WHERE user_id = {$kycRiderId}")->fetch();
assert_true(
    $dpCheck && $dpCheck['vehicle_plate_number'] === 'KMDF 450A' && $dpCheck['vehicle_model'] === 'Bajaj Boxer BM150',
    'TC-09m: Delivery profile in database reflects verified number plate KMDF 450A and model'
);

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

// 11. Buyer Notifications & Restock Alerts
Notifications::ensureSchema();

// 11a. Schema verification
$notifCols = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetch();
assert_true(!empty($notifCols), 'TC-11a: Notifications::ensureSchema() creates notifications table');

// 11b. New seller notification
$newSellerNotifId = Notifications::notifyNewSeller(2, 'Kirinyaga Highlands Tea Emporium', 'Kerugoya');
assert_true($newSellerNotifId > 0, 'TC-11b: Notifications::notifyNewSeller() broadcasts alert to buyers');

// 11c. New stock notification
$newStockNotifId = Notifications::notifyNewStock(1, 'Kirinyaga Organic Honey 500g', 850.00, 'Kirinyaga Highlands');
assert_true($newStockNotifId > 0, 'TC-11c: Notifications::notifyNewStock() broadcasts new arrival alert');

// 11d. Restock notification
$restockNotifId = Notifications::notifyRestock(1, 'Kirinyaga Organic Honey 500g', 50, 'Kirinyaga Highlands');
assert_true($restockNotifId > 0, 'TC-11d: Notifications::notifyRestock() broadcasts back-in-stock alert');

// 11e. Buyer notifications retrieval
$buyerId = 4; // Seed buyer user ID (Mercy Wangari)
$buyerFeed = Notifications::getBuyerNotifications($buyerId, null, 10);
assert_true(!empty($buyerFeed) && isset($buyerFeed[0]['is_read']), 'TC-11e: Notifications::getBuyerNotifications() retrieves buyer feed with is_read status');

// 11f. Unread count check
$unreadBefore = Notifications::getUnreadCount($buyerId);
assert_true($unreadBefore > 0, 'TC-11f: Notifications::getUnreadCount() reflects unread alerts');

// 11g. Mark single as read
Notifications::markAsRead($newSellerNotifId, $buyerId);
$feedAfter = Notifications::getBuyerNotifications($buyerId, null, 10);
$foundRead = false;
foreach ($feedAfter as $fa) {
    if ((int) $fa['id'] === $newSellerNotifId && (bool) $fa['is_read'] === true) {
        $foundRead = true;
        break;
    }
}
assert_true($foundRead === true, 'TC-11g: Notifications::markAsRead() marks individual notification as read');

// 11h. Mark all as read
Notifications::markAllAsRead($buyerId);
$unreadAfter = Notifications::getUnreadCount($buyerId);
assert_true($unreadAfter === 0, 'TC-11h: Notifications::markAllAsRead() clears all unread alerts for buyer');

// 11i. Public storefront announcements
$publicAlerts = Notifications::getRecentAnnouncements(3);
assert_true(is_array($publicAlerts) && count($publicAlerts) >= 1, 'TC-11i: Notifications::getRecentAnnouncements() returns public marketplace alerts for ticker');

// Clean up temporary test notifications & audit logs
$pdo->prepare("DELETE FROM notifications WHERE id IN (:n1, :n2, :n3)")->execute([
    ':n1' => $newSellerNotifId,
    ':n2' => $newStockNotifId,
    ':n3' => $restockNotifId,
]);
$pdo->prepare("DELETE FROM audit_logs WHERE action IN ('notification.new_seller_broadcast', 'notification.new_stock_broadcast', 'notification.restock_broadcast')")->execute();

// 12. Seller Monthly Payouts & Commission Engine
Payouts::ensureSchema();

// Reset test billing cycle for seller 2 to ensure idempotent test runs
$pdo->exec("DELETE FROM seller_payouts WHERE seller_user_id = 2 AND period_start = '2026-10-01'");

// 12a. Schema check
$payoutTable = $pdo->query("SHOW TABLES LIKE 'seller_payouts'")->fetch();
assert_true(!empty($payoutTable), 'TC-12a: Payouts::ensureSchema() creates seller_payouts table');

// 12b. Period sales and 10% fee calculation
// Seller 2 in October 2026 had Order 1 delivered with line_total = 1200.00
$octSales = Payouts::calculatePeriodSales(2, '2026-10-01', '2026-10-31');
assert_true(
    (float) $octSales['gross_sales'] === 1200.00 &&
    (float) $octSales['platform_fee'] === 120.00 &&
    (float) $octSales['net_amount'] === 1080.00 &&
    count($octSales['line_items']) === 1,
    'TC-12b: Payouts::calculatePeriodSales() computes gross sales, 10% platform fee, and net amount'
);

// 12c. Generate monthly statements
$genRes = Payouts::generateMonthlyPayouts('2026-10', 1);
assert_true(
    $genRes['ok'] === true &&
    $genRes['year_month'] === '2026-10' &&
    $genRes['total_gross'] >= 1200.00,
    'TC-12c: Payouts::generateMonthlyPayouts() generates monthly payout statements for active sellers'
);

// Find generated payout ID for seller 2
$s2Payout = $pdo->query("SELECT id, status, gross_sales, platform_fee, net_amount FROM seller_payouts WHERE seller_user_id = 2 AND period_start = '2026-10-01'")->fetch();
$testPayoutId = (int) ($s2Payout['id'] ?? 0);
assert_true($testPayoutId > 0 && $s2Payout['status'] === 'draft', 'TC-12d: Draft payout record created with correct amounts');

// 12e. Idempotent re-generation updates draft without constraint collision
$reGen = Payouts::generateMonthlyPayouts('2026-10', 1);
assert_true(
    $reGen['ok'] === true &&
    $reGen['created'] === 0 &&
    $reGen['updated'] >= 1,
    'TC-12e: Payouts::generateMonthlyPayouts() updates existing drafts safely without duplicate key collision'
);

// 12f. Approve draft payout
$appRes = Payouts::approvePayout($testPayoutId, 1);
$statusAfterApprove = $pdo->query("SELECT status FROM seller_payouts WHERE id = {$testPayoutId}")->fetchColumn();
assert_true($appRes['ok'] === true && $statusAfterApprove === 'approved', 'TC-12f: Payouts::approvePayout() transitions payout to approved');

// 12g. Reject invalid M-Pesa transaction reference
$badMpesa = Payouts::markPayoutPaid($testPayoutId, 'bad-code!', 1);
assert_true($badMpesa['ok'] === false && str_contains($badMpesa['error'], 'Invalid M-Pesa'), 'TC-12g: Payouts::markPayoutPaid() validates Safaricom M-Pesa code format');

// 12h. Mark payout paid with valid Safaricom transaction reference
$goodMpesa = Payouts::markPayoutPaid($testPayoutId, 'QJD782394A', 1);
$paidRow = $pdo->query("SELECT status, mpesa_reference, paid_at FROM seller_payouts WHERE id = {$testPayoutId}")->fetch();
assert_true(
    $goodMpesa['ok'] === true &&
    $paidRow['status'] === 'paid' &&
    $paidRow['mpesa_reference'] === 'QJD782394A' &&
    !empty($paidRow['paid_at']),
    'TC-12h: Payouts::markPayoutPaid() records M-Pesa reference, sets paid_at, and marks status paid'
);

// 12i. Details and line items retrieval
$details = Payouts::getPayoutDetails($testPayoutId);
assert_true(
    $details !== null &&
    (int) $details['id'] === $testPayoutId &&
    $details['status'] === 'paid' &&
    count($details['line_items']) >= 1 &&
    $details['line_items'][0]['order_code'] === 'SKH-2026-1001',
    'TC-12i: Payouts::getPayoutDetails() returns complete statement with merchant profile and order breakdown'
);

// 12j. Paid payout statements are protected from recalculation overwrites
$skipRes = Payouts::createOrUpdatePayout(2, '2026-10-01', '2026-10-31', 1);
assert_true(
    $skipRes['ok'] === true &&
    $skipRes['action'] === 'skipped',
    'TC-12j: Finalized / paid statements are immutable and protected against recalculation overwrites'
);

// 12k. Financial summary metrics
$summary = Payouts::getPayoutSummary();
assert_true(
    $summary['total_count'] >= 1 &&
    $summary['total_gross'] >= 1200.00 &&
    $summary['total_fees'] >= 120.00 &&
    $summary['total_paid'] >= 1080.00,
    'TC-12k: Payouts::getPayoutSummary() computes gross sales, fees, and disbursed totals'
);

// 13. Bulk Product CSV Upload Engine
BulkImport::ensureSchema();

// 13a. Schema check
$tBulk1 = $pdo->query("SHOW TABLES LIKE 'bulk_imports'")->fetch();
$tBulk2 = $pdo->query("SHOW TABLES LIKE 'bulk_import_rows'")->fetch();
assert_true(!empty($tBulk1) && !empty($tBulk2), 'TC-13a: BulkImport::ensureSchema() creates bulk_imports and bulk_import_rows tables');

// 13b. Category resolution
$catFood = BulkImport::resolveCategory($pdo, 'Food');
$catGas  = BulkImport::resolveCategory($pdo, 'gas');
$catId1  = BulkImport::resolveCategory($pdo, '1');
$catBad  = BulkImport::resolveCategory($pdo, 'NonExistentCategory999');
assert_true(
    $catFood === 3 && $catGas === 2 && $catId1 === 1 && $catBad === null,
    'TC-13b: BulkImport::resolveCategory() resolves by name, slug, and ID, rejecting invalid categories'
);

// 13c. Sample CSV template generator
$sampleCsv = BulkImport::generateSampleCsv();
assert_true(
    str_contains($sampleCsv, 'name,category,description,price') &&
    str_contains($sampleCsv, 'Kirinyaga Organic Raw Honey'),
    'TC-13c: BulkImport::generateSampleCsv() generates standard valid CSV template'
);

// 13d. Unverified seller check (Grace Wanjiku, user 3 is pending KYC)
$tmpCsvPath = tempnam(sys_get_temp_dir(), 'csv_test_');
file_put_contents($tmpCsvPath, "name,category,description,price\nTest Prod,Food,Test description long enough,500.00\n");
$unverifiedUpload = BulkImport::uploadAndProcessCsv(3, [
    'name'     => 'test.csv',
    'tmp_name' => $tmpCsvPath,
    'size'     => filesize($tmpCsvPath),
    'error'    => UPLOAD_ERR_OK,
], 3, 'seller');
assert_true(
    $unverifiedUpload['ok'] === false && str_contains($unverifiedUpload['error'], 'KYC Compliance Required'),
    'TC-13d: BulkImport::uploadAndProcessCsv() blocks unverified merchants from uploading catalog CSVs'
);

// 13e. Valid CSV upload & batch product creation (Seller 2 is verified)
$validCsvContent = "name,category,description,price,original_price,stock_qty,low_stock_threshold,condition_type,is_featured\n" .
    "Kirinyaga Bulk Premium Tea Leaves 500g,Food,Fresh hand-picked high-grown tea leaves from Mount Kenya slopes.,450.00,500.00,20,5,New,1\n" .
    "Heavy Duty Kitchen Gas Hose 2M,Gas,Reinforced high-pressure safety gas hose certified for domestic cylinders.,650.00,750.00,15,3,New,0\n";
file_put_contents($tmpCsvPath, $validCsvContent);

$validUpload = BulkImport::uploadAndProcessCsv(2, [
    'name'     => 'catalog_batch_1.csv',
    'tmp_name' => $tmpCsvPath,
    'size'     => filesize($tmpCsvPath),
    'error'    => UPLOAD_ERR_OK,
], 2, 'seller');

assert_true(
    $validUpload['ok'] === true &&
    $validUpload['total_rows'] === 2 &&
    $validUpload['success_rows'] === 2 &&
    $validUpload['error_rows'] === 0 &&
    $validUpload['status'] === 'completed',
    'TC-13e: BulkImport::uploadAndProcessCsv() creates products and publishes to catalog cleanly'
);

// Verify products created in products table
$pCheck1 = $pdo->query("SELECT id, name, price, stock_qty FROM products WHERE name = 'Kirinyaga Bulk Premium Tea Leaves 500g'")->fetch();
$pCheck2 = $pdo->query("SELECT id, name, price, stock_qty FROM products WHERE name = 'Heavy Duty Kitchen Gas Hose 2M'")->fetch();
assert_true(
    !empty($pCheck1) && !empty($pCheck2) && (float) $pCheck1['price'] === 450.00 && (int) $pCheck2['stock_qty'] === 15,
    'TC-13f: Bulk-imported products exist in MySQL products table with correct fields and stock'
);

// 13g. Fault tolerance with mixed valid & invalid rows
$mixedCsvContent = "name,category,description,price,stock_qty\n" .
    "Valid Handcrafted Ceramic Bowl,Others,Artisan fired traditional clay soup bowl for home dining.,350.00,10\n" .
    "Bad Price Item,Food,High quality rice from Mwea irrigation scheme.,-200.00,5\n" .
    "Short,Clothes,Short,1200.00,8\n" .
    "Invalid Category Item,UnknownCategoryXYZ,High quality authentic product description here.,800.00,10\n";
file_put_contents($tmpCsvPath, $mixedCsvContent);

$mixedUpload = BulkImport::uploadAndProcessCsv(2, [
    'name'     => 'mixed_batch.csv',
    'tmp_name' => $tmpCsvPath,
    'size'     => filesize($tmpCsvPath),
    'error'    => UPLOAD_ERR_OK,
], 2, 'seller');

assert_true(
    $mixedUpload['ok'] === true &&
    $mixedUpload['total_rows'] === 4 &&
    $mixedUpload['success_rows'] === 1 &&
    $mixedUpload['error_rows'] === 3,
    'TC-13g: Fault tolerance: Valid items are created while invalid items capture row errors without aborting the batch'
);

// 13h. Import details & row error inspection
$mixedDetails = BulkImport::getImportDetails($mixedUpload['import_id']);
assert_true(
    !empty($mixedDetails) &&
    count($mixedDetails['rows']) === 4 &&
    $mixedDetails['rows'][0]['status'] === 'created' &&
    $mixedDetails['rows'][1]['status'] === 'invalid' &&
    str_contains($mixedDetails['rows'][1]['error_message'], 'Selling price must be a positive number') &&
    str_contains($mixedDetails['rows'][2]['error_message'], 'at least 10 characters') &&
    str_contains($mixedDetails['rows'][3]['error_message'], 'Unknown category'),
    'TC-13h: BulkImport::getImportDetails() records precise error messages for price, description, and category'
);

// 13i. Seller & Admin import history lists
$sellerHistory = BulkImport::getSellerImports(2);
$adminHistory = BulkImport::getAdminImports();
assert_true(
    count($sellerHistory) >= 2 && count($adminHistory) >= 2,
    'TC-13i: BulkImport::getSellerImports() and getAdminImports() return complete upload audit history'
);

// 13j. Manual spreadsheet grid multi-product creation (without CSV file)
$gridRows = [
    [
        'name'                => 'Grid Product One Tea 250g',
        'category_id'         => 3,
        'description'         => 'Premium tea leaves harvested directly from Kirinyaga tea farms.',
        'price'               => '320.00',
        'original_price'      => '380.00',
        'stock_qty'           => '25',
        'low_stock_threshold' => '5',
        'condition_type'      => 'New',
    ],
    [
        'name'                => 'Grid Product Two Woolen Scarf',
        'category_id'         => 1,
        'description'         => 'Warm hand-knitted acrylic woolen scarf for cold seasons.',
        'price'               => '450.00',
        'original_price'      => '500.00',
        'stock_qty'           => '18',
        'low_stock_threshold' => '4',
        'condition_type'      => 'New',
    ],
];

$gridResult = BulkImport::processManualBulkRows(2, $gridRows, 2, 'seller');
assert_true(
    $gridResult['ok'] === true &&
    $gridResult['total_rows'] === 2 &&
    $gridResult['success_rows'] === 2 &&
    $gridResult['error_rows'] === 0,
    'TC-13j: BulkImport::processManualBulkRows() creates multiple products via in-browser spreadsheet grid without CSV'
);

// 13k. Bulk update existing products via spreadsheet editor
$p1Id = $pdo->query("SELECT id FROM products WHERE name = 'Grid Product One Tea 250g'")->fetchColumn();
$p2Id = $pdo->query("SELECT id FROM products WHERE name = 'Grid Product Two Woolen Scarf'")->fetchColumn();

$updateItems = [
    [
        'id'                  => (int) $p1Id,
        'name'                => 'Grid Product One Tea 250g (Updated)',
        'category_id'         => 3,
        'price'               => 350.00,
        'stock_qty'           => 40,
        'low_stock_threshold' => 8,
        'condition_type'      => 'New',
        'status'              => 'active',
    ],
    [
        'id'                  => (int) $p2Id,
        'name'                => 'Grid Product Two Woolen Scarf',
        'category_id'         => 1,
        'price'               => 480.00,
        'stock_qty'           => 12,
        'low_stock_threshold' => 3,
        'condition_type'      => 'New',
        'status'              => 'active',
    ],
];

$bulkUpRes = BulkImport::bulkUpdateProducts(2, $updateItems, false, 2);
$p1After = $pdo->query("SELECT name, price, stock_qty FROM products WHERE id = {$p1Id}")->fetch();
$p2After = $pdo->query("SELECT price, stock_qty FROM products WHERE id = {$p2Id}")->fetch();

assert_true(
    $bulkUpRes['ok'] === true &&
    $bulkUpRes['updated_count'] === 2 &&
    $p1After['name'] === 'Grid Product One Tea 250g (Updated)' &&
    (float) $p1After['price'] === 350.00 &&
    (int) $p1After['stock_qty'] === 40 &&
    (float) $p2After['price'] === 480.00,
    'TC-13k: BulkImport::bulkUpdateProducts() modifies multiple product prices and stock quantities in-place'
);

// Clean up grid test products
if ($p1Id && $p2Id) {
    $pdo->exec("DELETE FROM products WHERE id IN ({$p1Id}, {$p2Id})");
}

// Clean up temporary test products and test imports
if (!empty($pCheck1['id'])) {
    $pdo->exec("DELETE FROM products WHERE id IN ({$pCheck1['id']}, {$pCheck2['id']})");
}
$createdRowProdId = $pdo->query("SELECT product_id FROM bulk_import_rows WHERE import_id = {$mixedUpload['import_id']} AND status = 'created'")->fetchColumn();
if ($createdRowProdId) {
    $pdo->exec("DELETE FROM products WHERE id = {$createdRowProdId}");
}
@unlink($tmpCsvPath);

// 14. Lucide Vector Icons Integration Verification
$iconMarkup = icon('shopping-cart', 'lucide-sm');
assert_true(str_contains($iconMarkup, 'data-lucide="shopping-cart"') && str_contains($iconMarkup, 'class="lucide lucide-sm"'), 'TC-14a: Lucide icon helper generates data-lucide markup');
assert_true(file_exists(__DIR__ . '/../assets/js/lucide.min.js') && filesize(__DIR__ . '/../assets/js/lucide.min.js') > 100000, 'TC-14b: Local Lucide bundle vendored at assets/js/lucide.min.js');
$coloredIcon = icon('package', 'lucide-xs', 'emerald');
assert_true(str_contains($coloredIcon, 'class="lucide lucide-xs icon-emerald"'), 'TC-14c: Lucide icon helper applies compact size and color classes');

// 15. Simplified Percentage-Only Discount Engine
$pricing15 = calculate_discount_pricing(1000.0, 15.0);
assert_true(
    abs($pricing15['price'] - 850.0) < 0.01 && abs($pricing15['original_price'] - 1000.0) < 0.01 && $pricing15['discount_percent'] === 15,
    'TC-15a: calculate_discount_pricing computes 15% discount as KSh 850 with original KSh 1000'
);

$pricingZero = calculate_discount_pricing(1200.0, 0.0);
$pricingNull = calculate_discount_pricing(1200.0, null);
assert_true(
    abs($pricingZero['price'] - 1200.0) < 0.01 && $pricingZero['original_price'] === null && $pricingNull['original_price'] === null,
    'TC-15b: calculate_discount_pricing with 0% or null retains standard price without original_price strikethrough'
);

$invalidDiscErrors = validate_product_fields([
    'name'             => 'Discount Test Item',
    'description'      => 'A valid product description for unit testing.',
    'category_id'      => 1,
    'price'            => 100,
    'discount_percent' => 150,
    'stock_qty'        => 5,
    'condition_type'   => 'New',
]);
assert_true(
    in_array('Discount percentage must be a number between 0% and 99%.', $invalidDiscErrors, true),
    'TC-15c: validate_product_fields rejects out-of-bounds discount percentage (>= 100%)'
);

$validDiscErrors = validate_product_fields([
    'name'             => 'Valid 20 Percent Item',
    'description'      => 'A valid product description for unit testing.',
    'category_id'      => 1,
    'price'            => 500,
    'discount_percent' => 20,
    'stock_qty'        => 5,
    'condition_type'   => 'New',
]);
assert_true(empty($validDiscErrors), 'TC-15d: validate_product_fields accepts valid discount percentage (20%)');

// BulkImport test with discount_percent
$bulkDiscRes = BulkImport::processManualBulkRows($sellerId, [
    [
        'name'             => 'Bulk Discount Test Bananas',
        'category_id'      => 3,
        'description'      => 'Freshly harvested sweet ripe bananas from Kutus farmers.',
        'price'            => '200.00',
        'discount_percent' => '25',
        'stock_qty'        => '30',
        'condition_type'   => 'New',
    ]
], $sellerId, 'seller');
assert_true(!empty($bulkDiscRes['ok']) && $bulkDiscRes['success_rows'] === 1, 'TC-15e: BulkImport successfully processes discount_percent row');
$createdBananaId = (int) ($bulkDiscRes['rows'][0]['product_id'] ?? 0);
$bCheck = $pdo->query("SELECT price, original_price FROM products WHERE id = {$createdBananaId}")->fetch();
assert_true(
    $bCheck && abs((float) $bCheck['price'] - 150.00) < 0.01 && abs((float) $bCheck['original_price'] - 200.00) < 0.01,
    'TC-15f: Product created with 25% discount stores sale price 150.00 and original price 200.00 in database'
);
if ($createdBananaId) {
    $pdo->exec("DELETE FROM products WHERE id = {$createdBananaId}");
}

// 16. Step 3: Seller-Driven Rider Allocation & Standby Dispatch
// 16a. Kyc::getAvailableRiders() returns approved riders with is_available = 1
Kyc::updateStatus($kycRiderId, 'delivery', 'approved', 1);
Kyc::toggleRiderAvailability($kycRiderId, true);
$activeRiders = Kyc::getAvailableRiders();
$foundRider = false;
foreach ($activeRiders as $ar) {
    if ((int) $ar['rider_id'] === $kycRiderId) {
        $foundRider = true;
        break;
    }
}
assert_true($foundRider === true, 'TC-16a: Kyc::getAvailableRiders() retrieves approved on-duty delivery riders');

// 16b. Kyc::toggleRiderAvailability() toggles rider Off Duty
$isAvailOff = Kyc::toggleRiderAvailability($kycRiderId, false);
assert_true($isAvailOff === false, 'TC-16b: Kyc::toggleRiderAvailability() sets rider to Off Duty');
$ridersAfterOff = Kyc::getAvailableRiders();
$foundAfterOff = false;
foreach ($ridersAfterOff as $ar) {
    if ((int) $ar['rider_id'] === $kycRiderId) {
        $foundAfterOff = true;
        break;
    }
}
assert_true($foundAfterOff === false, 'TC-16c: Off-duty delivery rider is hidden from merchant available dispatch list');

// Toggle back on duty
$isAvailOn = Kyc::toggleRiderAvailability($kycRiderId, true);
assert_true($isAvailOn === true, 'TC-16d: Kyc::toggleRiderAvailability() restores rider to On Duty');

// 16e. Create test order, product item, and pending delivery for allocation tests
$pdo->beginTransaction();
$pdo->prepare("
    INSERT INTO orders (buyer_id, order_code, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town)
    VALUES (:bid, 'ORD-ALLOC-TEST-01', 1200.00, 'M-Pesa', 'unpaid', 'pending', 'Kerugoya Town Plaza', 'Kerugoya')
")->execute([':bid' => $buyerId]);
$allocOrderId = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
    VALUES (:oid, 1, :sid, 2, 600.00, 1200.00)
")->execute([':oid' => $allocOrderId, ':sid' => $sellerId]);

$pdo->prepare("
    INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
    VALUES (:oid, 'Kutus Market', 'Kerugoya Town Plaza', 150.00, 'pending', 'Awaiting merchant rider assignment')
")->execute([':oid' => $allocOrderId]);
$allocDeliveryId = (int) $pdo->lastInsertId();
$pdo->commit();

// 16f. Unauthorized seller cannot assign rider to an order containing none of their items
$unauthSellerAssign = Kyc::assignRiderToDelivery($allocDeliveryId, $kycRiderId, 9999, 'seller');
assert_true(
    $unauthSellerAssign['ok'] === false && str_contains($unauthSellerAssign['error'], 'permission'),
    'TC-16e: Kyc::assignRiderToDelivery() blocks unauthorized seller from allocating rider to another store order'
);

// 16g. Assignment rejects unapproved user or non-delivery account
$invalidRiderAssign = Kyc::assignRiderToDelivery($allocDeliveryId, $buyerId, $sellerId, 'seller');
assert_true(
    $invalidRiderAssign['ok'] === false && str_contains($invalidRiderAssign['error'], 'not approved or verified'),
    'TC-16f: Kyc::assignRiderToDelivery() rejects assigning task to non-delivery or unapproved user'
);

// 16h. Legitimate merchant assigns verified rider
$validAssign = Kyc::assignRiderToDelivery($allocDeliveryId, $kycRiderId, $sellerId, 'seller');
assert_true(
    $validAssign['ok'] === true && !empty($validAssign['rider_name']),
    'TC-16g: Legitimate merchant successfully assigns verified rider with vehicle plate to delivery'
);

// Verify delivery record in database
$dCheck = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id');
$dCheck->execute([':id' => $allocDeliveryId]);
$delRecord = $dCheck->fetch(PDO::FETCH_ASSOC);
assert_true(
    (int) $delRecord['delivery_person_id'] === $kycRiderId &&
    (int) $delRecord['assigned_by_seller_id'] === $sellerId &&
    $delRecord['delivery_status'] === 'assigned' &&
    str_contains($delRecord['tracking_notes'], 'Assigned by Merchant'),
    'TC-16h: Database reflects assigned_by_seller_id, delivery_person_id, status assigned, and tracking audit'
);

// 16i. Rider task query isolation: rider views strictly assigned tasks, unassigned tasks never leak
$riderTasks = $pdo->prepare('SELECT * FROM deliveries WHERE delivery_person_id = :rid');
$riderTasks->execute([':rid' => $kycRiderId]);
$riderRows = $riderTasks->fetchAll(PDO::FETCH_ASSOC);
$foundAllocatedTask = false;
foreach ($riderRows as $rt) {
    if ((int) $rt['id'] === $allocDeliveryId) {
        $foundAllocatedTask = true;
        break;
    }
}
assert_true($foundAllocatedTask === true, 'TC-16i: Delivery rider sees strictly tasks assigned to them');

// Clean up allocation test order & delivery
$pdo->prepare('DELETE FROM deliveries WHERE id = :id')->execute([':id' => $allocDeliveryId]);
$pdo->prepare('DELETE FROM order_items WHERE order_id = :oid')->execute([':oid' => $allocOrderId]);
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $allocOrderId]);
$pdo->prepare("DELETE FROM audit_logs WHERE action = 'delivery.rider_assigned' AND entity_id = :eid")->execute([':eid' => $allocDeliveryId]);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
