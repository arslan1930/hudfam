# AGENTS.md

TechxForm / Hudfam — a linkbuilding inventory app. Plain **PHP + MySQL + HTML/CSS**, no framework and no build step. The deployable app lives entirely in [`public_html/`](public_html/). See [`README.md`](README.md) and [`public_html/HOSTINGER.md`](public_html/HOSTINGER.md) for the product overview and deploy steps.

## Cursor Cloud specific instructions

The VM snapshot already has PHP 8.3 (with `pdo_mysql`) and MariaDB 10.11 installed. There is **no package manager** for this app (no `composer.json`, no `package.json`), so there are no per-run dependency installs. "Lint" for this codebase is PHP's built-in syntax checker.

### Start the services (needed once per fresh VM)

MariaDB does not auto-start and its runtime socket dir lives on tmpfs (recreated empty on boot), so start it explicitly:

```bash
sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld
sudo mariadbd --user=mysql > /tmp/mariadb.log 2>&1 &   # wait ~5s; check with: sudo mariadb -e "SELECT 1"
```

Then serve the app from `public_html/`:

```bash
cd public_html && php -S 127.0.0.1:8080     # http://127.0.0.1:8080
```

### Database + config (gotchas)

- The app reads DB creds from `public_html/config.php`, which is **git-ignored** (created by `install.php` / copied from `config.sample.php`). It, and the MariaDB data dir, are captured in the VM snapshot, so on most runs the DB (`techxform`) and `config.php` already exist — you only need to (re)start the services above.
- Use host **`127.0.0.1`** (TCP) in `config.php`, not `localhost`. PHP's `pdo_mysql` default socket is `/var/run/mysqld/mysqld.sock` but MariaDB binds its socket at `/run/mysqld/mysqld.sock`, so a `localhost` (socket) connection fails; TCP avoids this.
- If starting from an empty DB: create it with `sudo mariadb -e "CREATE DATABASE IF NOT EXISTS techxform CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'techxform'@'localhost' IDENTIFIED BY 'techxform'; GRANT ALL ON techxform.* TO 'techxform'@'localhost'; FLUSH PRIVILEGES;"`, then run the installer once: POST to `install.php` (creates schema, seeds demo data, and writes `config.php`), e.g. `curl -X POST http://127.0.0.1:8080/install.php --data-urlencode db_host=127.0.0.1 --data-urlencode db_name=techxform --data-urlencode db_user=techxform --data-urlencode db_pass=techxform`.

### Lint / test / run

- **Lint (syntax check):** `find public_html -name '*.php' -exec php -l {} \;` — the repo has no other linter and no automated test suite.
- **Run:** `php -S 127.0.0.1:8080` from `public_html/` (see above).
- **Seed logins:** `admin` / `admin123`, `admin2` / `admin123`, `teammate` / `team123`.
