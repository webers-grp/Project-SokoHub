<?php
/**
 * SokoHub M-Pesa Real-Time Payment Status Polling Endpoint
 *
 * Polled by client-side JavaScript on checkout and order tracking pages
 * to verify whether the STK Push prompt was approved by customer PIN.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = current_user();

$orderId = (int) ($_GET['order_id'] ?? 0);
$checkoutRequestId = trim((string) ($_GET['checkout_request_id'] ?? ''));

if ($orderId <= 0 && $checkoutRequestId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'status' => 'error', 'message' => 'Missing order_id or checkout_request_id parameter.']);
    exit;
}

try {
    $pdo = get_db_connection();

    // Verify access if order ID provided and user is logged in
    if ($orderId > 0 && $user !== null) {
        $chk = $pdo->prepare('SELECT buyer_id FROM orders WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $orderId]);
        $buyerId = (int) $chk->fetchColumn();

        if ($user['role'] === 'buyer' && $buyerId !== (int) $user['id']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'Unauthorized order lookup.']);
            exit;
        }
    }

    $poll = mpesa_poll_status($pdo, $orderId, $checkoutRequestId ?: null);
    echo json_encode([
        'ok'      => true,
        'status'  => $poll['status'],
        'paid'    => (bool) $poll['paid'],
        'receipt' => $poll['receipt'] ?? null,
        'message' => $poll['message'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
