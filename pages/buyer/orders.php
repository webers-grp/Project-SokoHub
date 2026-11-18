<?php
/**
 * SokoHub Orders Management & Payment Tracking
 *
 * Implements:
 * - Payment status display (M-Pesa paid with receipt, Pending COD, Cash Collected, Failed)
 * - M-Pesa Retry Payment trigger with interactive STK Push polling modal
 * - Order cancellation with inventory restock
 * - Role-based status updates (Seller / Admin) with automated delivery payout trigger
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_login();
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];
$stkModal = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('pages/buyer/orders.php');
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    // 1. Retry M-Pesa Payment
    if ($action === 'retry_payment') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $ord = $stmt->fetch();

        if (!$ord || ($role !== 'admin' && (int) $ord['buyer_id'] !== $userId)) {
            set_flash('error', 'Order not found or unauthorized.');
            redirect('pages/buyer/orders.php');
        }

        if ($ord['payment_status'] === 'paid') {
            set_flash('info', 'This order is already marked as paid.');
            redirect('pages/buyer/orders.php');
        }

        $phone = trim((string) ($_POST['retry_phone'] ?? $user['phone']));
        $normPhone = mpesa_normalize_phone($phone);

        if ($normPhone === '') {
            set_flash('error', 'Please provide a valid Kenyan M-Pesa phone number.');
            redirect('pages/buyer/orders.php');
        }

        $stkRes = mpesa_initiate_stk_push($pdo, $orderId, $normPhone, (float) $ord['total_amount']);
        if ($stkRes['ok']) {
            $stkModal = [
                'order_id'            => $orderId,
                'order_code'          => $ord['order_code'],
                'amount'              => (float) $ord['total_amount'],
                'phone'               => $normPhone,
                'checkout_request_id' => $stkRes['checkout_request_id'],
                'message'             => $stkRes['message'],
            ];
        } else {
            set_flash('error', 'STK Push failed: ' . $stkRes['message']);
            redirect('pages/buyer/orders.php');
        }
    }

    // 2. Cancel Order
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
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled', cancel_reason = 'Cancelled by customer', cancelled_at = NOW() WHERE id = :id")
                ->execute([':id' => $orderId]);
            $pdo->prepare("UPDATE deliveries SET delivery_status = 'failed', tracking_notes = 'Cancelled by customer' WHERE order_id = :id")
                ->execute([':id' => $orderId]);

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

    // 3. Update Order Status (Seller / Admin)
    if ($action === 'update_status' && in_array($role, ['seller', 'admin'], true)) {
        $newStatus = (string) ($_POST['order_status'] ?? '');
        if (!in_array($newStatus, ['pending', 'confirmed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'cancelled'], true)) {
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

        // If delivered, automatically trigger payouts to seller and rider
        if ($newStatus === 'delivered') {
            mpesa_trigger_order_delivery_payouts($pdo, $orderId);
        }

        set_flash('success', 'Order status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.');
        redirect('pages/buyer/orders.php');
    }
}

// Fetch orders according to role
if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes, d.cash_collected,
                p.mpesa_receipt, p.status AS mpesa_status, p.checkout_request_id
         FROM orders o
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         LEFT JOIN payments p ON p.id = (SELECT id FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1)
         WHERE o.buyer_id = :uid
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} elseif ($role === 'seller') {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes, d.cash_collected,
                p.mpesa_receipt, p.status AS mpesa_status, p.checkout_request_id
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         LEFT JOIN payments p ON p.id = (SELECT id FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1)
         WHERE oi.seller_id = :uid
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll();
} else {
    $orders = $pdo->query(
        'SELECT o.*, u.full_name AS buyer_name, d.delivery_status, d.tracking_notes, d.cash_collected,
                p.mpesa_receipt, p.status AS mpesa_status, p.checkout_request_id
         FROM orders o
         INNER JOIN users u ON u.id = o.buyer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         LEFT JOIN payments p ON p.id = (SELECT id FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1)
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

$pageTitle = 'SokoHub | Orders & Payments';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Orders &amp; Payment History</h1>
            <p class="muted">Monitor customer orders, Daraja M-Pesa receipts, and fulfillment milestones.</p>
        </div>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
            <?= icon('shopping-bag', 'lucide-sm', 'white') ?>
            <span>Shop Marketplace</span>
        </a>
    </div>

    <?php if ($stkModal !== null): ?>
        <!-- Interactive STK Push Polling Card -->
        <div class="card" style="max-width: 600px; margin: 0 auto 2rem; text-align: center; border: 2px solid var(--primary);">
            <span class="badge badge-warning" id="modalBadge">Awaiting Phone PIN...</span>
            <h2 style="margin-top: 0.5rem;">STK Push Sent</h2>
            <p class="muted" id="modalMsg" style="margin-top: 0.35rem;">
                Prompt for <strong><?= format_ksh($stkModal['amount']) ?></strong> sent to <strong><?= e($stkModal['phone']) ?></strong>.
            </p>
            <div style="margin: 1.25rem 0;" id="spinnerWrap">
                <div class="spinner" style="width: 24px; height: 24px; border: 3px solid #cbd5e1; border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto;"></div>
            </div>

            <?php if (get_mpesa_config()['allow_simulation']): ?>
                <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 6px; margin: 1rem 0;">
                    <span class="small muted" style="font-weight: 700;">Sandbox Simulation:</span>
                    <button type="button" class="btn btn-primary btn-sm" onclick="simCallback('success')">Simulate PIN Success</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="simCallback('cancel')">Simulate Cancel</button>
                </div>
            <?php endif; ?>
        </div>

        <script>
        const chkId = <?= json_encode($stkModal['checkout_request_id']) ?>;
        const oId = <?= (int) $stkModal['order_id'] ?>;
        const statUrl = <?= json_encode(url('pages/api/mpesa_status.php')) ?>;
        const simUrl = <?= json_encode(url('pages/api/mpesa_simulate.php')) ?>;

        const pInt = setInterval(async () => {
            try {
                const res = await fetch(`${statUrl}?checkout_request_id=${encodeURIComponent(chkId)}&order_id=${oId}`);
                const data = await res.json();
                if (data.status === 'success') {
                    clearInterval(pInt);
                    document.getElementById('modalBadge').className = 'badge badge-success';
                    document.getElementById('modalBadge').textContent = 'Paid!';
                    document.getElementById('modalMsg').innerHTML = `<strong style="color: #059669;">Payment Confirmed!</strong> Receipt: <strong>${data.mpesa_receipt || ''}</strong>`;
                    setTimeout(() => window.location.href = window.location.pathname, 1500);
                } else if (['failed', 'cancelled', 'timeout'].includes(data.status)) {
                    clearInterval(pInt);
                    document.getElementById('modalBadge').className = 'badge badge-danger';
                    document.getElementById('modalBadge').textContent = data.status.toUpperCase();
                    document.getElementById('modalMsg').textContent = data.message;
                }
            } catch(e) {}
        }, 2500);

        async function simCallback(act) {
            await fetch(simUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `checkout_request_id=${encodeURIComponent(chkId)}&action=${act}`
            });
        }
        </script>
        <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="card">
            <p class="muted">No orders found.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order Details</th>
                            <th>Buyer &amp; Address</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment &amp; Receipt</th>
                            <th>Status</th>
                            <th>Delivery</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $ord): ?>
                        <?php
                        $oBadge = get_status_badge($ord['order_status']);
                        $dBadge = get_status_badge($ord['delivery_status'] ?? 'pending');
                        $lineItems = $itemsByOrder[(int) $ord['id']] ?? [];
                        $isMpesa = ($ord['payment_method'] === 'M-Pesa');
                        $isPaid = ($ord['payment_status'] === 'paid');
                        $isCod = ($ord['payment_method'] === 'Cash on Delivery');
                        $isCodPending = ($ord['payment_status'] === 'pending_cod');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($ord['order_code']) ?></strong>
                                <div class="small muted"><?= e(date('d M Y, H:i', strtotime($ord['created_at']))) ?></div>
                            </td>
                            <td>
                                <strong><?= e($ord['buyer_name']) ?></strong>
                                <div class="small muted"><?= e($ord['delivery_town']) ?> &bull; <?= e($ord['delivery_address']) ?></div>
                            </td>
                            <td>
                                <?php foreach ($lineItems as $li): ?>
                                    <div class="small"><?= e($li['product_name']) ?> &times; <?= (int) $li['quantity'] ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <strong><?= format_ksh($ord['total_amount']) ?></strong>
                            </td>
                            <td>
                                <div style="margin-bottom: 0.25rem;">
                                    <strong><?= e($ord['payment_method']) ?></strong>
                                </div>
                                <?php if ($isPaid): ?>
                                    <span class="badge badge-success">
                                        <?= icon('check-circle', 'lucide-xs', 'emerald') ?>
                                        <span><?= $isCod ? 'Cash Collected' : 'Paid via M-Pesa' ?></span>
                                    </span>
                                    <?php if (!empty($ord['mpesa_receipt'])): ?>
                                        <div class="small" style="font-family: monospace; font-weight: 700; color: #047857; margin-top: 0.2rem;">
                                            Receipt: <?= e($ord['mpesa_receipt']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($isCodPending): ?>
                                    <span class="badge badge-warning">
                                        <?= icon('clock', 'lucide-xs', 'amber') ?>
                                        <span>Pending (COD)</span>
                                    </span>
                                    <div class="small muted" style="margin-top: 0.2rem;">Pay rider upon arrival</div>
                                <?php else: ?>
                                    <span class="badge badge-danger">
                                        <?= icon('alert-circle', 'lucide-xs', 'rose') ?>
                                        <span>Unpaid / Failed</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                                <?php if (!empty($ord['tracking_notes'])): ?>
                                    <div class="small muted" style="margin-top: 0.15rem;"><?= e($ord['tracking_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Retry M-Pesa Payment Button -->
                                <?php if ($isMpesa && !$isPaid && $ord['order_status'] !== 'cancelled' && ($role === 'buyer' || $role === 'admin')): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin-bottom: 0.35rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="retry_payment">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <input type="hidden" name="retry_phone" value="<?= e($user['phone']) ?>">
                                        <button type="submit" class="btn btn-amber btn-sm">
                                            <?= icon('refresh-cw', 'lucide-xs', 'white') ?>
                                            <span>Retry M-Pesa</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Cancel Order -->
                                <?php if ($ord['order_status'] === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin-bottom:0.35rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Cancel order <?= e($ord['order_code']) ?>?">
                                            <?= icon('x-circle', 'lucide-xs', 'white') ?>
                                            <span>Cancel</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Seller / Admin Status Change -->
                                <?php if (in_array($role, ['seller', 'admin'], true) && $ord['order_status'] !== 'cancelled'): ?>
                                    <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="display:flex; gap:0.25rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <select name="order_status" class="form-control" style="padding:0.2rem 0.35rem; font-size:0.8rem; width:auto;">
                                            <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'out_for_delivery', 'delivered'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline btn-sm">
                                            <?= icon('check', 'lucide-xs', 'emerald') ?>
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
