-- ---------------------------------------------------------
-- CREATE DATABASE
-- ---------------------------------------------------------
CREATE DATABASE IF NOT EXISTS attendance_db
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE attendance_db;

-- ---------------------------------------------------------
-- DROP TABLES (Correct reverse order for FK safety)
-- ---------------------------------------------------------
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS leaves;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS leave_balances;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS shifts;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS settings;

-- ---------------------------------------------------------
-- SETTINGS TABLE
-- ---------------------------------------------------------
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    office_lat DECIMAL(10,8) NOT NULL DEFAULT 37.7749,
    office_lng DECIMAL(11,8) NOT NULL DEFAULT -122.4194,
    radius INT NOT NULL DEFAULT 100
);

-- ---------------------------------------------------------
-- DEPARTMENTS TABLE
-- ---------------------------------------------------------
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL
);

-- ---------------------------------------------------------
-- SHIFTS TABLE
-- ---------------------------------------------------------
CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
);

-- ---------------------------------------------------------
-- HOLIDAYS TABLE
-- ---------------------------------------------------------
CREATE TABLE holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE UNIQUE NOT NULL,
    description VARCHAR(100)
);
-- ---------------------------------------------------------
-- USERS TABLE
-- ---------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'hr', 'employee') NOT NULL,
    emp_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------
-- EMPLOYEES TABLE
-- ---------------------------------------------------------
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    department_id INT NOT NULL,
    designation VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    shift_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------
-- LEAVE BALANCES TABLE
-- ---------------------------------------------------------
CREATE TABLE leave_balances (
    emp_id INT PRIMARY KEY,
    balance INT DEFAULT 21,
    FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
);
-- ---------------------------------------------------------
-- ATTENDANCE TABLE
-- ---------------------------------------------------------
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id INT NOT NULL,
    check_in DATETIME NULL,
    check_out DATETIME NULL,

    in_lat DECIMAL(10,8) NULL,
    in_lng DECIMAL(11,8) NULL,
    in_accuracy INT NULL,

    out_lat DECIMAL(10,8) NULL,
    out_lng DECIMAL(11,8) NULL,
    out_accuracy INT NULL,

    in_selfie VARCHAR(255) NULL,
    out_selfie VARCHAR(255) NULL,

    status ENUM('present','absent','on_leave') DEFAULT 'present',
    is_outside_zone BOOLEAN DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------
-- LEAVES TABLE
-- ---------------------------------------------------------
CREATE TABLE leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    document VARCHAR(255),

    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    applied_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    approved_by INT NULL,
    approved_date TIMESTAMP NULL,

    FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------
-- NOTIFICATIONS TABLE
-- ---------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------
-- INSERT MASTER DATA
-- ---------------------------------------------------------

INSERT INTO departments (name) VALUES 
('IT'), 
('HR'), 
('Finance');

INSERT INTO shifts (name, start_time, end_time) VALUES
('Day', '09:00:00', '17:00:00'),
('Night', '17:00:00', '01:00:00');

INSERT INTO settings (id, office_lat, office_lng, radius)
VALUES (1, 37.7749, -122.4194, 100);


-- ---------------------------------------------------------
-- ADMIN USER (password: password)
-- ---------------------------------------------------------
INSERT INTO users (username, password, role)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- hashed "password"
    'admin'
);


-- =========================================================
-- EMPLOYEE 1
-- Username: EMP001  
-- Password: emp123
-- =========================================================
INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
VALUES ('EMP001', 'John Doe', 1, 'Software Developer', 'john.doe@example.com', '1234567890', 1);

SET @emp1 = LAST_INSERT_ID();

INSERT INTO users (username, password, role, emp_id)
VALUES (
    'EMP001',
    '$2b$12$yNVxDT4nOdWlA/fGWKqd1.8pnnw2utCra8SdNlZq9VjhPXgbXDWZu',  -- hashed "emp123"
    'employee',
    @emp1
);

INSERT INTO leave_balances (emp_id) VALUES (@emp1);


-- =========================================================
-- EMPLOYEE 2
-- Username: EMP002  
-- Password: emp123
-- =========================================================
INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
VALUES ('EMP002', 'Jane Smith', 2, 'HR Manager', 'jane.smith@example.com', '0987654321', 1);

SET @emp2 = LAST_INSERT_ID();

INSERT INTO users (username, password, role, emp_id)
VALUES (
    'EMP002',
    '$2b$12$yNVxDT4nOdWlA/fGWKqd1.8pnnw2utCra8SdNlZq9VjhPXgbXDWZu',  -- hashed "emp123"
    'employee',
    @emp2
);

INSERT INTO leave_balances (emp_id) VALUES (@emp2);


-- =========================================================
-- EMPLOYEE 3
-- Username: EMP003  
-- Password: emp123
-- =========================================================
INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
VALUES ('EMP003', 'Bob Johnson', 3, 'Finance Analyst', 'bob.johnson@example.com', '5551234567', 2);

SET @emp3 = LAST_INSERT_ID();

INSERT INTO users (username, password, role, emp_id)
VALUES (
    'EMP003',
    '$2b$12$yNVxDT4nOdWlA/fGWKqd1.8pnnw2utCra8SdNlZq9VjhPXgbXDWZu', -- hashed "emp123"
    'employee',
    @emp3
);

INSERT INTO leave_balances (emp_id) VALUES (@emp3);