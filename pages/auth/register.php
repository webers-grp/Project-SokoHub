<?php
/**
 * SokoHub Role Selection Registration Hub (Upgraded from Main/Register.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$pageTitle = 'SokoHub | Choose Your Account Role';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div style="text-align: center; max-width: 680px; margin: 0 auto;">
        <span class="badge badge-warning">Join SokoHub</span>
        <h1 style="margin-top: 0.6rem; color: var(--green-dark);">How Would You Like to Use SokoHub?</h1>
        <p class="muted" style="margin-top: 0.5rem;">Choose the role that fits your goal. Each account unlocks tailored tools in your SokoHub dashboard.</p>
    </div>

    <div class="role-grid">
        <div class="role-card">
            <div class="role-icon">B</div>
            <h2>Buyer / Customer</h2>
            <p class="muted">Browse verified local listings, compare prices, checkout securely, and track your deliveries in real time.</p>
            <a href="<?= e(url('pages/auth/register_buyer.php')) ?>" class="btn btn-primary" style="margin-top: auto;">Register as Buyer</a>
        </div>

        <div class="role-card">
            <div class="role-icon">S</div>
            <h2>Seller / Vendor</h2>
            <p class="muted">List products with stock tracking, manage customer orders, and analyze sales &amp; inventory reports.</p>
            <a href="<?= e(url('pages/auth/register_seller.php')) ?>" class="btn btn-amber" style="margin-top: auto;">Register as Seller</a>
        </div>

        <div class="role-card">
            <div class="role-icon">D</div>
            <h2>Delivery Personnel</h2>
            <p class="muted">Claim local delivery requests across Kutus and Kerugoya, update transit status, and track fulfillment.</p>
            <a href="<?= e(url('pages/auth/register_delivery.php')) ?>" class="btn btn-primary" style="margin-top: auto;">Join Delivery Team</a>
        </div>
    </div>

    <p style="text-align: center; margin-top: 2rem;" class="muted">
        Already have an account? <a href="<?= e(url('pages/auth/login.php')) ?>"><strong>Sign In Here</strong></a>
    </p>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
