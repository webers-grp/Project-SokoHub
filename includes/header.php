<?php
/**
 * SokoHub Shared Header & Responsive Navigation Bar
 */
if (!isset($pageTitle)) {
    $pageTitle = 'SokoHub | Local Digital Marketplace';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
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
    <nav class="navbar" aria-label="Primary Navigation">
        <div class="nav-inner">
            <a href="<?= e(url('index.php')) ?>" class="brand" aria-label="SokoHub Home">
                <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo" class="brand-logo">
                <span class="brand-text"><span class="soko">Soko</span><span class="hub">Hub</span></span>
            </a>

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
                    <!-- Wishlist Pill -->
                    <?php if ($authUser !== null): ?>
                        <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="cart-pill" title="Saved Wishlist" style="background: rgba(255,255,255,0.12);">
                            <?= icon('heart', 'lucide-sm', 'rose') ?>
                            <span class="desktop-only">Wishlist</span>
                            <?php if ($wishlistCount > 0): ?>
                                <span class="cart-badge" style="background: #e11d48;"><?= $wishlistCount ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Notifications Pill -->
                        <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="cart-pill" title="Notifications" style="background: rgba(255,255,255,0.12); position: relative;">
                            <?= icon('bell', 'lucide-sm', 'amber') ?>
                            <span class="desktop-only">Alerts</span>
                            <?php if ($unreadNotificationsCount > 0): ?>
                                <span class="cart-badge" style="background: #ef4444;"><?= $unreadNotificationsCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <!-- Cart Pill -->
                    <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="cart-pill" title="Shopping Cart">
                        <?= icon('shopping-cart', 'lucide-sm') ?>
                        <span>Cart</span>
                        <span class="cart-badge" id="cartBadge"><?= (int) $cartItemsCount ?></span>
                    </a>

                    <!-- Dark/Light Mode Switcher -->
                    <button type="button" id="themeToggleBtn" class="btn btn-outline-light btn-sm" style="padding: 0.35rem 0.6rem;" title="Toggle Dark/Light Mode">
                        <span id="themeToggleIcon">🌙</span>
                    </button>

                    <?php if ($authUser !== null): ?>
                        <a href="<?= e(url('pages/auth/profile.php')) ?>" class="user-pill" style="text-decoration: none; color: inherit;" title="Manage Account Profile">
                            <?= icon('user', 'lucide-sm') ?>
                            <span class="user-name"><?= e(explode(' ', $authUser['full_name'])[0]) ?></span>
                            <span class="role-tag"><?= e(ucfirst($authUser['role'])) ?></span>
                        </a>
                        <a href="<?= e(url('pages/auth/logout.php')) ?>" class="btn btn-outline-light btn-sm" title="Sign Out">
                            <?= icon('log-out', 'lucide-sm') ?>
                            <span class="desktop-only">Logout</span>
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
