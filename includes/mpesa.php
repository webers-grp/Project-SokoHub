<?php
/**
 * SokoHub Safaricom Daraja M-Pesa API Client
 *
 * Implements:
 * - Phone number normalization (Kenyan 254XXXXXXXXX standard)
 * - OAuth Access Token retrieval and in-memory/file caching
 * - Lipa na M-Pesa Online (STK Push) initiation
 * - STK Push real-time query status polling
 * - Secure Daraja callback webhook processing with deduplication
 * - Fallback simulation engine for seamless offline/presentation testing
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/orders.php';

/**
 * Normalizes any format of Kenyan phone number into 254XXXXXXXXX format.
 * Examples:
 *   "0712345678"   -> "254712345678"
 *   "0112345678"   -> "254112345678"
 *   "+254712345678"-> "254712345678"
 *   "254712345678" -> "254712345678"
 *   "712345678"    -> "254712345678"
 */
function mpesa_format_phone(string $phone): string
{
    // Strip non-digit characters
    $digits = preg_replace('/\D/', '', $phone);

    // If starts with 0 and length is 10 (e.g. 07XXXXXXXX or 01XXXXXXXX)
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        return '254' . substr($digits, 1);
    }

    // If starts with 7 or 1 and length is 9 (e.g. 7XXXXXXXX or 1XXXXXXXX)
    if ((str_starts_with($digits, '7') || str_starts_with($digits, '1')) && strlen($digits) === 9) {
        return '254' . $digits;
    }

    // If already starts with 254 and length is 12
    if (str_starts_with($digits, '254') && strlen($digits) === 12) {
        return $digits;
    }

    // Fallback: return sanitized digits
    return $digits;
}

/**
 * Obtains an OAuth Bearer token from Safaricom Daraja API.
 * Uses file caching to minimize token generation latency.
 *
 * @param array|null $config
 * @return array ['ok' => bool, 'token' => ?string, 'error' => ?string]
 */
function mpesa_get_access_token(?array $config = null): array
{
    if ($config === null) {
        $config = get_mpesa_config();
    }

    if (empty($config['consumer_key']) || empty($config['consumer_secret'])) {
        return ['ok' => false, 'token' => null, 'error' => 'M-Pesa Consumer Key and Consumer Secret are not configured.'];
    }

    // Check token cache in system temp directory
    $cacheFile = sys_get_temp_dir() . '/sokohub_mpesa_token_' . md5($config['consumer_key']) . '.json';
    if (file_exists($cacheFile)) {
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
        CURLOPT_SSL_VERIFYPEER => ($config['env'] === 'live'),
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
        $payload = [
            'token'      => $data['access_token'],
            'expires_at' => time() + $expiresIn,
        ];
        @file_put_contents($cacheFile, json_encode($payload));

        return ['ok' => true, 'token' => $data['access_token'], 'error' => null];
    }

    $errMsg = $data['errorMessage'] ?? 'HTTP ' . $httpCode . ' unexpected response from Daraja OAuth.';
    return ['ok' => false, 'token' => null, 'error' => $errMsg];
}

/**
 * Initiates an M-Pesa STK Push (Lipa na M-Pesa Online) request.
 *
 * @param PDO    $pdo
 * @param int    $orderId
 * @param string $phone
 * @param float  $amount
 * @param string $accountReference
 * @param string $description
 * @return array ['ok' => bool, 'checkout_request_id' => string, 'message' => string, 'simulated' => bool]
 */
