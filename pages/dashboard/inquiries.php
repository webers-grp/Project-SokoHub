<?php
/**
 * SokoHub Contact Support Inquiries Admin Management
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();
require_role(['admin']);
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
        redirect('pages/dashboard/inquiries.php');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status') {
        $st = (string) ($_POST['status'] ?? 'read');
        if (in_array($st, ['new', 'read', 'resolved'], true)) {
            $pdo->prepare('UPDATE contact_messages SET status = :st WHERE id = :id')->execute([':st' => $st, ':id' => $id]);
            set_flash('success', 'Inquiry status updated.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM contact_messages WHERE id = :id')->execute([':id' => $id]);
        set_flash('info', 'Inquiry deleted.');
    }
    redirect('pages/dashboard/inquiries.php');
}

$messages = $pdo->query('SELECT * FROM contact_messages ORDER BY created_at DESC')->fetchAll();
$pageTitle = 'SokoHub | Support Inquiries';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Customer Support Inquiries</h1>
            <p class="muted">Review and resolve messages submitted through the Contact Us page.</p>
        </div>
        <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">Back to Dashboard</a>
    </div>

    <div class="card">
        <?php if (empty($messages)): ?>
            <p class="muted">No support inquiries logged yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sender</th>
                            <th>Subject &amp; Message</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($messages as $m): ?>
                        <?php $badge = get_status_badge($m['status']); ?>
                        <tr>
                            <td>
                                <strong><?= e($m['name']) ?></strong>
                                <div class="small muted"><?= e($m['email']) ?></div>
                            </td>
                            <td>
                                <strong><?= e($m['subject']) ?></strong>
                                <p class="small muted"><?= e($m['message']) ?></p>
                            </td>
                            <td><span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></td>
                            <td class="small"><?= e(date('d M Y, H:i', strtotime($m['created_at']))) ?></td>
                            <td>
                                <form action="<?= e(url('pages/dashboard/inquiries.php')) ?>" method="POST" style="display:flex; gap:0.35rem; margin-bottom:0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <select name="status" class="form-control" style="padding:0.25rem; font-size:0.82rem; width:auto;">
                                        <option value="new" <?= $m['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                        <option value="read" <?= $m['status'] === 'read' ? 'selected' : '' ?>>Read</option>
                                        <option value="resolved" <?= $m['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm">Save</button>
                                </form>
                                <form action="<?= e(url('pages/dashboard/inquiries.php')) ?>" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this inquiry?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
