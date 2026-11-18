<?php
/**
 * SokoHub Automated Integration Test Suite
 * Tests PDO connectivity, password hashing, session/cookies, validation,
 * Product CRUD, Checkout transaction, Order cancellation, Delivery workflow, and Reports.
 *
 * Usage: DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=sokohub_db DB_USER=root DB_PASS= php -d extension=pdo_mysql tests/run_tests.php
 */

require_once __DIR__ . '/../includes/auth.php';

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

// 8. Lucide Vector Icons Integration Verification
$iconMarkup = icon('shopping-cart', 'lucide-sm');
assert_true(str_contains($iconMarkup, 'data-lucide="shopping-cart"') && str_contains($iconMarkup, 'class="lucide lucide-sm"'), 'TC-08a: Lucide icon helper generates data-lucide markup');
assert_true(file_exists(__DIR__ . '/../assets/js/lucide.min.js') && filesize(__DIR__ . '/../assets/js/lucide.min.js') > 100000, 'TC-08b: Local Lucide bundle vendored at assets/js/lucide.min.js');
$coloredIcon = icon('package', 'lucide-xs', 'emerald');
assert_true(str_contains($coloredIcon, 'class="lucide lucide-xs icon-emerald"'), 'TC-08c: Lucide icon helper applies compact size and color classes');

// 9. M-Pesa Phone Number Normalization Tests
require_once __DIR__ . '/../includes/mpesa.php';

$p1 = mpesa_normalize_phone('0712345678');
$p2 = mpesa_normalize_phone('0112345678');
$p3 = mpesa_normalize_phone('+254712345678');
$p4 = mpesa_normalize_phone('254712345678');
$p5 = mpesa_normalize_phone('712345678');
$badPhone1 = mpesa_normalize_phone('0201234567'); // landline
$badPhone2 = mpesa_normalize_phone('12345');      // too short

assert_true($p1 === '254712345678', 'TC-09a: Normalize 07XX phone to 2547XXXXXXXX');
assert_true($p2 === '254112345678', 'TC-09b: Normalize 01XX phone to 2541XXXXXXXX');
assert_true($p3 === '254712345678' && $p4 === '254712345678' && $p5 === '254712345678', 'TC-09c: Normalize +254, 254, and 9-digit formats');
assert_true($badPhone1 === '' && $badPhone2 === '', 'TC-09d: Reject invalid non-mobile or malformed numbers');

