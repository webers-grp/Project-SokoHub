<?php
/**
 * Developer Console: Database Migrations Manager
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dev = dev_current_user();
$migrationsDir = APP_ROOT . '/database/migrations';

// Scan migration files
$migrationFiles = [];
if (is_dir($migrationsDir)) {
    $files = scandir($migrationsDir);
    foreach ($files as $f) {
        if (str_ends_with($f, '.sql')) {
            $migrationFiles[] = $f;
        }
    }
    sort($migrationFiles);
}

// Handle Migration Execution (Owner only + reauth)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dev_require_role('owner');
    dev_require_reauth(dev_url('pages/database/migrations.php'));

    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
        header('Location: ' . dev_url('pages/database/migrations.php'));
        exit;
    }

    $fileToRun = basename((string) ($_POST['migration_file'] ?? ''));
    $fullPath = $migrationsDir . '/' . $fileToRun;

    if (!file_exists($fullPath)) {
        dev_set_flash('error', "Migration file {$fileToRun} not found.");
    } else {
        try {
            // 1. Run automatic database backup first
            require_once APP_ROOT . '/scripts/backup.php';

            // 2. Execute SQL
            $sql = file_get_contents($fullPath);
            $lines = explode("\n", $sql);
            $cleanLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, '--') && $trimmed !== '') {
                    $cleanLines[] = $line;
                }
            }
            $cleanSql = implode("\n", $cleanLines);
            $stmts = array_filter(array_map('trim', explode(';', $cleanSql)));

            foreach ($stmts as $s) {
                if ($s !== '') {
                    $pdo->exec($s);
                }
            }

            DevAudit::log('database', 'run_migration', $fileToRun, ['file' => $fileToRun]);
            dev_set_flash('success', "Migration {$fileToRun} executed successfully after automatic pre-migration backup.");
        } catch (Throwable $e) {
            DevAudit::log('database', 'migration_failed', $fileToRun, ['error' => $e->getMessage()], 'failed');
            dev_set_flash('error', "Migration error: " . $e->getMessage());
        }
    }

    header('Location: ' . dev_url('pages/database/migrations.php'));
    exit;
}

$csrfToken = dev_csrf_token();
dev_header('Migrations Manager', 'migrations');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Database Migrations Manager</h1>
        <div class="page-subtitle">Track, inspect and execute idempotent schema upgrades with automatic pre-migration backups</div>
    </div>
</div>

<div class="card">
    <div class="card-title"><?= icon('git-merge', 'lucide-sm') ?> Available Migration Scripts (database/migrations/)</div>
    <div class="table-wrap">
        <table class="dev-table">
            <thead>
                <tr>
                    <th>Migration File</th>
                    <th>File Size</th>
                    <th>Last Modified</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($migrationFiles as $mf): 
                    $fPath = $migrationsDir . '/' . $mf;
                    $size = filesize($fPath);
                    $mtime = date('Y-m-d H:i:s', filemtime($fPath));
                ?>
                    <tr>
                        <td><code><?= htmlspecialchars($mf) ?></code></td>
                        <td><?= number_format($size / 1024, 2) ?> KB</td>
                        <td><?= $mtime ?></td>
                        <td>
                            <?php if ($dev['role'] === 'owner'): ?>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="migration_file" value="<?= htmlspecialchars($mf) ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" data-confirm="Execute migration <?= htmlspecialchars($mf) ?>? An automatic pre-migration backup will be created first.">
                                        <?= icon('play', 'lucide-xs') ?> Execute
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:0.75rem; color:var(--text-muted);">Owner Role Required</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php dev_footer(); ?>
