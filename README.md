# Hudfam / TechxForm

Linkbuilding inventory and client publication workflow — **Admin** + **Team** panels.

## Stack (plain code only)

- **PHP** + **MySQL** + **HTML** + **CSS**
- Optional **AJAX / JSON** form posts (same PHP pages)
- Runs on **Hostinger shared hosting** (upload `public_html/`)

**Not used:** Django, Node.js, React, Next.js, npm, Docker, Render/Railway Python deploy.

Laravel is fine in principle; this app stays **plain PHP** (no framework) so Hostinger install stays one-folder upload + `install.php`.

## Features

- **Global country catalogs** — Admin Catalog = country folders; manual Add + Bulk import per country (no project required)
- **Team catalog search** — select country first, then search/add against that country’s Admin data
- **Two-box Team filter** — old prospect inventory vs new paste; unique results add to both old list and dated batches
- **Project catalog** — country sheets, Filter & add, Admin prices (DR/traffic/agreed) for pitching
- **Email campaign inventory** — country sheets of URL + email; Admin export Ready; Team paste-cut Replied/Dealing
- **Client folders** + publication orders + CSV export
- Multi-admin collaboration per project

## Deploy on Hostinger

Full steps: **[public_html/HOSTINGER.md](public_html/HOSTINGER.md)**

1. Create a MySQL database in hPanel  
2. Upload everything inside [`public_html/`](public_html/) to your domain’s web root  
3. Open `https://YOUR-DOMAIN/install.php` → enter DB details → Install  
4. Copy the one-time passwords shown on the install screen, then **delete `install.php`**  
5. Login with those passwords — you will be asked to set a new password immediately  

### Already installed an older copy?
Upload the new files, sign in as Admin, open `/upgrade.php` once (Admin-only), then delete it.  
If anyone still uses old demo passwords (`admin123` / `team123`), upgrade flags them to change on next login.

## Local preview (optional)

```bash
# With PHP + MySQL available:
cd public_html
# create config.php from config.sample.php, import sql/schema.sql, then:
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