function mpesa_stk_push(
    PDO $pdo,
    int $orderId,
    string $phone,
    float $amount,
    string $accountReference,
    string $description = 'SokoHub Order Payment'
): array {
    ensure_order_schema_tables($pdo);
    $config = get_mpesa_config();

    $formattedPhone = mpesa_format_phone($phone);
    if (strlen($formattedPhone) !== 12 || !str_starts_with($formattedPhone, '254')) {
        return ['ok' => false, 'message' => 'Please provide a valid Kenyan phone number (e.g. 0712 345 678).'];
    }

    // Rounded integer amount per Safaricom Lipa na M-Pesa specification
    $stkAmount = max(1, (int) round($amount));
    $timestamp = date('YmdHis');
    $password = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);

    // If live credentials are not present, trigger local presentation simulation
    if (!is_mpesa_live_configured()) {
        $simCheckoutId = 'ws_CO_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
        $simMerchantId = 'MR_' . bin2hex(random_bytes(6));

        $stmt = $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, status, phone, merchant_request_id, checkout_request_id, result_desc)
             VALUES (:oid, 'mpesa', :amt, 'pending', :phone, :mrid, :crid, 'Dev Sandbox STK Push Initiated')"
        );
        $stmt->execute([
            ':oid'   => $orderId,
            ':amt'   => $amount,
            ':phone' => $formattedPhone,
            ':mrid'  => $simMerchantId,
            ':crid'  => $simCheckoutId,
        ]);

        return [
            'ok'                  => true,
            'simulated'           => true,
            'checkout_request_id' => $simCheckoutId,
            'message'             => 'STK Push sent to ' . $formattedPhone . ' (Development Sandbox Mode). Please enter your M-Pesa PIN.',
            'amount'              => $stkAmount,
            'phone'               => $formattedPhone,
        ];
    }

    // 1. Fetch OAuth Access Token
    $tokenResult = mpesa_get_access_token($config);
    if (!$tokenResult['ok']) {
        return ['ok' => false, 'message' => 'Failed to authenticate with Safaricom Daraja: ' . $tokenResult['error']];
    }

    $accessToken = $tokenResult['token'];

    // 2. Build STK Push Request Payload
    $postData = [
        'BusinessShortCode' => $config['shortcode'],
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $stkAmount,
        'PartyA'            => $formattedPhone,
        'PartyB'            => $config['shortcode'],
        'PhoneNumber'       => $formattedPhone,
        'CallBackURL'       => $config['callback_url'] ?: 'https://sokohub.co.ke/pages/api/mpesa_callback.php',
        'AccountReference'  => substr($accountReference, 0, 12),
        'TransactionDesc'   => substr($description, 0, 13),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['stk_url'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => ($config['env'] === 'live'),
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'message' => 'M-Pesa STK Push connection failed: ' . $curlErr];
    }

    $res = json_decode((string) $response, true);
    if (!empty($res['ResponseCode']) && $res['ResponseCode'] === '0') {
        $crid = $res['CheckoutRequestID'] ?? ('CR_' . bin2hex(random_bytes(8)));
        $mrid = $res['MerchantRequestID'] ?? ('MR_' . bin2hex(random_bytes(8)));

        $stmt = $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, status, phone, merchant_request_id, checkout_request_id, result_desc)
             VALUES (:oid, 'mpesa', :amt, 'pending', :phone, :mrid, :crid, :desc)"
        );
        $stmt->execute([
            ':oid'   => $orderId,
            ':amt'   => $amount,
            ':phone' => $formattedPhone,
            ':mrid'  => $mrid,
            ':crid'  => $crid,
            ':desc'  => $res['CustomerMessage'] ?? 'STK push sent to customer phone.',
        ]);

        return [
            'ok'                  => true,
            'simulated'           => false,
            'checkout_request_id' => $crid,
            'message'             => $res['CustomerMessage'] ?? 'STK push sent to your phone. Please enter your M-Pesa PIN.',
            'amount'              => $stkAmount,
            'phone'               => $formattedPhone,
        ];
    }

    $errMsg = $res['errorMessage'] ?? ($res['ResponseDescription'] ?? 'Failed to initiate M-Pesa STK Push.');
    return ['ok' => false, 'message' => $errMsg];
}

/**
 * Polls the payment status for an active M-Pesa transaction.
 * Checks the local database first, and if still pending, queries Daraja STK Query API.
 *
 * @param PDO    $pdo
 * @param int    $orderId
 * @param string|null $checkoutRequestId
 * @return array ['status' => string, 'paid' => bool, 'receipt' => ?string, 'message' => string]
 */
