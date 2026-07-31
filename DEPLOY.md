# Deploy Hudfam (Render — recommended)

Shared hosting cannot run this app. Use **Render** (steps below) or Railway.

## Option A — Render (easiest)

1. Open https://render.com and sign up / log in (**Login with GitHub**).
2. Click **New +** → **Blueprint**.
3. Connect the GitHub repo: `arslan1930/hudfam`.
4. Select branch: `cursor/hudfam-linkbuilding-app-f3f8` (or `main` after you merge the PR).
5. Render reads `render.yaml` and creates:
   - Postgres database `hudfam-db`
   - Web service `hudfam`
6. Click **Apply** / **Create**.
7. Wait for the first deploy (2–5 minutes).
8. Open the service URL, e.g. `https://hudfam.onrender.com`.

### Demo logins
- Admin: `admin` / `admin123`
- Team: `teammate` / `team123`

Change these passwords after first login.

### If Blueprint is unavailable
1. **New +** → **PostgreSQL** → create free DB → copy **Internal Database URL**.
2. **New +** → **Web Service** → pick `arslan1930/hudfam`.
3. Settings:
   - Language: Python
   - Branch: `cursor/hudfam-linkbuilding-app-f3f8`
   - Build command: `./build.sh`
   - Start command: `gunicorn config.wsgi:application --bind 0.0.0.0:$PORT --workers 2`
4. Environment variables:
   - `SECRET_KEY` = long random string
   - `DEBUG` = `False`
   - `SEED_DEMO` = `1`
   - `DATABASE_URL` = (from Postgres)
   - `DATABASE_SSL_REQUIRE` = `True`
   - `PYTHON_VERSION` = `3.12.3`
5. Create Web Service → wait → open the URL.

Free web services on Render **sleep after idle**; first load can take ~30–60s.

---

## Option B — Railway

1. Open https://railway.app → **Login with GitHub**.
2. **New Project** → **Deploy from GitHub repo** → `arslan1930/hudfam`.
3. Pick branch `cursor/hudfam-linkbuilding-app-f3f8`.
4. **Add Plugin / Database** → **PostgreSQL**.
5. In the web service **Variables**:
   - `SECRET_KEY` = random string
   - `DEBUG` = `False`
   - `SEED_DEMO` = `1` (optional; release already seeds)
   - `DATABASE_URL` = `${{Postgres.DATABASE_URL}}` (Railway reference)
6. Generate a domain: **Settings → Networking → Generate Domain**.
7. Redeploy if needed, then open the Railway URL.

---

## After deploy
1. Login as admin.
2. Create real project folders (e.g. `rexbo.de`).
3. Invite/create team users.
4. Import your Excel sites from **Admin → Import Excel**.
5. Set `SEED_DEMO=0` on later deploys if you no longer want demo data resets (seed only creates missing demo users/projects; it won’t wipe your sites).
