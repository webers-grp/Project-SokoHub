<?php
/**
 * SokoHub Administrative Audit Trail Viewer
 * Displays filterable, searchable system activity and administrative events.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$currentUser = require_role(['admin']);

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$roleFilter   = trim((string) ($_GET['actor_role'] ?? ''));
$dateFrom     = trim((string) ($_GET['date_from'] ?? ''));
$dateTo       = trim((string) ($_GET['date_to'] ?? ''));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$limit        = 25;
$offset       = ($page - 1) * $limit;

$filters = [
    'action'     => $actionFilter,
    'actor_role' => $roleFilter,
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
];

$totalLogs = Audit::countLogs($filters);
$totalPages = max(1, (int) ceil($totalLogs / $limit));
$logs = Audit::getLogs($filters, $limit, $offset);

// Quick dashboard statistics for audit overview
$pdo = get_db_connection();
Audit::ensureTableExists();
$statsToday = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= CURDATE()")->fetchColumn();
$statsAdmin = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'admin'")->fetchColumn();
$statsUniqueActors = (int) $pdo->query("SELECT COUNT(DISTINCT actor_user_id) FROM audit_logs WHERE actor_user_id IS NOT NULL")->fetchColumn();

$pageTitle = 'SokoHub | System Audit Trail';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>System Audit Trail &amp; Activity Log</h1>
            <p class="muted">Immutable log of administrative decisions, security events, and platform transactions.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">&larr; Back to Dashboard</a>
        </div>
    </div>

    <!-- Quick Stats Metric Cards -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <span class="stat-label">Total Logged Events</span>
            <strong class="stat-value"><?= number_format($totalLogs) ?></strong>
            <span class="badge badge-primary">All Time</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Today's Activity</span>
            <strong class="stat-value"><?= number_format($statsToday) ?></strong>
            <span class="badge badge-success">Last 24 Hours</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Admin Actions</span>
            <strong class="stat-value"><?= number_format($statsAdmin) ?></strong>
            <span class="badge badge-amber">Privileged</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Unique Actors</span>
            <strong class="stat-value"><?= number_format($statsUniqueActors) ?></strong>
            <span class="badge badge-primary">Users</span>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="card" style="margin-bottom: 2rem;">
        <form method="GET" action="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="filter-form" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            <div style="flex: 1 1 200px;">
                <label for="action" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Filter by Action</label>
                <input type="text" id="action" name="action" class="form-control" placeholder="e.g. seller., auth., product." value="<?= e($actionFilter) ?>">
            </div>

            <div style="flex: 1 1 150px;">
                <label for="actor_role" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Actor Role</label>
                <select id="actor_role" name="actor_role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="seller" <?= $roleFilter === 'seller' ? 'selected' : '' ?>>Seller</option>
                    <option value="buyer" <?= $roleFilter === 'buyer' ? 'selected' : '' ?>>Buyer</option>
                    <option value="delivery" <?= $roleFilter === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                </select>
            </div>

            <div style="flex: 1 1 150px;">
                <label for="date_from" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Date From</label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>

            <div style="flex: 1 1 150px;">
                <label for="date_to" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Date To</label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary">Filter Logs</button>
                <?php if ($actionFilter !== '' || $roleFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="btn btn-outline" title="Reset Filters">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Audit Table Card -->
    <div class="card">
        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Recent System Events (Page <?= (int) $page ?> of <?= (int) $totalPages ?>)</h2>
            <span class="muted" style="font-size: 0.9rem;">Showing <?= count($logs) ?> of <?= number_format($totalLogs) ?> total events</span>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <p class="muted">No audit events found matching the selected filter criteria.</p>
                <a href="<?= e(url('pages/dashboard/audit_logs.php')) ?>" class="btn btn-outline btn-sm">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color, #e2e8f0); text-align: left;">
                            <th style="padding: 0.75rem;">Time</th>
                            <th style="padding: 0.75rem;">Actor</th>
                            <th style="padding: 0.75rem;">Action</th>
                            <th style="padding: 0.75rem;">Target Entity</th>
                            <th style="padding: 0.75rem;">IP Address</th>
                            <th style="padding: 0.75rem; text-align: right;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $row): 
                            $badgeClass = 'badge-primary';
                            if (str_starts_with($row['action'], 'seller.')) $badgeClass = 'badge-amber';
                            elseif (str_starts_with($row['action'], 'auth.')) $badgeClass = 'badge-success';
                            elseif (str_starts_with($row['action'], 'payout.')) $badgeClass = 'badge-primary';
                            elseif (str_contains($row['action'], 'delete') || str_contains($row['action'], 'reject') || str_contains($row['action'], 'fail')) $badgeClass = 'badge-danger';
                        ?>
                            <tr style="border-bottom: 1px solid var(--border-color, #edf2f7);">
                                <td style="padding: 0.75rem; white-space: nowrap; font-size: 0.85rem;" class="muted">
                                    <?= e(date('M d, Y H:i:s', strtotime($row['created_at']))) ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <?php if ($row['actor_user_id']): ?>
                                        <div style="font-weight: 600; font-size: 0.9rem;"><?= e($row['actor_name'] ?: 'User #' . $row['actor_user_id']) ?></div>
                                        <div style="font-size: 0.75rem;" class="muted">
                                            <span class="badge" style="font-size: 0.7rem;"><?= e(strtoupper($row['actor_role'] ?? 'USER')) ?></span>
                                            <?= e($row['actor_email'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-outline" style="font-size: 0.75rem;">SYSTEM</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <span class="badge <?= e($badgeClass) ?>" style="font-family: monospace; font-size: 0.8rem;">
                                        <?= e($row['action']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.9rem;">
                                    <?php if ($row['entity_type']): ?>
                                        <span class="muted"><?= e($row['entity_type']) ?></span>
                                        <?php if ($row['entity_id']): ?>
                                            <strong>#<?= e($row['entity_id']) ?></strong>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;" class="muted">
                                    <?= e($row['ip_address'] ?? 'Unknown') ?>
                                </td>
                                <td style="padding: 0.75rem; text-align: right;">
                                    <?php if (!empty($row['details'])): ?>
                                        <details style="cursor: pointer; text-align: left; display: inline-block;">
                                            <summary class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">View JSON</summary>
                                            <div style="position: absolute; right: 2rem; z-index: 100; background: #1e293b; color: #f8fafc; padding: 1rem; border-radius: 6px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); max-width: 450px; overflow-x: auto; font-size: 0.75rem;">
                                                <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem; font-weight:bold; border-bottom:1px solid #475569; padding-bottom:0.25rem;">
                                                    <span>Event #<?= (int) $row['id'] ?> Details</span>
                                                </div>
                                                <pre style="margin: 0; font-family: monospace;"><?= e(json_encode(json_decode($row['details']), JSON_PRETTY_PRINT)) ?></pre>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <span class="muted" style="font-size: 0.75rem;">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem;">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(url('pages/dashboard/audit_logs.php?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>" class="btn btn-outline btn-sm">&larr; Previous</a>
                    <?php endif; ?>

                    <span style="display: flex; align-items: center; padding: 0 1rem; font-size: 0.9rem;">
                        Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= e(url('pages/dashboard/audit_logs.php?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
