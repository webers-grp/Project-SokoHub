<?php
/**
 * SokoHub M-Pesa Local Sandbox Simulation Helper
 *
 * Simulates customer entering their M-Pesa PIN on localhost when testing
 * without internet access or an ngrok webhook tunnel.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid order ID.']);
    exit;
}

try {
    $pdo = get_db_connection();

    // Verify ownership
    $chk = $pdo->prepare('SELECT buyer_id, order_code FROM orders WHERE id = :id LIMIT 1');
    $chk->execute([':id' => $orderId]);
    $ord = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Order not found.']);
        exit;
    }

    if ($user['role'] === 'buyer' && (int) $ord['buyer_id'] !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized order access.']);
        exit;
    }

    $sim = mpesa_simulate_success($pdo, $orderId);
    echo json_encode([
        'ok'      => true,
        'receipt' => $sim['receipt'],
        'message' => $sim['message'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
