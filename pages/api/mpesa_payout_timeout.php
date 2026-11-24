<?php
/**
 * Safaricom Daraja B2C / B2B Payout Timeout Webhook
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

$rawBody = file_get_contents('php://input');

if (!empty($rawBody)) {
    $pdo = get_db_connection();
    $data = json_decode($rawBody, true);
    $convId = $data['ConversationID'] ?? null;
    if ($convId) {
        $pdo->prepare("UPDATE payouts SET status = 'failed', error_message = 'Daraja queue timeout' WHERE conversation_id = :cid")
            ->execute([':cid' => $convId]);
    }
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Timeout acknowledged']);
exit;
