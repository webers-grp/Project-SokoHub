<?php
/**
 * Developer Console: Database Backup & Restore Manager
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$dev = dev_current_user();
$backupsDir = APP_ROOT . '/backups';
$restoreTestResult = null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/database/backup.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_backup') {
        require_once APP_ROOT . '/scripts/backup.php';
        $res = backup_sokohub_database();
        if ($res['ok']) {
            DevAudit::log('database', 'backup_created', basename($res['file']), ['size' => $res['size']]);
            dev_set_flash('success', "Database backup created successfully: " . basename($res['file']) . " (" . number_format($res['size'] / 1024, 1) . " KB)");
        } else {
            dev_set_flash('error', "Backup creation failed: " . ($res['error'] ?? 'Unknown error'));
        }
        header('Location: ' . dev_url('pages/database/backup.php'));
        exit;
    }

    if ($action === 'restore_test') {
        dev_require_role('owner');
        $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if ($phrase !== 'RESTORE TEST') {
            dev_set_flash('error', 'Confirmation phrase mismatch. Enter "RESTORE TEST" exactly.');
            header('Location: ' . dev_url('pages/database/backup.php'));
            exit;
        }

        require_once APP_ROOT . '/scripts/backup.php';
        // Find latest backup
        $files = glob($backupsDir . '/*.sql');
        if (empty($files)) {
            dev_set_flash('error', 'No backup files found to restore.');
            header('Location: ' . dev_url('pages/database/backup.php'));
            exit;
        }
        rsort($files);
        $latest = $files[0];

        $rest = restore_test_scratch_database($latest, 'sokohub_restore_test');
        $restoreTestResult = $rest;
        DevAudit::log('database', 'restore_test_executed', basename($latest), [
            'ok' => $rest['ok'],
            'tables' => $rest['tables_count'] ?? 0,
        ], $rest['ok'] ? 'success' : 'failed');
    }
}

// Download Handler (Owner only)
if (isset($_GET['download'])) {
    dev_require_role('owner');
    $fn = basename((string) $_GET['download']);
    $fPath = $backupsDir . '/' . $fn;
    if (file_exists($fPath) && str_ends_with($fn, '.sql')) {
        DevAudit::log('database', 'backup_downloaded', $fn, []);
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . filesize($fPath));
        readfile($fPath);
        exit;
    }
}

// Scan backups
$backupList = [];
if (is_dir($backupsDir)) {
    foreach (glob($backupsDir . '/*.sql') as $f) {
        $backupList[] = [
            'filename' => basename($f),
            'size'     => filesize($f),
            'mtime'    => filemtime($f),
        ];
    }
    usort($backupList, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

$csrfToken = dev_csrf_token();
dev_header('Backup & Restore Verification', 'backup');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Backup &amp; Restore Verification</h1>
        <div class="page-subtitle">On-demand SQL snapshots, disaster recovery testing, and scratch restore validation</div>
    </div>
    <div>
        <form method="POST" action="" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create_backup">
            <button type="submit" class="btn btn-primary">
                <?= icon('archive', 'lucide-xs') ?> Create On-Demand Backup Now
            </button>
        </form>
    </div>
</div>

<?php if ($restoreTestResult): ?>
    <div class="card" style="border-color: <?= $restoreTestResult['ok'] ? '#059669' : '#dc2626' ?>;">
        <div class="card-title" style="color: <?= $restoreTestResult['ok'] ? '#34d399' : '#f87171' ?>;">
            <?= icon($restoreTestResult['ok'] ? 'check-circle' : 'x-circle', 'lucide-sm') ?>
            Restore Test Results (Scratch Database: <code>sokohub_restore_test</code>)
        </div>
        <p style="font-size:0.85rem; color:#cbd5e1; margin-bottom:0.75rem;">
            <?= htmlspecialchars($restoreTestResult['message'] ?? '') ?>
        </p>
        <table class="dev-table">
            <tr>
                <td style="width:30%; color:var(--text-muted);">Tables Restored Cleanly</td>
                <td><strong><?= (int) ($restoreTestResult['tables_count'] ?? 0) ?> Tables</strong></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Scratch Cleanup</td>
                <td><span class="badge badge-success">&bull; DROPPED &amp; UNMOUNTED</span></td>
            </tr>
        </table>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 340px; gap: 1.25rem;">
    <!-- Backups Table -->
    <div class="card">
        <div class="card-title"><?= icon('hard-drive', 'lucide-sm') ?> Stored Backup Archives (backups/)</div>
        <div class="table-wrap">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Archive File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($backupList)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:1.5rem; color:var(--text-muted);">No backup archives found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($backupList as $b): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($b['filename']) ?></code></td>
                                <td><?= number_format($b['size'] / 1024, 1) ?> KB</td>
                                <td><?= date('Y-m-d H:i:s', $b['mtime']) ?></td>
                                <td>
                                    <?php if ($dev['role'] === 'owner'): ?>
                                        <a href="<?= dev_url('pages/database/backup.php?download=' . urlencode($b['filename'])) ?>" class="btn btn-secondary btn-sm">
                                            <?= icon('download', 'lucide-xs') ?> Download
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; color:var(--text-muted);">Owner Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Scratch Restore Test Panel -->
    <div class="card">
        <div class="card-title"><?= icon('refresh-cw', 'lucide-sm') ?> Scratch Restore Test</div>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1rem; line-height:1.45;">
            Tests full restore of the latest SQL backup against an isolated scratch database (<code>sokohub_restore_test</code>). Live tables are never modified.
        </p>
        <?php if ($dev['role'] === 'owner'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="restore_test">

                <div class="form-group">
                    <label class="form-label" for="confirm_phrase">Type "RESTORE TEST" to confirm:</label>
                    <input type="text" id="confirm_phrase" name="confirm_phrase" class="form-input" required placeholder="RESTORE TEST" style="font-family:var(--font-mono); text-align:center;">
                </div>

                <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center;">
                    <?= icon('play', 'lucide-xs') ?> Run Scratch Test
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-info" style="font-size:0.75rem;">
                Restore testing is restricted to Owner accounts.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php dev_footer(); ?>
