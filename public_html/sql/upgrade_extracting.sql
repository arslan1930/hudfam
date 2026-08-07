-- Extracting sites: Sites list + Extracting Results per country (team dual path)
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
