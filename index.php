<?php
/**
 * SokoHub Dynamic Home Page — Kilimall-style redesign
 */
require_once __DIR__ . '/includes/auth.php';
init_session();
track_visit_cookie();

$pdo = get_db_connection();

// Fetch categories for quick navigation strip
$categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY id ASC')->fetchAll();

// Fetch featured active products joined with category and seller profile
$featuredStmt = $pdo->query(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
            COALESCE(sp.business_name, u.full_name) AS seller_name
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     INNER JOIN users u ON u.id = p.seller_id
     LEFT JOIN seller_profiles sp ON sp.user_id = u.id
     WHERE p.status = 'active' AND p.stock_qty > 0
     ORDER BY p.is_featured DESC, p.created_at DESC
     LIMIT 8"
);
$featuredProducts = $featuredStmt->fetchAll();

// Fetch summary counts for hero metrics
$metrics = [
    'products' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn(),
    'sellers'  => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller' AND status = 'active'")->fetchColumn(),
    'riders'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'delivery' AND status = 'active'")->fetchColumn(),
    'orders'   => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
];

$pageTitle = 'SokoHub | Modern Local Digital Marketplace';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ═══════════════════════════════ HERO BANNER ════════════════════════════════ -->
<section class="kili-hero">
    <div class="kili-hero-left">
        <div class="kili-hero-slider">
            <button class="slider-arrow slider-prev" aria-label="Previous"><?= icon('chevron-left', 'lucide-sm') ?></button>
            <button class="slider-arrow slider-next" aria-label="Next"><?= icon('chevron-right', 'lucide-sm') ?></button>

            <div class="kili-hero-content">
                <span class="hero-tag"><?= icon('tag', 'lucide-xs') ?> Limited Time Offer</span>
                <h1>Up to 50% OFF on<br>Electronics &amp; Gas!</h1>
                <p>Shop the best local deals &mdash; fresh, affordable, and delivered to your door within Kirinyaga.</p>
                <div class="hero-btn-row">
                    <a href="<?= e(url('pages/public/products.php')) ?>" class="btn-hero-primary"><?= icon('shopping-bag', 'lucide-xs') ?> <span>Shop Now</span></a>
                    <a href="<?= e(url('pages/public/products.php?sort=newest')) ?>" class="btn-hero-outline"><?= icon('sparkles', 'lucide-xs') ?> <span>New Arrivals</span></a>
                </div>
            </div>
        </div>
    </div>

    <div class="kili-hero-right">
        <?php
        $heroCatIcons = [
            'gas'         => 'flame',
            'electronics' => 'smartphone',
            'clothes'     => 'shirt',
            'food'        => 'apple',
            'others'      => 'home',
        ];
        foreach (array_slice($categories, 0, 4) as $cat):
            $catIcon = $heroCatIcons[$cat['slug']] ?? 'package';
        ?>
        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($cat['slug']))) ?>" class="hero-cat-tile">
            <span class="hero-cat-icon"><?= icon($catIcon, 'lucide-sm') ?></span>
            <span><?= e($cat['name']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- ══════════════════════════ QUICK ACCESS SHORTCUTS ═════════════════════════ -->
<div class="quick-access-bar">
    <div class="container">
        <div class="quick-access-row">
            <a href="<?= e(url('pages/public/products.php?sort=newest')) ?>" class="quick-link">
                <span class="qa-icon"><?= icon('zap', 'lucide-sm') ?></span>
                <span>Flash Sales</span>
            </a>
            <a href="<?= e(url('pages/auth/register.php')) ?>" class="quick-link">
                <span class="qa-icon"><?= icon('gift', 'lucide-sm') ?></span>
                <span>New User</span>
            </a>
            <a href="<?= e(url('pages/public/products.php?sort=rating')) ?>" class="quick-link">
                <span class="qa-icon"><?= icon('percent', 'lucide-sm') ?></span>
                <span>Top Deals</span>
            </a>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="quick-link">
                <span class="qa-icon"><?= icon('grid', 'lucide-sm') ?></span>
                <span>All Categories</span>
            </a>
            <a href="<?= e(url('pages/public/products.php?sort=price_asc')) ?>" class="quick-link">
                <span class="qa-icon"><?= icon('tag', 'lucide-sm') ?></span>
                <span>Clearance</span>
            </a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════ FLASH DEALS ═══════════════════════════════ -->
<section class="container section-space">
    <div class="flash-deals-header">
        <span class="flash-title"><?= icon('zap', 'lucide-sm') ?> FLASH DEALS</span>
        <div class="flash-countdown" id="flash-countdown">
            <span class="cd-label">Ends in:</span>
            <span class="cd-block" id="cd-h">00</span>:
            <span class="cd-block" id="cd-m">00</span>:
            <span class="cd-block" id="cd-s">00</span>
        </div>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="flash-see-all">
            <span>See All</span> <?= icon('arrow-right', 'lucide-xs') ?>
        </a>
    </div>

    <?php if (empty($featuredProducts)): ?>
        <div class="kili-empty-state">
            <p>No featured products available yet. Sellers can add products from the Seller Dashboard.</p>
        </div>
    <?php else: ?>
        <div class="flash-scroll-row">
            <?php foreach ($featuredProducts as $product):
                $disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);
            ?>
            <article class="product-card">
                <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>" class="product-thumb">
                    <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
                    <?php if ($disc > 0): ?>
                        <span class="discount-badge">-<?= $disc ?>%</span>
                    <?php endif; ?>
                </a>
                <div class="product-body">
                    <div class="product-title">
                        <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>"><?= e($product['name']) ?></a>
                    </div>
                    <div class="product-price-row">
                        <span class="sale-price"><?= format_ksh($product['price']) ?></span>
                        <?php if ($product['original_price'] !== null && (float) $product['original_price'] > (float) $product['price']): ?>
                            <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="product-rating">
                        <?= render_stars((float) $product['rating_avg'], (int) $product['rating_count']) ?>
                    </div>
                    <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="btn btn-primary btn-block"><?= icon('shopping-cart', 'lucide-xs') ?> <span>Add to Cart</span></button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ══════════════════════════════ CATEGORIES GRID ════════════════════════════ -->
