<?php
/**
 * SokoHub Role-Aware User Dashboard
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/orders.php';
init_session();
$user = require_login();
$pdo = get_db_connection();
ensure_order_schema_tables($pdo);

$role = $user['role'];
$userId = (int) $user['id'];
$kpis = [];
$recentRows = [];

// Seller Vacation Mode Toggle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['seller', 'admin'], true) && post('action') === 'toggle_store_status') {
    verify_csrf();
    $targetSellerId = ($role === 'admin' && post('seller_id')) ? (int) post('seller_id') : $userId;
    $stmt = $pdo->prepare('SELECT is_open FROM seller_profiles WHERE user_id = :id LIMIT 1');
    $stmt->execute([':id' => $targetSellerId]);
    $currentStatus = (int) $stmt->fetchColumn();
    $newStatus = $currentStatus ? 0 : 1;
    $upd = $pdo->prepare('UPDATE seller_profiles SET is_open = :st WHERE user_id = :id');
    $upd->execute([':st' => $newStatus, ':id' => $targetSellerId]);
    set_flash_message('success', $newStatus ? 'Your store is now OPEN and accepting customer orders.' : 'Your store is now in VACATION mode. Checkout orders for your items are temporarily paused.');
    redirect('pages/dashboard/dashboard.php');
}

$sellerProfile = null;
$storeIsOpen = true;

if ($role === 'buyer') {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS spent FROM orders WHERE buyer_id = :id AND order_status <> "cancelled"');
    $stmt->execute([':id' => $userId]);
    $agg = $stmt->fetch();

    $activeDelStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         WHERE o.buyer_id = :id AND d.delivery_status IN ('pending','assigned','picked_up','in_transit')"
    );
    $activeDelStmt->execute([':id' => $userId]);

    $buyerAlerts = Notifications::getBuyerNotifications($userId, null, 4);

    $kpis = [
        ['label' => 'My Orders', 'value' => (int) $agg['cnt'], 'icon' => 'shopping-bag', 'color' => 'purple'],
        ['label' => 'Total Spent', 'value' => format_ksh($agg['spent']), 'icon' => 'wallet', 'color' => 'emerald'],
        ['label' => 'Active Deliveries', 'value' => (int) $activeDelStmt->fetchColumn(), 'icon' => 'truck', 'color' => 'amber'],
        ['label' => 'Items in Cart', 'value' => cart_count(), 'icon' => 'shopping-cart', 'color' => 'blue'],
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
    $spStmt = $pdo->prepare('SELECT business_name, till_number, payout_type, is_open, town FROM seller_profiles WHERE user_id = :id LIMIT 1');
    $spStmt->execute([':id' => $userId]);
    $sellerProfile = $spStmt->fetch() ?: [
        'business_name' => 'My Business',
        'till_number'   => '1648753',
        'payout_type'   => 'till',
        'is_open'       => 1,
        'town'          => 'Kutus'
    ];
    $storeIsOpen = (bool) ($sellerProfile['is_open'] ?? 1);

    $prodCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = :id AND status = 'active'");
    $prodCount->execute([':id' => $userId]);

    $lowStockCount = Inventory::getLowStockCount($userId);
    $myLowStockItems = Inventory::getLowStockProducts($userId);

    $revStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.line_total), 0) AS gross_rev,
                COALESCE(SUM(oi.quantity), 0) AS units,
                COALESCE(SUM(oi.platform_fee), 0) AS total_fee,
                COALESCE(SUM(oi.seller_earnings), 0) AS net_earn
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.seller_id = :id AND o.order_status <> 'cancelled'"
    );
    $revStmt->execute([':id' => $userId]);
    $rev = $revStmt->fetch();

    $gross = (float) $rev['gross_rev'];
    $platformFee = (float) $rev['total_fee'];
    $netPayout = (float) $rev['net_earn'];
    if ($platformFee <= 0 && $gross > 0) {
        $platformFee = round($gross * 0.02, 2);
        $netPayout = round($gross - $platformFee, 2);
    }

    $payoutLabel = ($sellerProfile['payout_type'] ?? 'till') === 'till'
        ? 'Till: ' . e($sellerProfile['till_number'])
        : 'Pochi: ' . e($sellerProfile['till_number']);

    $kpis = [
        ['label' => 'Active Products', 'value' => (int) $prodCount->fetchColumn(), 'icon' => 'package', 'color' => 'blue'],
        ['label' => 'Gross Sales', 'value' => format_ksh($gross), 'icon' => 'trending-up', 'color' => 'emerald'],
        ['label' => 'SokoHub Fee (2%)', 'value' => '-' . format_ksh($platformFee), 'icon' => 'receipt', 'color' => 'rose'],
        ['label' => 'Net Payout (98%)', 'value' => format_ksh($netPayout), 'icon' => 'wallet', 'color' => 'teal'],
        ['label' => 'Disbursement Destination', 'value' => $payoutLabel, 'icon' => 'smartphone', 'color' => 'cyan'],
        ['label' => 'Low Stock Alerts', 'value' => $lowStockCount, 'icon' => 'alert-triangle', 'color' => 'amber'],
    ];

    $recStmt = $pdo->prepare(
        "SELECT o.order_code, p.name AS product_name, oi.quantity, oi.line_total, oi.platform_fee, oi.seller_earnings, o.order_status, o.created_at
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

    $mineActive = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = :id AND delivery_status IN ('assigned','picked_up','in_transit')");
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
    $totalVolume = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status <> 'cancelled'")->fetchColumn();
    $platformCommission = (float) $pdo->query("SELECT COALESCE(SUM(platform_fee), 0) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.order_status <> 'cancelled'")->fetchColumn();
    if ($platformCommission <= 0 && $totalVolume > 0) {
        $platformCommission = round($totalVolume * 0.02, 2);
    }

    $kpis = [
        ['label' => 'Registered Users', 'value' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'icon' => 'users', 'color' => 'cyan'],
        ['label' => 'Active Products', 'value' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn(), 'icon' => 'package', 'color' => 'blue'],
        ['label' => 'Total Marketplace Volume', 'value' => format_ksh($totalVolume), 'icon' => 'trending-up', 'color' => 'emerald'],
        ['label' => 'SokoHub Commission (2%)', 'value' => format_ksh($platformCommission), 'icon' => 'wallet', 'color' => 'teal'],
        ['label' => 'Master Till Account', 'value' => 'Till: 1648753', 'icon' => 'smartphone', 'color' => 'purple'],
        ['label' => 'Unread Inquiries', 'value' => (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn(), 'icon' => 'mail', 'color' => 'pink'],
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

$needsKycBanner = false;
$kycBannerRole = '';
$kycCurrentStatus = '';
if ($role === 'seller' || $role === 'delivery') {
    $kycProf = Kyc::getUserKycProfile($userId, $role);
    $kycCurrentStatus = $kycProf['verification_status'] ?? 'pending';
    if ($kycCurrentStatus !== 'approved') {
        $needsKycBanner = true;
        $kycBannerRole = ($role === 'seller') ? 'Merchant / Seller' : 'Delivery Rider';
    }
}

$pageTitle = 'SokoHub | Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="badge badge-primary"><?= icon('shield-check', 'lucide-sm') ?> <?= e($roleLabels[$role] ?? ucfirst($role)) ?></span>
                <?php if ($role === 'seller'): ?>
                    <?php if ($storeIsOpen): ?>
                        <span class="badge badge-success" style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <?= icon('check-circle', 'lucide-xs', '#ffffff') ?>
                            <span>Store Open &amp; Accepting Orders</span>
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <?= icon('moon', 'lucide-xs', '#ffffff') ?>
                            <span>Store on Vacation (Paused)</span>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <h1 style="margin-top: 0.4rem;">Welcome, <?= e($user['full_name']) ?></h1>
            <p class="muted">Signed in as <strong><?= e($user['email']) ?></strong></p>

            <?php if ($role === 'seller'): ?>
                <div style="margin-top: 0.6rem;">
                    <form action="<?= e(url('pages/dashboard/dashboard.php')) ?>" method="POST" style="margin: 0; display: inline-block;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_store_status">
                        <button type="submit" class="btn btn-sm <?= $storeIsOpen ? 'btn-outline' : 'btn-primary' ?>" style="font-size: 0.82rem; padding: 0.35rem 0.75rem;">
                            <?= $storeIsOpen ? icon('pause-circle', 'lucide-xs') . ' <span>Pause Store (Vacation Mode)</span>' : icon('play-circle', 'lucide-xs', '#ffffff') . ' <span>Reopen Store for Orders</span>' ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="hero-cta">
            <?php if ($role === 'buyer'): ?>
                <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
                    <?= icon('shopping-bag', 'lucide-xs') ?>
                    <span>Browse Products</span>
                </a>
                <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="btn btn-outline">
                    <?= icon('receipt', 'lucide-xs') ?>
                    <span>My Orders</span>
                </a>
                <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="btn btn-amber">
                    <?= icon('bell', 'lucide-xs') ?>
                    <span>Restock &amp; Store Alerts</span>
                </a>
            <?php elseif ($role === 'seller'): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">
                    <?= icon('package', 'lucide-xs') ?>
                    <span>Manage Products</span>
                </a>
                <a href="<?= e(url('pages/dashboard/deliveries.php')) ?>" class="btn btn-outline" style="border-color: #3b82f6; color: #1d4ed8;">
                    <?= icon('truck', 'lucide-xs') ?>
                    <span>Dispatch &amp; Deliveries</span>
                </a>
                <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-outline" style="border-color: #f59e0b; color: #b45309;">
                    <?= icon('file-spreadsheet', 'lucide-xs') ?>
                    <span>Bulk CSV Import</span>
                </a>
                <a href="<?= e(url('pages/dashboard/reports.php')) ?>" class="btn btn-amber">
                    <?= icon('bar-chart-3', 'lucide-xs') ?>
                    <span>Sales &amp; Stock Reports</span>
                </a>
                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline" style="border-color: #10b981; color: #047857;">
                    <?= icon('wallet', 'lucide-xs') ?>
                    <span>Monthly Payouts</span>
                </a>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('shield-check', 'lucide-xs') ?>
                    <span>Verification &amp; KYC</span>
                </a>
            <?php elseif ($role === 'delivery'): ?>
                <a href="<?= e(url('pages/dashboard/deliveries.php')) ?>" class="btn btn-primary">
                    <?= icon('truck', 'lucide-xs') ?>
                    <span>Delivery Queue</span>
                </a>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-outline">
                    <?= icon('shield-check', 'lucide-xs') ?>
                    <span>Rider KYC &amp; Verification</span>
                </a>
                <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="btn btn-amber">
                    <?= icon('bar-chart-3', 'lucide-xs') ?>
                    <span>Delivery Report</span>
                </a>
            <?php else: ?>
                <a href="<?= e(url('pages/dashboard/users.php')) ?>" class="btn btn-primary">
                    <?= icon('users', 'lucide-xs') ?>
                    <span>User Management</span>
                </a>
                <a href="<?= e(url('pages/dashboard/payments_admin.php')) ?>" class="btn btn-outline" style="border-color: #0284c7; color: #0284c7;">
                    <?= icon('credit-card', 'lucide-xs') ?>
                    <span>Daraja Payments</span>
                </a>
                <a href="<?= e(url('pages/dashboard/system_health.php')) ?>" class="btn btn-outline" style="border-color: #22c55e; color: #16a34a;">
                    <?= icon('activity', 'lucide-xs') ?>
                    <span>System Health</span>
                </a>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-outline">
                    <?= icon('package', 'lucide-xs') ?>
                    <span>Manage Catalog</span>
                </a>
                <a href="<?= e(url('pages/dashboard/inquiries.php')) ?>" class="btn btn-outline">
                    <?= icon('message-square', 'lucide-xs') ?>
                    <span>Support Inquiries</span>
                </a>
                <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-outline" style="border-color: #f59e0b; color: #b45309;">
                    <?= icon('file-spreadsheet', 'lucide-xs') ?>
                    <span>Bulk CSV Import</span>
                </a>
                <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn btn-amber">
                    <?= icon('shield-alert', 'lucide-xs') ?>
                    <span>KYC Review Queue</span>
                </a>
                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline" style="border-color: #10b981; color: #047857;">
                    <?= icon('wallet', 'lucide-xs') ?>
                    <span>Seller Payouts</span>
                </a>
                <a href="<?= e(url('pages/dashboard/reports.php')) ?>" class="btn btn-outline">
                    <?= icon('bar-chart-3', 'lucide-xs') ?>
                    <span>System Reports</span>
                </a>
                <a href="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="btn btn-outline" style="border-color: #6366f1; color: #6366f1;">
                    <?= icon('file-text', 'lucide-xs') ?>
                    <span>Audit Logs</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($needsKycBanner): ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #f59e0b; background: #fffbeb;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #92400e; font-size: 1rem;">
                        <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                        <span><?= e($kycBannerRole) ?> Compliance Verification Required</span>
                    </strong>
                    <p style="margin: 0.25rem 0 0 0; color: #b45309; font-size: 0.9rem;">
                        Your account status is currently <strong><?= e(ucfirst($kycCurrentStatus)) ?></strong>. Submit your Kenyan National ID and Safaricom M-Pesa line to unlock full marketplace permissions (publishing products or claiming delivery jobs).
                    </p>
                </div>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-amber btn-sm">
                    <?= icon('shield-check', 'lucide-xs') ?>
                    <span>Complete KYC Verification</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($role === 'seller' && !empty($myLowStockItems)): ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #ef4444; background: #fff5f5;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #991b1b; font-size: 1.05rem;">
                        <?= icon('alert-triangle', 'lucide-xs', 'rose') ?>
                        <span>Immediate Restock Attention: <?= count($myLowStockItems) ?> product(s) at or below critical inventory threshold!</span>
                    </strong>
                    <p style="margin: 0.35rem 0 0.75rem 0; color: #7f1d1d; font-size: 0.9rem;">
                        The following items have reached automated alert levels and risk customer order fulfillment:
                    </p>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                        <?php foreach (array_slice($myLowStockItems, 0, 5) as $lsi): ?>
                            <span class="badge <?= $lsi['stock_qty'] <= 0 ? 'badge-danger' : 'badge-amber' ?>" style="font-size: 0.8rem; padding: 0.3rem 0.6rem;">
                                <?= e($lsi['name']) ?>: <strong><?= (int) $lsi['stock_qty'] ?> left</strong> (Alert &le; <?= (int) $lsi['low_stock_threshold'] ?>)
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($myLowStockItems) > 5): ?>
                            <span class="muted" style="font-size: 0.8rem; align-self: center;">+<?= count($myLowStockItems) - 5 ?> more</span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?= e(url('pages/dashboard/manage_products.php?filter=low_stock')) ?>" class="btn btn-primary btn-sm" style="background: #dc2626; border-color: #dc2626; align-self: center;">
                    <?= icon('plus', 'lucide-xs') ?>
                    <span>Restock Inventory</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
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

    <?php if ($role === 'buyer' && !empty($buyerAlerts)): ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 5px solid #6366f1;">
            <div class="section-heading" style="margin-bottom: 0.75rem;">
                <h2>🔔 Live Marketplace Updates &amp; Restock Alerts</h2>
                <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="btn btn-outline btn-sm">View All Alerts &rarr;</a>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <?php foreach ($buyerAlerts as $ba): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f8fafc; border-radius: 6px; flex-wrap: wrap; gap: 0.5rem; border: 1px solid #e2e8f0;">
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.2rem;">
                                <?= e(date('M d, Y H:i', strtotime($ba['created_at']))) ?>
                            </div>
                            <strong style="color: #0f172a; font-size: 0.95rem;"><?= e($ba['title']) ?></strong>
                            <p style="margin: 0.2rem 0 0 0; color: #475569; font-size: 0.85rem;"><?= e($ba['message']) ?></p>
                        </div>
                        <?php if (!empty($ba['link_url'])): ?>
                            <a href="<?= e(url($ba['link_url'])) ?>" class="btn btn-primary btn-sm" style="font-size: 0.8rem; padding: 0.25rem 0.65rem;">
                                View Details &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="section-heading">
            <h2>Recent Activity</h2>
            <a href="<?= e(url($role === 'delivery' ? 'pages/dashboard/deliveries.php' : 'pages/buyer/orders.php')) ?>" class="btn btn-outline btn-sm">View All</a>
        </div>

        <?php if (empty($recentRows)): ?>
            <p class="muted">No activity recorded yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <?php if ($role === 'buyer'): ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Total</th>
                            <th>Order Status</th>
                            <th>Delivery Status</th>
                            <th>Placed On</th>
                        </tr>
                    <?php elseif ($role === 'seller'): ?>
                        <tr>
                            <th>Order Code</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Gross</th>
                            <th>2% Fee</th>
                            <th>Net Earned</th>
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
                        <?php if ($role === 'buyer'): ?>
                            <?php $oBadge = get_status_badge($row['order_status']); ?>
                            <?php $dBadge = get_status_badge($row['delivery_status'] ?? 'pending'); ?>
                            <tr>
                                <td><strong><?= e($row['order_code']) ?></strong></td>
                                <td><?= format_ksh($row['total_amount']) ?></td>
                                <td><span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span></td>
                                <td><span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span></td>
                                <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
                            </tr>
                        <?php elseif ($role === 'seller'): ?>
                            <?php
                            $oBadge = get_status_badge($row['order_status']);
                            $itemGross = (float) $row['line_total'];
                            $itemFee = isset($row['platform_fee']) && $row['platform_fee'] > 0 ? (float) $row['platform_fee'] : round($itemGross * 0.02, 2);
                            $itemEarn = isset($row['seller_earnings']) && $row['seller_earnings'] > 0 ? (float) $row['seller_earnings'] : round($itemGross - $itemFee, 2);
                            ?>
                            <tr>
                                <td><strong><?= e($row['order_code']) ?></strong></td>
                                <td><?= e($row['product_name']) ?></td>
                                <td><?= (int) $row['quantity'] ?></td>
                                <td><?= format_ksh($itemGross) ?></td>
                                <td style="color: #dc2626; font-size: 0.85rem;">-<?= format_ksh($itemFee) ?></td>
                                <td style="color: #15803d; font-weight: 700;"><?= format_ksh($itemEarn) ?></td>
                                <td><span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span></td>
                                <td><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
                            </tr>
                        <?php elseif ($role === 'delivery'): ?>
                            <?php $dBadge = get_status_badge($row['delivery_status']); ?>
                            <tr>
                                <td><strong><?= e($row['order_code']) ?></strong></td>
                                <td><?= e($row['pickup_town']) ?></td>
                                <td><?= e($row['dropoff_address']) ?></td>
                                <td><?= format_ksh($row['delivery_fee']) ?></td>
                                <td><span class="badge <?= e($dBadge['class']) ?>"><?= e($dBadge['label']) ?></span></td>
                            </tr>
                        <?php else: ?>
                            <?php $oBadge = get_status_badge($row['order_status']); ?>
                            <tr>
                                <td><strong><?= e($row['order_code']) ?></strong></td>
                                <td><?= e($row['buyer_name']) ?></td>
                                <td><?= format_ksh($row['total_amount']) ?></td>
                                <td><span class="badge <?= e($oBadge['class']) ?>"><?= e($oBadge['label']) ?></span></td>
                                <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
