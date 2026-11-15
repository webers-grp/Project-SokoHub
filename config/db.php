<?php
/**
 * SokoHub Database Connection (PDO MySQL)
 * Demonstrates secure PDO connection with prepared statements and error handling.
 */

require_once __DIR__ . '/config.php';

/**
 * Returns a shared PDO instance connected to MySQL/MariaDB.
 */
function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = get_db_config();

    if (!empty($cfg['socket'])) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $cfg['socket'],
            $cfg['dbname'],
            $cfg['charset']
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname'],
            $cfg['charset']
        );
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    } catch (PDOException $e) {
        error_log('SokoHub DB connection error: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Please verify config/db.php or config/db.local.php settings.');
    }

    return $pdo;
}

/**
 * Safe connectivity probe used by health.php during early deployment checks.
 */
function test_db_connection(): array
{
    try {
        $pdo = get_db_connection();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return [
            'ok'      => true,
            'version' => $version,
            'tables'  => $tables,
            'error'   => null,
        ];
    } catch (Throwable $e) {
        return [
            'ok'      => false,
            'version' => null,
            'tables'  => [],
            'error'   => $e->getMessage(),
        ];
    }
}
