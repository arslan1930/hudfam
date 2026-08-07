-- TechxForm schema: users + Our database + add history + Order management.
-- Legacy Catalog / Emails / Published / Projects are not part of the app.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(80) NOT NULL DEFAULT '',
  contact_details TEXT,
  role ENUM('admin','team') NOT NULL DEFAULT 'team',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (role),
  INDEX (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS countries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  region VARCHAR(40) NOT NULL DEFAULT 'other',
  code VARCHAR(10) NOT NULL DEFAULT '',
  name VARCHAR(100) NOT NULL,
  default_language VARCHAR(50) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_country_name (name),
  INDEX (region),
  INDEX (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Our database: unique domains (no prices)
CREATE TABLE IF NOT EXISTS prospect_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  niche VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT,
  status ENUM('new','contacting','replied','skipped') NOT NULL DEFAULT 'new',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_prospect_country_domain (country, domain),
  INDEX (domain),
  INDEX (country),
  INDEX (language),
  INDEX (region),
  INDEX (status),
  CONSTRAINT fk_prospect_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add history: who added what, by day
CREATE TABLE IF NOT EXISTS prospect_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  batch_date DATE NOT NULL,
  site_count INT NOT NULL DEFAULT 0,
  country VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  niche VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_batch_date (user_id, batch_date),
  INDEX (batch_date),
  INDEX (user_id),
  CONSTRAINT fk_pbatch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prospect_batch_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL,
  prospect_site_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_batch_domain (batch_id, domain),
  INDEX (domain),
  CONSTRAINT fk_pbi_batch FOREIGN KEY (batch_id) REFERENCES prospect_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_pbi_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extracting sites (team): Sites list + Extracting Results per country
CREATE TABLE IF NOT EXISTS extract_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  country VARCHAR(100) NOT NULL,
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  site_count INT NOT NULL DEFAULT 0,
  results_text MEDIUMTEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_country (country),
  INDEX (updated_at),
  CONSTRAINT fk_extract_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extract_batch_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL,
  prospect_site_id INT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_batch_domain (batch_id, domain),
  INDEX (domain),
  INDEX (added_by),
  CONSTRAINT fk_ebs_batch FOREIGN KEY (batch_id) REFERENCES extract_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_ebs_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_ebs_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extracted URLs (admin): sites pushed from Team Extracting Results, per country
CREATE TABLE IF NOT EXISTS extracted_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL,
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  notes TEXT NULL,
  extract_batch_id INT NULL,
  pushed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extracted_country_domain (country, domain),
  INDEX (country),
  INDEX (domain),
  INDEX (pushed_by),
  CONSTRAINT fk_extracted_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order management: one sheet per client
CREATE TABLE IF NOT EXISTS order_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_order_client_name (name),
  INDEX (created_by),
  CONSTRAINT fk_oc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  row_type ENUM('site','year_end') NOT NULL DEFAULT 'site',
  site_name VARCHAR(255) NOT NULL DEFAULT '',
  site_note VARCHAR(255) NOT NULL DEFAULT '',
  placement_type VARCHAR(20) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  order_month TINYINT NULL,
  period_end_month TINYINT NULL,
  order_year SMALLINT NOT NULL DEFAULT 0,
  owner_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  decided_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  live_url VARCHAR(500) NOT NULL DEFAULT '',
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (client_id, sort_order),
  INDEX (client_id, order_year, order_month),
  CONSTRAINT fk_oi_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices (generated from completed order-sheet articles)
CREATE TABLE IF NOT EXISTS invoice_client_profiles (
  client_id INT NOT NULL PRIMARY KEY,
  bill_to_name VARCHAR(200) NOT NULL DEFAULT '',
  bill_to_address TEXT NULL,
  bill_to_hrb VARCHAR(120) NOT NULL DEFAULT '',
  bill_to_vat VARCHAR(120) NOT NULL DEFAULT '',
  supplier_number VARCHAR(120) NOT NULL DEFAULT 'NEW',
  cost_center VARCHAR(200) NOT NULL DEFAULT '',
  orderer VARCHAR(200) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_icp_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(32) NOT NULL,
  invoice_date DATE NOT NULL,
  client_id INT NULL,
  client_name VARCHAR(200) NOT NULL DEFAULT '',
  bill_to_name VARCHAR(200) NOT NULL DEFAULT '',
  bill_to_address TEXT NULL,
  bill_to_hrb VARCHAR(120) NOT NULL DEFAULT '',
  bill_to_vat VARCHAR(120) NOT NULL DEFAULT '',
  supplier_number VARCHAR(120) NOT NULL DEFAULT 'NEW',
  cost_center VARCHAR(200) NOT NULL DEFAULT '',
  orderer VARCHAR(200) NOT NULL DEFAULT '',
  company_name VARCHAR(200) NOT NULL DEFAULT '',
  company_bic VARCHAR(80) NOT NULL DEFAULT '',
  company_iban VARCHAR(80) NOT NULL DEFAULT '',
  company_phone VARCHAR(80) NOT NULL DEFAULT '',
  company_address TEXT NULL,
  company_reg_no VARCHAR(80) NOT NULL DEFAULT '',
  vat_note VARCHAR(255) NOT NULL DEFAULT 'Not VAT registered – no VAT charged.',
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  paid_at DATETIME NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  admin_note VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_invoice_number (invoice_number),
  INDEX (client_id),
  INDEX (invoice_date),
  INDEX (payment_status),
  INDEX (is_manual),
  CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  description TEXT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  qty INT NOT NULL DEFAULT 1,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  order_item_ids VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  INDEX (invoice_id, sort_order),
  CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
