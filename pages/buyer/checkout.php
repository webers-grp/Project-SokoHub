<?php
/**
 * SokoHub Order Checkout (Atomic MySQL Transaction)
 * Creates order, order_items, delivery dispatch record, and decrements product stock.
 */
require_once __DIR__ . '/../../includes/auth.php';
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
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['delivery_address'] = trim((string) ($_POST['delivery_address'] ?? ''));
        $old['delivery_town'] = trim((string) ($_POST['delivery_town'] ?? ''));
        $old['payment_method'] = (string) ($_POST['payment_method'] ?? 'M-Pesa');
        $old['notes'] = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($old['delivery_address']) < 5) {
            $errors[] = 'Please provide a clear delivery address (at least 5 characters).';
        }
        if (mb_strlen($old['delivery_town']) < 2) {
            $errors[] = 'Please provide your delivery town.';
        }
        if (!in_array($old['payment_method'], ['M-Pesa', 'Cash on Delivery', 'Bank Card'], true)) {
            $errors[] = 'Select a valid payment method.';
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
                $payStatus = ($old['payment_method'] === 'Cash on Delivery') ? 'unpaid' : 'paid';

                $ordStmt = $pdo->prepare(
                    "INSERT INTO orders
                     (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, notes)
                     VALUES (:code, :buyer, :total, :pmethod, :pstatus, 'pending', :addr, :town, :notes)"
                );
                $ordStmt->execute([
                    ':code'    => $orderCode,
                    ':buyer'   => (int) $user['id'],
                    ':total'   => $grandTotal,
                    ':pmethod' => $old['payment_method'],
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
                }

                $delStmt = $pdo->prepare(
                    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
                     VALUES (:oid, 'Kutus Market', :dropoff, :fee, 'pending', 'Awaiting rider claim')"
                );
                $delStmt->execute([
                    ':oid'     => $orderId,
                    ':dropoff' => $old['delivery_town'] . ' - ' . $old['delivery_address'],
                    ':fee'     => $deliveryFee,
                ]);

                $pdo->commit();
                $_SESSION['cart'] = [];
                set_flash('success', 'Order ' . $orderCode . ' placed successfully! A delivery dispatch request has been created.');
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
            <p class="muted">Confirm delivery location and payment preference.</p>
        </div>
        <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="btn btn-outline">
            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
            <span>Back to Cart</span>
        </a>
    </div>

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
                    <label for="delivery_town">Town / Area</label>
                    <input type="text" id="delivery_town" name="delivery_town" class="form-control" value="<?= e($old['delivery_town']) ?>" required data-minlength="2" placeholder="e.g. Kutus, Kerugoya, Sagana">
                </div>

                <div class="form-group">
                    <label for="delivery_address">Specific Drop-Off Address</label>
                    <input type="text" id="delivery_address" name="delivery_address" class="form-control" value="<?= e($old['delivery_address']) ?>" required data-minlength="5" placeholder="e.g. Kirinyaga University Gate B, Hostel 2">
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method" class="form-control" required>
                        <option value="M-Pesa" <?= $old['payment_method'] === 'M-Pesa' ? 'selected' : '' ?>>M-Pesa Mobile Money</option>
                        <option value="Cash on Delivery" <?= $old['payment_method'] === 'Cash on Delivery' ? 'selected' : '' ?>>Cash on Delivery</option>
                        <option value="Bank Card" <?= $old['payment_method'] === 'Bank Card' ? 'selected' : '' ?>>Bank Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Rider Instructions (Optional)</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="Call on arrival, landmark, etc."><?= e($old['notes']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-amber btn-block">
                    <?= icon('check-circle', 'lucide-sm', 'white') ?>
                    <span>Confirm &amp; Place Order</span>
                </button>
            </form>
        </div>

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
                <span>Delivery Fee (Payable to Rider)</span>
                <span><?= format_ksh($deliveryFee) ?></span>
            </p>
            <p style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:1.25rem; font-weight:800; color:var(--green-dark);">
                <span>Order Total</span>
                <span><?= format_ksh($itemsSubtotal) ?></span>
            </p>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
