-- TechxForm simple schema: users + Our database + add history only.
-- Catalog / Emails / Orders / Published / Projects are not part of the app.

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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (role),
  INDEX (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  purpose ENUM('email_verify','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token_hash (token_hash),
  INDEX (user_id, purpose),
  INDEX (expires_at),
  CONSTRAINT fk_auth_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
  UNIQUE KEY uniq_prospect_domain (domain),
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
  list_cleared_at DATETIME NULL DEFAULT NULL,
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
  country VARCHAR(100) NOT NULL DEFAULT '',
  prospect_site_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_batch_domain (batch_id, domain),
  INDEX (domain),
  INDEX (country),
  CONSTRAINT fk_pbi_batch FOREIGN KEY (batch_id) REFERENCES prospect_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_pbi_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin assigns work to teammates
CREATE TABLE IF NOT EXISTS team_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  notes TEXT NULL,
  country VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  niche VARCHAR(255) NOT NULL DEFAULT '',
  work_type VARCHAR(40) NOT NULL DEFAULT 'sites',
  target_count INT NULL,
  status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  assigned_to INT NOT NULL,
  created_by INT NOT NULL,
  due_date DATE NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (assigned_to, status),
  INDEX (created_by),
  INDEX (country),
  INDEX (due_date),
  CONSTRAINT fk_task_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extracting Sites with Emails pipeline
CREATE TABLE IF NOT EXISTS extract_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT '',
  notes TEXT NULL,
  submitted_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_queue_country_domain (country, domain),
  INDEX (country),
  INDEX (submitted_by),
  INDEX (created_at),
  CONSTRAINT fk_eq_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extract_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT '',
  notes TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_sites_country_domain (country, domain),
  INDEX (country),
  INDEX (added_by),
  CONSTRAINT fk_es_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extract_site_emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  extract_site_id INT NOT NULL,
  email VARCHAR(190) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_extract_email (extract_site_id, email),
  INDEX (extract_site_id),
  CONSTRAINT fk_ese_site FOREIGN KEY (extract_site_id) REFERENCES extract_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ese_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extract_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token CHAR(32) NOT NULL,
  user_id INT NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT '',
  queue_ids_json TEXT NOT NULL,
  domains_json TEXT NOT NULL,
  status ENUM('pending','opened') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  opened_at DATETIME NULL,
  UNIQUE KEY uniq_extract_claim_token (token),
  INDEX (user_id, status),
  CONSTRAINT fk_ec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
