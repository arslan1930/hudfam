-- TechxForm simple schema: users + Our database + add history only.
-- Catalog / Emails / Orders / Published / Projects are not part of the app.

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
