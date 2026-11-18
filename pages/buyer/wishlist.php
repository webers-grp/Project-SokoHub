<?php
/**
 * SokoHub Buyer Wishlist / Saved-for-Later View
 *
 * Dedicated wishlist interface allowing buyers to inspect saved products,
 * check stock availability, and move items into their shopping cart with a single click.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/wishlist.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/buyer/wishlist.php');
    }

    $action    = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($action === 'move_to_cart' && $productId > 0) {
        $res = Wishlist::moveToCart($userId, $productId);
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', $res['error']);
        }
        redirect('pages/buyer/wishlist.php');
    }

    if ($action === 'remove' && $productId > 0) {
        Wishlist::toggle($userId, $productId);
        set_flash('info', 'Item removed from your wishlist.');
        redirect('pages/buyer/wishlist.php');
    }
}

$items = Wishlist::getUserWishlist($userId);

$page_title = 'My Wishlist - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 1100px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Breadcrumb & Head -->
    <div style="margin-bottom: 2rem;">
        <nav style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
            <a href="<?= e(url('index.php')) ?>" style="color: #64748b; text-decoration: none;">Home</a> &rsaquo;
            <span style="color: #0f172a; font-weight: 600;">My Wishlist</span>
        </nav>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                    ❤️ Saved Wishlist (<?= count($items) ?>)
                </h1>
                <p style="color: #64748b; margin: 0.25rem 0 0; font-size: 0.9rem;">
                    Items you've bookmarked to buy later. Move them to your cart when you're ready to check out!
                </p>
            </div>
            <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="btn btn-outline" style="font-size: 0.85rem;">
                🛒 Go to Cart &rarr;
            </a>
        </div>
    </div>

    <!-- Items Grid -->
    <?php if (empty($items)): ?>
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 3rem 1rem; text-align: center; color: #64748b;">
            <div style="font-size: 3rem; margin-bottom: 0.5rem;">🤍</div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 0.25rem;">Your Wishlist is Empty</h3>
            <p style="font-size: 0.85rem; margin: 0 0 1.25rem;">Browse products on the marketplace and click the heart icon to save items for later.</p>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary" style="padding: 0.65rem 1.25rem; font-size: 0.9rem;">
                Explore Marketplace Catalog
            </a>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.5rem;">
            <?php foreach ($items as $item): ?>
                <?php
                $isOut = (int) $item['stock_qty'] <= 0;
                $isLow = !$isOut && (int) $item['stock_qty'] <= 5;
                $img = !empty($item['image_path']) ? url($item['image_path']) : url('assets/img/fresh-produce.jpg');
                ?>
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative;">
                    <!-- Image -->
                    <div style="height: 180px; background: #f8fafc; overflow: hidden; position: relative;">
                        <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $item['id'])) ?>">
                            <img src="<?= e($img) ?>" alt="<?= e($item['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </a>
                        <!-- Category Badge -->
                        <span style="position: absolute; top: 0.5rem; left: 0.5rem; background: rgba(15, 23, 42, 0.75); color: white; font-size: 0.7rem; font-weight: 600; padding: 0.2rem 0.5rem; border-radius: 4px; backdrop-filter: blur(4px);">
                            <?= e($item['category_name']) ?>
                        </span>
                        <!-- Remove button -->
                        <form method="POST" action="" style="position: absolute; top: 0.5rem; right: 0.5rem; margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" style="background: white; border: none; border-radius: 9999px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.15);" title="Remove from wishlist">
                                ❌
                            </button>
                        </form>
                    </div>

                    <!-- Details -->
                    <div style="padding: 1rem; display: flex; flex-direction: column; flex-grow: 1;">
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem;">
                            Vendor: <?= e($item['seller_name']) ?>
                        </div>
                        <h3 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0 0 0.5rem; line-height: 1.3;">
                            <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $item['id'])) ?>" style="color: inherit; text-decoration: none;">
                                <?= e($item['name']) ?>
                            </a>
                        </h3>

                        <!-- Rating & Scarcity -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                            <div style="font-size: 0.8rem; color: #eab308; font-weight: 600;">
                                ★ <?= number_format((float) $item['rating_avg'], 1) ?>
                                <span style="color: #94a3b8; font-size: 0.75rem;">(<?= (int) $item['rating_count'] ?>)</span>
                            </div>
                            <?php if ($isOut): ?>
                                <span style="background: #fee2e2; color: #b91c1c; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.4rem; border-radius: 4px;">Out of Stock</span>
                            <?php elseif ($isLow): ?>
                                <span style="background: #fef3c7; color: #b45309; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.4rem; border-radius: 4px;">Only <?= (int) $item['stock_qty'] ?> left!</span>
                            <?php else: ?>
                                <span style="background: #dcfce7; color: #15803d; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.4rem; border-radius: 4px;">In Stock</span>
                            <?php endif; ?>
                        </div>

                        <!-- Price & Action -->
                        <div style="margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                            <div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #0f172a;">
                                    <?= format_ksh($item['price']) ?>
                                </div>
                                <?php if (!empty($item['original_price']) && (float) $item['original_price'] > (float) $item['price']): ?>
                                    <div style="font-size: 0.75rem; color: #94a3b8; text-decoration: line-through;">
                                        <?= format_ksh($item['original_price']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="move_to_cart">
                                <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm" <?= $isOut ? 'disabled' : '' ?> style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                    🛒 Move to Cart
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
