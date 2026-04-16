# Deployment Guide — cPanel / HostPapa

This guide covers a complete from-scratch deployment of StratFlow on a cPanel-managed server (HostPapa or equivalent). Follow every step in order.

---

## Prerequisites

Before starting, confirm your hosting account has:

- **cPanel** access (HostPapa Business or higher)
- **PHP 8.4** available via MultiPHP Manager (8.2 minimum)
- **MySQL 8.x** (available in all HostPapa cPanel plans)
- **SSH access** — strongly recommended; available on HostPapa Business+. Enable it in cPanel → **SSH Access**
- **Composer** on the server, OR ability to upload a pre-built `vendor/` directory from your local machine
- A domain or subdomain pointed at your hosting account

---

## Step 1 — Create a MySQL Database

1. Log into cPanel → **Databases** → **MySQL Databases**
2. Under **Create New Database**, enter a name (e.g. `stratflow`) → click **Create Database**
   - Full name will be `<cpanel_user>_stratflow` (cPanel prefixes your username automatically)
3. Under **MySQL Users** → **Add New User**, create a user with a strong password
4. Under **Add User to Database**, select the new user and the new database → click **Add** → grant **All Privileges** → **Make Changes**
5. Note these values — you will need them in Step 5:
   - Database name: `<cpanel_user>_stratflow`
   - Username: `<cpanel_user>_<chosen_username>`
   - Password: `<the password you set>`
   - Host: `localhost` (always `localhost` for cPanel MySQL)

---

## Step 2 — Create the Domain / Subdomain

1. cPanel → **Domains** (or **Subdomains** for a subdomain)
2. Create your domain or subdomain pointing to your hosting account
3. When asked for the **Document Root**, set it to:
   ```
   public_html/stratflow/public
   ```
   Replace `stratflow` with whatever upload directory you choose. The critical thing is that the web server document root points **inside the `public/` subfolder** of the application — not at the project root.

   > **Why?** The application root contains `src/`, `database/`, `.env`, and `vendor/` — none of which should be web-accessible. Only `public/` is the web root.

4. Note the full server path (e.g. `/home/<cpanel_user>/public_html/stratflow/`). You will upload files here.

---

## Step 3 — Set PHP Version to 8.4

1. cPanel → **MultiPHP Manager**
2. Find your domain/subdomain in the list
3. Set the PHP version to **PHP 8.4** (or 8.2+ minimum)
4. Click **Apply**

---

## Step 4 — Upload Application Files

### Option A — SSH + rsync (recommended)

From your local machine, in the project root:

```bash
rsync -avz \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='docker/' \
  --exclude='docker-compose.yml' \
  --exclude='docker-compose.override.yml' \
  --exclude='tests/' \
  --exclude='vendor/' \
  --exclude='.claude/' \
  --exclude='*.lock' \
  ./ <cpanel_user>@<your-server-hostname>:/home/<cpanel_user>/public_html/stratflow/
```

### Option B — FTP / cPanel File Manager

1. Zip the entire project locally, excluding `.env`, `docker/`, `tests/`, `.git/`, `vendor/`:
   ```bash
   zip -r stratflow.zip . \
     --exclude="*.git*" \
     --exclude="docker/*" \
     --exclude="docker-compose*" \
     --exclude="tests/*" \
     --exclude="vendor/*" \
     --exclude=".env"
   ```
2. Upload `stratflow.zip` via cPanel **File Manager** or FTP to `/home/<cpanel_user>/public_html/stratflow/`
3. Extract using cPanel File Manager's **Extract** option

---

## Step 5 — Create the .htaccess File

StratFlow requires URL rewriting so all requests are routed through `public/index.php`. Create a file at `public_html/stratflow/public/.htaccess` with this content:

```apache
Options -Indexes
DirectoryIndex index.php

RewriteEngine On

# Allow direct file/directory access (assets, uploads)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Route everything else through the front controller
RewriteRule ^ index.php [L]

# Block access to .env, .git, and other sensitive files
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
```

Create this file via SSH:
```bash
cat > /home/<cpanel_user>/public_html/stratflow/public/.htaccess << 'EOF'
Options -Indexes
DirectoryIndex index.php

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]

<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
EOF
```

Also create a root-level `.htaccess` at `public_html/stratflow/.htaccess` to block direct access to application files if the document root misconfiguration ever occurs:

```bash
cat > /home/<cpanel_user>/public_html/stratflow/.htaccess << 'EOF'
# If somehow served from the project root, redirect to public/
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]

<FilesMatch "\.(env|sql|json|lock|md)$">
    Order allow,deny
    Deny from all
</FilesMatch>
EOF
```

---

## Step 6 — Install Composer Dependencies

