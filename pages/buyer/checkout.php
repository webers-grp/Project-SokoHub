<?php
/**
 * SokoHub Order Checkout (Atomic MySQL Transaction)
 *
 * Supports exactly two payment methods:
 * 1. M-Pesa (Safaricom Daraja STK Push)
 * 2. Cash on Delivery (COD)
 *
 * Implements:
 * - Dynamic delivery fee resolution based on delivery_zones and free delivery threshold
 * - Promotional coupon verification and discount calculation
 * - Saved user addresses pre-selection
 * - Phone number normalization (07XX, 01XX, +254, 254 -> 254XXXXXXXXX)
 * - Atomic order placement with row-level stock locks
 * - STK Push initiation & polling waiting modal
 * - Clean COD order placement with 'pending_cod' status
 * - Order status history audit logging and offline seller telephony dispatch
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';
require_once __DIR__ . '/../../includes/delivery.php';
require_once __DIR__ . '/../../includes/coupons.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/telephony.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];
$pdo = get_db_connection();

if (empty($_SESSION['cart'])) {
    set_flash('warning', 'Your shopping cart is empty. Add items before checking out.');
    redirect('pages/public/products.php');
}

// Fetch active delivery zones
$deliveryZones = Delivery::getActiveZones();

// Fetch saved user addresses
$addrStmt = $pdo->prepare('SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC');
$addrStmt->execute([':uid' => $userId]);
$savedAddresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch cart items
$ids = array_map('intval', array_keys($_SESSION['cart']));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT id, seller_id, name, price, stock_qty FROM products WHERE id IN ({$placeholders}) AND status = 'active'"
);
$stmt->execute($ids);
$dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

$defaultTown = !empty($savedAddresses[0]['town']) ? $savedAddresses[0]['town'] : ($user['town'] ?? 'Kutus');
$defaultAddress = !empty($savedAddresses[0]['address_line']) ? $savedAddresses[0]['address_line'] : '';

$errors = [];
$old = [
    'delivery_address' => $defaultAddress,
    'delivery_town'    => $defaultTown,
    'payment_method'   => 'M-Pesa',
    'mpesa_phone'      => $user['phone'] ?? '',
    'coupon_code'      => '',
    'notes'            => '',
];

$stkState = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['delivery_address'] = trim((string) ($_POST['delivery_address'] ?? ''));
        $old['delivery_town']    = trim((string) ($_POST['delivery_town'] ?? ''));
        $old['payment_method']   = (string) ($_POST['payment_method'] ?? 'M-Pesa');
        $old['mpesa_phone']      = trim((string) ($_POST['mpesa_phone'] ?? ''));
        $old['coupon_code']      = strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));
        $old['notes']            = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($old['delivery_address']) < 5) {
            $errors[] = 'Please provide a clear delivery address (at least 5 characters).';
        }
        if (mb_strlen($old['delivery_town']) < 2) {
            $errors[] = 'Please select your delivery town.';
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

        // Calculate delivery fee dynamically
        $deliveryCalc = Delivery::calculateFee($old['delivery_town'], $itemsSubtotal);
        $deliveryFee  = $deliveryCalc['fee'];

        // Validate coupon if entered
        $couponData = null;
        $discountAmount = 0.0;
        if (!empty($old['coupon_code'])) {
            $couponCheck = Coupons::validate($old['coupon_code'], $itemsSubtotal, $userId);
            if (!$couponCheck['ok']) {
                $errors[] = $couponCheck['error'];
            } else {
                $couponData = $couponCheck['coupon'];
                $discountAmount = $couponCheck['discount'];
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
                    $fresh = $lockStmt->fetch(PDO::FETCH_ASSOC);
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
                        'name'       => $fresh['name'],
                    ];
                }

                // Final total is net of discount + delivery fee
                // Note: delivery fee is collected and settled to the delivery rider
                $netGoodsTotal = max(0.0, $verifiedSubtotal - $discountAmount);
                $grandTotal    = $netGoodsTotal + $deliveryFee;
                $orderCode     = 'SKH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

                // Payment Status:
                // COD -> 'pending_cod'
                // M-Pesa -> 'unpaid'
                $payStatus = ($old['payment_method'] === 'Cash on Delivery') ? 'pending_cod' : 'unpaid';

                $ordStmt = $pdo->prepare(
                    "INSERT INTO orders
                     (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, delivery_fee, discount_amount, coupon_id, notes, created_at)
                     VALUES (:code, :buyer, :total, :pmethod, :pstatus, 'pending', :addr, :town, :dfee, :disc, :cid, :notes, NOW())"
                );
                $ordStmt->execute([
                    ':code'    => $orderCode,
                    ':buyer'   => $userId,
                    ':total'   => $grandTotal,
                    ':pmethod' => $old['payment_method'],
                    ':pstatus' => $payStatus,
                    ':addr'    => $old['delivery_address'],
                    ':town'    => $old['delivery_town'],
                    ':dfee'    => $deliveryFee,
                    ':disc'    => $discountAmount,
                    ':cid'     => $couponData ? (int) $couponData['id'] : null,
                    ':notes'   => $old['notes'] ?: null,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                // Insert order items & reduce stock atomically
                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
                     VALUES (:oid, :pid, :sid, :qty, :price, :line)'
                );
                $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :pid');
                $stockMoveStmt = $pdo->prepare('
                    INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by, created_at)
                    VALUES (:pid, :oid, :qty, "reserve", :bal, :uid, NOW())
                ');

                $sellerTotals = [];

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

                    $bal = (int) $pdo->query("SELECT stock_qty FROM products WHERE id = {$vi['id']}")->fetchColumn();
                    $stockMoveStmt->execute([
                        ':pid' => $vi['id'],
                        ':oid' => $orderId,
                        ':qty' => -$vi['qty'],
                        ':bal' => $bal,
                        ':uid' => $userId,
                    ]);

                    // Accumulate items for offline telephony notification
                    $sellerTotals[$vi['seller_id']]['total'] = ($sellerTotals[$vi['seller_id']]['total'] ?? 0) + $vi['line_total'];
                    $sellerTotals[$vi['seller_id']]['items'][] = "{$vi['qty']}x {$vi['name']}";
                }

                // Insert delivery record
                $delStmt = $pdo->prepare(
                    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
                     VALUES (:oid, 'Kutus Central Market', :dropoff, :fee, 'pending', 'Awaiting delivery rider assignment')"
                );
                $delStmt->execute([
                    ':oid'     => $orderId,
                    ':dropoff' => $old['delivery_town'] . ' - ' . $old['delivery_address'],
                    ':fee'     => $deliveryFee,
                ]);

                // Record coupon usage if applied
                if ($couponData) {
                    Coupons::recordUsage((int) $couponData['id'], $orderId, $userId, $discountAmount);
                }

                // Record initial order status history
                record_order_status_history($pdo, $orderId, 'pending', null, $userId, $old['delivery_town'], 'Order placed by customer via ' . $old['payment_method']);

                // Audit log
                Audit::record('order.created', 'order', $orderId, [
                    'order_code'     => $orderCode,
                    'total_amount'   => $grandTotal,
                    'payment_method' => $old['payment_method'],
                    'delivery_town'  => $old['delivery_town'],
                    'discount'       => $discountAmount,
                ], $userId, 'buyer');

                $pdo->commit();
                $orderCreated = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }

            if (!empty($orderCreated)) {
                $_SESSION['cart'] = [];

                // Notify offline sellers safely
                try {
                    foreach ($sellerTotals as $sellerUserId => $sData) {
                        $itemSummary = implode(', ', $sData['items']);
                        Telephony::notifyOfflineSellerOnOrder($sellerUserId, $orderCode, $itemSummary, (float) $sData['total']);
                    }
                } catch (Throwable $te) {
                    error_log('Telephony notification error: ' . $te->getMessage());
                }

                if ($old['payment_method'] === 'Cash on Delivery') {
                    set_flash('success', 'Order ' . $orderCode . ' placed successfully with Cash on Delivery! Payment status is pending (COD) — pay your rider upon delivery.');
                    redirect('pages/buyer/orders.php?order_code=' . urlencode($orderCode));
                } else {
                    // M-Pesa: Trigger STK Push
                    try {
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
                            redirect('pages/buyer/orders.php?order_code=' . urlencode($orderCode));
                        }
                    } catch (Throwable $pe) {
                        error_log('M-Pesa STK push error: ' . $pe->getMessage());
                        set_flash('warning', 'Order ' . $orderCode . ' was placed, but payment prompt failed. You can retry payment in your orders page.');
                        redirect('pages/buyer/orders.php?order_code=' . urlencode($orderCode));
                    }
                }
            }
        }
    }
}

// Initial Delivery Fee Calculation for view
$initialDelivery = Delivery::calculateFee($old['delivery_town'], $itemsSubtotal);

$pageTitle = 'SokoHub | Checkout';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space" style="max-width: 1100px;">
    <div class="page-head" style="margin-bottom: 1.5rem;">
        <div>
            <h1>Order Checkout</h1>
            <p class="muted">Confirm delivery destination and choose between M-Pesa or Cash on Delivery.</p>
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
                <a id="retryBtn" href="<?= e(url('pages/buyer/orders.php?order_code=' . urlencode($stkState['order_code']))) ?>" class="btn btn-amber">
                    <?= icon('refresh-cw', 'lucide-xs', 'white') ?>
                    <span>Retry M-Pesa Payment</span>
                </a>
                <a href="<?= e(url('pages/buyer/orders.php?order_code=' . urlencode($stkState['order_code']))) ?>" class="btn btn-outline" style="margin-left: 0.5rem;">
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
        const maxPolls = 35;

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
        <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

    <?php else: ?>
        <!-- ==================== 2-COLUMN CHECKOUT FORM ==================== -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 2rem; align-items: start;">
            <!-- Left Column: Delivery & Payment Details -->
            <div class="card" style="padding: 1.75rem;">
                <h2 style="color: var(--green-dark); margin-bottom: 1.25rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('truck', 'lucide-sm', 'amber') ?>
                    <span>1. Delivery Destination &amp; Payment</span>
                </h2>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                        <div>
                            <?php foreach ($errors as $err): ?>
                                <div><?= e($err) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Saved Addresses Pre-selector -->
                <?php if (!empty($savedAddresses)): ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                        <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #0f172a; margin-bottom: 0.4rem;">
                            📍 Use a Saved Delivery Address:
                        </label>
                        <select id="saved_address_selector" class="form-control" onchange="applySavedAddress(this)" style="font-size: 0.85rem;">
                            <option value="">-- Choose from your saved addresses --</option>
                            <?php foreach ($savedAddresses as $idx => $sa): ?>
                                <option value="<?= $idx ?>" data-town="<?= e($sa['town']) ?>" data-addr="<?= e($sa['address_line']) ?>" data-phone="<?= e($sa['phone']) ?>">
                                    <?= e($sa['label']) ?>: <?= e($sa['town']) ?> - <?= e($sa['address_line']) ?> <?= $sa['is_default'] ? '(Default)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <form action="<?= e(url('pages/buyer/checkout.php')) ?>" method="POST" id="checkoutForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- Town Selector (Dynamic Delivery Fee) -->
                    <div class="form-group">
                        <label for="delivery_town">Town / Delivery Zone</label>
                        <select id="delivery_town" name="delivery_town" class="form-control" required onchange="updateDeliveryFee()">
                            <?php foreach ($deliveryZones as $zone): ?>
                                <?php
                                    $isSel = (strcasecmp($old['delivery_town'], $zone['town_name']) === 0 || strcasecmp($old['delivery_town'], $zone['zone_name']) === 0);
                                    $zoneFee = (float) $zone['delivery_fee'];
                                ?>
                                <option value="<?= e($zone['town_name']) ?>" 
                                        data-fee="<?= $zoneFee ?>" 
                                        data-threshold="3000"
                                        data-town="<?= e($zone['town_name']) ?>"
                                        data-zone="<?= e($zone['zone_name']) ?>"
                                        <?= $isSel ? 'selected' : '' ?>>
                                    <?= e($zone['town_name']) ?> (<?= e($zone['zone_name']) ?> &mdash; KES <?= number_format($zoneFee, 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="muted">Local delivery fee calculated automatically based on destination.</small>
                    </div>

                    <div class="form-group">
                        <label for="delivery_address">Specific Drop-Off Address / Landmark</label>
                        <input type="text" id="delivery_address" name="delivery_address" class="form-control" value="<?= e($old['delivery_address']) ?>" required placeholder="e.g. Kirinyaga University Gate B, Joy Apartments Room 3">
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-control" required onchange="togglePaymentFields()">
                            <option value="M-Pesa" <?= $old['payment_method'] === 'M-Pesa' ? 'selected' : '' ?>>M-Pesa (Safaricom Daraja STK Push)</option>
                            <option value="Cash on Delivery" <?= $old['payment_method'] === 'Cash on Delivery' ? 'selected' : '' ?>>Cash on Delivery (COD)</option>
                        </select>
                        <small class="muted">M-Pesa charges go to platform Till 1648753 under safe escrow.</small>
                    </div>

                    <!-- M-Pesa Phone Number Input -->
                    <div class="form-group" id="mpesa_phone_group" style="<?= $old['payment_method'] === 'M-Pesa' ? '' : 'display:none;' ?>">
                        <label for="mpesa_phone">M-Pesa Phone Number to Charge</label>
                        <input type="tel" id="mpesa_phone" name="mpesa_phone" class="form-control" value="<?= e($old['mpesa_phone']) ?>" placeholder="e.g. 0712 345 678 or 0112 345 678">
                        <small class="muted">Enter your phone number. You will receive an instant STK Push prompt to enter your PIN.</small>
                    </div>

                    <div class="form-group">
                        <label for="notes">Rider Instructions (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Call before arrival, leave at security desk, etc."><?= e($old['notes']) ?></textarea>
                    </div>

                    <!-- Hidden Coupon Field synced from summary column -->
                    <input type="hidden" name="coupon_code" id="hidden_coupon_code" value="<?= e($old['coupon_code']) ?>">

                    <button type="submit" class="btn btn-amber btn-block" style="padding: 0.85rem; font-size: 1.05rem; font-weight: 700;">
                        <?= icon('check-circle', 'lucide-sm', 'white') ?>
                        <span id="submitBtnText">Place Order with M-Pesa</span>
                    </button>
                </form>
            </div>

            <!-- Right Column: Order Summary & Coupon Code -->
            <div class="card" style="padding: 1.75rem;">
                <h2 style="margin-bottom: 1.25rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('receipt', 'lucide-sm', 'purple') ?>
                    <span>2. Order Summary</span>
                </h2>

                <ul style="list-style:none; padding: 0; margin: 0 0 1rem;">
                    <?php foreach ($items as $it): ?>
                        <li style="display:flex; justify-content:space-between; padding:0.55rem 0; border-bottom:1px solid var(--border-color); font-size: 0.9rem;">
                            <span><?= e($it['name']) ?> &times; <?= (int) $it['qty'] ?></span>
                            <strong><?= format_ksh($it['line_total']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Promotional Coupon Box -->
                <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 0.35rem;">
                        🏷️ Have a Promo Coupon?
                    </label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="coupon_input" placeholder="e.g. WELCOME10, SOKO50" value="<?= e($old['coupon_code']) ?>" class="form-control" style="text-transform: uppercase; font-family: monospace; font-size: 0.85rem;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="applyCouponCode()">Apply</button>
                    </div>
                    <div id="coupon_feedback" style="font-size: 0.75rem; margin-top: 0.4rem; color: #16a34a; font-weight: 600;"></div>
                </div>

                <!-- Price Breakdown -->
                <p style="display:flex; justify-content:space-between; margin-top:0.5rem; font-size: 0.9rem;" class="muted">
                    <span>Products Subtotal</span>
                    <span id="summary_subtotal"><?= format_ksh($itemsSubtotal) ?></span>
                </p>

                <p id="discount_row" style="display:none; justify-content:space-between; margin-top:0.4rem; font-size: 0.9rem; color: #15803d; font-weight: 600;">
                    <span>Promotional Discount</span>
                    <span id="summary_discount">- KES 0.00</span>
                </p>

                <p style="display:flex; justify-content:space-between; margin-top:0.4rem; font-size: 0.9rem;" class="muted">
                    <span>Delivery Fee (<span id="summary_town_label"><?= e($initialDelivery['zone_name']) ?></span>)</span>
                    <span id="summary_delivery"><?= $initialDelivery['fee'] == 0 ? 'FREE' : format_ksh($initialDelivery['fee']) ?></span>
                </p>

                <p style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:1.35rem; font-weight:800; color:var(--green-dark); border-top: 2px dashed var(--border-color); padding-top: 0.75rem;">
                    <span>Total Amount</span>
                    <span id="summary_grand_total"><?= format_ksh($itemsSubtotal + $initialDelivery['fee']) ?></span>
                </p>

                <div style="margin-top: 1.5rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; color: #166534;">
                        <?= icon('shield-check', 'lucide-sm', 'teal') ?>
                        <strong style="font-size: 0.85rem;">SokoHub Escrow &amp; Safe Payout Guarantee</strong>
                    </div>
                    <p class="small muted" style="margin-top: 0.35rem; line-height: 1.45; color: #334155;">
                        Platform keeps a 2% fee; 98% is settled to vendors and riders upon verified doorstep delivery.
                    </p>
                </div>
            </div>
        </div>

        <script>
        var subtotal = <?= (float) $itemsSubtotal ?>;
        var currentDeliveryFee = <?= (float) $initialDelivery['fee'] ?>;
        var currentDiscount = <?= (float) $discountAmount ?>;

        function updateDeliveryFee() {
            var townSelect = document.getElementById('delivery_town');
            if (!townSelect || townSelect.selectedIndex < 0) return;
            var selectedOpt = townSelect.options[townSelect.selectedIndex];
            if (!selectedOpt) return;

            var rawFee = selectedOpt.getAttribute('data-fee');
            var baseFee = parseFloat(rawFee !== null && rawFee !== '' ? rawFee : '150');
            if (isNaN(baseFee)) baseFee = 150.0;

            var rawThreshold = selectedOpt.getAttribute('data-threshold');
            var threshold = parseFloat(rawThreshold !== null && rawThreshold !== '' ? rawThreshold : '3000');
            if (isNaN(threshold)) threshold = 3000.0;

            var townName = selectedOpt.getAttribute('data-town') || selectedOpt.value;
            var zoneName = selectedOpt.getAttribute('data-zone') || '';

            var feeSpan = document.getElementById('summary_delivery');
            var townSpan = document.getElementById('summary_town_label');

            if (threshold > 0 && subtotal >= threshold) {
                currentDeliveryFee = 0.0;
                if (feeSpan) {
                    feeSpan.textContent = 'FREE (Promo)';
                    feeSpan.style.color = '#15803d';
                }
            } else {
                currentDeliveryFee = baseFee;
                if (feeSpan) {
                    feeSpan.textContent = 'KES ' + baseFee.toFixed(2);
                    feeSpan.style.color = 'inherit';
                }
            }

            if (townSpan) {
                townSpan.textContent = zoneName ? (townName + ' - ' + zoneName) : townName;
            }
            recalculateGrandTotal();
        }

        function recalculateGrandTotal() {
            var fee = isNaN(currentDeliveryFee) ? 0 : currentDeliveryFee;
            var disc = isNaN(currentDiscount) ? 0 : currentDiscount;
            var sub = isNaN(subtotal) ? 0 : subtotal;
            var grand = Math.max(0, (sub - disc)) + fee;
            var grandSpan = document.getElementById('summary_grand_total');
            if (grandSpan) {
                grandSpan.textContent = 'KES ' + grand.toFixed(2);
            }
        }

        function applyCouponCode() {
            var code = document.getElementById('coupon_input').value.trim().toUpperCase();
            var feedback = document.getElementById('coupon_feedback');
            var hiddenCode = document.getElementById('hidden_coupon_code');

            if (!code) {
                feedback.textContent = 'Please enter a coupon code.';
                feedback.style.color = '#ef4444';
                return;
            }

            hiddenCode.value = code;
            feedback.textContent = 'Coupon ' + code + ' applied for order submission!';
            feedback.style.color = '#15803d';
        }

        function applySavedAddress(sel) {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;

            var town = (opt.getAttribute('data-town') || '').trim();
            var addr = (opt.getAttribute('data-addr') || '').trim();
            var phone = (opt.getAttribute('data-phone') || '').trim();

            if (addr && document.getElementById('delivery_address')) {
                document.getElementById('delivery_address').value = addr;
            }
            if (phone && document.getElementById('mpesa_phone')) {
                document.getElementById('mpesa_phone').value = phone;
            }

            var townSelect = document.getElementById('delivery_town');
            if (townSelect && town) {
                for (var i = 0; i < townSelect.options.length; i++) {
                    var val = townSelect.options[i].value;
                    var tAttr = townSelect.options[i].getAttribute('data-town') || '';
                    if (val.toLowerCase() === town.toLowerCase() || tAttr.toLowerCase() === town.toLowerCase()) {
                        townSelect.selectedIndex = i;
                        break;
                    }
                }
            }
            updateDeliveryFee();
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            togglePaymentFields();
            updateDeliveryFee();
        });
        </script>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
