-- =============================================================================
-- Migration 001: platform features (auth, payments, delivery, sellers, audit, jobs)
-- Applies on top of schema.sql + seed.sql. Run ONCE, after taking a backup.
-- Target: MariaDB 10.6+ (InfinityFree) and MySQL 8+. Tested on MySQL 9.7.
-- Rollback: restore the backup taken before this migration (no down-script).
-- =============================================================================
USE sokohub_db;

-- -----------------------------------------------------------------------------
-- A. Alter existing tables
-- -----------------------------------------------------------------------------

-- users: email verification, 2FA for admins, login throttling
ALTER TABLE users
    MODIFY status ENUM('pending_verification', 'active', 'suspended') NOT NULL DEFAULT 'active',
    ADD COLUMN email_verified_at DATETIME NULL,
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN locked_until DATETIME NULL,
    ADD COLUMN last_login_at DATETIME NULL,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- seller_profiles: verification workflow and payout account
ALTER TABLE seller_profiles
    ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
    ADD COLUMN verified_at DATETIME NULL,
    ADD COLUMN verified_by INT UNSIGNED NULL,
    ADD COLUMN rejection_reason VARCHAR(255) NULL,
    ADD COLUMN mpesa_phone VARCHAR(20) NULL,
    ADD CONSTRAINT fk_seller_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL;

-- products: low-stock alerts, cached ratings, optimistic-lock version, search
ALTER TABLE products
    ADD COLUMN low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    ADD COLUMN low_stock_alerted_at DATETIME NULL,
    ADD COLUMN rating_avg DECIMAL(3, 2) NOT NULL DEFAULT 0.00,
    ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD INDEX idx_products_status_category (status, category_id),
    ADD FULLTEXT INDEX ft_products_search (name, description);

-- orders: reservation expiry, cancellation, optimistic-lock version, more statuses
ALTER TABLE orders
    MODIFY order_status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    MODIFY payment_status ENUM('unpaid', 'paid', 'partially_refunded', 'refunded') NOT NULL DEFAULT 'unpaid',
    ADD COLUMN expires_at DATETIME NULL,
    ADD COLUMN cancelled_at DATETIME NULL,
    ADD COLUMN cancel_reason VARCHAR(255) NULL,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD INDEX idx_orders_buyer_created (buyer_id, created_at),
    ADD INDEX idx_orders_status_expires (order_status, expires_at);

-- order_items: keep the product name as it was at purchase time
ALTER TABLE order_items
    ADD COLUMN product_name_snapshot VARCHAR(150) NULL;

-- deliveries: rider claim concurrency, failure reason, proof requirement
ALTER TABLE deliveries
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN proof_required TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN failure_reason VARCHAR(255) NULL;

-- -----------------------------------------------------------------------------
-- B. New tables, in dependency order
-- -----------------------------------------------------------------------------

