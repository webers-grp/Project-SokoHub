<?php
/**
 * Safaricom Daraja M-Pesa STK Push Callback Webhook
 *
 * Receives JSON payload from Safaricom Daraja API upon completion of customer STK Push.
 * Must always return HTTP 200 with {"ResultCode": 0, "ResultDesc": "Success"} JSON.
 * Idempotent: Deduplicated via SHA256 of raw input.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty request body']);
    exit;
}

try {
    $pdo = get_db_connection();
    $result = mpesa_process_callback($pdo, $rawBody);

    // Daraja expects ResultCode 0 acknowledgment
    http_response_code(200);
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Callback received successfully',
    ]);
} catch (Throwable $e) {
    error_log('Daraja callback handling exception: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Error logged internally',
    ]);
}
exit;
