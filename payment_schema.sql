-- ================================================
-- Pakistan AR Guide — Payment System Database Schema
-- ================================================

CREATE DATABASE IF NOT EXISTS pakistan_ar_guide CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pakistan_ar_guide;

-- ─── BOOKINGS TABLE ───────────────────────────
CREATE TABLE IF NOT EXISTS bookings (
    id              VARCHAR(36)  PRIMARY KEY,           -- UUID e.g. 'PKG-2025-ABCD'
    user_id         INT          NOT NULL,
    package_id      INT          NOT NULL,
    booking_date    DATE         NOT NULL,
    guests          INT          DEFAULT 1,
    total_amount    DECIMAL(10,2) NOT NULL,
    payment_status  ENUM('unpaid','pending','payment_pending','paid','refunded','failed')
                                 DEFAULT 'unpaid',
    txn_reference   VARCHAR(255) DEFAULT NULL,
    promo_code      VARCHAR(50)  DEFAULT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB;

-- ─── PAYMENT LOGS TABLE ────────────────────────
CREATE TABLE IF NOT EXISTS payment_logs (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    booking_id      VARCHAR(36)  NOT NULL,
    user_id         INT          DEFAULT NULL,
    payment_method  ENUM('card','easypaisa','jazzcash','bank_transfer') NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    currency        VARCHAR(3)   DEFAULT 'PKR',
    status          ENUM('success','failed','pending','pending_verification','pending_otp') NOT NULL,
    details         TEXT         DEFAULT NULL,   -- txn ref, error message, etc.
    gateway_response JSON        DEFAULT NULL,   -- raw API response for debugging
    ip_address      VARCHAR(45)  DEFAULT NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_booking_id (booking_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- ─── PROMO CODES TABLE ─────────────────────────
CREATE TABLE IF NOT EXISTS promo_codes (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50)  UNIQUE NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    discount_type   ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    discount_value  DECIMAL(8,2) NOT NULL,      -- % or PKR amount
    min_order       DECIMAL(10,2) DEFAULT 0.00,  -- minimum booking amount to apply
    usage_limit     INT          DEFAULT NULL,   -- NULL = unlimited
    usage_count     INT          DEFAULT 0,
    active          TINYINT(1)   DEFAULT 1,
    expires_at      DATETIME     DEFAULT NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- ─── REFUNDS TABLE ─────────────────────────────
CREATE TABLE IF NOT EXISTS refunds (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    booking_id      VARCHAR(36)  NOT NULL,
    payment_log_id  INT          NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    reason          TEXT         DEFAULT NULL,
    status          ENUM('pending','approved','rejected','processed') DEFAULT 'pending',
    refund_txn_ref  VARCHAR(255) DEFAULT NULL,
    processed_by    INT          DEFAULT NULL,   -- admin user ID
    processed_at    DATETIME     DEFAULT NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_log_id) REFERENCES payment_logs(id)
) ENGINE=InnoDB;

-- ─── SAMPLE DATA ───────────────────────────────
INSERT INTO promo_codes (code, description, discount_type, discount_value, active, expires_at) VALUES
('PAKISTAN10', '10% off for all users',          'percentage', 10.00, 1, '2025-12-31 23:59:59'),
('TOURISM20',  '20% off tourism promotion',      'percentage', 20.00, 1, '2025-06-30 23:59:59'),
('FYPDEMO',    '15% off FYP demo discount',      'percentage', 15.00, 1, NULL),
('FLAT500',    'Flat PKR 500 off any booking',   'fixed',      500.00, 1, '2025-12-31 23:59:59');