### Option A — Run Composer on the server (requires Composer installed)

```bash
ssh <cpanel_user>@<your-server-hostname>
cd /home/<cpanel_user>/public_html/stratflow/
composer install --no-dev --optimize-autoloader
```

Check Composer is available:
```bash
which composer
# or
composer --version
```

If not installed on the server, use Option B.

### Option B — Build vendor/ locally and upload

On your local machine (in the project root):

```bash
composer install --no-dev --optimize-autoloader
rsync -avz vendor/ \
  <cpanel_user>@<your-server-hostname>:/home/<cpanel_user>/public_html/stratflow/vendor/
```

---

## Step 7 — Create the Production .env File

SSH into the server and create the `.env` file:

```bash
ssh <cpanel_user>@<your-server-hostname>
nano /home/<cpanel_user>/public_html/stratflow/.env
```

Paste and fill in all values:

```env
# ── Application ────────────────────────────────────────────────────────────────
APP_ENV=production
APP_URL=https://yourdomain.com
APP_DEBUG=false
ALLOW_EXTERNAL_AI_PROCESSING=true

# ── Database ───────────────────────────────────────────────────────────────────
# On cPanel, DB_HOST is always 'localhost' (socket connection)
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<cpanel_user>_stratflow
DB_USERNAME=<cpanel_user>_<db_username>
DB_PASSWORD=<your_db_password>

# ── AI ─────────────────────────────────────────────────────────────────────────
# Required — get a key at https://aistudio.google.com/apikey
GEMINI_API_KEY=<your_production_gemini_key>
GEMINI_MODEL=gemini-3-flash-preview

# ── Stripe (use live keys in production) ───────────────────────────────────────
STRIPE_PUBLISHABLE_KEY=pk_live_...
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_PRODUCT=price_...
STRIPE_PRICE_CONSULTANCY=price_...
STRIPE_PRICE_USER_PACK=price_...
STRIPE_PRICE_EVAL_BOARD=price_...

# ── Security ───────────────────────────────────────────────────────────────────
# Must be exactly 32 characters. Generate with: openssl rand -hex 16
TOKEN_ENCRYPTION_KEY=<32_char_random_string>

# ── Email ──────────────────────────────────────────────────────────────────────
# Option 1: Resend (recommended)
RESEND_API_KEY=re_...
MAIL_FROM_NAME=StratFlow
MAIL_FROM_EMAIL=noreply@yourdomain.com
MAIL_SMTP_HOST=smtp.resend.com
MAIL_SMTP_PORT=587
MAIL_SMTP_ENCRYPTION=auto
MAIL_SMTP_USER=resend
MAIL_SMTP_PASS=<same_as_resend_api_key>

# Option 2: cPanel SMTP (use your HostPapa email credentials)
# MAIL_SMTP_HOST=mail.yourdomain.com
# MAIL_SMTP_PORT=587
# MAIL_SMTP_ENCRYPTION=tls
# MAIL_SMTP_USER=noreply@yourdomain.com
# MAIL_SMTP_PASS=<your_email_password>

# ── Optional Integrations ──────────────────────────────────────────────────────
# Leave blank to disable — these features will be hidden from the UI
GITHUB_APP_ID=
GITHUB_APP_SLUG=
GITHUB_APP_WEBHOOK_SECRET=
# Paste PEM content with literal \n between lines:
GITHUB_APP_PRIVATE_KEY=

JIRA_CLIENT_ID=
JIRA_CLIENT_SECRET=

XERO_CLIENT_ID=
XERO_CLIENT_SECRET=

# ── File Uploads ───────────────────────────────────────────────────────────────
UPLOAD_MAX_SIZE=268435456

# ── GrowthBook feature flags (optional) ───────────────────────────────────────
GROWTHBOOK_API_HOST=
GROWTHBOOK_CLIENT_KEY=
```

Set restrictive permissions:

```bash
chmod 600 /home/<cpanel_user>/public_html/stratflow/.env
```

---

## Step 8 — Initialise the Database

Run the init script. It applies the full schema and all 40 migrations in sequence — it is safe to run multiple times (idempotent):

```bash
cd /home/<cpanel_user>/public_html/stratflow/
php scripts/init-db.php
```

