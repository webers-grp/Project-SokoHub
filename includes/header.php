<?php
/**
 * SokoHub Shared Header & Responsive Navigation Bar
 */
require_once __DIR__ . '/notifications.php';
if (!isset($pageTitle)) {
    $pageTitle = 'SokoHub | Local Digital Marketplace';
}
$currentScript = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$authUser = current_user();
$navItems = get_nav_items($authUser);
$cartItemsCount = cart_count();
$flash = get_flash();
$savedTheme = $_COOKIE['sokohub_theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($savedTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
<<<<<<< HEAD
    <meta name="base-url" content="<?= e(url('')) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
=======
    <script>
        (function() {
            try {
                var stored = localStorage.getItem('sokohub_theme');
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var theme = stored || (document.cookie.match(/sokohub_theme=([^;]+)/) || [])[1] || (prefersDark ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch(e) {}
        })();
    </script>
>>>>>>> feature/seller-kyc-admin
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . filemtime(__DIR__ . '/../assets/css/style.css'))) ?>">
</head>
<body>
<header class="site-header">
    <nav class="navbar" aria-label="Primary Navigation">
        <div class="nav-inner">
            <a href="<?= e(url('index.php')) ?>" class="brand" aria-label="SokoHub Home">
                <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo" class="brand-logo">
                <span class="brand-text"><span class="soko">Soko</span><span class="hub">Hub</span></span>
            </a>

            <!-- Group 2: Instant Live Autocomplete Search Bar -->
            <div class="header-search-wrap" id="headerSearchWrap">
                <div class="header-search-box">
                    <?= icon('search', 'lucide-sm', 'blue') ?>
                    <input type="text"
                           id="navSearchInput"
                           class="header-search-input"
                           placeholder="Search products, gas, food, clothes..."
                           autocomplete="off"
                           aria-label="Search marketplace products"
                           aria-expanded="false"
                           aria-controls="navSearchDropdown">
                    <button type="button" class="search-clear-btn" id="navSearchClear" style="display:none;" aria-label="Clear search">&times;</button>
                    <span class="search-spinner" id="navSearchSpinner" style="display:none;"></span>
                </div>
                <div class="search-dropdown" id="navSearchDropdown" role="listbox" style="display: none;"></div>
            </div>

            <button class="nav-toggle" type="button" id="navToggle" aria-expanded="false" aria-controls="navMenu" aria-label="Toggle navigation menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <div class="nav-menu" id="navMenu">
                <ul class="nav-links">
                    <?php foreach ($navItems as $item): ?>
                        <?php $isActive = in_array($currentScript, $item['match'], true); ?>
                        <li>
                            <a href="<?= e(url($item['href'])) ?>" class="<?= $isActive ? 'active' : '' ?>">
                                <?php if (!empty($item['icon'])): ?>
                                    <?= icon($item['icon'], 'lucide-sm') ?>
                                <?php endif; ?>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="nav-actions">
                    <button type="button" class="theme-toggle-btn" id="themeToggle" aria-label="Toggle Dark/Light Mode" title="Toggle Dark/Light Mode">
                        <span class="theme-icon-light"><?= icon('sun', 'lucide-sm', 'amber') ?></span>
                        <span class="theme-icon-dark"><?= icon('moon', 'lucide-sm', 'cyan') ?></span>
                    </button>

                    <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="cart-pill" title="Shopping Cart">
                        <?= icon('shopping-cart', 'lucide-sm') ?>
                        <span>Cart</span>
                        <span class="cart-badge" id="cartBadge"><?= (int) $cartItemsCount ?></span>
                    </a>

                    <?php if ($authUser !== null): 
                        $notifUnread = Notifications::getUnreadCount((int) $authUser['id']);
                    ?>
                        <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="cart-pill" title="Marketplace Alerts &amp; Restocks" style="padding: 0.35rem 0.65rem;">
                            <?= icon('bell', 'lucide-sm', 'amber') ?>
                            <?php if ($notifUnread > 0): ?>
                                <span class="cart-badge" style="background: #ef4444;"><?= $notifUnread ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($authUser !== null): ?>
                        <div class="user-pill">
<<<<<<< HEAD
                            <?= icon('user', 'lucide-sm') ?>
=======
                            <?= icon('user', 'lucide-xs') ?>
>>>>>>> feature/seller-kyc-admin
                            <span class="user-name"><?= e(explode(' ', $authUser['full_name'])[0]) ?></span>
                            <span class="role-tag"><?= e(ucfirst($authUser['role'])) ?></span>
                        </div>
                        <a href="<?= e(url('pages/auth/logout.php')) ?>" class="btn btn-outline-light btn-sm">
<<<<<<< HEAD
                            <?= icon('log-out', 'lucide-sm') ?>
=======
                            <?= icon('log-out', 'lucide-xs') ?>
>>>>>>> feature/seller-kyc-admin
                            <span>Logout</span>
                        </a>
                    <?php else: ?>
                        <a href="<?= e(url('pages/auth/register.php')) ?>" class="btn btn-amber btn-sm">
                            <?= icon('user-plus', 'lucide-sm') ?>
                            <span>Register</span>
                        </a>
                        <a href="<?= e(url('pages/auth/login.php')) ?>" class="btn btn-light btn-sm">
                            <?= icon('log-in', 'lucide-sm') ?>
                            <span>Sign In</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
</header>

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
