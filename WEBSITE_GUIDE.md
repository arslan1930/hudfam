# TechxForm / Hudfam — Website Working Guide

**Audience:** Admin and Team members who need to understand how the site works.  
**Product:** Linkbuilding inventory and client publication workflow (Admin + Team panels).  
**Stack:** Plain PHP + MySQL + HTML/CSS (no React, Node, or framework).  
**App root:** Everything runs from `public_html/` via `index.php?page=...`

Related files:

- [`README.md`](README.md) — product overview and local preview
- [`public_html/HOSTINGER.md`](public_html/HOSTINGER.md) — Hostinger deploy steps
- [`AGENTS.md`](AGENTS.md) — developer / Cloud agent notes

---

## 1. What this website is

TechxForm is an **internal** Admin + Team tool used to:

1. Build and maintain a **country-based site database** (“Our database”)
2. Let Team **filter new lists** against that database (without seeing the full inventory)
3. Move sites through **Extracting → Emails → Campaigns**
4. Let Admin manage **orders** and **invoices** for clients
5. Organize work by **Departments** (who can use which tools)

It is not a public marketing website.

---

## 2. How the app is structured

```text
public_html/
  index.php              ← router (all pages)
  install.php / upgrade.php
  asset.php              ← serves CSS / JS / images
  assets/css|js|img/
  includes/              ← business logic (DB, auth, prospects, emails, orders…)
  pages/admin/           ← Admin screens
  pages/team/            ← Team screens
  sql/                   ← schema + upgrade SQL
```

### Routing

- URL shape: `index.php?page=admin_dashboard`
- Routes are defined in `public_html/index.php`
- Unknown `page` → 404
- After login:
  - **Admin** → Admin Dashboard
  - **Team with department(s)** → My departments / Team tools
  - **Team with no department** → waiting screen (tools locked)

### Auth and roles

| Role | Access |
|------|--------|
| `admin` | Full Admin panel; can also browse Team UI |
| `team` | Team panel only; tools limited by department assignment |

Security gates (`index.php` / `includes/auth.php`):

- Must be logged in for app pages
- Weak/demo passwords (`admin123`, `team123`) force **Change password**
- Unassigned Team cannot open tools
- Department-scoped Team can only open pages unlocked by their department(s)

---

## 3. Departments (who sees what)

Admin assigns Team users in **Admin → Departments**.

| Department | Tools unlocked for members |
|------------|----------------------------|
| **Site Finding** | Filter & add, Site adding history, Semrush Research |
| **Site Extracting** | Extracting sites (Sites list + Results + Push) |
| **Email Extracting** | Sites with emails – Team, Admin emails search |
| **Communication Team** | Admin emails search, Campaign search, Campaign drafts |

Rules:

- No department → Team only sees “Waiting for assignment”
- Multiple departments → tools from all of them combine
- Admin is **not** department-scoped (sees everything)

---

## 4. Main product flow (end-to-end)

```text
[Our database by country]
        ↑
   Filter & add (Team)
        ↓
[Extracting sites · Sites list]
        ↓
[Extracting Results → Push]
        ↓
   ┌────┴────┐
   ↓         ↓
Extracted   Sites with emails – Team
Sites       (add up to 4 emails)
(Admin)           ↓
            Push to Admin
                  ↓
         Emails data (Admin archive + Final mirror)
                  ↓
         Email campaign sheets / Communication tools
                  ↓
         Order management → Invoices
```

### Step-by-step

1. **Admin seeds Our database**  
   Open a country folder → Add sites (paste root domains).

2. **Site Finding Team finds more sites**  
   **Filter & add** → pick country → paste list → Filter.  
   Duplicates already in that country are removed **privately** (Team does not browse the full Admin inventory).  
   Add unique sites → they go into:
   - that country’s Our database
   - Site adding history (by person / day)
   - Extracting sites → Sites list for that country

3. **Site Extracting Team processes sites**  
   Open **Extracting sites** → country → paste into **Extracting Results** → **Push**.  
   Push routes:
   - country-specific TLDs can go to matching country folders
   - generic TLDs stay in the selected country  
   Results land in:
   - **Admin → Extracted Sites**
   - **Team → Sites with emails – Team**

4. **Email Extracting Team adds emails**  
   Fill emails (up to 4 per site) → **Push to Admin**.  
   Rows leave the Team working copy and appear under **Admin → Emails data**.

5. **Communication Team outreach**  
   Uses Admin emails search / Campaign search / Campaign drafts against Admin email archives and campaign sheets.

6. **Admin closes the loop commercially**  
   **Order management** (client sheets, prices, live URLs) → **Invoices** (printable client invoices).

---

## 5. Admin panel — screens

| Menu item | What it does |
|-----------|--------------|
| **Dashboard** | Stats, launch cards, recent adds, dashboard search |
| **Departments** | Assign members and tasks to Site Finding / Extracting / Email / Communication |
| **Our database** | Country folders of unique domains; browse / add / manage |
| **Site adding history** | Who added which sites, by day (batches) |
| **Semrush Research** | Research sheets (from Extracting Push / optional seed) |
| **Extracted Sites** | Sites pushed from Team Extracting Results |
| **Emails data** | Admin email archives, Final mirror, campaign sheets |
| **Order management** | Client sheets: sites, prices, profit, live URL, paid flags |
| **Invoices** | Generate / blank / view printable invoices |
| **Users** | Create/edit Admin and Team accounts, reset passwords |
| **Change password** | Own account password change |

### Our database (core inventory)

- One list **per country**
- Unique on `(country, domain)`
- Admin can paste/add; Team normally adds only via Filter & add
- Domains are normalized to **root domains**

### Site adding history

- One batch per user per day (plus domains in that batch)
- Used for accountability and review