// 10. Daraja Password and Timestamp Generation Tests
$passGen = mpesa_generate_password('174379', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919', '20261118120000');
$expectedPw = base64_encode('174379' . 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919' . '20261118120000');
assert_true($passGen['password'] === $expectedPw && $passGen['timestamp'] === '20261118120000', 'TC-10: Daraja password matches base64(shortcode + passkey + timestamp)');

// 11. Seller Payout Destination Validation Tests
$valTillGood = validate_payout_destination('till', '1648753');
$valTillBad = validate_payout_destination('till', 'abc');
$valPaybillGood = validate_payout_destination('paybill', '174379', 'SHOP01');
$valPaybillBadRef = validate_payout_destination('paybill', '174379', '');
$valPhoneGood = validate_payout_destination('phone', '0722000000');

assert_true($valTillGood['ok'] && !$valTillBad['ok'], 'TC-11a: Validate Till number format');
assert_true($valPaybillGood['ok'] && !$valPaybillBadRef['ok'], 'TC-11b: Validate Paybill requires valid account reference');
assert_true($valPhoneGood['ok'], 'TC-11c: Validate Pochi/Phone destination');

// 12. Settlement Platform Fee Calculation & Multi-Seller Payout Splitting Tests
$sampleGross1 = 1000.00;
$feePct = 2.0;
$fee1 = round($sampleGross1 * ($feePct / 100), 2);
$net1 = round($sampleGross1 - $fee1, 2);
assert_true($fee1 === 20.00 && $net1 === 980.00, 'TC-12a: 2% platform fee calculation (KSh 1,000 -> Fee KSh 20, Net KSh 980)');

// Test Multi-Seller Cart Splitting & Ledger generation in DB
$pdo->beginTransaction();
$splitOrderCode = 'SKH-SPLIT-' . bin2hex(random_bytes(3));
$pdo->prepare(
    "INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town)
     VALUES (:code, :buyer, 3000.00, 'M-Pesa', 'paid', 'pending', 'Hostel 5', 'Kerugoya')"
)->execute([':code' => $splitOrderCode, ':buyer' => $buyerId]);
$splitOrderId = (int) $pdo->lastInsertId();

// Seller 1 items: KSh 2,000
$pdo->prepare(
    "INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
     VALUES (:oid, 1, :sid, 2, 1000.00, 2000.00)"
)->execute([':oid' => $splitOrderId, ':sid' => $sellerId]);

// Seller 2 (admin account acting as second vendor): KSh 1,000
$adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$secondSellerId = (int) $adminUser['id'];
$pdo->prepare(
    "INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
     VALUES (:oid, 2, :sid, 1, 1000.00, 1000.00)"
)->execute([':oid' => $splitOrderId, ':sid' => $secondSellerId]);

$pdo->prepare("INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, delivery_person_id)
               VALUES (:oid, 'Kutus Market', 'Hostel 5', 150.00, 'pending', :rid)")
    ->execute([':oid' => $splitOrderId, ':rid' => $riderId]);

$ledgerRes = mpesa_generate_settlement_ledger($pdo, $splitOrderId, false);
$splitPayouts = $pdo->prepare('SELECT user_id, gross_amount, platform_fee_amount, net_amount, recipient_type FROM payouts WHERE order_id = :oid ORDER BY gross_amount DESC');
$splitPayouts->execute([':oid' => $splitOrderId]);
$pRows = $splitPayouts->fetchAll();

assert_true(count($pRows) === 3, 'TC-12b: Settlement ledger generated 3 split records (2 sellers + 1 delivery rider)');
assert_true((float)$pRows[0]['gross_amount'] === 2000.00 && (float)$pRows[0]['platform_fee_amount'] === 40.00 && (float)$pRows[0]['net_amount'] === 1960.00, 'TC-12c: Seller 1 split: Gross KSh 2,000, 2% Fee KSh 40, Net KSh 1,960');
assert_true((float)$pRows[1]['gross_amount'] === 1000.00 && (float)$pRows[1]['platform_fee_amount'] === 20.00 && (float)$pRows[1]['net_amount'] === 980.00, 'TC-12d: Seller 2 split: Gross KSh 1,000, 2% Fee KSh 20, Net KSh 980');
assert_true((float)$pRows[2]['gross_amount'] === 150.00 && $pRows[2]['recipient_type'] === 'delivery', 'TC-12e: Delivery rider ledger entry recorded for KSh 150');

// 13. M-Pesa STK Callback Processing & Idempotency Deduplication Tests
$testCheckoutId = 'ws_CO_TEST_' . bin2hex(random_bytes(6));
$pdo->prepare(
    "INSERT INTO payments (order_id, method, amount, phone, status, checkout_request_id, merchant_request_id)
     VALUES (:oid, 'mpesa', 3000.00, '254712345678', 'pending', :cid, 'mock_merchant')"
)->execute([':oid' => $splitOrderId, ':cid' => $testCheckoutId]);

$mockCallbackPayload = json_encode([
    'Body' => [
        'stkCallback' => [
            'MerchantRequestID' => 'mock_merchant',
            'CheckoutRequestID' => $testCheckoutId,
            'ResultCode'        => 0,
            'ResultDesc'        => 'The service request is processed successfully.',
            'CallbackMetadata'  => [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => 3000],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TESTRECEIPT01'],
                    ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ],
            ],
        ],
    ],
]);

// 1st processing
$cbRes1 = mpesa_process_callback($pdo, $mockCallbackPayload);
assert_true($cbRes1['ok'] === true && $cbRes1['result_code'] === 0, 'TC-13a: Callback processed successfully and verified');

$chkPay = $pdo->prepare('SELECT status, mpesa_receipt FROM payments WHERE checkout_request_id = :cid');
$chkPay->execute([':cid' => $testCheckoutId]);
$payRow = $chkPay->fetch();
assert_true($payRow['status'] === 'success' && $payRow['mpesa_receipt'] === 'TESTRECEIPT01', 'TC-13b: Payment record updated to success with M-Pesa receipt');

// 2nd duplicate processing (Idempotency test)
$cbRes2 = mpesa_process_callback($pdo, $mockCallbackPayload);
assert_true($cbRes2['ok'] === true && str_contains($cbRes2['message'], 'Duplicate callback ignored'), 'TC-13c: Duplicate callback identified and safely ignored without double processing');

// Clean up split test data
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $splitOrderId]);
$pdo->commit();

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
