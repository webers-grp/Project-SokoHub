<?php
/**
 * SokoHub Safaricom Daraja M-Pesa API Client & Settlement Engine
 *
 * Implements:
 * - Phone number normalization (2547XXXXXXXX / 2541XXXXXXXX)
 * - OAuth Access Token caching
 * - STK Push initiation (CustomerBuyGoodsOnline for Till, CustomerPayBillOnline for Paybill)
 * - STK Push query fallback
 * - Idempotent callback processing with SHA256 deduplication
 * - Settlement ledger splitting, platform fee calculation (2%), and multi-seller payouts
 * - COD cash collection and receivable fee recording
 * - Delivery rider B2C payouts
 * - Sandbox and simulation fallbacks for offline testing
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/functions.php';

/**
 * Normalizes any format of Kenyan phone number into standard 254XXXXXXXXX format.
 * Accepts:
 *   "0712345678"    -> "254712345678"
 *   "0112345678"    -> "254112345678"
 *   "+254712345678" -> "254712345678"
 *   "254712345678"  -> "254712345678"
 *   "712345678"     -> "254712345678"
 *
 * Returns empty string if invalid.
 */
function mpesa_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        $digits = '254' . substr($digits, 1);
    } elseif ((str_starts_with($digits, '7') || str_starts_with($digits, '1')) && strlen($digits) === 9) {
        $digits = '254' . $digits;
    } elseif (str_starts_with($digits, '254') && strlen($digits) === 12) {
        // already valid
    } else {
        return '';
    }

    // Must be exactly 12 digits and start with 2547 or 2541
    if (preg_match('/^254(7|1)[0-9]{8}$/', $digits)) {
        return $digits;
    }

    return '';
}

/**
 * Validate payout destination accounts based on type.
 * Returns ['ok' => bool, 'error' => ?string]
 */
function validate_payout_destination(string $type, string $account, ?string $accountRef = null): array
{
    $account = trim($account);
    $accountRef = trim((string) $accountRef);

    if ($type === 'till') {
        // Buy goods Till numbers are typically 5 to 7 digits
        if (!preg_match('/^[0-9]{5,8}$/', $account)) {
            return ['ok' => false, 'error' => 'Till Number must be a valid 5 to 8 digit number (e.g. 1648753).'];
        }
    } elseif ($type === 'paybill') {
        // Paybill numbers are typically 5 to 7 digits, requires account number
        if (!preg_match('/^[0-9]{5,7}$/', $account)) {
            return ['ok' => false, 'error' => 'Paybill Business Number must be 5 to 7 digits (e.g. 174379).'];
        }
        if ($accountRef === '' || mb_strlen($accountRef) < 2 || mb_strlen($accountRef) > 30) {
            return ['ok' => false, 'error' => 'Paybill requires an Account Number (2 to 30 characters).'];
        }
    } elseif ($type === 'pochi' || $type === 'phone') {
        $norm = mpesa_normalize_phone($account);
        if ($norm === '') {
            return ['ok' => false, 'error' => 'Please provide a valid Kenyan M-Pesa phone number (e.g. 0712345678 or 0112345678).'];
        }
    } else {
        return ['ok' => false, 'error' => 'Invalid payout destination type.'];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Generate Daraja password and timestamp.
 * Password = base64(shortcode + passkey + timestamp), timestamp format YYYYMMDDHHmmss.
 */
function mpesa_generate_password(string $shortcode, string $passkey, ?string $timestamp = null): array
{
    $ts = $timestamp ?: date('YmdHis');
    $pw = base64_encode($shortcode . $passkey . $ts);
    return [
        'password'  => $pw,
        'timestamp' => $ts,
    ];
}

/**
 * Obtains an OAuth Bearer token from Safaricom Daraja API.
 * Uses file caching to avoid redundant calls within token lifetime.
 */
function mpesa_get_access_token(?array $config = null): array
{
    if ($config === null) {
        $config = get_mpesa_config();
    }

    if (empty($config['consumer_key']) || empty($config['consumer_secret'])) {
        return ['ok' => false, 'token' => null, 'error' => 'M-Pesa Consumer Key and Consumer Secret are not configured in .env.'];
    }

    $cacheFile = sys_get_temp_dir() . '/sokohub_mpesa_token_' . md5($config['consumer_key'] . $config['env']) . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['token']) && ($cached['expires_at'] ?? 0) > (time() + 60)) {
            return ['ok' => true, 'token' => $cached['token'], 'error' => null];
        }
    }

    $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['oauth_url'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => $config['is_live'],
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'token' => null, 'error' => 'Daraja connection error: ' . $curlErr];
    }

    $data = json_decode((string) $response, true);
    if (!empty($data['access_token'])) {
        $expiresIn = (int) ($data['expires_in'] ?? 3599);
        @file_put_contents($cacheFile, json_encode([
            'token'      => $data['access_token'],
            'expires_at' => time() + $expiresIn,
        ]));
        return ['ok' => true, 'token' => $data['access_token'], 'error' => null];
    }

    $errMsg = $data['errorMessage'] ?? 'Failed to authenticate with Daraja (HTTP ' . $httpCode . ').';
    return ['ok' => false, 'token' => null, 'error' => $errMsg];
}

