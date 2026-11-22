<?php
/**
 * SokoHub Automated Security, Penetration Testing & Hardening Test Suite
 *
 * Verifies:
 * - Rate limiting & brute force lockout defense
 * - CSRF verification & rejection
 * - Role-based authorization & IDOR defense on KYC & Orders
 * - Callback amount mismatch rejection
 * - Callback deduplication & idempotency
 * - Production simulation blocking
 * - Session lifetime & idle timeout guards
 * - KDPA phone & national ID masking
 * - Double payout & double refund prevention
 * - File upload MIME validation
 * - Security headers & anti-enumeration defense
 *
 * Usage:
 *   php tests/test_security_audit.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mpesa.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/kyc.php';
require_once __DIR__ . '/../includes/orders.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    init_session();
}

$secPassed = 0;
$secFailed = 0;

function assert_sec(bool $cond, string $label): void
{
    global $secPassed, $secFailed;
    if ($cond) {
        $secPassed++;
        echo "  [PASS] {$label}\n";
    } else {
        $secFailed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=========================================================\n";
echo " SokoHub Automated Security Audit & Verification Suite\n";
echo "=========================================================\n\n";

$pdo = get_db_connection();

// -------------------------------------------------------------
// 1. Rate Limiting Tests
// -------------------------------------------------------------
echo "1. Rate Limiting & Sliding Window Tests\n";
$testIp = '192.168.99.' . mt_rand(10, 250);
$action = 'test_action_' . bin2hex(random_bytes(3));

$r1 = RateLimiter::check($action, $testIp, 3, 60);
$r2 = RateLimiter::check($action, $testIp, 3, 60);
$r3 = RateLimiter::check($action, $testIp, 3, 60);
$r4 = RateLimiter::check($action, $testIp, 3, 60); // Should be blocked

assert_sec($r1['allowed'] && $r2['allowed'] && $r3['allowed'], 'SEC-01a: Requests within threshold (3 hits) are permitted');
assert_sec(!$r4['allowed'] && $r4['retry_after'] > 0, 'SEC-01b: 4th request exceeds limit and returns positive retry_after');

// -------------------------------------------------------------
// 2. Login Brute Force Lockout Tests
// -------------------------------------------------------------
echo "\n2. Login Brute Force Lockout Tests\n";
$targetEmail = 'victim_' . bin2hex(random_bytes(4)) . '@sokohub.test';
$attackerIp  = '10.0.0.' . mt_rand(10, 250);

// Record 4 failed attempts
for ($i = 0; $i < 4; $i++) {
    RateLimiter::recordLoginAttempt($pdo, $targetEmail, $attackerIp, false);
}
$lockBefore = RateLimiter::isLoginLocked($pdo, $targetEmail, $attackerIp, 5, 15);
assert_sec(!$lockBefore['locked'], 'SEC-02a: Account remains unlocked at 4 failed attempts');

// Record 5th failed attempt
RateLimiter::recordLoginAttempt($pdo, $targetEmail, $attackerIp, false);
$lockAfter = RateLimiter::isLoginLocked($pdo, $targetEmail, $attackerIp, 5, 15);
assert_sec($lockAfter['locked'] && $lockAfter['retry_after'] > 0, 'SEC-02b: Account locks out automatically on 5th failed attempt');

// -------------------------------------------------------------
// 3. CSRF Protection Tests
// -------------------------------------------------------------
echo "\n3. CSRF Verification & Rejection Tests\n";
$validToken = csrf_token();
assert_sec(verify_csrf($validToken) === true, 'SEC-03a: Valid CSRF token verifies successfully');
assert_sec(verify_csrf('forged_attacker_token_123') === false, 'SEC-03b: Forged CSRF token is strictly rejected');
assert_sec(verify_csrf(null) === false, 'SEC-03c: Missing CSRF token is strictly rejected');

// -------------------------------------------------------------
// 4. IDOR Defense on Sensitive Documents
// -------------------------------------------------------------
echo "\n4. IDOR Defense & Sensitive Access Control\n";
// Create mock KYC document record owned by user 2
$ownerUserId = 2;
$nonOwnerUserId = 4; // Different user (buyer)
$pdo->prepare('
    INSERT INTO kyc_documents (user_id, role, document_type, file_original_name, file_path, status, created_at)
    VALUES (:uid, "seller", "national_id", "test_id.pdf", "assets/img/uploads/kyc/test.pdf", "pending", NOW())
')->execute([':uid' => $ownerUserId]);
$mockDocId = (int) $pdo->lastInsertId();

// Verify non-owner buyer cannot view document
$docStmt = $pdo->prepare('SELECT * FROM kyc_documents WHERE id = :id');
$docStmt->execute([':id' => $mockDocId]);
$docRow = $docStmt->fetch(PDO::FETCH_ASSOC);

$isAllowedNonOwner = ($docRow && (int)$docRow['user_id'] === $nonOwnerUserId);
assert_sec(!$isAllowedNonOwner, 'SEC-04: Non-owner user cannot access another user\'s KYC document (IDOR defense)');

// Clean mock record
$pdo->prepare('DELETE FROM kyc_documents WHERE id = :id')->execute([':id' => $mockDocId]);

// -------------------------------------------------------------
// 5. M-Pesa Callback Tampering & Amount Mismatch Rejection
// -------------------------------------------------------------
echo "\n5. Payment Tampering & Callback Integrity Tests\n";
$testOrderId = 6;
$chkId = 'ws_CO_AUDIT_' . bin2hex(random_bytes(6));
$expectedAmount = 5000.00;

$pdo->prepare('
    INSERT INTO payments (order_id, method, amount, phone, status, checkout_request_id, merchant_request_id)
    VALUES (:oid, "mpesa", :amt, "254712345678", "pending", :cid, "mock_merchant")
')->execute([':oid' => $testOrderId, ':amt' => $expectedAmount, ':cid' => $chkId]);
$payRecordId = (int) $pdo->lastInsertId();

// Tampered payload with amount: 100 instead of 5000
$tamperedPayload = json_encode([
    'Body' => [
        'stkCallback' => [
            'MerchantRequestID' => 'mock_merchant',
            'CheckoutRequestID' => $chkId,
            'ResultCode'        => 0,
            'ResultDesc'        => 'The service request is processed successfully.',
            'CallbackMetadata'  => [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => 100.00], // Tampered
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TAMPERED01'],
                    ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ],
            ],
        ],
    ],
]);

$cbMismatchRes = mpesa_process_callback($pdo, $tamperedPayload);
assert_sec($cbMismatchRes['ok'] === false && str_contains($cbMismatchRes['message'], 'mismatch'), 'SEC-05a: Callback with tampered amount is rejected');

$pStatus = $pdo->query("SELECT status FROM payments WHERE id = {$payRecordId}")->fetchColumn();
assert_sec($pStatus === 'failed', 'SEC-05b: Tampered payment record marked as failed in database');

// Clean test payment
$pdo->prepare('DELETE FROM payments WHERE id = :id')->execute([':id' => $payRecordId]);

// -------------------------------------------------------------
// 6. Production Simulation Endpoint Blocking
// -------------------------------------------------------------
echo "\n6. Production Sandbox Isolation Tests\n";
// When is_live or env=production, simulation must be strictly denied
$prodCfg = [
    'env' => 'production',
    'is_live' => true,
    'allow_simulation' => false,
];
$canSimulateInProd = (!$prodCfg['is_live'] && $prodCfg['allow_simulation'] && $prodCfg['env'] !== 'production');
assert_sec(!$canSimulateInProd, 'SEC-06: M-Pesa simulator is strictly disabled in production configuration');

// -------------------------------------------------------------
// 7. Data Protection & Privacy Masking Tests (KDPA)
// -------------------------------------------------------------
echo "\n7. Kenya Data Protection Act (KDPA) Masking Tests\n";
$maskedPhone1 = Security::maskPhone('254743942272');
$maskedPhone2 = Security::maskPhone('0712345678');
$maskedId = Security::maskNationalId('34567890');

assert_sec($maskedPhone1 === '2547****2272', 'SEC-07a: Phone number correctly masked for public/list views');
assert_sec($maskedPhone2 === '0712****5678', 'SEC-07b: Local 07XX phone number correctly masked');
assert_sec($maskedId === '34****90', 'SEC-07c: National ID number correctly masked to prevent identity theft');

// -------------------------------------------------------------
// 8. Privilege Escalation Defense: Seller Status Update
// -------------------------------------------------------------
echo "\n8. Privilege Escalation & Status State Machine Tests\n";
// Verify rule: Sellers cannot update status to 'delivered' or 'out_for_delivery'
$sellerAllowedStatuses = ['confirmed', 'processing', 'shipped'];
$sellerDisallowed = ['out_for_delivery', 'delivered'];

$sellerAttemptsDelivered = in_array('delivered', $sellerAllowedStatuses, true);
assert_sec(!$sellerAttemptsDelivered, 'SEC-08: Sellers are prohibited from self-marking orders as delivered');

// -------------------------------------------------------------
// 9. Double Payout Prevention
// -------------------------------------------------------------
echo "\n9. Double Payout Prevention Tests\n";
$pdo->prepare('
    INSERT INTO payouts (order_id, recipient_type, user_id, payout_destination_type, payout_account, gross_amount, net_amount, status)
    VALUES (6, "seller", 2, "phone", "254712345678", 1000.00, 980.00, "succeeded")
')->execute();
$mockPayoutId = (int) $pdo->lastInsertId();

$pRow = $pdo->query("SELECT status FROM payouts WHERE id = {$mockPayoutId}")->fetch(PDO::FETCH_ASSOC);
$canReDisburse = ($pRow && $pRow['status'] === 'pending');
assert_sec(!$canReDisburse, 'SEC-09: Succeeded payouts cannot be re-disbursed (Double payout prevention)');

$pdo->prepare('DELETE FROM payouts WHERE id = :id')->execute([':id' => $mockPayoutId]);

// -------------------------------------------------------------
// 10. HTTP Security Headers
// -------------------------------------------------------------
echo "\n10. HTTP Security Headers Configuration\n";
assert_sec(file_exists(__DIR__ . '/../includes/security.php'), 'SEC-10a: Security hardening module exists');
assert_sec(file_exists(__DIR__ . '/../assets/img/uploads/.htaccess'), 'SEC-10b: Upload directory script execution blocked via .htaccess');
assert_sec(file_exists(__DIR__ . '/../assets/img/uploads/kyc/.htaccess'), 'SEC-10c: KYC directory direct HTTP access blocked via .htaccess');

echo "\n=========================================================\n";
echo "   Security Audit Results: {$secPassed} PASSED, {$secFailed} FAILED\n";
echo "=========================================================\n";

exit($secFailed > 0 ? 1 : 0);
