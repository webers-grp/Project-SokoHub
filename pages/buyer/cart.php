<?php
/**
 * SokoHub Session Shopping Cart
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

$pdo = get_db_connection();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('pages/buyer/cart.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

    if ($action === 'add' && $productId > 0) {
        $stmt = $pdo->prepare("SELECT id, name, stock_qty FROM products WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $productId]);
        $prod = $stmt->fetch();

        if (!$prod || (int) $prod['stock_qty'] <= 0) {
            set_flash('error', 'Sorry, that product is out of stock.');
        } else {
            $newQty = min((int) $prod['stock_qty'], (int) ($_SESSION['cart'][$productId] ?? 0) + $quantity);
            $_SESSION['cart'][$productId] = $newQty;
            set_flash('success', 'Added "' . $prod['name'] . '" to your cart.');
        }
        redirect('pages/buyer/cart.php');
    }

    if ($action === 'update' && $productId > 0) {
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
        } else {
            $stmt = $pdo->prepare("SELECT stock_qty FROM products WHERE id = :id AND status = 'active' LIMIT 1");
            $stmt->execute([':id' => $productId]);
            $stock = (int) $stmt->fetchColumn();
            $_SESSION['cart'][$productId] = min(max(1, $stock), $quantity);
        }
        set_flash('info', 'Shopping cart updated.');
        redirect('pages/buyer/cart.php');
    }

    if ($action === 'remove' && $productId > 0) {
        unset($_SESSION['cart'][$productId]);
        set_flash('info', 'Item removed from cart.');
        redirect('pages/buyer/cart.php');
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        set_flash('info', 'Shopping cart cleared.');
        redirect('pages/buyer/cart.php');
    }
}

$cartItems = [];
$subtotal = 0.0;
$deliveryFee = 150.00;

if (!empty($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.price, p.stock_qty, p.image_path, COALESCE(sp.business_name, u.full_name) AS seller_name
         FROM products p
         INNER JOIN users u ON u.id = p.seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE p.id IN ({$placeholders}) AND p.status = 'active'"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $qty = min((int) $row['stock_qty'], max(1, (int) ($_SESSION['cart'][$pid] ?? 1)));
        if ($qty <= 0) {
            unset($_SESSION['cart'][$pid]);
            continue;
        }
        $lineTotal = (float) $row['price'] * $qty;
        $subtotal += $lineTotal;
        $row['cart_qty'] = $qty;
        $row['line_total'] = $lineTotal;
        $cartItems[] = $row;
    }
}

$pageTitle = 'SokoHub | Shopping Cart';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Your Shopping Cart</h1>
            <p class="muted">Review items before proceeding to secure checkout.</p>
        </div>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-outline">
            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
            <span>Continue Shopping</span>
        </a>
    </div>

    <?php if (empty($cartItems)): ?>
        <div class="card" style="text-align: center; padding: 3rem 1.5rem;">
            <div class="stat-icon-wrap stat-icon-amber" style="width: 52px; height: 52px; border-radius: 50%; margin: 0 auto 0.75rem;">
                <?= icon('shopping-bag', 'lucide-lg') ?>
            </div>
            <h2>Your cart is currently empty</h2>
            <p class="muted" style="margin: 0.6rem 0 1.4rem;">Explore fresh food, cooking gas, electronics, and clothing from local Kirinyaga vendors.</p>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
                <?= icon('shopping-bag', 'lucide-sm', 'white') ?>
                <span>Browse Marketplace</span>
            </a>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Seller</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Line Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cartItems as $item): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:0.75rem;">
                                    <img src="<?= e(url($item['image_path'])) ?>" alt="" class="table-thumb">
                                    <strong><?= e($item['name']) ?></strong>
                                </div>
                            </td>
                            <td><?= e($item['seller_name']) ?></td>
                            <td><?= format_ksh($item['price']) ?></td>
                            <td>
                                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" style="display:flex; gap:0.4rem; align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                    <input type="number" name="quantity" class="form-control" value="<?= (int) $item['cart_qty'] ?>" min="1" max="<?= (int) $item['stock_qty'] ?>" style="width:76px;">
                                    <button type="submit" class="btn btn-outline btn-sm">
                                        <?= icon('refresh-cw', 'lucide-xs', 'blue') ?>
                                        <span>Update</span>
                                    </button>
                                </form>
                            </td>
                            <td><strong><?= format_ksh($item['line_total']) ?></strong></td>
                            <td>
                                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <?= icon('trash-2', 'lucide-xs', 'white') ?>
                                        <span>Remove</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1.5rem; margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid var(--border-color);">
                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="Clear all items from your cart?">
                        <?= icon('trash', 'lucide-xs', 'rose') ?>
                        <span>Clear Cart</span>
                    </button>
                </form>

                <div style="text-align:right;">
                    <p class="muted">Items Subtotal: <strong><?= format_ksh($subtotal) ?></strong></p>
                    <p class="muted">Standard Local Delivery Fee: <strong><?= format_ksh($deliveryFee) ?></strong></p>
                    <p style="font-size:1.3rem; margin:0.45rem 0 0.9rem;">
                        Estimated Total: <strong style="color:var(--green-dark);"><?= format_ksh($subtotal + $deliveryFee) ?></strong>
                    </p>
                    <a href="<?= e(url('pages/buyer/checkout.php')) ?>" class="btn btn-amber">
                        <span>Proceed to Checkout</span>
                        <?= icon('arrow-right', 'lucide-xs', 'white') ?>
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
