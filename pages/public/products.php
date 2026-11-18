<?php
/**
 * SokoHub Dynamic Product Catalog
 * Supports debounced typeahead live search, category filtering (with cookie tracking),
 * condition filter, sorting, wishlist toggling, and pagination.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/wishlist.php';

init_session();

$pdo = get_db_connection();
$userId = is_logged_in() ? (int) current_user()['id'] : 0;

// Handle Wishlist AJAX/POST Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_wishlist') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'CSRF verification failed']);
        exit;
    }
    $pid = (int) ($_POST['product_id'] ?? 0);
    $res = Wishlist::toggle($userId, $pid);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }
    set_flash('info', $res['ok'] ? ($res['action'] === 'added' ? 'Added to wishlist!' : 'Removed from wishlist.') : $res['error']);
    redirect('pages/public/products.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$categorySlug = trim((string) ($_GET['category'] ?? ''));
$condition = trim((string) ($_GET['condition'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'newest'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

if ($categorySlug !== '') {
    set_recent_category_cookie($categorySlug);
}

$categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name ASC')->fetchAll();

// Build dynamic WHERE clause safely using prepared placeholders
$where = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :search_name OR p.description LIKE :search_desc)';
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_desc'] = '%' . $search . '%';
}

if ($categorySlug !== '') {
    $where[] = 'c.slug = :cat_slug';
    $params[':cat_slug'] = $categorySlug;
}

if (in_array($condition, ['New', 'Second Hand'], true)) {
    $where[] = 'p.condition_type = :cond';
    $params[':cond'] = $condition;
}

$whereSql = implode(' AND ', $where);

$orderBy = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'rating'     => 'p.rating_avg DESC',
    'name_asc'   => 'p.name ASC',
    default      => 'p.created_at DESC',
};

// Total count for pagination
$countSql = "SELECT COUNT(*)
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                   COALESCE(sp.business_name, u.full_name) AS seller_name
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            INNER JOIN users u ON u.id = p.seller_id
            LEFT JOIN seller_profiles sp ON sp.user_id = u.id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset";

$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $val) {
    $listStmt->bindValue($key, $val);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$products = $listStmt->fetchAll();

// Preload user wishlist product IDs
$userWishlistIds = [];
if ($userId > 0) {
    $wStmt = $pdo->prepare('SELECT product_id FROM wishlists WHERE user_id = :uid');
    $wStmt->execute([':uid' => $userId]);
    $userWishlistIds = array_flip($wStmt->fetchAll(PDO::FETCH_COLUMN));
}

$pageTitle = 'SokoHub | Explore Products';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Explore Local Marketplace Products</h1>
            <p class="muted">Showing <strong><?= $totalItems ?></strong> matching item(s) across Kirinyaga vendors.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <?php if (is_logged_in()): ?>
                <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="btn btn-outline">
                    <?= icon('heart', 'lucide-xs', 'rose') ?>
                    <span>My Wishlist</span>
                </a>
            <?php endif; ?>
            <?php if (is_logged_in() && in_array(current_user()['role'], ['seller', 'admin'], true)): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">
                    <?= icon('package', 'lucide-xs', 'white') ?>
                    <span>Manage My Products</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="shop-layout">
        <!-- Sidebar Filter Panel -->
        <aside class="filter-panel">
            <h3><?= icon('filter', 'lucide-sm') ?> Filter &amp; Sort</h3>
            <form action="<?= e(url('pages/public/products.php')) ?>" method="GET" id="searchFilterForm">
                <!-- Live Search Autocomplete Input -->
                <div class="form-group" style="position: relative;">
                    <label for="search-input">Search Keyword</label>
                    <input type="text" id="search-input" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Gas, honey, kiondo, phone..." autocomplete="off">
                    <!-- Dropdown Container -->
                    <div id="search-suggest-dropdown" style="display: none; position: absolute; left: 0; right: 0; top: 100%; background: white; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); z-index: 1000; max-height: 380px; overflow-y: auto; margin-top: 4px;"></div>
                </div>

                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="form-control">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['slug']) ?>" <?= $categorySlug === $cat['slug'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="condition">Condition</label>
                    <select id="condition" name="condition" class="form-control">
                        <option value="">Any Condition</option>
                        <option value="New" <?= $condition === 'New' ? 'selected' : '' ?>>New</option>
                        <option value="Second Hand" <?= $condition === 'Second Hand' ? 'selected' : '' ?>>Second Hand</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort" class="form-control">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
                        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Customer Rating (Highest)</option>
                        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Alphabetical (A-Z)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= icon('filter', 'lucide-xs', 'white') ?>
                    <span>Apply Filters</span>
                </button>
                <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-outline btn-block" style="margin-top: 0.5rem;">
                    <?= icon('rotate-ccw', 'lucide-xs', 'slate') ?>
                    <span>Reset</span>
                </a>
            </form>
        </aside>

        <!-- Product Grid Column -->
        <div>
            <?php if (empty($products)): ?>
                <div class="card" style="text-align: center; padding: 3rem 1rem;">
                    <h3>No Products Found</h3>
                    <p class="muted" style="margin-top: 0.4rem;">Try clearing your keyword or switching category filters.</p>
                </div>
            <?php else: ?>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php 
                        $pid = (int) $product['id'];
                        $disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);
                        $inWishlist = isset($userWishlistIds[$pid]);
                        $stockQty = (int) $product['stock_qty'];
                        $isLow = ($stockQty > 0 && $stockQty <= 5);
                        $isOut = ($stockQty <= 0);
                        ?>
                        <article class="product-card" style="position: relative;">
                            <!-- Wishlist Heart Toggle -->
                            <form method="POST" action="" class="wishlist-form" style="position: absolute; top: 0.75rem; right: 0.75rem; z-index: 10; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_wishlist">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <button type="submit" class="wishlist-btn" style="background: rgba(255,255,255,0.9); border: none; border-radius: 50%; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.15);" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Save to Wishlist' ?>">
                                    <?= $inWishlist ? '❤️' : '🤍' ?>
                                </button>
                            </form>

                            <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>" class="product-thumb">
                                <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
                                <?php if ($disc > 0): ?>
                                    <span class="discount-badge"><?= $disc ?>% OFF</span>
                                <?php endif; ?>
                                <span class="condition-badge"><?= e($product['condition_type']) ?></span>
                            </a>
                            <div class="product-body">
                                <span class="product-category"><?= e($product['category_name']) ?></span>
                                <h3 class="product-title">
                                    <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>"><?= e($product['name']) ?></a>
                                </h3>

                                <!-- Star Ratings & Scarcity Row -->
                                <div style="display: flex; align-items: center; justify-content: space-between; margin: 0.35rem 0; font-size: 0.8rem;">
                                    <div style="color: #eab308; font-weight: 700; display: flex; align-items: center; gap: 0.2rem;">
                                        ★ <?= number_format((float) $product['rating_avg'], 1) ?>
                                        <span style="color: #94a3b8; font-weight: 400; font-size: 0.75rem;">(<?= (int) $product['rating_count'] ?>)</span>
                                    </div>
                                    <?php if ($isOut): ?>
                                        <span style="color: #ef4444; font-weight: 700; font-size: 0.75rem;">Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 0.1rem 0.4rem; border-radius: 4px; font-weight: 700; font-size: 0.7rem;">Only <?= $stockQty ?> left!</span>
                                    <?php else: ?>
                                        <span style="color: #10b981; font-size: 0.75rem;"><?= $stockQty ?> in stock</span>
                                    <?php endif; ?>
                                </div>

                                <p class="product-seller">
                                    By <?= e($product['seller_name']) ?>
                                </p>
                                <div class="product-price-row">
                                    <span class="sale-price"><?= format_ksh($product['price']) ?></span>
                                    <?php if ($product['original_price'] !== null && (float) $product['original_price'] > (float) $product['price']): ?>
                                        <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="product-actions">
                                    <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>" class="btn btn-outline btn-sm" style="flex:1;">
                                        <?= icon('eye', 'lucide-xs', 'blue') ?>
                                        <span>View</span>
                                    </a>
                                    <?php if ($stockQty > 0): ?>
                                        <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" style="flex:1;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="add">
                                            <input type="hidden" name="product_id" value="<?= $pid ?>">
                                            <input type="hidden" name="quantity" value="1">
                                            <button type="submit" class="btn btn-primary btn-sm btn-block">
                                                <?= icon('shopping-cart', 'lucide-xs', 'gold') ?>
                                                <span>Add</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Product Pagination">
                        <?php
                        $queryBase = ['q' => $search, 'category' => $categorySlug, 'condition' => $condition, 'sort' => $sort];
                        for ($i = 1; $i <= $totalPages; $i++):
                            $queryBase['page'] = $i;
                            $url = url('pages/public/products.php?' . http_build_query(array_filter($queryBase, fn($v) => $v !== '')));
                            if ($i === $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= e($url) ?>"><?= $i ?></a>
                            <?php endif;
                        endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
// Live Search Typeahead Autocomplete
(function() {
    var searchInput = document.getElementById('search-input');
    var dropdown = document.getElementById('search-suggest-dropdown');
    var suggestUrl = <?= json_encode(url('pages/api/search_suggest.php')) ?>;
    var timer = null;

    if (!searchInput || !dropdown) return;

    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            return;
        }

        timer = setTimeout(function() {
            fetch(suggestUrl + '?q=' + encodeURIComponent(q))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data.ok || (data.suggestions.length === 0 && data.categories.length === 0)) {
                        dropdown.style.display = 'none';
                        return;
                    }

                    var html = '';
                    if (data.categories && data.categories.length > 0) {
                        html += '<div style="padding: 0.5rem 0.75rem; background: #f8fafc; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Categories</div>';
                        data.categories.forEach(function(c) {
                            html += '<a href="<?= e(url('pages/public/products.php')) ?>?category=' + encodeURIComponent(c.id) + '" style="display: block; padding: 0.5rem 0.75rem; text-decoration: none; color: #0f172a; font-size: 0.85rem; border-bottom: 1px solid #f1f5f9;">📁 ' + escapeHtml(c.name) + '</a>';
                        });
                    }

                    if (data.suggestions && data.suggestions.length > 0) {
                        html += '<div style="padding: 0.5rem 0.75rem; background: #f8fafc; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Products</div>';
                        data.suggestions.forEach(function(p) {
                            html += '<a href="<?= e(url('')) ?>' + p.url + '" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; text-decoration: none; color: #0f172a; border-bottom: 1px solid #f1f5f9;">' +
                                '<img src="<?= e(url('')) ?>' + p.image_path + '" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px;">' +
                                '<div style="flex-grow: 1; min-width: 0;">' +
                                    '<div style="font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + escapeHtml(p.name) + '</div>' +
                                    '<div style="font-size: 0.75rem; color: #16a34a; font-weight: 700;">' + p.formatted_price + '</div>' +
                                '</div>' +
                            '</a>';
                        });
                    }

                    dropdown.innerHTML = html;
                    dropdown.style.display = 'block';
                })
                .catch(function() {
                    dropdown.style.display = 'none';
                });
        }, 250);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
