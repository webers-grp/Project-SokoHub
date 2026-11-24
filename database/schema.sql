-- ============================================================================
-- SokoHub Marketplace Database Schema (MySQL 5.7+ / 8.0+ / MariaDB 10.4+)
-- Course: SPI 2301 - Web Application Development II (Kirinyaga University)
-- Note: On XAMPP, create database `sokohub_db` first and select it before import.
-- On InfinityFree/000webhost, select your pre-created host database in phpMyAdmin.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS contact_messages;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS wishlists;
DROP TABLE IF EXISTS addresses;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS delivery_profiles;
DROP TABLE IF EXISTS seller_profiles;
DROP TABLE IF EXISTS users;

-- 1. Core Users Table (Buyers, Sellers, Delivery Personnel, Admin)
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('buyer', 'seller', 'delivery', 'admin') NOT NULL DEFAULT 'buyer',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    last_seen_at DATETIME NULL,
    offline_alert_preference ENUM('both', 'sms', 'call', 'none') NOT NULL DEFAULT 'both',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seller Business Profiles (1-to-1 extension for role='seller')
CREATE TABLE seller_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    business_name VARCHAR(150) NOT NULL,
    business_type ENUM('retail', 'food_beverage', 'crafts', 'services', 'other') NOT NULL DEFAULT 'retail',
    town VARCHAR(100) NOT NULL DEFAULT 'Kutus',
    stall_location VARCHAR(180) NULL,
    student_reg_no VARCHAR(50) NULL,
    is_student_vendor TINYINT(1) NOT NULL DEFAULT 0,
    operating_hours VARCHAR(100) NULL DEFAULT '8:00 AM - 8:00 PM',
    whatsapp_phone VARCHAR(30) NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Delivery Rider Profiles (1-to-1 extension for role='delivery')
CREATE TABLE delivery_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    transport_method ENUM('motorbike', 'tuk_tuk', 'pickup_truck', 'car', 'bicycle') NOT NULL DEFAULT 'motorbike',
    vehicle_plate_number VARCHAR(20) NULL,
    vehicle_model VARCHAR(80) NULL,
    license_number VARCHAR(60) NOT NULL,
    service_area VARCHAR(120) NOT NULL DEFAULT 'Kutus & Kerugoya',
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Product Categories
CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Marketplace Products
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    original_price DECIMAL(10, 2) NULL,
    stock_qty INT NOT NULL DEFAULT 10,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    low_stock_alerted_at DATETIME NULL,
    condition_type ENUM('New', 'Second Hand') NOT NULL DEFAULT 'New',
    image_path VARCHAR(255) NOT NULL DEFAULT 'assets/img/cart-icon.jpg',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    rating_avg DECIMAL(3, 2) NOT NULL DEFAULT 0.00,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category (category_id),
    INDEX idx_products_seller (seller_id),
    INDEX idx_products_status (status),
    FULLTEXT INDEX ft_products_search (name, description),
    CONSTRAINT fk_product_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Customer Orders
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(32) NOT NULL UNIQUE,
    buyer_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(60) NOT NULL DEFAULT 'Digital Pay-on-Delivery (M-Pesa)',
    payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
    order_status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    delivery_address VARCHAR(255) NOT NULL,
    delivery_town VARCHAR(100) NOT NULL,
    notes VARCHAR(255) NULL,
    mpesa_receipt VARCHAR(30) NULL,
    paid_at DATETIME NULL,
    expires_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_buyer (buyer_id),
    INDEX idx_orders_status (order_status),
    INDEX idx_orders_status_expires (order_status, expires_at),
    CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Order Line Items (Supports multi-product & multi-vendor orders)
CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    seller_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    line_total DECIMAL(10, 2) NOT NULL,
    product_name_snapshot VARCHAR(150) NULL,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_seller (seller_id),
    CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_item_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Delivery Dispatch & Tracking
CREATE TABLE deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL UNIQUE,
    delivery_person_id INT UNSIGNED NULL,
    assigned_by_seller_id INT UNSIGNED NULL,
    digital_payment_id INT UNSIGNED NULL,
    pickup_town VARCHAR(100) NOT NULL DEFAULT 'Kutus Market',
    dropoff_address VARCHAR(255) NOT NULL,
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 150.00,
    delivery_status ENUM('pending', 'assigned', 'picked_up', 'in_transit', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    tracking_notes VARCHAR(255) NULL,
    assigned_at DATETIME NULL,
    delivered_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_delivery_person (delivery_person_id),
    INDEX idx_delivery_seller (assigned_by_seller_id),
    INDEX idx_delivery_status (delivery_status),
    CONSTRAINT fk_delivery_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_rider FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_delivery_seller FOREIGN KEY (assigned_by_seller_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Digital Payments (Pure Digital Pay-on-Delivery via M-Pesa STK)
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    delivery_id INT UNSIGNED NULL,
    rider_id INT UNSIGNED NULL,
    buyer_phone VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(60) NOT NULL DEFAULT 'Digital Pay-on-Delivery (M-Pesa)',
    status ENUM('pending', 'prompted', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    mpesa_checkout_id VARCHAR(80) NULL,
    mpesa_receipt VARCHAR(30) NULL,
    prompted_at DATETIME NULL,
    completed_at DATETIME NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_delivery (delivery_id),
    INDEX idx_payments_status (status),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Contact & Customer Support Inquiries
CREATE TABLE contact_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'resolved') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Buyer Saved Delivery Addresses (Group 2)
CREATE TABLE addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    recipient_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    county VARCHAR(80) NOT NULL DEFAULT 'Kirinyaga',
    town VARCHAR(100) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_addresses_user (user_id),
    CONSTRAINT fk_address_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Wishlists (Group 2)
CREATE TABLE wishlists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
    INDEX idx_wishlist_user (user_id),
    CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Verified Product Reviews & Ratings (Group 2)
CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    status ENUM('published', 'hidden') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_buyer_product (buyer_id, product_id),
    INDEX idx_reviews_product (product_id, status),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Stock Movements Audit Ledger
CREATE TABLE stock_movements (
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
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Offline Telephony Dispatch Alerts (SMS & Automated Voice Calls)
CREATE TABLE telephony_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    recipient_name VARCHAR(120) NULL,
    recipient_role VARCHAR(20) NULL,
    phone VARCHAR(20) NOT NULL,
    channel ENUM('sms', 'call', 'both') NOT NULL,
    purpose ENUM('new_order', 'delivery_assignment', 'payment_alert', 'system') NOT NULL,
    message TEXT NOT NULL,
    call_text TEXT NULL,
    status ENUM('queued', 'sent', 'delivered', 'failed', 'simulated') NOT NULL DEFAULT 'sent',
    provider VARCHAR(40) NOT NULL DEFAULT 'simulator',
    provider_ref VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_telephony_user (user_id),
    INDEX idx_telephony_channel (channel),
    INDEX idx_telephony_status (status),
    CONSTRAINT fk_telephony_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
