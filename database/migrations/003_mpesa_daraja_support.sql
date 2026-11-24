-- ============================================================================
-- SokoHub Migration 003: M-Pesa Daraja STK Push, Till Registration & Refunds
-- ============================================================================

-- 1. Ensure campus_landmark on orders
ALTER TABLE orders 
    ADD COLUMN IF NOT EXISTS campus_landmark VARCHAR(120) NULL AFTER delivery_town;

-- 2. Ensure till_number, payout_type, and is_open on seller_profiles
ALTER TABLE seller_profiles 
    ADD COLUMN IF NOT EXISTS till_number VARCHAR(30) NOT NULL DEFAULT '1648753' AFTER town,
    ADD COLUMN IF NOT EXISTS payout_type ENUM('till', 'paybill', 'pochi', 'phone') NOT NULL DEFAULT 'till' AFTER till_number,
    ADD COLUMN IF NOT EXISTS is_open TINYINT(1) NOT NULL DEFAULT 1 AFTER description;

-- 3. Ensure platform_fee and seller_earnings on order_items
ALTER TABLE order_items 
    ADD COLUMN IF NOT EXISTS platform_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER line_total,
    ADD COLUMN IF NOT EXISTS seller_earnings DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER platform_fee;

-- 3b. Ensure payments table has M-Pesa STK push and webhook tracking columns
ALTER TABLE payments 
    ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER buyer_phone,
    ADD COLUMN IF NOT EXISTS checkout_request_id VARCHAR(64) NULL AFTER mpesa_checkout_id,
    ADD COLUMN IF NOT EXISTS merchant_request_id VARCHAR(64) NULL AFTER checkout_request_id,
    ADD COLUMN IF NOT EXISTS result_code INT NULL AFTER mpesa_receipt,
    ADD COLUMN IF NOT EXISTS result_desc VARCHAR(255) NULL AFTER result_code,
    ADD COLUMN IF NOT EXISTS raw_callback LONGTEXT NULL AFTER failure_reason;

-- 4. Order Refunds Table
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
    INDEX idx_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Stock Movements Ledger Table
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
    INDEX idx_stock_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Order Status History Audit Table
CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    changed_by INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. M-Pesa Webhook Callbacks Table
CREATE TABLE IF NOT EXISTS mpesa_callbacks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(64) NULL,
    merchant_request_id VARCHAR(64) NULL,
    result_code INT NULL,
    result_desc VARCHAR(255) NULL,
    amount DECIMAL(10, 2) NULL,
    mpesa_receipt_number VARCHAR(32) NULL,
    transaction_date VARCHAR(20) NULL,
    phone_number VARCHAR(20) NULL,
    raw_payload LONGTEXT NULL,
    payload_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crid (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
