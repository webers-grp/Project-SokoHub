<?php
/**
 * SokoHub Dynamic Home Page
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
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

$recentAlerts = Notifications::getRecentAnnouncements(3);

$pageTitle = 'SokoHub | Modern Local Digital Marketplace';
require_once __DIR__ . '/includes/header.php';
?>

<section class="hero-banner">
    <div class="container hero-inner">
        <div>
            <span class="hero-eyebrow">Kirinyaga &amp; Kenya Local Commerce</span>
            <h1>Shop Local, Sell Smarter &amp; Deliver Faster with SokoHub</h1>
            <p>SokoHub connects small and medium local vendors, customers, and verified delivery riders in one secure marketplace — from fresh organic produce and cooking gas to electronics and apparel.</p>
            <div class="hero-cta">
                <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-amber">
                    <?= icon('shopping-bag') ?>
                    <span>Explore Marketplace</span>
                </a>
                <a href="<?= e(url('pages/auth/register.php')) ?>" class="btn btn-light">
                    <?= icon('user-plus') ?>
                    <span>Create Free Account</span>
                </a>
            </div>
        </div>

        <div class="hero-highlight-card">
            <h3>Live Marketplace Pulse</h3>
            <div class="hero-metrics">
                <div class="hero-metric">
                    <strong><?= $metrics['products'] ?></strong>
                    <span>Active Listings</span>
                </div>
                <div class="hero-metric">
                    <strong><?= $metrics['sellers'] ?></strong>
                    <span>Verified Sellers</span>
                </div>
                <div class="hero-metric">
                    <strong><?= $metrics['riders'] ?></strong>
                    <span>Delivery Riders</span>
                </div>
                <div class="hero-metric">
                    <strong><?= $metrics['orders'] ?></strong>
                    <span>Orders Placed</span>
                </div>
            </div>
        </div>
    </div>
</section>

<main class="container section-space">
    <?php if (!empty($recentAlerts)): ?>
        <div style="margin-bottom: 2rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.75rem 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span class="badge badge-success" style="font-size: 0.75rem; text-transform: uppercase;">
                    <?= icon('bell', 'lucide-xs', 'white') ?>
                    <span>Marketplace Live</span>
                </span>
                <span style="font-size: 0.9rem; color: #166534;">
                    <strong><?= e($recentAlerts[0]['title']) ?></strong> &mdash; <?= e($recentAlerts[0]['message']) ?>
                </span>
            </div>
            <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="btn btn-outline btn-sm" style="font-size: 0.75rem; border-color: #16a34a; color: #166534;">
                <span>View All Alerts</span>
                <?= icon('arrow-right', 'lucide-xs') ?>
            </a>
        </div>
    <?php endif; ?>

    <section>
        <div class="section-heading">
            <h2>Browse by Category</h2>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-outline btn-sm">
                <span>View Full Catalog</span>
                <?= icon('arrow-right', 'lucide-sm') ?>
            </a>
        </div>
        <div class="category-strip">
            <a href="<?= e(url('pages/public/products.php')) ?>" class="cat-chip active">All Categories</a>
            <?php foreach ($categories as $cat): ?>
                <a href="<?= e(url('pages/public/products.php?category=' . urlencode($cat['slug']))) ?>" class="cat-chip">
                    <?= e($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <div class="section-heading">
            <h2>Featured Marketplace Deals</h2>
            <?php if (!empty($_COOKIE['sokohub_recent_category'])): ?>
                <span class="muted small">
                    Last browsed: <a href="<?= e(url('pages/public/products.php?category=' . urlencode($_COOKIE['sokohub_recent_category']))) ?>"><?= e(ucfirst($_COOKIE['sokohub_recent_category'])) ?></a>
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($featuredProducts)): ?>
            <div class="card">
                <p class="muted">No featured products available yet. Sellers can add products from the Seller Dashboard.</p>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($featuredProducts as $product): ?>
                    <?php $disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null); ?>
                    <article class="product-card">
                        <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>" class="product-thumb">
                            <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>">
                            <?php if ($disc > 0): ?>
                                <span class="discount-badge"><?= $disc ?>% OFF</span>
                            <?php endif; ?>
                            <span class="condition-badge"><?= e($product['condition_type']) ?></span>
                        </a>
                        <div class="product-body">
                            <span class="product-category"><?= e($product['category_name']) ?></span>
                            <h3 class="product-title">
                                <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>"><?= e($product['name']) ?></a>
                            </h3>
                            <p class="product-seller">Sold by <?= e($product['seller_name']) ?> &bull; <?= (int) $product['stock_qty'] ?> left</p>
                            <div class="product-price-row">
                                <span class="sale-price"><?= format_ksh($product['price']) ?></span>
                                <?php if ($product['original_price'] !== null && (float) $product['original_price'] > (float) $product['price']): ?>
                                    <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="product-actions">
                                <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>" class="btn btn-outline btn-sm" style="flex:1;">
                                    <?= icon('eye', 'lucide-xs', 'blue') ?>
                                    <span>Details</span>
                                </a>
                                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" style="flex:1;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary btn-sm btn-block">
                                        <?= icon('shopping-cart', 'lucide-xs', 'gold') ?>
                                        <span>Add to Cart</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section style="margin-top: 3rem;">
        <div class="section-heading">
            <h2>Why Choose SokoHub?</h2>
        </div>
        <div class="prop-grid">
            <div class="prop-item">
                <div class="prop-icon-wrap prop-icon-green"><?= icon('truck', 'lucide-md') ?></div>
                <h3>Instant Local Delivery</h3>
                <p>Dedicated motorbike, bicycle, and van riders pick up directly from local merchants and update real-time order statuses.</p>
            </div>
            <div class="prop-item">
                <div class="prop-icon-wrap prop-icon-amber"><?= icon('credit-card', 'lucide-md') ?></div>
                <h3>Flexible Payments</h3>
                <p>Checkout smoothly using M-Pesa, Cash on Delivery, or Bank Card with clear order receipts and tracking codes.</p>
            </div>
            <div class="prop-item">
                <div class="prop-icon-wrap prop-icon-blue"><?= icon('store', 'lucide-md') ?></div>
                <h3>Empowered Local MSMEs</h3>
                <p>Vendors manage inventory, pricing, stock alerts, and revenue reports from one clean web dashboard.</p>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
