-- Sites with emails (run once, or use /upgrade.php)
CREATE TABLE IF NOT EXISTS sites_with_emails (
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
  UNIQUE KEY uniq_swe_country_domain (country, domain),
  INDEX (country),
  INDEX (domain),
  INDEX (pushed_by),
  CONSTRAINT fk_swe_pushed_by FOREIGN KEY (pushed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
