<?php
/**
 * SokoHub Shared Footer & Sticky Mobile Bottom Navigation
 */
$lastVisitCookie = $_COOKIE['sokohub_last_visit'] ?? null;
$footerUser = current_user();
$footerCartCount = cart_count();
?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col">
            <div class="footer-brand">
                <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo" class="brand-logo">
                <h3><span class="soko">Soko</span><span class="hub">Hub</span></h3>
            </div>
            <p>Connecting local sellers, buyers, and delivery riders across Kirinyaga and beyond with simple, fast, and reliable digital commerce.</p>
            <?php if ($lastVisitCookie): ?>
                <p class="cookie-note">Last visit tracked via cookie: <?= e($lastVisitCookie) ?></p>
            <?php endif; ?>
        </div>

        <div class="footer-col">
            <h4>Marketplace</h4>
            <ul>
                <li><a href="<?= e(url('index.php')) ?>"><?= icon('home', 'lucide-xs', 'amber') ?> <span>Home</span></a></li>
                <li><a href="<?= e(url('pages/public/products.php')) ?>"><?= icon('shopping-bag', 'lucide-xs', 'emerald') ?> <span>Browse Products</span></a></li>
                <li><a href="<?= e(url('pages/buyer/cart.php')) ?>"><?= icon('shopping-cart', 'lucide-xs', 'gold') ?> <span>Shopping Cart</span></a></li>
                <li><a href="<?= e(url('pages/public/about.php')) ?>"><?= icon('info', 'lucide-xs', 'sky') ?> <span>About SokoHub</span></a></li>
                <li><a href="<?= e(url('pages/public/contact.php')) ?>"><?= icon('headphones', 'lucide-xs', 'pink') ?> <span>Contact Support</span></a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Get Started</h4>
            <ul>
                <li><a href="<?= e(url('pages/auth/register_buyer.php')) ?>"><?= icon('user-check', 'lucide-xs', 'blue') ?> <span>Register as Buyer</span></a></li>
                <li><a href="<?= e(url('pages/auth/register_seller.php')) ?>"><?= icon('store', 'lucide-xs', 'amber') ?> <span>Become a Seller</span></a></li>
                <li><a href="<?= e(url('pages/auth/register_delivery.php')) ?>"><?= icon('truck', 'lucide-xs', 'emerald') ?> <span>Join Delivery Team</span></a></li>
                <li><a href="<?= e(url('pages/auth/login.php')) ?>"><?= icon('log-in', 'lucide-xs', 'purple') ?> <span>Sign In</span></a></li>
                <li><a href="<?= e(url('health.php')) ?>"><?= icon('activity', 'lucide-xs', 'teal') ?> <span>System Health Check</span></a></li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>&copy; <?= date('Y') ?> SokoHub Marketplace &mdash; Kirinyaga University SPI 2301 Semester Project.</p>
            <p><?= icon('map-pin', 'lucide-xs', 'rose') ?> 123 Market Street, Kutus, Kirinyaga, Kenya &bull; <?= icon('mail', 'lucide-xs', 'sky') ?> support@sokohub.co.ke</p>
        </div>
    </div>
</footer>

<!-- Sticky Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav" aria-label="Mobile Navigation">
    <a href="<?= e(url('index.php')) ?>" class="mobile-nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
        <span class="mobile-nav-icon"><?= icon('home', 'lucide-sm') ?></span>
        <span class="mobile-nav-label">Home</span>
    </a>
    <a href="<?= e(url('pages/public/products.php')) ?>" class="mobile-nav-item <?= in_array(basename($_SERVER['PHP_SELF']), ['products.php', 'product_details.php']) ? 'active' : '' ?>">
        <span class="mobile-nav-icon"><?= icon('shopping-bag', 'lucide-sm') ?></span>
        <span class="mobile-nav-label">Shop</span>
    </a>
    <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="mobile-nav-item <?= basename($_SERVER['PHP_SELF']) === 'cart.php' ? 'active' : '' ?>">
        <span class="mobile-nav-icon" style="position: relative;">
            <?= icon('shopping-cart', 'lucide-sm') ?>
            <?php if ($footerCartCount > 0): ?>
                <span class="mobile-cart-badge"><?= $footerCartCount ?></span>
            <?php endif; ?>
        </span>
        <span class="mobile-nav-label">Cart</span>
    </a>
    <a href="<?= e(url('pages/buyer/wishlist.php')) ?>" class="mobile-nav-item <?= basename($_SERVER['PHP_SELF']) === 'wishlist.php' ? 'active' : '' ?>">
        <span class="mobile-nav-icon"><?= icon('heart', 'lucide-sm') ?></span>
        <span class="mobile-nav-label">Wishlist</span>
    </a>
    <a href="<?= e(url($footerUser !== null ? 'pages/auth/profile.php' : 'pages/auth/login.php')) ?>" class="mobile-nav-item <?= in_array(basename($_SERVER['PHP_SELF']), ['profile.php', 'login.php', 'dashboard.php']) ? 'active' : '' ?>">
        <span class="mobile-nav-icon"><?= icon('user', 'lucide-sm') ?></span>
        <span class="mobile-nav-label"><?= $footerUser !== null ? 'Account' : 'Login' ?></span>
    </a>
</nav>

<script src="<?= e(url('assets/js/lucide.min.js?v=' . filemtime(__DIR__ . '/../assets/js/lucide.min.js'))) ?>"></script>
<script>
    function renderAppIcons() {
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    }
    renderAppIcons();

    // Theme Switcher Logic
    (function() {
        var btn = document.getElementById('themeToggleBtn');
        var iconWrap = document.getElementById('themeToggleIcon');
        if (!btn || !iconWrap) return;

        function updateThemeIcon() {
            var isDark = document.documentElement.classList.contains('dark-mode');
            iconWrap.innerHTML = isDark
                ? '<i data-lucide="sun" class="lucide lucide-sm" aria-hidden="true"></i>'
                : '<i data-lucide="moon" class="lucide lucide-sm" aria-hidden="true"></i>';
            renderAppIcons();
        }

        updateThemeIcon();

        btn.addEventListener('click', function() {
            document.documentElement.classList.toggle('dark-mode');
            var isDark = document.documentElement.classList.contains('dark-mode');
            localStorage.setItem('sokohub_theme', isDark ? 'dark' : 'light');
            updateThemeIcon();
        });
    })();
</script>
<script src="<?= e(url('assets/js/app.js?v=' . filemtime(__DIR__ . '/../assets/js/app.js'))) ?>"></script>
</body>
</html>
