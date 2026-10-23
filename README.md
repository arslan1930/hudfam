# Hudfam / TechxForm

Internal **Admin + Team** tool for linkbuilding inventory and client publication workflow.

**Team handbook (how the whole site works):** [`WEBSITE_GUIDE.md`](WEBSITE_GUIDE.md)

## Stack (plain code only)

- **PHP** + **MySQL** + **HTML** + **CSS**
- Optional **AJAX / JSON** form posts (same PHP pages)
- Runs on **Hostinger shared hosting** (upload `public_html/`)

**Not used:** Django, Node.js, React, Next.js, npm, Docker, Composer.

## Features (current)

- **Our database** — Admin country folders of unique root domains
- **Team Filter & add** — paste a list vs one country; duplicates removed privately; uniques added to Our database + Site adding history + Extracting list
- **Departments** — Site Finding, Site Extracting, Email Extracting, Communication Team (tools unlock per assignment)
- **Extracting sites** — per-country Sites list + Extracting Results + Push → Extracted Sites + Sites with emails – Team
- **Emails data** — Admin archive, Final mirror, Email campaign projects/sheets/drafts
- **Order management** + **Invoices** (printable; company/bank details from `config.php`)
- **Semrush Research** — country research sheets (Admin seed / Team edit + comments)
- Multi-admin users; Team passwords set by Admin; Admin forgot-password via verified email

## Deploy on Hostinger

Full steps: **[public_html/HOSTINGER.md](public_html/HOSTINGER.md)**

1. Create a MySQL database in hPanel  
2. Upload everything inside [`public_html/`](public_html/) to your domain’s web root  
3. Open `https://YOUR-DOMAIN/install.php` → enter DB details → Install  
4. Copy the one-time passwords shown on the install screen, then **delete `install.php`**  
5. Login and set a new password when prompted  
6. Fill mail + invoice company keys in `config.php` (see `config.sample.php`)

### Already installed an older copy?

Upload the new files. To run the schema upgrade:

1. Temporarily comment the `install|upgrade` deny rule in `.htaccess` (see HOSTINGER.md)
2. Sign in as Admin → open `/upgrade.php` once → **Run upgrade**
3. Delete `upgrade.php` and restore the `.htaccess` deny rule  
4. Hard-refresh (Ctrl+F5)

## Local preview (optional)

```bash
# With PHP + MySQL available:
cd public_html
# create config.php from config.sample.php (use 127.0.0.1 for db_host on some VMs), then:
php -S 127.0.0.1:8080
```

Lint / smoke (from repo root):

```bash
find public_html tests -name '*.php' -exec php -l {} \;
php tests/smoke_admin_mvp.php
```

Integration tests (need a running app + DB; not for production web root):

```bash
php tests/tests_run.php
TXF_BASE=http://127.0.0.1:8080 php tests/tests_http.php
```

## Project layout

```text
public_html/          ← upload this folder’s contents to the server
  index.php
  install.php
  upgrade.php
  asset.php
  assets/
  includes/
  pages/admin/
  pages/team/
  sql/
tests/                ← CLI smoke / integration tests (not uploaded to Hostinger)
.github/workflows/    ← CI: php -l + smoke
```

## Security notes

- Sessions use HttpOnly / SameSite cookies; CSRF required on POST (forms + AJAX)
- Login throttling after repeated failures (per username + IP)
- `install.php` / `upgrade.php` are denied by `.htaccess` once `config.php` exists
- Test scripts live under `tests/`, not the web root
- App pages send `noindex` / basic security headers (internal tool)
