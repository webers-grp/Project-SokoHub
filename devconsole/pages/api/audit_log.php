<?php
/**
 * Developer Console: Append-Only Audit Log Viewer
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();

$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$sql = 'SELECT * FROM devconsole_audit_logs WHERE 1=1';
$params = [];

if ($category !== '') {
    $sql .= ' AND category = :cat';
    $params[':cat'] = $category;
}
if ($search !== '') {
    $sql .= ' AND (action LIKE :s1 OR target LIKE :s2 OR details LIKE :s3 OR username LIKE :s4)';
    $params[':s1'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "%{$search}%";
    $params[':s4'] = "%{$search}%";
}

$sql .= ' ORDER BY id DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

dev_header('Console Audit Log', 'audit_log');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Developer Console Audit Log</h1>
        <div class="page-subtitle">Immutable append-only record of every developer action, tool invocation, and security event</div>
    </div>
</div>

<div class="card">
    <form method="GET" action="" style="display: flex; gap: 0.75rem; align-items: center;">
        <select name="category" class="form-select" onchange="this.form.submit()" style="width: 180px;">
            <option value="">All Categories</option>
            <option value="auth" <?= $category === 'auth' ? 'selected' : '' ?>>Auth &amp; 2FA</option>
            <option value="payment_lab" <?= $category === 'payment_lab' ? 'selected' : '' ?>>Payment Lab</option>
            <option value="database" <?= $category === 'database' ? 'selected' : '' ?>>Database Tools</option>
            <option value="logs" <?= $category === 'logs' ? 'selected' : '' ?>>Logs &amp; Cron</option>
            <option value="config" <?= $category === 'config' ? 'selected' : '' ?>>Config &amp; Flags</option>
            <option value="security" <?= $category === 'security' ? 'selected' : '' ?>>Security &amp; Lockouts</option>
        </select>

        <input type="text" name="search" class="form-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search action, username, or target..." style="flex: 1;">
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('search', 'lucide-xs') ?> Filter</button>
        <?php if ($category !== '' || $search !== ''): ?>
            <a href="<?= dev_url('pages/api/audit_log.php') ?>" class="btn btn-secondary btn-sm">Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="card" style="padding: 0;">
    <div class="table-wrap" style="border: none;">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Developer</th>
                    <th>Category</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Details</th>
                    <th>Result</th>
                    <th>IP Address</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:1.5rem; color:var(--text-muted);">No console audit records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><code><?= $l['id'] ?></code></td>
                            <td><strong><?= htmlspecialchars($l['username']) ?></strong></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($l['category']) ?></span></td>
                            <td><code><?= htmlspecialchars($l['action']) ?></code></td>
                            <td><?= $l['target'] ? '<code>' . htmlspecialchars($l['target']) . '</code>' : '—' ?></td>
                            <td><code style="font-size:0.75rem;"><?= htmlspecialchars(substr($l['details'] ?? '', 0, 70)) ?></code></td>
                            <td>
                                <span class="badge <?= $l['result'] === 'success' ? 'badge-success' : ($l['result'] === 'blocked' ? 'badge-warning' : 'badge-danger') ?>" style="font-size:0.65rem;">
                                    <?= strtoupper($l['result']) ?>
                                </span>
                            </td>
                            <td><code><?= htmlspecialchars($l['ip_address']) ?></code></td>
                            <td><?= htmlspecialchars($l['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
