-- Dated prospect batches (reference; prefer upgrade.php)

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
