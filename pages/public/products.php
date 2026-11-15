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
            <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">+ Manage My Products</a>
        <?php endif; ?>
    </div>

    <div class="shop-layout">
        <aside class="filter-panel">
            <h3>Filter &amp; Sort</h3>
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

                <button type="submit" class="btn btn-primary btn-block">Apply Filters</button>
                <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-outline btn-block" style="margin-top: 0.5rem;">Reset</a>
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
                                <p class="product-seller">
                                    <?= e($product['seller_name']) ?> &bull;
                                    <?php if ((int) $product['stock_qty'] > 0): ?>
                                        <span><?= (int) $product['stock_qty'] ?> in stock</span>
                                    <?php else: ?>
                                        <span style="color: var(--danger); font-weight:700;">Out of stock</span>
                                    <?php endif; ?>
                                </p>
                                <div class="product-price-row">
                                    <span class="sale-price"><?= format_ksh($product['price']) ?></span>
                                    <?php if ($product['original_price'] !== null && (float) $product['original_price'] > (float) $product['price']): ?>
                                        <span class="original-price"><?= format_ksh($product['original_price']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="product-actions">
                                    <a href="<?= e(url('pages/public/product_details.php?id=' . (int) $product['id'])) ?>" class="btn btn-outline btn-sm" style="flex:1;">View</a>
                                    <?php if ((int) $product['stock_qty'] > 0): ?>
                                        <form action="<?= e(url('pages/buyer/cart.php')) ?>" method="POST" style="flex:1;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="add">
                                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                            <input type="hidden" name="quantity" value="1">
                                            <button type="submit" class="btn btn-primary btn-sm btn-block">Add</button>
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
