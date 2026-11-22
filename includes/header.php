<?php
/**
 * SokoHub Shared Header — Kilimall Kenya Marketplace Edition
 * Perfectly aligned 3-tier header layout:
 * Tier 1: Top Utility Bar (Welcome, Hotline, Free Delivery, Help Center, Seller Portal)
 * Tier 2: Main Brand Bar (Kilimall-style Brand Logo, Wide Centered Search with working Live Autocomplete,
 *         and cleanly aligned user action cluster: Account dropdown, Wishlist, Alerts, Cart, Dark Mode)
 * Tier 3: Category Nav Bar (Kilimall Orange strip with All Categories drawer, flash deals, and quick links)
 */

require_once __DIR__ . '/security.php';
Security::sendHeaders();

if (!isset($pageTitle)) {
    $pageTitle = 'SokoHub | Kenya\'s Online Shopping Marketplace';
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$authUser = current_user();
$navItems = get_nav_items($authUser);
$cartItemsCount = cart_count();
$flash = get_flash();

$unreadNotificationsCount = 0;
$wishlistCount = 0;
if ($authUser !== null) {
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/wishlist.php';
    $unreadNotificationsCount = Notifications::getUnreadCount((int) $authUser['id']);
    $wishlistCount = Wishlist::count((int) $authUser['id']);
}

// Fetch categories from database for navigation and drawer
$headerCategories = [];
try {
    $headerPdo = get_db_connection();
    $catStmt = $headerPdo->query('SELECT id, name, slug FROM categories ORDER BY id ASC');
    $headerCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $headerCategories = [
        ['id' => 1, 'name' => 'Clothes & Fashion', 'slug' => 'clothes'],
        ['id' => 2, 'name' => 'Cooking Gas', 'slug' => 'gas'],
        ['id' => 3, 'name' => 'Farm Fresh & Food', 'slug' => 'food'],
        ['id' => 4, 'name' => 'Electronics', 'slug' => 'electronics'],
        ['id' => 5, 'name' => 'Home & Living', 'slug' => 'others'],
    ];
}

$categoryIconMap = [
    'gas'         => 'flame',
    'clothes'     => 'shirt',
    'food'        => 'apple',
    'electronics' => 'smartphone',
    'others'      => 'home',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . filemtime(__DIR__ . '/../assets/css/style.css'))) ?>">
    <script>
        // Apply saved theme immediately before render
        (function() {
            var theme = localStorage.getItem('sokohub_theme');
            if (theme === 'dark' || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
</head>
<body>

<header class="site-header">

    <!-- ======================================================
         TIER 1 — Utility Top Bar (Kilimall Kenya Style)
         ====================================================== -->
    <div class="header-utility-bar">
        <div class="header-utility-inner">
            <div class="utility-left">
                <span class="utility-badge" style="background: var(--brand-red); color: white; padding: 2px 6px; border-radius: 3px; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.5px;">KENYA</span>
                <span class="utility-text"><strong>Welcome to SokoHub!</strong> Free delivery on orders above KES 3,000 across Kirinyaga</span>
                <span class="utility-separator">|</span>
                <span class="utility-hotline"><?= icon('phone', 'lucide-xs') ?> Helpline: <strong>0700 100 001</strong></span>
            </div>
            <div class="utility-right">
                <?php if ($authUser !== null): ?>
                    <span class="utility-user-greeting">Hi, <strong><?= e(explode(' ', $authUser['full_name'])[0]) ?></strong></span>
                    <a href="<?= e(url('pages/buyer/orders.php')) ?>">My Orders</a>
                    <?php if (in_array($authUser['role'], ['seller', 'admin'], true)): ?>
                        <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>">Merchant Center</a>
                    <?php else: ?>
                        <a href="<?= e(url('pages/auth/register_seller.php')) ?>">Sell on SokoHub</a>
                    <?php endif; ?>
                    <a href="<?= e(url('pages/public/contact.php')) ?>">Help Center</a>
                    <a href="<?= e(url('pages/auth/logout.php')) ?>" class="utility-logout-link">Sign Out</a>
                <?php else: ?>
                    <span class="utility-guest-prompt">
                        Welcome! <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In</a> or <a href="<?= e(url('pages/auth/register.php')) ?>">Register</a>
                    </span>
                    <a href="<?= e(url('pages/auth/register_seller.php')) ?>">Sell on SokoHub</a>
                    <a href="<?= e(url('pages/public/contact.php')) ?>">Help Center</a>
                    <a href="<?= e(url('pages/public/about.php')) ?>">About Us</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ======================================================
         TIER 2 — Main Brand Bar (Well Aligned Logo + Search + Actions)
         ====================================================== -->
    <div class="header-brand-bar">
        <div class="header-brand-inner">

            <!-- Brand Logo -->
            <a href="<?= e(url('index.php')) ?>" class="brand" aria-label="SokoHub Home">
                <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo" class="brand-logo">
                <div class="brand-text-wrap">
                    <span class="brand-text"><span class="soko">Soko</span><span class="hub">Hub</span></span>
                    <span class="brand-subtext">ONLINE SHOPPING KENYA</span>
                </div>
            </a>

            <!-- Prominent Center Search Bar -->
            <div class="header-search-wrap">
                <form class="header-search-form" action="<?= e(url('pages/public/products.php')) ?>" method="GET" role="search" autocomplete="off">
                    <input
                        type="search"
                        name="q"
                        id="main-search-input"
                        class="header-search-input"
                        placeholder="Search products, brands and categories in Kenya..."
                        value="<?= e($_GET['q'] ?? $_GET['search'] ?? '') ?>"
                        autocomplete="off"
                        aria-label="Search products"
                    >
                    <button type="submit" class="header-search-btn" aria-label="Search">
                        <?= icon('search', 'lucide-sm') ?>
                        <span>SEARCH</span>
                    </button>
                </form>
                <!-- Search Suggest Dropdown -->
                <div id="header-search-dropdown" role="listbox" aria-label="Search suggestions"></div>
            </div>

            <!-- Right Actions Cluster (Meticulously Aligned) -->
            <div class="header-actions">

                <!-- User Account Dropdown -->
                <div class="header-account-dropdown">
                    <?php if ($authUser !== null): ?>
                        <a href="<?= e(url('pages/auth/profile.php')) ?>" class="header-action-item" title="Manage Account">
                            <span class="action-icon"><?= icon('user', 'lucide-sm') ?></span>
                            <span class="action-label"><?= e(explode(' ', $authUser['full_name'])[0]) ?> <?= icon('chevron-down', 'lucide-xs') ?></span>
                        </a>
                        <div class="header-account-menu">
                            <div class="account-menu-header">
                                <strong><?= e($authUser['full_name']) ?></strong>
                                <span class="muted small"><?= e(ucfirst($authUser['role'])) ?> Account</span>
                            </div>
                            <div class="account-menu-divider"></div>
                            <a href="<?= e(url('pages/auth/profile.php')) ?>" class="account-menu-item">
                                <?= icon('user', 'lucide-xs') ?> <span>My Profile &amp; Addresses</span>
                            </a>
                            <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="account-menu-item">
                                <?= icon('receipt', 'lucide-xs') ?> <span>My Orders &amp; Tracking</span>
                            </a>
                            <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="account-menu-item">
                                <?= icon('heart', 'lucide-xs') ?> <span>Saved Wishlist</span>
                            </a>
                            <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="account-menu-item">
                                <?= icon('bell', 'lucide-xs') ?> <span>Notifications</span>
                            </a>
                            <?php if (in_array($authUser['role'], ['seller', 'admin'], true)): ?>
                                <div class="account-menu-divider"></div>
                                <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="account-menu-item">
                                    <?= icon('layout-dashboard', 'lucide-xs') ?> <span>Seller Dashboard</span>
                                </a>
                                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="account-menu-item">
                                    <?= icon('package', 'lucide-xs') ?> <span>Manage Products</span>
                                </a>
                                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="account-menu-item">
                                    <?= icon('wallet', 'lucide-xs') ?> <span>Payouts &amp; Settlement</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($authUser['role'] === 'admin'): ?>
                                <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="account-menu-item">
                                    <?= icon('shield-check', 'lucide-xs') ?> <span>KYC Verification Queue</span>
                                </a>
                                <a href="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="account-menu-item">
                                    <?= icon('file-text', 'lucide-xs') ?> <span>System Audit Trail</span>
                                </a>
                            <?php endif; ?>
                            <div class="account-menu-divider"></div>
                            <a href="<?= e(url('pages/auth/logout.php')) ?>" class="account-menu-item account-menu-logout">
                                <?= icon('log-out', 'lucide-xs') ?> <span>Sign Out</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <a href="<?= e(url('pages/auth/login.php')) ?>" class="header-action-item" title="Sign In">
                            <span class="action-icon"><?= icon('user', 'lucide-sm') ?></span>
                            <span class="action-label">Account <?= icon('chevron-down', 'lucide-xs') ?></span>
                        </a>
                        <div class="header-account-menu">
                            <div style="padding: 0.8rem 1rem;">
                                <a href="<?= e(url('pages/auth/login.php')) ?>" class="btn btn-primary btn-block btn-sm" style="margin-bottom: 0.5rem;">
                                    <?= icon('log-in', 'lucide-xs') ?> <span>Sign In</span>
                                </a>
                                <a href="<?= e(url('pages/auth/register.php')) ?>" class="btn btn-outline btn-block btn-sm">
                                    <?= icon('user-plus', 'lucide-xs') ?> <span>Register Free</span>
                                </a>
                            </div>
                            <div class="account-menu-divider"></div>
                            <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="account-menu-item">
                                <?= icon('receipt', 'lucide-xs') ?> <span>Track Order</span>
                            </a>
                            <a href="<?= e(url('pages/auth/register_seller.php')) ?>" class="account-menu-item">
                                <?= icon('store', 'lucide-xs') ?> <span>Open a Seller Shop</span>
                            </a>
                            <a href="<?= e(url('pages/public/contact.php')) ?>" class="account-menu-item">
                                <?= icon('headphones', 'lucide-xs') ?> <span>Customer Care</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Wishlist Action -->
                <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="header-action-item" title="Saved Wishlist" aria-label="View Saved Wishlist">
                    <span class="action-icon">
                        <?= icon('heart', 'lucide-sm') ?>
                        <?php if ($wishlistCount > 0): ?>
                            <span class="header-action-badge"><?= $wishlistCount ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="action-label desktop-only">Wishlist</span>
                </a>

                <!-- Notifications Alerts Action -->
                <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="header-action-item" title="In-App Alerts" aria-label="View Alerts and Notifications">
                    <span class="action-icon">
                        <?= icon('bell', 'lucide-sm') ?>
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <span class="header-action-badge"><?= $unreadNotificationsCount ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="action-label desktop-only">Alerts</span>
                </a>

                <!-- Shopping Cart Action (The Kilimall Kenya Cart Pill) -->
                <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="header-cart-item" title="View Shopping Cart" aria-label="Shopping Cart">
                    <span class="cart-icon-wrap">
                        <?= icon('shopping-cart', 'lucide-sm') ?>
                        <span class="header-action-badge header-cart-badge" id="cartBadge"><?= (int) $cartItemsCount ?></span>
                    </span>
                    <div class="cart-label-wrap desktop-only">
                        <span class="cart-label-top">Cart</span>
                        <span class="cart-label-sub"><?= (int) $cartItemsCount ?> items</span>
                    </div>
                </a>

                <!-- Dark / Light Mode Toggle Button -->
                <button type="button" id="themeToggleBtn" title="Toggle Dark/Light Mode" aria-label="Toggle Color Theme">
                    <span id="themeToggleIcon"><?= icon('moon', 'lucide-sm') ?></span>
                </button>

            </div><!-- /.header-actions -->
        </div><!-- /.header-brand-inner -->
    </div><!-- /.header-brand-bar -->

    <!-- ======================================================
         TIER 3 — Kilimall Kenya Category Navigation Bar
         ====================================================== -->
    <nav class="header-category-bar" aria-label="Category Navigation">
        <div class="header-category-inner">

            <!-- Mobile Hamburger Button -->
            <button class="cat-nav-toggle" id="catNavToggle" type="button" aria-label="Open category menu">
                <?= icon('menu', 'lucide-xs') ?> <span>Categories</span>
            </button>

            <!-- All Categories Mega Button -->
            <button class="all-categories-btn" id="allCategoriesBtn" type="button" aria-expanded="false" aria-controls="categorySidebar">
                <span class="all-cat-icon"><?= icon('menu', 'lucide-sm') ?></span>
                <span class="all-cat-text">ALL CATEGORIES</span>
                <span class="all-cat-caret"><?= icon('chevron-down', 'lucide-xs') ?></span>
            </button>

            <!-- Primary Navigation Links -->
            <ul class="category-nav-links" id="navMenu">
                <li>
                    <a href="<?= e(url('index.php')) ?>" class="<?= $currentScript === 'index.php' ? 'active' : '' ?>">
                        <?= icon('home', 'lucide-xs') ?> <span>Home</span>
                    </a>
                </li>
                <li>
                    <a href="<?= e(url('pages/public/products.php?sort=rating')) ?>" class="flash-deals-nav-link">
                        <?= icon('zap', 'lucide-xs') ?> <span>Flash Deals</span>
                    </a>
                </li>
                <li>
                    <a href="<?= e(url('pages/public/products.php')) ?>" class="<?= $currentScript === 'products.php' && empty($_GET['category']) ? 'active' : '' ?>">
                        <?= icon('shopping-bag', 'lucide-xs') ?> <span>All Products</span>
                    </a>
                </li>
                <?php foreach (array_slice($headerCategories, 0, 4) as $hCat): ?>
                    <?php
                    $isCatActive = ($currentScript === 'products.php' && (($_GET['category'] ?? '') === $hCat['slug'] || ($_GET['category'] ?? '') === (string)$hCat['id']));
                    $catIcon = $categoryIconMap[$hCat['slug']] ?? 'package';
                    ?>
                    <li>
                        <a href="<?= e(url('pages/public/products.php?category=' . urlencode($hCat['slug']))) ?>" class="<?= $isCatActive ? 'active' : '' ?>">
                            <?= icon($catIcon, 'lucide-xs') ?> <span><?= e($hCat['name']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li>
                    <a href="<?= e(url('pages/buyer/orders.php')) ?>" class="<?= in_array($currentScript, ['orders.php', 'checkout.php'], true) ? 'active' : '' ?>">
                        <?= icon('truck', 'lucide-xs') ?> <span>Track Order</span>
                    </a>
                </li>
            </ul>

            <!-- Right Callout in Category Bar -->
            <div class="header-category-right desktop-only">
                <a href="<?= e(url(is_logged_in() ? 'pages/dashboard/manage_products.php' : 'pages/auth/register_seller.php')) ?>" class="cat-bar-sell-btn">
                    <?= icon('store', 'lucide-xs') ?> <span>Open Shop on SokoHub</span> <?= icon('arrow-right', 'lucide-xs') ?>
                </a>
            </div>

        </div>
    </nav>

</header><!-- /.site-header -->

<!-- Category Sidebar Overlay -->
<div class="category-overlay" id="categoryOverlay" aria-hidden="true"></div>

<!-- Category Sidebar Drawer -->
<aside class="category-sidebar" id="categorySidebar" role="navigation" aria-label="All Categories">
    <div class="category-sidebar-header">
        <span><?= icon('grid', 'lucide-sm') ?> All Product Categories</span>
        <button class="category-sidebar-close" id="categorySidebarClose" aria-label="Close categories"><?= icon('x', 'lucide-sm') ?></button>
    </div>
    <ul class="category-sidebar-list">
        <li>
            <a href="<?= e(url('pages/public/products.php')) ?>">
                <span class="cat-side-icon"><?= icon('store', 'lucide-sm') ?></span>
                <span>All Marketplace Products</span>
            </a>
        </li>
        <?php foreach ($headerCategories as $cat): ?>
            <?php $catIcon = $categoryIconMap[$cat['slug']] ?? 'package'; ?>
            <li>
                <a href="<?= e(url('pages/public/products.php?category=' . urlencode($cat['slug']))) ?>">
                    <span class="cat-side-icon"><?= icon($catIcon, 'lucide-sm') ?></span>
                    <span><?= e($cat['name']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
        <li class="sidebar-divider"></li>
        <li>
            <a href="<?= e(url('pages/public/products.php?sort=newest')) ?>">
                <span class="cat-side-icon"><?= icon('sparkles', 'lucide-sm') ?></span>
                <span>New Arrivals</span>
            </a>
        </li>
        <li>
            <a href="<?= e(url('pages/public/products.php?sort=price_asc')) ?>">
                <span class="cat-side-icon"><?= icon('tag', 'lucide-sm') ?></span>
                <span>Budget &amp; Clearance Deals</span>
            </a>
        </li>
    </ul>
</aside>

<?php if ($flash !== null): ?>
    <?php
    $alertIcon = match ($flash['type'] ?? 'info') {
        'success' => 'check-circle-2',
        'danger', 'error' => 'alert-circle',
        'warning' => 'alert-triangle',
        default => 'info',
    };
    ?>
    <div class="container flash-wrap">
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert" style="display: flex; align-items: center; gap: 0.6rem;">
            <?= icon($alertIcon) ?>
            <span style="flex: 1;"><?= e($flash['message']) ?></span>
            <button type="button" class="alert-close" aria-label="Dismiss">&times;</button>
        </div>
    </div>
<?php endif; ?>

<script>
(function() {
    // Category Sidebar toggle
    var allBtn = document.getElementById('allCategoriesBtn');
    var catToggle = document.getElementById('catNavToggle');
    var sidebar = document.getElementById('categorySidebar');
    var overlay = document.getElementById('categoryOverlay');
    var closeBtn = document.getElementById('categorySidebarClose');

    function openSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('open');
        overlay.classList.add('open');
        if (allBtn) allBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        if (allBtn) allBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (allBtn) allBtn.addEventListener('click', openSidebar);
    if (catToggle) catToggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

    // Live Search Autocomplete (Debounced 200ms, handling categories & suggestions properly)
    var searchInput = document.getElementById('main-search-input');
    var dropdown = document.getElementById('header-search-dropdown');
    var debounceTimer = null;

    if (searchInput && dropdown) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var q = this.value.trim();
            if (q.length < 2) {
                dropdown.classList.remove('open');
                dropdown.innerHTML = '';
                return;
            }
            debounceTimer = setTimeout(function() {
                fetch('<?= e(url('pages/api/search_suggest.php')) ?>?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var suggestions = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];
                        var categories = (data && Array.isArray(data.categories)) ? data.categories : [];

                        if (suggestions.length === 0 && categories.length === 0) {
                            dropdown.innerHTML = '<div class="search-suggest-empty">No products or categories matching "' + q.replace(/</g, '&lt;') + '"</div>';
                            dropdown.classList.add('open');
                            return;
                        }

                        var html = '';

                        // Render Matched Categories
                        if (categories.length > 0) {
                            html += '<div class="search-suggest-section-title">Matching Categories</div>';
                            categories.forEach(function(cat) {
                                var catUrl = '<?= e(url('pages/public/products.php')) ?>?category=' + encodeURIComponent(cat.slug || cat.id);
                                html += '<a href="' + catUrl + '" class="search-suggest-category-item">';
                                html += '<span class="suggest-folder-icon">' + (window.lucide ? '<i data-lucide="folder" class="lucide-xs"></i>' : '') + '</span>';
                                html += '<strong>' + (cat.name || '') + '</strong>';
                                html += '<span class="cat-tag-pill">Category</span>';
                                html += '</a>';
                            });
                        }

                        // Render Matched Products
                        if (suggestions.length > 0) {
                            html += '<div class="search-suggest-section-title">Products</div>';
                            suggestions.forEach(function(item) {
                                var itemUrl = '<?= e(url('pages/public/product_details.php')) ?>?id=' + item.id;
                                var imgUrl = item.image_path ? '<?= e(url('')) ?>/' + item.image_path.replace(/^\//, '') : '<?= e(url('assets/img/fresh-produce.jpg')) ?>';
                                html += '<a href="' + itemUrl + '" class="search-suggest-item">';
                                html += '<img src="' + imgUrl + '" alt="" loading="lazy">';
                                html += '<div class="suggest-details">';
                                html += '<div class="suggest-name">' + (item.name || '') + '</div>';
                                html += '<div class="suggest-meta">';
                                html += '<span class="suggest-price">' + (item.formatted_price || ('KES ' + item.price)) + '</span>';
                                if (item.stock_qty <= 0) {
                                    html += '<span class="badge badge-danger">Out of Stock</span>';
                                } else if (item.stock_qty <= 5) {
                                    html += '<span class="badge badge-warning">Only ' + item.stock_qty + ' left</span>';
                                }
                                html += '</div>';
                                html += '</div>';
                                html += '</a>';
                            });
                        }

                        dropdown.innerHTML = html;
                        dropdown.classList.add('open');
                    })
                    .catch(function() {
                        dropdown.classList.remove('open');
                    });
            }, 200);
        });

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== searchInput) {
                dropdown.classList.remove('open');
            }
        });

        // Close on Escape
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.classList.remove('open');
                dropdown.innerHTML = '';
            }
        });
    }
})();
</script>
