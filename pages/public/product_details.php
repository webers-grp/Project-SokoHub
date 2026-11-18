<?php
/**
 * SokoHub Single Product Detail View
 *
 * Includes:
 * - Product specifications, pricing, vendor info, and stock scarcity indicators
 * - One-click Add to Wishlist toggle
 * - Verified Purchase Customer Reviews & Ratings with 1-5 Star Breakdown
 * - Verified Buyer Review submission form with moderation
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reviews.php';
require_once __DIR__ . '/../../includes/wishlist.php';

init_session();

$productId = (int) ($_GET['id'] ?? 0);
$pdo = get_db_connection();
$userId = is_logged_in() ? (int) current_user()['id'] : 0;

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/public/product_details.php?id=' . $productId);
    }

    $action = (string) ($_POST['action'] ?? '');

    // Submit Verified Review
    if ($action === 'submit_review') {
        if (!is_logged_in()) {
            set_flash('error', 'Please log in to submit a review.');
            redirect('pages/auth/login.php');
        }

        $rating = (int) ($_POST['rating'] ?? 5);
        $reviewTitle = trim((string) ($_POST['review_title'] ?? ''));
        $reviewText = trim((string) ($_POST['review_text'] ?? ''));

        $res = Reviews::submitReview($productId, $userId, $rating, $reviewText, $reviewTitle);
        if ($res['ok']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('error', $res['error']);
        }
        redirect('pages/public/product_details.php?id=' . $productId . '#reviews');
    }

    // Toggle Wishlist
    if ($action === 'toggle_wishlist') {
        if (!is_logged_in()) {
            set_flash('error', 'Please log in to add items to your wishlist.');
            redirect('pages/auth/login.php');
        }
        $res = Wishlist::toggle($userId, $productId);
        set_flash('info', $res['ok'] ? ($res['action'] === 'added' ? 'Added to wishlist!' : 'Removed from wishlist.') : $res['error']);
        redirect('pages/public/product_details.php?id=' . $productId);
    }
}

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

$stockQty = (int) $product['stock_qty'];
$isOut = ($stockQty <= 0);
$isLow = ($stockQty > 0 && $stockQty <= 5);

$inWishlist = $userId > 0 ? Wishlist::isInWishlist($userId, $productId) : false;
$reviewEligibility = $userId > 0 ? Reviews::canUserReview($productId, $userId) : ['can_review' => false, 'reason' => 'Log in to write a review.'];
$reviews = Reviews::getProductReviews($productId);

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

    <!-- Product Master Card -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="split-grid">
            <div style="position: relative;">
                <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" class="rounded-img" style="max-height: 420px; width: 100%; object-fit: cover;">
                <!-- Wishlist Toggle Button -->
                <form method="POST" action="" style="position: absolute; top: 1rem; right: 1rem; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_wishlist">
                    <button type="submit" class="btn" style="background: white; border-radius: 50%; width: 42px; height: 42px; padding: 0; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px rgba(0,0,0,0.15);" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' ?>">
                        <span style="font-size: 1.3rem;"><?= $inWishlist ? '❤️' : '🤍' ?></span>
                    </button>
                </form>
            </div>

            <div>
                <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <span class="badge badge-info"><?= e($product['category_name']) ?></span>
                    <span class="badge badge-outline"><?= e($product['condition_type']) ?></span>
                    <?php if ($isOut): ?>
                        <span class="badge badge-danger">Out of Stock</span>
                    <?php elseif ($isLow): ?>
                        <span class="badge badge-warning">Only <?= $stockQty ?> left in stock!</span>
                    <?php else: ?>
                        <span class="badge badge-success">In Stock</span>
                    <?php endif; ?>
                </div>

                <h1 style="margin-top: 0.6rem; font-size: 1.9rem; color: #0f172a;"><?= e($product['name']) ?></h1>

                <!-- Ratings Header Summary -->
                <div style="display: flex; align-items: center; gap: 0.5rem; margin: 0.5rem 0 1rem;">
                    <div style="color: #eab308; font-size: 1.1rem; font-weight: 700;">
                        ★ <?= number_format((float) $product['rating_avg'], 1) ?>
                    </div>
                    <span style="color: #64748b; font-size: 0.85rem;">
                        (<?= (int) $product['rating_count'] ?> customer rating<?= (int) $product['rating_count'] === 1 ? '' : 's' ?>) &bull; <a href="#reviews" style="color: #16a34a; text-decoration: none;">Read reviews</a>
                    </span>
                </div>

                <!-- Price Row -->
                <div class="product-price-row" style="margin: 1rem 0;">
                    <span class="sale-price" style="font-size: 1.8rem; font-weight: 800; color: #0f172a;"><?= format_ksh($product['price']) ?></span>
                    <?php if ($disc > 0): ?>
                        <span class="original-price" style="font-size: 1.1rem; color: #94a3b8; text-decoration: line-through;"><?= format_ksh($product['original_price']) ?></span>
                        <span class="badge badge-warning">Save <?= $disc ?>%</span>
                    <?php endif; ?>
                </div>

                <p style="margin-bottom: 1.25rem; line-height: 1.5; color: #334155;"><?= e($product['description']) ?></p>

                <!-- Seller Info Box -->
                <div class="card" style="background: #f8fafc; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; padding: 1rem;">
                    <strong style="color: #0f172a; display: flex; align-items: center; gap: 0.35rem;">
                        <?= icon('store', 'lucide-xs', 'emerald') ?>
                        <span>Marketplace Vendor Information</span>
                    </strong>
                    <div class="small muted" style="margin-top: 0.5rem; line-height: 1.4;">
                        Vendor: <strong><?= e($product['business_name'] ?: $product['owner_name']) ?></strong><br>
                        Location: <?= icon('map-pin', 'lucide-xs', 'rose') ?> <?= e($product['seller_town'] ?: 'Kutus, Kirinyaga') ?><br>
                        Delivery Dispatch: Within 30 - 60 minutes for local Kirinyaga orders
                    </div>
                </div>

                <!-- Add to Cart Form -->
                <?php if ($stockQty > 0): ?>
                    <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" class="form-grid-2" style="align-items: end;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1" max="<?= $stockQty ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary" style="height: 42px;">
                            <?= icon('shopping-cart', 'lucide-sm', 'white') ?>
                            <span>Add to Cart</span>
                        </button>
                    </form>
                <?php else: ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.9rem; font-weight: 600;">
                        This item is currently sold out. Check back soon for restock notifications!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Verified Customer Reviews Section -->
    <div class="card" id="reviews" style="padding: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0;">
                    ⭐ Verified Customer Reviews
                </h2>
                <p style="color: #64748b; font-size: 0.85rem; margin: 0.25rem 0 0;">
                    Overall Rating: <strong><?= number_format((float) $product['rating_avg'], 1) ?> out of 5</strong> based on <?= (int) $product['rating_count'] ?> review(s).
                </p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem; align-items: start;">
            <!-- Review List -->
            <div>
                <?php if (empty($reviews)): ?>
                    <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 2rem; text-align: center; color: #64748b;">
                        <p style="font-weight: 600; margin: 0 0 0.25rem;">No reviews yet for this product.</p>
                        <p style="font-size: 0.85rem; margin: 0;">Be the first verified customer to leave your feedback!</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($reviews as $rev): ?>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.35rem;">
                                    <div>
                                        <strong style="color: #0f172a; font-size: 0.95rem;"><?= e($rev['buyer_name']) ?></strong>
                                        <span class="badge badge-success" style="font-size: 0.7rem; padding: 0.15rem 0.4rem; margin-left: 0.25rem;">
                                            ✓ Verified Purchase
                                        </span>
                                    </div>
                                    <div style="color: #eab308; font-weight: 700; font-size: 0.9rem;">
                                        <?= str_repeat('★', (int) $rev['rating']) ?><?= str_repeat('☆', 5 - (int) $rev['rating']) ?>
                                    </div>
                                </div>
                                <?php if (!empty($rev['review_title'])): ?>
                                    <h4 style="font-size: 0.9rem; font-weight: 700; color: #0f172a; margin: 0.25rem 0;">
                                        <?= e($rev['review_title']) ?>
                                    </h4>
                                <?php endif; ?>
                                <p style="font-size: 0.85rem; color: #334155; margin: 0.35rem 0 0; line-height: 1.4;">
                                    <?= nl2br(e($rev['review_text'])) ?>
                                </p>
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.5rem;">
                                    Reviewed on <?= e(date('d M Y', strtotime($rev['created_at']))) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Write a Review Form Card -->
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 0.5rem;">
                    ✍️ Leave a Verified Review
                </h3>

                <?php if ($reviewEligibility['can_review']): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="submit_review">

                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                                Rating (1 - 5 Stars)
                            </label>
                            <select name="rating" class="form-control" style="width: 100%;" required>
                                <option value="5">★★★★★ - 5 Stars (Excellent)</option>
                                <option value="4">★★★★☆ - 4 Stars (Very Good)</option>
                                <option value="3">★★★☆☆ - 3 Stars (Average)</option>
                                <option value="2">★★☆☆☆ - 2 Stars (Below Average)</option>
                                <option value="1">★☆☆☆☆ - 1 Star (Poor)</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                                Review Headline / Title (Optional)
                            </label>
                            <input type="text" name="review_title" placeholder="e.g. Great quality and swift delivery!" class="form-control" style="width: 100%;">
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                                Detailed Review
                            </label>
                            <textarea name="review_text" rows="3" placeholder="Tell other marketplace shoppers about your experience..." class="form-control" style="width: 100%;" required minlength="5"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            Publish Verified Review
                        </button>
                    </form>
                <?php else: ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1rem; font-size: 0.85rem; color: #64748b; line-height: 1.4;">
                        <strong>Verified Purchase Policy:</strong><br>
                        <?= e($reviewEligibility['reason']) ?>
                        <?php if (!is_logged_in()): ?>
                            <div style="margin-top: 0.5rem;">
                                <a href="<?= e(url('pages/auth/login.php')) ?>" class="btn btn-outline btn-sm">Log in to Review</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
