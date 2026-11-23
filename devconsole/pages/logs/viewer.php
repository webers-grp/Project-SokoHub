<?php
/**
 * Developer Console: System Log Viewer
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$logsDir = APP_ROOT . '/logs';
$logFiles = ['app_error.log', 'telephony.log'];
if (is_dir($logsDir)) {
    foreach (scandir($logsDir) as $f) {
        if (str_ends_with($f, '.log') && !in_array($f, $logFiles, true)) {
            $logFiles[] = $f;
        }
    }
}

$selectedLog = $_GET['file'] ?? 'app_error.log';
if (!in_array($selectedLog, $logFiles, true)) {
    $selectedLog = $logFiles[0] ?? 'app_error.log';
}

$search = trim((string) ($_GET['search'] ?? ''));
$fullPath = $logsDir . '/' . $selectedLog;

$lines = [];
if (file_exists($fullPath)) {
    $allLines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($search !== '') {
        $allLines = array_filter($allLines, fn($l) => stripos($l, $search) !== false);
    }
    // Tail last 150 lines
    $lines = array_slice($allLines, -150);
}

// Download Handler
if (isset($_GET['download']) && file_exists($fullPath)) {
    DevAudit::log('logs', 'log_downloaded', $selectedLog, []);
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $selectedLog . '"');
    readfile($fullPath);
    exit;
}

dev_header('System &amp; Event Log Viewer', 'logs');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">System &amp; Event Log Viewer</h1>
        <div class="page-subtitle">Inspect server error logs, payments, telephony, and audit streams with real-time tailing</div>
    </div>
    <div>
        <?php if (file_exists($fullPath)): ?>
            <a href="<?= dev_url('pages/logs/viewer.php?file=' . urlencode($selectedLog) . '&download=1') ?>" class="btn btn-secondary btn-sm">
                <?= icon('download', 'lucide-xs') ?> Download Full Log
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <form method="GET" action="" style="display: flex; gap: 0.75rem; align-items: center; flex: 1;">
        <label style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600;">Log File:</label>
        <select name="file" class="form-select" onchange="this.form.submit()" style="width: 200px;">
            <?php foreach ($logFiles as $lf): ?>
                <option value="<?= htmlspecialchars($lf) ?>" <?= $lf === $selectedLog ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lf) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="search" class="form-input" value="<?= htmlspecialchars($search) ?>" placeholder="Filter lines by keyword..." style="max-width: 320px;">
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('search', 'lucide-xs') ?> Filter</button>
        <?php if ($search !== ''): ?>
            <a href="<?= dev_url('pages/logs/viewer.php?file=' . urlencode($selectedLog)) ?>" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div style="font-size: 0.8rem; color: var(--text-muted);">
        Showing last <strong><?= count($lines) ?></strong> lines (File size: <?= file_exists($fullPath) ? number_format(filesize($fullPath) / 1024, 1) . ' KB' : '0 KB' ?>)
    </div>
</div>

<div class="card" style="padding: 0;">
    <pre class="code-block" style="border: none; max-height: 600px; padding: 1.25rem; font-size: 0.78rem; line-height: 1.5;"><?php
        if (empty($lines)) {
            echo '<span style="color:var(--text-muted);">Log file is empty or no lines matched the search filter.</span>';
        } else {
            foreach ($lines as $line) {
                $color = '#94a3b8';
                if (str_contains($line, 'Fatal') || str_contains($line, 'Error') || str_contains($line, 'error')) $color = '#f87171';
                elseif (str_contains($line, 'Warning') || str_contains($line, 'warning')) $color = '#f59e0b';
                elseif (str_contains($line, 'Notice') || str_contains($line, 'info')) $color = '#38bdf8';
                echo '<span style="color:' . $color . ';">' . htmlspecialchars($line) . '</span>' . "\n";
            }
        }
    ?></pre>
</div>

<?php dev_footer(); ?>