function mpesa_poll_status(PDO $pdo, int $orderId, ?string $checkoutRequestId = null): array
{
    ensure_order_schema_tables($pdo);

    // 1. Check local order status
    $ordStmt = $pdo->prepare('SELECT id, order_code, payment_status, total_amount FROM orders WHERE id = :id LIMIT 1');
    $ordStmt->execute([':id' => $orderId]);
    $order = $ordStmt->fetch();

    if (!$order) {
        return ['status' => 'error', 'paid' => false, 'message' => 'Order not found.'];
    }

    if ($order['payment_status'] === 'paid') {
        $recStmt = $pdo->prepare('SELECT mpesa_receipt FROM payments WHERE order_id = :id AND status = "succeeded" ORDER BY id DESC LIMIT 1');
        $recStmt->execute([':id' => $orderId]);
        $receipt = $recStmt->fetchColumn() ?: 'MPESA_OK';

        return [
            'status'  => 'succeeded',
            'paid'    => true,
            'receipt' => $receipt,
            'message' => 'Payment received successfully via M-Pesa (Receipt #' . $receipt . ').',
        ];
    }

    // 2. Fetch the corresponding payment attempt
    if ($checkoutRequestId) {
        $pStmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :crid LIMIT 1');
        $pStmt->execute([':crid' => $checkoutRequestId]);
    } else {
        $pStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = :oid AND method = 'mpesa' ORDER BY id DESC LIMIT 1");
        $pStmt->execute([':oid' => $orderId]);
    }
    $payment = $pStmt->fetch();

    if (!$payment) {
        return ['status' => 'pending', 'paid' => false, 'message' => 'Awaiting payment initiation...'];
    }

    if ($payment['status'] === 'succeeded') {
        return [
            'status'  => 'succeeded',
            'paid'    => true,
            'receipt' => $payment['mpesa_receipt'],
            'message' => 'Payment completed successfully.',
        ];
    }

    if (in_array($payment['status'], ['failed', 'cancelled'], true)) {
        return [
            'status'  => $payment['status'],
            'paid'    => false,
            'message' => $payment['result_desc'] ?: 'Payment was cancelled or could not be processed.',
        ];
    }

    // 3. If live credentials are configured and CheckoutRequestID exists, query Safaricom
    $config = get_mpesa_config();
    if (is_mpesa_live_configured() && !empty($payment['checkout_request_id'])) {
        $tokenRes = mpesa_get_access_token($config);
        if ($tokenRes['ok']) {
            $timestamp = date('YmdHis');
            $password = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);

            $queryPayload = [
                'BusinessShortCode' => $config['shortcode'],
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'CheckoutRequestID' => $payment['checkout_request_id'],
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $config['query_url'],
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $tokenRes['token'],
                    'Content-Type: application/json',
                ],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($queryPayload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => ($config['env'] === 'live'),
            ]);
            $qRes = curl_exec($ch);
            curl_close($ch);

            $qData = json_decode((string) $qRes, true);
            if (isset($qData['ResultCode'])) {
                $code = (int) $qData['ResultCode'];
                if ($code === 0) {
                    $pdo->prepare("UPDATE payments SET status = 'succeeded', result_code = 0, result_desc = :desc WHERE id = :id")
                        ->execute([':desc' => $qData['ResultDesc'] ?? 'Completed', ':id' => $payment['id']]);
                    $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = :oid")
                        ->execute([':oid' => $orderId]);

                    return [
                        'status'  => 'succeeded',
                        'paid'    => true,
                        'receipt' => $payment['mpesa_receipt'] ?: 'CONFIRMED',
                        'message' => 'Payment confirmed via M-Pesa.',
                    ];
                } elseif (in_array($code, [1032, 1037, 2001, 1], true)) {
                    $st = ($code === 1032) ? 'cancelled' : 'failed';
                    $pdo->prepare('UPDATE payments SET status = :st, result_code = :rc, result_desc = :desc WHERE id = :id')
                        ->execute([':st' => $st, ':rc' => $code, ':desc' => $qData['ResultDesc'] ?? 'Cancelled/Failed', ':id' => $payment['id']]);

                    return [
                        'status'  => $st,
                        'paid'    => false,
                        'message' => $qData['ResultDesc'] ?? 'Payment was cancelled or failed.',
                    ];
                }
            }
        }
    }

    return [
        'status'  => 'pending',
        'paid'    => false,
        'message' => 'STK Push prompt sent. Waiting for customer to enter M-Pesa PIN...',
    ];
}

/**
 * Handles incoming raw JSON callback from Safaricom Daraja STK Push webhook.
 *
 * @param PDO    $pdo
 * @param string $rawJson
 * @return array ['ok' => bool, 'result_code' => int, 'message' => string]
 */
