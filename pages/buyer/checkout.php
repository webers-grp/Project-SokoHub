<?php
/**
 * SokoHub Order Checkout (Atomic MySQL Transaction)
 *
 * Supports exactly two payment methods:
 * 1. M-Pesa (Safaricom Daraja STK Push)
 * 2. Cash on Delivery (COD)
 *
 * Implements:
 * - Phone number normalization (07XX, 01XX, +254, 254 -> 254XXXXXXXXX)
 * - Atomic order placement with row-level stock locks
 * - STK Push initiation
 * - Real-time polling waiting modal with simulation fallback
 * - Clean COD order placement with 'pending_cod' status
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_login();
$pdo = get_db_connection();

if (empty($_SESSION['cart'])) {
    set_flash('warning', 'Your shopping cart is empty. Add items before checking out.');
    redirect('pages/public/products.php');
}

$deliveryFee = 150.00;
$ids = array_map('intval', array_keys($_SESSION['cart']));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT id, seller_id, name, price, stock_qty FROM products WHERE id IN ({$placeholders}) AND status = 'active'"
);
$stmt->execute($ids);
$dbProducts = $stmt->fetchAll();

$items = [];
$itemsSubtotal = 0.0;
foreach ($dbProducts as $p) {
    $pid = (int) $p['id'];
    $qty = min((int) $p['stock_qty'], max(1, (int) ($_SESSION['cart'][$pid] ?? 0)));
    if ($qty <= 0) {
        continue;
    }
    $lineTotal = (float) $p['price'] * $qty;
    $itemsSubtotal += $lineTotal;
    $p['qty'] = $qty;
    $p['line_total'] = $lineTotal;
    $items[] = $p;
}

if (empty($items)) {
    $_SESSION['cart'] = [];
    set_flash('error', 'None of your cart items are currently available.');
    redirect('pages/public/products.php');
}

$errors = [];
$old = [
    'delivery_address' => '',
    'delivery_town'    => 'Kutus',
    'payment_method'   => 'M-Pesa',
    'mpesa_phone'      => $user['phone'] ?? '',
    'notes'            => '',
];

$stkState = null; // Will hold STK push modal payload when M-Pesa is triggered

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['delivery_address'] = trim((string) ($_POST['delivery_address'] ?? ''));
        $old['delivery_town'] = trim((string) ($_POST['delivery_town'] ?? ''));
        $old['payment_method'] = (string) ($_POST['payment_method'] ?? 'M-Pesa');
        $old['mpesa_phone'] = trim((string) ($_POST['mpesa_phone'] ?? ''));
        $old['notes'] = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($old['delivery_address']) < 5) {
            $errors[] = 'Please provide a clear delivery address (at least 5 characters).';
        }
        if (mb_strlen($old['delivery_town']) < 2) {
            $errors[] = 'Please provide your delivery town.';
        }
        if (!in_array($old['payment_method'], ['M-Pesa', 'Cash on Delivery'], true)) {
            $errors[] = 'Please select a valid payment method (M-Pesa or Cash on Delivery).';
        }

        // Validate M-Pesa phone number if M-Pesa was chosen
        $normMpesaPhone = '';
        if ($old['payment_method'] === 'M-Pesa') {
            $normMpesaPhone = mpesa_normalize_phone($old['mpesa_phone']);
            if ($normMpesaPhone === '') {
                $errors[] = 'Please provide a valid Kenyan M-Pesa phone number (e.g. 0712345678, 0112345678, or +254...).';
            }
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $verifiedItems = [];
                $verifiedSubtotal = 0.0;

                $lockStmt = $pdo->prepare("SELECT id, seller_id, name, price, stock_qty FROM products WHERE id = :id AND status = 'active' FOR UPDATE");
                foreach ($items as $it) {
                    $lockStmt->execute([':id' => (int) $it['id']]);
                    $fresh = $lockStmt->fetch();
                    if (!$fresh || (int) $fresh['stock_qty'] < (int) $it['qty']) {
                        throw new RuntimeException('Insufficient stock for "' . $it['name'] . '". Please update your cart.');
                    }
                    $line = (float) $fresh['price'] * (int) $it['qty'];
                    $verifiedSubtotal += $line;
                    $verifiedItems[] = [
                        'id'         => (int) $fresh['id'],
                        'seller_id'  => (int) $fresh['seller_id'],
                        'qty'        => (int) $it['qty'],
                        'unit_price' => (float) $fresh['price'],
                        'line_total' => $line,
                    ];
                }

                $grandTotal = $verifiedSubtotal;
                $orderCode = 'SKH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

                // Payment Status:
                // COD -> 'pending_cod'
                // M-Pesa -> 'unpaid' (NEVER mark paid on frontend; only verified callback/query does that)
                $payStatus = ($old['payment_method'] === 'Cash on Delivery') ? 'pending_cod' : 'unpaid';

                $ordStmt = $pdo->prepare(
                    "INSERT INTO orders
                     (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, delivery_fee, notes)
                     VALUES (:code, :buyer, :total, :pmethod, :pstatus, 'pending', :addr, :town, :dfee, :notes)"
                );
                $ordStmt->execute([
                    ':code'    => $orderCode,
                    ':buyer'   => (int) $user['id'],
                    ':total'   => $grandTotal,
                    ':pmethod' => $old['payment_method'],
                    ':pstatus' => $payStatus,
                    ':addr'    => $old['delivery_address'],
                    ':town'    => $old['delivery_town'],
                    ':dfee'    => $deliveryFee,
                    ':notes'   => $old['notes'],
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
                     VALUES (:oid, :pid, :sid, :qty, :price, :line)'
                );
                $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :pid');

                foreach ($verifiedItems as $vi) {
                    $itemStmt->execute([
                        ':oid'   => $orderId,
                        ':pid'   => $vi['id'],
                        ':sid'   => $vi['seller_id'],
                        ':qty'   => $vi['qty'],
                        ':price' => $vi['unit_price'],
                        ':line'  => $vi['line_total'],
                    ]);
                    $stockStmt->execute([
                        ':qty' => $vi['qty'],
                        ':pid' => $vi['id'],
                    ]);
                }

                $delStmt = $pdo->prepare(
                    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
                     VALUES (:oid, 'Kutus Market', :dropoff, :fee, 'pending', 'Awaiting rider assignment')"
                );
                $delStmt->execute([
                    ':oid'     => $orderId,
                    ':dropoff' => $old['delivery_town'] . ' - ' . $old['delivery_address'],
                    ':fee'     => $deliveryFee,
                ]);

                $pdo->commit();
                $_SESSION['cart'] = [];

                if ($old['payment_method'] === 'Cash on Delivery') {
                    set_flash('success', 'Order ' . $orderCode . ' placed successfully with Cash on Delivery! Payment status is pending (COD) — pay your rider when items arrive.');
                    redirect('pages/buyer/orders.php?order_id=' . $orderId);
                } else {
                    // M-Pesa: Trigger STK Push
                    $stkRes = mpesa_initiate_stk_push($pdo, $orderId, $normMpesaPhone, $grandTotal);
                    if ($stkRes['ok']) {
                        $stkState = [
                            'order_id'            => $orderId,
                            'order_code'          => $orderCode,
                            'amount'              => $grandTotal,
                            'phone'               => $normMpesaPhone,
                            'checkout_request_id' => $stkRes['checkout_request_id'],
                            'message'             => $stkRes['message'],
                            'simulated'           => $stkRes['simulated'],
                        ];
                    } else {
                        set_flash('warning', 'Order ' . $orderCode . ' was created, but M-Pesa STK push could not be initiated: ' . $stkRes['message'] . '. You can retry payment below.');
                        redirect('pages/buyer/orders.php?order_id=' . $orderId);
                    }
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'SokoHub | Checkout';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Order Checkout</h1>
            <p class="muted">Confirm delivery location and choose between M-Pesa or Cash on Delivery.</p>
        </div>
        <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="btn btn-outline">
            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
            <span>Back to Cart</span>
        </a>
    </div>

    <?php if ($stkState !== null): ?>
        <!-- ==================== M-PESA STK WAITING STATE MODAL ==================== -->
        <div class="card" style="max-width: 650px; margin: 0 auto; text-align: center; border: 2px solid var(--primary); box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
            <div style="margin-bottom: 1rem;">
                <div class="pulse-icon" style="width: 64px; height: 64px; margin: 0 auto; background: #ecfdf5; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <?= icon('smartphone', 'lucide-md', 'emerald') ?>
                </div>
            </div>

            <span class="badge badge-warning" id="modalBadge" style="font-size: 0.85rem; padding: 0.35rem 0.8rem;">
                Awaiting M-Pesa PIN...
            </span>

            <h2 style="margin-top: 0.75rem; color: var(--green-dark);">Check Your Phone</h2>
            <p class="muted" id="modalInstruction" style="margin-top: 0.5rem; font-size: 1.05rem;">
                An M-Pesa payment prompt for <strong><?= format_ksh($stkState['amount']) ?></strong> has been sent to <strong><?= e($stkState['phone']) ?></strong>. Please enter your M-Pesa PIN on your handset.
            </p>

            <div style="margin: 1.5rem 0; display: flex; justify-content: center; align-items: center; gap: 0.75rem;">
                <div class="spinner" id="modalSpinner" style="width: 24px; height: 24px; border: 3px solid #cbd5e1; border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                <span class="small muted" id="modalTimerText">Waiting for Safaricom confirmation (polling)...</span>
            </div>

            <!-- Development Simulation Bar (Only shown in sandbox/simulation mode) -->
            <?php if (get_mpesa_config()['allow_simulation']): ?>
                <div style="background: #f1f5f9; border-radius: 8px; padding: 0.85rem; margin: 1.25rem 0; text-align: left;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                        <span class="small" style="font-weight: 700; color: #475569;">Developer Sandbox Controls:</span>
                        <span class="badge badge-neutral" style="font-size: 0.7rem;">Simulate Handset</span>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="simulateMpesa('success')">
                            <?= icon('check', 'lucide-xs', 'white') ?>
                            <span>Simulate Correct PIN (Success)</span>
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="simulateMpesa('cancel')">
                            <?= icon('x', 'lucide-xs', 'rose') ?>
                            <span>Simulate User Cancel</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div id="modalActionArea" style="display: none; margin-top: 1.5rem;">
                <a id="retryBtn" href="<?= e(url('pages/buyer/orders.php?order_id=' . $stkState['order_id'])) ?>" class="btn btn-amber">
                    <?= icon('refresh-cw', 'lucide-xs', 'white') ?>
                    <span>Retry M-Pesa Payment</span>
                </a>
                <a href="<?= e(url('pages/buyer/orders.php?order_id=' . $stkState['order_id'])) ?>" class="btn btn-outline" style="margin-left: 0.5rem;">
                    <span>View Order Details</span>
                </a>
            </div>
        </div>

        <script>
        const checkoutId = <?= json_encode($stkState['checkout_request_id']) ?>;
        const orderId = <?= (int) $stkState['order_id'] ?>;
        const statusUrl = <?= json_encode(url('pages/api/mpesa_status.php')) ?>;
        const simulateUrl = <?= json_encode(url('pages/api/mpesa_simulate.php')) ?>;

        let pollCount = 0;
        const maxPolls = 35; // 35 * 2.5s = ~87 seconds

        const pollInterval = setInterval(async () => {
            pollCount++;
            try {
                const res = await fetch(`${statusUrl}?checkout_request_id=${encodeURIComponent(checkoutId)}&order_id=${orderId}`);
                const data = await res.json();

                if (data.status === 'success') {
                    clearInterval(pollInterval);
                    document.getElementById('modalBadge').className = 'badge badge-success';
                    document.getElementById('modalBadge').textContent = 'Payment Confirmed!';
                    document.getElementById('modalSpinner').style.display = 'none';
                    document.getElementById('modalTimerText').textContent = 'Receipt: ' + (data.mpesa_receipt || 'Confirmed');
                    document.getElementById('modalInstruction').innerHTML = `<strong style="color: #059669;">Payment Received!</strong> Receipt: <strong>${data.mpesa_receipt || ''}</strong>. Redirecting to your order...`;
                    setTimeout(() => {
                        window.location.href = data.redirect_url;
                    }, 1800);
                } else if (data.status === 'failed' || data.status === 'cancelled' || data.status === 'timeout') {
                    clearInterval(pollInterval);
                    document.getElementById('modalSpinner').style.display = 'none';
                    document.getElementById('modalBadge').className = 'badge badge-danger';
                    document.getElementById('modalBadge').textContent = data.status.toUpperCase();
                    document.getElementById('modalInstruction').textContent = data.message || 'Payment was not completed.';
                    document.getElementById('modalTimerText').textContent = 'Status: ' + data.status;
                    document.getElementById('modalActionArea').style.display = 'block';
                }
            } catch (err) {
                console.warn('Status poll error:', err);
            }

            if (pollCount >= maxPolls) {
                clearInterval(pollInterval);
                document.getElementById('modalSpinner').style.display = 'none';
                document.getElementById('modalBadge').className = 'badge badge-warning';
                document.getElementById('modalBadge').textContent = 'TIMEOUT';
                document.getElementById('modalInstruction').textContent = 'Prompt timed out. If you already entered your PIN, please check your orders page.';
                document.getElementById('modalActionArea').style.display = 'block';
            }
        }, 2500);

        async function simulateMpesa(action) {
            try {
                const res = await fetch(simulateUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `checkout_request_id=${encodeURIComponent(checkoutId)}&action=${action}`
                });
                const data = await res.json();
                console.log('Simulated response:', data);
            } catch (e) {
                console.error(e);
            }
        }
        </script>
        <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        </style>

    <?php else: ?>
        <!-- ==================== STANDARD CHECKOUT FORM ==================== -->
        <div class="split-grid" style="align-items: start;">
            <div class="card">
                <h2 style="color: var(--green-dark); margin-bottom: 1rem;">
                    <?= icon('truck', 'lucide-sm', 'amber') ?>
                    <span>Delivery &amp; Payment Details</span>
                </h2>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <div>
                            <?php foreach ($errors as $err): ?>
                                <div><?= e($err) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="<?= e(url('pages/buyer/checkout.php')) ?>" method="POST" data-validate="true">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="form-group">
                        <label for="delivery_town">Town / Delivery Area</label>
                        <input type="text" id="delivery_town" name="delivery_town" class="form-control" value="<?= e($old['delivery_town']) ?>" required data-minlength="2" placeholder="e.g. Kutus, Kerugoya, Sagana">
                    </div>

                    <div class="form-group">
                        <label for="delivery_address">Specific Drop-Off Address / Landmark</label>
                        <input type="text" id="delivery_address" name="delivery_address" class="form-control" value="<?= e($old['delivery_address']) ?>" required data-minlength="5" placeholder="e.g. Kirinyaga University Gate B, Hostel 2">
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-control" required onchange="togglePaymentFields()">
                            <option value="M-Pesa" <?= $old['payment_method'] === 'M-Pesa' ? 'selected' : '' ?>>M-Pesa (Safaricom Daraja STK Push)</option>
                            <option value="Cash on Delivery" <?= $old['payment_method'] === 'Cash on Delivery' ? 'selected' : '' ?>>Cash on Delivery (COD)</option>
                        </select>
                        <small class="muted">Payments via M-Pesa are sent directly to platform Till 1648753.</small>
                    </div>

                    <!-- M-Pesa Phone Number Input -->
                    <div class="form-group" id="mpesa_phone_group" style="<?= $old['payment_method'] === 'M-Pesa' ? '' : 'display:none;' ?>">
                        <label for="mpesa_phone">M-Pesa Phone Number to Charge</label>
                        <input type="tel" id="mpesa_phone" name="mpesa_phone" class="form-control" value="<?= e($old['mpesa_phone']) ?>" placeholder="e.g. 0712 345 678 or 0112 345 678">
                        <small class="muted">Enter your phone number. You will receive an instant STK Push prompt to enter your PIN.</small>
                    </div>

                    <div class="form-group">
                        <label for="notes">Rider Instructions (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="Call on arrival, nearby landmark, etc."><?= e($old['notes']) ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-amber btn-block" style="padding: 0.85rem; font-size: 1.05rem;">
                        <?= icon('check-circle', 'lucide-sm', 'white') ?>
                        <span id="submitBtnText">Place Order with M-Pesa</span>
                    </button>
                </form>
            </div>

            <!-- Order Summary Column -->
            <div class="card">
                <h2 style="margin-bottom: 1rem;">
                    <?= icon('receipt', 'lucide-sm', 'purple') ?>
                    <span>Order Summary</span>
                </h2>
                <ul style="list-style:none;">
                    <?php foreach ($items as $it): ?>
                        <li style="display:flex; justify-content:space-between; padding:0.55rem 0; border-bottom:1px solid var(--border-color);">
                            <span><?= e($it['name']) ?> &times; <?= (int) $it['qty'] ?></span>
                            <strong><?= format_ksh($it['line_total']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="display:flex; justify-content:space-between; margin-top:0.85rem;" class="muted">
                    <span>Products Subtotal</span>
                    <span><?= format_ksh($itemsSubtotal) ?></span>
                </p>
                <p style="display:flex; justify-content:space-between; margin-top:0.4rem;" class="muted">
                    <span>Delivery Fee (Paid to Rider)</span>
                    <span><?= format_ksh($deliveryFee) ?></span>
                </p>
                <p style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:1.25rem; font-weight:800; color:var(--green-dark); border-top: 2px dashed var(--border-color); padding-top: 0.75rem;">
                    <span>Order Total</span>
                    <span><?= format_ksh($itemsSubtotal) ?></span>
                </p>

                <div style="margin-top: 1.5rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; color: #0f766e;">
                        <?= icon('shield-check', 'lucide-sm', 'teal') ?>
                        <strong style="font-size: 0.9rem;">SokoHub Safe Escrow Guarantee</strong>
                    </div>
                    <p class="small muted" style="margin-top: 0.35rem; line-height: 1.45;">
                        Funds paid via M-Pesa are protected in platform escrow. The vendor and delivery rider are only paid once you confirm receipt of your goods.
                    </p>
                </div>
            </div>
        </div>

        <script>
        function togglePaymentFields() {
            const method = document.getElementById('payment_method').value;
            const phoneGroup = document.getElementById('mpesa_phone_group');
            const phoneInput = document.getElementById('mpesa_phone');
            const submitBtn = document.getElementById('submitBtnText');

            if (method === 'M-Pesa') {
                phoneGroup.style.display = 'block';
                phoneInput.setAttribute('required', 'required');
                submitBtn.textContent = 'Pay with M-Pesa (STK Push)';
            } else {
                phoneGroup.style.display = 'none';
                phoneInput.removeAttribute('required');
                submitBtn.textContent = 'Confirm Cash on Delivery Order';
            }
        }
        document.addEventListener('DOMContentLoaded', togglePaymentFields);
        </script>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
