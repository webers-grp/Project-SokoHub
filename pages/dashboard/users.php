<?php
/**
 * SokoHub Admin User Management Portal
 * Allows administrators to search, filter, inspect, suspend, reactivate,
 * unlock brute-forced accounts, verify emails, and delete users from the system.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_management.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$adminUser = require_role(['admin']);
$adminId   = (int) $adminUser['id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/users.php');
    }

    $action       = trim((string) ($_POST['action'] ?? ''));
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $reason       = trim((string) ($_POST['reason'] ?? ''));
    $newRole      = trim((string) ($_POST['new_role'] ?? ''));

    if ($targetUserId <= 0) {
        set_flash('error', 'Invalid target user selected.');
        redirect('pages/dashboard/users.php');
    }

    if ($action === 'delete') {
        $errorMsg = null;
        $mode = null;
        $ok = UserManagement::deleteUser($targetUserId, $adminId, $errorMsg, $mode);
        if ($ok) {
            if ($mode === 'hard_deleted') {
                set_flash('success', "User #{$targetUserId} and all associated records have been permanently removed.");
            } else {
                set_flash('info', "User #{$targetUserId} has past order transactions. Their account was safely archived, logins revoked, and personal credentials deactivated to preserve financial audit integrity.");
            }
        } else {
            set_flash('error', $errorMsg ?: 'Failed to delete user.');
        }
    } elseif ($action === 'suspend') {
        if (UserManagement::suspendUser($targetUserId, $adminId, $reason)) {
            set_flash('warning', "User #{$targetUserId} has been suspended from accessing SokoHub.");
        } else {
            set_flash('error', 'Unable to suspend user account (cannot suspend your own account).');
        }
    } elseif ($action === 'reactivate') {
        if (UserManagement::reactivateUser($targetUserId, $adminId)) {
            set_flash('success', "User #{$targetUserId} has been reactivated successfully.");
        } else {
            set_flash('error', 'Failed to reactivate user account.');
        }
    } elseif ($action === 'unlock') {
        if (UserManagement::unlockAccount($targetUserId, $adminId)) {
            set_flash('success', "Account #{$targetUserId} brute-force lockout cleared! User can now log in.");
        } else {
            set_flash('error', 'Failed to unlock user account.');
        }
    } elseif ($action === 'verify_email') {
        if (UserManagement::verifyUserEmail($targetUserId, $adminId)) {
            set_flash('success', "User #{$targetUserId} email verified manually and account activated.");
        } else {
            set_flash('error', 'Failed to verify user email.');
        }
    } elseif ($action === 'change_role') {
        if (UserManagement::updateRole($targetUserId, $newRole, $adminId)) {
            set_flash('success', "User #{$targetUserId} role updated to '" . ucfirst($newRole) . "'.");
        } else {
            set_flash('error', 'Failed to change user role. Note: The platform must retain at least one active administrator.');
        }
    }

    // Preserve query filters on redirect
    $redirectParams = array_filter([
        'q'      => $_GET['q'] ?? null,
        'role'   => $_GET['role'] ?? null,
        'status' => $_GET['status'] ?? null,
        'locked' => $_GET['locked'] ?? null,
        'page'   => $_GET['page'] ?? null,
    ]);
    redirect('pages/dashboard/users.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : ''));
}

// Filters & Pagination
$qFilter      = trim((string) ($_GET['q'] ?? ''));
$roleFilter   = trim((string) ($_GET['role'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$lockedFilter = trim((string) ($_GET['locked'] ?? ''));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$limit        = 20;
$offset       = ($page - 1) * $limit;

$filters = [
    'q'      => $qFilter,
    'role'   => $roleFilter,
    'status' => $statusFilter,
    'locked' => $lockedFilter,
];

$stats      = UserManagement::getStats();
$totalUsers = UserManagement::countUsers($filters);
$totalPages = max(1, (int) ceil($totalUsers / $limit));
$usersList  = UserManagement::getUsers($filters, $limit, $offset);

$pageTitle = 'SokoHub | User Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <!-- Header -->
    <div class="page-head">
        <div>
            <h1>User &amp; Identity Management</h1>
            <p class="muted">Manage buyer, seller, rider, and admin accounts, unlock throttled logins, and remove users.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/system_health.php')) ?>" class="btn btn-outline">
                <?= icon('activity', 'lucide-xs') ?>
                <span>System Health</span>
            </a>
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-primary">
                <?= icon('arrow-left', 'lucide-xs', 'white') ?>
                <span>Back to Hub</span>
            </a>
        </div>
    </div>

    <!-- Quick Stats Metric Strip -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <span class="stat-label">Total Registered</span>
            <strong class="stat-value"><?= number_format($stats['total']) ?></strong>
            <span class="badge badge-primary">Platform All-Time</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Active Buyers</span>
            <strong class="stat-value"><?= number_format($stats['buyers_active']) ?></strong>
            <span class="badge badge-success">Online Shoppers</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Merchants / Sellers</span>
            <strong class="stat-value"><?= number_format($stats['sellers']) ?></strong>
            <span class="badge badge-amber"><?= $stats['student_vendors'] ?> KyU Student Vendors</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Delivery Riders</span>
            <strong class="stat-value"><?= number_format($stats['riders']) ?></strong>
            <span class="badge badge-info">Active Couriers</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Security Attention</span>
            <strong class="stat-value" style="color: <?= ($stats['locked'] > 0 || $stats['suspended'] > 0) ? '#ef4444' : 'inherit' ?>;">
                <?= number_format($stats['locked']) ?> Locked / <?= number_format($stats['suspended']) ?> Susp.
            </strong>
            <span class="badge <?= $stats['locked'] > 0 ? 'badge-danger' : 'badge-neutral' ?>">
                <?= $stats['pending_verification'] ?> Pending Email
            </span>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
        <form method="GET" action="<?= e(url('pages/dashboard/users.php')) ?>" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: flex-end;">
            <div style="flex: 1 1 240px;">
                <label for="q" class="small muted" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">Search Accounts</label>
                <input type="text" id="q" name="q" class="form-control" value="<?= e($qFilter) ?>" placeholder="Name, email, phone, shop, reg no...">
            </div>

            <div style="flex: 0 1 160px;">
                <label for="role" class="small muted" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">Role</label>
                <select id="role" name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="buyer" <?= $roleFilter === 'buyer' ? 'selected' : '' ?>>Buyers</option>
                    <option value="seller" <?= $roleFilter === 'seller' ? 'selected' : '' ?>>Sellers</option>
                    <option value="delivery" <?= $roleFilter === 'delivery' ? 'selected' : '' ?>>Delivery Riders</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrators</option>
                </select>
            </div>

            <div style="flex: 0 1 180px;">
                <label for="status" class="small muted" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">Account Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="pending_verification" <?= $statusFilter === 'pending_verification' ? 'selected' : '' ?>>Pending Verification</option>
                </select>
            </div>

            <div style="display: flex; align-items: center; gap: 0.4rem; padding-bottom: 0.5rem;">
                <input type="checkbox" id="locked" name="locked" value="1" <?= $lockedFilter === '1' ? 'checked' : '' ?>>
                <label for="locked" class="small" style="font-weight: 600; cursor: pointer; color: #ef4444; margin: 0;">
                    <?= icon('lock', 'lucide-xs', 'rose') ?> Locked Only
                </label>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.15rem;">
                    <?= icon('filter', 'lucide-xs') ?>
                    <span>Filter</span>
                </button>
                <?php if ($qFilter || $roleFilter || $statusFilter || $lockedFilter): ?>
                    <a href="<?= e(url('pages/dashboard/users.php')) ?>" class="btn btn-outline" style="padding: 0.65rem 1rem;">
                        <span>Reset</span>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Users Table Card -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div style="font-weight: 700; font-size: 1.05rem;">
                <span>Accounts Directory</span>
                <span class="badge badge-neutral" style="margin-left: 0.5rem;"><?= number_format($totalUsers) ?> found</span>
            </div>
            <div class="small muted">
                Showing page <?= $page ?> of <?= $totalPages ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>User Identity</th>
                        <th>Role</th>
                        <th>Store / Fleet Details</th>
                        <th>Status &amp; Security</th>
                        <th>Activity</th>
                        <th style="text-align: right; min-width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usersList)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 3rem 1rem;" class="muted">
                                <?= icon('users', 'lucide-lg', 'muted') ?>
                                <p style="margin-top: 0.75rem;">No user accounts match the selected criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usersList as $u): ?>
                            <?php 
                            $isSelf = ($u['id'] === $adminId);
                            $isLocked = (!empty($u['locked_until']) && strtotime($u['locked_until']) > time());
                            ?>
                            <tr>
                                <td>
                                    <span class="small muted" style="font-family: monospace; font-weight: 700;">#<?= $u['id'] ?></span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--green-light); color: var(--green-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.95rem; flex-shrink: 0;">
                                            <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700;">
                                                <?= e($u['full_name']) ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="badge badge-neutral" style="font-size: 0.7rem; padding: 0.1rem 0.4rem;">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small muted" style="line-height: 1.3;">
                                                <a href="mailto:<?= e($u['email']) ?>" style="color: inherit; text-decoration: underline;"><?= e($u['email']) ?></a>
                                                <br>
                                                <span><?= e($u['phone']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge badge-danger">
                                            <?= icon('shield-alert', 'lucide-xs') ?> Admin
                                        </span>
                                    <?php elseif ($u['role'] === 'seller'): ?>
                                        <span class="badge badge-amber">
                                            <?= icon('store', 'lucide-xs') ?> Seller
                                        </span>
                                    <?php elseif ($u['role'] === 'delivery'): ?>
                                        <span class="badge badge-info">
                                            <?= icon('truck', 'lucide-xs') ?> Delivery
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">
                                            <?= icon('shopping-bag', 'lucide-xs') ?> Buyer
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['role'] === 'seller'): ?>
                                        <div style="font-size: 0.88rem; font-weight: 600;">
                                            <?= e($u['business_name'] ?: 'Unnamed Shop') ?>
                                        </div>
                                        <div class="small muted">
                                            <?= e($u['town']) ?><?= $u['stall_location'] ? ' &bull; ' . e($u['stall_location']) : '' ?>
                                        </div>
                                        <?php if (!empty($u['is_student_vendor'])): ?>
                                            <div class="small" style="color: var(--green-dark); font-weight: 600; display: flex; align-items: center; gap: 0.25rem; margin-top: 0.2rem;">
                                                <?= icon('graduation-cap', 'lucide-xs') ?>
                                                <span>KyU (<?= e($u['student_reg_no'] ?: 'Verified') ?>)</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($u['role'] === 'delivery'): ?>
                                        <div style="font-size: 0.88rem; font-weight: 600;">
                                            <?= ucfirst($u['transport_method'] ?: 'Motorbike') ?>
                                        </div>
                                        <div class="small muted">
                                            Plate: <strong><?= e($u['vehicle_plate_number'] ?: 'Pending Plate') ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <div class="small muted">
                                            <?= $u['buyer_order_count'] ?> order(s) placed
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                        <?php if ($u['status'] === 'active'): ?>
                                            <span class="badge badge-success" style="width: fit-content;">
                                                <?= icon('check-circle', 'lucide-xs') ?> Active
                                            </span>
                                        <?php elseif ($u['status'] === 'suspended'): ?>
                                            <span class="badge badge-danger" style="width: fit-content;">
                                                <?= icon('slash', 'lucide-xs') ?> Suspended
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="width: fit-content;">
                                                <?= icon('clock', 'lucide-xs') ?> Pending Verification
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($isLocked): ?>
                                            <div style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.76rem; color: #ef4444; font-weight: 700; background: rgba(239, 68, 68, 0.12); padding: 0.2rem 0.45rem; border-radius: 4px;">
                                                <?= icon('lock', 'lucide-xs', 'rose') ?>
                                                <span>Locked (<?= $u['failed_login_count'] ?> fails)</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small muted">
                                        Joined: <?= date('M j, Y', strtotime($u['created_at'])) ?>
                                    </div>
                                    <div class="small muted" style="font-size: 0.78rem;">
                                        <?php if (!empty($u['last_login_at'])): ?>
                                            Login: <?= date('M j, H:i', strtotime($u['last_login_at'])) ?>
                                        <?php else: ?>
                                            Login: Never
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.4rem;">
                                        <!-- Unlock Account Button (if locked) -->
                                        <?php if ($isLocked): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Clear brute-force lockout for <?= e($u['full_name']) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="action" value="unlock">
                                                <button type="submit" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; border-color: #ef4444; color: #ef4444;" title="Clear brute-force throttle and reset failed attempts">
                                                    <?= icon('unlock', 'lucide-xs') ?> Unlock
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Manual Email Verify (if pending) -->
                                        <?php if ($u['status'] === 'pending_verification'): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Manually verify and activate email for <?= e($u['full_name']) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="action" value="verify_email">
                                                <button type="submit" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; border-color: #22c55e; color: #22c55e;" title="Manually verify email and activate account">
                                                    <?= icon('check-check', 'lucide-xs') ?> Verify
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Suspend / Reactivate -->
                                        <?php if (!$isSelf): ?>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <button type="button" class="btn btn-outline btn-open-suspend" 
                                                        data-id="<?= $u['id'] ?>" 
                                                        data-name="<?= e($u['full_name']) ?>"
                                                        style="padding: 0.35rem 0.65rem; font-size: 0.78rem; border-color: #f59e0b; color: #b45309;" title="Suspend account access">
                                                    <?= icon('slash', 'lucide-xs') ?> Suspend
                                                </button>
                                            <?php elseif ($u['status'] === 'suspended'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate account for <?= e($u['full_name']) ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <button type="submit" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; border-color: #22c55e; color: #22c55e;" title="Restore account access">
                                                        <?= icon('rotate-cw', 'lucide-xs') ?> Reactivate
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Role Edit Button -->
                                            <button type="button" class="btn btn-outline btn-open-role"
                                                    data-id="<?= $u['id'] ?>"
                                                    data-name="<?= e($u['full_name']) ?>"
                                                    data-role="<?= e($u['role']) ?>"
                                                    style="padding: 0.35rem 0.65rem; font-size: 0.78rem;" title="Change account role">
                                                <?= icon('user-cog', 'lucide-xs') ?> Role
                                            </button>

                                            <!-- Direct Delete / Remove User Button -->
                                            <button type="button" class="btn btn-danger btn-open-delete"
                                                    data-id="<?= $u['id'] ?>"
                                                    data-name="<?= e($u['full_name']) ?>"
                                                    data-email="<?= e($u['email']) ?>"
                                                    data-orders="<?= $u['buyer_order_count'] ?>"
                                                    data-sales="<?= $u['seller_sales_count'] ?>"
                                                    style="padding: 0.35rem 0.65rem; font-size: 0.78rem;" title="Remove user from SokoHub">
                                                <?= icon('trash-2', 'lucide-xs') ?> Remove
                                            </button>
                                        <?php else: ?>
                                            <span class="small muted" style="font-style: italic;">Protected</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                <div class="small muted">
                    Showing records <?= $offset + 1 ?> to <?= min($totalUsers, $offset + $limit) ?> of <?= $totalUsers ?>
                </div>
                <div class="pagination" style="display: flex; gap: 0.35rem;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current" style="padding: 0.4rem 0.85rem; border-radius: 6px;"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= e(url('pages/dashboard/users.php?' . http_build_query(array_merge($filters, ['page' => $i])))) ?>" 
                               style="padding: 0.4rem 0.85rem; border-radius: 6px; text-decoration: none;">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal: Delete / Remove User -->
<div id="deleteModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 500px; width: 100%; border-top: 4px solid #ef4444; padding: 1.5rem; position: relative;">
        <div style="display: flex; align-items: center; gap: 0.5rem; color: #ef4444; font-size: 1.15rem; font-weight: 700; margin-bottom: 0.75rem;">
            <?= icon('alert-triangle', 'lucide-sm', 'rose') ?>
            <span>Confirm Account Removal</span>
        </div>

        <p id="deleteModalText" style="line-height: 1.5; margin-bottom: 1rem;">
            Are you sure you want to remove this user from SokoHub?
        </p>

        <div id="deleteHistoryNotice" style="display: none; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; padding: 0.85rem; margin-bottom: 1.25rem;">
            <div style="font-weight: 700; color: #b45309; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem;">
                <?= icon('shield-alert', 'lucide-xs', 'amber') ?>
                <span>Financial Transaction Integrity Active</span>
            </div>
            <p class="small" style="margin: 0; color: inherit; line-height: 1.4;">
                This account has existing marketplace orders/sales. To preserve audit and tax records, the user will be <strong>Safely Archived &amp; Deactivated</strong> (login revoked, name/email scrambled, credentials disabled) without corrupting order records.
            </p>
        </div>

        <div id="deleteCleanNotice" style="display: none; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; padding: 0.85rem; margin-bottom: 1.25rem;">
            <p class="small" style="margin: 0; color: #ef4444; line-height: 1.4;">
                <?= icon('trash-2', 'lucide-xs') ?> This account has no order history. It will be <strong>permanently hard-deleted</strong> from the database.
            </p>
        </div>

        <form method="POST" action="<?= e(url('pages/dashboard/users.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId" value="">

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline btn-close-modal">Cancel</button>
                <button type="submit" class="btn btn-danger" style="background: #ef4444; border-color: #ef4444;">
                    <?= icon('trash-2', 'lucide-xs') ?>
                    <span>Yes, Remove User</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Suspend User -->
<div id="suspendModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 480px; width: 100%; border-top: 4px solid #f59e0b; padding: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem; color: #b45309; font-size: 1.15rem; font-weight: 700; margin-bottom: 0.75rem;">
            <?= icon('slash', 'lucide-sm', 'amber') ?>
            <span>Suspend User Account</span>
        </div>

        <p id="suspendModalText" style="line-height: 1.5; margin-bottom: 1rem;">
            Suspending this account will immediately block the user from logging in or placing/fulfilling orders.
        </p>

        <form method="POST" action="<?= e(url('pages/dashboard/users.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="user_id" id="suspendUserId" value="">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="suspendReason">Administrative Suspension Reason</label>
                <textarea id="suspendReason" name="reason" class="form-control" rows="3" placeholder="e.g. Terms violation, dispute under investigation, fraudulent activity..."></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline btn-close-modal">Cancel</button>
                <button type="submit" class="btn btn-amber">
                    <?= icon('slash', 'lucide-xs') ?>
                    <span>Confirm Suspension</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Change Role -->
<div id="roleModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 440px; width: 100%; border-top: 4px solid #3b82f6; padding: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem; color: #1d4ed8; font-size: 1.15rem; font-weight: 700; margin-bottom: 0.75rem;">
            <?= icon('user-cog', 'lucide-sm', 'blue') ?>
            <span>Change Account Role</span>
        </div>

        <p id="roleModalText" style="line-height: 1.5; margin-bottom: 1rem;">
            Assign a new platform role to this user.
        </p>

        <form method="POST" action="<?= e(url('pages/dashboard/users.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="user_id" id="roleUserId" value="">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="new_role_select">Select New Role</label>
                <select id="new_role_select" name="new_role" class="form-control">
                    <option value="buyer">Buyer (Standard Shopper)</option>
                    <option value="seller">Seller (Store Merchant)</option>
                    <option value="delivery">Delivery Rider (Courier)</option>
                    <option value="admin">Administrator (Full Privileges)</option>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline btn-close-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <?= icon('check', 'lucide-xs') ?>
                    <span>Update Role</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modal Helpers
    function closeModal() {
        document.querySelectorAll('.modal-backdrop').forEach(m => m.style.display = 'none');
    }

    document.querySelectorAll('.btn-close-modal').forEach(b => {
        b.addEventListener('click', closeModal);
    });

    // Delete Modal Trigger
    document.querySelectorAll('.btn-open-delete').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const email = this.getAttribute('data-email');
            const orders = parseInt(this.getAttribute('data-orders') || '0', 10);
            const sales = parseInt(this.getAttribute('data-sales') || '0', 10);

            document.getElementById('deleteUserId').value = id;
            document.getElementById('deleteModalText').innerHTML = `Are you sure you want to remove user <strong>${name}</strong> (#${id} &bull; ${email})?`;

            const hasHistory = (orders > 0 || sales > 0);
            document.getElementById('deleteHistoryNotice').style.display = hasHistory ? 'block' : 'none';
            document.getElementById('deleteCleanNotice').style.display = hasHistory ? 'none' : 'block';

            const modal = document.getElementById('deleteModal');
            modal.style.display = 'flex';
        });
    });

    // Suspend Modal Trigger
    document.querySelectorAll('.btn-open-suspend').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');

            document.getElementById('suspendUserId').value = id;
            document.getElementById('suspendModalText').innerHTML = `Are you sure you want to suspend <strong>${name}</strong> (#${id})? They will immediately be barred from active session access.`;

            const modal = document.getElementById('suspendModal');
            modal.style.display = 'flex';
        });
    });

    // Role Modal Trigger
    document.querySelectorAll('.btn-open-role').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const currentRole = this.getAttribute('data-role');

            document.getElementById('roleUserId').value = id;
            document.getElementById('roleModalText').innerHTML = `Change platform role for <strong>${name}</strong> (#${id}):`;
            document.getElementById('new_role_select').value = currentRole;

            const modal = document.getElementById('roleModal');
            modal.style.display = 'flex';
        });
    });

    // Close on backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(m => {
        m.addEventListener('click', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