Expected output:
```
Connected to database: <cpanel_user>_stratflow@localhost
Schema applied successfully.
Running migration: 001_v1_completion.sql
Running migration: 002_admin_features.sql
Running migration: 003_sounding_boards.sql
Running migration: 004_drift_engine.sql
Running migration: 005_polish.sql
Running migration: 006_email_tokens.sql
Running migration: 007_security_hardening.sql
Running migration: 008_performance_indexes.sql
Running migration: 009_jira_integration.sql
Running migration: 010_project_jira_key.sql
Running migration: 011_sync_mappings_risk_type.sql
Running migration: 012_team_board_sprint_team.sql
Running migration: 013_status_and_jira_fields.sql
Running migration: 014_expanded_roles.sql
Running migration: 015_billing_access_flag.sql
Running migration: 016_db_sessions.sql
Running migration: 017_jira_status_pull.sql
Running migration: 018_git_links.sql
Running migration: 019_xero_integration.sql
Running migration: 020_executive_dashboard_flag.sql
Running migration: 021_github_integration.sql
Running migration: 022_key_results.sql
Running migration: 023_story_quality.sql
Running migration: 024_quality_scores.sql
Running migration: 025_app_defaults.sql
Running migration: 025b_quality_state.sql
Running migration: 026_invoice_billing_fields.sql
Running migration: 027_project_admin_flag.sql
Running migration: 028_project_permissions.sql
Running migration: 029_personal_access_tokens.sql
Running migration: 030_user_story_assignee.sql
Running migration: 031_developer_role_jira_account.sql
Running migration: 032_user_team.sql
Running migration: 033_work_item_team.sql
Running migration: 034_closed_status_and_risk_roam.sql
Running migration: 035_risk_owner.sql
Running migration: 036_capability_permissions.sql
Running migration: 037_jira_display_name.sql
Running migration: 038_audit_log_hash_chain.sql
Running migration: 039_mfa_totp.sql
Running migration: 040_org_soft_delete.sql
Database initialisation complete.
```

`Skipped (already applied)` lines are normal — they indicate idempotent statements that found the column or index already exists.

> **Alternative via phpMyAdmin:** If you do not have SSH, go to cPanel → **phpMyAdmin**, select your database, click the **SQL** tab, and paste the contents of `database/schema.sql`. Then repeat for each migration file in `database/migrations/` in order (001 through 040).

---

## Step 9 — Create the Admin User

```bash
cd /home/<cpanel_user>/public_html/stratflow/
php scripts/create-admin.php \
  --email=admin@yourdomain.com \
  --password=YourSecurePassword1! \
  --name="Admin User" \
  --org="Your Organisation Name"
```

Or run interactively (will prompt for each field):
```bash
php scripts/create-admin.php
```

---

## Step 10 — Configure PHP Settings

Create or edit `.user.ini` in the **project root** (not the `public/` folder, since PHP-FPM reads it from the script directory):

```bash
cat > /home/<cpanel_user>/public_html/stratflow/.user.ini << 'EOF'
upload_max_filesize = 256M
post_max_size = 256M
memory_limit = 256M
max_execution_time = 120
max_input_time = 120
EOF
```

Also create one in `public/` so it applies to web requests:

```bash
cp /home/<cpanel_user>/public_html/stratflow/.user.ini \
   /home/<cpanel_user>/public_html/stratflow/public/.user.ini
```

Alternatively, configure these in cPanel → **MultiPHP INI Editor** → select your PHP version → set the values there.

---

## Step 11 — Enable HTTPS (SSL)

1. cPanel → **SSL/TLS** → **Let's Encrypt SSL** (or **SSL/TLS Status**)
2. Issue a certificate for your domain
3. Once issued, enable **Force HTTPS Redirect** (cPanel → **Domains** → toggle the HTTPS redirect)

Verify the `APP_URL` in `.env` uses `https://`:
```env
APP_URL=https://yourdomain.com
```

---

## Step 12 — Configure Stripe Webhooks

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/) → **Developers** → **Webhooks**
2. Click **Add endpoint**
3. Endpoint URL: `https://yourdomain.com/webhook/stripe`
4. Select these events:
   - `checkout.session.completed`
   - `customer.subscription.deleted`
   - `customer.subscription.updated`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
5. Click **Add endpoint**
6. Copy the **Signing secret** (`whsec_...`) that appears
7. Update `STRIPE_WEBHOOK_SECRET` in `.env`

---

## Step 13 — Set Up the Quality Scoring Worker (Cron Job)

The quality scoring worker processes pending story quality scores in the background. On cPanel, set it up as a cron job:

1. cPanel → **Cron Jobs**
2. Set the schedule to **Every 5 minutes**: `*/5 * * * *`
3. Command:
   ```bash
   /usr/local/bin/php /home/<cpanel_user>/public_html/stratflow/bin/score_quality.php --limit=10 >> /home/<cpanel_user>/logs/stratflow-quality.log 2>&1
   ```
4. Click **Add New Cron Job**

Find the correct PHP binary path:
```bash
which php
# Typical HostPapa paths:
# /usr/local/bin/php
# /opt/cpanel/ea-php84/root/usr/bin/php
```

