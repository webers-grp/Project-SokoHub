<?php
/**
 * SokoHub Product Management — Full CRUD (Create, Read, Update, Delete)
 * Accessible to authenticated Sellers (own products) and Admins (all products).
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();
$user = require_role(['seller', 'admin']);
$pdo = get_db_connection();

$isAdmin = ($user['role'] === 'admin');
$userId = (int) $user['id'];

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$presetImages = get_preset_product_images();
$errors = [];

// Verify seller payout profile requirement
$hasPayoutConfigured = true;
if (!$isAdmin) {
    $profCheck = $pdo->prepare('SELECT payout_type, payout_account FROM seller_profiles WHERE user_id = :uid LIMIT 1');
    $profCheck->execute([':uid' => $userId]);
    $sellerProf = $profCheck->fetch();
    $hasPayoutConfigured = !empty($sellerProf['payout_account']);
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
        set_flash('info', '"' . $target['name'] . '" has existing order history, so it was archived safely instead of hard-deleted.');
    } else {
        $del = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $del->execute([':id' => $prodId]);
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

$form = [
    'name'           => $editingProduct['name'] ?? '',
    'category_id'    => $editingProduct['category_id'] ?? ($categories[0]['id'] ?? 1),
    'description'    => $editingProduct['description'] ?? '',
    'price'          => $editingProduct['price'] ?? '',
    'original_price' => $editingProduct['original_price'] ?? '',
    'stock_qty'      => $editingProduct['stock_qty'] ?? '10',
    'condition_type' => $editingProduct['condition_type'] ?? 'New',
    'image_path'     => $editingProduct['image_path'] ?? 'assets/img/cart-icon.jpg',
    'is_featured'    => $editingProduct['is_featured'] ?? 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $form['name'] = trim((string) ($_POST['name'] ?? ''));
        $form['category_id'] = (int) ($_POST['category_id'] ?? 0);
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['price'] = trim((string) ($_POST['price'] ?? ''));
        $form['original_price'] = trim((string) ($_POST['original_price'] ?? ''));
        $form['stock_qty'] = trim((string) ($_POST['stock_qty'] ?? '0'));
        $form['condition_type'] = (string) ($_POST['condition_type'] ?? 'New');
        $form['is_featured'] = !empty($_POST['is_featured']) ? 1 : 0;

        $chosenPreset = (string) ($_POST['preset_image'] ?? $form['image_path']);
        if (!array_key_exists($chosenPreset, $presetImages) && !str_starts_with($chosenPreset, 'assets/img/uploads/')) {
            $chosenPreset = 'assets/img/cart-icon.jpg';
        }
        $form['image_path'] = $chosenPreset;

        $errors = validate_product_fields($_POST);

        if (!$isAdmin && !$hasPayoutConfigured) {
            $errors[] = 'You must configure your M-Pesa Receive Payments payout destination before publishing products.';
        }

        if (empty($errors) && isset($_FILES['image_file'])) {
            [$uploadedPath, $uploadErr] = handle_product_image_upload($_FILES['image_file'], $form['image_path']);
            if ($uploadErr !== null) {
                $errors[] = $uploadErr;
            } else {
                $form['image_path'] = $uploadedPath;
            }
        }

        if (empty($errors)) {
            $origVal = $form['original_price'] !== '' ? (float) $form['original_price'] : null;

            if ($editingProduct !== null) {
                $upd = $pdo->prepare(
                    'UPDATE products
                     SET category_id = :cat, name = :name, description = :descr,
                         price = :price, original_price = :orig, stock_qty = :stock,
                         condition_type = :cond, image_path = :img, is_featured = :feat
                     WHERE id = :id'
                );
                $upd->execute([
                    ':cat'   => $form['category_id'],
                    ':name'  => $form['name'],
                    ':descr' => $form['description'],
                    ':price' => (float) $form['price'],
                    ':orig'  => $origVal,
                    ':stock' => (int) $form['stock_qty'],
                    ':cond'  => $form['condition_type'],
                    ':img'   => $form['image_path'],
                    ':feat'  => $form['is_featured'],
                    ':id'    => (int) $editingProduct['id'],
                ]);
                set_flash('success', 'Product "' . $form['name'] . '" updated successfully.');
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO products
                     (seller_id, category_id, name, description, price, original_price, stock_qty, condition_type, image_path, is_featured, status)
                     VALUES (:seller, :cat, :name, :descr, :price, :orig, :stock, :cond, :img, :feat, 'active')"
                );
                $ins->execute([
                    ':seller' => $userId,
                    ':cat'    => $form['category_id'],
                    ':name'   => $form['name'],
                    ':descr'  => $form['description'],
                    ':price'  => (float) $form['price'],
                    ':orig'   => $origVal,
                    ':stock'  => (int) $form['stock_qty'],
                    ':cond'   => $form['condition_type'],
                    ':img'    => $form['image_path'],
                    ':feat'   => $form['is_featured'],
                ]);
                set_flash('success', 'New product "' . $form['name'] . '" created and published!');
            }

            redirect('pages/dashboard/manage_products.php');
        }
    }
}

// Read products list for table
$search = trim((string) ($_GET['search'] ?? ''));
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
if ($search !== '') {
    $sql .= ' AND p.name LIKE :q';
    $params[':q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY p.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$myProducts = $stmt->fetchAll();

$pageTitle = 'SokoHub | Manage Products (CRUD)';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1><?= $editingProduct ? 'Edit Product #' . (int) $editingProduct['id'] : 'Product Inventory Management (CRUD)' ?></h1>
            <p class="muted">Create, view, update, and delete marketplace listings stored in MySQL.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="<?= e(url('pages/dashboard/seller_payout_settings.php')) ?>" class="btn btn-outline">
                <?= icon('wallet', 'lucide-xs', 'emerald') ?>
                <span>Receive Payments Settings</span>
            </a>
            <?php if ($editingProduct): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-outline">Cancel Edit / Add New</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isAdmin && !$hasPayoutConfigured): ?>
        <div class="alert alert-warning" style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <?= icon('alert-triangle', 'lucide-sm', 'amber') ?>
                <div>
                    <strong>Payout Destination Required to Publish</strong>
                    <p class="small muted" style="margin-top: 0.2rem;">You must set up your M-Pesa payout channel (Till, Paybill, or Pochi) before your products can be published.</p>
                </div>
            </div>
            <a href="<?= e(url('pages/dashboard/seller_payout_settings.php')) ?>" class="btn btn-primary btn-sm">Set Up Payouts Now</a>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-bottom: 1rem; color: var(--green-dark);">
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
                    <label for="price">Selling Price (KSh)</label>
                    <input type="number" step="0.01" min="1" id="price" name="price" class="form-control" value="<?= e($form['price']) ?>" required placeholder="1200">
                </div>

                <div class="form-group">
                    <label for="original_price">Original Price (Optional, KSh)</label>
                    <input type="number" step="0.01" min="1" id="original_price" name="original_price" class="form-control" value="<?= e($form['original_price']) ?>" placeholder="1800">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="stock_qty">Stock Quantity</label>
                    <input type="number" min="0" id="stock_qty" name="stock_qty" class="form-control" value="<?= e($form['stock_qty']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="condition_type">Condition</label>
                    <select id="condition_type" name="condition_type" class="form-control" required>
                        <option value="New" <?= $form['condition_type'] === 'New' ? 'selected' : '' ?>>New</option>
                        <option value="Second Hand" <?= $form['condition_type'] === 'Second Hand' ? 'selected' : '' ?>>Second Hand</option>
                    </select>
                </div>
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

            <button type="submit" class="btn btn-primary">
                <?= icon($editingProduct ? 'save' : 'plus-circle', 'lucide-sm', 'white') ?>
                <span><?= $editingProduct ? 'Save Changes' : 'Create Product' ?></span>
            </button>
        </form>
    </div>

    <div class="card">
        <div class="section-heading">
            <h2>Current Listings (<?= count($myProducts) ?>)</h2>
            <form action="<?= e(url('pages/dashboard/manage_products.php')) ?>" method="GET" style="display:flex; gap:0.5rem;">
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Search listing..." style="max-width:220px;">
                <button type="submit" class="btn btn-outline btn-sm">
                    <?= icon('search', 'lucide-xs', 'blue') ?>
                    <span>Search</span>
                </button>
            </form>
        </div>

        <?php if (empty($myProducts)): ?>
            <p class="muted">No active products found. Use the form above to add your first listing!</p>
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
                            <th>Stock</th>
                            <th>Condition</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myProducts as $prod): ?>
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
                                <?php if ((int) $prod['stock_qty'] <= 5): ?>
                                    <span class="badge badge-danger"><?= (int) $prod['stock_qty'] ?> Low</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><?= (int) $prod['stock_qty'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($prod['condition_type']) ?></td>
                            <td>
                                <div style="display:flex; gap:0.4rem;">
                                    <a href="<?= e(url('pages/dashboard/manage_products.php?edit=' . (int) $prod['id'])) ?>" class="btn btn-outline btn-sm">
                                        <?= icon('edit-3', 'lucide-xs', 'blue') ?>
                                        <span>Edit</span>
                                    </a>
                                    <form action="<?= e(url('pages/dashboard/manage_products.php')) ?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="product_id" value="<?= (int) $prod['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete product '<?= e($prod['name']) ?>'?">
                                            <?= icon('trash-2', 'lucide-xs', 'white') ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
