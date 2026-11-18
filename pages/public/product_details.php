<?php
/**
 * SokoHub Single Product Detail View — Kilimall-style redesign
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

// Star breakdown
$starBreakdown = [5=>0,4=>0,3=>0,2=>0,1=>0];
foreach ($reviews as $rev) {
    $r = max(1, min(5, (int)$rev['rating']));
    $starBreakdown[$r]++;
}
$totalReviews = array_sum($starBreakdown);

$pageTitle = 'SokoHub | ' . $product['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Detail page layout ── */
.detail-wrap { padding: 1.25rem 0 2rem; }

/* ── Breadcrumb ── */
.kili-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    color: var(--text-muted);
    flex-wrap: wrap;
    margin-bottom: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border-color);
}
.kili-breadcrumb a { color: var(--text-muted); text-decoration: none; }
.kili-breadcrumb a:hover { color: var(--brand-red); }
.kili-breadcrumb .bc-sep { color: var(--border-color); }
.kili-breadcrumb .bc-current { color: var(--text-main); font-weight: 600; }

/* ── Main product card ── */
.detail-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 1.5rem;
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 2rem;
    margin-bottom: 1.25rem;
}

/* ── Image pane ── */
.detail-image-pane { position: relative; }
.detail-main-img {
    width: 100%;
    aspect-ratio: 1/1;
    object-fit: cover;
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    display: block;
}
.detail-thumb-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
}
.detail-thumb {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-sm);
    cursor: pointer;
    opacity: .7;
    transition: border-color .15s, opacity .15s;
}
.detail-thumb:hover, .detail-thumb.active { border-color: var(--brand-red); opacity: 1; }

