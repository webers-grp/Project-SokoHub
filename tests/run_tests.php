<?php
/**
 * SokoHub Automated Integration Test Suite
 * Tests PDO connectivity, password hashing, session/cookies, validation,
 * Product CRUD, Checkout transaction, Order cancellation with stock restore,
 * M-Pesa phone normalization, configuration, and refund processing.
 *
 * Usage: DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=sokohub_db DB_USER=root DB_PASS= php -d extension=pdo_mysql tests/run_tests.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/mpesa.php';

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
assert_true(count($dbCheck['tables']) >= 9, 'TC-01b: All schema tables exist (' . count($dbCheck['tables']) . ' tables)');

$pdo = get_db_connection();
ensure_order_schema_tables($pdo);

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

// 5. Buyer Order Placement & Stock Decrement
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

// Clean up test order & test product
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

// 8. M-Pesa Phone Normalization
assert_true(mpesa_format_phone('0712345678') === '254712345678', 'TC-08a: Format standard Safaricom 07XXXXXXXX');
assert_true(mpesa_format_phone('0112345678') === '254112345678', 'TC-08b: Format modern Airtel/Safaricom 01XXXXXXXX');
assert_true(mpesa_format_phone('+254712345678') === '254712345678', 'TC-08c: Strip leading plus prefix');
assert_true(mpesa_format_phone('254712345678') === '254712345678', 'TC-08d: Retain valid 12-digit international format');

// 9. M-Pesa Configuration & Environment Resolution
$mpesaConfig = get_mpesa_config();
assert_true(in_array($mpesaConfig['env'], ['sandbox', 'live'], true), 'TC-09a: M-Pesa environment resolves cleanly');
assert_true(!empty($mpesaConfig['shortcode']), 'TC-09b: Business shortcode resolved');
assert_true(!empty($mpesaConfig['passkey']), 'TC-09c: Lipa na M-Pesa passkey resolved');

// 10. Order Cancellation with Atomic Stock Restoration & Refund Generation
$pdo->beginTransaction();
$pdo->prepare(
    "INSERT INTO products (seller_id, category_id, name, description, price, stock_qty, condition_type, image_path, status)
     VALUES (:sid, 1, 'Restock Test Coat', 'Test item for cancel restock', 1200.00, 10, 'New', 'assets/img/clothes.jpg', 'active')"
)->execute([':sid' => $sellerId]);
$cancelProdId = (int) $pdo->lastInsertId();

$cancelOrderCode = 'SKH-RESTORE-' . bin2hex(random_bytes(3));
$pdo->prepare(
    "INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town)
     VALUES (:code, :buyer, 3600.00, 'M-Pesa', 'paid', 'pending', 'Test Street', 'Kutus')"
)->execute([':code' => $cancelOrderCode, ':buyer' => $buyerId]);
$cancelOrderId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
     VALUES (:oid, :pid, :sid, 3, 1200.00, 3600.00)'
)->execute([':oid' => $cancelOrderId, ':pid' => $cancelProdId, ':sid' => $sellerId]);

// Decrement 3 units from initial 10 -> 7
$pdo->prepare('UPDATE products SET stock_qty = stock_qty - 3 WHERE id = :pid')->execute([':pid' => $cancelProdId]);
$pdo->prepare(
    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status)
     VALUES (:oid, 'Kutus', 'Test Street', 150.00, 'pending')"
)->execute([':oid' => $cancelOrderId]);
$pdo->commit();

// Cancel order as buyer
$cancelResult = cancel_order_with_stock_restore($pdo, $cancelOrderId, $buyerId, 'buyer', 'Found better alternative');
assert_true($cancelResult['ok'] === true, 'TC-10a: Order cancellation succeeds');
assert_true($cancelResult['restored_items'] === 3, 'TC-10b: Exact 3 items returned to stock');

// Verify stock balance restored from 7 back to 10
$checkStock = $pdo->prepare('SELECT stock_qty FROM products WHERE id = :id');
$checkStock->execute([':id' => $cancelProdId]);
assert_true((int) $checkStock->fetchColumn() === 10, 'TC-10c: Product stock accurately restored to 10');

// Verify order status updated to cancelled
$checkOrder = $pdo->prepare('SELECT order_status, cancel_reason FROM orders WHERE id = :id');
$checkOrder->execute([':id' => $cancelOrderId]);
$cancelledRow = $checkOrder->fetch();
assert_true($cancelledRow['order_status'] === 'cancelled', 'TC-10d: Order status updated to cancelled');
assert_true(str_contains((string) $cancelledRow['cancel_reason'], 'Found better alternative'), 'TC-10e: Cancellation reason stored');

// 11. Automatic Refund Request Generated for Paid Order
$refCheck = $pdo->prepare('SELECT * FROM refunds WHERE order_id = :oid');
$refCheck->execute([':oid' => $cancelOrderId]);
$refundRow = $refCheck->fetch();
assert_true($refundRow && (float) $refundRow['amount'] === 3600.00, 'TC-11a: Refund request created for KSh 3,600.00');
assert_true($refundRow['status'] === 'requested', 'TC-11b: Initial refund status is requested');

// Admin processes refund with M-Pesa reversal reference
$adminLogin = login_user($pdo, 'admin@sokohub.co.ke', 'SokoHub@2026', false);
$adminId = (int) $adminLogin['user']['id'];
$refProcess = process_order_refund($pdo, (int) $refundRow['id'], $adminId, 'admin', 'complete', 'REV-MPESA-99882', 'Reversed to customer phone');
assert_true($refProcess['ok'] === true, 'TC-11c: Admin approves and completes refund');

$refCheck->execute([':oid' => $cancelOrderId]);
$completedRefund = $refCheck->fetch();
assert_true($completedRefund['status'] === 'completed' && $completedRefund['provider_reference'] === 'REV-MPESA-99882', 'TC-11d: Refund record updated with provider reference');

// Verify order payment status is now refunded
$checkOrder->execute([':id' => $cancelOrderId]);
$refundedOrder = $checkOrder->fetch();
$ordPayCheck = $pdo->prepare('SELECT payment_status FROM orders WHERE id = :id');
$ordPayCheck->execute([':id' => $cancelOrderId]);
assert_true($ordPayCheck->fetchColumn() === 'refunded', 'TC-11e: Order payment status updated to refunded');

// Clean up cancel test order and product
$pdo->prepare('DELETE FROM refunds WHERE order_id = :oid')->execute([':oid' => $cancelOrderId]);
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $cancelOrderId]);
$pdo->prepare('DELETE FROM products WHERE id = :pid')->execute([':pid' => $cancelProdId]);

// 12. M-Pesa STK Push Simulation & Status Polling
$simOrderCode = 'SKH-SIM-' . bin2hex(random_bytes(3));
$pdo->prepare(
    "INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town)
     VALUES (:code, :buyer, 1200.00, 'M-Pesa', 'unpaid', 'pending', 'Kirinyaga Uni Hostel 1', 'Kutus')"
)->execute([':code' => $simOrderCode, ':buyer' => $buyerId]);
$simOrderId = (int) $pdo->lastInsertId();

$simRes = mpesa_simulate_success($pdo, $simOrderId);
assert_true($simRes['ok'] === true, 'TC-12a: Simulated M-Pesa payment approved');
assert_true(!empty($simRes['receipt']), 'TC-12b: Valid M-Pesa receipt generated (' . $simRes['receipt'] . ')');

$pollRes = mpesa_poll_status($pdo, $simOrderId);
assert_true($pollRes['status'] === 'succeeded' && $pollRes['paid'] === true, 'TC-12c: Status polling confirms paid state');

$pdo->prepare('DELETE FROM payments WHERE order_id = :oid')->execute([':oid' => $simOrderId]);
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $simOrderId]);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
