<?php
/**
 * SokoHub Dynamic Product Catalog — Kilimall-style redesign
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

$search = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
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
    if (is_numeric($categorySlug)) {
        $where[] = 'c.id = :cat_slug';
    } else {
        $where[] = 'c.slug = :cat_slug';
    }
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

<style>
/* ── Catalog layout ── */
.catalog-wrap {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    padding: 1.25rem 0;
}

/* ── Sidebar ── */
.kili-sidebar {
    flex: 0 0 210px;
    min-width: 210px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
}
.sidebar-title {
    background: var(--brand-red);
    color: #fff;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius) var(--radius) 0 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.sidebar-section { padding: 0.75rem 0.9rem; border-bottom: 1px solid var(--border-color); }
.sidebar-section:last-child { border-bottom: none; }
.sidebar-section-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: block;
}
.sidebar-cat-link {
    display: block;
    padding: 0.3rem 0;
    color: var(--text-main);
    text-decoration: none;
    font-size: 0.82rem;
    border-radius: 2px;
    transition: color .15s;
}
.sidebar-cat-link:hover, .sidebar-cat-link.active { color: var(--brand-red); font-weight: 600; }
.sidebar-radio-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.82rem;
    cursor: pointer;
    padding: 0.2rem 0;
}
.price-inputs { display: flex; gap: 0.4rem; }
.price-input {
    width: 100%;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0.3rem 0.4rem;
    font-size: 0.8rem;
    color: var(--text-main);
}
.sidebar-sort-select {
    width: 100%;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.4rem;
    font-size: 0.82rem;
    color: var(--text-main);
}
.btn-apply-filter {
    display: block;
    width: 100%;
    background: var(--brand-red);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    padding: 0.5rem;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    margin-top: 0.75rem;
    text-align: center;
    transition: background .15s;
}
.btn-apply-filter:hover { background: var(--brand-dark-red); }
.btn-reset-filter {
    display: block;
    text-align: center;
    color: var(--brand-red);
    font-size: 0.8rem;
    text-decoration: none;
    margin-top: 0.4rem;
}

/* ── Main content area ── */
.catalog-main { flex: 1 1 0; min-width: 0; }

/* ── Top search bar ── */
.catalog-search-bar {
    display: flex;
    gap: 0;
    margin-bottom: 1rem;
    border: 2px solid var(--brand-red);
    border-radius: 3px;
    overflow: hidden;
}
.catalog-search-input {
    flex: 1;
    border: none;
    outline: none;
    padding: 0.6rem 0.9rem;
    font-size: 0.95rem;
    color: var(--text-main);
}
.catalog-search-btn {
    background: var(--brand-red);
    color: #fff;
    border: none;
    padding: 0 1.2rem;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: background .15s;
}
.catalog-search-btn:hover { background: var(--brand-dark-red); }

/* ── Results bar ── */
.catalog-results-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    font-size: 0.82rem;
    color: var(--text-muted);
    flex-wrap: wrap;
    gap: 0.5rem;
}
.catalog-results-bar strong { color: var(--text-main); }

/* ── Product grid ── */
.kili-product-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
}

/* ── Pagination ── */
.kili-pagination {
    display: flex;
    gap: 0.35rem;
    justify-content: center;
    margin-top: 1.5rem;
    flex-wrap: wrap;
}
.kili-pagination a,
.kili-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: var(--text-main);
    font-size: 0.85rem;
    font-weight: 600;
    background: var(--bg-surface);
    transition: border-color .15s, background .15s;
}
.kili-pagination a:hover { border-color: var(--brand-red); color: var(--brand-red); }
.kili-pagination span.current {
    background: var(--brand-red);
    color: #fff;
    border-color: var(--brand-red);
}

/* ── Empty state ── */
.kili-no-products {
    text-align: center;
    padding: 3rem 1rem;
    background: var(--bg-surface);
    border: 1px dashed var(--border-color);
    border-radius: var(--radius);
    color: var(--text-muted);
    grid-column: 1 / -1;
}

