<?php
/**
 * Developer Console: Daraja STK Push Simulator
 * Available strictly in SANDBOX environment.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../../includes/mpesa.php';

dev_require_auth();

$mpesaEnv = strtolower(getenv('MPESA_ENV') ?: 'sandbox');
if ($mpesaEnv === 'production') {
    DevAudit::log('security', 'simulator_blocked_production', null, [], 'blocked');
    http_response_code(403);
    die('<div style="padding:2rem;color:red;font-family:sans-serif;"><h3>Security Exception</h3><p>STK Push Simulator is permanently disabled in production mode.</p></div>');
}

$pdo = get_db_connection();
$simulationResult = null;
$error = null;

// Fetch active pending payments for quick selection
$pendingStmt = $pdo->query("
    SELECT p.*, o.order_number, o.total_amount
    FROM payments p
    LEFT JOIN orders o ON o.id = p.order_id
    WHERE p.status = 'pending' AND p.method = 'mpesa'
    ORDER BY p.id DESC LIMIT 20
");
$pendingPayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $checkoutRequestId = trim((string) ($_POST['checkout_request_id'] ?? ''));
        $scenario = trim((string) ($_POST['scenario'] ?? 'success'));

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :cid LIMIT 1');
        $stmt->execute([':cid' => $checkoutRequestId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $error = 'Payment record not found for CheckoutRequestID: ' . htmlspecialchars($checkoutRequestId);
        } else {
            $orderId = (int) $payment['order_id'];
            $amount = (float) $payment['amount'];
            $phone = (string) $payment['phone'];
            $merchantReqId = (string) ($payment['merchant_request_id'] ?: ('MR_' . bin2hex(random_bytes(6))));

            // Build Daraja-Compliant Callback Payload
            $payload = [];
            switch ($scenario) {
                case 'wrong_pin':
                    $payload = [
                        'Body' => [
                            'stkCallback' => [
                                'MerchantRequestID' => $merchantReqId,
                                'CheckoutRequestID' => $checkoutRequestId,
                                'ResultCode'        => 2001,
                                'ResultDesc'        => 'The initiator information is invalid. Wrong PIN entered.',
                            ],
                        ],
                    ];
                    break;

                case 'insufficient_funds':
                    $payload = [
                        'Body' => [
                            'stkCallback' => [
                                'MerchantRequestID' => $merchantReqId,
                                'CheckoutRequestID' => $checkoutRequestId,
                                'ResultCode'        => 1,
                                'ResultDesc'        => 'The balance is insufficient for the transaction.',
                            ],
                        ],
                    ];
                    break;

                case 'cancelled':
                    $payload = [
                        'Body' => [
                            'stkCallback' => [
                                'MerchantRequestID' => $merchantReqId,
                                'CheckoutRequestID' => $checkoutRequestId,
                                'ResultCode'        => 1032,
                                'ResultDesc'        => 'Request cancelled by user.',
                            ],
                        ],
                    ];
                    break;

                case 'timeout':
                    $payload = [
                        'Body' => [
                            'stkCallback' => [
                                'MerchantRequestID' => $merchantReqId,
                                'CheckoutRequestID' => $checkoutRequestId,
                                'ResultCode'        => 1037,
                                'ResultDesc'        => 'DS timeout user cannot be reached or did not respond.',
                            ],
                        ],
                    ];
                    break;

                case 'duplicate':
                case 'success':
                default:
                    $receiptNo = 'SIM' . strtoupper(bin2hex(random_bytes(4)));
                    $payload = [
                        'Body' => [
                            'stkCallback' => [
                                'MerchantRequestID' => $merchantReqId,
                                'CheckoutRequestID' => $checkoutRequestId,
                                'ResultCode'        => 0,
                                'ResultDesc'        => 'The service request is processed successfully.',
                                'CallbackMetadata'  => [
                                    'Item' => [
                                        ['Name' => 'Amount', 'Value' => $amount],
                                        ['Name' => 'MpesaReceiptNumber', 'Value' => $receiptNo],
                                        ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                                        ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                                    ],
                                ],
                            ],
                        ],
                    ];
                    break;
            }

            // Invoke Real STK Callback Processor
            $jsonPayload = json_encode($payload);
            $processResult = mpesa_process_callback($pdo, $jsonPayload);

            $simulationResult = [
                'scenario'       => $scenario,
                'checkout_id'    => $checkoutRequestId,
                'order_id'       => $orderId,
                'process_result' => $processResult,
                'payload'        => $payload,
            ];

            DevAudit::log('payment_lab', 'stk_simulate', (string) $orderId, [
                'checkout_id' => $checkoutRequestId,
                'scenario'    => $scenario,
                'ok'          => $processResult['ok'] ?? false,
            ], ($processResult['ok'] ?? false) ? 'success' : 'failed');
        }
    }
}

$csrfToken = dev_csrf_token();
dev_header('Daraja STK Push Simulator', 'simulator');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">M-Pesa STK Push Simulator</h1>
        <div class="page-subtitle">Sandbox-isolated callback injection and state-machine verification</div>
    </div>
    <span class="badge badge-sandbox">&bull; SANDBOX ENVIRONMENT ONLY</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= icon('alert-circle', 'lucide-sm') ?>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($simulationResult): ?>
    <div class="card" style="border-color: #059669; background: #064e3b22;">
        <div class="card-title" style="color: #34d399;">
            <?= icon('check-circle', 'lucide-sm') ?> Callback Injected Successfully
        </div>
        <p style="font-size: 0.85rem; color: #cbd5e1; margin-bottom: 0.75rem;">
            Processor Response: <strong><?= htmlspecialchars($simulationResult['process_result']['message'] ?? 'Done') ?></strong>
            (Order ID: #<?= $simulationResult['order_id'] ?>)
        </p>
        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.35rem; font-weight: 600;">Simulated Payload Sent to Daraja Handler:</div>
        <pre class="code-block"><?= htmlspecialchars(json_encode($simulationResult['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <div style="margin-top: 0.75rem;">
            <a href="<?= dev_url('pages/payments/inspector.php?order_id=' . $simulationResult['order_id']) ?>" class="btn btn-secondary btn-sm">
                <?= icon('search', 'lucide-xs') ?> Inspect Payment &amp; Ledger
            </a>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
    <!-- Simulator Form -->
    <div class="card">
        <div class="card-title"><?= icon('play-circle', 'lucide-sm') ?> Trigger Callback Scenario</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="checkout_request_id">CheckoutRequestID</label>
                <input type="text" id="checkout_request_id" name="checkout_request_id" class="form-input" required placeholder="ws_CO_..." style="font-family: var(--font-mono);">
            </div>

            <div class="form-group">
                <label class="form-label" for="scenario">Callback Simulation Scenario</label>
                <select id="scenario" name="scenario" class="form-select">
                    <option value="success">Success (ResultCode: 0 - PIN Accepted &amp; Settled)</option>
                    <option value="wrong_pin">Wrong PIN (ResultCode: 2001)</option>
                    <option value="insufficient_funds">Insufficient Funds (ResultCode: 1)</option>
                    <option value="cancelled">User Cancelled on USSD Prompt (ResultCode: 1032)</option>
                    <option value="timeout">DS Request Timeout (ResultCode: 1037)</option>
                    <option value="duplicate">Duplicate Callback (Replay / Idempotency Test)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.65rem;">
                <?= icon('send', 'lucide-xs') ?> Inject Simulated Callback to Pipeline
            </button>
        </form>
    </div>

    <!-- Active Pending Payments for Quick Fill -->
    <div class="card">
        <div class="card-title"><?= icon('clock', 'lucide-sm') ?> Active Pending Payments in DB</div>
        <?php if (empty($pendingPayments)): ?>
            <p style="font-size: 0.85rem; color: var(--text-muted);">No pending M-Pesa payments currently waiting in the database.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="dev-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Amount</th>
                            <th>CheckoutID</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingPayments as $p): ?>
                            <tr>
                                <td>#<?= (int) $p['order_id'] ?></td>
                                <td>KSh <?= number_format($p['amount'], 2) ?></td>
                                <td><code style="font-size:0.75rem;"><?= substr($p['checkout_request_id'], 0, 14) ?>...</code></td>
                                <td>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('checkout_request_id').value='<?= e($p['checkout_request_id']) ?>'">
                                        Select
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php dev_footer(); ?>
