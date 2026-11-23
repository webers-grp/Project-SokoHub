<?php
/**
 * Developer Console Configuration
 * Isolated from the customer-facing storefront.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';

// Console Environment Settings
define('DEVCONSOLE_ENABLED', filter_var(getenv('DEVCONSOLE_ENABLED') !== false ? getenv('DEVCONSOLE_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN));
define('DEVCONSOLE_ALLOWED_IPS', trim((string) (getenv('DEVCONSOLE_ALLOWED_IPS') ?: '')));
define('DEVCONSOLE_ALLOW_PROD_WRITES', filter_var(getenv('DEVCONSOLE_ALLOW_PROD_WRITES') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// Anti-Indexing & Security Headers
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Check Console Enabled Switch
if (!DEVCONSOLE_ENABLED) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;padding:3rem;text-align:center;">'
       . '<h2>403 Forbidden</h2><p>Developer Console is administratively disabled.</p></body></html>';
    exit;
}

// Check IP Allowlist if configured
if (DEVCONSOLE_ALLOWED_IPS !== '') {
    $allowedList = array_map('trim', explode(',', DEVCONSOLE_ALLOWED_IPS));
    $clientIp = Security::getClientIp();
    if (!in_array($clientIp, $allowedList, true) && !in_array('127.0.0.1', $allowedList, true) && $clientIp !== '127.0.0.1') {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;padding:3rem;text-align:center;">'
           . '<h2>403 Forbidden</h2><p>Access from your IP address is not permitted.</p></body></html>';
        exit;
    }
}

/**
 * Get base URL for Developer Console
 */
function dev_url(string $path = ''): string
{
    $clean = ltrim($path, '/');
    $base = app_base_path();
    return ($base !== '' ? $base : '') . '/devconsole' . ($clean !== '' ? '/' . $clean : '');
}

/**
 * Validates whether a SQL query is a safe read-only statement.
 */
function dev_query_is_safe_read(string $sql): bool
{
    $cleanSql = trim($sql);
    $firstWord = strtoupper(strtok($cleanSql, " \t\n\r"));
    $isSelect = in_array($firstWord, ['SELECT', 'EXPLAIN', 'DESCRIBE', 'SHOW'], true);
    if (!$isSelect) {
        return false;
    }

    $disallowed = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];
    foreach ($disallowed as $kw) {
        if (preg_match('/\b' . $kw . '\b/i', $cleanSql)) {
            return false;
        }
    }
    return true;
}