/**
 * Initiates an M-Pesa STK Push (Lipa na M-Pesa Online).
 *
 * Rate limiting: Prevents duplicate STK push for the same order if a pending payment exists
 * and was initiated within the last 45 seconds.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @param string $phone Customer phone number
 * @param float $amount Amount in KSh
 * @return array ['ok' => bool, 'checkout_request_id' => ?string, 'message' => string, 'simulated' => bool]
 */
function mpesa_initiate_stk_push(PDO $pdo, int $orderId, string $phone, float $amount): array
{
    $config = get_mpesa_config();
    $normPhone = mpesa_normalize_phone($phone);

    if ($normPhone === '') {
        return ['ok' => false, 'checkout_request_id' => null, 'message' => 'Invalid Kenyan phone number format.', 'simulated' => false];
    }

    if ($amount <= 0) {
        return ['ok' => false, 'checkout_request_id' => null, 'message' => 'Payment amount must be greater than zero.', 'simulated' => false];
    }

    // Rate Limit & Duplicate Prevention: Check for recent pending payment for this order
    $recentStmt = $pdo->prepare(
        "SELECT id, checkout_request_id, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_secs 
         FROM payments 
         WHERE order_id = :oid AND status = 'pending' 
         ORDER BY id DESC LIMIT 1"
    );
    $recentStmt->execute([':oid' => $orderId]);
    $recent = $recentStmt->fetch();

    if ($recent && (int) $recent['age_secs'] < 30) {
        return [
            'ok'                  => true,
            'checkout_request_id' => $recent['checkout_request_id'],
            'message'             => 'An STK prompt was already sent to your phone. Please check your screen or wait a few seconds.',
            'simulated'           => false,
        ];
    }

    // Fetch order code for account reference
    $ordStmt = $pdo->prepare('SELECT order_code FROM orders WHERE id = :id');
    $ordStmt->execute([':id' => $orderId]);
    $orderCode = (string) ($ordStmt->fetchColumn() ?: ('SKH-' . $orderId));

    $intAmount = (int) max(1, round($amount));

    // Determine PartyB & TransactionType based on config
    $transType = $config['transaction_type'];
    $shortcode = $config['shortcode'];
    $tillNumber = $config['till_number'];

    // In CustomerBuyGoodsOnline (Till), PartyB is the Till number!
    if ($transType === 'CustomerBuyGoodsOnline') {
        $partyB = $tillNumber;
    } else {
        $partyB = $shortcode;
    }

    // Check if live Daraja is configured
    $hasRealKeys = is_mpesa_live_configured();

    if (!$hasRealKeys) {
        // Fallback: Safe Local Dev / Sandbox Simulation
        if (!$config['allow_simulation']) {
            return [
                'ok'                  => false,
                'checkout_request_id' => null,
                'message'             => 'M-Pesa credentials not configured. Please fill MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in .env.',
                'simulated'           => false,
            ];
        }

        // Create simulated pending payment
        $simCheckoutId = 'ws_CO_' . date('dmYHis') . '_' . bin2hex(random_bytes(4));
        $simMerchantId = 'sim_' . bin2hex(random_bytes(6));

        $ins = $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, phone, status, checkout_request_id, merchant_request_id, attempt_no)
             VALUES (:oid, 'mpesa', :amt, :phone, 'pending', :cid, :mid, 1)"
        );
        $ins->execute([
            ':oid'   => $orderId,
            ':amt'   => $amount,
            ':phone' => $normPhone,
            ':cid'   => $simCheckoutId,
            ':mid'   => $simMerchantId,
        ]);

        return [
            'ok'                  => true,
            'checkout_request_id' => $simCheckoutId,
            'message'             => 'STK Push sent to ' . $normPhone . '. Please enter your M-Pesa PIN on your phone.',
            'simulated'           => true,
        ];
    }

    // Live or Sandbox Daraja API Call
    $tokenRes = mpesa_get_access_token($config);
    if (!$tokenRes['ok']) {
        return ['ok' => false, 'checkout_request_id' => null, 'message' => $tokenRes['error'], 'simulated' => false];
    }

    $passInfo = mpesa_generate_password($shortcode, $config['passkey']);
    $accountRef = substr(preg_replace('/[^A-Za-z0-9]/', '', $orderCode), 0, 12);

    $payload = [
        'BusinessShortCode' => (int) $shortcode,
        'Password'          => $passInfo['password'],
        'Timestamp'         => $passInfo['timestamp'],
        'TransactionType'   => $transType,
        'Amount'            => $intAmount,
        'PartyA'            => (int) $normPhone,
        'PartyB'            => (int) $partyB,
        'PhoneNumber'       => (int) $normPhone,
        'CallBackURL'       => $config['callback_url'],
        'AccountReference'  => $accountRef,
        'TransactionDesc'   => 'Order ' . $orderCode,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['stk_url'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tokenRes['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => $config['is_live'],
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'checkout_request_id' => null, 'message' => 'Daraja connection error: ' . $curlErr, 'simulated' => false];
    }

    $data = json_decode((string) $response, true);
    if (!empty($data['ResponseCode']) && (string) $data['ResponseCode'] === '0') {
        $checkoutRequestId = $data['CheckoutRequestID'] ?? ('ws_CO_' . date('dmYHis'));
        $merchantRequestId = $data['MerchantRequestID'] ?? null;

        $ins = $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, phone, status, checkout_request_id, merchant_request_id, attempt_no)
             VALUES (:oid, 'mpesa', :amt, :phone, 'pending', :cid, :mid, 1)"
        );
        $ins->execute([
            ':oid'   => $orderId,
            ':amt'   => $amount,
            ':phone' => $normPhone,
            ':cid'   => $checkoutRequestId,
            ':mid'   => $merchantRequestId,
        ]);

        return [
            'ok'                  => true,
            'checkout_request_id' => $checkoutRequestId,
            'message'             => $data['CustomerMessage'] ?? 'STK push prompt sent to your phone.',
            'simulated'           => false,
        ];
    }

    $errorMsg = $data['errorMessage'] ?? ($data['ResponseDescription'] ?? 'Failed to initiate STK Push (HTTP ' . $httpCode . ').');
    return ['ok' => false, 'checkout_request_id' => null, 'message' => $errorMsg, 'simulated' => false];
}

