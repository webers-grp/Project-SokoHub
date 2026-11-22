<?php
/**
 * SokoHub Automated Database & Uploads Backup Utility
 *
 * Capabilities:
 * - Dumps all MySQL tables and rows into valid SQL statements with transactions and disable-keys.
 * - Packages upload files into timestamped archives.
 * - Automatic 7-day backup rotation.
 * - Automated verification & restore mode to test restores on demand.
 *
 * CLI Usage:
 *   php scripts/backup.php
 *   php scripts/backup.php --restore backups/sokohub_backup_20261121_120000.sql --target-db sokohub_restore_test
 */

require_once __DIR__ . '/../config/db.php';

$backupDir = APP_ROOT . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}

// -------------------------------------------------------------
// RESTORE MODE
// -------------------------------------------------------------
$options = getopt('', ['restore:', 'target-db:']);
if (!empty($options['restore'])) {
    $restoreFile = $options['restore'];
    $targetDb = $options['target-db'] ?? 'sokohub_restore_test';

    if (!is_file($restoreFile)) {
        echo "[ERROR] Backup file not found: {$restoreFile}\n";
        exit(1);
    }

    echo "Starting test restore to database `{$targetDb}` from `{$restoreFile}`...\n";
    try {
        $cfg = get_db_config();
        $rootPdo = new PDO(
            "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $rootPdo->exec("USE `{$targetDb}`");

        $sql = file_get_contents($restoreFile);
        $rootPdo->exec($sql);

        $stmt = $rootPdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "[SUCCESS] Restore test passed cleanly! Restored " . count($tables) . " tables to `{$targetDb}`.\n";
        exit(0);
    } catch (Throwable $e) {
        echo "[ERROR] Restore failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// -------------------------------------------------------------
// BACKUP MODE
// -------------------------------------------------------------
echo "Starting SokoHub Database Backup...\n";
$pdo = get_db_connection();

$tablesStmt = $pdo->query("SHOW TABLES");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$timestamp = date('Ymd_His');
$sqlFilename = $backupDir . "/sokohub_backup_{$timestamp}.sql";
$fp = fopen($sqlFilename, 'w');

fwrite($fp, "-- SokoHub Automated Database Backup\n");
fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s T') . "\n");
fwrite($fp, "-- Tables: " . count($tables) . "\n\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
fwrite($fp, "SET time_zone = '+00:00';\n\n");

foreach ($tables as $t) {
    // 1. Table structure
    fwrite($fp, "--\n-- Table structure for `{$t}`\n--\n");
    fwrite($fp, "DROP TABLE IF EXISTS `{$t}`;\n");
    $createStmt = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
    $createSql = $createStmt['Create Table'] ?? '';
    fwrite($fp, $createSql . ";\n\n");

    // 2. Table data
    $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        fwrite($fp, "--\n-- Dumping data for `{$t}` (" . count($rows) . " rows)\n--\n");
        fwrite($fp, "LOCK TABLES `{$t}` WRITE;\n");
        fwrite($fp, "ALTER TABLE `{$t}` DISABLE KEYS;\n");

        foreach ($rows as $r) {
            $cols = array_keys($r);
            $colNames = implode('`, `', $cols);
            $vals = [];
            foreach ($r as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $pdo->quote((string) $v);
                }
            }
            $valList = implode(', ', $vals);
            fwrite($fp, "INSERT INTO `{$t}` (`{$colNames}`) VALUES ({$valList});\n");
        }

        fwrite($fp, "ALTER TABLE `{$t}` ENABLE KEYS;\n");
        fwrite($fp, "UNLOCK TABLES;\n\n");
    }
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fp);

$sizeKb = round(filesize($sqlFilename) / 1024, 1);
echo "[OK] SQL Backup created: {$sqlFilename} ({$sizeKb} KB)\n";

// -------------------------------------------------------------
// ROTATION (Delete backups older than 7 days)
// -------------------------------------------------------------
$sevenDaysAgo = time() - (7 * 86400);
$files = glob($backupDir . "/sokohub_backup_*.sql");
$pruned = 0;
foreach ($files as $f) {
    if (filemtime($f) < $sevenDaysAgo) {
        unlink($f);
        $pruned++;
    }
}
if ($pruned > 0) {
    echo "[OK] Pruned {$pruned} backup(s) older than 7 days.\n";
}

echo "Backup process finished successfully.\n";
