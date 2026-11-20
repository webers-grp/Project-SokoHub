<?php
/**
 * SokoHub Orders Management, Visual Tracking & Refunds Engine
 *
 * Implements:
 * - Visual order tracking milestone stepper (Pending -> Confirmed -> Processing -> Out for Delivery -> Delivered)
 * - Order status history audit timeline
 * - Assigned delivery rider contact card
 * - Payment status display (M-Pesa paid with receipt, Pending COD, Cash Collected, Failed, Refunded)
 * - Customer refund requests for delivered orders with admin processing
 * - M-Pesa Retry Payment trigger with interactive STK Push polling modal
 * - Order cancellation with inventory restock and payout cancellation
 * - Role-based status updates (Seller / Admin) with automated delivery payout trigger
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';
require_once __DIR__ . '/../../includes/orders.php';
require_once __DIR__ . '/../../includes/telephony.php';
require_once __DIR__ . '/../../includes/audit.php';

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
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);

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
        $res = cancel_order($pdo, $orderId, $userId, $role, 'Customer cancelled order');
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', $res['message']);
        }
        redirect('pages/buyer/orders.php');
    }

    // 3. Request Refund (Buyer)
    if ($action === 'request_refund') {
        $amount = (float) ($_POST['refund_amount'] ?? 0);
        $reason = trim((string) ($_POST['refund_reason'] ?? ''));

        if (mb_strlen($reason) < 5) {
            set_flash('error', 'Please provide a clear reason for your refund request.');
            redirect('pages/buyer/orders.php');
        }

        $res = request_order_refund($pdo, $orderId, $amount, $reason, $userId);
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', $res['message']);
        }
        redirect('pages/buyer/orders.php');
    }

    // 4. Update Order Status (Seller / Admin)
    if ($action === 'update_status' && in_array($role, ['seller', 'admin'], true)) {
        $newStatus = (string) ($_POST['order_status'] ?? '');
        $statusNotes = trim((string) ($_POST['status_notes'] ?? ''));

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

        // Fetch prev status
        $prevSt = $pdo->query("SELECT order_status FROM orders WHERE id = {$orderId}")->fetchColumn();

        $pdo->prepare('UPDATE orders SET order_status = :st, updated_at = NOW() WHERE id = :id')->execute([
            ':st' => $newStatus,
            ':id' => $orderId,
        ]);

        // Record history
        record_order_status_history($pdo, $orderId, $newStatus, $prevSt, $userId, null, $statusNotes ?: 'Status updated to ' . $newStatus);

        // If delivered, automatically trigger payouts to seller and rider
        if ($newStatus === 'delivered') {
            mpesa_trigger_order_delivery_payouts($pdo, $orderId);
            $pdo->prepare("UPDATE deliveries SET delivery_status = 'delivered', delivered_at = NOW() WHERE order_id = :oid")->execute([':oid' => $orderId]);
        } elseif ($newStatus === 'out_for_delivery') {
            $pdo->prepare("UPDATE deliveries SET delivery_status = 'in_transit' WHERE order_id = :oid")->execute([':oid' => $orderId]);
        }

        set_flash('success', 'Order status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.');
        redirect('pages/buyer/orders.php');
    }
}

// Fetch orders according to role
$baseOrderSql = '
    SELECT o.*, 
           o.order_status AS status,
           u.full_name AS buyer_name, 
           u.phone AS buyer_phone,
           d.delivery_status, 
           d.tracking_notes, 
           d.cash_collected,
           d.delivery_person_id,
           ra.full_name AS rider_name,
           ra.phone AS rider_phone,
           dp.transport_method AS rider_transport,
           COALESCE(dp.vehicle_plate, dp.license_number) AS rider_plate,
           p.mpesa_receipt, 
           p.status AS mpesa_status, 
           p.checkout_request_id,
           r.status AS refund_status,
           r.amount AS refund_amount,
           r.reason AS refund_reason
    FROM orders o
    INNER JOIN users u ON u.id = o.buyer_id
    LEFT JOIN deliveries d ON d.order_id = o.id
    LEFT JOIN users ra ON ra.id = d.delivery_person_id
    LEFT JOIN delivery_profiles dp ON dp.user_id = d.delivery_person_id
    LEFT JOIN payments p ON p.id = (SELECT id FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1)
    LEFT JOIN refunds r ON r.id = (SELECT id FROM refunds WHERE order_id = o.id ORDER BY id DESC LIMIT 1)
';

if ($role === 'buyer') {
    $stmt = $pdo->prepare($baseOrderSql . ' WHERE o.buyer_id = :uid ORDER BY o.created_at DESC');
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'seller') {
    $stmt = $pdo->prepare($baseOrderSql . ' INNER JOIN order_items oi ON oi.order_id = o.id WHERE oi.seller_id = :uid GROUP BY o.id ORDER BY o.created_at DESC');
    $stmt->execute([':uid' => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $orders = $pdo->query($baseOrderSql . ' ORDER BY o.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

// Preload line items per order
$itemsByOrder = [];
$historyByOrder = [];
if (!empty($orders)) {
    $oIds = array_map(fn($r) => (int) $r['id'], $orders);
    $ph = implode(',', array_fill(0, count($oIds), '?'));

    $itStmt = $pdo->prepare(
        "SELECT oi.*, p.name AS product_name, p.image_path
         FROM order_items oi
         INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ({$ph})"
    );
    $itStmt->execute($oIds);
    foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsByOrder[(int) $it['order_id']][] = $it;
    }

    $hStmt = $pdo->prepare(
        "SELECT osh.*, u.full_name AS actor_name
         FROM order_status_history osh
         LEFT JOIN users u ON u.id = osh.changed_by
         WHERE osh.order_id IN ({$ph})
         ORDER BY osh.created_at ASC"
    );
    $hStmt->execute($oIds);
    foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $historyByOrder[(int) $h['order_id']][] = $h;
    }
}

$pageTitle = 'SokoHub | Orders & Tracking';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space" style="max-width: 1200px;">
    <div class="page-head">
        <div>
            <h1>Orders &amp; Visual Delivery Tracking</h1>
            <p class="muted">Monitor order progress milestones, Daraja M-Pesa receipts, and delivery rider assignments.</p>
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
        <div class="card" style="text-align: center; padding: 3rem 1rem;">
            <div style="margin-bottom: 0.5rem; color: #94a3b8; display: flex; justify-content: center;"><?= icon('package-search', 'lucide-xl') ?></div>
            <h3 style="font-size: 1.2rem; color: #0f172a; margin: 0 0 0.5rem;">No Orders Found</h3>
            <p class="muted" style="margin: 0 0 1.25rem;">You haven't placed any marketplace orders yet.</p>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">Start Shopping</a>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            <?php foreach ($orders as $ord): ?>
                <?php
                $oid = (int) $ord['id'];
                $st = $ord['order_status'] ?? ($ord['status'] ?? 'pending');
                $lineItems = $itemsByOrder[$oid] ?? [];
                $history = $historyByOrder[$oid] ?? [];
                $isPaid = ($ord['payment_status'] === 'paid');
                $isCod = ($ord['payment_method'] === 'Cash on Delivery');
                $isCodPending = ($ord['payment_status'] === 'pending_cod');

                // Visual Stepper Milestone Determination
                $steps = [
                    'pending'          => ['label' => 'Order Placed',     'icon' => 'file-text'],
                    'confirmed'        => ['label' => 'Confirmed',        'icon' => 'check-circle-2'],
                    'processing'       => ['label' => 'Packaging',        'icon' => 'package'],
                    'out_for_delivery' => ['label' => 'Out for Delivery', 'icon' => 'truck'],
                    'delivered'        => ['label' => 'Delivered',        'icon' => 'party-popper'],
                ];

                $stepKeys = array_keys($steps);
                $isCancelled = in_array($st, ['cancelled', 'refunded'], true);
                $currentIdx = array_search($st, $stepKeys, true);
                if ($currentIdx === false) {
                    $currentIdx = 0;
                }
                ?>
                <div class="card" style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <!-- Order Top Banner -->
                    <div style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <strong style="font-size: 1.15rem; color: #0f172a;"><?= e($ord['order_code']) ?></strong>
                                <span style="font-size: 0.8rem; color: #64748b;">
                                    &bull; Placed <?= e(date('d M Y, H:i', strtotime($ord['created_at']))) ?>
                                </span>
                            </div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                Recipient: <strong><?= e($ord['buyer_name']) ?></strong> &bull; <?= e($ord['delivery_town']) ?>, <?= e($ord['delivery_address']) ?>
                            </div>
                        </div>

                        <div style="text-align: right;">
                            <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a;">
                                <?= format_ksh($ord['total_amount']) ?>
                            </div>
                            <div style="font-size: 0.8rem;">
                                <?php if ($isPaid): ?>
                                    <span style="color: #15803d; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><?= icon('check', 'lucide-xs') ?> <?= $isCod ? 'Cash Collected' : 'Paid via M-Pesa' ?></span>
                                    <?php if (!empty($ord['mpesa_receipt'])): ?>
                                        <span style="font-family: monospace; color: #047857;">(<?= e($ord['mpesa_receipt']) ?>)</span>
                                    <?php endif; ?>
                                <?php elseif ($isCodPending): ?>
                                    <span style="color: #b45309; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><?= icon('clock', 'lucide-xs') ?> Pending (Pay Cash on Delivery)</span>
                                <?php elseif ($isCancelled): ?>
                                    <span style="color: #b91c1c; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><?= icon('x', 'lucide-xs') ?> <?= ucfirst($st) ?></span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><?= icon('x', 'lucide-xs') ?> Unpaid</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Visual Milestone Stepper -->
                    <div style="padding: 1.5rem; border-bottom: 1px solid #f1f5f9; background: white;">
                        <h4 style="font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin: 0 0 1rem; letter-spacing: 0.5px;">
                            Delivery Tracking Progress
                        </h4>

                        <?php if ($isCancelled): ?>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.9rem; font-weight: 600;">
                                This order was cancelled / refunded. Stock inventory has been restored.
                            </div>
                        <?php else: ?>
                            <div style="display: flex; justify-content: space-between; position: relative; margin: 1rem 0;">
                                <!-- Connecting track line -->
                                <div style="position: absolute; top: 18px; left: 20px; right: 20px; height: 4px; background: #e2e8f0; z-index: 1;"></div>
                                <div style="position: absolute; top: 18px; left: 20px; width: <?= ($currentIdx / (count($steps) - 1)) * 100 ?>%; height: 4px; background: #16a34a; z-index: 2; transition: width 0.3s;"></div>

                                <?php foreach ($stepKeys as $idx => $sKey): ?>
                                    <?php
                                    $isPassed = ($idx <= $currentIdx);
                                    $isCurrent = ($idx === $currentIdx);
                                    ?>
                                    <div style="display: flex; flex-direction: column; align-items: center; position: relative; z-index: 3; width: 80px; text-align: center;">
                                        <div style="width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; font-weight: 700; background: <?= $isPassed ? '#16a34a' : '#f8fafc' ?>; color: <?= $isPassed ? 'white' : '#94a3b8' ?>; border: 3px solid <?= $isCurrent ? '#86efac' : ($isPassed ? '#16a34a' : '#cbd5e1') ?>; box-shadow: <?= $isCurrent ? '0 0 0 4px rgba(22, 163, 74, 0.2)' : 'none' ?>;">
                                            <?= icon($steps[$sKey]['icon'], 'lucide-sm', $isPassed ? 'white' : 'currentColor') ?>
                                        </div>
                                        <span style="font-size: 0.75rem; margin-top: 0.5rem; font-weight: <?= $isCurrent ? '700' : '600' ?>; color: <?= $isCurrent ? '#0f172a' : ($isPassed ? '#15803d' : '#94a3b8') ?>;">
                                            <?= e($steps[$sKey]['label']) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Order Details & Actions Grid -->
                    <div style="padding: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; background: #ffffff;">
                        <!-- Line Items -->
                        <div>
                            <h4 style="font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin: 0 0 0.75rem;">
                                Order Items (<?= count($lineItems) ?>)
                            </h4>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach ($lineItems as $li): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.4rem;">
                                        <div>
                                            <strong><?= e($li['product_name']) ?></strong>
                                            <span style="color: #64748b;"> &times; <?= (int) $li['quantity'] ?></span>
                                        </div>
                                        <div style="font-weight: 600; color: #0f172a;">
                                            <?= format_ksh($li['line_total']) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Delivery Rider Card -->
                        <div>
                            <h4 style="font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin: 0 0 0.75rem;">
                                Delivery Dispatch Details
                            </h4>
                            <?php if (!empty($ord['rider_name'])): ?>
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; font-size: 0.85rem;">
                                    <div style="font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 6px;">
                                        <?= icon('bike', 'lucide-xs') ?> Rider: <?= e($ord['rider_name']) ?>
                                    </div>
                                    <div style="color: #334155; margin-bottom: 0.25rem;">
                                        Phone: <a href="tel:<?= e($ord['rider_phone']) ?>" style="color: #16a34a; font-weight: 600; text-decoration: none;"><?= e($ord['rider_phone']) ?></a>
                                    </div>
                                    <?php if (!empty($ord['rider_plate'])): ?>
                                        <div style="color: #64748b; font-size: 0.8rem;">
                                            Vehicle: <?= e(ucfirst($ord['rider_transport'] ?? 'Motorbike')) ?> &bull; Plate: <strong><?= e($ord['rider_plate']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 1rem; font-size: 0.85rem; color: #64748b;">
                                    Awaiting delivery rider dispatch assignment. Local dispatch riders will be notified.
                                </div>
                            <?php endif; ?>

                            <!-- Refund Info if requested -->
                            <?php if (!empty($ord['refund_status'])): ?>
                                <div style="margin-top: 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 0.75rem; font-size: 0.8rem;">
                                    <strong style="color: #b45309;">Refund Status: <?= ucfirst($ord['refund_status']) ?></strong>
                                    <div style="color: #334155; margin-top: 0.25rem;">
                                        Amount: KES <?= number_format((float) $ord['refund_amount'], 2) ?> &bull; Reason: <?= e($ord['refund_reason']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; justify-content: flex-start;">
                            <h4 style="font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin: 0 0 0.75rem;">
                                Actions
                            </h4>

                            <!-- Retry M-Pesa -->
                            <?php if ($ord['payment_method'] === 'M-Pesa' && !$isPaid && !$isCancelled && ($role === 'buyer' || $role === 'admin')): ?>
                                <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="retry_payment">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <input type="hidden" name="retry_phone" value="<?= e($user['phone']) ?>">
                                    <button type="submit" class="btn btn-amber btn-sm" style="width: 100%;">
                                        <?= icon('refresh-cw', 'lucide-xs', 'white') ?>
                                        <span>Retry M-Pesa STK Push</span>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Cancel Order (Pending only) -->
                            <?php if ($st === 'pending' && ($role === 'buyer' || $role === 'admin')): ?>
                                <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" onsubmit="return confirm('Cancel this order?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; color: #ef4444; border-color: #ef4444;">
                                        Cancel Order
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Request Refund (Delivered orders) -->
                            <?php if ($st === 'delivered' && empty($ord['refund_status']) && ($role === 'buyer' || $role === 'admin')): ?>
                                <details style="position: relative;">
                                    <summary class="btn btn-outline btn-sm" style="width: 100%; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                        <?= icon('corner-up-left', 'lucide-xs') ?> <span>Request Refund</span>
                                    </summary>
                                    <div style="background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); margin-top: 0.5rem;">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="request_refund">
                                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                                            <input type="hidden" name="refund_amount" value="<?= (float) $ord['total_amount'] ?>">

                                            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;">
                                                Reason for Refund:
                                            </label>
                                            <textarea name="refund_reason" rows="2" placeholder="Item damaged, wrong item delivered, etc." class="form-control" style="font-size: 0.8rem; width: 100%; margin-bottom: 0.5rem;" required minlength="5"></textarea>

                                            <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; font-size: 0.75rem;">
                                                Submit Request
                                            </button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>

                            <!-- Seller/Admin Status Update -->
                            <?php if (in_array($role, ['seller', 'admin'], true) && !$isCancelled): ?>
                                <form action="<?= e(url('pages/buyer/orders.php')) ?>" method="POST" style="margin-top: 0.5rem;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">

                                    <label style="display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 0.2rem;">
                                        Update Fulfillment Status:
                                    </label>
                                    <div style="display: flex; gap: 0.25rem;">
                                        <select name="order_status" class="form-control" style="font-size: 0.8rem; padding: 0.25rem;">
                                            <?php foreach (['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered'] as $stepName): ?>
                                                <option value="<?= $stepName ?>" <?= $st === $stepName ? 'selected' : '' ?>>
                                                    <?= ucfirst(str_replace('_', ' ', $stepName)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Expandable Status History Timeline -->
                    <?php if (!empty($history)): ?>
                        <details style="border-top: 1px solid #f1f5f9; background: #fafafa; padding: 0.75rem 1.5rem;">
                            <summary style="font-size: 0.8rem; font-weight: 600; color: #64748b; cursor: pointer;">
                                View Complete Status Audit Timeline (<?= count($history) ?> events)
                            </summary>
                            <div style="margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.4rem;">
                                <?php foreach ($history as $h): ?>
                                    <div style="font-size: 0.75rem; color: #334155; display: flex; gap: 0.5rem; align-items: baseline;">
                                        <span style="color: #94a3b8; font-family: monospace;"><?= e(date('H:i d/m', strtotime($h['created_at']))) ?></span>
                                        <strong><?= e(ucfirst(str_replace('_', ' ', $h['to_status']))) ?>:</strong>
                                        <span><?= e($h['notes'] ?: 'No notes') ?></span>
                                        <?php if (!empty($h['actor_name'])): ?>
                                            <span style="color: #94a3b8;">(by <?= e($h['actor_name']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