<section class="container section-space">
    <div class="section-heading">
        <h2>Shop by Category</h2>
        <a href="<?= e(url('pages/public/products.php')) ?>" class="see-all-link"><span>View All</span> <?= icon('arrow-right', 'lucide-xs') ?></a>
    </div>

    <div class="kili-category-grid">
        <a href="<?= e(url('pages/public/products.php')) ?>" class="kili-cat-card">
            <span class="kili-cat-emoji"><?= icon('shopping-bag', 'lucide-sm') ?></span>
            <span>All Items</span>
        </a>
        <?php
        foreach ($categories as $cat):
            $catIcon = $heroCatIcons[$cat['slug']] ?? 'package';
        ?>
        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($cat['slug']))) ?>" class="kili-cat-card">
            <span class="kili-cat-emoji"><?= icon($catIcon, 'lucide-sm') ?></span>
            <span><?= e($cat['name']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════════════════════════ WHY SOKOHUB ═══════════════════════════════ -->
<section class="why-sokohub-strip">
    <div class="container">
        <div class="why-row">
            <div class="why-item">
                <span class="why-icon"><?= icon('smartphone', 'lucide-md') ?></span>
                <div>
                    <strong>M-Pesa Payments</strong>
                    <p>Pay instantly and securely via M-Pesa or Cash on Delivery.</p>
                </div>
            </div>
            <div class="why-item">
                <span class="why-icon"><?= icon('bike', 'lucide-md') ?></span>
                <div>
                    <strong>Fast Local Delivery</strong>
                    <p>Dedicated riders deliver within 30&ndash;60 minutes in Kirinyaga.</p>
                </div>
            </div>
            <div class="why-item">
                <span class="why-icon"><?= icon('shield-check', 'lucide-md') ?></span>
                <div>
                    <strong>Verified Sellers</strong>
                    <p>Every vendor is screened and rated by real customers.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Live Marketplace Metrics (compact strip) -->
<section class="container" style="margin-bottom: 2rem;">
    <div class="metrics-strip">
        <div class="metric-item"><strong><?= $metrics['products'] ?>+</strong><span>Active Listings</span></div>
        <div class="metric-item"><strong><?= $metrics['sellers'] ?>+</strong><span>Verified Sellers</span></div>
        <div class="metric-item"><strong><?= $metrics['riders'] ?>+</strong><span>Delivery Riders</span></div>
        <div class="metric-item"><strong><?= $metrics['orders'] ?>+</strong><span>Orders Placed</span></div>
    </div>
</section>

<script>
// Flash Deals countdown — resets to 8 hours from page load
(function() {
    var end = Date.now() + 8 * 3600 * 1000;
    function tick() {
        var diff = Math.max(0, Math.floor((end - Date.now()) / 1000));
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        document.getElementById('cd-h').textContent = String(h).padStart(2,'0');
        document.getElementById('cd-m').textContent = String(m).padStart(2,'0');
        document.getElementById('cd-s').textContent = String(s).padStart(2,'0');
        if (diff > 0) setTimeout(tick, 1000);
    }
    tick();
})();
</script>

<style>
/* ── Kilimall Hero ── */
.kili-hero {
    display: flex;
    min-height: 320px;
    background: var(--brand-red);
}
.kili-hero-left {
    flex: 1 1 60%;
    position: relative;
    overflow: hidden;
}
.kili-hero-slider {
    height: 100%;
    padding: 2.5rem 2rem 2.5rem 2.5rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.slider-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    font-size: 2rem;
    width: 40px;
    height: 60px;
    cursor: pointer;
    z-index: 2;
    border-radius: 3px;
}
.slider-prev { left: 0; }
.slider-next { right: 0; }
.kili-hero-content { max-width: 520px; }
.hero-tag {
    display: inline-block;
    background: var(--brand-orange);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.2rem 0.6rem;
    border-radius: 3px;
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.kili-hero-content h1 {
    color: #fff;
    font-size: 2.2rem;
    font-weight: 900;
    line-height: 1.2;
    margin: 0 0 0.75rem;
}
.kili-hero-content p {
    color: rgba(255,255,255,0.88);
    font-size: 1rem;
    margin-bottom: 1.5rem;
    line-height: 1.5;
}
.hero-btn-row { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.btn-hero-primary {
    background: var(--brand-orange);
    color: #fff;
    padding: 0.65rem 1.6rem;
    font-weight: 700;
    font-size: 1rem;
    border-radius: 3px;
    text-decoration: none;
    display: inline-block;
    transition: opacity .2s;
}
.btn-hero-primary:hover { opacity: 0.88; }
.btn-hero-outline {
    background: transparent;
    color: #fff;
    border: 2px solid #fff;
    padding: 0.63rem 1.4rem;
    font-weight: 700;
    font-size: 1rem;
    border-radius: 3px;
    text-decoration: none;
    display: inline-block;
    transition: background .2s;
}
.btn-hero-outline:hover { background: rgba(255,255,255,0.12); }

.kili-hero-right {
    flex: 0 0 220px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px;
    background: rgba(0,0,0,0.15);
}
.hero-cat-tile {
    background: rgba(255,255,255,0.12);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    color: #fff;
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 1rem 0.5rem;
    transition: background .2s;
}
.hero-cat-tile:hover { background: rgba(255,255,255,0.22); }
.hero-cat-icon { font-size: 1.8rem; }

/* ── Quick Access Bar ── */
.quick-access-bar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    padding: 0.6rem 0;
}
.quick-access-row {
    display: flex;
    gap: 0;
    justify-content: space-around;
}
.quick-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    color: var(--text-main);
    text-decoration: none;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.4rem 0.75rem;
    border-radius: 3px;
    transition: color .15s;
}
.quick-link:hover { color: var(--brand-red); }
.qa-icon { font-size: 1.3rem; }

/* ── Flash Deals ── */
.flash-deals-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: var(--brand-red);
    padding: 0.6rem 1rem;
    border-radius: 3px 3px 0 0;
    margin-bottom: 0;
}
.flash-title {
    color: #fff;
    font-weight: 900;
    font-size: 1.1rem;
    letter-spacing: 0.5px;
    flex: 1;
}
.flash-countdown {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    color: #fff;
    font-size: 0.85rem;
}
.cd-label { font-size: 0.75rem; opacity: .85; }
.cd-block {
    background: rgba(0,0,0,0.3);
    padding: 0.1rem 0.4rem;
    border-radius: 2px;
    font-weight: 700;
    font-size: 0.95rem;
    min-width: 28px;
    text-align: center;
}
.flash-see-all {
    color: #fff;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
.flash-see-all:hover { text-decoration: underline; }

.flash-scroll-row {
    display: flex;
    gap: 0;
    overflow-x: auto;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-top: none;
    padding: 0.75rem;
    gap: 0.75rem;
    scrollbar-width: thin;
}
.flash-scroll-row .product-card {
    flex: 0 0 180px;
    min-width: 180px;
}

/* ── Category Grid ── */
.kili-category-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 0.75rem;
}
.kili-cat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem 0.5rem;
    text-decoration: none;
    color: var(--text-main);
    font-size: 0.8rem;
    font-weight: 600;
    gap: 0.4rem;
    transition: border-color .15s, box-shadow .15s;
    text-align: center;
}
.kili-cat-card:hover { border-color: var(--brand-red); box-shadow: var(--shadow-sm); }
.kili-cat-emoji { font-size: 2rem; }

