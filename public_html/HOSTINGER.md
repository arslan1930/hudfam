# Run Hudfam on Hostinger shared hosting

This folder is a **plain PHP + MySQL** app. It works on Hostinger shared hosting (no Django / Node / Docker).

## 1. Create MySQL database
1. Hostinger hPanel → **Databases → MySQL Databases**
2. Create database + user + password
3. Note: host (usually `localhost`), db name, user, password

## 2. Upload files
Upload **everything inside** `public_html/` to your domain’s `public_html` (or subdomain folder):

- `index.php`
- `install.php`
- `assets/`
- `includes/`
- `pages/`
- `sql/`
- `config.sample.php`
- `.htaccess`

Do **not** upload the Django files (`manage.py`, `core/`, etc.) into `public_html`.

### Easy ways
- Git on server (if SSH enabled): clone repo, then copy `public_html/*` into the web root  
- Or zip `public_html` and upload via File Manager / FTP

## 3. Install
1. Open `https://YOUR-DOMAIN/install.php`
2. Enter MySQL details → **Install**
3. Delete `install.php` after success

## 4. Login
- Admin: `admin` / `admin123`
- Team: `teammate` / `team123`

Change passwords under **Admin → Users**.

## 5. Use the workflow
1. **Admins** (multiple): each has unique name + contact details; assign collaborating admins per project
2. Admin opens a **project → Catalog**: country **sheets** (Germany, Austria, …). Fill daily via Filter & add (set Country) or CSV
3. Admin fills **DR, traffic, quote & agreed prices** on sites in each sheet
4. **Team → Search all sheets** (one bar): checks every country sheet + Our inventory; shows comments (already have it, used, low traffic, …)
5. **Team → project → Filter & add**: only domains new across catalogs + inventory; lands in the chosen country sheet
6. Global **Our inventory** (Prospects) Filter & add stays separate for outreach lists / dated batches
7. Admin sends packs / publication orders as before

### Already installed earlier?
Open `upgrade.php` once, then delete it.

## Troubleshooting
| Problem | Fix |
|--------|-----|
| 404 on domain | Files not in correct `public_html` / wrong domain document root |
| **No design / unstyled page** | Upload the **`assets/`** folder (must include `assets/css/app.css` and `assets/img/techxform-logo.svg`). Also upload **`asset.php`**. Open `https://YOUR-DOMAIN/asset.php?f=css/app.css` — you should see CSS text. If that 404s, files are in the wrong folder (do not nest `public_html/public_html`). |
| Filter / Prospects errors | Open `upgrade.php` once (creates prospect tables), then delete it. Newer builds also auto-create those tables on first use. |
| Still says Hudfam | Edit `config.php` and set `'app_name' => 'TechxForm'`. |
| Database error on install | Check DB name/user/password; host is usually `localhost` |
| Blank page | Enable PHP error display temporarily, or check hPanel error logs |
| Permission denied writing config | Create `config.php` manually from `config.sample.php` |

### Correct upload layout (web root)
```text
public_html/          ← Hostinger web root for your domain
  index.php
  asset.php           ← serves CSS if /assets URL fails
  install.php
  assets/
    css/
      app.css         ← required for design
  includes/
  pages/
  sql/
  .htaccess
```
