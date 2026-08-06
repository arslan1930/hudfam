# TechxForm on Hostinger (simple URL database)

Plain **PHP + MySQL + HTML/CSS**. No Catalog, Emails, Orders, or Published.

## Menu (only these)

| Admin | Team |
|-------|------|
| Dashboard | Dashboard (tasks on top) |
| Our database | Filter & add |
| Add sites | Added sites |
| Added sites | |
| Users (includes Tasks) | |
| Account | |

## Admin email & password reset

1. In `config.php` set `app_url` (https://your-domain.com) and `mail_from` (an address on your domain).
2. Optional: Hostinger SMTP (`smtp.hostinger.com`, port 465, ssl) if `mail()` fails.
3. Admin → **Account** → save email → **Send verification** → open link.
4. Login → **Forgot password?** works only for verified Admin emails. Team passwords are set by Admin on Users.

## Deploy (important — wipe old files)

Old Catalog/Email/Order pages stay visible if you only overwrite some files.
Do a **clean replace**:

1. In File Manager, open your domain web root (`public_html`).
2. **Delete** these if they still exist:
   - `pages/admin/sites.php`, `site_form.php`, `bulk_import.php`, `catalog_site_form.php`
   - `pages/admin/projects.php`, `project_*.php`, `pitch_*.php`
   - `pages/admin/email_*.php`, `clients.php`, `client_*.php`, `order_*.php`, `orders_export.php`, `published.php`, `countries.php`
   - `pages/team/search.php`, `sites.php`, `site_form.php`, `projects.php`, `project_*.php`, `results.php`
   - `pages/team/email_*.php`, `countries.php`, `country_detail.php`
   - `includes/inventory.php`, `email_campaigns.php`, `country_catalog.php`, `orders.php`
3. Upload **everything** from the repo’s `public_html/` folder.
4. Open `https://YOUR-DOMAIN/upgrade.php` once → **Run upgrade** (drops old DB tables).
5. Delete `upgrade.php` and `install.php` (if already installed).
6. Hard-refresh the browser (Ctrl+F5). Sidebar should show only the simple menu.

## Fresh install
1. Create MySQL DB in hPanel  
2. Upload `public_html/` contents  
3. Open `install.php` → Install  
4. Delete `install.php`  
5. Login: `admin` / `admin123` or `teammate` / `team123`

## Correct layout
```text
public_html/
  index.php
  asset.php
  .htaccess
  assets/css/app.css
  includes/   (auth, db, geo, guides, helpers, layout, prospects only)
  pages/admin/ (dashboard, prospects, prospect_add, prospect_batches, prospect_batch, users)
  pages/team/  (dashboard, prospect_check, prospects, prospect_form, prospect_batches, prospect_batch)
  sql/
```

## Troubleshooting
| Problem | Fix |
|--------|-----|
| Still see Catalog / Emails / Orders | Old files on server — delete them (list above), re-upload, run `upgrade.php` |
| 403 Forbidden | Re-upload `.htaccess`; folders 755, files 644 |
| No design | Upload `assets/` + `asset.php` |
