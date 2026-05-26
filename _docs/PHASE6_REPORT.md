# Phase 6 — InfinityFree Deployment: Final Report
Deployed: 2026-05-26

---

## Live URLs

- **Admin login:** https://demo-panel.infinityfreeapp.com/admin/login.php
- **Login:** `demo_admin` / `demo123` (auto-fill button on login page)

---

## What was done

### Steps A–C (manual, by user)
InfinityFree account created, subdomain provisioned, MySQL database created.

### Step 1 — Case sensitivity (no changes needed)
`lower_case_table_names = 0` on production. Verified that all PHP queries
use the same capitalisation as schema table names — no mismatches found.

### Step 2 — Production config files
Created `demo/db-config.prod.php` and `demo/env.prod.php` (both gitignored).
Updated `reset.php` to check `env.prod.php` first for the reset token.

### Step 3 — Deployment artifact
Built `_deploy/` via robocopy, excluding `.git`, `_docs`, `starter-kit`,
`*.md`, and all secret config files. Added `_deploy/` to `.gitignore`.

### Step 4 — Production schema + seed (manual, via phpMyAdmin)
37 tables imported. 34 Orders rows, 1 admin row (`demo_admin`) confirmed.

### Step 5 — FTP upload (manual, via FileZilla)
All files uploaded to `/htdocs/`. Config files uploaded separately:
- `db-config.prod.php` → `/htdocs/demo/db-config.php`
- `env.prod.php` → `/htdocs/demo/env.prod.php`

### Step 6 — Smoke test + fixes
Login and all nav sections working. Two issues found and fixed:

| Issue | Fix | Commit |
|---|---|---|
| `CREATE VIEW` denied on free tier | Replaced `order_products` VIEW with TABLE + seed INSERT | `ae467cc` |
| Email Logs showed "No records" on production | `IS_LOCALHOST` env filter excluded all `@demo.local`/`%test%` seed addresses; bypassed in DEMO_MODE | `42f6ce4` |

### Step 7 — Nightly reset cron
cron-job.org job confirmed working: HTTP 200, `Reset complete: <timestamp>`.
Schedule: daily 04:00 UTC. Timeout: 30 s (free tier max; reset runs in ~3 s).

---

## Production gotchas documented

See `demo/PROD_DEPLOY_NOTES.md` for the VIEW → TABLE workaround detail.

---

## Remaining placeholders in README.md

- Live demo URL (line 5): update to `https://demo-panel.infinityfreeapp.com`
- Screenshots section: add once you have them