function mpesa_process_callback(PDO $pdo, string $rawJson): array
{
    ensure_order_schema_tables($pdo);

    $data = json_decode($rawJson, true);
    if (!isset($data['Body']['stkCallback'])) {
        return ['ok' => false, 'result_code' => 1, 'message' => 'Invalid M-Pesa callback envelope.'];
    }

    $cb = $data['Body']['stkCallback'];
    $merchantRequestId = $cb['MerchantRequestID'] ?? null;
    $checkoutRequestId = $cb['CheckoutRequestID'] ?? null;
    $resultCode = (int) ($cb['ResultCode'] ?? 1);
    $resultDesc = (string) ($cb['ResultDesc'] ?? '');

    // Extract metadata items if payment succeeded
    $mpesaReceipt = null;
    $amount = null;
    $phone = null;
    $txnDate = null;

    if ($resultCode === 0 && !empty($cb['CallbackMetadata']['Item'])) {
        foreach ($cb['CallbackMetadata']['Item'] as $item) {
            $name = $item['Name'] ?? '';
            $val = $item['Value'] ?? null;
            if ($name === 'MpesaReceiptNumber') {
                $mpesaReceipt = (string) $val;
            } elseif ($name === 'Amount') {
                $amount = (float) $val;
            } elseif ($name === 'PhoneNumber') {
                $phone = (string) $val;
            } elseif ($name === 'TransactionDate') {
                $txnDate = (string) $val;
            }
        }
    }

    // Deduplicate callback to avoid double-processing
    $dedupeKey = hash('sha256', $rawJson);
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mpesa_callbacks (checkout_request_id, result_code, mpesa_receipt, payload, dedupe_key, processed_at)
             VALUES (:crid, :rc, :rec, :pl, :dk, NOW())'
        );
        $ins->execute([
            ':crid' => $checkoutRequestId,
            ':rc'   => $resultCode,
            ':rec'  => $mpesaReceipt,
            ':pl'   => $rawJson,
            ':dk'   => $dedupeKey,
        ]);
    } catch (Throwable) {
        // Callback already received and processed
        return ['ok' => true, 'result_code' => $resultCode, 'message' => 'Duplicate callback ignored safely.'];
    }

    // Locate corresponding payment row
    $pStmt = $pdo->prepare('SELECT id, order_id FROM payments WHERE checkout_request_id = :crid LIMIT 1');
    $pStmt->execute([':crid' => $checkoutRequestId]);
    $payment = $pStmt->fetch();

    if ($payment) {
        $payId = (int) $payment['id'];
        $orderId = (int) $payment['order_id'];

        if ($resultCode === 0) {
            $upd = $pdo->prepare(
                "UPDATE payments
                 SET status = 'succeeded',
                     mpesa_receipt = :rec,
                     result_code = 0,
                     result_desc = :desc
                 WHERE id = :id"
            );
            $upd->execute([
                ':rec'  => $mpesaReceipt,
                ':desc' => $resultDesc,
                ':id'   => $payId,
            ]);

            $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = :oid")->execute([':oid' => $orderId]);
            $pdo->prepare("UPDATE deliveries SET tracking_notes = CONCAT(COALESCE(tracking_notes,''), ' | M-Pesa Ref: ', :rec) WHERE order_id = :oid")
                ->execute([':rec' => $mpesaReceipt, ':oid' => $orderId]);
        } else {
            $newStatus = ($resultCode === 1032) ? 'cancelled' : 'failed';
            $upd = $pdo->prepare(
                'UPDATE payments
                 SET status = :st,
                     result_code = :rc,
                     result_desc = :desc
                 WHERE id = :id'
            );
            $upd->execute([
                ':st'   => $newStatus,
                ':rc'   => $resultCode,
                ':desc' => $resultDesc,
                ':id'   => $payId,
            ]);
        }
    }

    return ['ok' => true, 'result_code' => $resultCode, 'message' => 'Callback processed.'];
}

/**
 * Simulates an instant successful M-Pesa PIN approval for local testing and presentation demos.
 *
 * @param PDO    $pdo
 * @param int    $orderId
 * @param string|null $customReceipt
 * @return array ['ok' => bool, 'receipt' => string, 'message' => string]
 */
function mpesa_simulate_success(PDO $pdo, int $orderId, ?string $customReceipt = null): array
{
    ensure_order_schema_tables($pdo);

    $receipt = $customReceipt ? strtoupper(trim($customReceipt)) : ('SKH' . strtoupper(bin2hex(random_bytes(4))));

    // Update or insert payment
    $chk = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND method = 'mpesa' ORDER BY id DESC LIMIT 1");
    $chk->execute([':oid' => $orderId]);
    $payId = $chk->fetchColumn();

    if ($payId) {
        $pdo->prepare(
            "UPDATE payments
             SET status = 'succeeded',
                 mpesa_receipt = :rec,
                 result_code = 0,
                 result_desc = 'The service request is processed successfully. (Demo Simulation)'
             WHERE id = :id"
        )->execute([':rec' => $receipt, ':id' => $payId]);
    } else {
        $ord = $pdo->prepare('SELECT total_amount FROM orders WHERE id = :id');
        $ord->execute([':id' => $orderId]);
        $total = (float) $ord->fetchColumn();

        $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, status, mpesa_receipt, result_code, result_desc)
             VALUES (:oid, 'mpesa', :amt, 'succeeded', :rec, 0, 'The service request is processed successfully. (Demo Simulation)')"
        )->execute([':oid' => $orderId, ':amt' => $total, ':rec' => $receipt]);
    }

    // Update order status
    $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = :oid")->execute([':oid' => $orderId]);

    // Update delivery note
    $pdo->prepare("UPDATE deliveries SET tracking_notes = CONCAT(COALESCE(tracking_notes,''), ' | M-Pesa Receipt: ', :rec) WHERE order_id = :oid")
        ->execute([':rec' => $receipt, ':oid' => $orderId]);

    return [
        'ok'      => true,
        'receipt' => $receipt,
        'message' => 'Simulated M-Pesa payment approved! Receipt: ' . $receipt,
    ];
}