@media (max-width: 900px) {
    .kili-product-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .catalog-wrap { flex-direction: column; }
    .kili-sidebar { flex: none; width: 100%; min-width: unset; }
    .kili-product-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="container">
    <!-- Top action bar -->
    <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 0; flex-wrap:wrap; gap:0.5rem;">
        <h1 style="font-size:1.1rem; font-weight:800; color:var(--text-main); margin:0;">
            Product Catalog
            <span style="font-size:0.8rem; font-weight:400; color:var(--text-muted);">&mdash; <?= $totalItems ?> item(s)</span>
        </h1>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <?php if (is_logged_in()): ?>
                <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="btn btn-outline" style="font-size:0.82rem;">❤️ Wishlist</a>
            <?php endif; ?>
            <?php if (is_logged_in() && in_array(current_user()['role'], ['seller', 'admin'], true)): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary" style="font-size:0.82rem;">📦 My Products</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="catalog-wrap">
        <!-- ══ SIDEBAR ══ -->
        <aside class="kili-sidebar">
            <div class="sidebar-title">🔍 Filter &amp; Sort</div>
            <form action="<?= e(url('pages/public/products.php')) ?>" method="GET" id="searchFilterForm">

                <!-- Category links -->
                <div class="sidebar-section">
                    <span class="sidebar-section-label">Category</span>
                    <a href="<?= e(url('pages/public/products.php?' . http_build_query(array_filter(['q'=>$search,'condition'=>$condition,'sort'=>$sort], fn($v)=>$v!=='')))) ?>"
                       class="sidebar-cat-link <?= $categorySlug === '' ? 'active' : '' ?>">All Categories</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= e(url('pages/public/products.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$cat['slug'],'condition'=>$condition,'sort'=>$sort], fn($v)=>$v!=='')))) ?>"
                           class="sidebar-cat-link <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
                            <?= e($cat['name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <!-- hidden field so category persists through the filter form submit -->
                    <input type="hidden" name="category" value="<?= e($categorySlug) ?>">
                </div>

                <!-- Condition -->
                <div class="sidebar-section">
                    <span class="sidebar-section-label">Condition</span>
                    <label class="sidebar-radio-label">
                        <input type="radio" name="condition" value="" <?= $condition === '' ? 'checked' : '' ?>> Any
                    </label>
                    <label class="sidebar-radio-label">
                        <input type="radio" name="condition" value="New" <?= $condition === 'New' ? 'checked' : '' ?>> New
                    </label>
                    <label class="sidebar-radio-label">
                        <input type="radio" name="condition" value="Second Hand" <?= $condition === 'Second Hand' ? 'checked' : '' ?>> Second Hand
                    </label>
                </div>

                <!-- Price range -->
                <div class="sidebar-section">
                    <span class="sidebar-section-label">Price Range (KES)</span>
                    <div class="price-inputs">
                        <input type="number" class="price-input" placeholder="Min" name="price_min" min="0" value="<?= e($_GET['price_min'] ?? '') ?>">
                        <input type="number" class="price-input" placeholder="Max" name="price_max" min="0" value="<?= e($_GET['price_max'] ?? '') ?>">
                    </div>
                </div>

                <!-- Sort -->
                <div class="sidebar-section">
                    <span class="sidebar-section-label">Sort By</span>
                    <select name="sort" class="sidebar-sort-select" onchange="this.form.submit()">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
                        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Highest Rated</option>
                        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low → High</option>
                        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High → Low</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>A – Z</option>
                    </select>
                </div>

                <div style="padding:0 0.9rem 0.9rem;">
                    <!-- Hidden search passthrough -->
                    <input type="hidden" name="q" value="<?= e($search) ?>">
                    <button type="submit" class="btn-apply-filter">Apply Filters</button>
                    <a href="<?= e(url('pages/public/products.php')) ?>" class="btn-reset-filter">✕ Reset All</a>
                </div>
            </form>
        </aside>

        <!-- ══ MAIN CONTENT ══ -->
        <div class="catalog-main">
            <!-- Top search bar -->
            <div style="position:relative;">
                <form action="<?= e(url('pages/public/products.php')) ?>" method="GET" class="catalog-search-bar">
                    <input type="hidden" name="category" value="<?= e($categorySlug) ?>">
                    <input type="hidden" name="condition" value="<?= e($condition) ?>">
                    <input type="hidden" name="sort" value="<?= e($sort) ?>">
                    <input type="text" id="search-input" name="q" class="catalog-search-input"
                           value="<?= e($search) ?>"
                           placeholder="Search products, brands and categories..."
                           autocomplete="off">
                    <button type="submit" class="catalog-search-btn">SEARCH</button>
                </form>
                <!-- Autocomplete dropdown -->
                <div id="search-suggest-dropdown" style="display:none; position:absolute; left:0; right:0; top:100%; background:white; border:1px solid #cbd5e1; border-radius:4px; box-shadow:0 10px 25px rgba(0,0,0,0.15); z-index:1000; max-height:380px; overflow-y:auto; margin-top:2px;"></div>
            </div>

            <!-- Results info bar -->
            <div class="catalog-results-bar">
                <span>Showing <strong><?= $totalItems ?></strong> result<?= $totalItems !== 1 ? 's' : '' ?><?= $search ? ' for "<strong>' . e($search) . '</strong>"' : '' ?></span>
                <?php if ($categorySlug): ?>
                    <span>Category: <strong><?= e(ucfirst($categorySlug)) ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- Product grid -->
            <?php if (empty($products)): ?>
                <div class="kili-no-products">
                    <h3 style="margin:0 0 0.4rem;">No Products Found</h3>
                    <p>Try clearing your keyword or switching category filters.</p>
                </div>
            <?php else: ?>
                <div class="kili-product-grid">
                    <?php foreach ($products as $product):
                        $pid = (int) $product['id'];
                        $disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);
                        $inWishlist = isset($userWishlistIds[$pid]);
                        $stockQty = (int) $product['stock_qty'];
                        $isLow = ($stockQty > 0 && $stockQty <= 5);
                        $isOut = ($stockQty <= 0);
                    ?>
                    <article class="product-card" style="position:relative;">
                        <!-- Wishlist Heart Toggle -->
                        <form method="POST" action="" class="wishlist-form" style="position:absolute; top:0.5rem; right:0.5rem; z-index:10; margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_wishlist">
                            <input type="hidden" name="product_id" value="<?= $pid ?>">
                            <button type="submit" class="wishlist-btn" style="background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:30px; height:30px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.15); font-size:0.9rem;" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Save to Wishlist' ?>">
                                <?= $inWishlist ? '❤️' : '🤍' ?>
                            </button>
                        </form>

                        <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>" class="product-thumb">
                            <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
                            <?php if ($disc > 0): ?>
                                <span class="discount-badge">-<?= $disc ?>%</span>
                            <?php endif; ?>
                        </a>
                        <div class="product-body">
                            <div class="product-title">
                                <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>"><?= e($product['name']) ?></a>
                            </div>
                            <div class="product-price-row">
                                <span class="sale-price"><?= format_ksh($product['price']) ?></span>
                                <?php if ($product['original_price'] !== null && (float) $product['original_price'] > (float) $product['price']): ?>
                                    <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="product-rating">
                                <?= str_repeat('★', min(5, (int) round((float) $product['rating_avg']))) ?><?= str_repeat('☆', max(0, 5 - (int) round((float) $product['rating_avg']))) ?>
                                <span>(<?= (int) $product['rating_count'] ?>)</span>
                            </div>
                            <?php if ($isOut): ?>
                                <span style="color:#ef4444; font-size:0.75rem; font-weight:700;">Out of Stock</span>
                            <?php elseif ($isLow): ?>
                                <span style="color:#b45309; font-size:0.72rem; font-weight:700;">Only <?= $stockQty ?> left!</span>
                            <?php endif; ?>

                            <?php if ($stockQty > 0): ?>
                                <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" style="margin-top:0.4rem;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary btn-block">Add to Cart</button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-primary btn-block" disabled style="opacity:.5; cursor:not-allowed;">Out of Stock</button>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="kili-pagination" aria-label="Product Pagination">
                        <?php
                        $queryBase = ['q' => $search, 'category' => $categorySlug, 'condition' => $condition, 'sort' => $sort];
                        for ($i = 1; $i <= $totalPages; $i++):
                            $queryBase['page'] = $i;
                            $pUrl = url('pages/public/products.php?' . http_build_query(array_filter($queryBase, fn($v) => $v !== '')));
                            if ($i === $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= e($pUrl) ?>"><?= $i ?></a>
                            <?php endif;
                        endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                        html += '<div style="padding:0.5rem 0.75rem; background:#f8fafc; font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Categories</div>';
                        data.categories.forEach(function(c) {
                            html += '<a href="<?= e(url('pages/public/products.php')) ?>?category=' + encodeURIComponent(c.id) + '" style="display:block; padding:0.5rem 0.75rem; text-decoration:none; color:#0f172a; font-size:0.85rem; border-bottom:1px solid #f1f5f9;">📁 ' + escapeHtml(c.name) + '</a>';
                        });
                    }

                    if (data.suggestions && data.suggestions.length > 0) {
                        html += '<div style="padding:0.5rem 0.75rem; background:#f8fafc; font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Products</div>';
                        data.suggestions.forEach(function(p) {
                            html += '<a href="<?= e(url('')) ?>' + p.url + '" style="display:flex; align-items:center; gap:0.75rem; padding:0.5rem 0.75rem; text-decoration:none; color:#0f172a; border-bottom:1px solid #f1f5f9;">' +
                                '<img src="<?= e(url('')) ?>' + p.image_path + '" style="width:36px; height:36px; object-fit:cover; border-radius:3px;">' +
                                '<div style="flex-grow:1; min-width:0;">' +
                                    '<div style="font-size:0.85rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escapeHtml(p.name) + '</div>' +
                                    '<div style="font-size:0.75rem; color:var(--brand-red); font-weight:700;">' + p.formatted_price + '</div>' +
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
