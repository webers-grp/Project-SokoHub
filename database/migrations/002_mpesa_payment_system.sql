-- =============================================================================
-- Migration 002: Safaricom Daraja M-Pesa Integration, Payments, and Settlement Ledger
-- SokoHub E-Commerce Marketplace
-- =============================================================================

USE sokohub_db;

-- 1. Update orders table: enforce M-Pesa & Cash on Delivery only, support pending_cod and out_for_delivery
ALTER TABLE orders 
    MODIFY COLUMN payment_method ENUM('M-Pesa', 'Cash on Delivery') NOT NULL DEFAULT 'M-Pesa',
    MODIFY COLUMN payment_status ENUM('unpaid', 'pending_cod', 'paid', 'refunded', 'failed') NOT NULL DEFAULT 'unpaid',
    MODIFY COLUMN order_status ENUM('pending', 'confirmed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending';

-- Add cancellation and delivery fee fields if not existing
SET @dbname = DATABASE();
SET @tablename = "orders";
SET @col = "cancel_reason";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL, ADD COLUMN cancelled_at DATETIME NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = "delivery_fee";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 150.00;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Update deliveries table: cash collection and payout tracking
ALTER TABLE deliveries
    MODIFY COLUMN delivery_status ENUM('pending', 'assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed') NOT NULL DEFAULT 'pending';

SET @tablename = "deliveries";
SET @col = "cash_collected";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE deliveries ADD COLUMN cash_collected TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN cash_collected_at DATETIME NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = "delivery_fee_paid";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE deliveries ADD COLUMN delivery_fee_paid TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN delivery_payout_id INT UNSIGNED NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Update seller_profiles table: payout destination and COD balance due
SET @tablename = "seller_profiles";
SET @col = "payout_type";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE seller_profiles ADD COLUMN payout_type ENUM('till', 'paybill', 'pochi', 'phone') NULL, ADD COLUMN payout_account VARCHAR(60) NULL, ADD COLUMN payout_account_reference VARCHAR(60) NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = "cod_balance_due";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE seller_profiles ADD COLUMN cod_balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Update delivery_profiles table: M-Pesa payout phone & admin approval
SET @tablename = "delivery_profiles";
SET @col = "mpesa_phone";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE delivery_profiles ADD COLUMN mpesa_phone VARCHAR(20) NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = "approval_status";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE delivery_profiles ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved';"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Customer Payments table (STK Push transactions, receipts, status)
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    method ENUM('mpesa', 'cash_on_delivery') NOT NULL DEFAULT 'mpesa',
    amount DECIMAL(10, 2) NOT NULL,
    phone VARCHAR(20) NULL,
    status ENUM('pending', 'success', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'pending',
    checkout_request_id VARCHAR(64) NULL,
    merchant_request_id VARCHAR(64) NULL,
    mpesa_receipt VARCHAR(30) NULL,
    result_code INT NULL,
    result_desc VARCHAR(255) NULL,
    raw_callback LONGTEXT NULL,
    attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_status (status),
    INDEX idx_payments_checkout (checkout_request_id),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Settlement & Payouts Ledger table
CREATE TABLE IF NOT EXISTS payouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    recipient_type ENUM('seller', 'delivery') NOT NULL DEFAULT 'seller',
    user_id INT UNSIGNED NOT NULL,
    payout_destination_type ENUM('till', 'paybill', 'pochi', 'phone') NOT NULL DEFAULT 'phone',
    payout_account VARCHAR(60) NOT NULL,
    payout_account_reference VARCHAR(60) NULL,
    gross_amount DECIMAL(10, 2) NOT NULL,
    platform_fee_percent DECIMAL(5, 2) NOT NULL DEFAULT 2.00,
    platform_fee_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    cod_fee_deducted DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(10, 2) NOT NULL,
    payout_method ENUM('b2c', 'b2b_buygoods', 'b2b_paybill', 'manual', 'cod_settled') NOT NULL DEFAULT 'b2c',
    status ENUM('pending', 'processing', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    conversation_id VARCHAR(64) NULL,
    originator_conversation_id VARCHAR(64) NULL,
    transaction_id VARCHAR(64) NULL,
    error_message VARCHAR(255) NULL,
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payouts_order (order_id),
    INDEX idx_payouts_user (user_id),
    INDEX idx_payouts_status (status),
    CONSTRAINT fk_payout_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Raw M-Pesa Callbacks Audit Table (Guarantees deduplication & replay protection)
CREATE TABLE IF NOT EXISTS mpesa_callbacks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    callback_type VARCHAR(30) NOT NULL DEFAULT 'stk',
    request_id VARCHAR(64) NULL,
    payload_hash CHAR(64) NOT NULL UNIQUE,
    payload LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_callbacks_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
