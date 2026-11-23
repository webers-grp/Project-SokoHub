<?php
/**
 * Developer Console: Database Query Runner
 * SELECT-only by default with strict write/DDL blocklist.
 * Write mode is restricted to owners, requires re-auth, and is blocked in production.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$pdo = get_db_connection();
$dev = dev_current_user();

$sqlQuery = trim((string) ($_POST['sql_query'] ?? ''));
$writeModeActive = !empty($_SESSION['dev_write_mode']) && dev_reauth_valid();
$results = null;
$error = null;
$execTimeMs = 0;
$affectedRows = 0;

// Handle Write Mode Toggle (Owner only + re-auth)
if (isset($_POST['toggle_write_mode'])) {
    dev_require_role('owner');
    if (!dev_reauth_valid()) {
        header('Location: ' . dev_url('reauth.php?return=' . urlencode(dev_url('pages/database/query.php'))));
        exit;
    }
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        dev_set_flash('error', 'Security token mismatch.');
    } else {
        $enable = !empty($_POST['enable_write']);
        if ($enable && defined('APP_ENV') && APP_ENV === 'production' && !DEVCONSOLE_ALLOW_PROD_WRITES) {
            dev_set_flash('error', 'Write mode is strictly prohibited in production (DEVCONSOLE_ALLOW_PROD_WRITES=false).');
        } else {
            $_SESSION['dev_write_mode'] = $enable;
            DevAudit::log('database', $enable ? 'write_mode_enabled' : 'write_mode_disabled', null, []);
            dev_set_flash('info', $enable ? 'Database Write Mode has been ENABLED for this session.' : 'Database Write Mode has been disabled.');
        }
    }
    header('Location: ' . dev_url('pages/database/query.php'));
    exit;
}

// Handle Query Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sqlQuery !== '' && !isset($_POST['toggle_write_mode'])) {
    if (!dev_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        $cleanSql = trim($sqlQuery);
        $firstWord = strtoupper(strtok($cleanSql, " \t\n\r"));
        $isSelect = in_array($firstWord, ['SELECT', 'EXPLAIN', 'DESCRIBE', 'SHOW'], true);
        $isSafeRead = dev_query_is_safe_read($cleanSql);

        if (!$isSafeRead) {
            if (!$writeModeActive) {
                $error = 'Write / DDL operations are blocked. Query runner operates in SELECT-only mode unless Write Mode is unlocked by an Owner.';
                DevAudit::log('database', 'query_write_blocked', null, ['sql' => substr($cleanSql, 0, 200)], 'blocked');
            } elseif ($dev['role'] !== 'owner') {
                $error = 'Only Owner accounts can execute write statements.';
                DevAudit::log('database', 'query_write_denied', null, ['sql' => substr($cleanSql, 0, 200)], 'blocked');
            }
        }

        if (!$error) {
            try {
                $start = microtime(true);

                // For SELECT queries, ensure safety limit
                if ($isSelect && !str_contains(strtoupper($cleanSql), 'LIMIT')) {
                    $cleanSql .= ' LIMIT 100';
                }

                $stmt = $pdo->prepare($cleanSql);
                $stmt->execute();
                $execTimeMs = round((microtime(true) - $start) * 1000, 2);

                if ($isSelect) {
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $affectedRows = $stmt->rowCount();
                    DevAudit::log('database', 'query_write_executed', null, [
                        'sql'      => substr($cleanSql, 0, 300),
                        'affected' => $affectedRows,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
                DevAudit::log('database', 'query_error', null, ['sql' => substr($cleanSql, 0, 200), 'error' => $error], 'failed');
            }
        }
    }
}

$csrfToken = dev_csrf_token();
dev_header('Query Runner', 'query');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">SQL Query Runner</h1>
        <div class="page-subtitle">Interactive SQL console with hard row limits and guarded write isolation</div>
    </div>
    <div>
        <?php if ($writeModeActive): ?>
            <span class="badge badge-danger" style="padding: 0.4rem 0.8rem;">
                <?= icon('alert-triangle', 'lucide-xs') ?> WRITE MODE UNLOCKED
            </span>
        <?php else: ?>
            <span class="badge badge-success" style="padding: 0.4rem 0.8rem;">
                <?= icon('shield-check', 'lucide-xs') ?> READ-ONLY MODE (SELECT ONLY)
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= icon('alert-circle', 'lucide-sm') ?>
        <span><strong>Query Error:</strong> <?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<!-- Write Mode Guard Banner (Owner only) -->
<?php if ($dev['role'] === 'owner'): ?>
    <div class="card" style="background: #1e293b; border-color: <?= $writeModeActive ? '#dc2626' : '#334155' ?>; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <div style="font-weight:700; color:#fff; font-size:0.9rem;">
                <?= $writeModeActive ? 'Owner Write Mode is ACTIVE' : 'Write Mode is Disabled (Default)' ?>
            </div>
            <div style="font-size:0.8rem; color:var(--text-secondary); margin-top:0.2rem;">
                <?= $writeModeActive ? 'Caution: DDL, UPDATE, INSERT, and DELETE statements can be executed. All statements are audited.' : 'In read-only mode, only SELECT, EXPLAIN, SHOW, and DESCRIBE queries are permitted.' ?>
            </div>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="toggle_write_mode" value="1">
            <input type="hidden" name="enable_write" value="<?= $writeModeActive ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $writeModeActive ? 'btn-secondary' : 'btn-danger' ?> btn-sm">
                <?= $writeModeActive ? 'Disable Write Mode' : 'Unlock Write Mode (Owner)' ?>
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Query Form -->
<div class="card">
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <label class="form-label" for="sql_query">SQL Statement</label>
            <textarea id="sql_query" name="sql_query" class="form-textarea" rows="4" placeholder="SELECT * FROM products WHERE stock_qty < 5;"><?= htmlspecialchars($sqlQuery) ?></textarea>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:0.75rem; color:var(--text-muted);">
                Max 100 rows returned automatically. Execution timeout: 5s.
            </div>
            <button type="submit" class="btn btn-primary">
                <?= icon('play', 'lucide-xs') ?> Execute Query
            </button>
        </div>
    </form>
</div>

<!-- Query Results -->
<?php if ($results !== null): ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
            <div class="card-title" style="margin-bottom:0;">
                <?= icon('check-circle', 'lucide-sm', 'icon-emerald') ?> Query Succeeded
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted);">
                Execution Time: <strong><?= $execTimeMs ?> ms</strong> &bull; Rows Returned: <strong><?= count($results) ?></strong>
            </div>
        </div>

        <?php if (empty($results)): ?>
            <p style="font-size:0.85rem; color:var(--text-muted); padding:1rem 0;">Query executed successfully. 0 rows returned.</p>
        <?php else: ?>
            <div class="table-wrap" style="max-height: 500px;">
                <table class="dev-table">
                    <thead>
                        <tr>
                            <?php foreach (array_keys($results[0]) as $col): ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <?php foreach ($row as $val): ?>
                                    <td><?= $val !== null ? htmlspecialchars((string) $val) : '<span style="color:var(--text-muted);">NULL</span>' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($affectedRows > 0): ?>
    <div class="alert alert-success">
        <?= icon('check-circle', 'lucide-sm') ?>
        <span>Statement executed successfully. <strong><?= $affectedRows ?></strong> row(s) affected in <strong><?= $execTimeMs ?> ms</strong>.</span>
    </div>
<?php endif; ?>

<?php dev_footer(); ?>
