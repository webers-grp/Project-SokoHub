<?php
/**
 * SokoHub Orders Management (View, Update Status, Cancel with Stock Restore)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();
$user = require_login();
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('pages/buyer/orders.php');
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $ord = $stmt->fetch();

        if (!$ord || ($role !== 'admin' && (int) $ord['buyer_id'] !== $userId)) {
            set_flash('error', 'Order not found or unauthorized.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['order_status'] !== 'pending') {
            set_flash('warning', 'Only pending orders can be cancelled.');
            redirect('pages/buyer/orders.php');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = :id")->execute([':id' => $orderId]);
            $pdo->prepare("UPDATE deliveries SET delivery_status = 'failed', tracking_notes = 'Cancelled by buyer' WHERE order_id = :id")->execute([':id' => $orderId]);

            $itemsStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :id');
            $itemsStmt->execute([':id' => $orderId]);
            $restock = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid');
            foreach ($itemsStmt->fetchAll() as $it) {
                $restock->execute([':qty' => (int) $it['quantity'], ':pid' => (int) $it['product_id']]);
            }

            $pdo->commit();
            set_flash('info', 'Order ' . $ord['order_code'] . ' cancelled and product stock restored.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', 'Unable to cancel order.');
        }
        redirect('pages/buyer/orders.php');
    }

    if ($action === 'update_status' && in_array($role, ['seller', 'admin'], true)) {
        $newStatus = (string) ($_POST['order_status'] ?? '');
        if (!in_array($newStatus, ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'], true)) {
            set_flash('error', 'Invalid order status.');
            redirect('pages/buyer/orders.php');
        }

        if ($role === 'seller') {
            $ownCheck = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND seller_id = :sid');
            $ownCheck->execute([':oid' => $orderId, ':sid' => $userId]);
            if ((int) $ownCheck->fetchColumn() === 0) {
                set_flash('error', 'Unauthorized order update.');
                redirect('pages/buyer/orders.php');
            }
        }

        $pdo->prepare('UPDATE orders SET order_status = :st WHERE id = :id')->execute([
            ':st' => $newStatus,
            ':id' => $orderId,
        ]);
        set_flash('success', 'Order status updated to ' . ucfirst($newStatus) . '.');
        redirect('pages/buyer/orders.php');
    }
}

if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes
         FROM orders o
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         WHERE o.buyer_id = :uid
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} elseif ($role === 'seller') {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         WHERE oi.seller_id = :uid
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} else {
    $orders = $pdo->query(
        'SELECT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes
         FROM orders o
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         ORDER BY o.created_at DESC'
    )->fetchAll();
}

// Preload line items per order
$itemsByOrder = [];
if (!empty($orders)) {
    $oIds = array_map(fn($r) => (int) $r['id'], $orders);
    $ph = implode(',', array_fill(0, count($oIds), '?'));
    $itStmt = $pdo->prepare(
        "SELECT oi.*, p.name AS product_name
         FROM order_items oi
         INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ({$ph})"
    );
    $itStmt->execute($oIds);
    foreach ($itStmt->fetchAll() as $it) {
        $itemsByOrder[(int) $it['order_id']][] = $it;
    }
}

$pageTitle = 'SokoHub | Orders';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Orders &amp; Fulfillment</h1>
            <p class="muted">Track marketplace purchases, payment states, and seller processing statuses.</p>
        </div>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
            <?= icon('shopping-bag', 'lucide-sm', 'white') ?>
            <span>Shop More Products</span>
        </a>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card">
            <p class="muted">No orders found yet.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Buyer &amp; Address</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Order Status</th>
                            <th>Delivery</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $ord): ?>
                        <?php
                        $oBadge = get_status_badge($ord['order_status']);
                        $dBadge = get_status_badge($ord['delivery_status'] ?? 'pending');
                        $pBadge = get_status_badge($ord['payment_status']);
                        $lineItems = $itemsByOrder[(int) $ord['id']] ?? [];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($ord['order_code']) ?></strong>
                                <div class="small muted"><?= e(date('d M Y', strtotime($ord['created_at']))) ?></div>
                            </td>
                            <td>
                                <strong><?= e($ord['buyer_name']) ?></strong>
                                <div class="small muted"><?= e($ord['delivery_town']) ?> &mdash; <?= e($ord['delivery_address']) ?></div>
                            </td>
                            <td>
                                <?php foreach ($lineItems as $li): ?>
                                    <div class="small"><?= e($li['product_name']) ?> &times; <?= (int) $li['quantity'] ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td><strong><?= format_ksh($ord['total_amount']) ?></strong></td>
                            <td>
                                <div><?= e($ord['payment_method']) ?></div>
                                <span class="badge <?= e($pBadge['class']) ?>"><?= e($pBadge['label']) ?></span>
                            </td>
                            <td><span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span></td>
                            <td>
                                <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                                <?php if (!empty($ord['tracking_notes'])): ?>
                                    <div class="small muted"><?= e($ord['tracking_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ord['order_status'] === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin-bottom:0.4rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Cancel order <?= e($ord['order_code']) ?>?">
                                            <?= icon('x-circle', 'lucide-xs', 'white') ?>
                                            <span>Cancel</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($role, ['seller', 'admin'], true) && $ord['order_status'] !== 'cancelled'): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="display:flex; gap:0.3rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <select name="order_status" class="form-control" style="padding:0.25rem 0.4rem; font-size:0.82rem; width:auto;">
                                            <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline btn-sm">
                                            <?= icon('check', 'lucide-xs', 'emerald') ?>
                                            <span>Set</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
