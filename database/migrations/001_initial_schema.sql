-- Service App Initial Schema
-- GoDaddy MySQL-compatible

-- Users and roles
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('technician', 'admin', 'manager') DEFAULT 'technician',
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    contact_phone VARCHAR(20),
    address_line1 VARCHAR(255),
    address_line2 VARCHAR(255),
    city VARCHAR(100),
    province VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'CA',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sites (customer locations)
CREATE TABLE IF NOT EXISTS sites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    site_name VARCHAR(255) NOT NULL,
    address_line1 VARCHAR(255),
    address_line2 VARCHAR(255),
    city VARCHAR(100),
    province VARCHAR(100),
    postal_code VARCHAR(20),
    contact_person VARCHAR(255),
    contact_phone VARCHAR(20),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipment/Assets
CREATE TABLE IF NOT EXISTS equipment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    site_id INT NOT NULL,
    equipment_type VARCHAR(100) NOT NULL, -- e.g., 'tank', 'pump', 'filter'
    model VARCHAR(255),
    serial_number VARCHAR(255),
    capacity_liters INT,
    size_dimension VARCHAR(100),
    installation_date DATE,
    last_service_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service visits
CREATE TABLE IF NOT EXISTS service_visits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    site_id INT NOT NULL,
    technician_id INT NOT NULL,
    visit_status ENUM('scheduled', 'in-progress', 'pending-review', 'completed') DEFAULT 'scheduled',
    visit_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    visit_notes TEXT,
    customer_signature_path VARCHAR(255),
    signature_timestamp TIMESTAMP NULL,
    sync_status ENUM('local', 'pending-sync', 'synced') DEFAULT 'local',
    idempotency_key VARCHAR(100) UNIQUE, -- for safe retry on conflicts
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Measurements (e.g., pH, chlorine, etc.)
CREATE TABLE IF NOT EXISTS measurements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visit_id INT NOT NULL,
    equipment_id INT NOT NULL,
    measurement_type VARCHAR(100) NOT NULL, -- e.g., 'pH', 'chlorine', 'temperature'
    value DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(20), -- e.g., 'ppm', 'C', 'psi'
    status ENUM('normal', 'warning', 'critical') DEFAULT 'normal',
    measurement_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES service_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consumables replaced
CREATE TABLE IF NOT EXISTS consumables_used (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visit_id INT NOT NULL,
    equipment_id INT NOT NULL,
    consumable_name VARCHAR(255) NOT NULL, -- e.g., 'Filter cartridge', 'Membrane'
    quantity_used DECIMAL(10, 2),
    unit VARCHAR(20), -- e.g., 'units', 'kg'
    reason VARCHAR(255),
    is_billable TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES service_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repair recommendations
CREATE TABLE IF NOT EXISTS repair_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visit_id INT NOT NULL,
    equipment_id INT NOT NULL,
    issue_description TEXT NOT NULL,
    recommendation TEXT,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    estimated_cost DECIMAL(10, 2),
    status ENUM('recommended', 'approved', 'completed', 'declined') DEFAULT 'recommended',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES service_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media (photos, videos)
CREATE TABLE IF NOT EXISTS media_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visit_id INT NOT NULL,
    equipment_id INT,
    media_type VARCHAR(50) NOT NULL, -- 'photo', 'video', 'document'
    original_filename VARCHAR(255),
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500),
    file_size INT,
    mime_type VARCHAR(100),
    is_uploaded TINYINT(1) DEFAULT 0,
    upload_timestamp TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES service_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log for compliance
CREATE TABLE IF NOT EXISTS audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    entity_type VARCHAR(100),
    entity_id INT,
    action VARCHAR(50), -- 'insert', 'update', 'delete'
    old_values JSON,
    new_values JSON,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices (optional for v1, can be added later)
CREATE TABLE IF NOT EXISTS invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visit_id INT NOT NULL UNIQUE,
    invoice_number VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    total_amount DECIMAL(10, 2),
    status ENUM('draft', 'issued', 'paid', 'cancelled') DEFAULT 'draft',
    due_date DATE,
    paid_date DATE,
    payment_method VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES service_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for performance
CREATE INDEX idx_customers_active ON customers(is_active);
CREATE INDEX idx_sites_customer ON sites(customer_id);
CREATE INDEX idx_equipment_site ON equipment(site_id);
CREATE INDEX idx_visits_site ON service_visits(site_id);
CREATE INDEX idx_visits_technician ON service_visits(technician_id);
CREATE INDEX idx_visits_status ON service_visits(visit_status);
CREATE INDEX idx_measurements_visit ON measurements(visit_id);
CREATE INDEX idx_consumables_visit ON consumables_used(visit_id);
CREATE INDEX idx_repairs_visit ON repair_recommendations(visit_id);
CREATE INDEX idx_media_visit ON media_items(visit_id);
CREATE INDEX idx_audit_user ON audit_log(user_id);
CREATE INDEX idx_invoices_customer ON invoices(customer_id);
