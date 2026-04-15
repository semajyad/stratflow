# cPanel Deployment Guide

This guide covers deploying StratFlow to a cPanel-managed server, targeting `stratflow.threepointssolutions.com`.

## Prerequisites

- cPanel hosting account with:
  - PHP 8.4 (or 8.2+)
  - MySQL 8.x
  - SSH access (recommended)
  - Let's Encrypt SSL available
- FTP client or rsync access
- Composer available on the server (or run locally and upload `vendor/`)

---

## Step 1 — Create a Subdomain in cPanel

1. Log into cPanel → **Domains** → **Subdomains**
2. Create subdomain: `stratflow` under `threepointssolutions.com`
3. Note the document root (e.g., `/home/<user>/stratflow.threepointssolutions.com/`)
4. The webserver must serve from the `public/` subdirectory — configure this in **Apache** or set a symlink (see Step 9)

---

## Step 2 — Create a MySQL Database

1. cPanel → **Databases** → **MySQL Databases**
2. Create a new database (e.g., `<cpanel_user>_stratflow`)
3. Create a database user with a strong password
4. Grant the user **All Privileges** on that database
5. Note the database name, username, and password for Step 6

---

## Step 3 — Upload Application Files

Exclude files that should not go to production:

```bash
rsync -avz --exclude='.git' \
           --exclude='docker' \
           --exclude='docker-compose.yml' \
           --exclude='docker-compose.override.yml' \
           --exclude='.env' \
           --exclude='tests/' \
           --exclude='vendor/' \
           ./ user@server:/home/<user>/stratflow.threepointssolutions.com/
```

Alternatively, zip and upload via cPanel **File Manager**, then extract.

---

## Step 4 — Install Composer Dependencies

**Option A — Run on the server (requires Composer installed):**

```bash
ssh user@server
cd /home/<user>/stratflow.threepointssolutions.com/
composer install --no-dev --optimize-autoloader
```

**Option B — Upload vendor/ from local (simpler, no Composer on server needed):**

```bash
# Locally:
composer install --no-dev --optimize-autoloader
rsync -avz vendor/ user@server:/home/<user>/stratflow.threepointssolutions.com/vendor/
```

---

## Step 5 — Create the Production .env

SSH into the server and create `.env` in the project root:

```bash
APP_ENV=production
APP_URL=https://stratflow.threepointssolutions.com

DB_HOST=localhost
DB_PORT=3306
DB_NAME=<cpanel_user>_stratflow
DB_USER=<db_user>
DB_PASS=<db_password>

GEMINI_API_KEY=<your-production-gemini-key>

STRIPE_PUBLIC_KEY=pk_live_...
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

UPLOAD_MAX_SIZE=268435456
```

Set restrictive permissions on `.env`:

```bash
chmod 600 .env
```

---

## Step 6 — Import the Database Schema

In cPanel → **phpMyAdmin**, select the production database and run the contents of `database/schema.sql`.

Alternatively via SSH:

```bash
mysql -u <db_user> -p <db_name> < database/schema.sql
```

---

## Step 7 — Create the Admin User

```bash
ssh user@server
cd /home/<user>/stratflow.threepointssolutions.com/
php scripts/create-admin.php
```

---

## Step 8 — Configure Stripe Webhooks for Production

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/) → Developers → Webhooks
2. Click **Add endpoint**
3. Endpoint URL: `https://stratflow.threepointssolutions.com/webhook/stripe`
4. Select events: `checkout.session.completed`, `customer.subscription.deleted`, `customer.subscription.updated`
5. Copy the **Signing secret** and update `STRIPE_WEBHOOK_SECRET` in `.env`

---

## Step 9 — Set the Document Root to public/

The web server must serve only the `public/` directory (not the project root). In cPanel:

**Apache / .htaccess approach:**

If cPanel points to the project root, add a `.htaccess` at the project root to redirect to `public/`:

```apache
RewriteEngine On
RewriteRule ^(.*)$ public/$1 [L]
```

**Preferred approach — change document root:**

In cPanel → Domains → set the document root directly to `public/` when creating the subdomain.

---

## Step 10 — Configure PHP Settings

In cPanel → **MultiPHP INI Editor** (or create/edit `.user.ini` in the project root):

```ini
upload_max_filesize = 256M
post_max_size = 256M
memory_limit = 256M
max_execution_time = 120
```

---

## Step 11 — Enable HTTPS via Let's Encrypt

1. cPanel → **SSL/TLS** → **Let's Encrypt SSL**
2. Issue a certificate for `stratflow.threepointssolutions.com`
3. Enable **Force HTTPS** redirect

---

## Post-Deployment Checklist

- [ ] `https://stratflow.threepointssolutions.com` loads the pricing page
- [ ] Login works with the admin account
- [ ] File upload processes correctly (PDF text extraction)
- [ ] Gemini AI summary generates
- [ ] Diagram generates and displays
- [ ] Work items generate
- [ ] Stripe checkout flow completes (use a real card in live mode)
- [ ] Stripe webhook receives and processes events (check Stripe Dashboard → Webhooks → logs)
- [ ] `.env` is not publicly accessible (return 403/404 for `/.env` in browser)

---

## Updating the Application

```bash
# Pull latest code
rsync -avz --exclude='.env' --exclude='vendor/' --exclude='docker*' \
  ./ user@server:/home/<user>/stratflow.threepointssolutions.com/

# Re-run composer if dependencies changed
ssh user@server "cd /home/<user>/stratflow.threepointssolutions.com && composer install --no-dev --optimize-autoloader"

# Apply any schema changes manually via phpMyAdmin or mysql CLI
```

---

## Database migration strategy — expand/contract

Auto-rollback (Railway rolling back code to `LAST_GOOD_SHA`) only undoes the application binary.
It does **not** reverse database schema changes. A destructive migration + auto-rollback = production outage.

### Rule

Every schema migration MUST be backward compatible with the **previous deploy** of the code.
Use the expand/contract pattern:

| Phase | What happens | Safe to rollback? |
|-------|-------------|-------------------|
| **Expand** | Add new column/table (nullable or with default). Old code ignores it. | ✅ Yes |
| **Migrate** | Backfill data, deploy new code that uses the new column. | ✅ Yes |
| **Contract** | Drop the old column/table in a *separate* later migration, after verifying the previous deploy is no longer `LAST_GOOD_SHA`. | ✅ Yes — old code is gone |

### Forbidden in a single deploy

- `DROP COLUMN` (unless the column is not read by the running code)
- `RENAME COLUMN` / `RENAME TABLE` (breaks the previous code immediately)
- `TRUNCATE TABLE` on non-temporary data
- `ALTER COLUMN` narrowing the type (e.g. `VARCHAR(500)` → `VARCHAR(10)`)

### CI enforcement

`scripts/ci/check_destructive_migrations.py` runs on every PR that touches `database/migrations/`.
If it detects any of the above:

1. It blocks merge until the PR has the `safe-migration-reviewed` label.
2. Add that label only after confirming the LAST_GOOD_SHA code is compatible with the new schema.

### Bypassing auto-rollback for destructive migrations

If you intentionally need a destructive migration (e.g. the expand phase already shipped):

1. Add `safe-migration-reviewed` label to the PR (CI will pass).
2. Immediately after merge, update `LAST_GOOD_SHA` to the new deploy SHA so rollback never targets the pre-migration code:

   ```bash
   gh variable set LAST_GOOD_SHA --body "<new deploy SHA>" --repo semajyad/stratflow
   ```

3. Monitor `/healthz` for 10 minutes post-deploy before considering the deploy stable.
