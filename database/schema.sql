-- ============================================================================
-- SokoHub Marketplace Database Schema (MySQL 5.7+ / 8.0+ / MariaDB 10.4+)
-- Course: SPI 2301 - Web Application Development II (Kirinyaga University)
-- Note: On XAMPP, create database `sokohub_db` first and select it before import.
-- On InfinityFree/000webhost, select your pre-created host database in phpMyAdmin.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS contact_messages;
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seller Business Profiles (1-to-1 extension for role='seller')
CREATE TABLE seller_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    business_name VARCHAR(150) NOT NULL,
    business_type ENUM('retail', 'food_beverage', 'crafts', 'services', 'other') NOT NULL DEFAULT 'retail',
    town VARCHAR(100) NOT NULL DEFAULT 'Kutus',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Delivery Rider Profiles (1-to-1 extension for role='delivery')
CREATE TABLE delivery_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    transport_method ENUM('motorbike', 'bicycle', 'car', 'foot') NOT NULL DEFAULT 'motorbike',
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
    condition_type ENUM('New', 'Second Hand') NOT NULL DEFAULT 'New',
    image_path VARCHAR(255) NOT NULL DEFAULT 'assets/img/cart-icon.jpg',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category (category_id),
    INDEX idx_products_seller (seller_id),
    INDEX idx_products_status (status),
    CONSTRAINT fk_product_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Customer Orders
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(32) NOT NULL UNIQUE,
    buyer_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('M-Pesa', 'Cash on Delivery', 'Bank Card') NOT NULL DEFAULT 'M-Pesa',
    payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'paid',
    order_status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    delivery_address VARCHAR(255) NOT NULL,
    delivery_town VARCHAR(100) NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_buyer (buyer_id),
    INDEX idx_orders_status (order_status),
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
    pickup_town VARCHAR(100) NOT NULL DEFAULT 'Kutus Market',
    dropoff_address VARCHAR(255) NOT NULL,
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 150.00,
    delivery_status ENUM('pending', 'assigned', 'picked_up', 'in_transit', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    tracking_notes VARCHAR(255) NULL,
    assigned_at DATETIME NULL,
    delivered_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_delivery_person (delivery_person_id),
    INDEX idx_delivery_status (delivery_status),
    CONSTRAINT fk_delivery_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_rider FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Contact & Customer Support Inquiries
CREATE TABLE contact_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'resolved') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
