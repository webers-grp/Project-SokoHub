<?php
/**
 * SokoHub Order Checkout (Atomic MySQL Transaction)
 * Pure Digital Pay-on-Delivery (M-Pesa STK Prompt upon arrival). Strictly No Cash.
 * Creates order, order_items, delivery dispatch record, and decrements product stock.
 * Authored by Kennedy Kalawa.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/payments.php';
init_session();
$user = require_login();
$pdo = get_db_connection();
Payments::ensureSchema();

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
    'mpesa_phone'      => $user['phone'] ?? '',
    'payment_method'   => 'Digital Pay-on-Delivery (M-Pesa)',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['delivery_address'] = trim((string) ($_POST['delivery_address'] ?? ''));
        $old['delivery_town'] = trim((string) ($_POST['delivery_town'] ?? ''));
        $old['mpesa_phone'] = trim((string) ($_POST['mpesa_phone'] ?? ''));
        $old['payment_method'] = 'Digital Pay-on-Delivery (M-Pesa)';
        $old['notes'] = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($old['delivery_address']) < 5) {
            $errors[] = 'Please provide a clear delivery address (at least 5 characters).';
        }
        if (mb_strlen($old['delivery_town']) < 2) {
            $errors[] = 'Please provide your delivery town.';
        }

        $cleanMpesa = Kyc::cleanMpesaPhone($old['mpesa_phone']);
        if (!$cleanMpesa) {
            $errors[] = 'Please provide a valid Safaricom phone number (e.g. 0712345678 or 0112345678) to receive the M-Pesa STK payment prompt on delivery.';
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
                        throw new RuntimeException('Insufficient stock for "' . $it['name'] . '".');
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

                // Step 4: Pure Digital Pay-on-Delivery (Strictly zero upfront payment)
                $payStatus = 'unpaid';

                $ordStmt = $pdo->prepare(
                    "INSERT INTO orders
                     (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, notes)
                     VALUES (:code, :buyer, :total, :pmethod, :pstatus, 'pending', :addr, :town, :notes)"
                );
                $ordStmt->execute([
                    ':code'    => $orderCode,
                    ':buyer'   => (int) $user['id'],
                    ':total'   => $grandTotal,
                    ':pmethod' => 'Digital Pay-on-Delivery (M-Pesa)',
                    ':pstatus' => $payStatus,
                    ':addr'    => $old['delivery_address'],
                    ':town'    => $old['delivery_town'],
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
                    Inventory::checkProductLowStock((int) $vi['id'], 'Order: ' . $orderCode);
                }

                // Initial delivery tracking note reflecting merchant-driven assignment
                $delStmt = $pdo->prepare(
                    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
                     VALUES (:oid, 'Kutus Market', :dropoff, :fee, 'pending', 'Awaiting merchant rider assignment')"
                );
                $delStmt->execute([
                    ':oid'     => $orderId,
                    ':dropoff' => $old['delivery_town'] . ' - ' . $old['delivery_address'],
                    ':fee'     => $deliveryFee,
                ]);
                $delId = (int) $pdo->lastInsertId();

                // Create initial pending payment record for STK push dispatch
                $payStmt = $pdo->prepare("
                    INSERT INTO payments (order_id, delivery_id, buyer_phone, amount, payment_method, status)
                    VALUES (:oid, :did, :phone, :amt, 'Digital Pay-on-Delivery (M-Pesa)', 'pending')
                ");
                $payStmt->execute([
                    ':oid'   => $orderId,
                    ':did'   => $delId,
                    ':phone' => $cleanMpesa,
                    ':amt'   => $grandTotal + $deliveryFee,
                ]);

                // Update user's phone if missing or changed
                if (!empty($cleanMpesa) && empty($user['phone'])) {
                    $pdo->prepare('UPDATE users SET phone = :phone WHERE id = :uid')->execute([
                        ':phone' => $cleanMpesa,
                        ':uid'   => (int) $user['id'],
                    ]);
                    $_SESSION['user']['phone'] = $cleanMpesa;
                }

                $pdo->commit();
                $_SESSION['cart'] = [];
                set_flash('success', 'Order ' . $orderCode . ' placed successfully! Pure Digital Pay-on-Delivery: Your assigned delivery rider will prompt phone ' . $cleanMpesa . ' via Safaricom M-Pesa STK push upon arrival.');
                redirect('pages/buyer/orders.php');
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
            <p class="muted">Confirm delivery location and Safaricom phone number for Pay-on-Delivery M-Pesa prompt.</p>
        </div>
        <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="btn btn-outline">Back to Cart</a>
    </div>

    <div class="split-grid" style="align-items: start;">
        <div class="card">
            <h2 style="color: var(--green-dark); margin-bottom: 1rem;">Delivery &amp; Payment Details</h2>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Digital Pay-on-Delivery Notice -->
            <div style="background: rgba(16, 185, 129, 0.08); border-left: 5px solid #10b981; padding: 0.9rem 1.1rem; border-radius: 6px; margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                    <span class="badge badge-success" style="font-size: 0.78rem;">
                        <?= icon('shield-check', 'lucide-xs') ?> Strictly Cashless
                    </span>
                    <strong style="color: #065f46; font-size: 0.95rem;">Pure Digital Pay-on-Delivery</strong>
                </div>
                <p class="small muted" style="margin: 0; line-height: 1.5;">
                    <strong>Zero upfront payment.</strong> SokoHub operates on a strictly cashless digital delivery model. When your rider arrives with your package, they will trigger an automated Safaricom M-Pesa STK Push directly to your phone. Enter your PIN on arrival to release the goods. No cash will be accepted.
                </p>
            </div>

            <form action="<?= e(url('pages/buyer/checkout.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="delivery_town">Town / Delivery Area *</label>
                    <input type="text" id="delivery_town" name="delivery_town" class="form-control" value="<?= e($old['delivery_town']) ?>" required data-minlength="2" placeholder="e.g. Kutus, Kerugoya, Sagana">
                </div>

                <div class="form-group">
                    <label for="delivery_address">Specific Drop-Off Address *</label>
                    <input type="text" id="delivery_address" name="delivery_address" class="form-control" value="<?= e($old['delivery_address']) ?>" required data-minlength="5" placeholder="e.g. Kirinyaga University Gate B, Hostel 2, Room 14">
                </div>

                <div class="form-group">
                    <label for="mpesa_phone">Safaricom Phone for M-Pesa STK Push Prompt *</label>
                    <input type="tel" id="mpesa_phone" name="mpesa_phone" class="form-control" value="<?= e($old['mpesa_phone']) ?>" required placeholder="e.g. 0712345678 or 0112345678">
                    <small class="muted">Your phone will receive an automated STK Push prompt to enter your PIN when the rider arrives.</small>
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <div style="padding: 0.65rem 0.85rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-surface); display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <?= icon('smartphone', 'lucide-xs', 'emerald') ?>
                            <strong>Digital Pay-on-Delivery (Safaricom M-Pesa)</strong>
                        </div>
                        <span class="badge badge-success" style="font-size: 0.72rem;">On Arrival</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Rider Instructions (Optional)</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Call on arrival, landmark, gate colour..."><?= e($old['notes']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-amber btn-block" style="font-size: 1.05rem; padding: 0.75rem;">
                    <?= icon('check-circle', 'lucide-xs') ?>
                    <span>Confirm Order (Pay on Delivery)</span>
                </button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 1rem;">Order Summary</h2>
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
                <span>Delivery Fee (Kirinyaga Local Dispatch)</span>
                <span><?= format_ksh($deliveryFee) ?></span>
            </p>
            <p style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:1.25rem; font-weight:800; color:var(--green-dark);">
                <span>Total Payable on Delivery</span>
                <span><?= format_ksh($itemsSubtotal + $deliveryFee) ?></span>
            </p>
            <div class="small muted" style="margin-top: 0.5rem; text-align: center; border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                No payment is deducted now. STK push will be initiated upon rider arrival.
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
