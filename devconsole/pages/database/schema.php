<?php
/**
 * Developer Console: Database Schema Browser
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dbName = getenv('DB_NAME') ?: 'sokohub_db';

// Get all tables with row count and size
$tablesStmt = $pdo->query("
    SELECT 
        table_name AS name,
        engine,
        table_rows AS rows_count,
        ROUND((data_length + index_length) / 1024, 2) AS size_kb,
        table_comment AS comment
    FROM information_schema.tables 
    WHERE table_schema = '{$dbName}'
    ORDER BY table_name ASC
");
$tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

// Selected table details
$selectedTable = $_GET['table'] ?? ($tables[0]['name'] ?? 'users');
$columns = [];
$indexes = [];

if ($selectedTable) {
    // Columns
    $colStmt = $pdo->prepare("
        SELECT 
            column_name AS name,
            column_type AS type,
            is_nullable AS nullable,
            column_default AS default_val,
            column_key AS key_type,
            extra
        FROM information_schema.columns 
        WHERE table_schema = :db AND table_name = :tbl
        ORDER BY ordinal_position ASC
    ");
    $colStmt->execute([':db' => $dbName, ':tbl' => $selectedTable]);
    $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    // Indexes
    $idxStmt = $pdo->prepare("
        SELECT 
            index_name,
            non_unique,
            column_name,
            seq_in_index,
            index_type
        FROM information_schema.statistics
        WHERE table_schema = :db AND table_name = :tbl
        ORDER BY index_name, seq_in_index ASC
    ");
    $idxStmt->execute([':db' => $dbName, ':tbl' => $selectedTable]);
    $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
}

dev_header('Database Schema Browser', 'schema');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Database Schema Browser</h1>
        <div class="page-subtitle">Inspect table structures, column definitions, index distributions, and storage size</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 280px 1fr; gap: 1.25rem;">
    <!-- Tables Sidebar -->
    <div class="card" style="padding: 0.75rem;">
        <div class="card-title" style="padding: 0.5rem 0.5rem 0.25rem;">
            <?= icon('database', 'lucide-xs') ?> Tables (<?= count($tables) ?>)
        </div>
        <div style="max-height: 600px; overflow-y: auto;">
            <?php foreach ($tables as $t): ?>
                <a href="<?= dev_url('pages/database/schema.php?table=' . urlencode($t['name'])) ?>" 
                   style="display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0.65rem; border-radius: 4px; text-decoration: none; font-size: 0.82rem; color: <?= $t['name'] === $selectedTable ? '#93c5fd' : 'var(--text-secondary)' ?>; background: <?= $t['name'] === $selectedTable ? '#1e3a8a' : 'transparent' ?>; margin-bottom: 2px;">
                    <code><?= htmlspecialchars($t['name']) ?></code>
                    <span style="font-size: 0.7rem; color: var(--text-muted);"><?= (int) $t['rows_count'] ?> rows</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Table Details -->
    <div>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h2 style="font-size:1.15rem; color:#fff; display:flex; align-items:center; gap:0.5rem;">
                    <?= icon('table', 'lucide-sm') ?> <code><?= htmlspecialchars($selectedTable) ?></code>
                </h2>
                <div>
                    <a href="<?= dev_url('pages/database/browser.php?table=' . urlencode($selectedTable)) ?>" class="btn btn-secondary btn-sm">
                        <?= icon('eye', 'lucide-xs') ?> Browse Records &rarr;
                    </a>
                </div>
            </div>

            <!-- Columns Table -->
            <div class="table-wrap" style="margin-bottom:1.5rem;">
                <table class="dev-table">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Type</th>
                            <th>Nullable</th>
                            <th>Default</th>
                            <th>Key</th>
                            <th>Extra</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($c['type']) ?></code></td>
                                <td><?= $c['nullable'] === 'YES' ? '<span style="color:var(--text-muted);">NULL</span>' : '<span style="color:#34d399;">NOT NULL</span>' ?></td>
                                <td><?= $c['default_val'] !== null ? '<code>' . htmlspecialchars($c['default_val']) . '</code>' : '<span style="color:var(--text-muted);">None</span>' ?></td>
                                <td>
                                    <?php if ($c['key_type'] === 'PRI'): ?>
                                        <span class="badge badge-warning">PRIMARY</span>
                                    <?php elseif ($c['key_type'] === 'UNI'): ?>
                                        <span class="badge badge-dev">UNIQUE</span>
                                    <?php elseif ($c['key_type'] === 'MUL'): ?>
                                        <span class="badge badge-secondary">INDEX</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($c['extra']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Indexes Table -->
            <div class="card-title"><?= icon('layers', 'lucide-sm') ?> Indexes</div>
            <div class="table-wrap">
                <table class="dev-table">
                    <thead>
                        <tr>
                            <th>Index Name</th>
                            <th>Type</th>
                            <th>Column</th>
                            <th>Seq</th>
                            <th>Unique</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($indexes as $idx): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($idx['index_name']) ?></code></td>
                                <td><?= htmlspecialchars($idx['index_type']) ?></td>
                                <td><strong><?= htmlspecialchars($idx['column_name']) ?></strong></td>
                                <td><?= $idx['seq_in_index'] ?></td>
                                <td><?= $idx['non_unique'] == 0 ? '<span class="badge badge-success">UNIQUE</span>' : 'INDEX' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php dev_footer(); ?>