/**
 * Handle M-Pesa STK Push Callback Webhook.
 * Idempotent, deduplicated via SHA256 of raw JSON payload.
 *
 * @param PDO $pdo
 * @param string $rawJson
 * @return array ['ok' => bool, 'result_code' => int, 'message' => string]
 */
function mpesa_process_callback(PDO $pdo, string $rawJson): array
{
    $hash = hash('sha256', $rawJson);

    // Deduplication check
    $check = $pdo->prepare('SELECT id FROM mpesa_callbacks WHERE payload_hash = :hash LIMIT 1');
    $check->execute([':hash' => $hash]);
    if ($check->fetch()) {
        return ['ok' => true, 'result_code' => 0, 'message' => 'Duplicate callback ignored.'];
    }

    $data = json_decode($rawJson, true);
    if (!$data || !isset($data['Body']['stkCallback'])) {
        return ['ok' => false, 'result_code' => 1, 'message' => 'Invalid callback structure.'];
    }

    $stk = $data['Body']['stkCallback'];
    $merchantRequestId = $stk['MerchantRequestID'] ?? '';
    $checkoutRequestId = $stk['CheckoutRequestID'] ?? '';
    $resultCode = (int) ($stk['ResultCode'] ?? -1);
    $resultDesc = (string) ($stk['ResultDesc'] ?? '');

    // Log raw callback
    $logStmt = $pdo->prepare('INSERT INTO mpesa_callbacks (callback_type, request_id, payload_hash, payload) VALUES ("stk", :req, :hash, :pl)');
    $logStmt->execute([':req' => $checkoutRequestId, ':hash' => $hash, ':pl' => $rawJson]);

    // Find pending payment matching checkoutRequestId
    $payStmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :cid ORDER BY id DESC LIMIT 1');
    $payStmt->execute([':cid' => $checkoutRequestId]);
    $payment = $payStmt->fetch();

    if (!$payment) {
        return ['ok' => false, 'result_code' => 1, 'message' => 'Payment record not found for CheckoutRequestID ' . $checkoutRequestId];
    }

    // If already finalized, do not re-process
    if ($payment['status'] === 'success') {
        return ['ok' => true, 'result_code' => 0, 'message' => 'Payment already verified successfully.'];
    }

    $orderId = (int) $payment['order_id'];

    if ($resultCode === 0) {
        // Success: Extract metadata items
        $items = $stk['CallbackMetadata']['Item'] ?? [];
        $receipt = null;
        $amount = 0.0;
        $phone = null;
        $transDate = null;

        foreach ($items as $item) {
            $name = $item['Name'] ?? '';
            $val = $item['Value'] ?? null;
            if ($name === 'MpesaReceiptNumber') {
                $receipt = (string) $val;
            } elseif ($name === 'Amount') {
                $amount = (float) $val;
            } elseif ($name === 'PhoneNumber') {
                $phone = (string) $val;
            } elseif ($name === 'TransactionDate') {
                $transDate = (string) $val;
            }
        }

        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }
        try {
            // Update payment row
            $updPay = $pdo->prepare(
                "UPDATE payments
                 SET status = 'success', mpesa_receipt = :rcpt, result_code = 0, result_desc = :rdesc, raw_callback = :raw
                 WHERE id = :id"
            );
            $updPay->execute([
                ':rcpt'  => $receipt,
                ':rdesc' => $resultDesc,
                ':raw'   => $rawJson,
                ':id'    => (int) $payment['id'],
            ]);

            // Update order payment status
            $updOrd = $pdo->prepare(
                "UPDATE orders
                 SET payment_status = 'paid', order_status = IF(order_status = 'pending', 'confirmed', order_status)
                 WHERE id = :oid"
            );
            $updOrd->execute([':oid' => $orderId]);

            // Settlement ledger processing
            $cfg = get_mpesa_config();
            if ($cfg['payout_trigger'] === 'on_payment') {
                mpesa_generate_settlement_ledger($pdo, $orderId, true);
            } else {
                // Pre-generate pending ledger entries for seller & delivery
                mpesa_generate_settlement_ledger($pdo, $orderId, false);
            }

            if ($manageTx) {
                $pdo->commit();
            }
            return ['ok' => true, 'result_code' => 0, 'message' => 'Payment succeeded and verified.'];
        } catch (Throwable $e) {
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Callback processing error: ' . $e->getMessage());
            return ['ok' => false, 'result_code' => 1, 'message' => $e->getMessage()];
        }
    } else {
        // Non-zero result code (Failed, cancelled, or timeout)
        $status = match ($resultCode) {
            1032 => 'cancelled', // Cancelled by user
            1037 => 'timeout',   // User cannot be reached / timeout
            default => 'failed',
        };

        $updPay = $pdo->prepare(
            'UPDATE payments SET status = :st, result_code = :rc, result_desc = :rd, raw_callback = :raw WHERE id = :id'
        );
        $updPay->execute([
            ':st'  => $status,
            ':rc'  => $resultCode,
            ':rd'  => $resultDesc,
            ':raw' => $rawJson,
            ':id'  => (int) $payment['id'],
        ]);

        return ['ok' => true, 'result_code' => $resultCode, 'message' => 'Payment status recorded as ' . $status];
    }
}

