-- Sites with emails - Team / Admin (or use /upgrade.php)
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

-- All sites with emails - Final (Admin-only mirror of sites_with_emails_admin)
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

-- Optional: move legacy single-table data into Team working copy
-- INSERT IGNORE INTO sites_with_emails_team
--   (domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by, created_at, updated_at)
-- SELECT domain, country, language, region, email1, email2, email3, email4, extract_batch_id, pushed_by, created_at, updated_at
-- FROM sites_with_emails;
