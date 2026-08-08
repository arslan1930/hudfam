-- Email campaign sheets (or use /upgrade.php)
CREATE TABLE IF NOT EXISTS email_campaign_sheets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_campaign_sheet_name (name),
  INDEX (updated_at),
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

-- Existing installs: emailed checkpoint (same rule as Sites with emails - Admin), per sheet.
-- Safe to re-run: skips when columns already exist (use /upgrade.php or ensure_email_campaign_schema()).
-- ALTER TABLE email_campaign_rows
--   ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER email4,
--   ADD COLUMN email_sent_at TIMESTAMP NULL DEFAULT NULL AFTER email_sent,
--   ADD INDEX idx_email_campaign_sheet_sent (sheet_id, email_sent);
