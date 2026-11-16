-- =============================================================================
-- Migration 002: M-Pesa Integration, Order Cancellation & Stock Restore, Refunds
-- Course: SPI 2301 - SokoHub Marketplace Project
-- Developer: Abel
-- =============================================================================

CREATE DATABASE IF NOT EXISTS sokohub_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sokohub_db;

-- 1. Ensure orders has cancellation columns
SET @dbname = DATABASE();
SET @tablename = "orders";
SET @columnname = "cancel_reason";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL, ADD COLUMN cancelled_at DATETIME NULL;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Payments table (M-Pesa STK push attempts, receipts, transaction status)
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    method ENUM('mpesa', 'cash_on_delivery', 'bank_card') NOT NULL DEFAULT 'mpesa',
    amount DECIMAL(10, 2) NOT NULL,
    status ENUM('initiated', 'pending', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'initiated',
    phone VARCHAR(20) NULL,
    merchant_request_id VARCHAR(64) NULL,
    checkout_request_id VARCHAR(64) NULL,
    mpesa_receipt VARCHAR(30) NULL,
    result_code INT NULL,
    result_desc VARCHAR(255) NULL,
    attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_status (status, created_at),
    INDEX idx_payments_checkout (checkout_request_id),
    CONSTRAINT fk_payment_order_m2 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Raw M-Pesa callbacks audit table with SHA256 deduplication
CREATE TABLE IF NOT EXISTS mpesa_callbacks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(64) NULL,
    result_code INT NULL,
    mpesa_receipt VARCHAR(30) NULL,
    payload JSON NOT NULL,
    dedupe_key CHAR(64) NOT NULL UNIQUE,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    INDEX idx_callbacks_checkout (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Refunds management table
CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NULL,
    amount DECIMAL(10, 2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('requested', 'approved', 'rejected', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'requested',
    requested_by INT UNSIGNED NOT NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    provider_reference VARCHAR(64) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_refunds_order (order_id),
    CONSTRAINT fk_refund_order_m2 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_refund_requester_m2 FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Stock movements ledger (audits every decrement and restored stock)
CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    change_qty INT NOT NULL,
    reason ENUM('reserve', 'release', 'adjustment', 'import') NOT NULL,
    balance_after INT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_product (product_id, created_at),
    INDEX idx_stock_order (order_id),
    CONSTRAINT fk_stock_product_m2 FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Order status history audit table
CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    changed_by INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_order (order_id, created_at),
    CONSTRAINT fk_history_order_m2 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
