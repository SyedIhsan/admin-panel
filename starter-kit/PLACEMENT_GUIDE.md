# Starter Kit — Placement Guide

This kit contains scaffolding files. Drop each into the matching location in your `Admin Dashboard` project.

## File placement map

| Starter-kit file | Drop it into your project at |
|---|---|
| `stubs/google_api_stub.php` | `elearning/lib/google_api_stub.php` (then update requires in google.php, contents.php, etc.) |
| `stubs/payment_gateway_stub.php` | `api/payment_gateway_stub.php` |
| `stubs/mail_stub.php` | `api/mail_stub.php` (replaces sendmail.php logic) |
| `partials/demo-banner.php` | `partials/demo-banner.php` (then include from header.php) |
| `demo/db-config.example.php` | `demo/db-config.example.php` |
| `demo/.htaccess` | `.htaccess` at web root (merge with any existing rules) |
| `demo/schema.skeleton.sql` | `demo/schema.skeleton.sql` (Claude Code refines into schema.sql) |
| `demo/reset.php` | `demo/reset.php` |
| `bootstrap-patch.example.php` | Reference only — apply diffs to bootstrap.php manually or via Claude Code |
| `.gitignore` | `.gitignore` at project root |
| `README.template.md` | `README.md` at project root (fill in TODOs) |

## How to feed these to Claude Code

After pasting the main Phase prompt, add this follow-up message:

> I've placed a starter-kit folder at `./starter-kit/`. Use it as your source of truth:
> - In Phase 2, copy `starter-kit/stubs/*` into the project and update all requires that imported the original integrations.
> - In Phase 2, apply the patches in `starter-kit/bootstrap-patch.example.php` to the real `bootstrap.php`.
> - In Phase 3, use `starter-kit/demo/schema.skeleton.sql` as the starting point for `demo/schema.sql` — verify every table against actual queries in the codebase before finalising.
> - In Phase 3, copy `starter-kit/demo/db-config.example.php` and `starter-kit/demo/reset.php` into `demo/`.
> - In Phase 4, copy `starter-kit/partials/demo-banner.php` and wire it into `partials/header.php`.
> - In Phase 5, use `starter-kit/README.template.md` as the basis for the README, filling in real screenshots and links.
> - In Phase 6, use `starter-kit/demo/.htaccess` as the web-root htaccess (merge with any existing rules).
> - Copy `starter-kit/.gitignore` to project root before the first commit.
> Once you've consumed everything from `starter-kit/`, delete the folder.
