<?php
/**
 * SokoHub Single Product Detail View
 * Role: Group 2 (Octavious & Ronny)
 *
 * Features:
 * 1. Product details, seller profile, stock availability
 * 2. Instant Wishlist Toggle button (adds/removes via AJAX)
 * 3. Average Rating and Rating Count display
 * 4. Rating Histogram Breakdown (5-star down to 1-star percentage bars)
 * 5. Interactive 5-Star Rating submission widget with text comment box
 * 6. Verified buyer delivery check (deliveries.delivery_status = 'delivered')
 * 7. List of published customer reviews with verified purchase badges
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
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    set_flash('error', 'Requested product could not be found.');
    redirect('pages/public/products.php');
}

set_recent_category_cookie($product['category_slug']);
$disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);
$authUser = current_user();

// Wishlist check
$inWishlist = false;
if ($authUser) {
    $inWishlist = is_in_wishlist($productId, (int) $authUser['id'], $pdo);
}

// Rating histogram and reviews
$ratingStats = get_product_rating_breakdown($productId, $pdo);
$ratingAvg = (float) $product['rating_avg'] > 0 ? (float) $product['rating_avg'] : (float) $ratingStats['average'];
$ratingCount = (int) $product['rating_count'] > 0 ? (int) $product['rating_count'] : (int) $ratingStats['total'];

// Verified buyer check for submitting a review
$canReview = false;
$existingReview = null;
if ($authUser) {
    $checkPurchase = $pdo->prepare(
        "SELECT oi.id
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN deliveries d ON d.order_id = o.id
         WHERE o.buyer_id = :bid
           AND oi.product_id = :pid
           AND d.delivery_status = 'delivered'
         LIMIT 1"
    );
    $checkPurchase->execute([':bid' => (int) $authUser['id'], ':pid' => $productId]);
    $canReview = (bool) $checkPurchase->fetchColumn();

    if ($canReview) {
        $exStmt = $pdo->prepare('SELECT * FROM reviews WHERE buyer_id = :bid AND product_id = :pid LIMIT 1');
        $exStmt->execute([':bid' => (int) $authUser['id'], ':pid' => $productId]);
        $existingReview = $exStmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch all published reviews
$revListStmt = $pdo->prepare(
    "SELECT r.*, u.full_name AS buyer_name
     FROM reviews r
     INNER JOIN users u ON u.id = r.buyer_id
     WHERE r.product_id = :pid AND r.status = 'published'
     ORDER BY r.created_at DESC"
);
$revListStmt->execute([':pid' => $productId]);
$publishedReviews = $revListStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'SokoHub | ' . $product['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <!-- Breadcrumb Navigation -->
    <p class="small muted" style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
        <?= icon('home', 'lucide-sm') ?>
        <a href="<?= e(url('index.php')) ?>">Home</a> /
        <a href="<?= e(url('pages/public/products.php')) ?>">Products</a> /
        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($product['category_slug']))) ?>"><?= e($product['category_name']) ?></a> /
        <span><?= e($product['name']) ?></span>
    </p>

    <!-- Product Overview Card -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="split-grid">
            <div style="position: relative;">
                <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" class="rounded-img" style="max-height: 420px; width: 100%; object-fit: cover;">
                <!-- Wishlist Toggle Floating Button (Group 2) -->
                <button type="button"
                        class="btn-wishlist-toggle <?= $inWishlist ? 'active' : '' ?>"
                        data-product-id="<?= (int) $product['id'] ?>"
                        title="<?= $inWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' ?>"
                        aria-label="Toggle Wishlist"
                        style="position: absolute; top: 14px; right: 14px;">
                    <?= icon('heart', 'lucide-md', $inWishlist ? 'rose' : '') ?>
                </button>
            </div>

            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <span class="badge badge-info"><?= e($product['category_name']) ?> &bull; <?= e($product['condition_type']) ?></span>
                    <?php if ($ratingCount > 0): ?>
                        <div class="rating-badge-pill" style="display:flex; align-items:center; gap:0.35rem; font-weight:700; color:#b45309; background:#fef3c7; padding:0.25rem 0.6rem; border-radius:999px;">
                            <?= icon('star', 'lucide-xs', 'amber') ?>
                            <span><?= number_format($ratingAvg, 1) ?> / 5.0</span>
                            <span class="muted small">(<?= $ratingCount ?> <?= $ratingCount === 1 ? 'review' : 'reviews' ?>)</span>
                        </div>
                    <?php endif; ?>
                </div>

                <h1 style="margin-top: 0.6rem; font-size: 1.85rem;"><?= e($product['name']) ?></h1>

                <div class="product-price-row" style="margin: 0.9rem 0;">
                    <span class="sale-price" style="font-size: 1.75rem;"><?= format_ksh($product['price']) ?></span>
                    <?php if ($disc > 0): ?>
                        <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                        <span class="badge badge-warning">Save <?= $disc ?>%</span>
                    <?php endif; ?>
                </div>

                <p style="margin-bottom: 1.25rem; line-height: 1.6;"><?= e($product['description']) ?></p>

                <!-- Seller Info Box -->
                <div class="card" style="background: #f8fafc; margin-bottom: 1.25rem;">
                    <strong><?= icon('store', 'lucide-xs', 'emerald') ?> Seller Information</strong>
                    <p class="small muted" style="margin-top: 0.35rem; line-height: 1.5;">
                        Vendor: <strong><?= e($product['business_name'] ?: $product['owner_name']) ?></strong><br>
                        Location: <?= icon('map-pin', 'lucide-xs', 'rose') ?> <?= e($product['seller_town'] ?: 'Kutus, Kirinyaga') ?><br>
                        Availability:
                        <?php if ((int) $product['stock_qty'] > 0): ?>
                            <span class="badge badge-success"><?= (int) $product['stock_qty'] ?> units in stock</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Currently Out of Stock</span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Add to Shopping Cart Form (Delegates to cart_actions.php) -->
                <?php if ((int) $product['stock_qty'] > 0): ?>
                    <form action="<?= e(url('cart_actions.php')) ?>" method="POST" data-validate="true" class="form-grid-2" style="align-items: end;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1" max="<?= (int) $product['stock_qty'] ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary" id="btnAddToCart">
                            <?= icon('shopping-cart', 'lucide-sm', 'white') ?>
                            <span>Add to Shopping Cart</span>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <?= icon('alert-triangle', 'lucide-xs', 'amber') ?>
                        <span>This item is temporarily out of stock. You can save it to your wishlist to buy when restocked.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================= RATINGS & REVIEWS SECTION (Group 2) ================= -->
    <section class="ratings-reviews-section">
        <h2 style="font-size: 1.45rem; margin-bottom: 1.25rem;">
            <?= icon('star', 'lucide-sm', 'amber') ?>
            <span>Customer Ratings &amp; Reviews</span>
        </h2>

        <div class="split-grid" style="align-items: start; margin-bottom: 2rem;">
            <!-- Average Rating & Histogram Breakdown (Group 2) -->
            <div class="card">
                <h3 style="font-size: 1.15rem; margin-bottom: 1rem;">Rating Breakdown</h3>
                <div class="rating-overview-flex" style="display: flex; gap: 1.5rem; align-items: center; margin-bottom: 1.25rem;">
                    <div class="rating-big-score" style="text-align: center; min-width: 110px;">
                        <div style="font-size: 2.8rem; font-weight: 800; color: #1e293b; line-height: 1;">
                            <?= number_format($ratingAvg, 1) ?>
                        </div>
                        <div class="stars-display" style="color: #f59e0b; margin: 0.35rem 0;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <?php if ($ratingAvg >= $s): ?>
                                    <?= icon('star', 'lucide-xs', 'amber') ?>
                                <?php else: ?>
                                    <?= icon('star', 'lucide-xs', 'neutral') ?>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="muted small"><?= $ratingCount ?> verified <?= $ratingCount === 1 ? 'rating' : 'ratings' ?></div>
                    </div>

                    <!-- Histogram Percentage Bars -->
                    <div class="rating-histogram-bars" style="flex: 1;">
                        <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                            <?php
                            $cnt = $ratingStats['counts'][$stars] ?? 0;
                            $pct = $ratingStats['percentages'][$stars] ?? 0;
                            ?>
                            <div class="histogram-row" style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.4rem; font-size: 0.85rem;">
                                <span style="min-width: 44px; text-align: right;"><?= $stars ?> <?= icon('star', 'lucide-xs', 'amber') ?></span>
                                <div class="histogram-track" style="flex: 1; height: 9px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                    <div class="histogram-fill" style="width: <?= $pct ?>%; height: 100%; background: #f59e0b; border-radius: 999px;"></div>
                                </div>
                                <span class="muted small" style="min-width: 32px; text-align: right;"><?= $cnt ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Interactive 5-Star Rating Submission Widget (Group 2) -->
            <div class="card">
                <h3 style="font-size: 1.15rem; margin-bottom: 0.85rem;">
                    <?= icon('message-square', 'lucide-sm', 'blue') ?>
                    <span><?= $existingReview ? 'Update Your Review' : 'Write a Verified Review' ?></span>
                </h3>

                <?php if (!$authUser): ?>
                    <div class="alert alert-info" style="margin-bottom: 0;">
                        <?= icon('lock', 'lucide-xs', 'blue') ?>
                        <span>
                            Please <a href="<?= e(url('pages/auth/login.php')) ?>" style="text-decoration: underline; font-weight: 700;">Sign In</a> to review this product. Reviews are reserved for verified delivered buyers.
                        </span>
                    </div>
                <?php elseif (!$canReview): ?>
                    <div class="alert alert-neutral" style="margin-bottom: 0;">
                        <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                        <span>
                            <strong>Verified Purchase Required:</strong> Only buyers who have purchased and received this product (delivery completed) can submit a review.
                        </span>
                    </div>
                <?php else: ?>
                    <form action="<?= e(url('submit_review.php')) ?>" method="POST" id="reviewForm" data-validate="true">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                        <!-- Interactive 5-Star Selection Widget -->
                        <div class="form-group">
                            <label style="font-weight: 700; margin-bottom: 0.35rem; display: block;">Your Rating *</label>
                            <div class="interactive-star-widget" id="starWidget" data-current="<?= (int) ($existingReview['rating'] ?? 5) ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star-btn <?= ($i <= ($existingReview['rating'] ?? 5)) ? 'active' : '' ?>" data-value="<?= $i ?>" aria-label="<?= $i ?> Star">
                                        <?= icon('star', 'lucide-md') ?>
                                    </button>
                                <?php endfor; ?>
                                <input type="hidden" name="rating" id="reviewRatingInput" value="<?= (int) ($existingReview['rating'] ?? 5) ?>">
                                <span class="rating-label muted small" id="ratingText" style="margin-left: 0.6rem; font-weight: 600;">5 Stars (Excellent)</span>
                            </div>
                        </div>

                        <!-- Text Comment Box -->
                        <div class="form-group">
                            <label for="review_comment" style="font-weight: 700;">Your Experience / Feedback</label>
                            <textarea id="review_comment"
                                      name="comment"
                                      class="form-control"
                                      rows="3"
                                      placeholder="How was the product quality, delivery speed, and overall satisfaction?"><?= e($existingReview['comment'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-amber btn-block" id="btnSubmitReview">
                            <?= icon('check-circle', 'lucide-xs', 'white') ?>
                            <span><?= $existingReview ? 'Update My Review' : 'Submit Verified Review' ?></span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Verified Reviews List (Group 2) -->
        <div class="card">
            <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem;">
                Verified Buyer Feedback (<?= count($publishedReviews) ?>)
            </h3>

            <?php if (empty($publishedReviews)): ?>
                <p class="muted" style="text-align: center; padding: 2rem 1rem;">
                    No reviews yet for this product. Be the first verified buyer to leave feedback!
                </p>
            <?php else: ?>
                <div class="reviews-list">
                    <?php foreach ($publishedReviews as $rev): ?>
                        <div class="review-item" style="padding: 1rem 0; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                <div>
                                    <strong><?= e($rev['buyer_name']) ?></strong>
                                    <span class="badge badge-success small" style="margin-left: 0.35rem;">
                                        <?= icon('check', 'lucide-xs') ?> Verified Purchase
                                    </span>
                                </div>
                                <span class="muted small"><?= date('M d, Y', strtotime($rev['created_at'])) ?></span>
                            </div>

                            <div class="stars-display" style="color: #f59e0b; margin: 0.35rem 0;">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <?php if ((int) $rev['rating'] >= $s): ?>
                                        <?= icon('star', 'lucide-xs', 'amber') ?>
                                    <?php else: ?>
                                        <?= icon('star', 'lucide-xs', 'neutral') ?>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </div>

                            <?php if (!empty($rev['comment'])): ?>
                                <p style="margin: 0.45rem 0 0; color: #334155; line-height: 1.5; font-size: 0.95rem;">
                                    <?= e($rev['comment']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
