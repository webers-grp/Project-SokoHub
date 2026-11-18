<?php
/**
 * SokoHub M-Pesa STK Push Real-Time Status Poller
 *
 * Polled via AJAX by the checkout waiting state modal every 2-3 seconds.
 * Returns current payment state and handles fallback query to Daraja.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$pdo = get_db_connection();

$checkoutRequestId = trim((string) ($_GET['checkout_request_id'] ?? $_POST['checkout_request_id'] ?? ''));
$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($checkoutRequestId === '' && $orderId > 0) {
    $cStmt = $pdo->prepare("SELECT checkout_request_id FROM payments WHERE order_id = :oid AND method = 'mpesa' ORDER BY id DESC LIMIT 1");
    $cStmt->execute([':oid' => $orderId]);
    $checkoutRequestId = (string) ($cStmt->fetchColumn() ?: '');
}

if ($checkoutRequestId === '') {
    echo json_encode(['ok' => false, 'status' => 'error', 'message' => 'Missing CheckoutRequestID.']);
    exit;
}

// Find payment record
$payStmt = $pdo->prepare('SELECT p.*, o.order_code, o.payment_status FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE p.checkout_request_id = :cid ORDER BY p.id DESC LIMIT 1');
$payStmt->execute([':cid' => $checkoutRequestId]);
$payment = $payStmt->fetch();

if (!$payment) {
    echo json_encode(['ok' => false, 'status' => 'not_found', 'message' => 'Payment record not found.']);
    exit;
}

$status = $payment['status'];

// If order itself is already marked paid, reflect success
if ($payment['payment_status'] === 'paid' && $status !== 'success') {
    $status = 'success';
}

// If still pending, check age and optionally perform Daraja query
if ($status === 'pending') {
    $createdTs = strtotime($payment['created_at']);
    $age = time() - $createdTs;

    // After 20 seconds, attempt fallback status query if live Daraja is configured
    if ($age > 20 && is_mpesa_live_configured()) {
        $queryResult = mpesa_query_stk_status($pdo, $checkoutRequestId);
        if ($queryResult['ok'] && in_array($queryResult['status'], ['success', 'failed', 'cancelled', 'timeout'], true)) {
            $status = $queryResult['status'];
            $payment['result_desc'] = $queryResult['message'];
        }
    }

    // Auto-timeout after 90 seconds
    if ($age > 90) {
        $status = 'timeout';
        $pdo->prepare("UPDATE payments SET status = 'timeout', result_desc = 'Customer response timed out' WHERE id = :id AND status = 'pending'")
            ->execute([':id' => (int) $payment['id']]);
    }
}

$friendlyMessage = match ($status) {
    'success' => 'Payment received successfully! Your order has been placed.',
    'cancelled' => 'You cancelled the M-Pesa prompt on your phone.',
    'timeout' => 'M-Pesa prompt timed out. Please click Retry below to try again.',
    'failed' => $payment['result_desc'] ?: 'Payment could not be completed. Please try again.',
    default => 'Check your phone and enter your M-Pesa PIN to complete payment.',
};

echo json_encode([
    'ok'                  => true,
    'status'              => $status,
    'order_id'            => (int) $payment['order_id'],
    'order_code'          => $payment['order_code'],
    'checkout_request_id' => $checkoutRequestId,
    'mpesa_receipt'       => $payment['mpesa_receipt'] ?? null,
    'message'             => $friendlyMessage,
    'redirect_url'        => url('pages/buyer/orders.php?order_id=' . (int) $payment['order_id'] . '&payment_result=' . $status),
]);
exit;
