CREATE DATABASE IF NOT EXISTS dreport
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE dreport;

CREATE TABLE IF NOT EXISTS work_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wo_no VARCHAR(80) NOT NULL,
    customer_name VARCHAR(255) NULL,
    project_name VARCHAR(255) NULL,
    dn_number VARCHAR(40) NULL,
    destination VARCHAR(255) NULL,
    edd VARCHAR(80) NULL,
    wo_qty DECIMAL(14,3) NULL,
    duct_weight DECIMAL(14,3) NULL,
    mnf_weight DECIMAL(14,3) NULL,
    mnf_date VARCHAR(40) NULL,
    fix_anc_weight DECIMAL(14,3) NULL,
    fix_anc_date VARCHAR(40) NULL,
    raw_data LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_work_orders_wo_no (wo_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_order_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wo_no VARCHAR(80) NOT NULL,
    dn_number VARCHAR(40) NOT NULL,
    project_name VARCHAR(255) NULL,
    edd VARCHAR(80) NULL,
    wo_qty DECIMAL(14,3) NULL,
    mnf_qty DECIMAL(14,3) NULL,
    duct_weight DECIMAL(14,3) NULL,
    mnf_weight DECIMAL(14,3) NULL,
    fix_anc_weight DECIMAL(14,3) NULL,
    raw_data LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_work_order_deliveries_wo_dn (wo_no, dn_number),
    KEY idx_work_order_deliveries_wo_no (wo_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_order_vehicle_types (
    wo_no VARCHAR(80) NOT NULL PRIMARY KEY,
    vehicle_type VARCHAR(120) NOT NULL,
    source_file VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
