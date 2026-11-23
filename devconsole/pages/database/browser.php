<?php
/**
 * Developer Console: Read-Only Data Browser
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dbName = getenv('DB_NAME') ?: 'sokohub_db';

// Get list of tables
$tablesStmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' ORDER BY table_name ASC");
$allTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$table = $_GET['table'] ?? ($allTables[0] ?? 'users');
if (!in_array($table, $allTables, true)) {
    $table = $allTables[0] ?? 'users';
}

$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$search = trim((string) ($_GET['search'] ?? ''));

// Get primary key / first column
$colsStmt = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl ORDER BY ordinal_position ASC");
$colsStmt->execute([':db' => $dbName, ':tbl' => $table]);
$columns = $colsStmt->fetchAll(PDO::FETCH_ASSOC);

// Total Count
$countSql = "SELECT COUNT(*) FROM `{$table}`";
$countStmt = $pdo->query($countSql);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

// Fetch Records
$dataSql = "SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT {$limit} OFFSET {$offset}";
$dataStmt = $pdo->query($dataSql);
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

function mask_table_cell(string $colName, mixed $val): string
{
    if ($val === null) {
        return '<span style="color:var(--text-muted);">NULL</span>';
    }
    $col = strtolower($colName);
    if (str_contains($col, 'password') || str_contains($col, 'totp') || str_contains($col, 'token_hash') || str_contains($col, 'secret') || str_contains($col, 'passkey')) {
        return '<span style="color:#f59e0b; font-family:var(--font-mono);">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>';
    }
    if (str_contains($col, 'phone') && strlen((string)$val) >= 9) {
        return '<code>' . htmlspecialchars(Security::maskPhone((string)$val)) . '</code>';
    }
    if (str_contains($col, 'national_id') && strlen((string)$val) >= 4) {
        return '<code>' . htmlspecialchars(Security::maskNationalId((string)$val)) . '</code>';
    }
    return htmlspecialchars((string) $val);
}

dev_header('Read-Only Data Browser', 'browser');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Read-Only Data Browser</h1>
        <div class="page-subtitle">Inspect table records safely with automatic PII and credential masking</div>
    </div>
</div>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <form method="GET" action="" style="display: flex; gap: 0.5rem; align-items: center;">
        <label style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600;">Table:</label>
        <select name="table" class="form-select" onchange="this.form.submit()" style="width: 220px;">
            <?php foreach ($allTables as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $t === $table ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div style="font-size: 0.85rem; color: var(--text-secondary);">
        Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalRows) ?> records)
    </div>
</div>

<div class="card" style="padding: 0;">
    <div class="table-wrap" style="border: none;">
        <table class="dev-table">
            <thead>
                <tr>
                    <?php foreach ($columns as $c): ?>
                        <th><?= htmlspecialchars($c['column_name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= count($columns) ?>" style="text-align:center; padding:1.5rem; color:var(--text-muted);">No records in this table.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $c): ?>
                                <td><?= mask_table_cell($c['column_name'], $row[$c['column_name']] ?? null) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; border-top: 1px solid var(--border-color);">
            <div>
                <?php if ($page > 1): ?>
                    <a href="<?= dev_url('pages/database/browser.php?table=' . urlencode($table) . '&p=' . ($page - 1)) ?>" class="btn btn-secondary btn-sm">
                        &larr; Previous
                    </a>
                <?php endif; ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">
                Page <?= $page ?> / <?= $totalPages ?>
            </div>
            <div>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= dev_url('pages/database/browser.php?table=' . urlencode($table) . '&p=' . ($page + 1)) ?>" class="btn btn-secondary btn-sm">
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php dev_footer(); ?>
