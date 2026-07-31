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
1. Admin creates project folders (`rexbo.de`, `xyw.com`, …) and assigns team
2. Team adds negotiated sites (status **Agreed**)
3. Admin sends packs and updates Rejected / Processing / Completed
4. Team reads results and refills sites

## Troubleshooting
| Problem | Fix |
|--------|-----|
| 404 on domain | Files not in correct `public_html` / wrong domain document root |
| Database error on install | Check DB name/user/password; host is usually `localhost` |
| Blank page | Enable PHP error display temporarily, or check hPanel error logs |
| Permission denied writing config | Create `config.php` manually from `config.sample.php` |