/**
 * Fallback query endpoint: Polls Daraja STK Push status directly.
 */
function mpesa_query_stk_status(PDO $pdo, string $checkoutRequestId): array
{
    $payStmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :cid ORDER BY id DESC LIMIT 1');
    $payStmt->execute([':cid' => $checkoutRequestId]);
    $payment = $payStmt->fetch();

    if (!$payment) {
        return ['ok' => false, 'status' => 'not_found', 'message' => 'Payment not found.'];
    }

    if ($payment['status'] !== 'pending') {
        return [
            'ok'            => true,
            'status'        => $payment['status'],
            'mpesa_receipt' => $payment['mpesa_receipt'],
            'message'       => $payment['result_desc'] ?: ucfirst($payment['status']),
        ];
    }

    $config = get_mpesa_config();
    if (!is_mpesa_live_configured()) {
        // In simulation mode without live keys, return existing status
        return [
            'ok'            => true,
            'status'        => $payment['status'],
            'mpesa_receipt' => $payment['mpesa_receipt'],
            'message'       => 'Awaiting phone confirmation...',
        ];
    }

    $tokenRes = mpesa_get_access_token($config);
    if (!$tokenRes['ok']) {
        return ['ok' => false, 'status' => 'pending', 'message' => $tokenRes['error']];
    }

    $shortcode = $config['shortcode'];
    $passInfo = mpesa_generate_password($shortcode, $config['passkey']);

    $payload = [
        'BusinessShortCode' => (int) $shortcode,
        'Password'          => $passInfo['password'],
        'Timestamp'         => $passInfo['timestamp'],
        'CheckoutRequestID' => $checkoutRequestId,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['query_url'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tokenRes['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => $config['is_live'],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string) $response, true);
    if (!$data || !isset($data['ResultCode'])) {
        return ['ok' => true, 'status' => 'pending', 'message' => 'Pending PIN entry...'];
    }

    $resCode = (int) $data['ResultCode'];
    $resDesc = (string) ($data['ResultDesc'] ?? '');

    if ($resCode === 0) {
        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare("UPDATE payments SET status = 'success', result_code = 0, result_desc = :rd WHERE id = :id")
                ->execute([':rd' => $resDesc, ':id' => (int) $payment['id']]);

            $pdo->prepare("UPDATE orders SET payment_status = 'paid', order_status = IF(order_status = 'pending', 'confirmed', order_status) WHERE id = :oid")
                ->execute([':oid' => (int) $payment['order_id']]);

            mpesa_generate_settlement_ledger($pdo, (int) $payment['order_id'], false);
            if ($manageTx) {
                $pdo->commit();
            }

            return ['ok' => true, 'status' => 'success', 'message' => 'Payment verified successfully!'];
        } catch (Throwable $e) {
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'status' => 'pending', 'message' => $e->getMessage()];
        }
    } else {
        $newStatus = match ($resCode) {
            1032 => 'cancelled',
            1037 => 'timeout',
            default => 'failed',
        };
        $pdo->prepare('UPDATE payments SET status = :st, result_code = :rc, result_desc = :rd WHERE id = :id')
            ->execute([':st' => $newStatus, ':rc' => $resCode, ':rd' => $resDesc, ':id' => (int) $payment['id']]);

        return ['ok' => true, 'status' => $newStatus, 'message' => $resDesc ?: ucfirst($newStatus)];
    }
}

/**
 * Simulates a successful or failed STK payment for local development / testing.
 */
function mpesa_simulate_callback(PDO $pdo, string $checkoutRequestId, bool $success = true): array
{
    $payStmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :cid LIMIT 1');
    $payStmt->execute([':cid' => $checkoutRequestId]);
    $pay = $payStmt->fetch();

    if (!$pay) {
        return ['ok' => false, 'message' => 'Payment not found.'];
    }

    $receipt = 'SKH' . strtoupper(bin2hex(random_bytes(4)));
    $phone = $pay['phone'] ?: '254712345678';
    $amount = (float) $pay['amount'];

    if ($success) {
        $mockJson = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $pay['merchant_request_id'] ?: 'mock_merchant',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'The service request is processed successfully.',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                            ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                            ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                        ],
                    ],
                ],
            ],
        ]);
    } else {
        $mockJson = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $pay['merchant_request_id'] ?: 'mock_merchant',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode'        => 1032,
                    'ResultDesc'        => 'Request cancelled by user',
                ],
            ],
        ]);
    }

    return mpesa_process_callback($pdo, $mockJson);
}

