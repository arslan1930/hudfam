# TechxForm on Hostinger

Plain **PHP + MySQL + HTML/CSS**. Internal Admin + Team linkbuilding inventory app.

Full product guide: [`../WEBSITE_GUIDE.md`](../WEBSITE_GUIDE.md)

## What you get after upload

| Admin | Team (by department) |
|-------|----------------------|
| Dashboard | Dashboard / My departments |
| Departments | Filter & add, Site adding history, Semrush Research |
| Our database | Extracting sites |
| Site adding history | Sites with emails – Team |
| Semrush Research | Admin emails search (view only) |
| Extracted Sites | Campaign search / Campaign drafts |
| Emails data (+ archive delete) | |
| Order management | |
| Invoices | |
| Users / Account | Change password |

## Fresh install

1. Create a MySQL database in hPanel → Databases.  
2. Upload **everything** from this repo’s `public_html/` folder into the domain web root.  
   Do **not** upload the repo’s `tests/` folder.  
3. Open `https://YOUR-DOMAIN/install.php` → enter DB details → Install.  
4. Copy the one-time passwords shown, then **delete `install.php`**.  
5. Sign in and change your password when prompted.  
6. Edit `config.php` (created by the installer):
   - Set `app_url` to `https://YOUR-DOMAIN` (no trailing slash)
   - Set `mail_from` (and SMTP if needed) for Admin email verify / forgot password
   - Set `invoice_company_*` keys for invoice letterhead (see `config.sample.php`)
7. Confirm `.htaccess` is present (denies `install.php` / `upgrade.php` once `config.php` exists).

## Upgrade an existing install

1. Upload the new `public_html/` files (overwrite).  
2. In `.htaccess`, **temporarily comment out** the three lines that deny `install|upgrade` when `config.php` exists (search for `install|upgrade`).  
3. Sign in as **Admin**, open `https://YOUR-DOMAIN/upgrade.php` → **Run upgrade**.  
4. **Delete `upgrade.php`**, then **uncomment** those `.htaccess` lines again.  
5. Hard-refresh the browser (Ctrl+F5).  
6. If you still use old demo passwords (`admin123` / `team123`), upgrade flags those accounts to change password on next login.

## PHP extensions

Enable **pdo_mysql** and **mbstring** in hPanel → PHP Configuration (usually already on).

## Correct layout on the server

```text
public_html/   (your domain web root)
  index.php
  asset.php
  .htaccess
  config.php          ← created by install; never commit / never share
  assets/
  includes/
  pages/admin/
  pages/team/
  sql/
```

## Security checklist after deploy

- [ ] `install.php` deleted (or blocked by `.htaccess` because `config.php` exists)
- [ ] `upgrade.php` deleted and `.htaccess` deny rule restored
- [ ] No `reset_admin_once.php`, `tests_run.php`, `tests_http.php`, or `smoke_admin_mvp.php` in the web root
- [ ] Admin Account → verify email if you need Forgot password
- [ ] Invoice company / IBAN filled in `config.php`

## Backups

In hPanel: export the MySQL database regularly, and keep a copy of `config.php` offline (it has DB and SMTP secrets).

## Troubleshooting

| Problem | Fix |
|--------|-----|
| 403 on install/upgrade after first install | Expected once `config.php` exists. For upgrade, temporarily comment the deny rule (see above). |
| 403 Forbidden on the site | Re-upload `.htaccess`; folders 755, files 644 |
| No design / broken JS | Upload `assets/` + `asset.php`; hard-refresh |
| Forgot password does nothing | Set `app_url` + `mail_from` (or SMTP) in `config.php`; verify Admin email under Account |
| Invoice shows blank company block | Fill `invoice_company_*` in `config.php` from `config.sample.php` |
| “Invalid or missing security token” | Reload the page and try again (CSRF session expired) |
| Too many failed sign-in attempts | Wait ~15 minutes, or have another Admin reset the password under Users |
