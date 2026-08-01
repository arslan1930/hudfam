-- Publisher quote fields on catalog sites
ALTER TABLE sites
  ADD COLUMN publisher_quote_price DECIMAL(12,2) NULL AFTER traffic,
  ADD COLUMN publisher_quote_date DATE NULL AFTER publisher_quote_price;

-- Countries catalog (region folders)
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
