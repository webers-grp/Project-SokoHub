<?php
/**
 * SokoHub Global Configuration
 * Supports XAMPP local development, InfinityFree/000webhost via db.local.php,
 * and Render/Docker via environment variables.
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', 'SokoHub');
    define('APP_TAGLINE', 'Local Digital Marketplace & Delivery Hub');
    define('APP_CURRENCY', 'KSh');
    define('APP_ROOT', str_replace('\\', '/', (string) realpath(__DIR__ . '/..')));
    define('UPLOAD_DIR', APP_ROOT . '/assets/img/uploads');
    define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2 MB
}

/**
 * Load environment variables from .env file into PHP environment.
 */
function load_env_file(?string $path = null): void
{
    static $loaded = false;
    if ($loaded && $path === null) {
        return;
    }

    $file = $path ?: (APP_ROOT . '/.env');
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);

        // Strip inline comments: KEY=val # comment
        if (preg_match('/^(.*?)\s+#.*$/', $val, $matches)) {
            $val = trim($matches[1]);
        }

        // Unquote strings
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }

        if (getenv($key) === false || getenv($key) === '') {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }

    $loaded = true;
}

load_env_file();

// Application Environment & Timezone
date_default_timezone_set('Africa/Nairobi');

$appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: 'development')));
if (!defined('APP_ENV')) {
    define('APP_ENV', $appEnv);
    define('IS_PRODUCTION', $appEnv === 'production');
}

// Error handling based on environment
if (IS_PRODUCTION) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', '1');
    $logDir = APP_ROOT . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    ini_set('error_log', $logDir . '/app_error.log');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

/**
 * Automatically detect base URL prefix whether hosted at domain root
 * (InfinityFree, PHP built-in server, Render) or in a subfolder (XAMPP htdocs/Project-SokoHub).
 */
function app_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_FILENAME']) || empty($_SERVER['SCRIPT_NAME'])) {
        $basePath = '';
        return $basePath;
    }

    $scriptFile = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME']));
    $scriptName = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);

    if ($scriptFile !== '' && str_starts_with($scriptFile, APP_ROOT . '/')) {
        $relScript = substr($scriptFile, strlen(APP_ROOT));
        if (str_ends_with($scriptName, $relScript)) {
            $prefix = substr($scriptName, 0, strlen($scriptName) - strlen($relScript));
            $basePath = rtrim($prefix, '/');
            return $basePath;
        }
    }

    $basePath = '';
    return $basePath;
}

/**
 * Build an application-root-relative URL safe from any nested directory.
 */
function url(string $path = ''): string
{
    if (preg_match('#^(https?://|/)#i', $path)) {
        return $path;
    }

    $clean = ltrim($path, '/');
    $base = app_base_path();
    return ($base !== '' ? $base : '') . ($clean !== '' ? '/' . $clean : '/');
}

/**
 * Resolve database configuration in priority order:
 * 1. Local override file config/db.local.php (recommended for InfinityFree / 000webhost)
 * 2. Environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SOCKET)
 * 3. Default XAMPP local settings
 */
function get_db_config(): array
{
    $config = [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'dbname'  => getenv('DB_NAME') ?: 'sokohub_db',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'socket'  => getenv('DB_SOCKET') ?: '',
        'charset' => 'utf8mb4',
    ];

    $localFile = __DIR__ . '/db.local.php';
    if (is_file($localFile)) {
        $override = require $localFile;
        if (is_array($override)) {
            $config = array_merge($config, $override);
        }
    }

    return $config;
}
