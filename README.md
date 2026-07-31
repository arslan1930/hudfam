# Hudfam

Linkbuilding inventory and client publication workflow — **Admin** + **Team** panels.

Plain **PHP + MySQL** app for Hostinger shared hosting (and any PHP host).

## Features

- Project folders per campaign (`rexbo.de`, `xyw.com`, …)
- Team adds negotiated sites; Admin sends packs and updates statuses
- **Client email folders** under each project (name + email)
- **Publication orders**: article URL, date sent, client price, live URL → mark completed
- Download CSV spreadsheet of order records

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
Upload the new files, then open `/upgrade.php` once to add client/order tables, then delete it.

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
