#!/usr/bin/env bash
#
# Cloud Agent install phase for TechxForm / Hudfam.
#
# Prepares durable state that is captured in the environment build snapshot:
#   - PHP 8.3 (with pdo_mysql + curl) and MariaDB 10.11 packages
#   - the `techxform` database and app user
#   - the app's config.php + schema + demo data (via the HTTP installer)
#   - the documented seed logins (see AGENTS.md)
#
# Per-boot service startup lives in start.sh, not here. This script is
# idempotent: re-running it against an already-prepared VM is a no-op.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$REPO_ROOT/public_html"

DB_NAME="techxform"
DB_USER="techxform"
DB_PASS="techxform"

wait_for_db() {
  for _ in $(seq 1 30); do
    if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

echo "==> Installing system packages (php, mariadb)"
sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
  php-cli php-mysql php-curl mariadb-server

echo "==> Ensuring MariaDB is running"
# MariaDB's runtime socket dir lives on tmpfs and is recreated empty on boot.
sudo mkdir -p /run/mysqld
sudo chown mysql:mysql /run/mysqld
if ! sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
  sudo mariadbd --user=mysql >/tmp/mariadb-install.log 2>&1 &
  wait_for_db || { echo "MariaDB did not come up"; cat /tmp/mariadb-install.log; exit 1; }
fi

echo "==> Ensuring database + user exist"
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;"

# Use TCP host 127.0.0.1 (not localhost): PHP's pdo_mysql default socket path
# differs from where MariaDB binds its socket, so a socket connection fails.
if [ ! -f "$APP_DIR/config.php" ]; then
  echo "==> Running the app installer (schema + demo data + config.php)"
  ( cd "$APP_DIR" && php -S 127.0.0.1:8091 >/tmp/php-install.log 2>&1 ) &
  PHP_PID=$!
  for _ in $(seq 1 30); do
    if curl -s -o /dev/null "http://127.0.0.1:8091/index.php?page=login"; then break; fi
    sleep 1
  done
  curl -s -X POST http://127.0.0.1:8091/install.php \
    --data-urlencode db_host=127.0.0.1 \
    --data-urlencode db_name="${DB_NAME}" \
    --data-urlencode db_user="${DB_USER}" \
    --data-urlencode db_pass="${DB_PASS}" >/tmp/install-result.html
  kill "$PHP_PID" 2>/dev/null || true
  wait "$PHP_PID" 2>/dev/null || true

  echo "==> Setting documented seed passwords (AGENTS.md)"
  HASH_ADMIN="$(php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")"
  HASH_TEAM="$(php -r "echo password_hash('team123', PASSWORD_DEFAULT);")"
  sudo mariadb "${DB_NAME}" -e "
    UPDATE users SET password_hash='${HASH_ADMIN}' WHERE username IN ('admin','admin2');
    UPDATE users SET password_hash='${HASH_TEAM}' WHERE username='teammate';"
else
  echo "==> config.php already present; skipping app install"
fi

echo "==> Install phase complete"
