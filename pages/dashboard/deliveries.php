<?php
/**
 * SokoHub Delivery Dispatch & Rider Coordination Module
 *
 * Implements:
 * - Order claiming & seller/admin rider assignment
 * - Delivery status tracking (assigned, picked up, out for delivery, delivered)
 * - COD Cash Collection confirmation (transitions order to paid & records fee receivable)
 * - Automated or manual M-Pesa B2C payout of delivery fees to rider
 * - Rider verification status
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mpesa.php';

init_session();
$user = require_role(['delivery', 'seller', 'buyer', 'admin']);
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('pages/dashboard/deliveries.php');
    }

    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    // 1. Rider Claims Delivery Task
    if ($action === 'claim' && in_array($role, ['delivery', 'admin'], true)) {
        $claim = $pdo->prepare(
            "UPDATE deliveries
             SET delivery_person_id = :rider, delivery_status = 'assigned',
                 assigned_at = NOW(), tracking_notes = 'Rider assigned and heading for pickup'
             WHERE id = :id AND delivery_person_id IS NULL AND delivery_status = 'pending'"
        );
        $claim->execute([':rider' => $userId, ':id' => $deliveryId]);
        set_flash($claim->rowCount() > 0 ? 'success' : 'warning', $claim->rowCount() > 0 ? 'Delivery task claimed successfully!' : 'Delivery already assigned.');
        redirect('pages/dashboard/deliveries.php');
    }

    // 2. Seller or Admin Assigns Rider to Order
    if ($action === 'assign' && in_array($role, ['seller', 'admin'], true)) {
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        if ($riderId > 0) {
            $pdo->prepare(
                "UPDATE deliveries 
                 SET delivery_person_id = :rider, delivery_status = 'assigned', assigned_at = NOW(), tracking_notes = 'Assigned by seller'
                 WHERE id = :id"
            )->execute([':rider' => $riderId, ':id' => $deliveryId]);
            set_flash('success', 'Rider assigned to delivery.');
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // 3. Update Delivery Milestone & COD Cash Collection
    if ($action === 'update' && in_array($role, ['delivery', 'admin'], true)) {
        $newStatus = (string) ($_POST['delivery_status'] ?? '');
        $notes = trim((string) ($_POST['tracking_notes'] ?? ''));
        $cashCollected = !empty($_POST['cash_collected']);

        if (!in_array($newStatus, ['assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed'], true)) {
            set_flash('error', 'Invalid delivery status.');
            redirect('pages/dashboard/deliveries.php');
        }

        $dStmt = $pdo->prepare('SELECT d.*, o.payment_method, o.payment_status FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE d.id = :id LIMIT 1');
        $dStmt->execute([':id' => $deliveryId]);
        $del = $dStmt->fetch();

        if (!$del || ($role !== 'admin' && (int) $del['delivery_person_id'] !== $userId)) {
            set_flash('error', 'Unauthorized to update this delivery.');
            redirect('pages/dashboard/deliveries.php');
        }

        $orderId = (int) $del['order_id'];
        $pdo->beginTransaction();
        try {
            $deliveredAtSql = ($newStatus === 'delivered') ? ', delivered_at = NOW()' : '';
            $cashSql = '';
            if ($cashCollected && (int) $del['cash_collected'] === 0) {
                $cashSql = ', cash_collected = 1, cash_collected_at = NOW()';
            }

            $upd = $pdo->prepare(
                "UPDATE deliveries
                 SET delivery_status = :st, tracking_notes = :notes {$deliveredAtSql} {$cashSql}
                 WHERE id = :id"
            );
            $upd->execute([':st' => $newStatus, ':notes' => $notes, ':id' => $deliveryId]);

            // If COD cash was collected, mark order payment_status as paid!
            if ($cashCollected && $del['payment_method'] === 'Cash on Delivery') {
                $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = :oid")->execute([':oid' => $orderId]);
                // Record COD settlement ledger & fee receivable
                mpesa_generate_settlement_ledger($pdo, $orderId, false);
            }

            // Sync order status
            if ($newStatus === 'delivered') {
                $pdo->prepare("UPDATE orders SET order_status = 'delivered' WHERE id = :oid")->execute([':oid' => $orderId]);
                // Automatically trigger payouts to seller and rider
                mpesa_trigger_order_delivery_payouts($pdo, $orderId);
            } elseif ($newStatus === 'out_for_delivery' || $newStatus === 'in_transit') {
                $pdo->prepare("UPDATE orders SET order_status = 'out_for_delivery' WHERE id = :oid AND order_status IN ('pending','confirmed','processing','shipped')")
                    ->execute([':oid' => $orderId]);
            }

            $pdo->commit();
            set_flash('success', 'Delivery status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', 'Failed to update delivery: ' . $e->getMessage());
        }
        redirect('pages/dashboard/deliveries.php');
    }

    // 4. Seller Triggers Manual Delivery Fee Payout to Rider
    if ($action === 'pay_rider' && in_array($role, ['seller', 'admin'], true)) {
        $payoutId = (int) ($_POST['payout_id'] ?? 0);
        if ($payoutId > 0) {
            $res = mpesa_execute_single_payout($pdo, $payoutId);
            set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
        redirect('pages/dashboard/deliveries.php');
    }
}

// Fetch deliveries according to user role
if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.payment_method, o.payment_status, o.total_amount,
                r.full_name AS rider_name, r.phone AS rider_phone
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         WHERE o.buyer_id = :uid
         ORDER BY d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll();
} elseif ($role === 'delivery') {
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.payment_method, o.payment_status, o.total_amount,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                p.status AS payout_status, p.id AS payout_id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN payouts p ON p.id = d.delivery_payout_id
         WHERE d.delivery_person_id = :uid OR d.delivery_person_id IS NULL
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll();
} else {
    // Seller or Admin
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, o.payment_method, o.payment_status, o.total_amount,
                b.full_name AS buyer_name, b.phone AS buyer_phone,
                r.full_name AS rider_name, r.phone AS rider_phone,
                p.status AS payout_status, p.id AS payout_id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         LEFT JOIN payouts p ON p.id = d.delivery_payout_id
         ORDER BY d.id DESC'
    );
    $stmt->execute();
    $deliveries = $stmt->fetchAll();
}

// Active available approved riders for assignment dropdown
$riders = $pdo->query("SELECT u.id, u.full_name, u.phone FROM users u WHERE u.role = 'delivery' AND u.status = 'active'")->fetchAll();

$pageTitle = 'SokoHub | Deliveries & Dispatch';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Delivery Dispatch &amp; Tracking</h1>
            <p class="muted">Manage local drop-offs, cash collections, and automated rider delivery payouts.</p>
        </div>
        <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">
            <?= icon('layout-dashboard', 'lucide-xs', 'blue') ?>
            <span>Back to Dashboard</span>
        </a>
    </div>

    <?php if (empty($deliveries)): ?>
        <div class="card">
            <p class="muted">No delivery dispatch tasks found.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Pickup &amp; Drop-Off</th>
                            <th>Payment &amp; COD</th>
                            <th>Delivery Fee</th>
                            <th>Rider</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $del): ?>
                        <?php
                        $dBadge = get_status_badge($del['delivery_status']);
                        $isCod = ($del['payment_method'] === 'Cash on Delivery');
                        $isDelivered = ($del['delivery_status'] === 'delivered');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($del['order_code']) ?></strong>
                                <div class="small muted">Order Total: <?= format_ksh($del['total_amount']) ?></div>
                            </td>
                            <td>
                                <div class="small"><strong>From:</strong> <?= e($del['pickup_town']) ?></div>
                                <div class="small"><strong>To:</strong> <?= e($del['dropoff_address']) ?></div>
                            </td>
                            <td>
                                <div><strong><?= e($del['payment_method']) ?></strong></div>
                                <?php if ($isCod): ?>
                                    <?php if ($del['cash_collected']): ?>
                                        <span class="badge badge-success"><?= icon('check-circle', 'lucide-xs', 'emerald') ?> Cash Collected</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><?= icon('banknote', 'lucide-xs', 'amber') ?> Collect Cash: <?= format_ksh($del['total_amount']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-success"><?= icon('check-circle', 'lucide-xs', 'emerald') ?> Pre-Paid M-Pesa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= format_ksh($del['delivery_fee']) ?></strong>
                                <?php if (!empty($del['delivery_fee_paid'])): ?>
                                    <div class="small" style="color: #059669;">&bull; Rider Paid</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($del['rider_name'])): ?>
                                    <strong><?= e($del['rider_name']) ?></strong>
                                    <div class="small muted"><?= e($del['rider_phone'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="badge badge-neutral">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span>
                                <?php if (!empty($del['tracking_notes'])): ?>
                                    <div class="small muted" style="margin-top: 0.15rem;"><?= e($del['tracking_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Rider Claim Action -->
                                <?php if (empty($del['delivery_person_id']) && in_array($role, ['delivery', 'admin'], true)): ?>
                                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin-bottom: 0.35rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="claim">
                                        <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <?= icon('check', 'lucide-xs', 'white') ?>
                                            <span>Claim Job</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Seller/Admin Assign Rider Dropdown -->
                                <?php if (empty($del['delivery_person_id']) && in_array($role, ['seller', 'admin'], true)): ?>
                                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display:flex; gap:0.25rem; margin-bottom: 0.35rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="assign">
                                        <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                        <select name="rider_id" class="form-control" style="padding:0.2rem 0.35rem; font-size:0.8rem; width:auto;" required>
                                            <option value="">Assign Rider</option>
                                            <?php foreach ($riders as $r): ?>
                                                <option value="<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline btn-sm">Assign</button>
                                    </form>
                                <?php endif; ?>

                                <!-- Rider Milestone & COD Update -->
                                <?php if (!empty($del['delivery_person_id']) && (int) $del['delivery_person_id'] === $userId && !$isDelivered): ?>
                                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="display: flex; flex-direction: column; gap: 0.25rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">

                                        <div style="display: flex; gap: 0.25rem;">
                                            <select name="delivery_status" class="form-control" style="padding:0.2rem 0.35rem; font-size:0.8rem; width:auto;">
                                                <option value="picked_up" <?= $del['delivery_status'] === 'picked_up' ? 'selected' : '' ?>>Picked Up</option>
                                                <option value="out_for_delivery" <?= $del['delivery_status'] === 'out_for_delivery' || $del['delivery_status'] === 'in_transit' ? 'selected' : '' ?>>Out for Delivery</option>
                                                <option value="delivered" <?= $del['delivery_status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                            </select>
                                            <button type="submit" class="btn btn-amber btn-sm">Update</button>
                                        </div>

                                        <?php if ($isCod && !$del['cash_collected']): ?>
                                            <label class="small" style="display:flex; align-items:center; gap:0.25rem; color:#b45309; font-weight:700;">
                                                <input type="checkbox" name="cash_collected" value="1">
                                                <span>Cash Collected (<?= format_ksh($del['total_amount']) ?>)</span>
                                            </label>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>

                                <!-- Manual Pay Rider Button for Seller / Admin -->
                                <?php if (!empty($del['payout_id']) && empty($del['delivery_fee_paid']) && in_array($role, ['seller', 'admin'], true)): ?>
                                    <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST" style="margin-top: 0.25rem;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="pay_rider">
                                        <input type="hidden" name="payout_id" value="<?= (int) $del['payout_id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <?= icon('wallet', 'lucide-xs', 'white') ?>
                                            <span>Disburse Rider Fee</span>
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
