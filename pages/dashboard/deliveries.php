<?php
/**
 * SokoHub Delivery Dispatch & Tracking Module
 * - Seller-Driven Rider Allocation (Merchants assign vetted riders on standby)
 * - Pure Digital Pay-on-Delivery via Safaricom M-Pesa STK push prompted by rider on arrival
 * - Strictly Cashless Policy: deliveries cannot be completed without confirmed digital payment
 * Authored by Kennedy Kalawa.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/payments.php';
require_once __DIR__ . '/../../includes/telephony.php';
init_session();
$user = require_role(['seller', 'delivery', 'buyer', 'admin']);
$pdo = get_db_connection();
Kyc::ensureSchema();
Payments::ensureSchema();
Telephony::ensureSchema();

$role = $user['role'];
$userId = (int) $user['id'];
$isVerifiedRider = ($role === 'delivery') ? Kyc::isUserApproved($userId, 'delivery') : true;
$isVerifiedSeller = ($role === 'seller') ? Kyc::isSellerApproved($userId) : true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['seller', 'delivery', 'admin'], true)) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('pages/dashboard/deliveries.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);

    // Rider toggles On-Duty vs Off-Duty status
    if ($action === 'toggle_availability' && $role === 'delivery') {
        $nowAvailable = Kyc::toggleRiderAvailability($userId);
        if ($nowAvailable) {
            set_flash('success', 'You are now ON DUTY. Merchants can discover you and assign order deliveries to your vehicle.');
        } else {
            set_flash('info', 'You are now OFF DUTY. You are hidden from merchant dispatch queues until you switch back on duty.');
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // Seller or Admin assigns a verified delivery rider
    if ($action === 'assign_rider' && in_array($role, ['seller', 'admin'], true)) {
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        if ($riderId <= 0) {
            set_flash('error', 'Please choose a verified delivery rider from the available list.');
            redirect('pages/dashboard/deliveries.php');
        }

        $res = Kyc::assignRiderToDelivery($deliveryId, $riderId, $userId, $role);
        if ($res['ok']) {
            $msg = "Order {$res['order_code']} successfully dispatched to rider {$res['rider_desc']}.";
            if (!empty($res['telephony']['dispatched'])) {
                $chs = implode(' & ', array_keys($res['telephony']['channels'] ?? ['sms' => true]));
                $msg .= " (Rider was offline: automated {$chs} dispatch alert sent).";
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', $res['error'] ?? 'Failed to assign rider to delivery.');
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // Step 5: Update offline telephony alert preference (SMS, Call, Both, None)
    if ($action === 'update_telephony_preference') {
        $pref = (string) ($_POST['offline_alert_preference'] ?? 'both');
        Telephony::updateAlertPreference($userId, $pref);
        set_flash('success', 'Offline dispatch alert preference updated successfully.');
        redirect('pages/dashboard/deliveries.php');
    }

    // Step 4: Rider prompts customer's phone for Safaricom M-Pesa STK Push
    if ($action === 'prompt_stk' && in_array($role, ['delivery', 'admin'], true)) {
        $buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
        $res = Payments::initiateStkPush($deliveryId, $userId, !empty($buyerPhone) ? $buyerPhone : null);
        if ($res['ok']) {
            set_flash('success', "M-Pesa STK Push prompted to customer {$res['phone']} for KES " . number_format($res['amount'], 2) . ". Waiting for customer PIN...");
        } else {
            set_flash('error', $res['error'] ?? 'Failed to initiate M-Pesa STK push.');
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // Step 4: Rider confirms M-Pesa payment received digitally
    if ($action === 'confirm_digital_payment' && in_array($role, ['delivery', 'admin'], true)) {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $receipt = trim((string) ($_POST['mpesa_receipt'] ?? ''));
        $res = Payments::confirmDigitalPayment($paymentId, $userId, !empty($receipt) ? $receipt : null);
        if ($res['ok']) {
            set_flash('success', "Digital payment confirmed! Safaricom M-Pesa Ref: {$res['receipt']} (KES " . number_format($res['amount'], 2) . "). Order is paid. Package cleared for handoff.");
        } else {
            set_flash('error', $res['error'] ?? 'Failed to confirm digital payment.');
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // Cancel active STK push prompt
    if ($action === 'cancel_stk' && in_array($role, ['delivery', 'admin'], true)) {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        Payments::cancelPrompt($paymentId, $userId, 'Cancelled by rider');
        set_flash('info', 'M-Pesa STK prompt cancelled. You may re-prompt customer with a new phone number.');
        redirect('pages/dashboard/deliveries.php');
    }

    // Explicitly reject any self-claiming attempt
    if ($action === 'claim') {
        set_flash('error', 'Self-claiming deliveries is disabled. SokoHub operates on merchant-driven rider allocation; sellers choose and assign verified riders directly.');
        redirect('pages/dashboard/deliveries.php');
    }

    // Rider or Admin updates delivery status and tracking notes
    if ($action === 'update' && in_array($role, ['delivery', 'admin'], true)) {
        $newStatus = (string) ($_POST['delivery_status'] ?? '');
        $notes = trim((string) ($_POST['tracking_notes'] ?? ''));

        if (!in_array($newStatus, ['assigned', 'picked_up', 'in_transit', 'delivered', 'failed'], true)) {
            set_flash('error', 'Invalid delivery status.');
            redirect('pages/dashboard/deliveries.php');
        }

        $dStmt = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id LIMIT 1');
        $dStmt->execute([':id' => $deliveryId]);
        $del = $dStmt->fetch();

        if (!$del || ($role !== 'admin' && (int) $del['delivery_person_id'] !== $userId)) {
            set_flash('error', 'You cannot update a delivery not assigned to you.');
            redirect('pages/dashboard/deliveries.php');
        }

        // STEP 4 ENFORCEMENT: STRICTLY NO CASH - Cannot deliver without confirmed digital payment
        if ($newStatus === 'delivered' && !Payments::isDeliveryPaid($deliveryId)) {
            set_flash('error', 'Strictly Cashless Delivery Policy: You cannot mark an order as "Delivered" until digital payment is confirmed via Safaricom M-Pesa STK push. Paper cash is strictly forbidden.');
            redirect('pages/dashboard/deliveries.php');
        }

        $pdo->beginTransaction();
        try {
            $deliveredAtSql = $newStatus === 'delivered' ? ', delivered_at = NOW()' : '';
            $upd = $pdo->prepare(
                "UPDATE deliveries
                 SET delivery_status = :st, tracking_notes = :notes {$deliveredAtSql}
                 WHERE id = :id"
            );
            $upd->execute([':st' => $newStatus, ':notes' => $notes, ':id' => $deliveryId]);

            if ($newStatus === 'delivered') {
                $pdo->prepare("UPDATE orders SET order_status = 'delivered', payment_status = 'paid' WHERE id = :oid")
                    ->execute([':oid' => (int) $del['order_id']]);
            } elseif ($newStatus === 'in_transit') {
                $pdo->prepare("UPDATE orders SET order_status = 'shipped' WHERE id = :oid AND order_status IN ('pending','confirmed','processing')")
                    ->execute([':oid' => (int) $del['order_id']]);
            }

            $pdo->commit();
            set_flash('success', 'Delivery tracking updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', 'Failed to update delivery status: ' . $e->getMessage());
        }
        redirect('pages/dashboard/deliveries.php');
    }
}

// Fetch available riders for sellers & admins
$availableRiders = [];
if (in_array($role, ['seller', 'admin'], true)) {
    $availableRiders = Kyc::getAvailableRiders();
}

// Query deliveries based on user role with digital payments linkage
if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.total_amount, o.payment_status, o.mpesa_receipt,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model,
                p.status AS payment_prompt_status, p.buyer_phone AS payment_phone, p.amount AS payment_amount
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         LEFT JOIN payments p ON p.id = d.digital_payment_id
         WHERE o.buyer_id = :uid
         ORDER BY d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'delivery') {
    // Delivery rider: strictly view assignments dispatched to them (NO unassigned tasks)
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.total_amount, o.payment_status, o.mpesa_receipt,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                s.full_name AS seller_name, s.phone AS seller_phone,
                sp.business_name AS seller_business, sp.town AS seller_town,
                p.id AS active_payment_id, p.status AS payment_prompt_status, p.buyer_phone AS payment_phone,
                p.amount AS payment_amount, p.mpesa_checkout_id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users s ON s.id = d.assigned_by_seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = s.id
         LEFT JOIN payments p ON p.id = d.digital_payment_id
         WHERE d.delivery_person_id = :uid
         ORDER BY FIELD(d.delivery_status, "assigned", "picked_up", "in_transit", "delivered", "failed"), d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'seller') {
    // Seller: orders containing products from this seller
    $stmt = $pdo->prepare(
        'SELECT DISTINCT d.*, o.order_code, o.order_status, o.payment_status, o.total_amount, o.mpesa_receipt,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model,
                p.status AS payment_prompt_status, p.buyer_phone AS payment_phone, p.amount AS payment_amount
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         LEFT JOIN payments p ON p.id = d.digital_payment_id
         WHERE oi.seller_id = :uid
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Admin: full visibility
    $deliveries = $pdo->query(
        'SELECT d.*, o.order_code, o.order_status, o.payment_status, o.total_amount, o.mpesa_receipt,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                s.full_name AS seller_name, sp.business_name AS seller_business,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model,
                p.id AS active_payment_id, p.status AS payment_prompt_status, p.buyer_phone AS payment_phone,
                p.amount AS payment_amount, p.mpesa_checkout_id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users s ON s.id = d.assigned_by_seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = s.id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         LEFT JOIN payments p ON p.id = d.digital_payment_id
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'SokoHub | Delivery Coordination & Cashless Dispatch';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>
                <?= icon('truck', 'lucide-sm', 'amber') ?>
                <span>Delivery Dispatch &amp; Cashless Fulfillment</span>
            </h1>
            <p class="muted">
                <?php if ($role === 'seller'): ?>
                    Manage order dispatch and assign vetted Kirinyaga delivery riders to customer shipments.
                <?php elseif ($role === 'delivery'): ?>
                    Manage assigned deliveries, trigger customer M-Pesa STK prompts on arrival, and toggle your availability.
                <?php elseif ($role === 'buyer'): ?>
                    Track fulfillment progress and prepare to authorize your Safaricom M-Pesa prompt upon rider arrival.
                <?php else: ?>
                    System-wide delivery oversight and digital cashless dispatch coordination.
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if (in_array($role, ['delivery', 'admin'], true)): ?>
                <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="btn btn-amber btn-sm">
                    <?= icon('bar-chart-3', 'lucide-xs') ?>
                    <span>Delivery Performance Report</span>
                </a>
            <?php endif; ?>
            <?php if ($role === 'seller'): ?>
                <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="btn btn-outline btn-sm">
                    <?= icon('receipt', 'lucide-xs') ?>
                    <span>View Orders</span>
                </a>
            <?php endif; ?>
        </div>
<<<<<<< HEAD
        <?php if (in_array($role, ['delivery', 'admin'], true)): ?>
            <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="btn btn-amber">
                <?= icon('file-text', 'lucide-xs', 'white') ?>
                <span>Delivery Performance Report</span>
            </a>
        <?php endif; ?>
=======
>>>>>>> feature/seller-kyc-admin
    </div>

    <!-- Strictly Cashless Banner -->
    <div class="card" style="margin-bottom: 1.5rem; border-left: 5px solid #10b981; background: rgba(16, 185, 129, 0.05); padding: 0.85rem 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <span class="badge badge-success" style="font-size: 0.78rem;">
                    <?= icon('shield-check', 'lucide-xs') ?> STRICTLY CASHLESS
                </span>
                <span style="font-weight: 600; font-size: 0.95rem; color: #065f46;">
                    Pure Digital Pay-on-Delivery (Safaricom M-Pesa STK Push)
                </span>
            </div>
            <div class="small muted">
                No paper cash allowed. Riders prompt customer phones upon arrival. Packages are released once M-Pesa confirms payment.
            </div>
        </div>
    </div>

    <?php if ($role === 'delivery'): 
        $riderProfile = Kyc::getDeliveryProfile($userId);
        $isOnDuty = (int) ($riderProfile['is_available'] ?? 1) === 1;
    ?>
        <?php if (!$isVerifiedRider): 
            $riderStatus = $riderProfile['verification_status'] ?? 'pending';
        ?>
            <div class="card" style="margin-bottom: 1.5rem; border-left: 6px solid #f59e0b; background: var(--bg-surface, #fffbeb);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong style="color: #b45309; font-size: 1rem;">
                            <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                            Rider KYC Verification Required
                        </strong>
                        <p style="margin: 0.25rem 0 0 0; color: #92400e; font-size: 0.9rem;">
                            Your rider account is currently <strong><?= e(ucfirst($riderStatus)) ?></strong>. Upload your Kenyan National ID, Safaricom M-Pesa line, and vehicle number plate so merchants can assign you delivery tasks.
                        </p>
                    </div>
                    <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-amber btn-sm">
                        <?= icon('upload', 'lucide-xs') ?>
                        <span>Complete Rider Verification &rarr;</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Rider On-Duty / Off-Duty Availability Controller -->
            <div class="card" style="margin-bottom: 1.5rem; border-left: 6px solid <?= $isOnDuty ? '#10b981' : '#6b7280' ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="badge <?= $isOnDuty ? 'badge-success' : 'badge-neutral' ?>" style="font-size: 0.85rem; padding: 0.35rem 0.65rem;">
                            <?= $isOnDuty ? '● ON DUTY & AVAILABLE' : '○ OFF DUTY / STANDBY' ?>
                        </span>
                        <?php if (!empty($riderProfile['vehicle_plate_number'])): ?>
                            <span class="badge badge-outline" style="font-family: monospace; font-size: 0.85rem; letter-spacing: 0.05em;">
                                <?= icon('car', 'lucide-xs') ?>
                                <?= e($riderProfile['vehicle_plate_number']) ?> (<?= e(ucfirst($riderProfile['transport_method'] ?? 'Vehicle')) ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="muted small" style="margin: 0.4rem 0 0 0;">
                        <?= $isOnDuty 
                            ? 'Merchants across Kirinyaga County can discover your vehicle and assign customer orders directly to you.' 
                            : 'You are currently hidden from merchant dispatch lists. Turn On Duty when you are ready to receive deliveries.' ?>
                    </p>
                </div>
                <div style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin: 0; display: flex; gap: 0.35rem; align-items: center;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_telephony_preference">
                        <label class="small muted" style="margin: 0; font-size: 0.78rem;">Offline Alerts:</label>
                        <select name="offline_alert_preference" class="form-control" style="padding: 0.2rem 0.4rem; font-size: 0.78rem; width: auto;" onchange="this.form.submit()">
                            <?php $curPref = $riderProfile['offline_alert_preference'] ?? 'both'; ?>
                            <option value="both" <?= $curPref === 'both' ? 'selected' : '' ?>>SMS &amp; Voice Call</option>
                            <option value="sms" <?= $curPref === 'sms' ? 'selected' : '' ?>>SMS Only</option>
                            <option value="call" <?= $curPref === 'call' ? 'selected' : '' ?>>Voice Call Only</option>
                            <option value="none" <?= $curPref === 'none' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </form>
                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_availability">
                        <button type="submit" class="btn <?= $isOnDuty ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                            <?= icon($isOnDuty ? 'pause-circle' : 'play-circle', 'lucide-xs') ?>
                            <span><?= $isOnDuty ? 'Switch to Off Duty' : 'Go On Duty (Receive Deliveries)' ?></span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($role === 'seller'): ?>
        <div class="card" style="margin-bottom: 1.5rem; border-left: 5px solid #3b82f6; background: rgba(59, 130, 246, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h3 style="margin: 0 0 0.25rem 0; font-size: 1.05rem; display: flex; align-items: center; gap: 0.4rem;">
                        <?= icon('user-check', 'lucide-xs') ?>
                        <span>Merchant Dispatch Control</span>
                    </h3>
                    <p class="muted small" style="margin: 0;">
                        SokoHub gives you full control over fulfillment: riders register and wait on standby, and you select the best available rider with verified Kenyan plates for your deliveries.
                    </p>
                </div>
                <div>
                    <span class="badge badge-info" style="font-size: 0.82rem;">
                        <?= count($availableRiders) ?> Verified <?= count($availableRiders) === 1 ? 'Rider' : 'Riders' ?> Available on Duty
                    </span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($deliveries)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem;">
                <p class="muted" style="font-size: 1.05rem; margin-bottom: 0.5rem;">
                    <?= $role === 'delivery' ? 'No delivery assignments dispatched to you yet.' : 'No delivery tasks found.' ?>
                </p>
                <p class="small muted">
                    <?= $role === 'delivery' 
                        ? 'Keep your status On Duty. When merchants assign orders to you, they will appear here with pickup & dropoff coordinates.' 
                        : 'New customer orders will generate dispatch tasks here for merchant allocation.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Route (Pickup &rarr; Dropoff)</th>
                            <th>Amount Payable</th>
                            <th>Assigned Rider</th>
                            <th>M-Pesa Payment Status</th>
                            <th>Delivery Status</th>
                            <th>Tracking Notes</th>
                            <?php if (in_array($role, ['seller', 'delivery', 'admin'], true)): ?>
                                <th>Action &amp; Cashless Handoff</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $del): ?>
                        <?php 
                        $badge = get_status_badge($del['delivery_status']); 
                        $hasRider = !empty($del['delivery_person_id']);
                        $isDelivered = in_array($del['delivery_status'], ['delivered', 'failed'], true);
                        $isPaid = ($del['payment_status'] === 'paid');
                        $isPrompted = ($del['payment_prompt_status'] === 'prompted');
                        $totalPayable = (float) $del['total_amount'] + (float) $del['delivery_fee'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($del['order_code']) ?></strong>
                                <?php if (!empty($del['buyer_name'])): ?>
                                    <div class="small muted">
                                        <?= icon('user', 'lucide-xs') ?>
                                        <span>Buyer: <?= e($del['buyer_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($del['buyer_phone'])): ?>
                                    <div class="small muted">
                                        <?= icon('phone', 'lucide-xs') ?>
                                        <a href="tel:<?= e($del['buyer_phone']) ?>"><?= e($del['buyer_phone']) ?></a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($del['seller_business'])): ?>
                                    <div class="small muted">
                                        <?= icon('store', 'lucide-xs') ?>
                                        <span>Merchant: <?= e($del['seller_business']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong>From:</strong> <?= e($del['pickup_town']) ?></div>
                                <div class="small muted"><strong>To:</strong> <?= e($del['dropoff_address']) ?></div>
                            </td>
<<<<<<< HEAD
                            <td><?= format_ksh($del['delivery_fee']) ?></td>
                            <td><span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></td>
                            <td class="small"><?= e($del['tracking_notes'] ?: '-') ?></td>
                            <?php if (in_array($role, ['delivery', 'admin'], true)): ?>
                                <td>
                                    <?php if ($del['delivery_person_id'] === null && $del['delivery_status'] === 'pending' && $role === 'delivery'): ?>
                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="claim">
                                            <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <?= icon('hand', 'lucide-xs', 'white') ?>
                                                <span>Claim Task</span>
                                            </button>
                                        </form>
                                    <?php elseif ($role === 'admin' || (int) $del['delivery_person_id'] === $userId): ?>
                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display:flex; flex-direction:column; gap:0.35rem; min-width:190px;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                            <select name="delivery_status" class="form-control" style="padding:0.28rem 0.45rem; font-size:0.84rem;">
                                                <?php foreach (['assigned', 'picked_up', 'in_transit', 'delivered', 'failed'] as $st): ?>
                                                    <option value="<?= $st ?>" <?= $del['delivery_status'] === $st ? 'selected' : '' ?>>
                                                        <?= ucfirst(str_replace('_', ' ', $st)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="tracking_notes" class="form-control" style="padding:0.28rem 0.45rem; font-size:0.82rem;" value="<?= e($del['tracking_notes'] ?? '') ?>" placeholder="Update note...">
                                            <button type="submit" class="btn btn-amber btn-sm">
                                                <?= icon('check', 'lucide-xs', 'white') ?>
                                                <span>Update</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small muted">Assigned to another rider</span>
=======
                            <td>
                                <div><strong><?= format_ksh($totalPayable) ?></strong></div>
                                <div class="small muted">Goods: <?= format_ksh($del['total_amount']) ?> + Fee: <?= format_ksh($del['delivery_fee']) ?></div>
                            </td>
                            <td>
                                <?php if ($hasRider): ?>
                                    <div>
                                        <strong><?= e($del['rider_name']) ?></strong>
                                        <?php if (!empty($del['rider_phone'])): ?>
                                            <div class="small muted">
                                                <?= icon('phone', 'lucide-xs') ?>
                                                <a href="tel:<?= e($del['rider_phone']) ?>"><?= e($del['rider_phone']) ?></a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($del['vehicle_plate_number'])): ?>
                                            <span class="badge badge-outline" style="font-family: monospace; font-size: 0.75rem; margin-top: 0.2rem; display: inline-block;">
                                                <?= icon('car', 'lucide-xs') ?>
                                                <?= e($del['vehicle_plate_number']) ?>
                                            </span>
                                        <?php elseif (!empty($del['transport_method'])): ?>
                                            <span class="badge badge-neutral" style="font-size: 0.72rem;">
                                                <?= e(ucfirst($del['transport_method'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="font-size: 0.75rem;">
                                        <?= icon('clock', 'lucide-xs') ?>
                                        <span>Unassigned</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPaid): ?>
                                    <span class="badge badge-success" style="font-size: 0.75rem;">
                                        <?= icon('check-circle', 'lucide-xs') ?> Paid via M-Pesa
                                    </span>
                                    <?php if (!empty($del['mpesa_receipt'])): ?>
                                        <div class="small" style="font-family: monospace; font-weight: 600; color: #065f46; margin-top: 0.2rem;">
                                            Ref: <?= e($del['mpesa_receipt']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($isPrompted): ?>
                                    <span class="badge badge-info" style="font-size: 0.75rem;">
                                        <?= icon('loader', 'lucide-xs') ?> STK Prompt Sent
                                    </span>
                                    <div class="small muted" style="margin-top: 0.15rem;">
                                        To: <?= e($del['payment_phone'] ?: $del['buyer_phone']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="font-size: 0.75rem;">
                                        <?= icon('clock', 'lucide-xs') ?> Unpaid (PoD)
                                    </span>
                                    <div class="small muted" style="margin-top: 0.15rem;">Prompt on arrival</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($badge['class']) ?>">
                                    <?= e($badge['label']) ?>
                                </span>
                            </td>
                            <td class="small" style="max-width: 220px;">
                                <?= e($del['tracking_notes'] ?: '-') ?>
                            </td>
                            <?php if (in_array($role, ['seller', 'delivery', 'admin'], true)): ?>
                                <td style="min-width: 230px;">
                                    <?php if (in_array($role, ['seller', 'admin'], true)): ?>
                                        <?php if (!$hasRider): ?>
                                            <!-- Seller / Admin: Assign available rider -->
                                            <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="assign_rider">
                                                <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                
                                                <select name="rider_id" class="form-control" style="padding: 0.3rem 0.45rem; font-size: 0.82rem;" required>
                                                    <option value="">-- Choose Verified Rider --</option>
                                                    <?php foreach ($availableRiders as $r): ?>
                                                        <option value="<?= (int) $r['rider_id'] ?>">
                                                            <?= e($r['rider_name']) ?> - <?= e(ucfirst($r['transport_method'])) ?> <?= !empty($r['vehicle_plate_number']) ? '[' . e($r['vehicle_plate_number']) . ']' : '' ?> (<?= (int) $r['active_deliveries'] ?> active)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                
                                                <button type="submit" class="btn btn-primary btn-sm" <?= empty($availableRiders) ? 'disabled' : '' ?>>
                                                    <?= icon('user-check', 'lucide-xs') ?>
                                                    <span><?= empty($availableRiders) ? 'No Riders on Duty' : 'Assign Rider' ?></span>
                                                </button>
                                            </form>
                                        <?php elseif (!$isDelivered): ?>
                                            <!-- Allow re-assignment if order is still in early stage -->
                                            <details style="font-size: 0.82rem;">
                                                <summary class="muted" style="cursor: pointer; padding: 0.2rem 0;">
                                                    <?= icon('arrow-right-left', 'lucide-xs') ?>
                                                    <span>Change Rider</span>
                                                </summary>
                                                <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.35rem;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="assign_rider">
                                                    <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                    
                                                    <select name="rider_id" class="form-control" style="padding: 0.28rem 0.45rem; font-size: 0.8rem;" required>
                                                        <option value="">-- Reassign to Rider --</option>
                                                        <?php foreach ($availableRiders as $r): ?>
                                                            <?php if ((int) $r['rider_id'] !== (int) $del['delivery_person_id']): ?>
                                                                <option value="<?= (int) $r['rider_id'] ?>">
                                                                    <?= e($r['rider_name']) ?> - <?= e(ucfirst($r['transport_method'])) ?> <?= !empty($r['vehicle_plate_number']) ? '[' . e($r['vehicle_plate_number']) . ']' : '' ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-amber btn-sm">
                                                        <?= icon('user-check', 'lucide-xs') ?>
                                                        <span>Update Assignment</span>
                                                    </button>
                                                </form>
                                            </details>
                                        <?php else: ?>
                                            <span class="small muted">
                                                <?= icon('check-circle', 'lucide-xs', 'emerald') ?>
                                                Completed
                                            </span>
                                        <?php endif; ?>

                                    <?php elseif ($role === 'delivery' && (int) $del['delivery_person_id'] === $userId): ?>
                                        <!-- Rider: Cashless Payment & Tracking Controller -->
                                        <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                                            
                                            <!-- Step 4 Digital Pay-on-Delivery Module -->
                                            <?php if (!$isPaid): ?>
                                                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; padding: 0.55rem 0.65rem;">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                                                        <strong style="color: #065f46; font-size: 0.82rem;">
                                                            <?= icon('smartphone', 'lucide-xs') ?> M-Pesa STK Prompt
                                                        </strong>
                                                        <span class="badge badge-warning" style="font-size: 0.68rem;">NO CASH</span>
                                                    </div>

                                                    <?php if ($isPrompted): ?>
                                                        <div class="small" style="color: #047857; margin-bottom: 0.4rem; font-weight: 500;">
                                                            Waiting for customer (<?= e($del['payment_phone'] ?: $del['buyer_phone']) ?>) to enter PIN for <strong><?= format_ksh($totalPayable) ?></strong>.
                                                        </div>

                                                        <!-- Confirm Payment Button -->
                                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin-bottom: 0.35rem;">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="confirm_digital_payment">
                                                            <input type="hidden" name="payment_id" value="<?= (int) ($del['active_payment_id'] ?? 0) ?>">
                                                            <input type="text" name="mpesa_receipt" placeholder="M-Pesa Ref (optional code)" class="form-control" style="font-size: 0.78rem; font-family: monospace; padding: 0.25rem 0.45rem; margin-bottom: 0.35rem; text-transform: uppercase;">
                                                            <button type="submit" class="btn btn-success btn-sm btn-block" style="font-size: 0.8rem; padding: 0.35rem 0.5rem; background: #059669;">
                                                                <?= icon('check-circle', 'lucide-xs') ?>
                                                                <span>Confirm M-Pesa Payment Received</span>
                                                            </button>
                                                        </form>

                                                        <!-- Resend STK Prompt -->
                                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="prompt_stk">
                                                            <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                            <button type="submit" class="btn btn-outline btn-sm btn-block" style="font-size: 0.75rem; padding: 0.25rem;">
                                                                <?= icon('refresh-cw', 'lucide-xs') ?>
                                                                <span>Resend STK Push Prompt</span>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <p class="small muted" style="margin: 0 0 0.4rem 0;">
                                                            Arrived at customer? Prompt their Safaricom phone to enter M-Pesa PIN.
                                                        </p>
                                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="prompt_stk">
                                                            <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                            <div style="display: flex; gap: 0.3rem; margin-bottom: 0.35rem;">
                                                                <input type="tel" name="buyer_phone" value="<?= e($del['buyer_phone'] ?? '') ?>" placeholder="Customer Safaricom line" class="form-control" style="font-size: 0.78rem; padding: 0.25rem 0.45rem;" required>
                                                            </div>
                                                            <button type="submit" class="btn btn-primary btn-sm btn-block" style="background: #059669; border-color: #059669; font-size: 0.8rem; padding: 0.35rem 0.5rem;">
                                                                <?= icon('send', 'lucide-xs') ?>
                                                                <span>Prompt Buyer to Pay (<?= format_ksh($totalPayable) ?>)</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; border-radius: 6px; padding: 0.4rem 0.6rem; color: #065f46; font-size: 0.82rem; font-weight: 600;">
                                                    <?= icon('check-circle', 'lucide-xs') ?>
                                                    <span>M-Pesa Cleared (<?= e($del['mpesa_receipt'] ?? '') ?>) &mdash; Release package</span>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Tracking Status Updater -->
                                            <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                
                                                <select name="delivery_status" class="form-control" style="padding: 0.28rem 0.45rem; font-size: 0.84rem;">
                                                    <option value="assigned" <?= $del['delivery_status'] === 'assigned' ? 'selected' : '' ?>>Assigned (Heading for pickup)</option>
                                                    <option value="picked_up" <?= $del['delivery_status'] === 'picked_up' ? 'selected' : '' ?>>Picked Up (Package collected)</option>
                                                    <option value="in_transit" <?= $del['delivery_status'] === 'in_transit' ? 'selected' : '' ?>>In Transit (On the way)</option>
                                                    <option value="delivered" <?= $del['delivery_status'] === 'delivered' ? 'selected' : '' ?> <?= !$isPaid ? 'disabled' : '' ?>>
                                                        Delivered <?= !$isPaid ? '(Requires M-Pesa Payment First)' : '(Handoff Complete)' ?>
                                                    </option>
                                                    <option value="failed" <?= $del['delivery_status'] === 'failed' ? 'selected' : '' ?>>Failed (Delivery unsuccessful)</option>
                                                </select>
                                                
                                                <input type="text" name="tracking_notes" class="form-control" style="padding: 0.28rem 0.45rem; font-size: 0.82rem;" value="<?= e($del['tracking_notes'] ?? '') ?>" placeholder="Customer note / update...">
                                                
                                                <button type="submit" class="btn btn-amber btn-sm">
                                                    <?= icon('save', 'lucide-xs') ?>
                                                    <span>Update Tracking</span>
                                                </button>
                                            </form>
                                        </div>
>>>>>>> feature/seller-kyc-admin
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
