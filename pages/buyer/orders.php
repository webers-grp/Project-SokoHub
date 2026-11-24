<?php
/**
 * SokoHub Orders Management & Live Dispatch Tracking
 * Features:
 * - Real-time Visual 5-Stage Order Progress Timeline
 * - Rider Contact & Kenyan Vehicle Number Plate Display
 * - M-Pesa Pay-on-Delivery Status & Receipt Code
 * - Order Cancellation with Atomic Stock Restoration
 * Authored by Kennedy Kalawa.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payments.php';
init_session();
$user = require_login();
$pdo = get_db_connection();
Payments::ensureSchema();

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

// Base SQL query joining order, delivery, assigned rider, vehicle plate, and digital payment
$sqlBase = '
    SELECT o.*, u.full_name AS buyer_name, u.phone AS buyer_phone,
           d.id AS delivery_id, d.delivery_status, d.tracking_notes, d.delivery_fee,
           d.delivery_person_id, d.assigned_at, d.delivered_at, d.pickup_town, d.dropoff_address,
           r.full_name AS rider_name, r.phone AS rider_phone,
           dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model,
           p.id AS payment_id, p.status AS payment_prompt_status, p.buyer_phone AS payment_phone,
           p.amount AS payment_amount, p.mpesa_checkout_id, p.prompted_at
    FROM orders o
    INNER JOIN users u ON u.id = o.buyer_id
    LEFT JOIN deliveries d ON d.order_id = o.id
    LEFT JOIN users r ON r.id = d.delivery_person_id
    LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
    LEFT JOIN payments p ON p.id = d.digital_payment_id
';

if ($role === 'buyer') {
    $stmt = $pdo->prepare($sqlBase . ' WHERE o.buyer_id = :uid ORDER BY o.created_at DESC');
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'seller') {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT o.*, u.full_name AS buyer_name, u.phone AS buyer_phone,
                d.id AS delivery_id, d.delivery_status, d.tracking_notes, d.delivery_fee,
                d.delivery_person_id, d.assigned_at, d.delivered_at, d.pickup_town, d.dropoff_address,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model,
                p.id AS payment_id, p.status AS payment_prompt_status, p.buyer_phone AS payment_phone,
                p.amount AS payment_amount, p.mpesa_checkout_id, p.prompted_at
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         LEFT JOIN payments p ON p.id = d.digital_payment_id
         WHERE oi.seller_id = :uid
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $orders = $pdo->query($sqlBase . ' ORDER BY o.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
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

$pageTitle = 'SokoHub | Orders & Live Delivery Tracking';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Orders &amp; Live Delivery Tracking</h1>
            <p class="muted">Track real-time rider dispatch, Kenyan vehicle number plates, and M-Pesa Pay-on-Delivery states.</p>
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
        <!-- Desktop Table View (>= 769px) -->
        <div class="desktop-orders-view card">
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
                        $isActive = !in_array($ord['order_status'], ['delivered', 'cancelled'], true) && ($ord['delivery_status'] ?? '') !== 'delivered';
                        ?>
                        <tr style="border-bottom: <?= $isActive ? 'none' : '1px solid #e2e8f0' ?>;">
                            <td>
                                <strong><?= e($ord['order_code']) ?></strong>
                                <div class="small muted"><?= e(date('d M Y', strtotime($ord['created_at']))) ?></div>
                                <button type="button" id="timeline-btn-<?= (int) $ord['id'] ?>" class="btn btn-outline btn-sm" onclick="toggleTimeline(<?= (int) $ord['id'] ?>)" style="margin-top: 0.35rem; font-size: 0.72rem; padding: 0.2rem 0.45rem;">
                                    <?= icon('navigation', 'lucide-xs') ?>
                                    <span><?= $isActive ? 'Hide Tracker' : 'View Tracker' ?></span>
                                </button>
                            </td>
                            <td>
                                <strong><?= e($ord['buyer_name']) ?></strong>
                                <div class="small muted"><?= e($ord['delivery_town']) ?> &mdash; <?= e($ord['delivery_address']) ?></div>
                                <?php if (!empty($ord['rider_name'])): ?>
                                    <div class="small" style="margin-top: 0.2rem; color: #0284c7;">
                                        <?= icon('user-check', 'lucide-xs') ?> Rider: <?= e($ord['rider_name']) ?>
                                    </div>
                                <?php endif; ?>
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
                                <?php if (!empty($ord['mpesa_receipt'])): ?>
                                    <div class="small" style="font-family: monospace; font-weight: 600; color: #065f46; margin-top: 0.15rem;">
                                        Ref: <?= e($ord['mpesa_receipt']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span></td>
                            <td>
                                <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                                <?php if (!empty($ord['vehicle_plate_number'])): ?>
                                    <div style="margin-top: 0.25rem;">
                                        <span class="badge badge-outline" style="font-family: monospace; font-size: 0.72rem;">
                                            <?= icon('car', 'lucide-xs') ?> <?= e($ord['vehicle_plate_number']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($ord['tracking_notes'])): ?>
                                    <div class="small muted" style="margin-top: 0.2rem;"><?= e($ord['tracking_notes']) ?></div>
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

                        <!-- Interactive Visual Order Progress Timeline Row (Desktop) -->
                        <tr id="timeline-row-<?= (int) $ord['id'] ?>" style="display: <?= $isActive ? 'table-row' : 'none' ?>;">
                            <td colspan="8" style="padding: 0 0.75rem 1rem 0.75rem; border-top: none; background: rgba(0,0,0,0.01);">
                                <?= render_order_progress_timeline($ord) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile Card View (<= 768px) -->
        <div class="mobile-orders-view">
            <?php foreach ($orders as $ord): ?>
                <?php
                $oBadge = get_status_badge($ord['order_status']);
                $dBadge = get_status_badge($ord['delivery_status'] ?? 'pending');
                $pBadge = get_status_badge($ord['payment_status']);
                $lineItems = $itemsByOrder[(int) $ord['id']] ?? [];
                $isActive = !in_array($ord['order_status'], ['delivered', 'cancelled'], true) && ($ord['delivery_status'] ?? '') !== 'delivered';
                ?>
                <div class="mobile-order-card">
                    <div class="mobile-order-card-head">
                        <div>
                            <strong style="font-size: 1.05rem;">#<?= e($ord['order_code']) ?></strong>
                            <div class="small muted"><?= e(date('d M Y, H:i', strtotime($ord['created_at']))) ?></div>
                        </div>
                        <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end;">
                            <span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span>
                            <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                        </div>
                    </div>

                    <div class="mobile-order-card-meta">
                        <div>
                            <span class="muted small" style="display: block;">Dropoff Location:</span>
                            <strong><?= e($ord['delivery_town']) ?></strong>
                            <div class="small muted"><?= e($ord['delivery_address']) ?></div>
                        </div>
                        <div>
                            <span class="muted small" style="display: block;">Total &amp; Payment:</span>
                            <strong style="color: #16a34a;"><?= format_ksh($ord['total_amount']) ?></strong>
                            <div style="margin-top: 0.2rem;">
                                <span class="badge <?= e($pBadge['class']) ?>" style="font-size: 0.75rem;"><?= e($pBadge['label']) ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($ord['rider_name'])): ?>
                        <div style="background: rgba(2, 132, 199, 0.08); border: 1px solid rgba(2, 132, 199, 0.2); border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.4rem;">
                            <div>
                                <span style="color: #0284c7; font-weight: 600;"><?= icon('user-check', 'lucide-xs') ?> Rider: <?= e($ord['rider_name']) ?></span>
                                <?php if (!empty($ord['vehicle_plate_number'])): ?>
                                    <span class="badge badge-outline" style="font-family: monospace; font-size: 0.75rem; margin-left: 0.3rem;">
                                        <?= e($ord['vehicle_plate_number']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ord['rider_phone'])): ?>
                                <a href="tel:<?= e($ord['rider_phone']) ?>" class="btn btn-outline btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                                    <?= icon('phone', 'lucide-xs') ?> Call
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mobile-order-card-items">
                        <span class="muted small" style="font-weight: 600;">Items Ordered:</span>
                        <?php foreach ($lineItems as $li): ?>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?= e($li['product_name']) ?></span>
                                <span class="muted">&times; <?= (int) $li['quantity'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tracker Toggle Button -->
                    <button type="button" id="mobile-timeline-btn-<?= (int) $ord['id'] ?>" class="btn btn-outline btn-sm" onclick="toggleTimeline(<?= (int) $ord['id'] ?>)" style="width: 100%; justify-content: center; padding: 0.5rem;">
                        <?= icon('navigation', 'lucide-xs') ?>
                        <span><?= $isActive ? 'Hide Dispatch Tracker' : 'View Dispatch Tracker' ?></span>
                    </button>

                    <!-- Interactive Visual Order Progress Timeline (Mobile) -->
                    <div id="mobile-timeline-<?= (int) $ord['id'] ?>" style="display: <?= $isActive ? 'block' : 'none' ?>;">
                        <?= render_order_progress_timeline($ord) ?>
                    </div>

                    <div class="mobile-order-card-actions">
                        <?php if ($ord['order_status'] === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                            <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;" data-confirm="Cancel order <?= e($ord['order_code']) ?>?">Cancel Order</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($role, ['seller', 'admin'], true) && $ord['order_status'] !== 'cancelled'): ?>
                            <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="display: flex; gap: 0.4rem; align-items: center;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                <select name="order_status" class="form-control" style="flex: 1; padding: 0.35rem 0.5rem; font-size: 0.85rem;">
                                    <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline btn-sm">Update Status</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleTimeline(orderId) {
    // Desktop row toggle
    const row = document.getElementById('timeline-row-' + orderId);
    const btn = document.getElementById('timeline-btn-' + orderId);
    if (row) {
        const isHidden = (row.style.display === 'none');
        row.style.display = isHidden ? 'table-row' : 'none';
        if (btn) {
            const span = btn.querySelector('span');
            if (span) span.textContent = isHidden ? 'Hide Tracker' : 'View Tracker';
        }
    }
    // Mobile card toggle
    const mRow = document.getElementById('mobile-timeline-' + orderId);
    const mBtn = document.getElementById('mobile-timeline-btn-' + orderId);
    if (mRow) {
        const isHidden = (mRow.style.display === 'none');
        mRow.style.display = isHidden ? 'block' : 'none';
        if (mBtn) {
            const span = mBtn.querySelector('span');
            if (span) span.textContent = isHidden ? 'Hide Dispatch Tracker' : 'View Dispatch Tracker';
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
