<?php
/**
 * Migration 004 Runner: Security Hardening & Performance Indexes
 * Safe, idempotent execution for SokoHub.
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Migration 004: Security Hardening & Performance Indexes...\n";

$pdo = get_db_connection();

// 1. Create rate_limits table
$pdo->exec("
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `rate_key` VARCHAR(128) NOT NULL PRIMARY KEY,
    `action` VARCHAR(32) NOT NULL,
    `identifier` VARCHAR(64) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `reset_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rate_action` (`action`, `reset_at`),
    INDEX `idx_rate_identifier` (`identifier`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] Table `rate_limits` ready.\n";

// 1b. Create login_attempts table (brute force lockout)
$pdo->exec("
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(160) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_attempts_email` (`email`, `attempted_at`),
    INDEX `idx_attempts_ip` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] Table `login_attempts` ready.\n";

// 2. Create account_deletions table (KDPA compliance)
$pdo->exec("
CREATE TABLE IF NOT EXISTS `account_deletions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `email_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('pending', 'processed', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL,
    `notes` TEXT NULL,
    INDEX `idx_del_user` (`user_id`),
    INDEX `idx_del_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "  [OK] Table `account_deletions` ready.\n";

// 3. Helper to add index if not exists
function add_index_if_missing(PDO $pdo, string $table, string $indexName, string $columns): void
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :iname");
    $stmt->execute([':iname' => $indexName]);
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})");
            echo "  [OK] Added index `{$indexName}` on `{$table}`.\n";
        } catch (Throwable $e) {
            echo "  [SKIP] Index `{$indexName}` could not be added: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  [EXISTS] Index `{$indexName}` already present on `{$table}`.\n";
    }
}

// Performance indexes on key tables
add_index_if_missing($pdo, 'products', 'idx_prod_cat_status', 'category_id, status, price');
add_index_if_missing($pdo, 'products', 'idx_prod_seller_status', 'seller_id, status');
add_index_if_missing($pdo, 'orders', 'idx_orders_buyer_status', 'buyer_id, order_status, created_at');
add_index_if_missing($pdo, 'order_items', 'idx_items_order_seller', 'order_id, seller_id');
add_index_if_missing($pdo, 'payments', 'idx_payments_checkout_status', 'checkout_request_id, status');
add_index_if_missing($pdo, 'payouts', 'idx_payouts_user_status', 'user_id, status');
add_index_if_missing($pdo, 'deliveries', 'idx_deliv_person_status', 'delivery_person_id, delivery_status');
add_index_if_missing($pdo, 'audit_logs', 'idx_audit_action_created', 'action, created_at');

echo "Migration 004 completed successfully.\n";
