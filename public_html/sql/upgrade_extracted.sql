-- Extracted URLs: sites pushed from Team Extracting Results (per country)
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
