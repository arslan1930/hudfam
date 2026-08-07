-- Office departments (or use /upgrade.php)
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_department_slug (slug),
  UNIQUE KEY uniq_department_name (name),
  INDEX (sort_order),
  INDEX (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department_members (
  department_id INT NOT NULL,
  user_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT NULL,
  PRIMARY KEY (department_id, user_id),
  INDEX (user_id),
  CONSTRAINT fk_dm_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  assigned_to INT NULL,
  created_by INT NULL,
  due_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (department_id),
  INDEX (status),
  INDEX (assigned_to),
  INDEX (created_by),
  CONSTRAINT fk_dt_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_dt_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dt_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO departments (slug, name, sort_order, is_active) VALUES
  ('site_finding', 'Site Finding', 10, 1),
  ('site_extracting', 'Site Extracting', 20, 1),
  ('email_extracting', 'Email Extracting', 30, 1),
  ('communication', 'Communication Team', 40, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), is_active=1;
