<?php
/**
 * SokoHub Buyer Wishlist Page (pages/buyer/wishlist.php)
 * Role: Group 2 (Octavious & Ronny)
 */

require_once __DIR__ . '/../../includes/auth.php';
init_session();
$user = require_login();

$pdo = get_db_connection();
$userId = (int) $user['id'];

// Fetch user's wishlist items
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.price, p.original_price, p.stock_qty, p.image_path, p.rating_avg, p.rating_count,
            c.name AS category_name, c.slug AS category_slug,
            w.created_at AS saved_at
     FROM wishlists w
     INNER JOIN products p ON p.id = w.product_id
     INNER JOIN categories c ON c.id = p.category_id
     WHERE w.user_id = :uid AND p.status = 'active'
     ORDER BY w.created_at DESC"
);
$stmt->execute([':uid' => $userId]);
$wishlistItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'SokoHub | My Saved Wishlist';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1><?= icon('heart', 'lucide-md', 'rose') ?> My Saved Wishlist</h1>
            <p class="muted">Items you have bookmarked for future purchases.</p>
        </div>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-outline">
            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
            <span>Continue Shopping</span>
        </a>
    </div>

    <?php if (empty($wishlistItems)): ?>
        <div class="card" style="text-align: center; padding: 3.5rem 1.5rem;">
            <div class="stat-icon-wrap stat-icon-amber" style="width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 1rem;">
                <?= icon('heart', 'lucide-lg', 'rose') ?>
            </div>
            <h2>Your wishlist is empty</h2>
            <p class="muted" style="margin: 0.6rem 0 1.5rem;">
                Browse the marketplace and click the heart icon on any product to save it here.
            </p>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary">
                <?= icon('shopping-bag', 'lucide-sm', 'white') ?>
                <span>Explore Products</span>
            </a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($wishlistItems as $item): ?>
                <?php
                $disc = discount_percent((float) $item['price'], $item['original_price'] !== null ? (float) $item['original_price'] : null);
                ?>
                <div class="product-card" id="wishlist-card-<?= (int) $item['id'] ?>">
                    <div style="position: relative;">
                        <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $item['id'])) ?>">
                            <img src="<?= e(url($item['image_path'])) ?>" alt="<?= e($item['name']) ?>" class="product-thumb">
                        </a>
                        <button type="button" class="btn-wishlist-toggle active" data-product-id="<?= (int) $item['id'] ?>" title="Remove from wishlist" style="position: absolute; top: 10px; right: 10px;">
                            <?= icon('heart', 'lucide-sm', 'rose') ?>
                        </button>
                    </div>

                    <div class="product-body">
                        <span class="badge badge-info"><?= e($item['category_name']) ?></span>
                        <h3 class="product-title" style="margin-top: 0.4rem;">
                            <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $item['id'])) ?>"><?= e($item['name']) ?></a>
                        </h3>

                        <div class="rating-stars" style="margin: 0.35rem 0; font-size: 0.85rem; color: #f59e0b;">
                            <?= icon('star', 'lucide-xs', 'amber') ?>
                            <strong><?= number_format((float) $item['rating_avg'], 1) ?></strong>
                            <span class="muted small">(<?= (int) $item['rating_count'] ?>)</span>
                        </div>

                        <div class="product-price-row">
                            <span class="sale-price"><?= format_ksh($item['price']) ?></span>
                            <?php if ($disc > 0): ?>
                                <span class="original-price"><?= format_ksh($item['original_price']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 0.9rem; display: flex; gap: 0.5rem;">
                            <?php if ((int) $item['stock_qty'] > 0): ?>
                                <form action="<?= e(url('cart_actions.php')) ?>" method="POST" style="flex: 1;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary btn-sm btn-block">
                                        <?= icon('shopping-cart', 'lucide-xs', 'white') ?>
                                        <span>Add to Cart</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline btn-sm btn-block" disabled>Out of Stock</button>
                            <?php endif; ?>

                            <button type="button" class="btn btn-outline btn-sm btn-wishlist-remove" data-product-id="<?= (int) $item['id'] ?>" title="Remove item">
                                <?= icon('trash-2', 'lucide-xs', 'rose') ?>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
