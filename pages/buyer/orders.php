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
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/mpesa.php';
require_once __DIR__ . '/../../includes/payments.php';
init_session();
$user = require_login();
$pdo = get_db_connection();
ensure_order_schema_tables($pdo);
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

    // Action 1: Cancel Order with Atomic Stock Restoration
    if ($action === 'cancel') {
        $reason = trim((string) ($_POST['cancel_reason'] ?? 'Cancelled by user'));
        $notes = trim((string) ($_POST['cancel_notes'] ?? ''));
        if ($notes !== '') {
            $reason .= ' - ' . $notes;
        }

        $res = cancel_order_with_stock_restore($pdo, $orderId, $userId, $role, $reason);
        if ($res['ok']) {
            set_flash('info', $res['message']);
        } else {
            set_flash('error', $res['message']);
        }
        redirect('pages/buyer/orders.php');
    }

    // Action 2: Trigger M-Pesa STK Push for Unpaid Order
    if ($action === 'pay_mpesa') {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
            || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

        $phoneInput = trim((string) ($_POST['mpesa_phone'] ?? $user['phone'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ord || ($role === 'buyer' && (int) $ord['buyer_id'] !== $userId)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Order not found or unauthorized.']);
                exit;
            }
            set_flash('error', 'Order not found or unauthorized.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['order_status'] === 'cancelled') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Cannot pay for a cancelled order.']);
                exit;
            }
            set_flash('warning', 'Cannot pay for a cancelled order.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['payment_status'] === 'paid') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'This order is already marked as paid.']);
                exit;
            }
            set_flash('info', 'This order is already marked as paid.');
            redirect('pages/buyer/orders.php');
        }

        $stk = mpesa_stk_push($pdo, $orderId, $phoneInput, (float) $ord['total_amount'], $ord['order_code']);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'                  => (bool) $stk['ok'],
                'simulated'           => !empty($stk['simulated']),
                'order_id'            => $orderId,
                'order_code'          => $ord['order_code'],
                'amount'              => (float) $ord['total_amount'],
                'formatted_amount'    => 'KES ' . number_format((float) $ord['total_amount'], 2),
                'checkout_request_id' => $stk['checkout_request_id'] ?? '',
                'phone'               => $stk['phone'] ?? $phoneInput,
                'message'             => $stk['message'] ?? ($stk['ok'] ? 'STK Push sent successfully.' : 'Failed to send STK push.')
            ]);
            exit;
        }

        if ($stk['ok']) {
            set_flash('success', 'STK Push sent to ' . $stk['phone'] . '. Please enter your M-Pesa PIN.');
            redirect('pages/buyer/orders.php?mpesa_prompt=1&order_id=' . $orderId . '&crid=' . urlencode($stk['checkout_request_id']));
        } else {
            set_flash('error', 'M-Pesa error: ' . $stk['message']);
            redirect('pages/buyer/orders.php');
        }
    }

    // Action 3: 1-Click Re-order past items
    if ($action === 'reorder') {
        $reRes = reorder_order_items($orderId);
        if ($reRes['ok']) {
            set_flash('success', $reRes['message']);
            redirect('pages/buyer/cart.php');
        } else {
            set_flash('error', $reRes['message']);
            redirect('pages/buyer/orders.php');
        }
    }

    // Action 4: Process Refund (Sellers & Admins)
    if ($action === 'process_refund' && in_array($role, ['seller', 'admin'], true)) {
        $refundId = (int) ($_POST['refund_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? 'complete');
        $reference = trim((string) ($_POST['provider_reference'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $res = process_order_refund($pdo, $refundId, $userId, $role, $decision, $reference, $notes);
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', $res['message']);
        }
        redirect('pages/buyer/orders.php');
    }

    // Action 5: Update Order Status
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

        if ($newStatus === 'cancelled') {
            $res = cancel_order_with_stock_restore($pdo, $orderId, $userId, $role, 'Cancelled by ' . ucfirst($role));
            if ($res['ok']) {
                set_flash('info', $res['message']);
            } else {
                set_flash('error', $res['message']);
            }
        } else {
            $pdo->prepare('UPDATE orders SET order_status = :st WHERE id = :id')->execute([
                ':st' => $newStatus,
                ':id' => $orderId,
            ]);
            set_flash('success', 'Order status updated to ' . ucfirst($newStatus) . '.');
        }
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

    // Preload refunds per order
    $refundsByOrder = [];
    try {
        $refStmt = $pdo->prepare("SELECT * FROM refunds WHERE order_id IN ({$ph}) ORDER BY id DESC");
        $refStmt->execute($oIds);
        foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $rf) {
            $refundsByOrder[(int) $rf['order_id']] = $rf;
        }
    } catch (Throwable $_) {}
}

// Prompt info for modal
$promptOrderId = (int) ($_GET['order_id'] ?? 0);
$promptCrid = trim((string) ($_GET['crid'] ?? ''));
$showMpesaModal = (!empty($_GET['mpesa_prompt']) || !empty($_GET['prompt_mpesa'])) && $promptOrderId > 0;
$promptOrder = null;
if ($showMpesaModal) {
    foreach ($orders as $o) {
        if ((int) $o['id'] === $promptOrderId) {
            $promptOrder = $o;
            break;
        }
    }
    if ($promptOrder === null) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id' . ($role === 'buyer' ? ' AND buyer_id = :uid' : '') . ' LIMIT 1');
        $params = [':id' => $promptOrderId];
        if ($role === 'buyer') {
            $params[':uid'] = $userId;
        }
        $stmt->execute($params);
        $promptOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
                                <!-- M-Pesa Pay Prompt (for unpaid orders) -->
                                <?php if ($ord['payment_status'] === 'unpaid' && $ord['order_status'] !== 'cancelled' && ($role === 'buyer' || $role === 'admin')): ?>
                                    <button type="button" class="btn btn-primary btn-sm btn-mpesa-pay" style="margin-bottom:0.35rem; width:100%;"
                                            data-order-id="<?= (int) $ord['id'] ?>"
                                            data-order-code="<?= e($ord['order_code']) ?>"
                                            data-amount="<?= number_format((float) $ord['total_amount'], 2) ?>"
                                            data-phone="<?= e($ord['payment_phone'] ?? $ord['buyer_phone'] ?? '') ?>">
                                        <?= icon('smartphone', 'lucide-xs', 'white') ?>
                                        <span>Pay M-Pesa</span>
                                    </button>
                                <?php endif; ?>

                                <!-- 1-Click Re-order past order (for buyer) -->
                                <?php if ($role === 'buyer' && in_array($ord['order_status'], ['delivered', 'confirmed'], true)): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin-bottom:0.35rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reorder">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;" title="Add items from this order back to your cart">
                                            <?= icon('rotate-cw', 'lucide-xs') ?>
                                            <span>Re-order</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Printable Receipt Voucher -->
                                <?php if ($ord['payment_status'] === 'paid' || !empty($ord['mpesa_receipt'])): ?>
                                    <button type="button" class="btn btn-outline btn-sm btn-receipt-view" style="margin-bottom:0.35rem; width:100%;"
                                            data-receipt='<?= htmlspecialchars(json_encode([
                                                'order_code' => $ord['order_code'],
                                                'date'       => date('d M Y, H:i', strtotime($ord['created_at'])),
                                                'buyer'      => $ord['buyer_name'],
                                                'phone'      => $ord['buyer_phone'] ?? '',
                                                'address'    => $ord['delivery_address'],
                                                'items'      => array_map(fn($it) => ['name' => $it['product_name'], 'qty' => (int) $it['quantity'], 'total' => format_ksh($it['line_total'])], $lineItems),
                                                'total'      => format_ksh($ord['total_amount']),
                                                'receipt'    => $ord['mpesa_receipt'] ?: 'Confirmed'
                                            ]), ENT_QUOTES, 'UTF-8') ?>'>
                                        <?= icon('file-text', 'lucide-xs') ?>
                                        <span>Receipt</span>
                                    </button>
                                <?php endif; ?>

                                <!-- Cancel Order with Stock Restoration -->
                                <?php if ($ord['order_status'] === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                                    <button type="button" class="btn btn-danger btn-sm btn-open-cancel" style="margin-bottom:0.35rem; width:100%;"
                                            data-order-id="<?= (int) $ord['id'] ?>"
                                            data-order-code="<?= e($ord['order_code']) ?>"
                                            data-paid="<?= $ord['payment_status'] === 'paid' ? '1' : '0' ?>"
                                            data-amount="<?= format_ksh($ord['total_amount']) ?>"
                                            data-item-count="<?= count($lineItems) ?>">
                                        <?= icon('x-circle', 'lucide-xs', 'white') ?>
                                        <span>Cancel</span>
                                    </button>
                                <?php endif; ?>

                                <!-- Refund resolution for Seller & Admin -->
                                <?php if (isset($refundsByOrder[(int) $ord['id']]) && in_array($role, ['seller', 'admin'], true)): ?>
                                    <?php $rf = $refundsByOrder[(int) $ord['id']]; ?>
                                    <?php if ($rf['status'] === 'requested' || $rf['status'] === 'approved'): ?>
                                        <button type="button" class="btn btn-amber btn-sm btn-open-refund" style="margin-bottom:0.35rem; width:100%;"
                                                data-refund-id="<?= (int) $rf['id'] ?>"
                                                data-order-code="<?= e($ord['order_code']) ?>"
                                                data-amount="<?= format_ksh($rf['amount']) ?>"
                                                data-reason="<?= e($rf['reason']) ?>">
                                            <?= icon('wallet', 'lucide-xs') ?>
                                            <span>Resolve Refund</span>
                                        </button>
                                    <?php endif; ?>
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

                    <div class="mobile-order-card-actions" style="display:flex; flex-direction:column; gap:0.4rem;">
                        <?php if ($ord['payment_status'] === 'unpaid' && $ord['order_status'] !== 'cancelled' && ($role === 'buyer' || $role === 'admin')): ?>
                            <button type="button" class="btn btn-primary btn-sm btn-mpesa-pay" style="width:100%;"
                                    data-order-id="<?= (int) $ord['id'] ?>"
                                    data-order-code="<?= e($ord['order_code']) ?>"
                                    data-amount="<?= number_format((float) $ord['total_amount'], 2) ?>"
                                    data-phone="<?= e($ord['payment_phone'] ?? $ord['buyer_phone'] ?? '') ?>">
                                <?= icon('smartphone', 'lucide-xs', 'white') ?>
                                <span>Pay with M-Pesa</span>
                            </button>
                        <?php endif; ?>

                        <?php if ($role === 'buyer' && in_array($ord['order_status'], ['delivered', 'confirmed'], true)): ?>
                            <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reorder">
                                <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="width: 100%;">
                                    <?= icon('rotate-cw', 'lucide-xs') ?>
                                    <span>Re-order Items</span>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($ord['payment_status'] === 'paid' || !empty($ord['mpesa_receipt'])): ?>
                            <button type="button" class="btn btn-outline btn-sm btn-receipt-view" style="width: 100%;"
                                    data-receipt='<?= htmlspecialchars(json_encode([
                                        'order_code' => $ord['order_code'],
                                        'date'       => date('d M Y, H:i', strtotime($ord['created_at'])),
                                        'buyer'      => $ord['buyer_name'],
                                        'phone'      => $ord['buyer_phone'] ?? '',
                                        'address'    => $ord['delivery_address'],
                                        'items'      => array_map(fn($it) => ['name' => $it['product_name'], 'qty' => (int) $it['quantity'], 'total' => format_ksh($it['line_total'])], $lineItems),
                                        'total'      => format_ksh($ord['total_amount']),
                                        'receipt'    => $ord['mpesa_receipt'] ?: 'Confirmed'
                                    ]), ENT_QUOTES, 'UTF-8') ?>'>
                                <?= icon('file-text', 'lucide-xs') ?>
                                <span>View Receipt</span>
                            </button>
                        <?php endif; ?>

                        <?php if ($ord['order_status'] === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                            <button type="button" class="btn btn-danger btn-sm btn-open-cancel" style="width: 100%;"
                                    data-order-id="<?= (int) $ord['id'] ?>"
                                    data-order-code="<?= e($ord['order_code']) ?>"
                                    data-paid="<?= $ord['payment_status'] === 'paid' ? '1' : '0' ?>"
                                    data-amount="<?= format_ksh($ord['total_amount']) ?>"
                                    data-item-count="<?= count($lineItems) ?>">
                                <?= icon('x-circle', 'lucide-xs', 'white') ?>
                                <span>Cancel Order</span>
                            </button>
                        <?php endif; ?>

                        <?php if (isset($refundsByOrder[(int) $ord['id']]) && in_array($role, ['seller', 'admin'], true)): ?>
                            <?php $rf = $refundsByOrder[(int) $ord['id']]; ?>
                            <?php if ($rf['status'] === 'requested' || $rf['status'] === 'approved'): ?>
                                <button type="button" class="btn btn-amber btn-sm btn-open-refund" style="width: 100%;"
                                        data-refund-id="<?= (int) $rf['id'] ?>"
                                        data-order-code="<?= e($ord['order_code']) ?>"
                                        data-amount="<?= format_ksh($rf['amount']) ?>"
                                        data-reason="<?= e($rf['reason']) ?>">
                                    <?= icon('wallet', 'lucide-xs') ?>
                                    <span>Resolve Refund</span>
                                </button>
                            <?php endif; ?>
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
                                <button type="submit" class="btn btn-outline btn-sm">Set</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL 1: M-Pesa STK Push Live Waiting & Polling Modal -->
<div id="mpesaModal" class="modal-overlay" style="<?= $showMpesaModal ? 'display:flex;' : 'display:none;' ?>">
    <div class="modal-card">
        <div style="background: #15803d; color: #ffffff; padding: 1.1rem 1.4rem; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <?= icon('smartphone', 'lucide-sm', '#ffffff') ?>
                <strong style="font-size: 1.15rem; color: #ffffff;">Lipa na M-Pesa Online</strong>
            </div>
            <button type="button" id="closeMpesaModal" style="background: none; border: none; color: #ffffff; font-size: 1.4rem; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <div style="padding: 1.4rem;">
            <!-- View 1: Prompt Input View (shown when clicking "Pay M-Pesa" on unpaid orders) -->
            <div id="mpesaPromptView" style="display: none;">
                <div style="text-align: center; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.85rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Order Payment</div>
                    <h3 id="promptViewOrderCode" style="font-size: 1.35rem; font-weight: 800; margin: 0.2rem 0;">#SKH-0000</h3>
                    <div id="promptViewAmount" style="font-size: 1.2rem; font-weight: 700; color: #15803d;">KES 0.00</div>
                </div>

                <div class="alert alert-info" style="margin-bottom: 1.2rem; font-size: 0.88rem;">
                    <strong>📱 Instant STK Push:</strong> Enter your Safaricom phone number below. An instant prompt will appear on your handset asking for your M-Pesa PIN.
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="mpesaInputPhone" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">M-Pesa Mobile Number *</label>
                    <input type="tel" id="mpesaInputPhone" class="form-control" placeholder="0712345678 or 2547..." style="width: 100%; font-size: 1rem; padding: 0.6rem 0.75rem;" value="<?= e($user['phone'] ?? '') ?>">
                    <span id="mpesaInputPhoneError" style="display: none; color: #dc2626; font-size: 0.82rem; margin-top: 0.25rem;">Please enter a valid Safaricom phone number (e.g. 0712345678 or 254...).</span>
                </div>

                <div id="mpesaPromptError" class="alert alert-danger" style="display: none; margin-bottom: 1rem; font-size: 0.88rem;"></div>

                <div style="display: flex; gap: 0.6rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" id="btnCancelPrompt">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSubmitStkPush" style="background: #15803d; border-color: #15803d; color: #ffffff;">
                        <?= icon('send', 'lucide-xs', '#ffffff') ?>
                        <span>Send STK Push Prompt</span>
                    </button>
                </div>
            </div>

            <!-- View 2: Live Processing & Waiting View -->
            <div id="mpesaLoadingView" style="<?= $showMpesaModal ? 'display:block;' : 'display:none;' ?> text-align: center;">
                <div class="spinner" style="width: 48px; height: 48px; border: 4px solid #e2e8f0; border-top: 4px solid #15803d; border-radius: 50%; margin: 0 auto 1rem; animation: spin 1s linear infinite;"></div>
                <h3 id="mpesaModalTitle" style="color: #15803d; margin-bottom: 0.35rem;">STK Push Sent to Your Phone!</h3>
                <div id="mpesaTargetPhoneBadge" style="display: inline-block; font-size: 0.85rem; font-weight: 700; background: rgba(22, 163, 74, 0.12); color: #15803d; border: 1px solid rgba(22, 163, 74, 0.3); padding: 0.25rem 0.65rem; border-radius: 9999px; margin-bottom: 0.75rem;">
                    Sent to: <span id="mpesaTargetPhone"><?= $promptOrder ? e($promptOrder['payment_phone'] ?? $promptOrder['buyer_phone'] ?? '') : '' ?></span>
                </div>
                <p id="mpesaModalMessage" class="muted" style="font-size: 0.9rem; margin-bottom: 1rem;">
                    Please check your phone screen and enter your <strong>M-Pesa PIN</strong> to approve payment for order
                    <strong id="mpesaOrderCode"><?= $promptOrder ? e($promptOrder['order_code']) : '' ?></strong>
                    <span id="mpesaOrderAmount"><?= $promptOrder ? ' (KES ' . number_format((float) $promptOrder['total_amount'], 2) . ')' : '' ?></span>.
                </p>

                <div style="margin: 1.25rem 0; padding: 0.85rem; background: rgba(22, 163, 74, 0.1); border: 1px solid rgba(22, 163, 74, 0.3); border-radius: 6px;">
                    <div style="font-size: 0.88rem; color: #166534; font-weight: 600;">
                        Waiting for Safaricom confirmation... <span id="mpesaCountdown">60</span>s
                    </div>
                    <div id="mpesaStatusNote" class="small muted" style="margin-top: 0.25rem;">
                        Polling gateway status every 3 seconds...
                    </div>
                </div>

                <div style="display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap;">
                    <button type="button" id="btnCheckMpesaNow" class="btn btn-outline btn-sm">Check Status Now</button>
                    <!-- Developer Presentation Simulation Button -->
                    <button type="button" id="btnSimulateMpesa" class="btn btn-amber btn-sm" title="Simulates customer entering M-Pesa PIN for offline demo" style="background: #f59e0b; border-color: #d97706; color: #0f172a; font-weight: 600;">
                        ⚡ Simulate PIN Approval (Demo)
                    </button>
                    <!-- Retry / Change Phone Button if prompt times out or wrong number -->
                    <button type="button" id="btnRetryMpesa" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                        <?= icon('refresh-cw', 'lucide-xs') ?>
                        <span>Resend / Change Phone</span>
                    </button>
                </div>

                <div id="mpesaSimulatedNotice" style="<?= !is_mpesa_live_configured() ? 'display:block;' : 'display:none;' ?> margin-top: 1rem; font-size: 0.8rem; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 0.6rem 0.85rem; text-align: left; line-height: 1.45;">
                    <strong>ℹ Offline Presentation Demo:</strong> Live Daraja credentials are not configured, so an airtime/cellular prompt is not sent to physical handsets. Click <strong>⚡ Simulate PIN Approval (Demo)</strong> to approve the transaction, or configure <code>config/mpesa.local.php</code> for live prompts.
                </div>
            </div>

            <!-- View 3: Payment Success View -->
            <div id="mpesaSuccessView" style="display: none; text-align: center; padding: 1rem 0;">
                <div style="font-size: 3.5rem; color: #16a34a; margin-bottom: 0.5rem; line-height: 1;">✓</div>
                <h3 style="color: #166534; margin-bottom: 0.4rem; font-size: 1.4rem;">Payment Confirmed!</h3>
                <p class="muted" id="mpesaSuccessText" style="font-size: 0.95rem;">Your M-Pesa payment was processed successfully.</p>
                <div style="margin-top: 1.25rem;">
                    <button type="button" class="btn btn-primary" onclick="window.location.href='<?= e(url('pages/buyer/orders.php')) ?>'" style="background: #15803d; border-color: #15803d; color: #ffffff;">Refresh Orders</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: Order Cancellation with Stock Restore Confirmation -->
<div id="cancelModal" class="modal-overlay" style="display: none;">
    <div class="modal-card">
        <div style="background: #dc2626; color: #ffffff; padding: 1rem 1.4rem; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <strong style="font-size: 1.1rem;">Cancel Order &amp; Restore Stock</strong>
            <button type="button" id="closeCancelModal" style="background: none; border: none; color: #ffffff; font-size: 1.4rem; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="padding: 1.4rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="order_id" id="cancelOrderId" value="">

            <p style="margin-bottom: 1rem;">
                Are you sure you want to cancel order <strong id="cancelOrderCodeText"></strong>?
            </p>

            <div class="alert alert-info" style="margin-bottom: 1rem; font-size: 0.88rem;">
                <strong>✓ Stock Restoration:</strong> All <span id="cancelItemCount"></span> product item(s) in this order will be immediately returned to vendor inventory.
            </div>

            <div id="cancelRefundNotice" class="alert alert-warning" style="display: none; margin-bottom: 1rem; font-size: 0.88rem;">
                <strong>💰 Paid Order Notice:</strong> This order was paid (<span id="cancelAmount"></span>). An automatic refund record will be queued for seller/admin processing.
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="cancel_reason" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Reason for Cancellation</label>
                <select name="cancel_reason" id="cancel_reason" class="form-control" required style="width: 100%;">
                    <option value="Changed my mind">Changed my mind / No longer needed</option>
                    <option value="Ordered incorrect item or quantity">Ordered incorrect item or quantity</option>
                    <option value="Found alternative product or price">Found alternative product or price</option>
                    <option value="Delivery time too long">Delivery time too long</option>
                    <option value="Wrong delivery address provided">Wrong delivery address provided</option>
                    <option value="Vendor unable to fulfill">Vendor unable to fulfill</option>
                    <option value="Other reason">Other reason</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="cancel_notes" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Additional Details (Optional)</label>
                <textarea name="cancel_notes" id="cancel_notes" class="form-control" rows="2" placeholder="Brief note explaining cancellation" style="width: 100%;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.6rem;">
                <button type="button" class="btn btn-outline" id="btnDismissCancel">Keep Order</button>
                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Process Refund (Admin / Seller) -->
<div id="refundModal" class="modal-overlay" style="display: none;">
    <div class="modal-card">
        <div style="background: #0284c7; color: #ffffff; padding: 1rem 1.4rem; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <strong style="font-size: 1.1rem;">Process Customer Refund</strong>
            <button type="button" id="closeRefundModal" style="background: none; border: none; color: #ffffff; font-size: 1.4rem; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="padding: 1.4rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="process_refund">
            <input type="hidden" name="refund_id" id="processRefundId" value="">

            <p style="margin-bottom: 0.8rem;">
                Processing refund for order <strong id="refundOrderCode"></strong>:
            </p>

            <div style="background: rgba(2, 132, 199, 0.08); border: 1px solid rgba(2, 132, 199, 0.2); padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                    <span class="muted">Refund Amount:</span>
                    <strong id="refundAmountText" style="color: #0284c7; font-size: 1.1rem;"></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="muted">Reason:</span>
                    <span id="refundReasonText" class="small" style="font-weight: 500;"></span>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="refundDecision" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Decision</label>
                <select name="decision" id="refundDecision" class="form-control" required style="width: 100%;">
                    <option value="complete">Approve &amp; Mark Completed (Refunded to customer)</option>
                    <option value="reject">Reject Refund Request</option>
                </select>
            </div>

            <div class="form-group" id="refundRefGroup" style="margin-bottom: 1rem;">
                <label for="provider_reference" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">M-Pesa Reversal / Transaction Code (Optional)</label>
                <input type="text" name="provider_reference" id="provider_reference" class="form-control" placeholder="e.g. QGH91823XX or B2C Ref" style="width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="refund_notes" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Resolution Note (Optional)</label>
                <textarea name="notes" id="refund_notes" class="form-control" rows="2" placeholder="e.g. Reversed via M-Pesa on 24 Nov" style="width: 100%;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.6rem;">
                <button type="button" class="btn btn-outline" id="btnDismissRefund">Close</button>
                <button type="submit" class="btn btn-primary">Save Refund Decision</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: Printable Official Receipt Voucher -->
<div id="receiptModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 540px;">
        <div style="background: #0f172a; color: #ffffff; padding: 1rem 1.4rem; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <?= icon('file-text', 'lucide-sm', '#ffffff') ?>
                <strong style="font-size: 1.1rem;">Official Payment Receipt</strong>
            </div>
            <button type="button" id="closeReceiptModal" style="background: none; border: none; color: #ffffff; font-size: 1.4rem; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <div style="padding: 1.4rem;">
            <!-- Receipt Printable Box -->
            <div id="receiptPrintArea" style="border: 1px dashed #94a3b8; border-radius: 8px; padding: 1.25rem; background: #ffffff; color: #0f172a;">
                <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 0.75rem; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0;">SokoHub Kenya</h3>
                    <div style="font-size: 0.8rem; color: #64748b;">Kirinyaga University Campus Marketplace</div>
                    <div style="font-size: 0.85rem; font-weight: 600; margin-top: 0.4rem; color: #16a34a;">★ PAYMENT CONFIRMATION ★</div>
                </div>

                <div style="font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Order Code:</span>
                        <strong id="rcptOrderCode"></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Date &amp; Time:</span>
                        <span id="rcptDate"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Customer:</span>
                        <strong id="rcptCustomer"></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Drop-off:</span>
                        <span id="rcptAddress" style="text-align: right; max-width: 60%;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">M-Pesa Receipt:</span>
                        <strong id="rcptReceipt" style="font-family: monospace; color: #15803d;"></strong>
                    </div>
                </div>

                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 1rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid #cbd5e1; border-top: 1px solid #cbd5e1; text-align: left;">
                            <th style="padding: 0.4rem 0;">Item</th>
                            <th style="padding: 0.4rem 0; text-align: center;">Qty</th>
                            <th style="padding: 0.4rem 0; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody id="rcptItemsTable">
                        <!-- Populated by JS -->
                    </tbody>
                </table>

                <div style="border-top: 2px solid #0f172a; padding-top: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 1rem; font-weight: 700;">Amount Paid:</span>
                    <strong id="rcptGrandTotal" style="font-size: 1.25rem; color: #15803d;"></strong>
                </div>

                <div style="text-align: center; margin-top: 1rem; font-size: 0.75rem; color: #64748b;">
                    Thank you for supporting student entrepreneurs at Kirinyaga University!
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.25rem;">
                <button type="button" class="btn btn-outline" id="btnDismissReceipt">Close</button>
                <button type="button" class="btn btn-primary" onclick="printReceiptArea()">
                    <?= icon('printer', 'lucide-xs', '#ffffff') ?>
                    <span>Print Receipt</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

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

function printReceiptArea() {
    const area = document.getElementById('receiptPrintArea');
    if (!area) return;
    const printWindow = window.open('', '_blank', 'height=650,width=800');
    if (!printWindow) {
        alert('Please allow popups to print your receipt.');
        return;
    }
    printWindow.document.write('<!DOCTYPE html><html><head><title>SokoHub Receipt</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 2rem; color: #0f172a; margin: 0; }');
    printWindow.document.write('.receipt-box { max-width: 520px; margin: 0 auto; border: 1px dashed #94a3b8; border-radius: 8px; padding: 1.5rem; background: #ffffff; }');
    printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }');
    printWindow.document.write('th, td { padding: 0.4rem 0; font-size: 0.9rem; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body><div class="receipt-box">');
    printWindow.document.write(area.innerHTML);
    printWindow.document.write('</div></body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 300);
}

document.addEventListener('DOMContentLoaded', () => {
    let currentPollingOrderId = <?= (int) $promptOrderId ?>;
    let currentPollingCrid = '<?= e($promptCrid) ?>';
    let currentPollingOrderCode = '<?= $promptOrder ? e($promptOrder['order_code']) : '' ?>';
    let currentPollingAmount = '<?= $promptOrder ? number_format((float) $promptOrder['total_amount'], 2) : '' ?>';
    let currentPollingPhone = '<?= $promptOrder ? e($promptOrder['payment_phone'] ?? $promptOrder['buyer_phone'] ?? '') : '' ?>';
    let pollInterval = null;
    let countdownSec = 60;

    const mpesaModal = document.getElementById('mpesaModal');
    const closeMpesaModal = document.getElementById('closeMpesaModal');
    const mpesaPromptView = document.getElementById('mpesaPromptView');
    const mpesaLoadingView = document.getElementById('mpesaLoadingView');
    const mpesaSuccessView = document.getElementById('mpesaSuccessView');
    const countdownEl = document.getElementById('mpesaCountdown');
    const btnCheckNow = document.getElementById('btnCheckMpesaNow');
    const btnSimulate = document.getElementById('btnSimulateMpesa');
    const btnRetryMpesa = document.getElementById('btnRetryMpesa');
    const btnCancelPrompt = document.getElementById('btnCancelPrompt');
    const btnSubmitStkPush = document.getElementById('btnSubmitStkPush');
    const mpesaInputPhone = document.getElementById('mpesaInputPhone');
    const mpesaInputPhoneError = document.getElementById('mpesaInputPhoneError');
    const mpesaPromptError = document.getElementById('mpesaPromptError');

    // Show In-Modal Prompt View (Triggered by "Pay M-Pesa" on any unpaid order)
    function showPromptView(orderId, orderCode, amount, phone) {
        if (pollInterval) clearInterval(pollInterval);
        currentPollingOrderId = orderId;
        currentPollingOrderCode = orderCode;
        currentPollingAmount = amount;
        currentPollingPhone = phone || '';

        const codeEl = document.getElementById('promptViewOrderCode');
        if (codeEl) codeEl.textContent = orderCode || ('#' + orderId);

        const amtEl = document.getElementById('promptViewAmount');
        if (amtEl) amtEl.textContent = amount ? ('KES ' + amount) : '';

        if (mpesaInputPhone) {
            mpesaInputPhone.value = phone || mpesaInputPhone.value || '';
        }
        if (mpesaInputPhoneError) mpesaInputPhoneError.style.display = 'none';
        if (mpesaPromptError) {
            mpesaPromptError.style.display = 'none';
            mpesaPromptError.textContent = '';
        }

        if (mpesaPromptView) mpesaPromptView.style.display = 'block';
        if (mpesaLoadingView) mpesaLoadingView.style.display = 'none';
        if (mpesaSuccessView) mpesaSuccessView.style.display = 'none';
        if (mpesaModal) mpesaModal.style.display = 'flex';
        if (mpesaInputPhone) mpesaInputPhone.focus();
    }

    // 1. M-Pesa Status Polling & Live Countdown
    function startMpesaPolling(orderId, crid, orderCode, phone, amount, isSimulated) {
        currentPollingOrderId = orderId;
        currentPollingCrid = crid || '';
        currentPollingOrderCode = orderCode || currentPollingOrderCode;
        currentPollingPhone = phone || currentPollingPhone;
        currentPollingAmount = amount || currentPollingAmount;
        countdownSec = 60;

        if (countdownEl) countdownEl.textContent = countdownSec;

        const titleEl = document.getElementById('mpesaModalTitle');
        if (titleEl) {
            titleEl.textContent = 'STK Push Sent to Your Phone!';
            titleEl.style.color = '#15803d';
        }

        const codeEl = document.getElementById('mpesaOrderCode');
        if (codeEl) codeEl.textContent = currentPollingOrderCode || ('#' + orderId);

        const amtEl = document.getElementById('mpesaOrderAmount');
        if (amtEl) amtEl.textContent = currentPollingAmount ? (' (KES ' + currentPollingAmount + ')') : '';

        const phoneEl = document.getElementById('mpesaTargetPhone');
        if (phoneEl) phoneEl.textContent = currentPollingPhone;

        const simNotice = document.getElementById('mpesaSimulatedNotice');
        if (simNotice && typeof isSimulated === 'boolean') {
            simNotice.style.display = isSimulated ? 'block' : 'none';
        }

        const noteEl = document.getElementById('mpesaStatusNote');
        if (noteEl) {
            noteEl.textContent = 'Polling gateway status every 3 seconds...';
            noteEl.style.color = '#64748b';
        }

        if (mpesaPromptView) mpesaPromptView.style.display = 'none';
        if (mpesaLoadingView) mpesaLoadingView.style.display = 'block';
        if (mpesaSuccessView) mpesaSuccessView.style.display = 'none';
        if (mpesaModal) mpesaModal.style.display = 'flex';

        if (pollInterval) clearInterval(pollInterval);

        pollInterval = setInterval(() => {
            countdownSec--;
            if (countdownEl) countdownEl.textContent = Math.max(0, countdownSec);

            if (countdownSec % 3 === 0) {
                checkPaymentStatus();
            }

            if (countdownSec <= 0) {
                clearInterval(pollInterval);
                if (noteEl) {
                    noteEl.textContent = 'Prompt timed out. If you did not receive it or need to retry, click "Resend / Change Phone" below.';
                    noteEl.style.color = '#d97706';
                }
            }
        }, 1000);
    }

    async function checkPaymentStatus() {
        if (!currentPollingOrderId) return;
        try {
            const url = '<?= e(url('pages/api/mpesa_status.php')) ?>?order_id=' + currentPollingOrderId + '&checkout_request_id=' + encodeURIComponent(currentPollingCrid);
            const res = await fetch(url);
            const data = await res.json();

            if (data.ok && data.paid) {
                if (pollInterval) clearInterval(pollInterval);
                if (mpesaPromptView) mpesaPromptView.style.display = 'none';
                if (mpesaLoadingView) mpesaLoadingView.style.display = 'none';
                if (mpesaSuccessView) mpesaSuccessView.style.display = 'block';
                const successText = document.getElementById('mpesaSuccessText');
                if (successText) {
                    successText.textContent = 'M-Pesa payment confirmed (Receipt #' + (data.receipt || 'OK') + ')!';
                }

                setTimeout(() => {
                    window.location.href = '<?= e(url('pages/buyer/orders.php')) ?>';
                }, 2200);
            } else if (data.status === 'failed' || data.status === 'cancelled') {
                if (pollInterval) clearInterval(pollInterval);
                const titleEl = document.getElementById('mpesaModalTitle');
                if (titleEl) {
                    titleEl.textContent = 'Payment ' + (data.status === 'cancelled' ? 'Cancelled' : 'Failed');
                    titleEl.style.color = '#dc2626';
                }
                const noteEl = document.getElementById('mpesaStatusNote');
                if (noteEl) {
                    noteEl.textContent = data.message || 'Payment request was cancelled or declined on your handset.';
                    noteEl.style.color = '#dc2626';
                }
            }
        } catch (e) {
            console.error('M-Pesa status query error:', e);
        }
    }

    if (btnCheckNow) {
        btnCheckNow.addEventListener('click', async () => {
            const origText = btnCheckNow.textContent;
            btnCheckNow.textContent = 'Checking...';
            btnCheckNow.disabled = true;
            await checkPaymentStatus();
            setTimeout(() => {
                btnCheckNow.textContent = origText;
                btnCheckNow.disabled = false;
            }, 500);
        });
    }

    if (btnSimulate) {
        btnSimulate.addEventListener('click', async () => {
            btnSimulate.disabled = true;
            btnSimulate.textContent = 'Processing simulation...';
            try {
                const formData = new FormData();
                formData.append('order_id', currentPollingOrderId);

                const res = await fetch('<?= e(url('pages/api/mpesa_simulate.php')) ?>', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.ok) {
                    checkPaymentStatus();
                } else {
                    alert('Simulation error: ' + (data.message || 'Failed'));
                }
            } catch (err) {
                alert('Simulation request failed.');
            } finally {
                btnSimulate.disabled = false;
                btnSimulate.textContent = '⚡ Simulate PIN Approval (Demo)';
            }
        });
    }

    if (btnRetryMpesa) {
        btnRetryMpesa.addEventListener('click', () => {
            showPromptView(currentPollingOrderId, currentPollingOrderCode, currentPollingAmount, currentPollingPhone);
        });
    }

    if (btnCancelPrompt) {
        btnCancelPrompt.addEventListener('click', () => {
            if (pollInterval) clearInterval(pollInterval);
            mpesaModal.style.display = 'none';
        });
    }

    if (closeMpesaModal) {
        closeMpesaModal.addEventListener('click', () => {
            if (pollInterval) clearInterval(pollInterval);
            mpesaModal.style.display = 'none';
        });
    }

    if (btnSubmitStkPush) {
        btnSubmitStkPush.addEventListener('click', async () => {
            const phone = mpesaInputPhone ? mpesaInputPhone.value.trim() : '';
            if (!phone || phone.length < 9) {
                if (mpesaInputPhoneError) mpesaInputPhoneError.style.display = 'block';
                return;
            }
            if (mpesaInputPhoneError) mpesaInputPhoneError.style.display = 'none';
            if (mpesaPromptError) mpesaPromptError.style.display = 'none';

            btnSubmitStkPush.disabled = true;
            const origHtml = btnSubmitStkPush.innerHTML;
            btnSubmitStkPush.textContent = 'Sending STK Push...';

            try {
                const fd = new FormData();
                fd.append('csrf_token', '<?= e(csrf_token()) ?>');
                fd.append('action', 'pay_mpesa');
                fd.append('order_id', currentPollingOrderId);
                fd.append('mpesa_phone', phone);
                fd.append('ajax', '1');

                const res = await fetch('<?= e(url('pages/buyer/orders.php')) ?>', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();

                if (data.ok) {
                    currentPollingCrid = data.checkout_request_id || '';
                    currentPollingPhone = data.phone || phone;
                    startMpesaPolling(currentPollingOrderId, currentPollingCrid, currentPollingOrderCode, currentPollingPhone, currentPollingAmount, !!data.simulated);
                } else {
                    if (mpesaPromptError) {
                        mpesaPromptError.textContent = data.message || 'Failed to dispatch STK push. Please check your phone number.';
                        mpesaPromptError.style.display = 'block';
                    }
                }
            } catch (err) {
                if (mpesaPromptError) {
                    mpesaPromptError.textContent = 'Network or server error while initiating M-Pesa prompt.';
                    mpesaPromptError.style.display = 'block';
                }
            } finally {
                btnSubmitStkPush.disabled = false;
                btnSubmitStkPush.innerHTML = origHtml;
            }
        });
    }

    // Auto-start polling if modal requested by URL query
    <?php if ($showMpesaModal): ?>
    startMpesaPolling(
        <?= (int) $promptOrderId ?>,
        '<?= e($promptCrid) ?>',
        '<?= $promptOrder ? e($promptOrder['order_code']) : '' ?>',
        '<?= $promptOrder ? e($promptOrder['payment_phone'] ?? $promptOrder['buyer_phone'] ?? '') : '' ?>',
        '<?= $promptOrder ? number_format((float) $promptOrder['total_amount'], 2) : '' ?>',
        <?= !is_mpesa_live_configured() ? 'true' : 'false' ?>
    );
    <?php endif; ?>

    // Pay via M-Pesa button triggers (In-Modal Prompt without browser prompt() or page reload)
    document.querySelectorAll('.btn-mpesa-pay').forEach((btn) => {
        btn.addEventListener('click', () => {
            const oid = parseInt(btn.getAttribute('data-order-id'), 10);
            const code = btn.getAttribute('data-order-code') || '';
            const phone = btn.getAttribute('data-phone') || '';
            const amount = btn.getAttribute('data-amount') || '';
            showPromptView(oid, code, amount, phone);
        });
    });

    // 2. Cancellation Modal
    const cancelModal = document.getElementById('cancelModal');
    const closeCancelModal = document.getElementById('closeCancelModal');
    const btnDismissCancel = document.getElementById('btnDismissCancel');

    document.querySelectorAll('.btn-open-cancel').forEach((btn) => {
        btn.addEventListener('click', () => {
            const oid = btn.getAttribute('data-order-id');
            const code = btn.getAttribute('data-order-code');
            const paid = btn.getAttribute('data-paid') === '1';
            const amount = btn.getAttribute('data-amount');
            const count = btn.getAttribute('data-item-count');

            document.getElementById('cancelOrderId').value = oid;
            document.getElementById('cancelOrderCodeText').textContent = code;
            document.getElementById('cancelItemCount').textContent = count;
            document.getElementById('cancelAmount').textContent = amount;

            const refNotice = document.getElementById('cancelRefundNotice');
            if (refNotice) {
                refNotice.style.display = paid ? 'block' : 'none';
            }

            cancelModal.style.display = 'flex';
        });
    });

    if (closeCancelModal) closeCancelModal.addEventListener('click', () => { cancelModal.style.display = 'none'; });
    if (btnDismissCancel) btnDismissCancel.addEventListener('click', () => { cancelModal.style.display = 'none'; });

    // 3. Refund Modal
    const refundModal = document.getElementById('refundModal');
    const closeRefundModal = document.getElementById('closeRefundModal');
    const btnDismissRefund = document.getElementById('btnDismissRefund');

    document.querySelectorAll('.btn-open-refund').forEach((btn) => {
        btn.addEventListener('click', () => {
            const rid = btn.getAttribute('data-refund-id');
            const code = btn.getAttribute('data-order-code');
            const amt = btn.getAttribute('data-amount');
            const reason = btn.getAttribute('data-reason');

            document.getElementById('processRefundId').value = rid;
            document.getElementById('refundOrderCode').textContent = code;
            document.getElementById('refundAmountText').textContent = amt;
            document.getElementById('refundReasonText').textContent = reason;

            refundModal.style.display = 'flex';
        });
    });

    if (closeRefundModal) closeRefundModal.addEventListener('click', () => { refundModal.style.display = 'none'; });
    if (btnDismissRefund) btnDismissRefund.addEventListener('click', () => { refundModal.style.display = 'none'; });

    // 4. Receipt Modal
    const receiptModal = document.getElementById('receiptModal');
    const closeReceiptModal = document.getElementById('closeReceiptModal');
    const btnDismissReceipt = document.getElementById('btnDismissReceipt');

    document.querySelectorAll('.btn-receipt-view').forEach((btn) => {
        btn.addEventListener('click', () => {
            try {
                const data = JSON.parse(btn.getAttribute('data-receipt'));
                document.getElementById('rcptOrderCode').textContent = data.order_code || '';
                document.getElementById('rcptDate').textContent = data.date || '';
                document.getElementById('rcptCustomer').textContent = (data.buyer || '') + (data.phone ? ' (' + data.phone + ')' : '');
                document.getElementById('rcptAddress').textContent = data.address || '';
                document.getElementById('rcptReceipt').textContent = data.receipt || 'Confirmed';
                document.getElementById('rcptGrandTotal').textContent = data.total || '';

                const tbody = document.getElementById('rcptItemsTable');
                tbody.innerHTML = '';
                if (Array.isArray(data.items)) {
                    data.items.forEach((it) => {
                        const tr = document.createElement('tr');
                        tr.style.borderBottom = '1px solid #e2e8f0';
                        tr.innerHTML = `
                            <td style="padding: 0.35rem 0;">${it.name || ''}</td>
                            <td style="padding: 0.35rem 0; text-align: center;">&times; ${it.qty || 1}</td>
                            <td style="padding: 0.35rem 0; text-align: right; font-weight: 600;">${it.total || ''}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                }

                receiptModal.style.display = 'flex';
            } catch (err) {
                console.error('Error opening receipt modal:', err);
            }
        });
    });

    if (closeReceiptModal) closeReceiptModal.addEventListener('click', () => { receiptModal.style.display = 'none'; });
    if (btnDismissReceipt) btnDismissReceipt.addEventListener('click', () => { receiptModal.style.display = 'none'; });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
