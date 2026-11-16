<?php
/**
 * SokoHub Shared Footer
 */
$lastVisitCookie = $_COOKIE['sokohub_last_visit'] ?? null;
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
                <li><a href="<?= e(url('index.php')) ?>"><?= icon('home', 'lucide-sm') ?> <span>Home</span></a></li>
                <li><a href="<?= e(url('pages/public/products.php')) ?>"><?= icon('shopping-bag', 'lucide-sm') ?> <span>Browse Products</span></a></li>
                <li><a href="<?= e(url('pages/buyer/cart.php')) ?>"><?= icon('shopping-cart', 'lucide-sm') ?> <span>Shopping Cart</span></a></li>
                <li><a href="<?= e(url('pages/public/about.php')) ?>"><?= icon('info', 'lucide-sm') ?> <span>About SokoHub</span></a></li>
                <li><a href="<?= e(url('pages/public/contact.php')) ?>"><?= icon('headphones', 'lucide-sm') ?> <span>Contact Support</span></a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Get Started</h4>
            <ul>
                <li><a href="<?= e(url('pages/auth/register_buyer.php')) ?>"><?= icon('user-check', 'lucide-sm') ?> <span>Register as Buyer</span></a></li>
                <li><a href="<?= e(url('pages/auth/register_seller.php')) ?>"><?= icon('store', 'lucide-sm') ?> <span>Become a Seller</span></a></li>
                <li><a href="<?= e(url('pages/auth/register_delivery.php')) ?>"><?= icon('truck', 'lucide-sm') ?> <span>Join Delivery Team</span></a></li>
                <li><a href="<?= e(url('pages/auth/login.php')) ?>"><?= icon('log-in', 'lucide-sm') ?> <span>Sign In</span></a></li>
                <li><a href="<?= e(url('health.php')) ?>"><?= icon('activity', 'lucide-sm') ?> <span>System Health Check</span></a></li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>&copy; <?= date('Y') ?> SokoHub Marketplace &mdash; Kirinyaga University SPI 2301 Semester Project.</p>
            <p><?= icon('map-pin', 'lucide-sm') ?> 123 Market Street, Kutus, Kirinyaga, Kenya &bull; <?= icon('mail', 'lucide-sm') ?> support@sokohub.co.ke</p>
        </div>
    </div>
</footer>

<script src="<?= e(url('assets/js/lucide.min.js')) ?>"></script>
<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
