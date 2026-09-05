-- TechxForm schema: users + Our database + site adding history + Order management.
-- Legacy Catalog / Emails / Published / Projects are not part of the app.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  email_verified_at DATETIME NULL DEFAULT NULL,
  phone VARCHAR(80) NOT NULL DEFAULT '',
  contact_details TEXT,
  role ENUM('admin','team') NOT NULL DEFAULT 'team',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  session_version INT NOT NULL DEFAULT 1,
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
  niche VARCHAR(512) NOT NULL DEFAULT '',
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

-- Site adding history: who added what, by day
CREATE TABLE IF NOT EXISTS prospect_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  batch_date DATE NOT NULL,
  site_count INT NOT NULL DEFAULT 0,
  country VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  niche VARCHAR(512) NOT NULL DEFAULT '',
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
  emptied_at TIMESTAMP NULL DEFAULT NULL,
  last_pushed_at TIMESTAMP NULL DEFAULT NULL,
  sites_writer_id INT NULL DEFAULT NULL,
  sites_writer_at TIMESTAMP NULL DEFAULT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_country (country),
  INDEX (updated_at),
  INDEX (emptied_at),
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

-- Sites with emails - Team: site names from Extracting Results Push; emails added here
CREATE TABLE IF NOT EXISTS sites_with_emails_team (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL,
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  email1 VARCHAR(255) NOT NULL DEFAULT '',
  email2 VARCHAR(255) NOT NULL DEFAULT '',
  email3 VARCHAR(255) NOT NULL DEFAULT '',
  email4 VARCHAR(255) NOT NULL DEFAULT '',
  extract_batch_id INT NULL,
  pushed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_swe_team_country_domain (country, domain),
  INDEX (country),
  INDEX (domain),
  INDEX (pushed_by),
  CONSTRAINT fk_swe_team_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sites with emails - Admin: final archive from Team Push (data stays here)
CREATE TABLE IF NOT EXISTS sites_with_emails_admin (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL,
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  email1 VARCHAR(255) NOT NULL DEFAULT '',
  email2 VARCHAR(255) NOT NULL DEFAULT '',
  email3 VARCHAR(255) NOT NULL DEFAULT '',
  email4 VARCHAR(255) NOT NULL DEFAULT '',
  extract_batch_id INT NULL,
  pushed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_swe_admin_country_domain (country, domain),
  INDEX (country),
  INDEX (domain),
  INDEX (pushed_by),
  CONSTRAINT fk_swe_admin_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- All sites with emails - Final: Admin-only mirror synced from sites_with_emails_admin
CREATE TABLE IF NOT EXISTS sites_with_emails_admin_all (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL,
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  email1 VARCHAR(255) NOT NULL DEFAULT '',
  email2 VARCHAR(255) NOT NULL DEFAULT '',
  email3 VARCHAR(255) NOT NULL DEFAULT '',
  email4 VARCHAR(255) NOT NULL DEFAULT '',
  extract_batch_id INT NULL,
  pushed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_swe_admin_all_country_domain (country, domain),
  INDEX (country),
  INDEX (domain),
  INDEX (pushed_by),
  CONSTRAINT fk_swe_admin_all_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin "New data" reminders (cleared when Admin opens that section)
CREATE TABLE IF NOT EXISTS admin_data_signals (
  section VARCHAR(60) NOT NULL PRIMARY KEY,
  last_new_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_count INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_data_seen (
  user_id INT NOT NULL,
  section VARCHAR(60) NOT NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, section),
  CONSTRAINT fk_admin_data_seen_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-admin “seen” watermark for Sites with emails - Admin country folders
CREATE TABLE IF NOT EXISTS swe_admin_country_seen (
  user_id INT NOT NULL,
  country VARCHAR(100) NOT NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, country),
  CONSTRAINT fk_swe_admin_country_seen_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email campaign projects (Admin) → country sheets → Communication search + drafts
CREATE TABLE IF NOT EXISTS email_campaign_projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  team_search_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_campaign_project_name (name),
  INDEX (team_search_visible),
  INDEX (updated_at),
  CONSTRAINT fk_email_campaign_project_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email campaign sheets (Emails DATA → Communication Team search)
CREATE TABLE IF NOT EXISTS email_campaign_sheets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  project_id INT NULL,
  project_name VARCHAR(180) NOT NULL DEFAULT '',
  team_search_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_campaign_project_country (project_id, name),
  INDEX (updated_at),
  INDEX (team_search_visible),
  INDEX (project_id),
  CONSTRAINT fk_email_campaign_sheet_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_campaign_rows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sheet_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  email1 VARCHAR(255) NOT NULL DEFAULT '',
  email2 VARCHAR(255) NOT NULL DEFAULT '',
  email3 VARCHAR(255) NOT NULL DEFAULT '',
  email4 VARCHAR(255) NOT NULL DEFAULT '',
  email_sent TINYINT(1) NOT NULL DEFAULT 0,
  email_sent_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_campaign_sheet_domain (sheet_id, domain),
  INDEX (sheet_id),
  INDEX idx_email_campaign_sheet_id (sheet_id, id),
  INDEX idx_email_campaign_sheet_sent (sheet_id, email_sent),
  INDEX (domain),
  INDEX (country),
  CONSTRAINT fk_email_campaign_row_sheet
    FOREIGN KEY (sheet_id) REFERENCES email_campaign_sheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Communication / Admin: reusable outreach drafts per Email campaign project
CREATE TABLE IF NOT EXISTS email_campaign_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'custom',
  title VARCHAR(180) NOT NULL,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body MEDIUMTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (project_id),
  INDEX idx_email_campaign_draft_cat (project_id, category),
  INDEX (updated_at),
  CONSTRAINT fk_email_campaign_draft_project
    FOREIGN KEY (project_id) REFERENCES email_campaign_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_email_campaign_draft_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Office departments (Admin assigns members + tasks)
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_department_slug (slug),
  UNIQUE KEY uniq_department_name (name),
  INDEX (sort_order),
  INDEX (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department_members (
  department_id INT NOT NULL,
  user_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT NULL,
  PRIMARY KEY (department_id, user_id),
  INDEX (user_id),
  CONSTRAINT fk_dm_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  assigned_to INT NULL,
  created_by INT NULL,
  due_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (department_id),
  INDEX (status),
  INDEX (assigned_to),
  INDEX (created_by),
  CONSTRAINT fk_dt_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_dt_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dt_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order management: one pipeline sheet
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
  client_id INT NULL,
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
  article_doc_url VARCHAR(500) NOT NULL DEFAULT '',
  client_label VARCHAR(255) NOT NULL DEFAULT '',
  admin_user_id INT NULL,
  order_date DATE NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  site_price_row_id INT NULL,
  order_stage ENUM('processing','completed') NOT NULL DEFAULT 'processing',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (client_id, sort_order),
  INDEX (client_id, order_year, order_month),
  INDEX idx_oi_admin (admin_user_id),
  INDEX idx_oi_order_date (order_date),
  INDEX idx_oi_country (country),
  UNIQUE KEY uniq_order_items_site_price_row (site_price_row_id),
  INDEX idx_oi_order_stage (order_stage),
  CONSTRAINT fk_oi_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_oi_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS invoice_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NULL,
  event_type VARCHAR(40) NOT NULL DEFAULT '',
  actor_user_id INT NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  payload TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ie_invoice (invoice_id, id),
  INDEX idx_ie_type (event_type),
  CONSTRAINT fk_ie_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_ie_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Office expenses: Admin-only monthly office bills
CREATE TABLE IF NOT EXISTS office_expense_months (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_month CHAR(7) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_pkr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  row_count INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  saved_at DATETIME NULL,
  saved_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_office_expense_month (bill_month),
  INDEX (status),
  CONSTRAINT fk_oem_saved_by FOREIGN KEY (saved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_expense_rows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  month_id INT NOT NULL,
  paid_on DATE NOT NULL,
  category VARCHAR(20) NOT NULL DEFAULT 'other',
  description VARCHAR(255) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(8) NOT NULL DEFAULT 'eur',
  paid_by INT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (month_id, paid_on, sort_order, id),
  INDEX (paid_by),
  INDEX (category),
  INDEX (currency),
  CONSTRAINT fk_oer_month FOREIGN KEY (month_id) REFERENCES office_expense_months(id) ON DELETE CASCADE,
  CONSTRAINT fk_oer_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_oer_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_oer_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_expense_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  month_id INT NOT NULL,
  row_id INT NULL,
  actor_id INT NULL,
  kind VARCHAR(40) NOT NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (month_id, id),
  INDEX (actor_id),
  INDEX (row_id),
  CONSTRAINT fk_oee_month FOREIGN KEY (month_id) REFERENCES office_expense_months(id) ON DELETE CASCADE,
  CONSTRAINT fk_oee_row FOREIGN KEY (row_id) REFERENCES office_expense_rows(id) ON DELETE SET NULL,
  CONSTRAINT fk_oee_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semrush Research: Admin-seeded site names per country (Site Finding sheet)
CREATE TABLE IF NOT EXISTS semrush_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  country VARCHAR(100) NOT NULL,
  domain VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_semrush_country_domain (country, domain),
  INDEX (country),
  INDEX (updated_at),
  CONSTRAINT fk_semrush_site_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS semrush_sheet_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  country VARCHAR(100) NOT NULL,
  body TEXT NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (country, created_at),
  CONSTRAINT fk_semrush_comment_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS semrush_sheet_meta (
  country VARCHAR(100) NOT NULL PRIMARY KEY,
  last_writer_id INT NULL,
  last_writer_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_semrush_meta_user
    FOREIGN KEY (last_writer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Website prices (Office): publisher rate book, one country sheet
CREATE TABLE IF NOT EXISTS site_price_statuses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  label VARCHAR(120) NOT NULL,
  color VARCHAR(40) NOT NULL DEFAULT 'grey',
  lane ENUM('processing','new','other') NOT NULL DEFAULT 'other',
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 100,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_price_status_slug (slug),
  INDEX (lane),
  INDEX (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_price_rows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  country VARCHAR(100) NOT NULL,
  domain VARCHAR(255) NOT NULL,
  niche VARCHAR(512) NOT NULL DEFAULT '',
  da VARCHAR(40) NOT NULL DEFAULT '',
  dr VARCHAR(40) NOT NULL DEFAULT '',
  traffic VARCHAR(40) NOT NULL DEFAULT '',
  price_note TEXT NULL,
  extra_note VARCHAR(500) NOT NULL DEFAULT '',
  reply_email VARCHAR(190) NOT NULL DEFAULT '',
  row_tint VARCHAR(20) NOT NULL DEFAULT '',
  status_slug VARCHAR(80) NOT NULL DEFAULT 'new',
  sort_in_lane INT NOT NULL DEFAULT 0,
  identity_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  managed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_price_country_domain (country, domain),
  INDEX (country),
  INDEX (status_slug),
  INDEX (created_by),
  INDEX (managed_by),
  INDEX (country, status_slug, sort_in_lane),
  CONSTRAINT fk_spr_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_spr_managed FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_price_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  row_id INT NOT NULL,
  actor_id INT NULL,
  actor_role VARCHAR(20) NOT NULL DEFAULT '',
  kind VARCHAR(40) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (row_id, created_at),
  INDEX (actor_id),
  CONSTRAINT fk_spe_row FOREIGN KEY (row_id) REFERENCES site_price_rows(id) ON DELETE CASCADE,
  CONSTRAINT fk_spe_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
