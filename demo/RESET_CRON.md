# Nightly Demo Reset — cron-job.org Setup

**Status: confirmed working as of 2026-05-26**
Schedule: daily 04:00 UTC — cron-job.org free tier, 30 s timeout (reset runs in ~3 s)

---

The demo resets nightly at 4 AM MYT (Asia/Kuala_Lumpur) by calling
`demo/reset.php` with a secret token. This document covers how to configure
the cron job and verify it works.

---

## Prerequisites

1. **The demo is deployed** to your InfinityFree (or other) host.
2. **`demo/db-config.php` exists on the server** with the correct DB credentials
   and a `reset_token` value. This file is gitignored — upload it manually
   (e.g. via cPanel File Manager).
3. **The reset endpoint responds correctly** — test with curl first (see below).

---

## 1. Verify the endpoint locally

```bash
# Should return 403 (no output)
curl -i http://localhost/demo/reset.php

# Should return 403 (no output)
curl -i "http://localhost/demo/reset.php?token=wrong"

# Should return 200: Reset complete: <timestamp>
TOKEN="5ccf028887827061f4f787e87a0309d6"   # from your db-config.php reset_token
curl -i "http://localhost/demo/reset.php?token=$TOKEN"
```

---

## 2. Verify on the live host before setting up cron

Replace `YOUR_DOMAIN` and `YOUR_TOKEN` with your actual values:

```
https://YOUR_DOMAIN/demo/reset.php             → should return HTTP 403
https://YOUR_DOMAIN/demo/reset.php?token=wrong → should return HTTP 403
https://YOUR_DOMAIN/demo/reset.php?token=YOUR_TOKEN → should return:
    Reset complete: 2026-05-25T04:00:01+08:00
```

---

## 3. Set up cron-job.org

cron-job.org is free and requires no credit card.

1. Go to **https://cron-job.org** and create a free account.
2. Click **Create Cronjob**.
3. Fill in:

   | Field | Value |
   |---|---|
   | **Title** | Demo Reset |
   | **URL** | `https://YOUR_DOMAIN/demo/reset.php?token=YOUR_TOKEN` |
   | **Schedule** | Custom |
   | **Days of week** | Every day |
   | **Hours** | `20` (UTC) = 4 AM MYT (+08:00) |
   | **Minutes** | `0` |
   | **Request method** | GET |
   | **Timeout** | 30 seconds (free tier max — sufficient, reset runs in ~3 s) |

4. Under **Notifications** → enable email on failure (so you know if the reset breaks).
5. Click **Create**.

---

## 4. Test from cron-job.org

After creating the job:
1. Open the job's detail page.
2. Click **"Run now"** (or "Test execution").
3. Wait a few seconds and click the execution entry to see:
   - HTTP status: should be **200**
   - Response body: `Reset complete: <timestamp>`
4. Log into the demo admin and verify data is fresh.

---

## 5. What the reset does

Each run:
1. Drops all tables (`DROP TABLE IF EXISTS` on every table).
2. Re-imports `demo/schema.sql` (creates tables).
3. Re-imports `demo/seed.sql` (fills sample data).
4. Deletes all files in `demo/mail-outbox/` (clears fake sent emails).
5. Appends one line to `demo/reset.log` (server-side only, gitignored).

Total run time: typically 2–5 seconds on InfinityFree shared hosting.

---

## 6. Changing the token

1. Generate a new token:
   ```bash
   php -r "echo bin2hex(random_bytes(16));"
   ```
2. Update `reset_token` in `demo/db-config.php` on the server.
3. Update the URL in your cron-job.org job.
4. Test with curl to confirm the new token works.

---

## 7. Security notes

- The endpoint returns HTTP **403** with an empty body for any invalid or missing
  token — it does not reveal that the endpoint exists.
- Token comparison uses `hash_equals()` to prevent timing attacks.
- The endpoint refuses to run unless `DEMO_MODE === true` in bootstrap.
- `demo/db-config.php` (which contains the token) is gitignored — never committed.