/**
 * Calculate money flow settlement:
 * - Customer pays total amount to platform Till (1648753)
 * - Platform keeps 2% service fee (configurable)
 * - Remaining 98% is settled to seller's payout destination
 * - Splits multi-seller orders by seller subtotal
 * - Deducts any outstanding COD receivable fee balance
 */
function mpesa_generate_settlement_ledger(PDO $pdo, int $orderId, bool $executeNow = false): array
{
    $config = get_mpesa_config();
    $feePercent = $config['platform_fee_percent'];

    // Check if payout records already exist for this order to prevent duplicates
    $existing = $pdo->prepare('SELECT COUNT(*) FROM payouts WHERE order_id = :oid');
    $existing->execute([':oid' => $orderId]);
    if ((int) $existing->fetchColumn() > 0) {
        return ['ok' => true, 'message' => 'Payout ledger already generated.'];
    }

    // Fetch order details
    $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
    $ordStmt->execute([':id' => $orderId]);
    $order = $ordStmt->fetch();
    if (!$order) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }

    $isCod = ($order['payment_method'] === 'Cash on Delivery');

    // Aggregate items by seller
    $itemsStmt = $pdo->prepare(
        'SELECT seller_id, SUM(line_total) AS seller_subtotal 
         FROM order_items 
         WHERE order_id = :oid 
         GROUP BY seller_id'
    );
    $itemsStmt->execute([':oid' => $orderId]);
    $sellerTotals = $itemsStmt->fetchAll();

    foreach ($sellerTotals as $st) {
        $sellerId = (int) $st['seller_id'];
        $gross = (float) $st['seller_subtotal'];
        $platformFee = round($gross * ($feePercent / 100), 2);
        $net = round($gross - $platformFee, 2);

        // Fetch seller payout profile
        $profStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :uid LIMIT 1');
        $profStmt->execute([':uid' => $sellerId]);
        $profile = $profStmt->fetch();

        $payoutType = $profile['payout_type'] ?? 'phone';
        $payoutAccount = $profile['payout_account'] ?? '';
        $payoutRef = $profile['payout_account_reference'] ?? null;
        $codBalanceDue = (float) ($profile['cod_balance_due'] ?? 0.0);

        // If seller profile has no account set, fallback to user phone
        if (empty($payoutAccount)) {
            $userPhone = $pdo->query("SELECT phone FROM users WHERE id = {$sellerId}")->fetchColumn();
            $payoutAccount = mpesa_normalize_phone((string) $userPhone);
            $payoutType = 'phone';
        }

        if ($isCod) {
            // For COD: Customer gave cash directly to seller / delivery agent.
            // Platform fee is owed by the seller to the platform.
            // Record as receivable: update cod_balance_due in seller_profiles!
            $newCodDue = $codBalanceDue + $platformFee;
            $pdo->prepare('UPDATE seller_profiles SET cod_balance_due = :due WHERE user_id = :uid')
                ->execute([':due' => $newCodDue, ':uid' => $sellerId]);

            // Insert ledger entry recording COD fee receivable
            $ins = $pdo->prepare(
                "INSERT INTO payouts 
                 (order_id, recipient_type, user_id, payout_destination_type, payout_account, payout_account_reference, gross_amount, platform_fee_percent, platform_fee_amount, cod_fee_deducted, net_amount, payout_method, status, error_message, paid_at)
                 VALUES (:oid, 'seller', :uid, :dtype, :acct, :ref, :gross, :pct, :fee, 0.00, :net, 'cod_settled', 'succeeded', 'COD customer cash collected; platform fee recorded as receivable', NOW())"
            );
            $ins->execute([
                ':oid'   => $orderId,
                ':uid'   => $sellerId,
                ':dtype' => $payoutType,
                ':acct'  => $payoutAccount,
                ':ref'   => $payoutRef,
                ':gross' => $gross,
                ':pct'   => $feePercent,
                ':fee'   => $platformFee,
                ':net'   => $net,
            ]);
        } else {
            // Online M-Pesa order: Platform received money into Till.
            // Check if seller owes any prior COD fee balance to deduct from this payout!
            $codDeduction = 0.0;
            if ($codBalanceDue > 0 && $net > 0) {
                $codDeduction = min($codBalanceDue, $net);
                $net = round($net - $codDeduction, 2);
                $remCodDue = round($codBalanceDue - $codDeduction, 2);
                $pdo->prepare('UPDATE seller_profiles SET cod_balance_due = :due WHERE user_id = :uid')
                    ->execute([':due' => $remCodDue, ':uid' => $sellerId]);
            }

            $pMethod = match ($payoutType) {
                'till' => 'b2b_buygoods',
                'paybill' => 'b2b_paybill',
                default => 'b2c',
            };

            $status = $executeNow ? 'processing' : 'pending';

            $ins = $pdo->prepare(
                "INSERT INTO payouts 
                 (order_id, recipient_type, user_id, payout_destination_type, payout_account, payout_account_reference, gross_amount, platform_fee_percent, platform_fee_amount, cod_fee_deducted, net_amount, payout_method, status)
                 VALUES (:oid, 'seller', :uid, :dtype, :acct, :ref, :gross, :pct, :fee, :cod_deduct, :net, :pmethod, :status)"
            );
            $ins->execute([
                ':oid'        => $orderId,
                ':uid'        => $sellerId,
                ':dtype'      => $payoutType,
                ':acct'       => $payoutAccount,
                ':ref'        => $payoutRef,
                ':gross'      => $gross,
                ':pct'        => $feePercent,
                ':fee'        => $platformFee,
                ':cod_deduct' => $codDeduction,
                ':net'        => $net,
                ':pmethod'    => $pMethod,
                ':status'     => $status,
            ]);

            $payoutId = (int) $pdo->lastInsertId();

            if ($executeNow) {
                mpesa_execute_single_payout($pdo, $payoutId);
            }
        }
    }

    // Also pre-generate or record the delivery rider payout ledger
    $delStmt = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1');
    $delStmt->execute([':oid' => $orderId]);
    $delivery = $delStmt->fetch();

    if ($delivery && !empty($delivery['delivery_person_id'])) {
        $riderId = (int) $delivery['delivery_person_id'];
        $fee = (float) $delivery['delivery_fee'];

        // Get rider phone
        $riderPhone = $pdo->query("SELECT phone FROM users WHERE id = {$riderId}")->fetchColumn();
        $riderMpesa = mpesa_normalize_phone((string) $riderPhone);

        $insDel = $pdo->prepare(
            "INSERT INTO payouts 
             (order_id, recipient_type, user_id, payout_destination_type, payout_account, gross_amount, platform_fee_percent, platform_fee_amount, cod_fee_deducted, net_amount, payout_method, status)
             VALUES (:oid, 'delivery', :uid, 'phone', :acct, :gross, 0.00, 0.00, 0.00, :net, 'b2c', 'pending')"
        );
        $insDel->execute([
            ':oid'   => $orderId,
            ':uid'   => $riderId,
            ':acct'  => $riderMpesa,
            ':gross' => $fee,
            ':net'   => $fee,
        ]);
        $delPayoutId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE deliveries SET delivery_payout_id = :pid WHERE id = :did')
            ->execute([':pid' => $delPayoutId, ':did' => (int) $delivery['id']]);
    }

    return ['ok' => true, 'message' => 'Settlement ledger generated.'];
}