Use the PHP 8.4 binary path specifically if you have multiple PHP versions:
```bash
ls /opt/cpanel/ea-php84/root/usr/bin/php
# If it exists, use this full path in the cron command
```

---

## Step 14 — Set File Upload Permissions

The `public/uploads/` directory must be writable by the web server:

```bash
chmod 755 /home/<cpanel_user>/public_html/stratflow/public/uploads
```

If the directory does not exist, create it:
```bash
mkdir -p /home/<cpanel_user>/public_html/stratflow/public/uploads
chmod 755 /home/<cpanel_user>/public_html/stratflow/public/uploads
```

---

## Step 15 — Verify the Deployment

Open your domain in a browser and work through this checklist:

- [ ] Root URL loads the pricing/marketing page without errors
- [ ] Login works with the admin credentials from Step 9
- [ ] File upload works (PDF, DOCX, TXT) — check the extracted text appears
- [ ] AI summary generates from an uploaded document
- [ ] Mermaid diagram generates and renders in the browser
- [ ] Work items generate from the diagram
- [ ] Stripe checkout flow completes (use live cards in production)
- [ ] Stripe webhook receives events — check Stripe Dashboard → Webhooks → Recent deliveries
- [ ] `/.env` returns 403 or 404 (confirm sensitive file is blocked)
- [ ] HTTPS is active and HTTP redirects to HTTPS

---

## Updating the Application

### Pull and redeploy

```bash
# Upload new application files (excluding .env and vendor/)
rsync -avz \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='docker/' \
  --exclude='docker-compose.yml' \
  --exclude='tests/' \
  ./ <cpanel_user>@<your-server-hostname>:/home/<cpanel_user>/public_html/stratflow/

# SSH in and update dependencies if composer.json changed
ssh <cpanel_user>@<your-server-hostname>
cd /home/<cpanel_user>/public_html/stratflow/
composer install --no-dev --optimize-autoloader

# Apply any new migrations
php scripts/init-db.php
```

### Applying a single migration manually

If you need to apply one specific migration:

```bash
mysql -u <db_username> -p <cpanel_user>_stratflow \
  < /home/<cpanel_user>/public_html/stratflow/database/migrations/041_your_change.sql
```

---

## Troubleshooting

### Blank page or 500 error

1. Enable debug mode temporarily: set `APP_DEBUG=true` in `.env`
2. Check PHP error logs: cPanel → **Logs** → **Error Log**, or:
   ```bash
   tail -f ~/logs/error_log
   ```
3. Check file permissions — `public/` and `public/uploads/` must be readable by the web server (chmod 755)
4. Confirm `vendor/autoload.php` exists — if missing, run `composer install`

### 404 on all pages except the homepage

URL rewriting is not working. Confirm:
1. `public/.htaccess` exists with the `RewriteEngine On` block from Step 5
2. Apache `mod_rewrite` is enabled — check with cPanel support if unsure (it is enabled by default on HostPapa)
3. The document root in cPanel points to `public/`, not the project root

### Database connection failed

1. Confirm `DB_HOST=localhost` (not `mysql` — that is the Docker hostname)
2. Verify database name, username, and password against what you set in cPanel MySQL
3. Confirm the user has `ALL PRIVILEGES` on the database
4. Test the connection directly:
   ```bash
   mysql -u <db_username> -p -h localhost <cpanel_user>_stratflow
   ```

### File uploads failing

1. Check `UPLOAD_MAX_SIZE` in `.env` (default `268435456` = 256 MB)
2. Confirm `upload_max_filesize` and `post_max_size` in `.user.ini` match or exceed `UPLOAD_MAX_SIZE`
3. Confirm `public/uploads/` is writable: `chmod 755 public/uploads/`

### Stripe webhooks not receiving

1. Confirm the endpoint URL in Stripe Dashboard exactly matches `https://yourdomain.com/webhook/stripe`
2. Confirm `STRIPE_WEBHOOK_SECRET` in `.env` matches the signing secret shown in Stripe Dashboard
3. Check Stripe Dashboard → Webhooks → click the endpoint → **Recent deliveries** for error detail

---

## Directory Reference

| Location on server | Contents |
|--------------------|----------|
| `/home/<user>/public_html/stratflow/` | Project root (not web-accessible) |
| `/home/<user>/public_html/stratflow/public/` | Web root — only this directory is served |
| `/home/<user>/public_html/stratflow/.env` | Credentials and config (chmod 600) |
| `/home/<user>/public_html/stratflow/vendor/` | Composer dependencies |
| `/home/<user>/public_html/stratflow/database/` | Schema and migrations |
| `/home/<user>/public_html/stratflow/public/uploads/` | User-uploaded files (writable) |
| `/home/<user>/logs/stratflow-quality.log` | Quality worker cron output |
