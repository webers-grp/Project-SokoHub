<?php
/**
 * Safaricom Daraja B2C / B2B Payout Result Webhook
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

$rawBody = file_get_contents('php://input');

if (!empty($rawBody)) {
    $pdo = get_db_connection();
    $hash = hash('sha256', $rawBody);

    // Deduplication check
    $check = $pdo->prepare('SELECT id FROM mpesa_callbacks WHERE payload_hash = :hash LIMIT 1');
    $check->execute([':hash' => $hash]);
    if (!$check->fetch()) {
        $data = json_decode($rawBody, true);
        $result = $data['Result'] ?? [];
        $convId = $result['ConversationID'] ?? null;
        $resultCode = (int) ($result['ResultCode'] ?? -1);
        $resultDesc = (string) ($result['ResultDesc'] ?? '');
        $transId = (string) ($result['TransactionID'] ?? '');

        // Log callback
        $pdo->prepare('INSERT INTO mpesa_callbacks (callback_type, request_id, payload_hash, payload) VALUES ("payout_result", :req, :hash, :pl)')
            ->execute([':req' => $convId, ':hash' => $hash, ':pl' => $rawBody]);

        if ($convId) {
            if ($resultCode === 0) {
                $pdo->prepare("UPDATE payouts SET status = 'succeeded', transaction_id = :tx, error_message = NULL, paid_at = NOW() WHERE conversation_id = :cid")
                    ->execute([':tx' => $transId, ':cid' => $convId]);
            } else {
                $pdo->prepare("UPDATE payouts SET status = 'failed', error_message = :err WHERE conversation_id = :cid")
                    ->execute([':err' => $resultDesc, ':cid' => $convId]);
            }
        }
    }
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
exit;
