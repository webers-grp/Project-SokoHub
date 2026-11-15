<?php
/**
 * SokoHub About Page (Upgraded from Main/About.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

$pdo = get_db_connection();
$totalVendors = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller' AND status = 'active'")->fetchColumn();
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$totalDeliveries = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE delivery_status = 'delivered'")->fetchColumn();

$pageTitle = 'SokoHub | About Us';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="card">
        <div class="split-grid">
            <div>
                <span class="badge badge-primary">Our Mission</span>
                <h1 style="margin-top: 0.6rem; color: var(--green-dark);">Empowering Local Commerce Across Kirinyaga &amp; Beyond</h1>
                <p style="margin-top: 0.9rem;">
                    SokoHub is a modern online marketplace created to uplift small and medium-sized local businesses. We provide a simple, accessible, and reliable platform where vendors showcase their products, reach new customers, and grow beyond the physical boundaries of traditional retail stalls.
                </p>
                <p style="margin-top: 0.85rem;">
                    Our mission is to bridge the gap between neighborhood shops and digital commerce, making it easy for entrepreneurs to sell online and coordinate fast local delivery — even with minimal technical background.
                </p>
                <ul style="margin: 1rem 0 0 1.25rem; line-height: 1.9;">
                    <li>Supporting local businesses with practical digital inventory tools.</li>
                    <li>Creating equal opportunities in Kenya's growing online economy.</li>
                    <li>Building transparent trust between sellers, buyers, and riders.</li>
                    <li>Delivering goods reliably from Kutus, Kerugoya, Sagana, and surrounding towns.</li>
                </ul>
            </div>
            <div>
                <img src="<?= e(url('assets/img/about-stall.jpg')) ?>" alt="SokoHub Local Vendors" class="rounded-img">
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">Registered Local Vendors</span>
            <strong class="stat-value"><?= $totalVendors ?>+</strong>
        </div>
        <div class="stat-card">
            <span class="stat-label">Active Products Listed</span>
            <strong class="stat-value"><?= $totalProducts ?></strong>
        </div>
        <div class="stat-card">
            <span class="stat-label">Completed Deliveries</span>
            <strong class="stat-value"><?= $totalDeliveries ?></strong>
        </div>
        <div class="stat-card">
            <span class="stat-label">Core Ecosystem Roles</span>
            <strong class="stat-value">Buyer, Seller, Rider</strong>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
