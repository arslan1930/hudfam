# Run TechxForm on Hostinger shared hosting

This folder is a **plain PHP + MySQL + HTML + CSS** app.  
**No Django, Node.js, React, npm, or Docker.**

## What the app does
One shared **URL database**:
1. **Admin** adds URLs  
2. **Team** filters new lists against the database and adds only unique sites  
3. **Add history** shows who added what, by day  

Removed (not in the app anymore): Catalog, Email campaigns, Orders, Published, Projects.

## 1. Create MySQL database
1. Hostinger hPanel → **Databases → MySQL Databases**
2. Create database + user + password
3. Note: host (usually `localhost`), db name, user, password

## 2. Upload files
Upload **everything inside** `public_html/` to your domain’s web root:

- `index.php`, `asset.php`, `install.php`, `upgrade.php`
- `assets/`, `includes/`, `pages/`, `sql/`
- `config.sample.php`, `.htaccess`

Do **not** nest `public_html/public_html`.

## 3. Install
1. Open `https://YOUR-DOMAIN/install.php`
2. Enter MySQL details → **Install**
3. Delete `install.php` after success

## 4. Login
- Admin: `admin` / `admin123`
- Team: `teammate` / `team123`

Change passwords under **Admin → Users**.

## 5. Daily use
| Role | Menu |
|------|------|
| Admin | Dashboard · Our database · Add URLs · Add history · Users |
| Team | Dashboard · Filter & add · Our database · Add history |

### Already installed earlier?
Open `upgrade.php` once, then delete it.

## Troubleshooting
| Problem | Fix |
|--------|-----|
| **403 Forbidden** | Re-upload `.htaccess`. Folders **755**, files **644**. Open `index.php?page=login`. |
| 404 on domain | Wrong document root / nested folder |
| **No design** | Upload `assets/` + `asset.php`. Test `asset.php?f=css/app.css` |
| Filter / database errors | Run `upgrade.php` once, then delete it |
| Database error on install | Check DB name/user/password; host usually `localhost` |
| Blank page | Check hPanel error logs |

### Correct upload layout
```text
public_html/          ← Hostinger web root
  index.php
  asset.php
  install.php
  assets/css/app.css
  includes/
  pages/
  sql/
  .htaccess
```