CREATE TABLE schema_migrations (
    version    VARCHAR(40) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Runtime settings (commission, reservation time, OTP lifetime, ...)
CREATE TABLE settings (
    setting_key   VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by    INT UNSIGNED NULL,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes for signup, login, password reset, admin 2FA, delivery confirmation.
-- Only a hash of the code is stored.
CREATE TABLE otp_codes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    purpose      ENUM('signup', 'login', 'reset', 'admin_2fa', 'delivery_confirm') NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    target       VARCHAR(160) NOT NULL,
    expires_at   DATETIME NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at  DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_user_purpose (user_id, purpose, consumed_at),
    INDEX idx_otp_expires (expires_at),
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link-based password reset (alternative to OTP). Token hash only.
CREATE TABLE password_reset_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every login attempt, successful or not. Drives throttling by email and by IP.
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(160) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit trail: admin actions and security events.
-- The application database user should have INSERT and SELECT on this table only.
CREATE TABLE audit_logs (
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

-- Buyer address book
CREATE TABLE addresses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    recipient_name  VARCHAR(120) NOT NULL,
    phone           VARCHAR(30) NOT NULL,
    county          VARCHAR(80) NOT NULL DEFAULT 'Kirinyaga',
    town            VARCHAR(100) NOT NULL,
    street_address  VARCHAR(255) NOT NULL,
    label           VARCHAR(40) NOT NULL DEFAULT 'Home',
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_addresses_user (user_id),
    CONSTRAINT fk_address_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Several images per product (the single products.image_path stays as the fallback)
CREATE TABLE product_images (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    image_path  VARCHAR(255) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_images_product (product_id, sort_order),
    CONSTRAINT fk_image_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery fee by town, replacing the hardcoded fee
CREATE TABLE delivery_zones (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    town      VARCHAR(100) NOT NULL UNIQUE,
    fee       DECIMAL(10, 2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wishlist: one row per buyer and product
CREATE TABLE wishlists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
    INDEX idx_wishlist_user (user_id),
    CONSTRAINT fk_wish_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews: only for delivered order items, one per buyer per product
CREATE TABLE reviews (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    buyer_id      INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL UNIQUE,
    rating        TINYINT UNSIGNED NOT NULL,
    comment       VARCHAR(1000) NULL,
    status        ENUM('published', 'hidden') NOT NULL DEFAULT 'published',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_buyer_product (buyer_id, product_id),
    INDEX idx_reviews_product (product_id, status),
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments: one row per attempt. Source of truth for money. orders.payment_status is a summary.
CREATE TABLE payments (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id              INT UNSIGNED NOT NULL,
    method                ENUM('mpesa', 'cash_on_delivery', 'bank_card') NOT NULL,
    amount                DECIMAL(10, 2) NOT NULL,
    status                ENUM('initiated', 'pending', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'initiated',
    phone                 VARCHAR(20) NULL,
    merchant_request_id   VARCHAR(64) NULL,
    checkout_request_id   VARCHAR(64) NULL UNIQUE,
    mpesa_receipt         VARCHAR(30) NULL UNIQUE,
    result_code           INT NULL,
    result_desc           VARCHAR(255) NULL,
    attempt_no            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_status (status, created_at),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw M-Pesa callbacks, kept for audit and replay. dedupe_key = SHA-256 of the raw body.
CREATE TABLE mpesa_callbacks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(64) NULL,
    result_code         INT NULL,
    mpesa_receipt       VARCHAR(30) NULL,
    payload             JSON NOT NULL,
    dedupe_key          CHAR(64) NOT NULL UNIQUE,
    received_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at        DATETIME NULL,
    INDEX idx_callbacks_checkout (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every order status change, for tracking and disputes
CREATE TABLE order_status_history (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status   VARCHAR(20) NOT NULL,
    changed_by  INT UNSIGNED NULL,
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_order (order_id, created_at),
    CONSTRAINT fk_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock ledger: every change to products.stock_qty has a row here
CREATE TABLE stock_movements (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    order_id     INT UNSIGNED NULL,
    change_qty   INT NOT NULL,
    reason       ENUM('reserve', 'release', 'adjustment', 'import') NOT NULL,
    balance_after INT NOT NULL,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_product (product_id, created_at),
    INDEX idx_stock_order (order_id),
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refunds and cancellation requests
CREATE TABLE refunds (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id           INT UNSIGNED NOT NULL,
    payment_id         INT UNSIGNED NULL,
    amount             DECIMAL(10, 2) NOT NULL,
    reason             VARCHAR(255) NOT NULL,
    status             ENUM('requested', 'approved', 'rejected', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'requested',
    requested_by       INT UNSIGNED NOT NULL,
    decided_by         INT UNSIGNED NULL,
    decided_at         DATETIME NULL,
    provider_reference VARCHAR(64) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_refunds_order (order_id),
    CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_refund_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refund_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proof of delivery: photo, recipient signature, or confirmation by OTP
CREATE TABLE delivery_proofs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id     INT UNSIGNED NOT NULL,
    proof_type      ENUM('photo', 'signature', 'otp') NOT NULL,
    file_path       VARCHAR(255) NULL,
    recipient_name  VARCHAR(120) NULL,
    otp_code_id     BIGINT UNSIGNED NULL,
    latitude        DECIMAL(10, 7) NULL,
    longitude       DECIMAL(10, 7) NULL,
    captured_by     INT UNSIGNED NULL,
    captured_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proof_delivery (delivery_id),
    CONSTRAINT fk_proof_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_proof_otp FOREIGN KEY (otp_code_id) REFERENCES otp_codes(id) ON DELETE SET NULL,
    CONSTRAINT fk_proof_user FOREIGN KEY (captured_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Latest known position of each rider (one row per rider, updated in place)
CREATE TABLE rider_locations (
    delivery_person_id INT UNSIGNED PRIMARY KEY,
    latitude           DECIMAL(10, 7) NOT NULL,
    longitude          DECIMAL(10, 7) NOT NULL,
    accuracy_m         SMALLINT UNSIGNED NULL,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_location_rider FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordered stops for a rider's route on a given day
CREATE TABLE delivery_stops (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_person_id INT UNSIGNED NOT NULL,
    delivery_id        INT UNSIGNED NOT NULL,
    planned_for        DATE NOT NULL,
    sequence_no        SMALLINT UNSIGNED NOT NULL,
    status             ENUM('planned', 'done', 'skipped') NOT NULL DEFAULT 'planned',
    UNIQUE KEY uq_stop_sequence (delivery_person_id, planned_for, sequence_no),
    INDEX idx_stop_delivery (delivery_id),
    CONSTRAINT fk_stop_rider FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_stop_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seller verification documents. Store files OUTSIDE the public web root.
CREATE TABLE seller_documents (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id   INT UNSIGNED NOT NULL,
    doc_type         ENUM('business_registration', 'national_id', 'other') NOT NULL,
    file_path        VARCHAR(255) NOT NULL,
    review_status    ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by      INT UNSIGNED NULL,
    reviewed_at      DATETIME NULL,
    uploaded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_docs_seller (seller_user_id),
    CONSTRAINT fk_docs_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_docs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seller payout summaries per period
CREATE TABLE seller_payouts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id INT UNSIGNED NOT NULL,
    period_start   DATE NOT NULL,
    period_end     DATE NOT NULL,
    gross_sales    DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    platform_fee   DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    refunds        DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    net_amount     DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    status         ENUM('draft', 'approved', 'paid') NOT NULL DEFAULT 'draft',
    mpesa_reference VARCHAR(30) NULL,
    paid_at        DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payout_period (seller_user_id, period_start, period_end),
    CONSTRAINT fk_payout_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bulk product upload: one import job, many rows
CREATE TABLE bulk_imports (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id    INT UNSIGNED NOT NULL,
    original_filename VARCHAR(200) NOT NULL,
    file_path         VARCHAR(255) NOT NULL,
    status            ENUM('uploaded', 'validating', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'uploaded',
    total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at       DATETIME NULL,
    INDEX idx_imports_seller (seller_user_id, created_at),
    CONSTRAINT fk_import_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bulk_import_rows (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id     INT UNSIGNED NOT NULL,
    row_no        INT UNSIGNED NOT NULL,
    raw_data      JSON NOT NULL,
    status        ENUM('valid', 'invalid', 'created') NOT NULL DEFAULT 'valid',
    error_message VARCHAR(500) NULL,
    product_id    INT UNSIGNED NULL,
    INDEX idx_import_rows (import_id, row_no),
    CONSTRAINT fk_importrow_import FOREIGN KEY (import_id) REFERENCES bulk_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_importrow_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background job queue (emails, SMS, expiry, low-stock checks, bulk import, payouts)
CREATE TABLE jobs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue        VARCHAR(40) NOT NULL DEFAULT 'default',
    job_type     VARCHAR(80) NOT NULL,
    payload      JSON NOT NULL,
    status       ENUM('queued', 'running', 'done', 'failed', 'dead') NOT NULL DEFAULT 'queued',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at    DATETIME NULL,
    locked_by    VARCHAR(64) NULL,
    last_error   VARCHAR(500) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  DATETIME NULL,
    INDEX idx_jobs_pick (status, queue, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every email and SMS sent, with the provider's message id
CREATE TABLE notification_logs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel             ENUM('email', 'sms') NOT NULL,
    recipient           VARCHAR(160) NOT NULL,
    user_id             INT UNSIGNED NULL,
    template            VARCHAR(80) NOT NULL,
    status              ENUM('sent', 'failed', 'skipped') NOT NULL,
    provider_message_id VARCHAR(100) NULL,
    related_type        VARCHAR(40) NULL,
    related_id          VARCHAR(40) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, created_at),
    INDEX idx_notif_related (related_type, related_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- C. Data migration for existing rows
-- -----------------------------------------------------------------------------

-- Demo and existing accounts stay usable without re-verification
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

-- Existing sellers are treated as approved
UPDATE seller_profiles SET verification_status = 'approved', verified_at = NOW();

-- Snapshot product names onto existing order lines
UPDATE order_items oi
JOIN products p ON p.id = oi.product_id
SET oi.product_name_snapshot = p.name
WHERE oi.product_name_snapshot IS NULL;

-- Each existing order gets one payment row, so the payments table is complete from the start
INSERT INTO payments (order_id, method, amount, status, created_at)
SELECT
    o.id,
    CASE o.payment_method
        WHEN 'M-Pesa' THEN 'mpesa'
        WHEN 'Cash on Delivery' THEN 'cash_on_delivery'
        ELSE 'bank_card'
    END,
    o.total_amount,
    CASE o.payment_status
        WHEN 'paid' THEN 'succeeded'
        WHEN 'refunded' THEN 'succeeded'
        ELSE 'pending'
    END,
    o.created_at
FROM orders o;

-- -----------------------------------------------------------------------------
-- D. Reference data
-- -----------------------------------------------------------------------------

INSERT INTO settings (setting_key, setting_value) VALUES
('platform_commission_percent', '10'),
('order_reservation_minutes', '30'),
('otp_ttl_minutes', '10'),
('otp_max_attempts', '3'),
('login_max_failures', '5'),
('login_lock_minutes', '15'),
('low_stock_default_threshold', '5');

INSERT INTO delivery_zones (town, fee) VALUES
('Kutus', 150.00),
('Kerugoya', 200.00),
('Kagio', 200.00),
('Sagana', 250.00),
('Embu', 350.00);

INSERT INTO schema_migrations (version) VALUES ('001_platform_features');
