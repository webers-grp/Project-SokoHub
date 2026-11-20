<?php
/**
 * SokoHub Role Selection Registration Hub (Kilimall-style redesign)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$pageTitle = 'SokoHub | Choose Your Account Role';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.auth-page-wrap {
    min-height: calc(100vh - 160px);
    background: #F5F5F5;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}
.register-role-box {
    max-width: 860px;
    width: 100%;
    text-align: center;
}
.register-role-box .reg-head-badge {
    display: inline-block;
    background: #E8192C;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.25rem 0.9rem;
    border-radius: 3px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 0.75rem;
}
.register-role-box h1 {
    font-size: 1.9rem;
    font-weight: 800;
    color: #333;
    margin: 0 0 0.5rem;
}
.register-role-box .reg-sub {
    font-size: 0.9rem;
    color: #666;
    margin: 0 0 2.5rem;
}
.role-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.role-card-kili {
    background: #fff;
    border: 2px solid #E0E0E0;
    border-radius: 4px;
    padding: 2rem 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
    text-decoration: none;
}
.role-card-kili:hover {
    border-color: #E8192C;
    box-shadow: 0 4px 16px rgba(232,25,44,0.1);
}
.role-card-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin-bottom: 1rem;
}
.role-card-icon.buyer-icon  { background: #FFF0F0; color: #E8192C; }
.role-card-icon.seller-icon { background: #FFF4E0; color: #FF6600; }
.role-card-icon.rider-icon  { background: #E8F5E9; color: #2E7D32; }

.role-card-kili h2 {
    font-size: 1.1rem;
    font-weight: 800;
    color: #333;
    margin: 0 0 0.5rem;
}
.role-card-kili p {
    font-size: 0.84rem;
    color: #666;
    line-height: 1.5;
    margin: 0 0 1.5rem;
    flex-grow: 1;
}
.role-cta-btn {
    display: inline-block;
    padding: 0.6rem 1.4rem;
    border-radius: 3px;
    font-size: 0.88rem;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.2s;
}
.role-cta-btn.red  { background: #E8192C; color: #fff; }
.role-cta-btn.red:hover { background: #C0122A; }
.role-cta-btn.orange { background: #FF6600; color: #fff; }
.role-cta-btn.orange:hover { background: #e05500; }
.role-cta-btn.green  { background: #2E7D32; color: #fff; }
.role-cta-btn.green:hover { background: #1b5e20; }

.auth-links {
    font-size: 0.88rem;
    color: #666;
    text-align: center;
}
.auth-links a { color: #E8192C; font-weight: 700; text-decoration: none; }

@media (max-width: 680px) {
    .role-cards-grid { grid-template-columns: 1fr; }
}
</style>

<div class="auth-page-wrap">
    <div class="register-role-box">
        <div class="reg-head-badge">Join SokoHub</div>
        <h1>How Would You Like to Use SokoHub?</h1>
        <p class="reg-sub">Choose the role that fits your goal. Each account unlocks tailored tools in your SokoHub dashboard.</p>

        <div class="role-cards-grid">
            <!-- Buyer Card -->
            <div class="role-card-kili">
                <div class="role-card-icon buyer-icon"><?= icon('shopping-bag', 'lucide-lg') ?></div>
                <h2>Buyer / Customer</h2>
                <p>Browse verified local listings, compare prices, checkout securely, and track your deliveries in real time.</p>
                <a href="<?= e(url('pages/auth/register_buyer.php')) ?>" class="role-cta-btn red">Register as Buyer</a>
            </div>

            <!-- Seller Card -->
            <div class="role-card-kili">
                <div class="role-card-icon seller-icon"><?= icon('store', 'lucide-lg') ?></div>
                <h2>Seller / Vendor</h2>
                <p>List products with stock tracking, manage customer orders, and analyze sales &amp; inventory reports.</p>
                <a href="<?= e(url('pages/auth/register_seller.php')) ?>" class="role-cta-btn orange">Register as Seller</a>
            </div>

            <!-- Delivery Card -->
            <div class="role-card-kili">
                <div class="role-card-icon rider-icon"><?= icon('bike', 'lucide-lg') ?></div>
                <h2>Delivery Personnel</h2>
                <p>Claim local delivery requests across Kutus and Kerugoya, update transit status, and track fulfillment.</p>
                <a href="<?= e(url('pages/auth/register_delivery.php')) ?>" class="role-cta-btn green">Join Delivery Team</a>
            </div>
        </div>

        <div class="auth-links">
            Already have an account? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In Here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
