<?php
/**
 * SokoHub Single Product Detail View
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

$productId = (int) ($_GET['id'] ?? 0);
$pdo = get_db_connection();

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
            u.full_name AS owner_name, u.phone AS seller_phone,
            sp.business_name, sp.town AS seller_town, sp.description AS seller_bio
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     INNER JOIN users u ON u.id = p.seller_id
     LEFT JOIN seller_profiles sp ON sp.user_id = u.id
     WHERE p.id = :id AND p.status = 'active'
     LIMIT 1"
);
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Requested product could not be found.');
    redirect('pages/public/products.php');
}

set_recent_category_cookie($product['category_slug']);
$disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);

$pageTitle = 'SokoHub | ' . $product['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <p class="small muted" style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
        <?= icon('home', 'lucide-sm') ?>
        <a href="<?= e(url('index.php')) ?>">Home</a> /
        <a href="<?= e(url('pages/public/products.php')) ?>">Products</a> /
        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($product['category_slug']))) ?>"><?= e($product['category_name']) ?></a> /
        <span><?= e($product['name']) ?></span>
    </p>

    <div class="card">
        <div class="split-grid">
            <div>
                <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" class="rounded-img" style="max-height: 420px;">
            </div>
            <div>
                <span class="badge badge-info"><?= e($product['category_name']) ?> &bull; <?= e($product['condition_type']) ?></span>
                <h1 style="margin-top: 0.6rem; font-size: 1.9rem;"><?= e($product['name']) ?></h1>

                <div class="product-price-row" style="margin: 1rem 0;">
                    <span class="sale-price" style="font-size: 1.7rem;"><?= format_ksh($product['price']) ?></span>
                    <?php if ($disc > 0): ?>
                        <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                        <span class="badge badge-warning">Save <?= $disc ?>%</span>
                    <?php endif; ?>
                </div>

                <p style="margin-bottom: 1.25rem;"><?= e($product['description']) ?></p>

                <div class="card" style="background: #f8fafc; margin-bottom: 1.25rem;">
                    <strong><?= icon('store', 'lucide-sm') ?> Seller Information</strong>
                    <p class="small muted" style="margin-top: 0.35rem;">
                        Vendor: <strong><?= e($product['business_name'] ?: $product['owner_name']) ?></strong><br>
                        Location: <?= icon('map-pin', 'lucide-sm') ?> <?= e($product['seller_town'] ?: 'Kutus, Kirinyaga') ?><br>
                        Availability:
                        <?php if ((int) $product['stock_qty'] > 0): ?>
                            <span class="badge badge-success"><?= (int) $product['stock_qty'] ?> units available</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Currently Out of Stock</span>
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ((int) $product['stock_qty'] > 0): ?>
                    <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" data-validate="true" class="form-grid-2" style="align-items: end;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1" max="<?= (int) $product['stock_qty'] ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <?= icon('shopping-cart') ?>
                            <span>Add to Shopping Cart</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
