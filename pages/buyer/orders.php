<?php
/**
 * SokoHub Orders Management & Fulfillment Portal
 * Features:
 * - Real-time M-Pesa STK push initiation, status polling, and simulation
 * - Atomic order cancellation with product stock restoration
 * - Integrated refund request tracking and approval workflow
 * - Role-based controls for Buyers, Sellers, and Admins
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_login();
$pdo = get_db_connection();
ensure_order_schema_tables($pdo);

$role = $user['role'];
$userId = (int) $user['id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('pages/buyer/orders.php');
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    // Action 1: Cancel Order with Stock Restore
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

    // Action 2: Process Refund (Sellers & Admins)
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

    // Action 3: Trigger M-Pesa STK Push for an Unpaid Order
    if ($action === 'pay_mpesa') {
        $phoneInput = trim((string) ($_POST['mpesa_phone'] ?? $user['phone'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $ord = $stmt->fetch();

        if (!$ord || ($role === 'buyer' && (int) $ord['buyer_id'] !== $userId)) {
            set_flash('error', 'Order not found or unauthorized.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['order_status'] === 'cancelled') {
            set_flash('warning', 'Cannot pay for a cancelled order.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['payment_status'] === 'paid') {
            set_flash('info', 'This order is already marked as paid.');
            redirect('pages/buyer/orders.php');
        }

        $stk = mpesa_stk_push($pdo, $orderId, $phoneInput, (float) $ord['total_amount'], $ord['order_code']);
        if ($stk['ok']) {
            set_flash('success', 'STK Push sent to ' . $stk['phone'] . '. Please enter your M-Pesa PIN.');
            redirect('pages/buyer/orders.php?mpesa_prompt=1&order_id=' . $orderId . '&crid=' . urlencode($stk['checkout_request_id']));
        } else {
            set_flash('error', 'M-Pesa error: ' . $stk['message']);
            redirect('pages/buyer/orders.php');
        }
    }

    // Action 4: Update Order Status (Seller / Admin)
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

// Fetch orders according to role
$activeFilter = trim((string) ($_GET['filter'] ?? 'all'));

if ($role === 'buyer') {
    $sql = 'SELECT o.*, u.full_name AS buyer_name, u.phone AS buyer_phone, d.delivery_status, d.tracking_notes
            FROM orders o
            INNER JOIN users u ON u.id = o.buyer_id
            LEFT JOIN deliveries d ON d.order_id = o.id
            WHERE o.buyer_id = :uid';
    if ($activeFilter === 'pending') {
        $sql .= " AND o.order_status = 'pending'";
    } elseif ($activeFilter === 'paid') {
        $sql .= " AND o.payment_status = 'paid' AND o.order_status <> 'delivered'";
    } elseif ($activeFilter === 'delivered') {
        $sql .= " AND o.order_status = 'delivered'";
    } elseif ($activeFilter === 'cancelled') {
        $sql .= " AND (o.order_status = 'cancelled' OR o.payment_status = 'refunded')";
    }
    $sql .= ' ORDER BY o.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} elseif ($role === 'seller') {
    $sql = 'SELECT DISTINCT o.*, u.full_name AS buyer_name, u.phone AS buyer_phone, d.delivery_status, d.tracking_notes
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            INNER JOIN users u ON u.id = o.buyer_id
            LEFT JOIN deliveries d ON d.order_id = o.id
            WHERE oi.seller_id = :uid';
    if ($activeFilter === 'pending') {
        $sql .= " AND o.order_status = 'pending'";
    } elseif ($activeFilter === 'paid') {
        $sql .= " AND o.payment_status = 'paid' AND o.order_status <> 'delivered'";
    } elseif ($activeFilter === 'delivered') {
        $sql .= " AND o.order_status = 'delivered'";
    } elseif ($activeFilter === 'cancelled') {
        $sql .= " AND (o.order_status = 'cancelled' OR o.payment_status = 'refunded')";
    }
    $sql .= ' ORDER BY o.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} else {
    $sql = 'SELECT o.*, u.full_name AS buyer_name, u.phone AS buyer_phone, d.delivery_status, d.tracking_notes
            FROM orders o
            INNER JOIN users u ON u.id = o.buyer_id
            LEFT JOIN deliveries d ON d.order_id = o.id';
    if ($activeFilter === 'pending') {
        $sql .= " WHERE o.order_status = 'pending'";
    } elseif ($activeFilter === 'paid') {
        $sql .= " WHERE o.payment_status = 'paid' AND o.order_status <> 'delivered'";
    } elseif ($activeFilter === 'delivered') {
        $sql .= " WHERE o.order_status = 'delivered'";
    } elseif ($activeFilter === 'cancelled') {
        $sql .= " WHERE (o.order_status = 'cancelled' OR o.payment_status = 'refunded')";
    }
    $sql .= ' ORDER BY o.created_at DESC';
    $orders = $pdo->query($sql)->fetchAll();
}

// Preload line items, payments, and refunds
$itemsByOrder = [];
$refundsByOrder = [];
$paymentsByOrder = [];

if (!empty($orders)) {
    $oIds = array_map(fn($r) => (int) $r['id'], $orders);
    $ph = implode(',', array_fill(0, count($oIds), '?'));

    // Line items
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

    // Refunds
    $refStmt = $pdo->prepare("SELECT * FROM refunds WHERE order_id IN ({$ph}) ORDER BY id DESC");
    $refStmt->execute($oIds);
    foreach ($refStmt->fetchAll() as $rf) {
        $refundsByOrder[(int) $rf['order_id']] = $rf;
    }

    // Payments
    $payStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id IN ({$ph}) ORDER BY id DESC");
    $payStmt->execute($oIds);
    foreach ($payStmt->fetchAll() as $py) {
        if (!isset($paymentsByOrder[(int) $py['order_id']])) {
            $paymentsByOrder[(int) $py['order_id']] = $py;
        }
    }
}

// Prompt info for modal
$promptOrderId = (int) ($_GET['order_id'] ?? 0);
$promptCrid = trim((string) ($_GET['crid'] ?? ''));
$showMpesaModal = !empty($_GET['mpesa_prompt']) && $promptOrderId > 0;
$promptOrder = null;
if ($showMpesaModal) {
    foreach ($orders as $o) {
        if ((int) $o['id'] === $promptOrderId) {
            $promptOrder = $o;
            break;
        }
    }
}

$pageTitle = 'SokoHub | Orders, Payments & Refunds';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Orders &amp; Fulfillment</h1>
            <p class="muted">Manage marketplace orders, live M-Pesa STK push payments, cancellations with stock restore, and refunds.</p>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">Shop More Products</a>
        </div>
    </div>

    <!-- Filter navigation tabs -->
    <div class="pill-list" style="margin-bottom: 1.25rem;">
        <a href="<?= e(url('pages/buyer/orders.php?filter=all')) ?>" class="badge <?= $activeFilter === 'all' ? 'badge-primary' : 'badge-neutral' ?>" style="text-decoration:none; padding:0.45rem 0.85rem;">All Orders</a>
        <a href="<?= e(url('pages/buyer/orders.php?filter=pending')) ?>" class="badge <?= $activeFilter === 'pending' ? 'badge-primary' : 'badge-neutral' ?>" style="text-decoration:none; padding:0.45rem 0.85rem;">Pending</a>
        <a href="<?= e(url('pages/buyer/orders.php?filter=paid')) ?>" class="badge <?= $activeFilter === 'paid' ? 'badge-primary' : 'badge-neutral' ?>" style="text-decoration:none; padding:0.45rem 0.85rem;">Paid / Active</a>
        <a href="<?= e(url('pages/buyer/orders.php?filter=delivered')) ?>" class="badge <?= $activeFilter === 'delivered' ? 'badge-primary' : 'badge-neutral' ?>" style="text-decoration:none; padding:0.45rem 0.85rem;">Delivered</a>
        <a href="<?= e(url('pages/buyer/orders.php?filter=cancelled')) ?>" class="badge <?= $activeFilter === 'cancelled' ? 'badge-primary' : 'badge-neutral' ?>" style="text-decoration:none; padding:0.45rem 0.85rem;">Cancelled &amp; Refunds</a>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card">
            <p class="muted">No orders found for the selected filter.</p>
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
                            <th>Payment &amp; M-Pesa</th>
                            <th>Order Status</th>
                            <th>Delivery</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $ord): ?>
                        <?php
                        $oid = (int) $ord['id'];
                        $oBadge = get_status_badge($ord['order_status']);
                        $dBadge = get_status_badge($ord['delivery_status'] ?? 'pending');
                        $pBadge = get_status_badge($ord['payment_status']);
                        $lineItems = $itemsByOrder[$oid] ?? [];
                        $refund = $refundsByOrder[$oid] ?? null;
                        $payment = $paymentsByOrder[$oid] ?? null;
                        $isCancelled = ($ord['order_status'] === 'cancelled');
                        $isDelivered = ($ord['order_status'] === 'delivered');
                        ?>
                        <tr id="order-row-<?= $oid ?>">
                            <td>
                                <strong><?= e($ord['order_code']) ?></strong>
                                <div class="small muted"><?= e(date('d M Y, H:i', strtotime($ord['created_at']))) ?></div>
                                <?php if ($isCancelled && !empty($ord['cancelled_at'])): ?>
                                    <div class="small" style="color:var(--red-crimson, #c62828);">
                                        Cancelled: <?= e(date('d M Y', strtotime($ord['cancelled_at']))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong><?= e($ord['buyer_name']) ?></strong>
                                <div class="small muted"><?= e($ord['delivery_town']) ?> &mdash; <?= e($ord['delivery_address']) ?></div>
                                <div class="small muted">Phone: <?= e($ord['buyer_phone']) ?></div>
                            </td>

                            <td>
                                <?php foreach ($lineItems as $li): ?>
                                    <div class="small"><?= e($li['product_name']) ?> &times; <?= (int) $li['quantity'] ?></div>
                                <?php endforeach; ?>
                                <?php if ($isCancelled): ?>
                                    <span class="badge badge-success small" style="margin-top:0.3rem;">✓ Stock Restored</span>
                                <?php endif; ?>
                            </td>

                            <td><strong><?= format_ksh($ord['total_amount']) ?></strong></td>

                            <td>
                                <div><strong><?= e($ord['payment_method']) ?></strong></div>
                                <span class="badge <?= e($pBadge['class']) ?> payment-status-badge"><?= e($pBadge['label']) ?></span>

                                <?php if ($payment && !empty($payment['mpesa_receipt'])): ?>
                                    <div class="small" style="color:var(--green-dark); font-weight:600; margin-top:0.2rem;">
                                        Receipt: <?= e($payment['mpesa_receipt']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($refund): ?>
                                    <div style="margin-top:0.35rem;">
                                        <span class="badge <?= $refund['status'] === 'completed' ? 'badge-success' : ($refund['status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                                            Refund: <?= ucfirst($refund['status']) ?>
                                        </span>
                                        <?php if (!empty($refund['provider_reference'])): ?>
                                            <div class="small muted">Ref: <?= e($refund['provider_reference']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span>
                                <?php if ($isCancelled && !empty($ord['cancel_reason'])): ?>
                                    <div class="small muted" style="margin-top:0.25rem;">Reason: <?= e($ord['cancel_reason']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                                <?php if (!empty($ord['tracking_notes'])): ?>
                                    <div class="small muted" style="margin-top:0.2rem;"><?= e($ord['tracking_notes']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div style="display:flex; flex-direction:column; gap:0.35rem;">
                                    <!-- 1. Pay with M-Pesa (if unpaid and not cancelled) -->
                                    <?php if ($ord['payment_status'] === 'unpaid' && !$isCancelled && ($role === 'buyer' || $role === 'admin')): ?>
                                        <button type="button" class="btn btn-amber btn-sm btn-mpesa-pay"
                                                data-order-id="<?= $oid ?>"
                                                data-order-code="<?= e($ord['order_code']) ?>"
                                                data-amount="<?= (float) $ord['total_amount'] ?>"
                                                data-phone="<?= e($ord['buyer_phone']) ?>">
                                            📱 Pay via M-Pesa
                                        </button>
                                    <?php endif; ?>

                                    <!-- 2. Cancel Order (Buyer: if pending/confirmed; Seller/Admin: if not cancelled/delivered) -->
                                    <?php if (!$isCancelled && !$isDelivered && (
                                        ($role === 'buyer' && in_array($ord['order_status'], ['pending', 'confirmed'], true)) ||
                                        in_array($role, ['seller', 'admin'], true)
                                    )): ?>
                                        <button type="button" class="btn btn-danger btn-sm btn-open-cancel"
                                                data-order-id="<?= $oid ?>"
                                                data-order-code="<?= e($ord['order_code']) ?>"
                                                data-paid="<?= $ord['payment_status'] === 'paid' ? '1' : '0' ?>"
                                                data-amount="<?= format_ksh($ord['total_amount']) ?>"
                                                data-item-count="<?= count($lineItems) ?>">
                                            Cancel Order
                                        </button>
                                    <?php endif; ?>

                                    <!-- 3. Process Refund (Admin / Seller for paid cancelled orders) -->
                                    <?php if (in_array($role, ['seller', 'admin'], true) && $refund && in_array($refund['status'], ['requested', 'approved', 'processing'], true)): ?>
                                        <button type="button" class="btn btn-outline btn-sm btn-open-refund"
                                                data-refund-id="<?= (int) $refund['id'] ?>"
                                                data-order-code="<?= e($ord['order_code']) ?>"
                                                data-amount="<?= format_ksh($refund['amount']) ?>"
                                                data-reason="<?= e($refund['reason']) ?>">
                                            Process Refund
                                        </button>
                                    <?php endif; ?>

                                    <!-- 4. Update Status (Seller / Admin) -->
                                    <?php if (in_array($role, ['seller', 'admin'], true) && !$isCancelled): ?>
                                        <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="display:flex; gap:0.2rem;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                                            <select name="order_status" class="form-control" style="padding:0.25rem 0.35rem; font-size:0.8rem; width:auto;">
                                                <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered'] as $st): ?>
                                                    <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline btn-sm">Set</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL 1: M-Pesa STK Push Live Waiting & Polling Modal -->
<div id="mpesaModal" class="modal-overlay" style="<?= $showMpesaModal ? 'display:flex;' : 'display:none;' ?>">
    <div class="modal-card">
        <div style="background: #00703c; color: #fff; padding: 1.1rem 1.4rem; border-radius: 8px 8px 0 0; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:0.6rem;">
                <span style="font-size:1.5rem;">📲</span>
                <strong style="font-size:1.15rem;">Lipa na M-Pesa Online</strong>
            </div>
            <button type="button" id="closeMpesaModal" style="background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <div style="padding: 1.4rem;">
            <div id="mpesaLoadingView" style="text-align:center;">
                <div class="spinner" style="width:48px; height:48px; border:4px solid #e0e0e0; border-top:4px solid #00703c; border-radius:50%; margin: 0 auto 1rem; animation: spin 1s linear infinite;"></div>
                <h3 id="mpesaModalTitle" style="color: #00703c; margin-bottom: 0.5rem;">STK Push Sent to Your Phone!</h3>
                <p id="mpesaModalMessage" class="muted">
                    Please check your phone screen and enter your <strong>M-Pesa PIN</strong> to approve payment for order
                    <strong id="mpesaOrderCode"><?= $promptOrder ? e($promptOrder['order_code']) : '' ?></strong>.
                </p>
                <div style="margin: 1.25rem 0; padding: 0.85rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;">
                    <div style="font-size: 0.85rem; color: #166534;">
                        Waiting for Safaricom confirmation... <span id="mpesaCountdown">60</span>s
                    </div>
                </div>

                <div style="display:flex; gap:0.6rem; justify-content:center; flex-wrap:wrap;">
                    <button type="button" id="btnCheckMpesaNow" class="btn btn-outline btn-sm">Check Status Now</button>
                    <!-- Developer Presentation Simulation Button -->
                    <button type="button" id="btnSimulateMpesa" class="btn btn-amber btn-sm" title="Simulates customer entering M-Pesa PIN for offline demo">
                        ⚡ Simulate PIN Approval (Demo)
                    </button>
                </div>
            </div>

            <div id="mpesaSuccessView" style="display:none; text-align:center; padding: 1rem 0;">
                <div style="font-size:3rem; color:#16a34a; margin-bottom:0.5rem;">✓</div>
                <h3 style="color:#166534; margin-bottom:0.4rem;">Payment Confirmed!</h3>
                <p class="muted" id="mpesaSuccessText">Your M-Pesa payment was processed successfully.</p>
                <button type="button" class="btn btn-primary" onclick="window.location.href='<?= e(url('pages/buyer/orders.php')) ?>'">View Orders</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: Order Cancellation with Stock Restore Confirmation -->
<div id="cancelModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div style="background: var(--red-crimson, #c62828); color: #fff; padding: 1rem 1.4rem; border-radius: 8px 8px 0 0; display:flex; justify-content:space-between; align-items:center;">
            <strong>Cancel Order &amp; Restore Stock</strong>
            <button type="button" id="closeCancelModal" style="background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="padding: 1.4rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="order_id" id="cancelOrderId" value="">

            <p style="margin-bottom: 1rem;">
                Are you sure you want to cancel order <strong id="cancelOrderCodeText"></strong>?
            </p>

            <div class="alert alert-info" style="margin-bottom: 1rem; font-size:0.88rem;">
                <strong>✓ Stock Restoration:</strong> All <span id="cancelItemCount"></span> product item(s) in this order will be immediately returned to vendor stock.
            </div>

            <div id="cancelRefundNotice" class="alert alert-warning" style="display:none; margin-bottom: 1rem; font-size:0.88rem;">
                <strong>💰 Paid Order Notice:</strong> This order was paid (<span id="cancelAmount"></span>). An automatic refund request will be created upon cancellation.
            </div>

            <div class="form-group">
                <label for="cancel_reason">Reason for Cancellation</label>
                <select name="cancel_reason" id="cancel_reason" class="form-control" required>
                    <option value="Changed my mind">Changed my mind / No longer needed</option>
                    <option value="Ordered incorrect item or quantity">Ordered incorrect item or quantity</option>
                    <option value="Found alternative product or price">Found alternative product or price</option>
                    <option value="Delivery time too long">Delivery time too long</option>
                    <option value="Wrong delivery address provided">Wrong delivery address provided</option>
                    <option value="Vendor unable to fulfill">Vendor unable to fulfill</option>
                    <option value="Other reason">Other reason</option>
                </select>
            </div>

            <div class="form-group">
                <label for="cancel_notes">Additional Details (Optional)</label>
                <textarea name="cancel_notes" id="cancel_notes" class="form-control" rows="2" placeholder="Brief note explaining cancellation"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1.25rem;">
                <button type="button" class="btn btn-outline" id="btnDismissCancel">Keep Order</button>
                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Process Refund (Admin / Seller) -->
<div id="refundModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div style="background: var(--green-dark, #1b5e20); color: #fff; padding: 1rem 1.4rem; border-radius: 8px 8px 0 0; display:flex; justify-content:space-between; align-items:center;">
            <strong>Process Customer Refund</strong>
            <button type="button" id="closeRefundModal" style="background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="padding: 1.4rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="process_refund">
            <input type="hidden" name="refund_id" id="processRefundId" value="">

            <p style="margin-bottom: 0.8rem;">
                Processing refund for order <strong id="refundOrderCode"></strong>:
            </p>

            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 0.8rem; border-radius: 6px; margin-bottom: 1rem;">
                <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem;">
                    <span class="muted">Refund Amount:</span>
                    <strong id="refundAmountText" style="color:var(--green-dark); font-size:1.1rem;"></strong>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span class="muted">Reason:</span>
                    <span id="refundReasonText" class="small"></span>
                </div>
            </div>

            <div class="form-group">
                <label for="refundDecision">Decision</label>
                <select name="decision" id="refundDecision" class="form-control" required>
                    <option value="complete">Approve &amp; Mark Completed (Paid back to customer)</option>
                    <option value="reject">Reject Refund Request</option>
                </select>
            </div>

            <div class="form-group" id="refundRefGroup">
                <label for="provider_reference">M-Pesa Reversal / Transaction Code (Optional)</label>
                <input type="text" name="provider_reference" id="provider_reference" class="form-control" placeholder="e.g. QGH91823XX or B2C Ref">
            </div>

            <div class="form-group">
                <label for="refund_notes">Resolution Note (Optional)</label>
                <textarea name="notes" id="refund_notes" class="form-control" rows="2" placeholder="e.g. Reversed via M-Pesa on 16 Nov"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1.25rem;">
                <button type="button" class="btn btn-outline" id="btnDismissRefund">Close</button>
                <button type="submit" class="btn btn-primary">Save Refund Decision</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}
.modal-card {
    background: #fff;
    border-radius: 8px;
    max-width: 480px;
    width: 100%;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    animation: modalIn 0.2s ease-out;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let currentPollingOrderId = <?= (int) $promptOrderId ?>;
    let currentPollingCrid = '<?= e($promptCrid) ?>';
    let pollInterval = null;
    let countdownSec = 60;

    const mpesaModal = document.getElementById('mpesaModal');
    const closeMpesaModal = document.getElementById('closeMpesaModal');
    const mpesaLoadingView = document.getElementById('mpesaLoadingView');
    const mpesaSuccessView = document.getElementById('mpesaSuccessView');
    const countdownEl = document.getElementById('mpesaCountdown');
    const btnCheckNow = document.getElementById('btnCheckMpesaNow');
    const btnSimulate = document.getElementById('btnSimulateMpesa');

    // 1. M-Pesa Status Polling
    function startMpesaPolling(orderId, crid, orderCode) {
        currentPollingOrderId = orderId;
        currentPollingCrid = crid || '';
        countdownSec = 60;
        if (countdownEl) countdownEl.textContent = countdownSec;

        document.getElementById('mpesaOrderCode').textContent = orderCode || ('#' + orderId);
        mpesaLoadingView.style.display = 'block';
        mpesaSuccessView.style.display = 'none';
        mpesaModal.style.display = 'flex';

        if (pollInterval) clearInterval(pollInterval);

        pollInterval = setInterval(() => {
            countdownSec--;
            if (countdownEl) countdownEl.textContent = Math.max(0, countdownSec);

            if (countdownSec % 3 === 0) {
                checkPaymentStatus();
            }

            if (countdownSec <= 0) {
                clearInterval(pollInterval);
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
                mpesaLoadingView.style.display = 'none';
                mpesaSuccessView.style.display = 'block';
                document.getElementById('mpesaSuccessText').textContent = 'M-Pesa payment confirmed (Receipt #' + (data.receipt || 'OK') + ')!';

                // Update row in table dynamically
                const row = document.getElementById('order-row-' + currentPollingOrderId);
                if (row) {
                    const badge = row.querySelector('.payment-status-badge');
                    if (badge) {
                        badge.className = 'badge badge-success payment-status-badge';
                        badge.textContent = 'Paid';
                    }
                    const payBtn = row.querySelector('.btn-mpesa-pay');
                    if (payBtn) payBtn.remove();
                }

                setTimeout(() => {
                    mpesaModal.style.display = 'none';
                }, 2500);
            } else if (data.status === 'failed' || data.status === 'cancelled') {
                if (pollInterval) clearInterval(pollInterval);
                document.getElementById('mpesaModalTitle').textContent = 'Payment ' + (data.status === 'cancelled' ? 'Cancelled' : 'Failed');
                document.getElementById('mpesaModalTitle').style.color = '#c62828';
                document.getElementById('mpesaModalMessage').textContent = data.message;
            }
        } catch (e) {
            console.error('M-Pesa status query error:', e);
        }
    }

    if (btnCheckNow) {
        btnCheckNow.addEventListener('click', checkPaymentStatus);
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

    if (closeMpesaModal) {
        closeMpesaModal.addEventListener('click', () => {
            if (pollInterval) clearInterval(pollInterval);
            mpesaModal.style.display = 'none';
        });
    }

    // Auto-start polling if modal requested by URL query
    <?php if ($showMpesaModal): ?>
    startMpesaPolling(<?= (int) $promptOrderId ?>, '<?= e($promptCrid) ?>', '<?= $promptOrder ? e($promptOrder['order_code']) : '' ?>');
    <?php endif; ?>

    // Pay via M-Pesa button triggers
    document.querySelectorAll('.btn-mpesa-pay').forEach((btn) => {
        btn.addEventListener('click', () => {
            const oid = btn.getAttribute('data-order-id');
            const code = btn.getAttribute('data-order-code');
            const phone = btn.getAttribute('data-phone');
            const newPhone = prompt('Enter M-Pesa mobile number to receive STK push:', phone || '07');
            if (newPhone) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = '<?= e(url('pages/buyer/orders.php')) ?>';
                f.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="pay_mpesa">
                    <input type="hidden" name="order_id" value="${oid}">
                    <input type="hidden" name="mpesa_phone" value="${newPhone}">
                `;
                document.body.appendChild(f);
                f.submit();
            }
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
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
