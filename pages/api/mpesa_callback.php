<?php
/**
 * SokoHub Public Safaricom Daraja STK Push Callback Webhook
 *
 * Receives JSON webhook notification from Safaricom when customer enters/cancels PIN,
 * parses transaction metadata, updates order & payment status, and acknowledges Safaricom.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

$rawPayload = file_get_contents('php://input');

if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty request body']);
    exit;
}

try {
    $pdo = get_db_connection();
    $result = mpesa_process_callback($pdo, $rawPayload);

    // Safaricom expects a 200 OK with this standard JSON body
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted',
    ]);
} catch (Throwable $e) {
    error_log('SokoHub M-Pesa Callback Exception: ' . $e->getMessage());
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Acknowledged with internal log',
    ]);
}
