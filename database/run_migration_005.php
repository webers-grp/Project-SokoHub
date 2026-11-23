<?php
/**
 * Migration 005 Runner: Developer Console, 2FA, Append-Only Logs & System Settings
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Migration 005: Developer Console Setup...\n";

$pdo = get_db_connection();

$sqlFile = __DIR__ . '/migrations/005_developer_console.sql';
if (!file_exists($sqlFile)) {
    die("Migration file 005_developer_console.sql not found!\n");
}

$sql = file_get_contents($sqlFile);
$lines = explode("\n", $sql);
$cleanLines = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if (!str_starts_with($trimmed, '--') && $trimmed !== '') {
        $cleanLines[] = $line;
    }
}
$cleanSql = implode("\n", $cleanLines);
$statements = array_filter(array_map('trim', explode(';', $cleanSql)));

foreach ($statements as $stmt) {
    if ($stmt !== '') {
        $pdo->exec($stmt);
    }
}

echo "  [OK] Developer console tables ready (`developers`, `devconsole_audit_logs`, `system_settings`).\n";
echo "Migration 005 finished successfully.\n";
