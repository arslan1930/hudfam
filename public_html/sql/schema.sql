CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  role ENUM('admin','team') NOT NULL DEFAULT 'team',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  client_name VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(190) NOT NULL DEFAULT '',
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  niche VARCHAR(255) NOT NULL DEFAULT '',
  countries VARCHAR(500) NOT NULL DEFAULT '',
  region_focus VARCHAR(255) NOT NULL DEFAULT '',
  budget VARCHAR(100) NOT NULL DEFAULT '',
  price_min DECIMAL(12,2) NULL,
  price_max DECIMAL(12,2) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
  min_dr INT NULL,
  min_da INT NULL,
  min_traffic INT NULL,
  avoid_notes TEXT,
  workflow_notes TEXT,
  requirements_brief TEXT,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status),
  INDEX (niche),
  CONSTRAINT fk_projects_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_members (
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL UNIQUE,
  url VARCHAR(500) NOT NULL DEFAULT '',
  region VARCHAR(40) NOT NULL DEFAULT '',
  country VARCHAR(100) NOT NULL DEFAULT '',
  niche VARCHAR(255) NOT NULL DEFAULT '',
  language VARCHAR(50) NOT NULL DEFAULT '',
  dr INT NULL,
  da INT NULL,
  traffic INT NULL,
  backlink_price DECIMAL(12,2) NULL,
  banner_price_yearly DECIMAL(12,2) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
  status ENUM('draft','negotiating','agreed','sent','rejected','processing','completed','blocked') NOT NULL DEFAULT 'draft',
  publisher_email VARCHAR(190) NOT NULL DEFAULT '',
  outreach_notes TEXT,
  warning_flags VARCHAR(500) NOT NULL DEFAULT '',
  assigned_to INT NULL,
  created_by INT NULL,
  primary_project_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status),
  INDEX (country),
  INDEX (niche),
  INDEX (assigned_to),
  INDEX (dr),
  INDEX (da),
  INDEX (traffic),
  CONSTRAINT fk_sites_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sites_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sites_project FOREIGN KEY (primary_project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pitches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','sent','closed') NOT NULL DEFAULT 'draft',
  notes TEXT,
  sent_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pitches_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pitches_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pitch_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pitch_id INT NOT NULL,
  site_id INT NOT NULL,
  offered_price DECIMAL(12,2) NULL,
  item_status ENUM('sent','rejected','processing','completed') NOT NULL DEFAULT 'sent',
  reject_reason_code VARCHAR(40) NOT NULL DEFAULT '',
  reject_comment TEXT,
  client_notes TEXT,
  live_link VARCHAR(500) NOT NULL DEFAULT '',
  updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pitch_site (pitch_id, site_id),
  INDEX (item_status),
  CONSTRAINT fk_pi_pitch FOREIGN KEY (pitch_id) REFERENCES pitches(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS published_placements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  site_id INT NOT NULL,
  pitch_item_id INT NULL,
  live_link VARCHAR(500) NOT NULL DEFAULT '',
  notes TEXT,
  created_by INT NULL,
  published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id),
  INDEX (site_id),
  CONSTRAINT fk_pub_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pub_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_pub_item FOREIGN KEY (pitch_item_id) REFERENCES pitch_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_pub_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
