# TechxForm on Hostinger

Plain **PHP + MySQL + HTML/CSS**. Upload the repo’s `public_html/` folder to the domain web root.

## Menu (current)

| Admin | Team (by department) |
|-------|----------------------|
| Dashboard | Dashboard / My departments |
| Departments | Filter & add (Site Finding) |
| Our database | Semrush Research (Site Finding) |
| Site adding history | Site adding history |
| Semrush Research | Extracting sites (Site Extracting) |
| Extracted Sites | Sites with emails – Team (Email Extracting) |
| Emails data (Admin / Final / campaigns) | Admin emails search |
| Order management | Campaign search + drafts (Communication) |
| Invoices | |
| Users | |
| Account | Change password |

Our database country lists are **Admin only**. Team uses Filter & add; they never browse Our database.

**Do not delete** live modules such as `includes/email_campaigns.php`, `includes/orders.php`, or Emails/Orders pages. Those are part of the app.

## Deploy (clean replace of old Catalog files only)

Old **Catalog / pitch / published** filenames can linger if you only overwrite some files. Remove those **retired** names if they still exist, then upload the full `public_html/` from this repo:

- `pages/admin/sites.php`, `site_form.php`, `bulk_import.php`, `catalog_site_form.php`
- `pages/admin/projects.php`, `project_*.php`, `pitch_*.php`
- `pages/admin/clients.php`, `published.php`, `countries.php` (old catalog — **not** current Orders)
- `pages/team/search.php`, `sites.php`, `projects.php`, `results.php`

Do **not** delete current files such as `includes/email_campaigns.php`, `includes/orders.php`, `pages/admin/orders.php`, or `pages/admin/email_campaigns_app.php`.

Then:

1. Upload **everything** from the repo’s `public_html/` folder.
2. Opening the app as Admin is usually enough: each page runs `ensure_*()` and adds missing tables/columns.
3. If you still need the one-shot upgrader (legacy Catalog table drops): `.htaccess` **denies** `upgrade.php`. Temporarily comment `upgrade` out of the FilesMatch, sign in as Admin, open `/upgrade.php` → **Run upgrade**, then restore the deny line.
4. **Delete `upgrade.php` and `install.php`** (installer refuses to run again once `config.php` exists).
5. Do **not** rely on `.htaccess` alone for `tests_run.php`, `tests_http.php`, `smoke_admin_mvp.php`, or `reset_admin_once.php` — those files refuse web hits (`PHP_SAPI !== 'cli'`). Delete them from production if you uploaded the whole folder.
6. Hard-refresh the browser (Ctrl+F5).

## Fresh install

1. Create MySQL DB in hPanel  
2. Upload `public_html/` contents  
3. Open `install.php` → Install  
   - Database host: the host from hPanel, or **`127.0.0.1`**. Avoid `localhost` (PHP may use a different MySQL socket).  
4. Copy the one-time passwords shown, then **delete `install.php`** (and `upgrade.php`)  
5. Login and set a new password when prompted

## PHP extensions

Enable **pdo_mysql** and **mbstring** in hPanel → PHP Configuration (Hostinger usually has both on).

## Mail (Admin verify + Admin password reset)

Set `mail_from` to an address on your domain. If `mail()` fails, fill SMTP in `config.php` (`smtp.hostinger.com`, port 465, ssl). Team members cannot self-reset; Admin sets a temp password.

## Correct layout

```text
public_html/
  index.php
  asset.php
  .htaccess
  install.php          ← delete after install
  upgrade.php          ← delete after upgrade (also denied by .htaccess)
  tests_*.php          ← CLI only; delete from production
  reset_admin_once.php ← CLI only; do not leave on the server
  assets/
  includes/            ← keep email_campaigns.php, orders.php, invoices.php, departments.php
  pages/admin/
  pages/team/
  sql/
```

## Troubleshooting

| Problem | Fix |
|--------|-----|
| 403 Forbidden | Re-upload `.htaccess`; folders 755, files 644 |
| `/upgrade.php` is 403 | Expected: `.htaccess` denies it. Temporarily remove `upgrade` from FilesMatch, run it, restore the deny, delete the file. New columns also appear when you open app pages (`ensure_*`). |
| `tests_run.php` / `reset_admin_once.php` 404 | Expected. Those scripts are CLI-only. Do not recover the admin password via a browser URL. |
| No design | Upload `assets/` + `asset.php` |
| Cannot connect to MySQL | Use `127.0.0.1` or the hPanel host, not `localhost` |
| Forgot password does nothing | Set `mail_from` / SMTP; Admin email must be verified |
| Still see old Catalog labels | Upload a full `public_html/` replace; do not delete Emails/Orders includes |
