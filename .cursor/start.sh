#!/usr/bin/env bash
#
# Cloud Agent start phase for TechxForm / Hudfam.
#
# Runs on every boot. MariaDB does not auto-start and its runtime socket dir
# lives on tmpfs (recreated empty on boot), so (re)create it and launch the
# daemon. The database files themselves persist in the environment snapshot.
# The PHP dev server runs as a visible `terminals` process, not here.
set -euo pipefail

echo "==> Preparing MariaDB runtime dir"
sudo mkdir -p /run/mysqld
sudo chown mysql:mysql /run/mysqld

if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
  echo "==> MariaDB already running"
  exit 0
fi

echo "==> Starting MariaDB"
sudo mariadbd --user=mysql >/tmp/mariadb.log 2>&1 &

for _ in $(seq 1 30); do
  if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
    echo "==> MariaDB ready"
    exit 0
  fi
  sleep 1
done

echo "==> MariaDB failed to start" >&2
cat /tmp/mariadb.log >&2 || true
exit 1
