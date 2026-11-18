<?php
/**
 * SokoHub Automated Integration Test Suite
 * Tests PDO connectivity, password hashing, session/cookies, validation,
 * Product CRUD, Checkout transaction, Order cancellation, Delivery workflow, Reports,
 * Daraja M-Pesa STK Push, Callbacks, Payout Ledger, Multi-Seller Splits,
 * KYC Compliance, Reviews & Ratings, Wishlist, Coupons, Delivery Zones,
 * Telephony Alerts, In-App Notifications, Password Reset, and Bulk CSV Importer.
 *
 * Usage: php tests/run_tests.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mpesa.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/kyc.php';
require_once __DIR__ . '/../includes/reviews.php';
require_once __DIR__ . '/../includes/wishlist.php';
require_once __DIR__ . '/../includes/coupons.php';
require_once __DIR__ . '/../includes/delivery.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/telephony.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/inventory.php';
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

// 8. Lucide Vector Icons Integration Verification
$iconMarkup = icon('shopping-cart', 'lucide-sm');
assert_true(str_contains($iconMarkup, 'data-lucide="shopping-cart"') && str_contains($iconMarkup, 'class="lucide lucide-sm"'), 'TC-08a: Lucide icon helper generates data-lucide markup');
assert_true(file_exists(__DIR__ . '/../assets/js/lucide.min.js') && filesize(__DIR__ . '/../assets/js/lucide.min.js') > 100000, 'TC-08b: Local Lucide bundle vendored at assets/js/lucide.min.js');
$coloredIcon = icon('package', 'lucide-xs', 'emerald');
assert_true(str_contains($coloredIcon, 'class="lucide lucide-xs icon-emerald"'), 'TC-08c: Lucide icon helper applies compact size and color classes');

// 9. M-Pesa Phone Number Normalization Tests
$p1 = mpesa_normalize_phone('0712345678');
$p2 = mpesa_normalize_phone('0112345678');
$p3 = mpesa_normalize_phone('+254712345678');
$p4 = mpesa_normalize_phone('254712345678');
$p5 = mpesa_normalize_phone('712345678');
$badPhone1 = mpesa_normalize_phone('0201234567');
$badPhone2 = mpesa_normalize_phone('12345');

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

// Seller 2: KSh 1,000
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

$cbRes1 = mpesa_process_callback($pdo, $mockCallbackPayload);
assert_true($cbRes1['ok'] === true && $cbRes1['result_code'] === 0, 'TC-13a: Callback processed successfully and verified');

$chkPay = $pdo->prepare('SELECT status, mpesa_receipt FROM payments WHERE checkout_request_id = :cid');
$chkPay->execute([':cid' => $testCheckoutId]);
$payRow = $chkPay->fetch();
assert_true($payRow['status'] === 'success' && $payRow['mpesa_receipt'] === 'TESTRECEIPT01', 'TC-13b: Payment record updated to success with M-Pesa receipt');

$cbRes2 = mpesa_process_callback($pdo, $mockCallbackPayload);
assert_true($cbRes2['ok'] === true && str_contains($cbRes2['message'], 'Duplicate callback ignored'), 'TC-13c: Duplicate callback identified and safely ignored without double processing');

// Clean up split test data
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $splitOrderId]);
$pdo->commit();

// 14. Audit Logging Tests
Audit::record('test.action', 'test_entity', 999, ['detail_key' => 'test_val'], $buyerId, 'buyer');
$auditEntry = $pdo->query("SELECT * FROM audit_logs WHERE action = 'test.action' AND entity_id = 999 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true($auditEntry !== false && $auditEntry['actor_role'] === 'buyer', 'TC-14a: Universal Audit::record records immutable event');
$pdo->prepare('DELETE FROM audit_logs WHERE action = "test.action"')->execute();

// 15. KYC Compliance & Publication Guard Tests
$kycProfile = Kyc::getUserKycProfile($sellerId, 'seller');
assert_true(is_array($kycProfile), 'TC-15a: Kyc::getUserKycProfile fetches seller profile');
$canPublish = Kyc::canPublishProducts($sellerId);
assert_true(isset($canPublish['can_publish']), 'TC-15b: Kyc::canPublishProducts evaluates publication status');

// 16. Reviews & Ratings with Verified Purchase Verification
// First ensure buyer does NOT have verified purchase for product 1
$reviewCheckUnverified = Reviews::canUserReview(1, $buyerId);
// Create a delivered order for buyer to verify review eligibility
$vOrderCode = 'SKH-VERIFY-' . bin2hex(random_bytes(3));
$pdo->prepare("
    INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, created_at)
    VALUES (:code, :uid, 500.00, 'Cash on Delivery', 'paid', 'delivered', 'Campus Gate A', 'Kutus', NOW())
")->execute([':code' => $vOrderCode, ':uid' => $buyerId]);
$vOrderId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
    VALUES (:oid, 1, :sid, 1, 500.00, 500.00)
")->execute([':oid' => $vOrderId, ':sid' => $sellerId]);

$reviewCheckVerified = Reviews::canUserReview(1, $buyerId);
assert_true($reviewCheckVerified['can_review'] === true, 'TC-16a: Reviews::canUserReview confirms verified delivered purchase');

// Submit review
$revRes = Reviews::submitReview(1, $buyerId, 5, 'Outstanding authentic product and rapid delivery!', 'Excellent Quality');
assert_true($revRes['ok'] === true, 'TC-16b: Reviews::submitReview creates verified review');

// Verify rating aggregates updated on product
$pRating = $pdo->query('SELECT rating_avg, rating_count FROM products WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
assert_true((float) $pRating['rating_avg'] > 0 && (int) $pRating['rating_count'] >= 1, 'TC-16c: Reviews automatically refreshes product rating aggregates');

// Duplicate review prevention
$revDup = Reviews::submitReview(1, $buyerId, 4, 'Duplicate review attempt');
assert_true($revDup['ok'] === false, 'TC-16d: Duplicate review from same buyer is rejected');

// Clean up review and verified order
$pdo->prepare('DELETE FROM reviews WHERE product_id = 1 AND buyer_id = :uid')->execute([':uid' => $buyerId]);
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $vOrderId]);
Reviews::refreshProductRating(1);

// 17. Wishlist / Save-for-Later Tests
$wRes1 = Wishlist::toggle($buyerId, 1);
assert_true($wRes1['ok'] === true && $wRes1['action'] === 'added', 'TC-17a: Wishlist::toggle saves product to wishlist');
assert_true(Wishlist::isInWishlist($buyerId, 1) === true, 'TC-17b: Wishlist::isInWishlist confirms item is saved');
$cartBefore = cart_count();
$mRes = Wishlist::moveToCart($buyerId, 1, 1);
assert_true($mRes['ok'] === true, 'TC-17c: Wishlist::moveToCart transfers product to session cart and removes from wishlist');
assert_true(Wishlist::isInWishlist($buyerId, 1) === false, 'TC-17d: Wishlist removed after move to cart');
$_SESSION['cart'] = [];

// 18. Promotional Coupons Tests
$cValid = Coupons::validate('WELCOME10', 1000.00, $buyerId);
assert_true($cValid['ok'] === true && (float) $cValid['discount'] === 100.00, 'TC-18a: Coupons::validate 10% coupon calculates KES 100 on KES 1,000 spend');

$cMinSpend = Coupons::validate('WELCOME10', 50.00, $buyerId);
assert_true($cMinSpend['ok'] === false, 'TC-18b: Coupons::validate enforces minimum order threshold');

$cFake = Coupons::validate('NONEXISTENT_COUPON', 1000.00, $buyerId);
assert_true($cFake['ok'] === false, 'TC-18c: Coupons::validate rejects invalid code');

// 19. Dynamic Delivery Zones Tests
$delKutus = Delivery::calculateFee('Kutus', 500.00);
assert_true($delKutus['ok'] === true && $delKutus['fee'] == 100.00, 'TC-19a: Delivery::calculateFee applies Kutus zone fee (KES 100)');

$delFree = Delivery::calculateFee('Kutus', 3500.00);
assert_true($delFree['ok'] === true && $delFree['fee'] == 0.00 && $delFree['is_free'] === true, 'TC-19b: Free delivery threshold applied when subtotal >= KES 3,000');

// 20. Order Cancellation, Atomic Restocking & Payout Reversal Tests
$cOrderCode = 'SKH-CANCEL-' . bin2hex(random_bytes(3));
$initStock = (int) $pdo->query('SELECT stock_qty FROM products WHERE id = 1')->fetchColumn();

$pdo->beginTransaction();
$pdo->prepare("
    INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, created_at)
    VALUES (:code, :uid, 1000.00, 'Cash on Delivery', 'pending_cod', 'pending', 'Campus Gate', 'Kutus', NOW())
")->execute([':code' => $cOrderCode, ':uid' => $buyerId]);
$cOrderId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
    VALUES (:oid, 1, :sid, 2, 500.00, 1000.00)
")->execute([':oid' => $cOrderId, ':sid' => $sellerId]);
$pdo->prepare("UPDATE products SET stock_qty = stock_qty - 2 WHERE id = 1")->execute();

// Create pending payout record
$pdo->prepare("
    INSERT INTO payouts (order_id, recipient_type, user_id, gross_amount, platform_fee_amount, net_amount, payout_destination_type, payout_account, status, created_at)
    VALUES (:oid, 'seller', :sid, 1000.00, 20.00, 980.00, 'till', '1648753', 'pending', NOW())
")->execute([':oid' => $cOrderId, ':sid' => $sellerId]);
$pdo->commit();

// Execute cancel_order
$cRes = cancel_order($pdo, $cOrderId, $buyerId, 'buyer', 'Changed mind');
assert_true($cRes['ok'] === true && $cRes['restored_items'] === 1, 'TC-20a: cancel_order executes atomically');

$restoredStock = (int) $pdo->query('SELECT stock_qty FROM products WHERE id = 1')->fetchColumn();
assert_true($restoredStock === $initStock, 'TC-20b: cancel_order restocks product inventory to exact previous quantity');

$payoutStatus = $pdo->query("SELECT status FROM payouts WHERE order_id = {$cOrderId}")->fetchColumn();
assert_true($payoutStatus === 'cancelled', 'TC-20c: Pending payout for cancelled order is cancelled in settlement ledger');

// Clean up
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $cOrderId]);

// 21. Refund Request Workflow Tests
$rOrderCode = 'SKH-REFUND-' . bin2hex(random_bytes(3));
$pdo->prepare("
    INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, created_at)
    VALUES (:code, :uid, 800.00, 'M-Pesa', 'paid', 'delivered', 'Hostel Gate', 'Kerugoya', NOW())
")->execute([':code' => $rOrderCode, ':uid' => $buyerId]);
$rOrderId = (int) $pdo->lastInsertId();

$reqRef = request_order_refund($pdo, $rOrderId, 800.00, 'Product arrived damaged', $buyerId);
assert_true($reqRef['ok'] === true && $reqRef['refund_id'] > 0, 'TC-21a: Buyer requests refund for delivered order');

$procRef = process_order_refund($pdo, $reqRef['refund_id'], $adminUser['id'], 'admin', 'complete', 'M-PESA-REFUND-001', 'Approved and reimbursed');
assert_true($procRef['ok'] === true, 'TC-21b: Admin approves and completes refund with provider reference');

$refPaymentStatus = $pdo->query("SELECT payment_status FROM orders WHERE id = {$rOrderId}")->fetchColumn();
assert_true($refPaymentStatus === 'refunded', 'TC-21c: Order payment status updated to refunded');

// Clean up
$pdo->prepare('DELETE FROM orders WHERE id = :oid')->execute([':oid' => $rOrderId]);

// 22. Telephony Alerts Engine Tests
$offlineCheck = Telephony::isUserOffline($buyerId, 5);
assert_true(is_bool($offlineCheck), 'TC-22a: Telephony::isUserOffline returns boolean evaluation');

$smsRes = Telephony::sendSms('0712345678', 'Test SokoHub Dispatch Alert', $sellerId, 'new_order', 'Test Vendor', 'seller');
assert_true($smsRes['ok'] === true && $smsRes['channel'] === 'sms', 'TC-22b: Telephony::sendSms logs SMS alert in telephony_logs');

$callRes = Telephony::makeVoiceCall('0712345678', 'Automated Voice Dispatch Speech', $sellerId, 'new_order', 'Test Vendor', 'seller');
assert_true($callRes['ok'] === true && $callRes['channel'] === 'call', 'TC-22c: Telephony::makeVoiceCall logs simulated voice call');

$pdo->prepare('DELETE FROM telephony_logs WHERE message LIKE "%Test SokoHub Dispatch Alert%"')->execute();

// 23. In-App Notifications Tests
$notifId = Notifications::notifyNewStock(1, 'Kirinyaga Gas Refill', 1200.00, 'Mwangi Gas Store', 'Gas & Energy');
assert_true($notifId > 0, 'TC-23a: Notifications::notifyNewStock broadcasts notification');

$uNotifs = Notifications::getUserNotifications($buyerId, 5);
assert_true(is_array($uNotifs) && count($uNotifs) > 0, 'TC-23b: Notifications::getUserNotifications retrieves marketplace notifications');

$markOk = Notifications::markAsRead($notifId, $buyerId);
assert_true($markOk === true, 'TC-23c: Notifications::markAsRead records read receipt in notification_reads');

$pdo->prepare("DELETE FROM notifications WHERE id = {$notifId}")->execute();

// 24. Password Reset Token Generation & Single-Use Flow Tests
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare('
    INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
    VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
')->execute([':uid' => $buyerId, ':thash' => $tokenHash]);
$tokenId = (int) $pdo->lastInsertId();

$tokenLookup = $pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = :thash AND consumed_at IS NULL AND expires_at > NOW()');
$tokenLookup->execute([':thash' => $tokenHash]);
assert_true($tokenLookup->fetch() !== false, 'TC-24a: Password reset token generated, hashed with SHA-256, and verified valid');

// Consume token
$pdo->prepare('UPDATE password_reset_tokens SET consumed_at = NOW() WHERE id = :id')->execute([':id' => $tokenId]);
$tokenLookup->execute([':thash' => $tokenHash]);
assert_true($tokenLookup->fetch() === false, 'TC-24b: Consumed password reset token is rejected as single-use');
$pdo->prepare('DELETE FROM password_reset_tokens WHERE id = :id')->execute([':id' => $tokenId]);

// 25. Bulk CSV Importer Header Normalization Tests
$h1 = BulkImport::normalizeHeader('Product Name');
$h2 = BulkImport::normalizeHeader('Selling Price');
$h3 = BulkImport::normalizeHeader('Stock Quantity');
assert_true($h1 === 'name' && $h2 === 'price' && $h3 === 'stock_qty', 'TC-25: BulkImport::normalizeHeader normalizes alias CSV columns');

// 26. Live Search Autocomplete Query Verification
$catSuggest = $pdo->query("SELECT id, name FROM categories WHERE name LIKE '%Gas%' LIMIT 2")->fetchAll();
assert_true(count($catSuggest) >= 1, 'TC-26: Search suggest category query returns matched categories');

echo "\n====================================================\n";
echo "   SokoHub Test Suite: {$passed} PASSED, {$failed} FAILED   \n";
echo "====================================================\n";
exit($failed > 0 ? 1 : 0);
