<?php
/**
 * SokoHub Delivery Dispatch & Tracking Module (CRUD)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
init_session();
$user = require_role(['delivery', 'buyer', 'admin']);
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];
$isVerifiedRider = ($role === 'delivery') ? Kyc::isUserApproved($userId, 'delivery') : true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['delivery', 'admin'], true)) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('pages/dashboard/deliveries.php');
    }

    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'claim') {
        if ($role === 'delivery' && !$isVerifiedRider) {
            set_flash('error', 'KYC Compliance Required: You must be verified with an approved National ID and M-Pesa line before you can claim delivery orders.');
            redirect('pages/dashboard/deliveries.php');
        }

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

    if ($action === 'update') {
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
            set_flash('error', 'Failed to update delivery status.');
        }
        redirect('pages/dashboard/deliveries.php');
    }
}

if ($role === 'buyer') {
    $stmt = $pdo->prepare(
        'SELECT d.*, o.order_code, r.full_name AS rider_name, r.phone AS rider_phone
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
        'SELECT d.*, o.order_code, b.full_name AS buyer_name, b.phone AS buyer_phone
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         WHERE d.delivery_person_id = :uid OR d.delivery_person_id IS NULL
         ORDER BY (d.delivery_person_id IS NULL) DESC, d.id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $deliveries = $stmt->fetchAll();
} else {
    $deliveries = $pdo->query(
        'SELECT d.*, o.order_code, b.full_name AS buyer_name, r.full_name AS rider_name
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         INNER JOIN users b ON b.id = o.buyer_id
         LEFT JOIN users r ON r.id = d.delivery_person_id
         ORDER BY d.id DESC'
    )->fetchAll();
}

$pageTitle = 'SokoHub | Delivery Coordination';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Delivery Dispatch &amp; Tracking</h1>
            <p class="muted">Coordinate pickup, transit progress, and proof of completion.</p>
        </div>
        <?php if (in_array($role, ['delivery', 'admin'], true)): ?>
            <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="btn btn-amber">Delivery Performance Report</a>
        <?php endif; ?>
    </div>

    <?php if ($role === 'delivery' && !$isVerifiedRider): 
        $riderKyc = Kyc::getUserKycProfile($userId, 'delivery');
        $riderStatus = $riderKyc['verification_status'] ?? 'pending';
    ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #f59e0b; background: #fffbeb;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #92400e; font-size: 1rem;">⚠️ Delivery Rider KYC Verification Required</strong>
                    <p style="margin: 0.25rem 0 0 0; color: #b45309; font-size: 0.9rem;">
                        Your rider account is currently <strong><?= e(ucfirst($riderStatus)) ?></strong>. You must submit your Kenyan National ID and Safaricom M-Pesa line before you can claim customer delivery tasks.
                    </p>
                </div>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-amber btn-sm">Complete Rider Verification &rarr;</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($deliveries)): ?>
            <p class="muted">No delivery tasks found.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Route (Pickup &rarr; Dropoff)</th>
                            <th>Fee</th>
                            <th>Status</th>
                            <th>Tracking Notes</th>
                            <?php if (in_array($role, ['delivery', 'admin'], true)): ?><th>Update</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $del): ?>
                        <?php $badge = get_status_badge($del['delivery_status']); ?>
                        <tr>
                            <td>
                                <strong><?= e($del['order_code']) ?></strong>
                                <?php if (!empty($del['buyer_name'])): ?>
                                    <div class="small muted">Buyer: <?= e($del['buyer_name']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($del['rider_name'])): ?>
                                    <div class="small muted">Rider: <?= e($del['rider_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong>From:</strong> <?= e($del['pickup_town']) ?></div>
                                <div class="small muted"><strong>To:</strong> <?= e($del['dropoff_address']) ?></div>
                            </td>
                            <td><?= format_ksh($del['delivery_fee']) ?></td>
                            <td><span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></td>
                            <td class="small"><?= e($del['tracking_notes'] ?: '-') ?></td>
                            <?php if (in_array($role, ['delivery', 'admin'], true)): ?>
                                <td>
                                    <?php if ($del['delivery_person_id'] === null && $del['delivery_status'] === 'pending' && $role === 'delivery'): ?>
                                        <?php if ($isVerifiedRider): ?>
                                            <form action="<?= e(url('pages/dashboard/deliveries.php')) ?>" method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="claim">
                                                <input type="hidden" name="delivery_id" value="<?= (int) $del['id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">Claim Task</button>
                                            </form>
                                        <?php else: ?>
                                            <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-outline btn-sm" style="color: #b45309; border-color: #f59e0b; font-size: 0.75rem;" title="Upload National ID and M-Pesa to claim orders">
                                                KYC Required &rarr;
                                            </a>
                                        <?php endif; ?>
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
                                            <button type="submit" class="btn btn-amber btn-sm">Update</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small muted">Assigned to another rider</span>
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
