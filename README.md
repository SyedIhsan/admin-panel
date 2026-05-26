# Admin Panel — Demo

> **This is a sanitized demo** of a production PHP admin panel I built for an online education and digital products business. All credentials, customer data, payment integrations, and external API connections have been replaced with mocks. No real charges, emails, or data flows.

**Live demo:** [https://demo-panel.infinityfreeapp.com](https://demo-panel.infinityfreeapp.com)
**Login:** `demo_admin` / `demo123` (auto-fill button on login page)
**Source:** This repo

---

## What this is

A multi-module admin panel for managing:

- **Product payments** — one-time purchases and installment plans
- **Subscriptions** — recurring billing with status management
- **Webinars** — registration, automated reminders, marketing campaigns
- **e-Learning** — courses, content, student progress, certificate generation
- **Email campaigns** — audience segmentation, scheduling, open/click tracking

## Tech stack

- **Backend:** PHP 8+ (strict types throughout), MySQLi with prepared statements
- **Frontend:** Tailwind CSS via CDN (no build step), Quill rich-text editor
- **Database:** MySQL (originally dual-DB architecture, collapsed to single DB for demo)
- **Architecture:** Module-based, shared partials, CSRF-protected, session auth with MFA in production

## What's in here vs. what's mocked

| Component | Production | Demo |
|---|---|---|
| Authentication | Session + email MFA + trusted devices | Session only (MFA bypassed) |
| Payment gateways | SenangPay + Stripe | Mock "demo gateway" page |
| Email delivery | AWS SES + Brevo | Logged to `/demo/mail-outbox/` |
| Google Drive (course videos) | Real Drive API | Sample video URL |
| Google Sheets (student data) | Real Sheets API | Hardcoded sample rows |
| Two databases | `dbpbrejnv1axdx` + `dbyxrbeaeo77ih` | Single demo DB |

## What I want you to look at

- `partials/nav.php` — module-aware sidebar with auto-expanding sections
- `payment/dashboard.php` — KPI dashboard with date-range filters
- `payment/product-form.php` — complex form with pricing variants
- `email/campaign-content.php` — Quill-powered campaign editor
- `email/campaign-details.php` — open/click analytics
- `api/db_router.php` — multi-database routing pattern

## Local setup

1. `git clone <this repo>`
2. Drop into your local PHP environment (XAMPP `htdocs/`, Laragon `www/`, etc.)
3. Create a MySQL database, import `demo/schema.sql` then `demo/seed.sql` via phpMyAdmin
4. `cp demo/db-config.example.php demo/db-config.php` and fill in your local creds
5. Browse to `http://localhost/admin/`

## Notable engineering decisions (production version)

- **Strict types everywhere** — every file starts with `declare(strict_types=1);`
- **CSRF on every POST** — `csrf_token()` + `csrf_validate()` pattern, never skipped
- **Output escaping via `h()`** — single canonical escape function, no raw `echo` of user data
- **Environment-based data filter** — `ENV_PAY_WHERE` keeps test data out of production reports
- **Prepared statements only** — zero string interpolation into SQL
- **Split-token device trust** — selector + hashed-validator pattern (production only)

## Screenshots

[ Add screenshots here once you've taken them ]

---

*Built by [Syed Ihsan]*
