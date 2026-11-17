<?php
/**
 * SokoHub Delivery Dispatch & Tracking Module (Seller-Driven Rider Allocation)
 * Authored by Kennedy Kalawa.
 *
 * Rules:
 * - Sellers select and assign verified delivery riders to orders containing their products.
 * - Delivery riders wait on standby (On Duty vs Off Duty) and cannot self-claim tasks.
 * - Riders view only deliveries assigned to them by merchants or administrators.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
init_session();
$user = require_role(['seller', 'delivery', 'buyer', 'admin']);
$pdo = get_db_connection();
Kyc::ensureSchema();

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
            set_flash('success', "Order {$res['order_code']} successfully dispatched to rider {$res['rider_desc']}.");
        } else {
            set_flash('error', $res['error'] ?? 'Failed to assign rider to delivery.');
        }
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

// Query deliveries based on user role
if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         WHERE o.buyer_id = :uid
         ORDER BY d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'delivery') {
    // Delivery rider: strictly view assignments dispatched to them (NO unassigned tasks)
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.payment_status,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                s.full_name AS seller_name, s.phone AS seller_phone,
                sp.business_name AS seller_business, sp.town AS seller_town
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users s ON s.id = d.assigned_by_seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = s.id
         WHERE d.delivery_person_id = :uid
         ORDER BY FIELD(d.delivery_status, "assigned", "picked_up", "in_transit", "delivered", "failed"), d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'seller') {
    // Seller: orders containing products from this seller
    $stmt = $pdo->prepare(
        'SELECT DISTINCT d.*, o.order_code, o.order_status, o.payment_status, o.total_amount,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         WHERE oi.seller_id = :uid
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Admin: full visibility
    $deliveries = $pdo->query(
        'SELECT d.*, o.order_code, o.order_status, o.payment_status,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                s.full_name AS seller_name, sp.business_name AS seller_business,
                r.full_name AS rider_name, r.phone AS rider_phone,
                dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users s ON s.id = d.assigned_by_seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = s.id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN delivery_profiles dp ON dp.user_id = r.id
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'SokoHub | Delivery Coordination & Dispatch';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>
                <?= icon('truck', 'lucide-sm', 'amber') ?>
                <span>Delivery Dispatch &amp; Tracking</span>
            </h1>
            <p class="muted">
                <?php if ($role === 'seller'): ?>
                    Manage order dispatch and assign vetted Kirinyaga delivery riders to customer shipments.
                <?php elseif ($role === 'delivery'): ?>
                    View your assigned delivery dispatches and manage your on-duty availability.
                <?php elseif ($role === 'buyer'): ?>
                    Track the fulfillment progress and verified rider assigned to your orders.
                <?php else: ?>
                    System-wide delivery oversight and dispatch coordination.
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
                <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_availability">
                    <button type="submit" class="btn <?= $isOnDuty ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                        <?= icon($isOnDuty ? 'pause-circle' : 'play-circle', 'lucide-xs') ?>
                        <span><?= $isOnDuty ? 'Switch to Off Duty' : 'Go On Duty (Receive Deliveries)' ?></span>
                    </button>
                </form>
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
                            <th>Fee</th>
                            <th>Assigned Rider</th>
                            <th>Status</th>
                            <th>Tracking Notes</th>
                            <?php if (in_array($role, ['seller', 'delivery', 'admin'], true)): ?>
                                <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $del): ?>
                        <?php 
                        $badge = get_status_badge($del['delivery_status']); 
                        $hasRider = !empty($del['delivery_person_id']);
                        $isDelivered = in_array($del['delivery_status'], ['delivered', 'failed'], true);
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
                            <td><strong><?= format_ksh($del['delivery_fee']) ?></strong></td>
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
                                <span class="badge <?= e($badge['class']) ?>">
                                    <?= e($badge['label']) ?>
                                </span>
                            </td>
                            <td class="small" style="max-width: 220px;">
                                <?= e($del['tracking_notes'] ?: '-') ?>
                            </td>
                            <?php if (in_array($role, ['seller', 'delivery', 'admin'], true)): ?>
                                <td>
                                    <?php if (in_array($role, ['seller', 'admin'], true)): ?>
                                        <?php if (!$hasRider): ?>
                                            <!-- Seller / Admin: Assign available rider -->
                                            <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem; min-width: 210px;">
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
                                                <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem; min-width: 210px; margin-top: 0.35rem;">
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
                                        <!-- Rider: Update tracking status and notes -->
                                        <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.35rem; min-width: 190px;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                            
                                            <select name="delivery_status" class="form-control" style="padding: 0.28rem 0.45rem; font-size: 0.84rem;">
                                                <?php foreach (['assigned', 'picked_up', 'in_transit', 'delivered', 'failed'] as $st): ?>
                                                    <option value="<?= $st ?>" <?= $del['delivery_status'] === $st ? 'selected' : '' ?>>
                                                        <?= ucfirst(str_replace('_', ' ', $st)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <input type="text" name="tracking_notes" class="form-control" style="padding: 0.28rem 0.45rem; font-size: 0.82rem;" value="<?= e($del['tracking_notes'] ?? '') ?>" placeholder="Customer note / update...">
                                            
                                            <button type="submit" class="btn btn-amber btn-sm">
                                                <?= icon('save', 'lucide-xs') ?>
                                                <span>Update Tracking</span>
                                            </button>
                                        </form>
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
