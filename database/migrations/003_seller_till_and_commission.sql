-- =============================================================================
-- Migration 003: Seller Till Number & 2% Marketplace Commission Deduction
-- SokoHub Platform Collection Till: 1648753 (Account: sokohub)
-- =============================================================================

USE sokohub_db;

-- 1. Ensure till_number exists on seller_profiles
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'seller_profiles' 
      AND COLUMN_NAME = 'till_number'
);
SET @sql = IF(
    @col_exists = 0, 
    'ALTER TABLE seller_profiles ADD COLUMN till_number VARCHAR(30) NOT NULL DEFAULT "1648753" AFTER town', 
    'SELECT "Column till_number already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Ensure platform_fee and seller_earnings exist on order_items
SET @fee_col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'order_items' 
      AND COLUMN_NAME = 'platform_fee'
);
SET @sql_fee = IF(
    @fee_col_exists = 0, 
    'ALTER TABLE order_items ADD COLUMN platform_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00, ADD COLUMN seller_earnings DECIMAL(10, 2) NOT NULL DEFAULT 0.00', 
    'SELECT "Columns platform_fee and seller_earnings already exist"'
);
PREPARE stmt_fee FROM @sql_fee;
EXECUTE stmt_fee;
DEALLOCATE PREPARE stmt_fee;

-- 3. Backfill any existing order items with the 2% platform commission and 98% seller earnings
UPDATE order_items 
SET platform_fee = ROUND(line_total * 0.02, 2),
    seller_earnings = ROUND(line_total - (line_total * 0.02), 2)
WHERE (platform_fee = 0.00 OR platform_fee IS NULL) AND line_total > 0;

-- 4. Update seeded sellers with their Till numbers
UPDATE seller_profiles SET till_number = '1648753' WHERE user_id = 2;
UPDATE seller_profiles SET till_number = '789124' WHERE user_id = 3;