### Extracted Sites / Emails data

- Receive Push from Team workflows
- Admin archive feeds Communication tools
- Campaign data is organized as projects → country sheets → site + email rows

### Orders and invoices

- Clients have sheets of placement/order rows
- Completed articles can feed invoice generation
- Invoices support draft / done / payment tracking

---

## 6. Team panel — screens

What you see depends on department assignment.

| Tool | Typical department | What it does |
|------|--------------------|--------------|
| **Dashboard** | All | Open tasks + tool shortcuts (or waiting screen) |
| **My departments** | All assigned | Department folders + tasks |
| **Filter & add** | Site Finding | Paste → filter vs country DB → add unique only |
| **Site adding history** | Site Finding | Your daily adds |
| **Semrush Research** | Site Finding | Edit / comment / clear research sheets |
| **Extracting sites** | Site Extracting | Sites list + Extracting Results + Push |
| **Sites with emails – Team** | Email Extracting | Add emails, Push to Admin |
| **Admin emails search** | Email / Communication | Search Admin email archive; cleanup / delete emails |
| **Campaign search** | Communication | Search campaign sheets across countries |
| **Campaign drafts** | Communication | Formatted outreach drafts per project |

### Important Team rule

**Filter & add** is designed so teammates do **not** need to browse the full Our database. They only learn which of *their pasted* domains are new for that country.

---

## 7. Accounts, login, passwords

### Login

- Username + password for everyone
- Admin may also sign in with their **account email** (if unique)
- After login, weak/demo passwords force **Change password** before any other page

### Password change

- Sidebar → **Change password** (`account_password`)
- New password: at least 8 characters, not a known demo default, different from current

### Users (Admin)

- Create Admin / Team users
- Activate / deactivate (you cannot lock yourself out of your own admin account)
- Set / reset passwords (a temporary password may be shown once)

### Deploy note

On Hostinger / fresh install: run `install.php` once, copy one-time passwords, then **delete** the installer.  
On upgrades: Admin runs `upgrade.php` once, then delete it.

---

## 8. Data model (simple mental model)

| Concept | Meaning |
|---------|---------|
| `users` | Admin / Team logins |
| `countries` | Country catalog for folders |
| `prospect_sites` | Our database (unique domain per country) |
| `prospect_batches` / `prospect_batch_items` | Site adding history |
| `extract_batches` / `extract_batch_sites` | Extracting Sites list + results |
| Extracted / Semrush tables | Post-push research / extracted inventories |
| Sites-with-emails tables (team / admin / all) | Email working copy → Admin archive → Final mirror |
| `email_campaign_*` | Outreach projects / sheets / rows |
| `departments` / members / tasks | Org structure + assignments |
| `order_clients` / order items | Client publication sheets |
| `invoices` / invoice items | Billing documents |

**Country** is the main organizational key across inventory, extracting, and emails.

---

## 9. Day-to-day cheat sheets

### Admin first-week setup

1. Change your password  
2. Create Team users (**Users**)  
3. Assign them to departments (**Departments**)  
4. Seed a few country folders in **Our database**  
5. Create any needed Email campaign projects / sheets  
6. Optionally create client sheets in **Order management**

### Site Finding teammate — daily loop

1. Open **Filter & add**  
2. Choose country  
3. Paste domains → Filter → Add unique  
4. Check **Site adding history** if needed  
5. Use Semrush Research when that work is assigned  

### Site Extracting teammate — daily loop

1. Open **Extracting sites** for a country  
2. Work the Sites list  
3. Paste Extracting Results → **Push**  

### Email Extracting teammate — daily loop

1. Open **Sites with emails – Team**  
2. Fill emails  
3. **Push to Admin** when ready  

### Communication teammate — daily loop

1. Search Admin emails / Campaign sheets  
2. Update replied / dealing status as your process defines  
3. Use **Campaign drafts** to copy outreach text  

---

## 10. Glossary

| Term | Meaning |
|------|---------|
| **Our database** | Master unique URL inventory by country |
| **Filter & add** | Paste a list; keep only domains not already in that country |
| **Site adding history** | Daily log of who added what |
| **Extracting sites** | Working list + results per country before / while extracting contacts |
| **Push** | Send current results / emails downstream (to Admin inventories) |
| **Extracted Sites** | Admin copy of pushed extracting results |
| **Sites with emails** | Site + email slots (Team working copy vs Admin archive) |
| **Campaign sheet** | Country / project sheet used for outreach tracking |
| **Department** | Role bucket that unlocks tools + tasks for a teammate |

---

## 11. Technical notes (developers / deployers)

- Config: `public_html/config.php` (from `config.sample.php` / installer); **git-ignored**
- DB host tip on some VMs: use `127.0.0.1` (TCP), not `localhost` (socket path issues)
- Required PHP extensions: `pdo_mysql`, `mbstring`
- Assets go through `asset.php` (avoid double-loading CSS)
- Schema evolves via `ensure_*_schema()` helpers and/or `upgrade.php` + `sql/upgrade_*.sql`
- No Composer / npm build step — upload `public_html/` contents and run install / upgrade

### Local preview (optional)

```bash
cd public_html
# create config.php from config.sample.php, ensure MySQL is up, then:
php -S 127.0.0.1:8080
```

---

## 12. Quick URL map (common pages)

| Page key | Audience |
|----------|----------|
| `login` / `logout` / `account_password` | Everyone |
| `admin_dashboard` … `admin_users` | Admin |
| `team_dashboard` / `team_departments` | Team |
| `team_prospect_check` | Site Finding |
| `team_extracting` | Site Extracting |
| `team_sites_emails` | Email Extracting |
| `team_email_campaigns` / `team_email_campaigns_drafts` | Communication |

Full route list lives in `public_html/index.php`.
