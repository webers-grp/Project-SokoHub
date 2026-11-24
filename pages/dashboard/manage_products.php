<?php
/**
 * SokoHub Product Management — Full CRUD (Create, Read, Update, Delete)
 * Accessible to authenticated Sellers (own products) and Admins (all products).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/inventory.php';
init_session();
$user = require_role(['seller', 'admin']);
$pdo = get_db_connection();

$isAdmin = ($user['role'] === 'admin');
$userId = (int) $user['id'];
$isVerifiedSeller = $isAdmin || Kyc::isSellerApproved($userId);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$presetImages = get_preset_product_images();
$errors = [];

// Handle Quick Restock Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_restock') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/manage_products.php');
    }

    $prodId = (int) ($_POST['product_id'] ?? 0);
    $addUnits = max(1, (int) ($_POST['add_stock'] ?? 0));

    $check = $pdo->prepare('SELECT id, seller_id, stock_qty, name FROM products WHERE id = :id LIMIT 1');
    $check->execute([':id' => $prodId]);
    $target = $check->fetch();

    if ($target && ($isAdmin || (int) $target['seller_id'] === $userId)) {
        $newTotal = (int) $target['stock_qty'] + $addUnits;
        $res = Inventory::replenishStock($prodId, $newTotal, $userId, $user['role']);
        if ($res['ok']) {
            set_flash('success', 'Replenished "' . $target['name'] . '" by +' . $addUnits . ' units! Current stock: ' . $newTotal . '.');
        } else {
            set_flash('error', $res['error']);
        }
    } else {
        set_flash('error', 'Product not found or you do not have permission to restock it.');
    }
    redirect('pages/dashboard/manage_products.php');
}

// Handle Delete Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('pages/dashboard/manage_products.php');
    }

    $prodId = (int) ($_POST['product_id'] ?? 0);
    $check = $pdo->prepare('SELECT id, seller_id, name FROM products WHERE id = :id LIMIT 1');
    $check->execute([':id' => $prodId]);
    $target = $check->fetch();

    if (!$target || (!$isAdmin && (int) $target['seller_id'] !== $userId)) {
        set_flash('error', 'Product not found or you do not have permission to delete it.');
        redirect('pages/dashboard/manage_products.php');
    }

    // Check if product is referenced in historical orders
    $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = :id');
    $usedStmt->execute([':id' => $prodId]);
    $hasOrders = ((int) $usedStmt->fetchColumn()) > 0;

    if ($hasOrders) {
        $arch = $pdo->prepare("UPDATE products SET status = 'archived' WHERE id = :id");
        $arch->execute([':id' => $prodId]);
        Audit::record('product.archived', 'product', $prodId, ['name' => $target['name'], 'reason' => 'has_order_history']);
        set_flash('info', '"' . $target['name'] . '" has existing order history, so it was archived safely instead of hard-deleted.');
    } else {
        $del = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $del->execute([':id' => $prodId]);
        Audit::record('product.deleted', 'product', $prodId, ['name' => $target['name'], 'hard_deleted' => true]);
        set_flash('success', 'Product "' . $target['name'] . '" deleted permanently.');
    }
    redirect('pages/dashboard/manage_products.php');
}

// Handle Create or Update Action (POST)
$editId = (int) ($_GET['edit'] ?? $_POST['product_id'] ?? 0);
$editingProduct = null;

if ($editId > 0) {
    $eStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $eStmt->execute([':id' => $editId]);
    $editingProduct = $eStmt->fetch();

    if (!$editingProduct || (!$isAdmin && (int) $editingProduct['seller_id'] !== $userId)) {
        set_flash('error', 'You do not have permission to edit that product.');
        redirect('pages/dashboard/manage_products.php');
    }
}

$initialRegularPrice = '';
$initialDiscountPercent = '';

if ($editingProduct !== null) {
    if ($editingProduct['original_price'] !== null && (float) $editingProduct['original_price'] > (float) $editingProduct['price']) {
        $initialRegularPrice = (string) $editingProduct['original_price'];
        $initialDiscountPercent = (string) discount_percent((float) $editingProduct['price'], (float) $editingProduct['original_price']);
    } else {
        $initialRegularPrice = (string) $editingProduct['price'];
        $initialDiscountPercent = '';
    }
}

$form = [
    'name'                => $editingProduct['name'] ?? '',
    'category_id'         => $editingProduct['category_id'] ?? ($categories[0]['id'] ?? 1),
    'description'         => $editingProduct['description'] ?? '',
    'regular_price'       => $initialRegularPrice,
    'discount_percent'    => $initialDiscountPercent,
    'stock_qty'           => $editingProduct['stock_qty'] ?? '10',
    'low_stock_threshold' => $editingProduct['low_stock_threshold'] ?? '5',
    'condition_type'      => $editingProduct['condition_type'] ?? 'New',
    'image_path'          => $editingProduct['image_path'] ?? 'assets/img/cart-icon.jpg',
    'is_featured'         => $editingProduct['is_featured'] ?? 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } elseif (!$isVerifiedSeller) {
        $errors[] = 'KYC Compliance Required: You must submit your Kenyan National ID, Safaricom M-Pesa line, and verification documents and obtain admin approval before publishing products. Please visit the KYC Verification page to complete your submission.';
    } else {
        $form['name'] = trim((string) ($_POST['name'] ?? ''));
        $form['category_id'] = (int) ($_POST['category_id'] ?? 0);
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['regular_price'] = trim((string) ($_POST['regular_price'] ?? ($_POST['price'] ?? '')));
        $form['discount_percent'] = trim((string) ($_POST['discount_percent'] ?? ''));
        $form['stock_qty'] = trim((string) ($_POST['stock_qty'] ?? '0'));
        $form['low_stock_threshold'] = max(1, (int) ($_POST['low_stock_threshold'] ?? 5));
        $form['condition_type'] = (string) ($_POST['condition_type'] ?? 'New');
        $form['is_featured'] = !empty($_POST['is_featured']) ? 1 : 0;

        $chosenPreset = (string) ($_POST['preset_image'] ?? $form['image_path']);
        if (!array_key_exists($chosenPreset, $presetImages) && !str_starts_with($chosenPreset, 'assets/img/uploads/')) {
            $chosenPreset = 'assets/img/cart-icon.jpg';
        }
        $form['image_path'] = $chosenPreset;

        $validationPayload = $_POST;
        $validationPayload['price'] = $form['regular_price'];
        $validationPayload['discount_percent'] = $form['discount_percent'];
        $errors = validate_product_fields($validationPayload);

        if (empty($errors) && isset($_FILES['image_file'])) {
            [$uploadedPath, $uploadErr] = handle_product_image_upload($_FILES['image_file'], $form['image_path']);
            if ($uploadErr !== null) {
                $errors[] = $uploadErr;
            } else {
                $form['image_path'] = $uploadedPath;
            }
        }

        if (empty($errors)) {
            $regPrice = (float) $form['regular_price'];
            $discPct = $form['discount_percent'] !== '' ? (float) $form['discount_percent'] : null;
            $pricing = calculate_discount_pricing($regPrice, $discPct);
            $finalPrice = $pricing['price'];
            $origVal = $pricing['original_price'];

            if ($editingProduct !== null) {
                $upd = $pdo->prepare(
                    'UPDATE products
                     SET category_id = :cat, name = :name, description = :descr,
                         price = :price, original_price = :orig, stock_qty = :stock,
                         low_stock_threshold = :threshold,
                         condition_type = :cond, image_path = :img, is_featured = :feat
                     WHERE id = :id'
                );
                $upd->execute([
                    ':cat'       => $form['category_id'],
                    ':name'      => $form['name'],
                    ':descr'     => $form['description'],
                    ':price'     => $finalPrice,
                    ':orig'      => $origVal,
                    ':stock'     => (int) $form['stock_qty'],
                    ':threshold' => (int) $form['low_stock_threshold'],
                    ':cond'      => $form['condition_type'],
                    ':img'       => $form['image_path'],
                    ':feat'      => $form['is_featured'],
                    ':id'        => (int) $editingProduct['id'],
                ]);
                Audit::record('product.updated', 'product', (int) $editingProduct['id'], [
                    'name'      => $form['name'],
                    'price'     => $finalPrice,
                    'stock'     => (int) $form['stock_qty'],
                    'threshold' => (int) $form['low_stock_threshold'],
                ]);
                Inventory::checkProductLowStock((int) $editingProduct['id'], 'Product manual update');
                set_flash('success', 'Product "' . $form['name'] . '" updated successfully.');
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO products
                     (seller_id, category_id, name, description, price, original_price, stock_qty, low_stock_threshold, condition_type, image_path, is_featured, status)
                     VALUES (:seller, :cat, :name, :descr, :price, :orig, :stock, :threshold, :cond, :img, :feat, 'active')"
                );
                $ins->execute([
                    ':seller'    => $userId,
                    ':cat'       => $form['category_id'],
                    ':name'      => $form['name'],
                    ':descr'     => $form['description'],
                    ':price'     => $finalPrice,
                    ':orig'      => $origVal,
                    ':stock'     => (int) $form['stock_qty'],
                    ':threshold' => (int) $form['low_stock_threshold'],
                    ':cond'      => $form['condition_type'],
                    ':img'       => $form['image_path'],
                    ':feat'      => $form['is_featured'],
                ]);
                $newId = (int) $pdo->lastInsertId();
                Audit::record('product.created', 'product', $newId, [
                    'name'      => $form['name'],
                    'price'     => $finalPrice,
                    'stock'     => (int) $form['stock_qty'],
                    'threshold' => (int) $form['low_stock_threshold'],
                ]);
                Inventory::checkProductLowStock($newId, 'Initial listing creation');

                // Notify all buyers of new arrival / new stock
                require_once __DIR__ . '/../../includes/notifications.php';
                $sellerName = $user['full_name'] ?? 'Local Vendor';
                $sProf = Kyc::getSellerProfile($userId);
                if (!empty($sProf['business_name'])) {
                    $sellerName = $sProf['business_name'];
                }
                Notifications::notifyNewStock($newId, $form['name'], $finalPrice, $sellerName);

                set_flash('success', 'Product "' . $form['name'] . '" published successfully and buyers notified!');
            }

            redirect('pages/dashboard/manage_products.php');
        }
    }
}

// Read products list for table
$search = trim((string) ($_GET['search'] ?? ''));
$filter = trim((string) ($_GET['filter'] ?? ''));

$sql = "SELECT p.*, c.name AS category_name, u.full_name AS seller_name
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id
        INNER JOIN users u ON u.id = p.seller_id
        WHERE p.status = 'active'";
$params = [];

if (!$isAdmin) {
    $sql .= ' AND p.seller_id = :seller_id';
    $params[':seller_id'] = $userId;
}
if ($filter === 'low_stock') {
    $sql .= ' AND p.stock_qty <= p.low_stock_threshold';
}
if ($search !== '') {
    $sql .= ' AND p.name LIKE :q';
    $params[':q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY (p.stock_qty <= p.low_stock_threshold) DESC, (p.stock_qty <= 0) DESC, p.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$myProducts = $stmt->fetchAll();

$lowStockCount = Inventory::getLowStockCount($isAdmin ? null : $userId);

$pageTitle = 'SokoHub | Manage Products (CRUD)';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1><?= $editingProduct ? 'Edit Product #' . (int) $editingProduct['id'] : 'Product Inventory Management (CRUD)' ?></h1>
            <p class="muted">Create, view, update, and delete marketplace listings stored in MySQL.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-amber">
                <?= icon('file-spreadsheet', 'lucide-xs') ?>
                <span>Bulk CSV Upload</span>
            </a>
            <?php if ($editingProduct): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-outline">
                    <?= icon('x', 'lucide-xs') ?>
                    <span>Cancel Edit / Add New</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isAdmin): 
        $sellerKycStatus = Kyc::getSellerProfile($userId)['verification_status'] ?? 'pending';
    ?>
        <?php if ($sellerKycStatus !== 'approved'): ?>
            <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #f59e0b; background: #fffbeb;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong style="color: #92400e; font-size: 1rem;">⚠️ Merchant KYC Verification Required</strong>
                        <p style="margin: 0.25rem 0 0 0; color: #b45309; font-size: 0.9rem;">
                            Your merchant account is currently <strong><?= e(ucfirst($sellerKycStatus)) ?></strong>. You must submit your Kenyan National ID and Safaricom M-Pesa line and receive admin approval before publishing active listings to the marketplace.
                        </p>
                    </div>
                    <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-amber btn-sm">Complete KYC Verification &rarr;</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($lowStockCount > 0 && empty($editingProduct)): ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #ef4444; background: #fff5f5;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #991b1b; font-size: 1rem;">⚠️ Low Stock Inventory Warning: <?= (int) $lowStockCount ?> listing(s) at or below critical threshold!</strong>
                    <p style="margin: 0.25rem 0 0 0; color: #b91c1c; font-size: 0.9rem;">
                        Products reaching their alert threshold risk running out of stock for marketplace shoppers.
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <a href="<?= e(url('pages/dashboard/manage_products.php' . ($filter === 'low_stock' ? '' : '?filter=low_stock'))) ?>" class="btn <?= $filter === 'low_stock' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="<?= $filter === 'low_stock' ? '' : 'color: #991b1b; border-color: #ef4444;' ?>">
                        <?= $filter === 'low_stock' ? 'Show All Products' : 'Filter Low Stock (' . $lowStockCount . ')' ?>
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-bottom: 1rem; color: #15803d;">
            <?= $editingProduct ? 'Update Product Details' : 'Add New Product' ?>
        </h2>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <div>
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?= e(url('pages/dashboard/manage_products.php' . ($editingProduct ? '?edit=' . (int) $editingProduct['id'] : ''))) ?>" method="POST" enctype="multipart/form-data" data-validate="true">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($editingProduct): ?>
                <input type="hidden" name="product_id" value="<?= (int) $editingProduct['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="name">Product Name</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($form['name']) ?>" required data-minlength="3" placeholder="e.g. 6kg LPG Gas Refill">
                </div>

                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= (int) $form['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="regular_price">Regular Price (KSh) *</label>
                    <input type="number" step="0.01" min="1" id="regular_price" name="regular_price" class="form-control" value="<?= e($form['regular_price']) ?>" required placeholder="e.g. 1200.00">
                    <small class="muted" style="font-size:0.75rem;">Standard non-discounted selling price.</small>
                </div>

                <div class="form-group">
                    <label for="discount_percent">Discount Percentage (Optional, % Off)</label>
                    <input type="number" step="1" min="0" max="90" id="discount_percent" name="discount_percent" class="form-control" value="<?= e($form['discount_percent']) ?>" placeholder="e.g. 15 for 15% off">
                    <small class="muted" style="font-size:0.75rem;">Optional: 0–90% off. Leave blank if selling at regular price.</small>
                </div>
            </div>

            <!-- Dynamic Live Price Calculation Preview -->
            <div id="priceCalculationPreview" style="margin-top: -0.5rem; margin-bottom: 1.25rem; padding: 0.75rem 1rem; border-radius: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; font-size: 0.88rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <?= icon('tag', 'lucide-xs', 'emerald') ?>
                    <span id="priceCalcSummary" style="font-weight: 600; color: #0f172a;">
                        Enter regular price above to view final sale price.
                    </span>
                </div>
                <span id="priceCalcSavings" class="badge badge-success" style="display: none;">
                    0% Off
                </span>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="stock_qty">Current Available Stock Units *</label>
                    <input type="number" min="0" id="stock_qty" name="stock_qty" class="form-control" value="<?= e($form['stock_qty']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="low_stock_threshold">Low-Stock Alert Threshold *</label>
                    <input type="number" min="1" id="low_stock_threshold" name="low_stock_threshold" class="form-control" value="<?= e($form['low_stock_threshold']) ?>" required>
                    <small class="muted" style="font-size:0.75rem;">Triggers automated warning when stock is &le; this number (Default: 5).</small>
                </div>
            </div>

            <div class="form-group">
                <label for="condition_type">Condition</label>
                <select id="condition_type" name="condition_type" class="form-control" required>
                    <option value="New" <?= $form['condition_type'] === 'New' ? 'selected' : '' ?>>New</option>
                    <option value="Second Hand" <?= $form['condition_type'] === 'Second Hand' ? 'selected' : '' ?>>Second Hand</option>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="preset_image">Select Built-In Marketplace Image</label>
                    <select id="preset_image" name="preset_image" class="form-control">
                        <?php if (str_starts_with((string) $form['image_path'], 'assets/img/uploads/')): ?>
                            <option value="<?= e($form['image_path']) ?>" selected>Keep Uploaded Image</option>
                        <?php endif; ?>
                        <?php foreach ($presetImages as $imgPath => $imgLabel): ?>
                            <option value="<?= e($imgPath) ?>" <?= $form['image_path'] === $imgPath ? 'selected' : '' ?>>
                                <?= e($imgLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="image_file">Or Upload Custom Product Image (JPG/PNG/WEBP, max 2MB)</label>
                    <input type="file" id="image_file" name="image_file" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                </div>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3" required data-minlength="10" placeholder="Describe quality, size, warranty, or delivery availability..."><?= e($form['description']) ?></textarea>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" id="is_featured" name="is_featured" value="1" <?= !empty($form['is_featured']) ? 'checked' : '' ?>>
                <label for="is_featured" style="margin: 0;">Feature this product on the SokoHub Home Page</label>
            </div>

            <?php if ($isVerifiedSeller): ?>
                <button type="submit" class="btn btn-primary">
                    <?= icon('save', 'lucide-xs') ?>
                    <span><?= $editingProduct ? 'Save Changes' : 'Create Product' ?></span>
                </button>
            <?php else: ?>
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" disabled style="opacity: 0.6; cursor: not-allowed;" title="Admin verification required">
                        <?= icon('lock', 'lucide-xs') ?>
                        <span><?= $editingProduct ? 'Save Changes (KYC Required)' : 'Create Product (KYC Required)' ?></span>
                    </button>
                    <span style="color: #ef4444; font-size: 0.85rem; font-weight: 600;">
                        ⚠️ Verification required to publish listings. <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" style="text-decoration: underline; color: #b45309;">Complete KYC &rarr;</a>
                    </span>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="section-heading" style="flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <h2>Current Listings (<?= count($myProducts) ?>)</h2>
                <?php if ($filter === 'low_stock'): ?>
                    <span class="badge badge-danger">Filtered: Critical &amp; Low Stock Only</span>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <?php if ($lowStockCount > 0): ?>
                    <a href="<?= e(url('pages/dashboard/manage_products.php' . ($filter === 'low_stock' ? '' : '?filter=low_stock'))) ?>" class="btn <?= $filter === 'low_stock' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="<?= $filter === 'low_stock' ? '' : 'color: #b91c1c; border-color: #ef4444;' ?>">
                        <?= icon('alert-triangle', 'lucide-xs', 'amber') ?>
                        <span><?= $filter === 'low_stock' ? 'Show All Products' : 'Low Stock Alerts (' . $lowStockCount . ')' ?></span>
                    </a>
                <?php endif; ?>
                <form action="<?= e(url('pages/dashboard/manage_products.php')) ?>" method="GET" style="display:flex; gap:0.5rem;">
                    <?php if ($filter !== ''): ?>
                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Search listing..." style="max-width:200px;">
                    <button type="submit" class="btn btn-outline btn-sm">
                        <?= icon('search', 'lucide-xs') ?>
                        <span>Search</span>
                    </button>
                </form>
            </div>
        </div>

        <?php if (empty($myProducts)): ?>
            <p class="muted">No active products found matching the criteria. Use the form above to add your first listing!</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product</th>
                            <th>Category</th>
                            <?php if ($isAdmin): ?><th>Seller</th><?php endif; ?>
                            <th>Price</th>
                            <th>Stock &amp; Alert Level</th>
                            <th>Condition</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myProducts as $prod): 
                        $sq = (int) $prod['stock_qty'];
                        $th = (int) ($prod['low_stock_threshold'] ?? 5);
                    ?>
                        <tr>
                            <td><img src="<?= e(url($prod['image_path'])) ?>" alt="" class="table-thumb"></td>
                            <td>
                                <strong><?= e($prod['name']) ?></strong>
                                <?php if ((int) $prod['is_featured'] === 1): ?>
                                    <span class="badge badge-warning">Featured</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($prod['category_name']) ?></td>
                            <?php if ($isAdmin): ?><td><?= e($prod['seller_name']) ?></td><?php endif; ?>
                            <td><?= format_ksh($prod['price']) ?></td>
                            <td>
                                <?php if ($sq <= 0): ?>
                                    <span class="badge badge-danger" style="font-size:0.75rem;">Out of Stock (0)</span>
                                    <div class="small muted" style="font-size:0.7rem; margin-top:2px;">Threshold: <?= $th ?></div>
                                <?php elseif ($sq <= $th): ?>
                                    <span class="badge badge-amber" style="background:#fef3c7; color:#92400e; border:1px solid #f59e0b; font-size:0.75rem;">
                                        ⚠️ Low: <?= $sq ?> left
                                    </span>
                                    <div class="small muted" style="font-size:0.7rem; margin-top:2px;">Alert &le; <?= $th ?></div>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size:0.75rem;"><?= $sq ?> units</span>
                                    <div class="small muted" style="font-size:0.7rem; margin-top:2px;">Alert &le; <?= $th ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($prod['condition_type']) ?></td>
                            <td>
                                <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                                    <a href="<?= e(url('pages/dashboard/manage_products.php?edit=' . (int) $prod['id'])) ?>" class="btn btn-outline btn-sm" style="padding:0.25rem 0.5rem; font-size:0.8rem;">
                                        <?= icon('edit-3', 'lucide-xs', 'blue') ?>
                                        <span>Edit</span>
                                    </a>

                                    <?php if ($sq <= $th): ?>
                                        <details style="position: relative; display: inline-block;">
                                            <summary class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.45rem; color: #16a34a; border-color: #16a34a; cursor: pointer;">
                                                <?= icon('plus', 'lucide-xs') ?>
                                                <span>Restock</span>
                                            </summary>
                                            <div style="position: absolute; right: 0; z-index: 50; background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.6rem; box-shadow: 0 6px 18px rgba(0,0,0,0.15); min-width: 150px; text-align: left;">
                                                <form action="<?= e(url('pages/dashboard/manage_products.php')) ?>" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="quick_restock">
                                                    <input type="hidden" name="product_id" value="<?= (int) $prod['id'] ?>">
                                                    <label style="font-size: 0.75rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">Add Units:</label>
                                                    <input type="number" name="add_stock" value="10" min="1" max="1000" class="form-control" style="font-size: 0.8rem; padding: 0.2rem 0.4rem; margin-bottom: 0.4rem;" required>
                                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.75rem; width: 100%; padding: 0.25rem;">Confirm Restock</button>
                                                </form>
                                            </div>
                                        </details>
                                    <?php endif; ?>

                                    <form action="<?= e(url('pages/dashboard/manage_products.php')) ?>" method="POST" style="display:inline; margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="product_id" value="<?= (int) $prod['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding:0.25rem 0.45rem; font-size:0.8rem;" data-confirm="Delete product '<?= e($prod['name']) ?>'?">
                                            <?= icon('trash-2', 'lucide-xs') ?>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const regularInput = document.getElementById('regular_price');
    const discountInput = document.getElementById('discount_percent');
    const summaryEl = document.getElementById('priceCalcSummary');
    const savingsEl = document.getElementById('priceCalcSavings');
    const previewBox = document.getElementById('priceCalculationPreview');

    function updatePricePreview() {
        if (!regularInput || !summaryEl) return;

        const regular = parseFloat(regularInput.value) || 0;
        let discount = parseFloat(discountInput ? discountInput.value : 0) || 0;

        if (discount < 0) discount = 0;
        if (discount > 99) discount = 99;

        if (regular <= 0) {
            summaryEl.innerHTML = 'Enter regular price above to calculate final sale price.';
            savingsEl.style.display = 'none';
            if (previewBox) {
                previewBox.style.background = '#f8fafc';
                previewBox.style.borderColor = '#e2e8f0';
            }
            return;
        }

        if (discount > 0) {
            const finalPrice = Math.round((regular * (1 - discount / 100)) * 100) / 100;
            const savedAmount = Math.round((regular - finalPrice) * 100) / 100;
            summaryEl.innerHTML = `Final Selling Price: <strong style="color: #15803d; font-size: 1.05rem;">KSh ${finalPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> <span style="color: #64748b; text-decoration: line-through; margin-left: 6px; font-size: 0.9rem;">KSh ${regular.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
            savingsEl.textContent = `${Math.round(discount)}% OFF (Save KSh ${savedAmount.toLocaleString('en-US')})`;
            savingsEl.style.display = 'inline-block';
            if (previewBox) {
                previewBox.style.background = '#f0fdf4';
                previewBox.style.borderColor = '#bbf7d0';
            }
        } else {
            summaryEl.innerHTML = `Standard Selling Price: <strong style="color: #0f172a; font-size: 1.05rem;">KSh ${regular.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> (No discount applied)`;
            savingsEl.style.display = 'none';
            if (previewBox) {
                previewBox.style.background = '#f8fafc';
                previewBox.style.borderColor = '#e2e8f0';
            }
        }
    }

    if (regularInput) {
        regularInput.addEventListener('input', updatePricePreview);
    }
    if (discountInput) {
        discountInput.addEventListener('input', updatePricePreview);
    }

    // Run baseline calculation on page load
    updatePricePreview();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
