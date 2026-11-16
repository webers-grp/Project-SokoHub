<?php
/**
 * SokoHub Buyer Notification & Alert Center
 * Informs buyers about new sellers joining, products back in stock, and fresh catalog arrivals.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];

Notifications::ensureSchema();

// Handle Mark All Read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch.');
    } else {
        Notifications::markAllAsRead($userId);
        set_flash('success', 'All notifications marked as read.');
    }
    redirect('pages/buyer/notifications.php');
}

// Handle Mark Single Read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    if (verify_csrf($_POST['csrf_token'] ?? null)) {
        $notifId = (int) ($_POST['notification_id'] ?? 0);
        Notifications::markAsRead($notifId, $userId);
    }
    $redirectUrl = (string) ($_POST['redirect_to'] ?? 'pages/buyer/notifications.php');
    redirect($redirectUrl);
}

$typeFilter = trim((string) ($_GET['type'] ?? ''));
$notifications = Notifications::getBuyerNotifications($userId, $typeFilter, 40);
$unreadCount = Notifications::getUnreadCount($userId);

// Compute counts for filter pills
$allCount = count(Notifications::getBuyerNotifications($userId, null, 100));
$sellerCount = count(Notifications::getBuyerNotifications($userId, 'new_seller', 100));
$restockCount = count(Notifications::getBuyerNotifications($userId, 'restock', 100));
$stockCount = count(Notifications::getBuyerNotifications($userId, 'new_stock', 100));

$pageTitle = 'SokoHub | Marketplace Alerts & Restock Notifications';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Marketplace Announcements &amp; Restock Alerts</h1>
            <p class="muted">Real-time alerts whenever new verified merchants join or your favorite products are back in stock.</p>
        </div>

        <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <?php if ($unreadCount > 0): ?>
                <form action="<?= e(url('pages/buyer/notifications.php')) ?>" method="POST" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-outline btn-sm">
                        &check; Mark All as Read (<?= $unreadCount ?>)
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?= e(url('pages/public/products.php')) ?>" class="btn btn-primary btn-sm">Browse All Products &rarr;</a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <span class="stat-label">Unread Alerts</span>
            <strong class="stat-value" style="color: <?= $unreadCount > 0 ? '#ef4444' : '#10b981' ?>;">
                <?= $unreadCount ?>
            </strong>
            <span class="badge <?= $unreadCount > 0 ? 'badge-danger' : 'badge-success' ?>">
                <?= $unreadCount > 0 ? 'Actionable Updates' : 'All Caught Up' ?>
            </span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Restocked Items</span>
            <strong class="stat-value" style="color: #b45309;"><?= $restockCount ?></strong>
            <span class="badge badge-amber">Back in Inventory</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">New Sellers Joined</span>
            <strong class="stat-value" style="color: #6366f1;"><?= $sellerCount ?></strong>
            <span class="badge badge-primary">Verified Stores</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Fresh Arrivals</span>
            <strong class="stat-value" style="color: #10b981;"><?= $stockCount ?></strong>
            <span class="badge badge-success">New Listings</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="btn <?= $typeFilter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                All Alerts (<?= $allCount ?>)
            </a>
            <a href="<?= e(url('pages/buyer/notifications.php?type=restock')) ?>" class="btn <?= $typeFilter === 'restock' ? 'btn-amber' : 'btn-outline' ?> btn-sm">
                🔄 Back in Stock (<?= $restockCount ?>)
            </a>
            <a href="<?= e(url('pages/buyer/notifications.php?type=new_seller')) ?>" class="btn <?= $typeFilter === 'new_seller' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                🏪 New Sellers (<?= $sellerCount ?>)
            </a>
            <a href="<?= e(url('pages/buyer/notifications.php?type=new_stock')) ?>" class="btn <?= $typeFilter === 'new_stock' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                📦 New Arrivals (<?= $stockCount ?>)
            </a>
        </div>

        <span class="muted" style="font-size: 0.85rem;">Showing <?= count($notifications) ?> announcement(s)</span>
    </div>

    <!-- Notifications Stream -->
    <div style="display: flex; flex-direction: column; gap: 1rem;">
        <?php if (empty($notifications)): ?>
            <div class="card" style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No announcements found matching this category.</p>
                <a href="<?= e(url('pages/buyer/notifications.php')) ?>" class="btn btn-outline btn-sm">View All Notifications</a>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): 
                $isRead = !empty($n['is_read']);
                $type = $n['type'];
                
                // Type styling
                $typeConfig = [
                    'new_seller' => [
                        'icon'  => '🏪',
                        'badge' => 'badge-primary',
                        'label' => 'New Merchant Joined',
                        'btn'   => 'Explore Store',
                        'color' => '#6366f1',
                    ],
                    'restock' => [
                        'icon'  => '🔄',
                        'badge' => 'badge-amber',
                        'label' => 'Back in Stock',
                        'btn'   => 'View Restocked Item',
                        'color' => '#b45309',
                    ],
                    'new_stock' => [
                        'icon'  => '📦',
                        'badge' => 'badge-success',
                        'label' => 'New Product Arrival',
                        'btn'   => 'View Product Details',
                        'color' => '#10b981',
                    ],
                    'system' => [
                        'icon'  => '🔔',
                        'badge' => 'badge-primary',
                        'label' => 'Marketplace Update',
                        'btn'   => 'Learn More',
                        'color' => '#475569',
                    ],
                ];
                $cfg = $typeConfig[$type] ?? $typeConfig['system'];
            ?>
                <div class="card" style="border-left: 5px solid <?= $cfg['color'] ?>; background: <?= $isRead ? '#ffffff' : '#f8fafc' ?>; padding: 1.25rem; transition: box-shadow 0.2s ease;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                        <div style="display: flex; gap: 1rem; align-items: flex-start;">
                            <div style="font-size: 1.8rem; line-height: 1; padding: 0.5rem; background: #f1f5f9; border-radius: 8px;">
                                <?= $cfg['icon'] ?>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.35rem;">
                                    <span class="badge <?= $cfg['badge'] ?>" style="font-size: 0.7rem; text-transform: uppercase;">
                                        <?= $cfg['label'] ?>
                                    </span>
                                    <?php if (!$isRead): ?>
                                        <span class="badge badge-danger" style="font-size: 0.65rem; padding: 0.15rem 0.4rem;">
                                            New
                                        </span>
                                    <?php endif; ?>
                                    <span class="muted" style="font-size: 0.8rem;">
                                        <?= e(date('M d, Y \a\t H:i', strtotime($n['created_at']))) ?>
                                    </span>
                                </div>

                                <h3 style="margin: 0 0 0.4rem 0; font-size: 1.1rem; color: #0f172a;">
                                    <?= e($n['title']) ?>
                                </h3>
                                <p style="margin: 0; color: #475569; font-size: 0.95rem; line-height: 1.5;">
                                    <?= e($n['message']) ?>
                                </p>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem; min-width: 140px;">
                            <?php if (!empty($n['link_url'])): ?>
                                <form action="<?= e(url('pages/buyer/notifications.php')) ?>" method="POST" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="redirect_to" value="<?= e($n['link_url']) ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                                        <?= $cfg['btn'] ?> &rarr;
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isRead): ?>
                                <form action="<?= e(url('pages/buyer/notifications.php')) ?>" method="POST" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem; color: #64748b; border-color: #cbd5e1;">
                                        &check; Mark as Read
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
