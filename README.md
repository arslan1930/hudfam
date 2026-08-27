# Hudfam / TechxForm

Linkbuilding inventory and client publication workflow — **Admin** + **Team** panels.

## Stack (plain code only)

- **PHP** + **MySQL** + **HTML** + **CSS**
- Optional **AJAX / JSON** form posts (same PHP pages)
- Runs on **Hostinger shared hosting** (upload `public_html/`)

**Not used:** Django, Node.js, React, Next.js, npm, Docker, Render/Railway Python deploy.

Laravel is fine in principle; this app stays **plain PHP** (no framework) so Hostinger install stays one-folder upload + `install.php`.

## Features

- **Departments** — Site Finding, Site Extracting, Email Extracting, Communication; Team logins only see assigned tools
- **Our database** — Admin-only country folders of unique sites (Team cannot browse these lists)
- **Filter & add** — Team pastes a list; duplicates vs Our database are removed privately; only unique sites are saved
- **Semrush Research** — shared country notes/lists between Admin and Extracting
- **Extracting sites** — per-country Sites list + Extracting Results; Push fills Extracted Sites and Sites with emails – Team
- **Sites with emails** — Team sheet → Push to Admin archive (also synced to Final archive)
- **Email campaign projects** — Admin country sheets (site + up to 4 emails); Communication Team uses Campaign search + drafts (copy into an email client)
- **Orders** + **Invoices** — Admin one-sheet orders, unpaid LIVE push to printable bills (email or name, no client folder)
- **Users** — Admin and Team logins; department assignment; temp passwords

Retired names (do not look for these in the sidebar): Catalog, Project catalog, Client folders.

## Deploy on Hostinger

Full steps: **[public_html/HOSTINGER.md](public_html/HOSTINGER.md)**

1. Create a MySQL database in hPanel  
2. Upload everything inside [`public_html/`](public_html/) to your domain’s web root  
3. Open `https://YOUR-DOMAIN/install.php` → enter DB details → Install  
4. Copy the one-time passwords shown on the install screen, then **delete `install.php` and `upgrade.php`**  
5. Login with those passwords — you will be asked to set a new password immediately  

Use host **`127.0.0.1`** (or the host shown in hPanel), not `localhost`, if PHP and MySQL use different sockets.

### Already installed an older copy?
Upload the new files, sign in as Admin, open `/upgrade.php` once (Admin-only), then delete it.  
If anyone still uses old demo passwords (`admin123` / `team123`), upgrade flags them to change on next login.

## Local preview (optional)

```bash
# With PHP + MySQL available:
cd public_html
# create config.php from config.sample.php (db_host=127.0.0.1), import sql/schema.sql, then:
php -S 127.0.0.1:8080
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
```