/**
 * Dispatches a single payout (B2C or B2B) to a seller or rider.
 */
function mpesa_execute_single_payout(PDO $pdo, int $payoutId): array
{
    $stmt = $pdo->prepare('SELECT p.*, o.order_code FROM payouts p INNER JOIN orders o ON o.id = p.order_id WHERE p.id = :id LIMIT 1');
    $stmt->execute([':id' => $payoutId]);
    $payout = $stmt->fetch();

    if (!$payout) {
        return ['ok' => false, 'message' => 'Payout record not found.'];
    }

    if ($payout['status'] === 'succeeded') {
        return ['ok' => true, 'message' => 'Payout already succeeded.'];
    }

    $config = get_mpesa_config();
    $amount = (int) max(1, round((float) $payout['net_amount']));
    $destType = $payout['payout_destination_type'];
    $account = $payout['payout_account'];
    $orderCode = $payout['order_code'];

    // In simulation or sandbox without B2C cert: simulate success
    $hasB2cKeys = !empty($config['b2c_initiator_name']) && !empty($config['b2c_security_credential']) && is_mpesa_live_configured();

    if (!$hasB2cKeys) {
        if (!$config['allow_simulation']) {
            return ['ok' => false, 'message' => 'Daraja B2C credentials not configured in .env.'];
        }

        // Simulate B2C / B2B payout success
        $simTxId = 'B2C' . strtoupper(bin2hex(random_bytes(5)));
        $upd = $pdo->prepare(
            "UPDATE payouts 
             SET status = 'succeeded', transaction_id = :tx, paid_at = NOW(), error_message = NULL, raw_response = 'Simulated sandbox payout dispatch'
             WHERE id = :id"
        );
        $upd->execute([':tx' => $simTxId, ':id' => $payoutId]);

        // If this was a delivery fee payout, update deliveries table
        if ($payout['recipient_type'] === 'delivery') {
            $pdo->prepare("UPDATE deliveries SET delivery_fee_paid = 1 WHERE order_id = :oid")->execute([':oid' => $payout['order_id']]);
        }

        return ['ok' => true, 'transaction_id' => $simTxId, 'message' => 'Simulated payout of KSh ' . $amount . ' to ' . $account . ' succeeded.'];
    }

    // Live Daraja B2C / B2B API call
    $tokenRes = mpesa_get_access_token($config);
    if (!$tokenRes['ok']) {
        $pdo->prepare("UPDATE payouts SET status = 'failed', error_message = :err WHERE id = :id")
            ->execute([':err' => $tokenRes['error'], ':id' => $payoutId]);
        return ['ok' => false, 'message' => $tokenRes['error']];
    }

    if ($destType === 'phone' || $destType === 'pochi') {
        // B2C Payment
        $payload = [
            'InitiatorName'      => $config['b2c_initiator_name'],
            'SecurityCredential' => $config['b2c_security_credential'],
            'CommandID'          => 'BusinessPayment',
            'Amount'             => $amount,
            'PartyA'             => (int) $config['shortcode'],
            'PartyB'             => (int) $account,
            'Remarks'            => 'SokoHub Payout for ' . $orderCode,
            'QueueTimeOutURL'    => $config['timeout_url'],
            'ResultURL'          => $config['result_url'],
            'Occasion'           => $orderCode,
        ];
        $apiUrl = $config['b2c_url'];
    } else {
        // B2B Payment (Till or Paybill)
        $commandId = ($destType === 'till') ? 'BusinessBuyGoods' : 'BusinessPayBill';
        $receiverType = ($destType === 'till') ? 2 : 4;

        $payload = [
            'Initiator'              => $config['b2c_initiator_name'],
            'SecurityCredential'     => $config['b2c_security_credential'],
            'CommandID'              => $commandId,
            'SenderIdentifierType'   => 4,
            'RecieverIdentifierType' => $receiverType,
            'Amount'                 => $amount,
            'PartyA'                 => (int) $config['shortcode'],
            'PartyB'                 => (int) $account,
            'AccountReference'       => $payout['payout_account_reference'] ?: $orderCode,
            'Remarks'                => 'SokoHub Vendor Payout for ' . $orderCode,
            'QueueTimeOutURL'        => $config['timeout_url'],
            'ResultURL'              => $config['result_url'],
        ];
        $apiUrl = $config['b2b_url'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tokenRes['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => $config['is_live'],
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $pdo->prepare("UPDATE payouts SET status = 'failed', error_message = :err WHERE id = :id")
            ->execute([':err' => $curlErr, ':id' => $payoutId]);
        return ['ok' => false, 'message' => 'Daraja B2C connection error: ' . $curlErr];
    }

    $data = json_decode((string) $response, true);
    if (!empty($data['ResponseCode']) && (string) $data['ResponseCode'] === '0') {
        $convId = $data['ConversationID'] ?? null;
        $origId = $data['OriginatorConversationID'] ?? null;
        $pdo->prepare(
            "UPDATE payouts 
             SET status = 'processing', conversation_id = :cid, originator_conversation_id = :oid, raw_response = :raw 
             WHERE id = :id"
        )->execute([':cid' => $convId, ':oid' => $origId, ':raw' => $response, ':id' => $payoutId]);

        return ['ok' => true, 'message' => $data['ResponseDescription'] ?? 'Payout initiated successfully.'];
    }

    $errMsg = $data['errorMessage'] ?? ($data['ResponseDescription'] ?? 'Failed to dispatch payout.');
    $pdo->prepare("UPDATE payouts SET status = 'failed', error_message = :err, raw_response = :raw WHERE id = :id")
        ->execute([':err' => $errMsg, ':raw' => $response, ':id' => $payoutId]);

    return ['ok' => false, 'message' => $errMsg];
}

/**
 * Triggers all pending payouts for an order upon delivery confirmation.
 */
function mpesa_trigger_order_delivery_payouts(PDO $pdo, int $orderId): array
{
    // Generate ledger if not already done
    mpesa_generate_settlement_ledger($pdo, $orderId, false);

    $payoutsStmt = $pdo->prepare("SELECT id FROM payouts WHERE order_id = :oid AND status IN ('pending', 'failed')");
    $payoutsStmt->execute([':oid' => $orderId]);
    $ids = $payoutsStmt->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($ids as $pid) {
        $results[] = mpesa_execute_single_payout($pdo, (int) $pid);
    }

    return ['ok' => true, 'count' => count($ids), 'results' => $results];
}
