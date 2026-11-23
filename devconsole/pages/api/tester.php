<?php
/**
 * Developer Console: Internal API Endpoint Tester
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

dev_require_auth();

$endpoint = trim((string) ($_GET['endpoint'] ?? '/pages/api/search_suggest.php?q=gas'));
$method = strtoupper($_GET['method'] ?? 'GET');
$responseBody = null;
$responseCode = 0;
$execMs = 0;

if (isset($_GET['run'])) {
    $cleanPath = '/' . ltrim($endpoint, '/');
    $port = $_SERVER['SERVER_PORT'] ?? '8088';
    $host = '127.0.0.1';
    $url = "http://{$host}:{$port}{$cleanPath}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $start = microtime(true);
    $responseBody = curl_exec($ch);
    $execMs = round((microtime(true) - $start) * 1000, 2);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    DevAudit::log('api', 'endpoint_tested', $endpoint, ['code' => $responseCode, 'ms' => $execMs]);
}

dev_header('Internal API Request Tester', 'api_tester');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Internal API Request Tester</h1>
        <div class="page-subtitle">Send authenticated requests against local API endpoints and inspect JSON structures</div>
    </div>
</div>

<div class="card">
    <form method="GET" action="" style="display: flex; gap: 0.75rem;">
        <input type="hidden" name="run" value="1">
        <select name="method" class="form-select" style="width: 120px;">
            <option value="GET" <?= $method === 'GET' ? 'selected' : '' ?>>GET</option>
            <option value="POST" <?= $method === 'POST' ? 'selected' : '' ?>>POST</option>
        </select>
        <input type="text" name="endpoint" class="form-input" value="<?= htmlspecialchars($endpoint) ?>" placeholder="/pages/api/search_suggest.php?q=..." style="flex: 1; font-family: var(--font-mono);">
        <button type="submit" class="btn btn-primary"><?= icon('send', 'lucide-xs') ?> Send Request</button>
    </form>
</div>

<?php if ($responseBody !== null): ?>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <div class="card-title" style="margin-bottom:0;">
                Response: <span class="badge <?= $responseCode >= 200 && $responseCode < 300 ? 'badge-success' : 'badge-danger' ?>">HTTP <?= $responseCode ?></span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">
                Latency: <strong><?= $execMs ?> ms</strong> &bull; Size: <strong><?= strlen($responseBody) ?> bytes</strong>
            </div>
        </div>
        <pre class="code-block"><?php
            $decoded = json_decode($responseBody, true);
            if ($decoded !== null) {
                echo htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                echo htmlspecialchars($responseBody);
            }
        ?></pre>
    </div>
<?php endif; ?>

<?php dev_footer(); ?>
