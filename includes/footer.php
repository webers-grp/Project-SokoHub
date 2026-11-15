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
                <li><a href="<?= e(url('index.php')) ?>">Home</a></li>
                <li><a href="<?= e(url('pages/public/products.php')) ?>">Browse Products</a></li>
                <li><a href="<?= e(url('pages/buyer/cart.php')) ?>">Shopping Cart</a></li>
                <li><a href="<?= e(url('pages/public/about.php')) ?>">About SokoHub</a></li>
                <li><a href="<?= e(url('pages/public/contact.php')) ?>">Contact Support</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Get Started</h4>
            <ul>
                <li><a href="<?= e(url('pages/auth/register_buyer.php')) ?>">Register as Buyer</a></li>
                <li><a href="<?= e(url('pages/auth/register_seller.php')) ?>">Become a Seller</a></li>
                <li><a href="<?= e(url('pages/auth/register_delivery.php')) ?>">Join Delivery Team</a></li>
                <li><a href="<?= e(url('pages/auth/login.php')) ?>">Sign In</a></li>
                <li><a href="<?= e(url('health.php')) ?>">System Health Check</a></li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>&copy; <?= date('Y') ?> SokoHub Marketplace &mdash; Kirinyaga University SPI 2301 Semester Project.</p>
            <p>123 Market Street, Kutus, Kirinyaga, Kenya | support@sokohub.co.ke</p>
        </div>
    </div>
</footer>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
