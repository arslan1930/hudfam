# Hudfam

Linkbuilding inventory and client publication workflow — **Admin** + **Team** panels.

Plain **PHP + MySQL** app for Hostinger shared hosting (and any PHP host).

## Features

- **Admin inventory** — language, country, DA, DR, traffic, order status, comments, client name
- **Bulk CSV import** — Admin can load 10,000+ sites into a project
- **Team Super search** — domain lookup across the DB with **site details only** (no client/project secrets)
- Per-project work lists, publisher quote + agreed price, our mailbox fields
- Client folders + publication orders + CSV export

## Deploy on Hostinger

Full steps: **[public_html/HOSTINGER.md](public_html/HOSTINGER.md)**

1. Create a MySQL database in hPanel  
2. Upload everything inside [`public_html/`](public_html/) to your domain’s web root  
3. Open `https://YOUR-DOMAIN/install.php` → enter DB details → Install  
4. Delete `install.php` (and `upgrade.php` if you used it)  
5. Login:
   - Admin: `admin` / `admin123`
   - Team: `teammate` / `team123`

### Already installed an older copy?
Upload the new files, then open `/upgrade.php` once (clients/orders + countries + quote fields), then delete it.

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
  assets/
  includes/
  pages/admin/
  pages/team/
  sql/
```
