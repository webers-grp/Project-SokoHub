<?php
/**
 * SokoHub M-Pesa Local Sandbox Simulation Helper
 *
 * Simulates a successful or cancelled STK push response during local testing.
 * Disabled in production environments.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

$config = get_mpesa_config();
if ($config['is_live'] || $config['env'] === 'production' || !$config['allow_simulation'] || (defined('APP_ENV') && APP_ENV === 'production')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Simulation endpoint is strictly disabled in production.']);
    exit;
}

$checkoutRequestId = trim((string) ($_POST['checkout_request_id'] ?? $_GET['checkout_request_id'] ?? ''));
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'success')); // 'success' or 'cancel'

if ($checkoutRequestId === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing CheckoutRequestID.']);
    exit;
}

$pdo = get_db_connection();
$res = mpesa_simulate_callback($pdo, $checkoutRequestId, $action === 'success');

echo json_encode($res);
exit;
