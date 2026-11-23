<?php
/**
 * SokoHub Analytical Reports Module
 * Provides 4 database-driven reports (Sales & Revenue, Inventory & Low-Stock,
 * Delivery Performance, and User/Vendor Breakdown) with charts and CSV export.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory.php';
init_session();
$user = require_role(['seller', 'delivery', 'admin']);
$pdo = get_db_connection();

$role = $user['role'];
$userId = (int) $user['id'];

$reportType = (string) ($_GET['type'] ?? ($role === 'delivery' ? 'deliveries' : 'sales'));
if (!in_array($reportType, ['sales', 'inventory', 'deliveries', 'vendors'], true)) {
    $reportType = 'sales';
}

$startDate = trim((string) ($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'))));
$endDate = trim((string) ($_GET['end_date'] ?? date('Y-m-d')));
$categoryFilter = (int) ($_GET['category_id'] ?? 0);
$exportCsv = (($_GET['export'] ?? '') === 'csv');

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

// -------------------- 1. SALES & REVENUE REPORT --------------------
$salesByCat = [];
$salesSummary = ['orders' => 0, 'units' => 0, 'revenue' => 0.0, 'avg_order' => 0.0];
$salesOrders = [];

if ($reportType === 'sales') {
    $where = ["o.order_status <> 'cancelled'", 'DATE(o.created_at) BETWEEN :start_dt AND :end_dt'];
    $params = [':start_dt' => $startDate, ':end_dt' => $endDate];

    if ($role === 'seller') {
        $where[] = 'oi.seller_id = :seller_id';
        $params[':seller_id'] = $userId;
    }
    if ($categoryFilter > 0) {
        $where[] = 'p.category_id = :cat_id';
        $params[':cat_id'] = $categoryFilter;
    }

    $whereSql = implode(' AND ', $where);

    $sumStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT o.id) AS orders,
                COALESCE(SUM(oi.quantity), 0) AS units,
                COALESCE(SUM(oi.line_total), 0) AS revenue
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN products p ON p.id = oi.product_id
         WHERE {$whereSql}"
    );
    $sumStmt->execute($params);
    $agg = $sumStmt->fetch();
    $salesSummary['orders'] = (int) $agg['orders'];
    $salesSummary['units'] = (int) $agg['units'];
    $salesSummary['revenue'] = (float) $agg['revenue'];
    $salesSummary['avg_order'] = $salesSummary['orders'] > 0 ? ($salesSummary['revenue'] / $salesSummary['orders']) : 0.0;

    $catStmt = $pdo->prepare(
        "SELECT c.name AS category_name,
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(oi.quantity), 0) AS units_sold,
                COALESCE(SUM(oi.line_total), 0) AS category_revenue
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN products p ON p.id = oi.product_id
         INNER JOIN categories c ON c.id = p.category_id
         WHERE {$whereSql}
         GROUP BY c.id, c.name
         ORDER BY category_revenue DESC"
    );
    $catStmt->execute($params);
    $salesByCat = $catStmt->fetchAll();

    $ordStmt = $pdo->prepare(
        "SELECT o.order_code, DATE(o.created_at) AS order_date, o.payment_method, o.order_status,
                p.name AS product_name, oi.quantity, oi.line_total
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN products p ON p.id = oi.product_id
         WHERE {$whereSql}
         ORDER BY o.created_at DESC
         LIMIT 50"
    );
    $ordStmt->execute($params);
    $salesOrders = $ordStmt->fetchAll();

    if ($exportCsv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sokohub_sales_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order Code', 'Date', 'Product', 'Quantity', 'Line Total (KSh)', 'Payment Method', 'Order Status']);
        foreach ($salesOrders as $r) {
            fputcsv($out, [$r['order_code'], $r['order_date'], $r['product_name'], $r['quantity'], $r['line_total'], $r['payment_method'], $r['order_status']]);
        }
        fclose($out);
        exit;
    }
}

// -------------------- 2. INVENTORY & LOW-STOCK REPORT --------------------
$invRows = [];
$invByCat = [];
$invSummary = ['skus' => 0, 'total_units' => 0, 'valuation' => 0.0, 'low_stock' => 0];

if ($reportType === 'inventory') {
    $where = ["p.status = 'active'"];
    $params = [];
    if ($role === 'seller') {
        $where[] = 'p.seller_id = :sid';
        $params[':sid'] = $userId;
    }
    if ($categoryFilter > 0) {
        $where[] = 'p.category_id = :cid';
        $params[':cid'] = $categoryFilter;
    }
    $whereSql = implode(' AND ', $where);

    $invStmt = $pdo->prepare(
        "SELECT p.id, p.name, c.name AS category_name, p.condition_type, p.price, p.stock_qty,
                p.low_stock_threshold,
                (p.price * p.stock_qty) AS stock_value,
                COALESCE(sp.business_name, u.full_name) AS seller_name
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         INNER JOIN users u ON u.id = p.seller_id
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE {$whereSql}
         ORDER BY (p.stock_qty <= p.low_stock_threshold) DESC, p.stock_qty ASC, p.name ASC"
    );
    $invStmt->execute($params);
    $invRows = $invStmt->fetchAll();

    foreach ($invRows as $ir) {
        $invSummary['skus']++;
        $invSummary['total_units'] += (int) $ir['stock_qty'];
        $invSummary['valuation'] += (float) $ir['stock_value'];
        $threshold = (int) ($ir['low_stock_threshold'] ?? 5);
        if ((int) $ir['stock_qty'] <= $threshold) {
            $invSummary['low_stock']++;
        }
    }

    $catInvStmt = $pdo->prepare(
        "SELECT c.name AS category_name, COALESCE(SUM(p.stock_qty), 0) AS units
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'" . ($role === 'seller' ? ' AND p.seller_id = :sid' : '') . '
         GROUP BY c.id, c.name
         ORDER BY units DESC'
    );
    if ($role === 'seller') {
        $catInvStmt->execute([':sid' => $userId]);
    } else {
        $catInvStmt->execute();
    }
    $invByCat = $catInvStmt->fetchAll();

    if ($exportCsv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sokohub_inventory_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Product', 'Category', 'Seller', 'Condition', 'Unit Price (KSh)', 'Stock Qty', 'Low Stock Threshold', 'Status', 'Inventory Value (KSh)']);
        foreach ($invRows as $r) {
            $sq = (int) $r['stock_qty'];
            $th = (int) ($r['low_stock_threshold'] ?? 5);
            $st = ($sq <= 0) ? 'Out of Stock' : (($sq <= $th) ? 'Low Stock' : 'Adequate');
            fputcsv($out, [$r['id'], $r['name'], $r['category_name'], $r['seller_name'], $r['condition_type'], $r['price'], $sq, $th, $st, $r['stock_value']]);
        }
        fclose($out);
        exit;
    }
}

// -------------------- 3. DELIVERY PERFORMANCE REPORT --------------------
$delByStatus = [];
$riderRows = [];
$delSummary = ['total' => 0, 'delivered' => 0, 'rate' => 0.0, 'fees' => 0.0];

if ($reportType === 'deliveries') {
    $delByStatus = $pdo->query(
        'SELECT delivery_status, COUNT(*) AS cnt, COALESCE(SUM(delivery_fee), 0) AS total_fees
         FROM deliveries
         GROUP BY delivery_status
         ORDER BY cnt DESC'
    )->fetchAll();

    foreach ($delByStatus as $ds) {
        $delSummary['total'] += (int) $ds['cnt'];
        if ($ds['delivery_status'] === 'delivered') {
            $delSummary['delivered'] += (int) $ds['cnt'];
            $delSummary['fees'] += (float) $ds['total_fees'];
        }
    }
    $delSummary['rate'] = $delSummary['total'] > 0 ? round(($delSummary['delivered'] / $delSummary['total']) * 100, 1) : 0.0;

    $riderRows = $pdo->query(
        "SELECT u.full_name AS rider_name, dp.transport_method, dp.service_area,
                COUNT(d.id) AS total_assigned,
                SUM(CASE WHEN d.delivery_status = 'delivered' THEN 1 ELSE 0 END) AS completed_count,
                COALESCE(SUM(CASE WHEN d.delivery_status = 'delivered' THEN d.delivery_fee ELSE 0 END), 0) AS earned_fees
         FROM users u
         INNER JOIN delivery_profiles dp ON dp.user_id = u.id
         LEFT JOIN deliveries d ON d.delivery_person_id = u.id
         WHERE u.role = 'delivery'
         GROUP BY u.id, u.full_name, dp.transport_method, dp.service_area
         ORDER BY completed_count DESC, total_assigned DESC"
    )->fetchAll();

    if ($exportCsv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sokohub_delivery_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Rider Name', 'Transport', 'Service Area', 'Total Assigned', 'Completed', 'Earned Fees (KSh)']);
        foreach ($riderRows as $r) {
            fputcsv($out, [$r['rider_name'], $r['transport_method'], $r['service_area'], $r['total_assigned'], $r['completed_count'], $r['earned_fees']]);
        }
        fclose($out);
        exit;
    }
}

// -------------------- 4. USER & VENDOR BREAKDOWN REPORT --------------------
$roleCounts = [];
$topSellers = [];
$totalUsersCount = 0;

if ($reportType === 'vendors') {
    $roleCounts = $pdo->query(
        'SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY cnt DESC'
    )->fetchAll();
    foreach ($roleCounts as $rc) {
        $totalUsersCount += (int) $rc['cnt'];
    }

    $topSellers = $pdo->query(
        "SELECT u.full_name AS owner_name,
                COALESCE(sp.business_name, u.full_name) AS business_name,
                COALESCE(sp.town, 'Kutus') AS town,
                COUNT(DISTINCT p.id) AS product_count,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN oi.quantity ELSE 0 END), 0) AS units_sold,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN oi.line_total ELSE 0 END), 0) AS gross_revenue
         FROM users u
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         LEFT JOIN products p ON p.seller_id = u.id AND p.status = 'active'
         LEFT JOIN order_items oi ON oi.seller_id = u.id
         LEFT JOIN orders o ON o.id = oi.order_id
         WHERE u.role = 'seller'
         GROUP BY u.id, u.full_name, sp.business_name, sp.town
         ORDER BY gross_revenue DESC"
    )->fetchAll();

    if ($exportCsv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sokohub_vendors_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Business Name', 'Owner', 'Town', 'Active Products', 'Units Sold', 'Gross Revenue (KSh)']);
        foreach ($topSellers as $r) {
            fputcsv($out, [$r['business_name'], $r['owner_name'], $r['town'], $r['product_count'], $r['units_sold'], $r['gross_revenue']]);
        }
        fclose($out);
        exit;
    }
}

$pageTitle = 'SokoHub | Analytics & Reports';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Marketplace Reports &amp; Analytics</h1>
            <p class="muted">Real-time MySQL summaries, charts, and CSV exports across sales, stock, deliveries, and vendors.</p>
        </div>
        <div class="hero-cta no-print">
            <a href="<?= e(url('pages/dashboard/reports.php?' . http_build_query(['type' => $reportType, 'start_date' => $startDate, 'end_date' => $endDate, 'category_id' => $categoryFilter, 'export' => 'csv']))) ?>" class="btn btn-amber">
                <?= icon('download', 'lucide-sm', 'white') ?>
                <span>Export CSV</span>
            </a>
            <button type="button" onclick="window.print()" class="btn btn-outline">
                <?= icon('printer', 'lucide-sm', 'blue') ?>
                <span>Print Report</span>
            </button>
        </div>
    </div>

    <div class="report-tabs no-print">
        <a href="<?= e(url('pages/dashboard/reports.php?type=sales')) ?>" class="report-tab <?= $reportType === 'sales' ? 'active' : '' ?>">
            <?= icon('trending-up', 'lucide-xs', 'emerald') ?>
            <span>1. Sales &amp; Revenue</span>
        </a>
        <a href="<?= e(url('pages/dashboard/reports.php?type=inventory')) ?>" class="report-tab <?= $reportType === 'inventory' ? 'active' : '' ?>">
            <?= icon('package', 'lucide-xs', 'amber') ?>
            <span>2. Inventory &amp; Stock Alerts</span>
        </a>
        <a href="<?= e(url('pages/dashboard/reports.php?type=deliveries')) ?>" class="report-tab <?= $reportType === 'deliveries' ? 'active' : '' ?>">
            <?= icon('truck', 'lucide-xs', 'cyan') ?>
            <span>3. Delivery Performance</span>
        </a>
        <a href="<?= e(url('pages/dashboard/reports.php?type=vendors')) ?>" class="report-tab <?= $reportType === 'vendors' ? 'active' : '' ?>">
            <?= icon('users', 'lucide-xs', 'purple') ?>
            <span>4. Users &amp; Top Vendors</span>
        </a>
    </div>

    <?php if ($reportType === 'sales'): ?>
        <div class="card no-print">
            <form action="<?= e(url('pages/dashboard/reports.php')) ?>" method="GET" class="form-grid-2" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items: end;">
                <input type="hidden" name="type" value="sales">
                <div class="form-group" style="margin:0;">
                    <label for="start_date">From Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= e($startDate) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="end_date">To Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= e($endDate) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-control">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Run Sales Report</button>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Orders</span>
                <strong class="stat-value"><?= $salesSummary['orders'] ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Units Sold</span>
                <strong class="stat-value"><?= $salesSummary['units'] ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Gross Revenue</span>
                <strong class="stat-value"><?= format_ksh($salesSummary['revenue']) ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Avg Revenue / Order</span>
                <strong class="stat-value"><?= format_ksh($salesSummary['avg_order']) ?></strong>
            </div>
        </div>

        <div class="card">
            <h2>Revenue by Category (Chart &amp; Summary)</h2>
            <?php if (empty($salesByCat)): ?>
                <p class="muted" style="margin-top:0.5rem;">No sales transactions recorded in the selected date window.</p>
            <?php else: ?>
                <div class="chart-box">
                    <canvas
                        data-chart-labels='<?= e(json_encode(array_column($salesByCat, 'category_name'))) ?>'
                        data-chart-values='<?= e(json_encode(array_map(fn($r) => round((float) $r['category_revenue'], 2), $salesByCat))) ?>'>
                    </canvas>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Category</th><th>Orders</th><th>Units Sold</th><th>Revenue</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($salesByCat as $sc): ?>
                            <tr>
                                <td><strong><?= e($sc['category_name']) ?></strong></td>
                                <td><?= (int) $sc['order_count'] ?></td>
                                <td><?= (int) $sc['units_sold'] ?></td>
                                <td><strong><?= format_ksh($sc['category_revenue']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Detailed Sales Transactions</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Order Code</th><th>Date</th><th>Product</th><th>Qty</th><th>Line Total</th><th>Payment</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($salesOrders as $so): ?>
                        <?php $b = get_status_badge($so['order_status']); ?>
                        <tr>
                            <td><strong><?= e($so['order_code']) ?></strong></td>
                            <td><?= e($so['order_date']) ?></td>
                            <td><?= e($so['product_name']) ?></td>
                            <td><?= (int) $so['quantity'] ?></td>
                            <td><?= format_ksh($so['line_total']) ?></td>
                            <td><?= e($so['payment_method']) ?></td>
                            <td><span class="badge <?= e($b['class']) ?>"><?= e($b['label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($reportType === 'inventory'): ?>
        <div class="stats-grid">
            <div class="stat-card"><span class="stat-label">Active SKUs</span><strong class="stat-value"><?= $invSummary['skus'] ?></strong></div>
            <div class="stat-card"><span class="stat-label">Total Stock Units</span><strong class="stat-value"><?= $invSummary['total_units'] ?></strong></div>
            <div class="stat-card"><span class="stat-label">Inventory Valuation</span><strong class="stat-value"><?= format_ksh($invSummary['valuation']) ?></strong></div>
            <div class="stat-card"><span class="stat-label">Low Stock Alerts (&le; Threshold)</span><strong class="stat-value" style="color:var(--danger);"><?= $invSummary['low_stock'] ?></strong></div>
        </div>

        <div class="card">
            <h2>Stock Units by Category</h2>
            <div class="chart-box">
                <canvas
                    data-chart-labels='<?= e(json_encode(array_column($invByCat, 'category_name'))) ?>'
                    data-chart-values='<?= e(json_encode(array_map(fn($r) => (int) $r['units'], $invByCat))) ?>'>
                </canvas>
            </div>
        </div>

        <div class="card">
            <h2>Product Stock &amp; Valuation Breakdown</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Seller</th>
                            <th>Unit Price</th>
                            <th>Stock Qty</th>
                            <th>Alert Threshold</th>
                            <th>Total Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invRows as $ir): 
                        $sq = (int) $ir['stock_qty'];
                        $th = (int) ($ir['low_stock_threshold'] ?? 5);
                    ?>
                        <tr>
                            <td><strong><?= e($ir['name']) ?></strong></td>
                            <td><?= e($ir['category_name']) ?></td>
                            <td><?= e($ir['seller_name']) ?></td>
                            <td><?= format_ksh($ir['price']) ?></td>
                            <td><strong><?= $sq ?></strong></td>
                            <td>&le; <?= $th ?></td>
                            <td><?= format_ksh($ir['stock_value']) ?></td>
                            <td>
                                <?php if ($sq <= 0): ?>
                                    <span class="badge badge-danger">Out of Stock</span>
                                <?php elseif ($sq <= $th): ?>
                                    <span class="badge badge-amber" style="background:#fef3c7; color:#92400e; border:1px solid #f59e0b;">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Adequate</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($reportType === 'deliveries'): ?>
        <div class="stats-grid">
            <div class="stat-card"><span class="stat-label">Total Dispatch Tasks</span><strong class="stat-value"><?= $delSummary['total'] ?></strong></div>
            <div class="stat-card"><span class="stat-label">Completed Deliveries</span><strong class="stat-value"><?= $delSummary['delivered'] ?></strong></div>
            <div class="stat-card"><span class="stat-label">Completion Rate</span><strong class="stat-value"><?= $delSummary['rate'] ?>%</strong></div>
            <div class="stat-card"><span class="stat-label">Collected Delivery Fees</span><strong class="stat-value"><?= format_ksh($delSummary['fees']) ?></strong></div>
        </div>

        <div class="card">
            <h2>Deliveries by Status</h2>
            <div class="chart-box">
                <canvas
                    data-chart-labels='<?= e(json_encode(array_map(fn($r) => ucfirst(str_replace('_', ' ', $r['delivery_status'])), $delByStatus))) ?>'
                    data-chart-values='<?= e(json_encode(array_map(fn($r) => (int) $r['cnt'], $delByStatus))) ?>'>
                </canvas>
            </div>
        </div>

        <div class="card">
            <h2>Delivery Personnel Performance Table</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Rider Name</th><th>Transport</th><th>Service Area</th><th>Assigned</th><th>Completed</th><th>Fees Earned</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($riderRows as $rr): ?>
                        <tr>
                            <td><strong><?= e($rr['rider_name']) ?></strong></td>
                            <td><?= e(ucfirst($rr['transport_method'])) ?></td>
                            <td><?= e($rr['service_area']) ?></td>
                            <td><?= (int) $rr['total_assigned'] ?></td>
                            <td><span class="badge badge-success"><?= (int) $rr['completed_count'] ?></span></td>
                            <td><strong><?= format_ksh($rr['earned_fees']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: /* vendors */ ?>
        <div class="card">
            <h2>Registered Account Distribution (Total: <?= $totalUsersCount ?> Users)</h2>
            <div style="margin-top: 1rem;">
                <?php foreach ($roleCounts as $rc): ?>
                    <?php $pct = $totalUsersCount > 0 ? round(((int) $rc['cnt'] / $totalUsersCount) * 100, 1) : 0; ?>
                    <div class="bar-row">
                        <strong><?= e(ucfirst($rc['role'])) ?></strong>
                        <div class="bar-track"><div class="bar-fill" style="width: <?= $pct ?>%;"></div></div>
                        <span><?= (int) $rc['cnt'] ?> (<?= $pct ?>%)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Top Vendors by Revenue &amp; Catalog Size</h2>
            <div class="chart-box">
                <canvas
                    data-chart-labels='<?= e(json_encode(array_column($topSellers, 'business_name'))) ?>'
                    data-chart-values='<?= e(json_encode(array_map(fn($r) => round((float) $r['gross_revenue'], 2), $topSellers))) ?>'>
                </canvas>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Business Name</th><th>Owner</th><th>Town</th><th>Active Products</th><th>Units Sold</th><th>Gross Revenue</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topSellers as $ts): ?>
                        <tr>
                            <td><strong><?= e($ts['business_name']) ?></strong></td>
                            <td><?= e($ts['owner_name']) ?></td>
                            <td><?= e($ts['town']) ?></td>
                            <td><?= (int) $ts['product_count'] ?></td>
                            <td><?= (int) $ts['units_sold'] ?></td>
                            <td><strong><?= format_ksh($ts['gross_revenue']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
