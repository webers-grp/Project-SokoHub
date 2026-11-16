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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
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
                            <?= icon('user', 'lucide-xs') ?>
                            <span class="user-name"><?= e(explode(' ', $authUser['full_name'])[0]) ?></span>
                            <span class="role-tag"><?= e(ucfirst($authUser['role'])) ?></span>
                        </div>
                        <a href="<?= e(url('pages/auth/logout.php')) ?>" class="btn btn-outline-light btn-sm">
                            <?= icon('log-out', 'lucide-xs') ?>
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
    <div class="container flash-wrap">
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
            <span><?= e($flash['message']) ?></span>
            <button type="button" class="alert-close" aria-label="Dismiss">&times;</button>
        </div>
    </div>
<?php endif; ?>
