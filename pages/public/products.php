<?php
/**
 * SokoHub Dynamic Product Catalog
 * Supports search, category filtering (with cookie tracking), condition filter, sorting, and pagination.
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

$pdo = get_db_connection();

$search = trim((string) ($_GET['q'] ?? ''));
$categorySlug = trim((string) ($_GET['category'] ?? ''));
$condition = trim((string) ($_GET['condition'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'newest'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 6;

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

$authUser = current_user();
$userWishlist = [];
if ($authUser) {
    try {
        $wStmt = $pdo->prepare('SELECT product_id FROM wishlists WHERE user_id = :uid');
        $wStmt->execute([':uid' => (int) $authUser['id']]);
        $userWishlist = array_map('intval', $wStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        $userWishlist = [];
    }
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
        <?php if (is_logged_in() && in_array(current_user()['role'], ['seller', 'admin'], true)): ?>
            <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">
                <?= icon('package') ?>
                <span>Manage My Products</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="shop-layout">
        <aside class="filter-panel">
            <h3><?= icon('filter', 'lucide-sm') ?> Filter &amp; Sort</h3>
            <form action="<?= e(url('pages/public/products.php')) ?>" method="GET">
                <div class="form-group">
                    <label for="q">Search Keyword</label>
                    <input type="text" id="q" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Gas, food, shirt...">
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

        <div>
            <?php if (empty($products)): ?>
                <div class="card">
                    <h3>No Products Found</h3>
                    <p class="muted" style="margin-top: 0.4rem;">Try clearing your keyword or switching category filters.</p>
                </div>
            <?php else: ?>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $pid = (int) $product['id'];
                        $disc = discount_percent((float) $product['price'], $product['original_price'] !== null ? (float) $product['original_price'] : null);
                        $isSaved = in_array($pid, $userWishlist, true);
                        $ratingAvg = (float) ($product['rating_avg'] ?? 0.0);
                        $ratingCnt = (int) ($product['rating_count'] ?? 0);
                        ?>
                        <article class="product-card">
                            <div style="position: relative;">
                                <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>" class="product-thumb">
                                    <img src="<?= e(url($product['image_path'])) ?>" alt="<?= e($product['name']) ?>">
                                    <?php if ($disc > 0): ?>
                                        <span class="discount-badge"><?= $disc ?>% OFF</span>
                                    <?php endif; ?>
                                    <span class="condition-badge"><?= e($product['condition_type']) ?></span>
                                </a>
                                <button type="button"
                                        class="btn-wishlist-toggle <?= $isSaved ? 'active' : '' ?>"
                                        data-product-id="<?= $pid ?>"
                                        title="<?= $isSaved ? 'Remove from Wishlist' : 'Add to Wishlist' ?>"
                                        aria-label="Wishlist toggle"
                                        style="position: absolute; top: 10px; right: 10px;">
                                    <?= icon('heart', 'lucide-xs', $isSaved ? 'rose' : '') ?>
                                </button>
                            </div>
                            <div class="product-body">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span class="product-category"><?= e($product['category_name']) ?></span>
                                    <?php if ($ratingCnt > 0): ?>
                                        <span class="small" style="color: #b45309; font-weight: 700;">
                                            <?= icon('star', 'lucide-xs', 'amber') ?> <?= number_format($ratingAvg, 1) ?> (<?= $ratingCnt ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="product-title">
                                    <a href="<?= e(url('pages/public/product_details.php?id=' . $pid)) ?>"><?= e($product['name']) ?></a>
                                </h3>
                                <p class="product-seller">
                                    <?= e($product['seller_name']) ?> &bull;
                                    <?php if ((int) $product['stock_qty'] > 0): ?>
                                        <span><?= (int) $product['stock_qty'] ?> in stock</span>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-weight:700;">Out of stock</span>
                                    <?php endif; ?>
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
                                    <?php if ((int) $product['stock_qty'] > 0): ?>
                                        <form action="<?= e(url('cart_actions.php')) ?>" method="POST" style="flex:1;">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
