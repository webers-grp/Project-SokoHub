<?php
/**
 * SokoHub Role-Aware User Dashboard
 *
 * Provides real-time metrics, quick shortcuts, compliance indicators,
 * and recent transaction logs tailored for Buyers, Merchants, Delivery Agents, and Admins.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/wishlist.php';

init_session();
$user = require_login();
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];
$kpis = [];
$recentRows = [];

if ($role === 'buyer') {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS spent FROM orders WHERE buyer_id = :id AND order_status <> "cancelled"');
    $stmt->execute([':id' => $userId]);
    $agg = $stmt->fetch();

    $activeDelStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         WHERE o.buyer_id = :id AND d.delivery_status IN ('pending','assigned','in_transit')"
    );
    $activeDelStmt->execute([':id' => $userId]);

    $kpis = [
        ['label' => 'My Orders', 'value' => (int) $agg['cnt'], 'icon' => 'shopping-bag', 'color' => 'purple'],
        ['label' => 'Total Spent', 'value' => format_ksh($agg['spent']), 'icon' => 'wallet', 'color' => 'emerald'],
        ['label' => 'Active Deliveries', 'value' => (int) $activeDelStmt->fetchColumn(), 'icon' => 'truck', 'color' => 'amber'],
        ['label' => 'Saved Wishlist', 'value' => Wishlist::count($userId), 'icon' => 'heart', 'color' => 'rose'],
    ];

    $recStmt = $pdo->prepare(
        'SELECT o.order_code, o.total_amount, o.order_status, o.created_at, d.delivery_status
         FROM orders o
         LEFT JOIN deliveries d ON d.order_id = o.id
         WHERE o.buyer_id = :id
         ORDER BY o.created_at DESC
         LIMIT 5'
    );
    $recStmt->execute([':id' => $userId]);
    $recentRows = $recStmt->fetchAll();
} elseif ($role === 'seller') {
    $prodCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = :id AND status = 'active'");
    $prodCount->execute([':id' => $userId]);

    $lowStockCount = Inventory::getLowStockCount($userId);

    $revStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.line_total), 0) AS rev, COALESCE(SUM(oi.quantity), 0) AS units
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.seller_id = :id AND o.order_status <> 'cancelled'"
    );
    $revStmt->execute([':id' => $userId]);
    $rev = $revStmt->fetch();

    $kycProfile = Kyc::getUserKycProfile($userId, 'seller');
    $kycStatus = $kycProfile['verification_status'] ?? 'pending';

    $kpis = [
        ['label' => 'Active Products', 'value' => (int) $prodCount->fetchColumn(), 'icon' => 'package', 'color' => 'blue'],
        ['label' => 'Low Stock Items', 'value' => $lowStockCount, 'icon' => 'alert-triangle', 'color' => 'rose'],
        ['label' => 'Units Sold', 'value' => (int) $rev['units'], 'icon' => 'shopping-bag', 'color' => 'purple'],
        ['label' => 'Gross Sales', 'value' => format_ksh($rev['rev']), 'icon' => 'trending-up', 'color' => 'emerald'],
    ];

    $recStmt = $pdo->prepare(
        "SELECT o.order_code, p.name AS product_name, oi.quantity, oi.line_total, o.order_status, o.created_at
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.seller_id = :id
         ORDER BY o.created_at DESC
         LIMIT 6"
    );
    $recStmt->execute([':id' => $userId]);
    $recentRows = $recStmt->fetchAll();
} elseif ($role === 'delivery') {
    $avail = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id IS NULL AND delivery_status = 'pending'")->fetchColumn();

    $mineActive = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = :id AND delivery_status IN ('assigned','in_transit')");
    $mineActive->execute([':id' => $userId]);

    $mineDone = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(delivery_fee), 0) AS fees FROM deliveries WHERE delivery_person_id = :id AND delivery_status = 'delivered'");
    $mineDone->execute([':id' => $userId]);
    $done = $mineDone->fetch();

    $kpis = [
        ['label' => 'Available Tasks', 'value' => $avail, 'icon' => 'clock', 'color' => 'amber'],
        ['label' => 'My Active Deliveries', 'value' => (int) $mineActive->fetchColumn(), 'icon' => 'navigation', 'color' => 'blue'],
        ['label' => 'Completed Drops', 'value' => (int) $done['cnt'], 'icon' => 'check-circle-2', 'color' => 'emerald'],
        ['label' => 'Earned Delivery Fees', 'value' => format_ksh($done['fees']), 'icon' => 'wallet', 'color' => 'teal'],
    ];

    $recStmt = $pdo->prepare(
        "SELECT o.order_code, d.pickup_town, d.dropoff_address, d.delivery_fee, d.delivery_status
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         WHERE d.delivery_person_id = :id OR d.delivery_person_id IS NULL
         ORDER BY d.id DESC
         LIMIT 6"
    );
    $recStmt->execute([':id' => $userId]);
    $recentRows = $recStmt->fetchAll();
} else { // admin
    $kpis = [
        ['label' => 'Registered Users', 'value' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'icon' => 'users', 'color' => 'cyan'],
        ['label' => 'Active Products', 'value' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn(), 'icon' => 'package', 'color' => 'blue'],
        ['label' => 'Total Order Volume', 'value' => format_ksh($pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_status <> 'cancelled'")->fetchColumn()), 'icon' => 'trending-up', 'color' => 'emerald'],
        ['label' => 'Pending KYC Audits', 'value' => (int) $pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'pending'")->fetchColumn(), 'icon' => 'shield-alert', 'color' => 'amber'],
    ];

    $recentRows = $pdo->query(
        'SELECT o.order_code, u.full_name AS buyer_name, o.total_amount, o.order_status, o.created_at
         FROM orders o
         INNER JOIN users u ON u.id = o.buyer_id
         ORDER BY o.created_at DESC
         LIMIT 6'
    )->fetchAll();
}

$roleLabels = get_role_labels();
$pageTitle = 'SokoHub | Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head" style="flex-wrap: wrap; gap: 1rem;">
        <div>
            <span class="badge badge-primary"><?= icon('shield-check', 'lucide-sm') ?> <?= e($roleLabels[$role] ?? ucfirst($role)) ?></span>
            <h1 style="margin-top: 0.4rem;">Welcome, <?= e($user['full_name']) ?></h1>
            <p class="muted">Signed in as <strong><?= e($user['email']) ?></strong></p>
        </div>

        <div class="hero-cta" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($role === 'buyer'): ?>
                <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
                    <?= icon('shopping-bag', 'lucide-xs', 'white') ?>
                    <span>Browse Products</span>
                </a>
                <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="btn btn-outline">
                    <?= icon('receipt', 'lucide-xs') ?>
                    <span>My Orders</span>
                </a>
                <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="btn btn-outline">
                    <?= icon('heart', 'lucide-xs', 'rose') ?>
                    <span>Wishlist</span>
                </a>
                <a href="<?= e(url('pages/auth/profile.php')) ?>" class="btn btn-outline">
                    <?= icon('user', 'lucide-xs') ?>
                    <span>Account Profile</span>
                </a>
            <?php elseif ($role === 'seller'): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">
                    <?= icon('package', 'lucide-xs', 'white') ?>
                    <span>Manage Catalog</span>
                </a>
                <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('file-spreadsheet', 'lucide-xs') ?>
                    <span>Bulk CSV Upload</span>
                </a>
                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline">
                    <?= icon('wallet', 'lucide-xs') ?>
                    <span>Payouts Ledger</span>
                </a>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                    <span>KYC Compliance</span>
                </a>
                <a href="<?= e(url('pages/dashboard/reports.php')) ?>" class="btn btn-amber">
                    <?= icon('bar-chart-2', 'lucide-xs', 'white') ?>
                    <span>Reports</span>
                </a>
            <?php elseif ($role === 'delivery'): ?>
                <a href="<?= e(url('pages/dashboard/deliveries.php')) ?>" class="btn btn-primary">
                    <?= icon('truck', 'lucide-xs', 'white') ?>
                    <span>Delivery Tasks</span>
                </a>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                    <span>Rider Verification</span>
                </a>
                <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="btn btn-amber">
                    <?= icon('file-text', 'lucide-xs', 'white') ?>
                    <span>Delivery Reports</span>
                </a>
            <?php else: // Admin ?>
                <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn btn-primary">
                    <?= icon('shield-check', 'lucide-xs', 'white') ?>
                    <span>KYC Approval Queue</span>
                </a>
                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline">
                    <?= icon('wallet', 'lucide-xs') ?>
                    <span>Settlement Ledger</span>
                </a>
                <a href="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="btn btn-outline">
                    <?= icon('activity', 'lucide-xs') ?>
                    <span>Audit Trail</span>
                </a>
                <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('file-spreadsheet', 'lucide-xs') ?>
                    <span>Bulk Operations</span>
                </a>
                <a href="<?= e(url('pages/dashboard/inquiries.php')) ?>" class="btn btn-outline">
                    <?= icon('message-square', 'lucide-xs') ?>
                    <span>Support</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metrics Stats Grid -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <?php foreach ($kpis as $kpi): ?>
            <?php $color = $kpi['color'] ?? 'emerald'; ?>
            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <span class="stat-label"><?= e($kpi['label']) ?></span>
                    <?php if (!empty($kpi['icon'])): ?>
                        <div class="stat-icon-wrap stat-icon-<?= e($color) ?>">
                            <?= icon($kpi['icon'], 'lucide-sm') ?>
                        </div>
                    <?php endif; ?>
                </div>
                <strong class="stat-value"><?= e($kpi['value']) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent Activity Table -->
    <div class="card">
        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0;">Recent Marketplace Activity</h2>
            <a href="<?= e(url($role === 'delivery' ? 'pages/dashboard/deliveries.php' : 'pages/buyer/orders.php')) ?>" class="btn btn-outline btn-sm">View All &rarr;</a>
        </div>

        <?php if (empty($recentRows)): ?>
            <p class="muted">No recent activity recorded yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <?php if ($role === 'buyer'): ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Total Amount</th>
                            <th>Order Status</th>
                            <th>Delivery Status</th>
                            <th>Placed On</th>
                        </tr>
                    <?php elseif ($role === 'seller'): ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Revenue</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    <?php elseif ($role === 'delivery'): ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Pickup Hub</th>
                            <th>Dropoff Address</th>
                            <th>Fee</th>
                            <th>Status</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Buyer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRows as $row): ?>
                            <tr>
                                <td><strong><?= e($row['order_code']) ?></strong></td>
                                <?php if ($role === 'buyer'): ?>
                                    <td><?= format_ksh($row['total_amount']) ?></td>
                                    <td><span class="badge badge-primary"><?= e(ucfirst($row['order_status'])) ?></span></td>
                                    <td><span class="badge badge-info"><?= e(ucfirst($row['delivery_status'] ?? 'pending')) ?></span></td>
                                    <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
                                <?php elseif ($role === 'seller'): ?>
                                    <td><?= e($row['product_name']) ?></td>
                                    <td><?= (int) $row['quantity'] ?></td>
                                    <td><?= format_ksh($row['line_total']) ?></td>
                                    <td><span class="badge badge-primary"><?= e(ucfirst($row['order_status'])) ?></span></td>
                                    <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
                                <?php elseif ($role === 'delivery'): ?>
                                    <td><?= e($row['pickup_town']) ?></td>
                                    <td><?= e($row['dropoff_address']) ?></td>
                                    <td><?= format_ksh($row['delivery_fee']) ?></td>
                                    <td><span class="badge badge-info"><?= e(ucfirst($row['delivery_status'])) ?></span></td>
                                <?php else: ?>
                                    <td><?= e($row['buyer_name']) ?></td>
                                    <td><?= format_ksh($row['total_amount']) ?></td>
                                    <td><span class="badge badge-primary"><?= e(ucfirst($row['order_status'])) ?></span></td>
                                    <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
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