/* ── Info pane ── */
.detail-info-pane {}
.detail-badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
.detail-badge {
    font-size: 0.72rem;
    padding: 0.2rem 0.5rem;
    border-radius: 2px;
    font-weight: 600;
    text-transform: uppercase;
}
.db-category { background: #FFF3E0; color: #E65100; }
.db-condition { background: #E3F2FD; color: #1565C0; }
.db-instock { background: #E8F5E9; color: #2E7D32; }
.db-outstock { background: #FFEBEE; color: #C62828; }
.db-lowstock { background: #FFF8E1; color: #F57F17; }
.db-discount { background: var(--brand-red); color: #fff; }

.detail-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.25;
    margin: 0.4rem 0 0.75rem;
}
.detail-rating-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}
.detail-stars { color: #FFAA00; font-size: 1rem; }
.detail-rating-count { color: var(--text-muted); }
.detail-rating-link { color: var(--brand-red); text-decoration: none; }
.detail-rating-link:hover { text-decoration: underline; }

.detail-price-row {
    display: flex;
    align-items: baseline;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1.25rem;
}
.detail-sale-price {
    font-size: 2rem;
    font-weight: 900;
    color: var(--brand-red);
}
.detail-original-price {
    font-size: 1.1rem;
    color: #999;
    text-decoration: line-through;
    font-weight: 400;
}
.detail-disc-badge {
    background: var(--brand-red);
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    padding: 0.2rem 0.5rem;
    border-radius: 2px;
}

.detail-seller-box {
    background: #f8f8f8;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    font-size: 0.82rem;
    color: var(--text-muted);
    line-height: 1.6;
}
.detail-seller-box strong { color: var(--text-main); }

.btn-add-cart {
    display: block;
    width: 100%;
    background: var(--brand-red);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    padding: 0.9rem;
    font-size: 1.05rem;
    font-weight: 800;
    cursor: pointer;
    text-align: center;
    transition: background .15s;
    letter-spacing: 0.5px;
}
.btn-add-cart:hover { background: var(--brand-dark-red); }

.btn-wishlist-outline {
    display: block;
    width: 100%;
    background: transparent;
    color: var(--brand-red);
    border: 1.5px solid var(--brand-red);
    border-radius: var(--radius-sm);
    padding: 0.65rem;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
    margin-top: 0.6rem;
    transition: background .15s;
}
.btn-wishlist-outline:hover { background: #fff0f0; }

.qty-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.qty-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }
.qty-input {
    width: 70px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.5rem;
    font-size: 0.95rem;
    text-align: center;
}

/* ── Tabs ── */
.detail-tabs-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius); margin-bottom: 1.5rem; }
.tab-nav {
    display: flex;
    border-bottom: 1px solid var(--border-color);
}
.tab-btn {
    padding: 0.75rem 1.25rem;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-muted);
    background: none;
    border: none;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
    transition: color .15s, border-color .15s;
}
.tab-btn.active { color: var(--brand-red); border-color: var(--brand-red); }
.tab-btn:hover:not(.active) { color: var(--text-main); }
.tab-panel { display: none; padding: 1.5rem; }
.tab-panel.active { display: block; }

/* ── Reviews ── */
.reviews-layout { display: grid; grid-template-columns: 220px 1fr; gap: 2rem; }
.rating-summary { text-align: center; }
.rating-big { font-size: 3rem; font-weight: 900; color: var(--text-main); line-height: 1; }
.rating-stars-big { color: #FFAA00; font-size: 1.3rem; margin: 0.25rem 0; }
.rating-based { font-size: 0.75rem; color: var(--text-muted); }

.rating-breakdown { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.3rem; }
.rb-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; }
.rb-label { width: 32px; color: var(--text-muted); text-align: right; white-space: nowrap; }
.rb-bar-wrap { flex: 1; height: 8px; background: #E0E0E0; border-radius: 4px; overflow: hidden; }
.rb-bar-fill { height: 100%; background: #FFAA00; border-radius: 4px; transition: width .3s; }
.rb-count { width: 20px; color: var(--text-muted); }

.review-list { display: flex; flex-direction: column; gap: 0.75rem; }
.review-card {
    background: #f9f9f9;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 1rem;
}
.review-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.35rem; }
.reviewer-name { font-weight: 700; font-size: 0.9rem; color: var(--text-main); }
.verified-badge {
    font-size: 0.7rem;
    background: #E8F5E9;
    color: #2E7D32;
    padding: 0.1rem 0.4rem;
    border-radius: 2px;
    margin-left: 0.3rem;
}
.review-stars { color: #FFAA00; font-size: 0.85rem; }
.review-title { font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin: 0.2rem 0; }
.review-text { font-size: 0.83rem; color: #334155; line-height: 1.5; margin: 0; }
.review-date { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.4rem; }

.write-review-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-top: 1.5rem;
}
.write-review-title { font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0 0 1rem; }

@media (max-width: 900px) {
    .detail-card { grid-template-columns: 1fr; }
    .reviews-layout { grid-template-columns: 1fr; }
    .detail-main-img { max-height: 320px; aspect-ratio: unset; }
}
</style>

<div class="container detail-wrap">
    <!-- Breadcrumb -->
    <nav class="kili-breadcrumb">
        <a href="<?= e(url('index.php')) ?>">🏠 Home</a>
        <span class="bc-sep">/</span>
        <a href="<?= e(url('pages/public/products.php')) ?>">Products</a>
        <span class="bc-sep">/</span>
        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($product['category_slug']))) ?>"><?= e($product['category_name']) ?></a>
        <span class="bc-sep">/</span>
        <span class="bc-current"><?= e($product['name']) ?></span>
    </nav>

    <!-- Product Main Card -->
    <div class="detail-card">
        <!-- Left: Image -->
        <div class="detail-image-pane">
            <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" class="detail-main-img" id="detail-main-img">
            <!-- Thumbnail placeholder row (same image repeated) -->
            <div class="detail-thumb-row">
                <img src="<?= e(url($product['image_path'])) ?>" alt="thumb" class="detail-thumb active">
            </div>

            <!-- Wishlist toggle on image -->
            <form method="POST" action="" style="position:absolute; top:0.75rem; right:0.75rem; margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_wishlist">
                <button type="submit" style="background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:38px; height:38px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,0.2); font-size:1.1rem;" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' ?>">
                    <?= $inWishlist ? '❤️' : '🤍' ?>
                </button>
            </form>
        </div>

        <!-- Right: Info -->
        <div class="detail-info-pane">
            <!-- Badges -->
            <div class="detail-badges">
                <span class="detail-badge db-category"><?= e($product['category_name']) ?></span>
                <span class="detail-badge db-condition"><?= e($product['condition_type']) ?></span>
                <?php if ($isOut): ?>
                    <span class="detail-badge db-outstock">Out of Stock</span>
                <?php elseif ($isLow): ?>
                    <span class="detail-badge db-lowstock">Only <?= $stockQty ?> left!</span>
                <?php else: ?>
                    <span class="detail-badge db-instock">In Stock</span>
                <?php endif; ?>
                <?php if ($disc > 0): ?>
                    <span class="detail-badge db-discount">-<?= $disc ?>% OFF</span>
                <?php endif; ?>
            </div>

            <!-- Title -->
            <h1 class="detail-title"><?= e($product['name']) ?></h1>

            <!-- Rating row -->
            <div class="detail-rating-row">
                <span class="detail-stars">
                    <?= str_repeat('★', min(5, (int) round((float) $product['rating_avg']))) ?><?= str_repeat('☆', max(0, 5 - (int) round((float) $product['rating_avg']))) ?>
                </span>
                <span style="font-weight:700; color:#333;"><?= number_format((float) $product['rating_avg'], 1) ?></span>
                <span class="detail-rating-count">(<?= (int) $product['rating_count'] ?> review<?= (int) $product['rating_count'] === 1 ? '' : 's' ?>)</span>
                <a href="#reviews" class="detail-rating-link">Read reviews</a>
            </div>

            <!-- Price row -->
            <div class="detail-price-row">
                <span class="detail-sale-price"><?= format_ksh($product['price']) ?></span>
                <?php if ($disc > 0): ?>
                    <span class="detail-original-price"><?= format_ksh($product['original_price']) ?></span>
                    <span class="detail-disc-badge">Save <?= $disc ?>%</span>
                <?php endif; ?>
            </div>

            <!-- Seller info -->
            <div class="detail-seller-box">
                🏪 Sold by <strong><?= e($product['business_name'] ?: $product['owner_name']) ?></strong>
                &bull; 📍 <?= e($product['seller_town'] ?: 'Kutus, Kirinyaga') ?><br>
                🚴 Dispatch: Within 30–60 min for local Kirinyaga orders
            </div>

            <!-- Add to Cart / Out of Stock -->
            <?php if ($stockQty > 0): ?>
                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <div class="qty-row">
                        <label class="qty-label" for="quantity">Qty:</label>
                        <input type="number" id="quantity" name="quantity" class="qty-input" value="1" min="1" max="<?= $stockQty ?>" required>
                    </div>
                    <button type="submit" class="btn-add-cart">🛒 Add to Cart</button>
                </form>
            <?php else: ?>
                <div style="background:#FFEBEE; border:1px solid #FFCDD2; color:#C62828; border-radius:var(--radius-sm); padding:0.75rem 1rem; font-size:0.9rem; font-weight:600; text-align:center;">
                    This item is currently sold out. Check back soon!
                </div>
            <?php endif; ?>

            <!-- Save to Wishlist -->
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_wishlist">
                <button type="submit" class="btn-wishlist-outline">
                    <?= $inWishlist ? '❤️ Remove from Wishlist' : '🤍 Save to Wishlist' ?>
                </button>
            </form>
        </div>
    </div>

    <!-- ══ TABS: Description | Reviews | Seller Info ══ -->
    <div class="detail-tabs-wrap">
        <div class="tab-nav" role="tablist">
            <button class="tab-btn active" onclick="showTab('desc', this)" role="tab">📋 Description</button>
            <button class="tab-btn" onclick="showTab('reviews', this)" role="tab" id="reviews-tab-btn">⭐ Reviews (<?= (int) $product['rating_count'] ?>)</button>
            <button class="tab-btn" onclick="showTab('seller', this)" role="tab">🏪 Seller Info</button>
        </div>

        <!-- Description -->
        <div id="tab-desc" class="tab-panel active">
            <p style="line-height:1.7; color:var(--text-main); font-size:0.92rem; white-space:pre-line;"><?= e($product['description']) ?></p>
        </div>

        <!-- Reviews -->
        <div id="tab-reviews" class="tab-panel" id="reviews">
            <div class="reviews-layout">
                <!-- Left: summary + breakdown -->
                <div class="rating-summary">
                    <div class="rating-big"><?= number_format((float) $product['rating_avg'], 1) ?></div>
                    <div class="rating-stars-big">
                        <?= str_repeat('★', min(5, (int) round((float) $product['rating_avg']))) ?><?= str_repeat('☆', max(0, 5 - (int) round((float) $product['rating_avg']))) ?>
                    </div>
                    <div class="rating-based">Based on <?= $totalReviews ?> review<?= $totalReviews !== 1 ? 's' : '' ?></div>

                    <div class="rating-breakdown">
                        <?php foreach ([5,4,3,2,1] as $star):
                            $cnt = $starBreakdown[$star];
                            $pct = $totalReviews > 0 ? round(($cnt / $totalReviews) * 100) : 0;
                        ?>
                        <div class="rb-row">
                            <span class="rb-label"><?= $star ?>★</span>
                            <div class="rb-bar-wrap">
                                <div class="rb-bar-fill" style="width:<?= $pct ?>%;"></div>
                            </div>
                            <span class="rb-count"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right: individual reviews -->
                <div>
                    <?php if (empty($reviews)): ?>
                        <div style="text-align:center; color:var(--text-muted); padding:2rem 0;">
                            <p style="font-weight:600; margin-bottom:0.25rem;">No reviews yet.</p>
                            <p style="font-size:0.85rem;">Be the first verified customer to leave feedback!</p>
                        </div>
                    <?php else: ?>
                        <div class="review-list">
                            <?php foreach ($reviews as $rev): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <div>
                                        <span class="reviewer-name"><?= e($rev['buyer_name']) ?></span>
                                        <span class="verified-badge">✓ Verified Purchase</span>
                                    </div>
                                    <div class="review-stars">
                                        <?= str_repeat('★', (int) $rev['rating']) ?><?= str_repeat('☆', 5 - (int) $rev['rating']) ?>
                                    </div>
                                </div>
                                <?php if (!empty($rev['review_title'])): ?>
                                    <div class="review-title"><?= e($rev['review_title']) ?></div>
                                <?php endif; ?>
                                <p class="review-text"><?= nl2br(e($rev['review_text'])) ?></p>
                                <div class="review-date">Reviewed on <?= e(date('d M Y', strtotime($rev['created_at']))) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Write a Review -->
                    <div class="write-review-card">
                        <h3 class="write-review-title">✍️ Leave a Verified Review</h3>
                        <?php if ($reviewEligibility['can_review']): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="submit_review">

                                <div style="margin-bottom:0.75rem;">
                                    <label style="display:block; font-weight:600; font-size:0.82rem; color:var(--text-muted); margin-bottom:0.25rem;">Rating (1–5 Stars)</label>
                                    <select name="rating" class="form-control" style="width:100%;" required>
                                        <option value="5">★★★★★ — 5 Stars (Excellent)</option>
                                        <option value="4">★★★★☆ — 4 Stars (Very Good)</option>
                                        <option value="3">★★★☆☆ — 3 Stars (Average)</option>
                                        <option value="2">★★☆☆☆ — 2 Stars (Below Average)</option>
                                        <option value="1">★☆☆☆☆ — 1 Star (Poor)</option>
                                    </select>
                                </div>

                                <div style="margin-bottom:0.75rem;">
                                    <label style="display:block; font-weight:600; font-size:0.82rem; color:var(--text-muted); margin-bottom:0.25rem;">Review Headline (Optional)</label>
                                    <input type="text" name="review_title" placeholder="e.g. Great quality and swift delivery!" class="form-control" style="width:100%;">
                                </div>

                                <div style="margin-bottom:0.75rem;">
                                    <label style="display:block; font-weight:600; font-size:0.82rem; color:var(--text-muted); margin-bottom:0.25rem;">Detailed Review</label>
                                    <textarea name="review_text" rows="3" placeholder="Tell other shoppers about your experience..." class="form-control" style="width:100%;" required minlength="5"></textarea>
                                </div>

                                <button type="submit" class="btn-add-cart" style="font-size:0.95rem; padding:0.7rem;">Publish Verified Review</button>
                            </form>
                        <?php else: ?>
                            <div style="background:#f8fafc; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:1rem; font-size:0.85rem; color:var(--text-muted); line-height:1.5;">
                                <strong>Verified Purchase Policy:</strong><br>
                                <?= e($reviewEligibility['reason']) ?>
                                <?php if (!is_logged_in()): ?>
                                    <div style="margin-top:0.5rem;">
                                        <a href="<?= e(url('pages/auth/login.php')) ?>" class="btn btn-outline btn-sm">Log in to Review</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seller Info -->
        <div id="tab-seller" class="tab-panel">
            <div style="max-width:520px;">
                <h3 style="font-size:1rem; font-weight:800; color:var(--text-main); margin:0 0 0.75rem;">🏪 <?= e($product['business_name'] ?: $product['owner_name']) ?></h3>
                <table style="border-collapse:collapse; width:100%; font-size:0.85rem;">
                    <tr>
                        <td style="padding:0.4rem 0.75rem 0.4rem 0; color:var(--text-muted); white-space:nowrap;">👤 Seller Name</td>
                        <td style="padding:0.4rem 0; font-weight:600; color:var(--text-main);"><?= e($product['owner_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:0.4rem 0.75rem 0.4rem 0; color:var(--text-muted);">📍 Location</td>
                        <td style="padding:0.4rem 0; color:var(--text-main);"><?= e($product['seller_town'] ?: 'Kutus, Kirinyaga') ?></td>
                    </tr>
                    <tr>
                        <td style="padding:0.4rem 0.75rem 0.4rem 0; color:var(--text-muted);">🚴 Dispatch</td>
                        <td style="padding:0.4rem 0; color:var(--text-main);">30–60 min for local Kirinyaga orders</td>
                    </tr>
                    <?php if (!empty($product['seller_bio'])): ?>
                    <tr>
                        <td style="padding:0.4rem 0.75rem 0.4rem 0; color:var(--text-muted); vertical-align:top;">📝 About</td>
                        <td style="padding:0.4rem 0; color:var(--text-main); line-height:1.5;"><?= e($product['seller_bio']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
}

// Scroll to reviews tab when #reviews hash used
(function(){
    if (window.location.hash === '#reviews') {
        var btn = document.getElementById('reviews-tab-btn');
        if (btn) showTab('reviews', btn);
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
