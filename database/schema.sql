CREATE DATABASE IF NOT EXISTS rewards_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rewards_system;

DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS points_transactions;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS admins;

CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password CHAR(64) NOT NULL,
    role ENUM('superadmin','cashier') NOT NULL DEFAULT 'cashier',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    join_date DATE DEFAULT CURRENT_DATE,
    last_visit DATETIME DEFAULT NULL,
    notes TEXT
) ENGINE=InnoDB;

CREATE TABLE points_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    transaction_type ENUM('add','redeem','subtract','edit') NOT NULL,
    points INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_points_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_points_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auto_points_per_visit INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO admins (username, email, password, role)
VALUES ('admin', 'admin@example.com', SHA2('admin123', 256), 'superadmin')
ON DUPLICATE KEY UPDATE email=VALUES(email);

INSERT INTO settings (auto_points_per_visit) VALUES (10);

INSERT INTO customers (phone, name, email, join_date, last_visit, notes)
VALUES
('5551234567', 'John Doe', 'john@example.com', CURDATE(), NOW(), 'Prefers email receipts'),
('5559876543', 'Jane Smith', 'jane@example.com', DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'Likes seasonal promotions');

INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points)
VALUES
(1, 1, 'add', 120),
(1, 1, 'redeem', 200),
(2, 1, 'add', 80);

INSERT INTO activity_log (admin_id, action, details)
VALUES
(1, 'login', 'Default admin logged in'),
(1, 'customer_created', 'Seed customers imported');