/* ── Why SokoHub Strip ── */
.why-sokohub-strip {
    background: var(--bg-surface);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    padding: 1.5rem 0;
    margin-bottom: 0;
}
.why-row {
    display: flex;
    gap: 2rem;
    justify-content: space-around;
    flex-wrap: wrap;
}
.why-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    flex: 1 1 220px;
}
.why-icon { font-size: 2rem; flex-shrink: 0; }
.why-item strong { display: block; font-size: 0.95rem; color: var(--text-main); }
.why-item p { font-size: 0.8rem; color: var(--text-muted); margin: 0.15rem 0 0; line-height: 1.4; }

/* ── Metrics Strip ── */
.metrics-strip {
    display: flex;
    gap: 1rem;
    justify-content: space-around;
    flex-wrap: wrap;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 1rem 0;
    margin-top: 1.5rem;
}
.metric-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.15rem;
}
.metric-item strong { font-size: 1.5rem; color: var(--brand-red); font-weight: 900; }
.metric-item span { font-size: 0.75rem; color: var(--text-muted); }

/* ── Section utilities ── */
.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.section-heading h2 { font-size: 1.15rem; font-weight: 800; color: var(--text-main); }
.see-all-link { color: var(--brand-red); font-size: 0.85rem; text-decoration: none; font-weight: 600; }
.see-all-link:hover { text-decoration: underline; }
.kili-empty-state {
    text-align: center;
    padding: 2rem;
    background: var(--bg-surface);
    border: 1px dashed var(--border-color);
    border-radius: var(--radius);
    color: var(--text-muted);
}
.section-space { padding-top: 1.5rem; padding-bottom: 1.5rem; }

@media (max-width: 768px) {
    .kili-hero { flex-direction: column; }
    .kili-hero-right { flex: none; grid-template-columns: repeat(4, 1fr); }
    .kili-hero-content h1 { font-size: 1.5rem; }
    .kili-category-grid { grid-template-columns: repeat(3, 1fr); }
    .flash-scroll-row .product-card { flex: 0 0 150px; min-width: 150px; }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
