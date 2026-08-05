-- Email campaign inventory: per-country sheets of URL + email for outreach sends.
CREATE TABLE IF NOT EXISTS email_campaign_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  country VARCHAR(100) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  domain VARCHAR(255) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  status ENUM('ready','emailed','replied','dealing','do_not_email') NOT NULL DEFAULT 'ready',
  campaign_wave VARCHAR(120) NOT NULL DEFAULT '',
  notes TEXT,
  last_emailed_at DATETIME NULL,
  replied_at DATETIME NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_campaign_email (email),
  INDEX (country),
  INDEX (status),
  INDEX (domain),
  INDEX (last_emailed_at),
  CONSTRAINT fk_ecc_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ecc_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
