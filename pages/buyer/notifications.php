<?php
/**
 * SokoHub Buyer Notification Center
 *
 * In-app notification center showing announcements, new sellers, new stock,
 * restock alerts, and personal order/refund updates.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/buyer/notifications.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        Notifications::markAllAsRead($userId);
        set_flash('success', 'All notifications marked as read.');
        redirect('pages/buyer/notifications.php');
    } elseif ($action === 'mark_read') {
        $notifId = (int) ($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            Notifications::markAsRead($notifId, $userId);
        }
        redirect('pages/buyer/notifications.php');
    }
}

$notifications = Notifications::getUserNotifications($userId, 40);
$unreadCount = Notifications::getUnreadCount($userId);

$page_title = 'Notification Center - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 860px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Head Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <nav style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
                <a href="<?= e(url('index.php')) ?>" style="color: #64748b; text-decoration: none;">Home</a> &rsaquo;
                <span style="color: #0f172a; font-weight: 600;">Notifications</span>
            </nav>
            <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <?= icon('bell', 'lucide-sm') ?> <span>Notification Center</span>
                <?php if ($unreadCount > 0): ?>
                    <span style="background: #ef4444; color: white; font-size: 0.8rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px;">
                        <?= $unreadCount ?> new
                    </span>
                <?php endif; ?>
            </h1>
            <p style="color: #64748b; margin: 0.25rem 0 0; font-size: 0.9rem;">
                Stay informed on vendor restocks, new arrivals, order statuses, and system updates.
            </p>
        </div>

        <?php if ($unreadCount > 0): ?>
            <form method="POST" action="" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline" style="font-size: 0.85rem; padding: 0.5rem 1rem; display: inline-flex; align-items: center; gap: 6px;">
                    <?= icon('check-check', 'lucide-xs') ?> <span>Mark All as Read</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Notification List -->
    <?php if (empty($notifications)): ?>
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 3rem 1rem; text-align: center; color: #64748b;">
            <div style="margin-bottom: 0.5rem; color: #94a3b8; display: flex; justify-content: center;"><?= icon('bell-off', 'lucide-xl') ?></div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 0.25rem;">No Notifications Yet</h3>
            <p style="font-size: 0.85rem; margin: 0;">You're completely up to date! When sellers add items or your orders update, you'll see them here.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <?php foreach ($notifications as $n): ?>
                <?php
                $isRead = !empty($n['is_read']);
                $icon = 'bell';
                $badgeBg = '#f1f5f9';
                $badgeFg = '#475569';

                switch ($n['type']) {
                    case 'new_seller':
                        $icon = 'store';
                        $badgeBg = '#e0f2fe';
                        $badgeFg = '#0369a1';
                        break;
                    case 'new_stock':
                        $icon = 'sparkles';
                        $badgeBg = '#dcfce7';
                        $badgeFg = '#15803d';
                        break;
                    case 'restock':
                        $icon = 'package';
                        $badgeBg = '#fef3c7';
                        $badgeFg = '#b45309';
                        break;
                    case 'order':
                        $icon = 'shopping-bag';
                        $badgeBg = '#ede9fe';
                        $badgeFg = '#6d28d9';
                        break;
                    default:
                        $icon = 'info';
                }
                ?>
                <div style="background: <?= $isRead ? '#ffffff' : '#f8fafc' ?>; border: 1px solid <?= $isRead ? '#e2e8f0' : '#cbd5e1' ?>; border-left: 5px solid <?= $isRead ? '#94a3b8' : '#16a34a' ?>; border-radius: 8px; padding: 1.25rem; display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between;">
                    <div style="display: flex; gap: 1rem; align-items: flex-start;">
                        <span style="display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; background: <?= $badgeBg ?>; color: <?= $badgeFg ?>; flex-shrink: 0;"><?= icon($icon, 'lucide-sm') ?></span>
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; flex-wrap: wrap;">
                                <span style="background: <?= $badgeBg ?>; color: <?= $badgeFg ?>; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 9999px; text-transform: uppercase;">
                                    <?= e(str_replace('_', ' ', $n['type'])) ?>
                                </span>
                                <h3 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0;">
                                    <?= e($n['title']) ?>
                                </h3>
                                <span style="font-size: 0.75rem; color: #94a3b8;">
                                    &bull; <?= e(date('M d, Y H:i', strtotime($n['created_at']))) ?>
                                </span>
                            </div>
                            <p style="font-size: 0.85rem; color: #334155; margin: 0 0 0.5rem; line-height: 1.4;">
                                <?= e($n['message']) ?>
                            </p>
                            <?php if (!empty($n['link_url'])): ?>
                                <a href="<?= e(url($n['link_url'])) ?>" style="font-size: 0.8rem; font-weight: 600; color: #16a34a; text-decoration: none;">
                                    View Details &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$isRead): ?>
                        <form method="POST" action="" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" style="background: none; border: none; font-size: 0.75rem; color: #64748b; cursor: pointer; text-decoration: underline;" title="Mark as read">
                                Mark read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
