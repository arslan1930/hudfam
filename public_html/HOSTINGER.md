# TechxForm on Hostinger (simple URL database)

Plain **PHP + MySQL + HTML/CSS**. No Catalog, Emails, Orders, or Published.

## Menu (only these)

| Admin | Team |
|-------|------|
| Dashboard | Dashboard |
| Our database | Filter & add |
| Add sites | Our database |
| Add history | Add history |
| Users | |

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
  includes/   (auth, db, geo, guides, helpers, layout, prospects, extract, account, mail)
  pages/admin/ (dashboard, prospects*, users, tasks, account, extract_sites, extract_emails)
  pages/team/  (dashboard, prospect*, tasks, extract_submit, extract_queue, extract_work, extract_final, extract_emails)
  sql/
```

## Troubleshooting
| Problem | Fix |
|--------|-----|
| Still see Catalog / Emails / Orders | Old files on server — delete them (list above), re-upload, run `upgrade.php` |
| 403 Forbidden | Re-upload `.htaccess`; folders 755, files 644 |
| No design | Upload `assets/` + `asset.php` |
