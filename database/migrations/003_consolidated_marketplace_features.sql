-- =============================================================================
-- Migration 003: Consolidated Marketplace Features
-- Reviews, Wishlists, Coupons, Dynamic Delivery Zones, Saved Addresses,
-- Order Status History, Password Reset Tokens, KYC Verification, Audit Logs,
-- Telephony Logs, Notifications, Bulk Upload, and Stock Movements.
-- =============================================================================

USE sokohub_db;

-- 1. Universal Audit Trail
CREATE TABLE IF NOT EXISTS audit_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    actor_role    VARCHAR(20) NULL,
    action        VARCHAR(80) NOT NULL,
    entity_type   VARCHAR(40) NULL,
    entity_id     VARCHAR(40) NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    details       JSON NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Stock Movements Ledger
CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    change_qty INT NOT NULL,
    reason ENUM('reserve', 'release', 'adjustment', 'import', 'refund') NOT NULL,
    balance_after INT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_product (product_id, created_at),
    INDEX idx_stock_order (order_id),
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Order Status Milestones History
CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    changed_by INT UNSIGNED NULL,
    location_note VARCHAR(150) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_osh_order (order_id),
    CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    INDEX idx_refunds_order (order_id),
    CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. KYC Documents
CREATE TABLE IF NOT EXISTS kyc_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('seller', 'delivery') NOT NULL,
    document_type ENUM('national_id', 'business_permit', 'driving_license', 'kra_pin') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_original_name VARCHAR(200) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(255) NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kyc_user (user_id, status),
    CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bulk Imports
CREATE TABLE IF NOT EXISTS bulk_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id INT UNSIGNED NOT NULL,
    original_filename VARCHAR(200) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    status ENUM('uploaded', 'validating', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'uploaded',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
    summary_report TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bi_seller (seller_user_id),
    CONSTRAINT fk_bi_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bulk_import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id INT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    status ENUM('pending', 'success', 'error') NOT NULL DEFAULT 'pending',
    product_id INT UNSIGNED NULL,
    raw_data JSON NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bir_import (import_id),
    CONSTRAINT fk_bir_import FOREIGN KEY (import_id) REFERENCES bulk_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. In-App Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    data JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Telephony Logs (SMS & Calls)
CREATE TABLE IF NOT EXISTS telephony_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    channel ENUM('sms', 'call') NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('queued', 'sent', 'failed', 'delivered') NOT NULL DEFAULT 'sent',
    provider_response TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_telephony_user (user_id, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Product Ratings & Customer Reviews
CREATE TABLE IF NOT EXISTS reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_title VARCHAR(120) NULL,
    review_text TEXT NOT NULL,
    status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_buyer_product_review (product_id, buyer_id),
    INDEX idx_reviews_product (product_id, status),
    CONSTRAINT fk_rev_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Buyer Wishlist / Saved Items
CREATE TABLE IF NOT EXISTS wishlists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_product_wishlist (user_id, product_id),
    INDEX idx_wishlist_user (user_id),
    CONSTRAINT fk_wish_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Promotional Discount Coupons
CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    discount_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(10, 2) NOT NULL,
    min_order_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    max_discount_amount DECIMAL(10, 2) NULL,
    seller_id INT UNSIGNED NULL,
    usage_limit INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_coupon_code (code, is_active),
    CONSTRAINT fk_coupon_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_usages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    discount_amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cu_order (order_id),
    CONSTRAINT fk_cu_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Dynamic Delivery Zones & Town Rates
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    town_name VARCHAR(100) NOT NULL UNIQUE,
    zone_name VARCHAR(100) NOT NULL,
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 150.00,
    estimated_hours INT UNSIGNED NOT NULL DEFAULT 2,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default delivery zones for Kirinyaga County
INSERT IGNORE INTO delivery_zones (town_name, zone_name, delivery_fee, estimated_hours) VALUES
('Kutus', 'Zone A - Central Hub', 100.00, 1),
('Kerugoya', 'Zone A - Central Hub', 150.00, 2),
('Kagio', 'Zone B - Commercial Hub', 180.00, 2),
('Sagana', 'Zone C - West River Hub', 220.00, 3),
('Baricho', 'Zone C - West Suburb', 200.00, 3),
('Wanguru', 'Zone D - Mwea Rice Belt', 250.00, 4);

-- 13. Customer Saved Delivery Addresses
CREATE TABLE IF NOT EXISTS user_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(50) NOT NULL DEFAULT 'Home',
    town VARCHAR(100) NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    landmark VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_addr (user_id),
    CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Password Reset Tokens (Hashed, Expiring, Single-Use)
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prt_token (token_hash, consumed_at),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Alter Existing Tables Safely
SET @dbname = DATABASE();

-- users table additions
SET @col = "last_seen_at";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = "users" AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL, ADD COLUMN offline_alert_preference ENUM('both', 'sms', 'call', 'none') NOT NULL DEFAULT 'both', ADD COLUMN theme_preference ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'light';"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- seller_profiles KYC additions
SET @col = "verification_status";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = "seller_profiles" AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE seller_profiles ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'approved', ADD COLUMN national_id_no VARCHAR(30) NULL, ADD COLUMN verified_at DATETIME NULL, ADD COLUMN verified_by INT UNSIGNED NULL, ADD COLUMN rejection_reason VARCHAR(255) NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- delivery_profiles verification & vehicle additions
SET @col = "vehicle_plate";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = "delivery_profiles" AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE delivery_profiles ADD COLUMN vehicle_plate VARCHAR(20) NULL, ADD COLUMN national_id_no VARCHAR(30) NULL, ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'approved';"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- orders discount & coupon additions
SET @col = "discount_amount";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = "orders" AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, ADD COLUMN coupon_id INT UNSIGNED NULL, ADD COLUMN delivery_zone_id INT UNSIGNED NULL;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- products ratings cached counters
SET @col = "rating_avg";
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = "products" AND COLUMN_NAME = @col) > 0, "SELECT 1", "ALTER TABLE products ADD COLUMN rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00, ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5;"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
