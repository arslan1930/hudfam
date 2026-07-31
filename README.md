# Hudfam

Office linkbuilding inventory and client project workflow — **Admin panel** + **Team panel**, no React/Next.js.

## What it does

- **Project folders** per client campaign (`rexbo.de`, `xyw.com`, …) with their own niche, countries, budget, prices, and rules
- **Team** negotiates sites (Gmail stays outside), saves agreed prices, sees Admin results
- **Admin only** manages clients/projects, sends site packs, updates reject / processing / completed
- Rejection comments and published placements are **kept forever**; sites can be offered again later
- Excel/CSV import & export, search/filters, pagination ready for **100k+** sites

## Stack

- Django 5 + server-rendered HTML templates
- SQLite locally; PostgreSQL via Docker / `DATABASE_URL`
- openpyxl / pandas for spreadsheets

## Quick start (local)

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
python manage.py migrate
python manage.py seed_demo
python manage.py runserver
```

Open http://127.0.0.1:8000/

| User | Password | Panel |
|------|----------|--------|
| `admin` | `admin123` | Admin |
| `teammate` | `team123` | Team |
| `teammate2` | `team123` | Team |

## Docker (Postgres)

```bash
docker compose up --build
```

App: http://localhost:8000/  
Demo users are seeded when `SEED_DEMO=1`.

## Main URLs

- `/login/`
- `/admin-panel/` — projects, sites, send packs, import, users
- `/team/` — assigned project folders, add sites, results feed
- `/django-admin/` — Django admin

## Workflow

1. Admin creates a project folder and sets requirements + assigns team
2. Team opens the folder, finds/negotiates sites, marks **Agreed** with price
3. Admin builds a pack from the Agreed pool and sends it to the client
4. Admin updates each site: **Rejected** (+ reason) / **Processing** / **Completed** (+ live link)
5. Team reads results and refills better sites
6. Completed orders create **Published** records

## Deploy to cloud

Set env vars:

- `SECRET_KEY`
- `DEBUG=False`
- `ALLOWED_HOSTS=your.domain`
- `DATABASE_URL=postgres://...`

Then run migrate + gunicorn (see `Dockerfile` / `entrypoint.sh`). Works on Railway, Render, Fly.io, or any VPS with Docker.
