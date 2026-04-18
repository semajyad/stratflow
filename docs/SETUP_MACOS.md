# Local Development Setup — macOS (without Docker)

This guide sets up StratFlow directly on macOS using native services — no Docker required.
Tested on macOS Sonoma / Sequoia with an Apple Silicon (M-series) MacBook Pro, but works on Intel too.

---

## Prerequisites

Install [Homebrew](https://brew.sh) if you don't have it:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

Then install the required tools:

```bash
brew install php@8.4 mysql@8.4 nginx composer node
```

Link PHP 8.4 so it's on your PATH:

```bash
brew link php@8.4 --force
php -v   # should print PHP 8.4.x
```

---

## PHP Extensions

The app requires `pdo_mysql`, `zip`, and `gd`. Check what's already enabled:

```bash
php -m | grep -E 'pdo_mysql|zip|gd'
```

Homebrew's PHP 8.4 ships these extensions. If any are missing, edit the PHP ini:

```bash
# Find your php.ini
php --ini | grep "Loaded Configuration"
```

Uncomment or add:
```ini
extension=pdo_mysql
extension=zip
extension=gd
```

---

## MySQL Setup

Start MySQL and set it to launch on login:

```bash
brew services start mysql@8.4
```

Secure the installation and set a root password (optional for local dev):

```bash
mysql_secure_installation
```

Create the database and user:

```bash
mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS stratflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'stratflow'@'localhost' IDENTIFIED BY 'stratflow_secret';
GRANT ALL PRIVILEGES ON stratflow.* TO 'stratflow'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## Application Setup

### 1. Clone and install dependencies

```bash
git clone https://github.com/semajyad/stratflow.git
cd stratflow
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
```

Open `.env` and update the database block for native MySQL (no Docker hostnames):

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stratflow
DB_USERNAME=stratflow
DB_PASSWORD=stratflow_secret
```

Also set at minimum:

```ini
APP_URL=http://localhost:8890
GEMINI_API_KEY=your-gemini-api-key
```

For Stripe (payments), fill in `STRIPE_*` keys from your [Stripe Dashboard](https://dashboard.stripe.com/) test mode.
Leave other integrations (Jira, Xero, GitHub App) blank — the app works without them.

### 3. Initialise the database

```bash
php database/init-db.php
```

This runs `database/schema.sql` plus all migrations in `database/migrations/` in order.

### 4. Seed initial data (optional)

```bash
mysql -u stratflow -pstratflow_secret stratflow < database/seed.sql
```

### 5. Create your first admin user

```bash
php scripts/create-admin.php
```

The script will prompt for email, password, name, and organisation name.
Or pass flags directly:

```bash
php scripts/create-admin.php \
  --email=you@example.com \
  --password=YourPassword123! \
  --name="Your Name" \
  --org="Your Org"
```

---

## Web Server — Nginx

### Install and configure

Create a virtual host config. Replace `/path/to/stratflow` with the actual path to your clone:

```bash
mkdir -p /opt/homebrew/etc/nginx/servers
cat > /opt/homebrew/etc/nginx/servers/stratflow.conf << 'NGINX'
server {
    listen 8890;
    server_name localhost;
    root /path/to/stratflow/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }

    client_max_body_size 64M;
}
NGINX
```

> **Intel Mac note:** Homebrew paths are `/usr/local/etc/nginx/` instead of `/opt/homebrew/etc/nginx/`.

### Start PHP-FPM and Nginx

```bash
brew services start php@8.4   # starts PHP-FPM on port 9000
brew services start nginx
```

Visit [http://localhost:8890](http://localhost:8890) — you should see the StratFlow login page.

---

## Quality Worker (optional)

The quality scorer runs asynchronously. In Docker it runs as a separate container; natively, run it in a terminal tab or as a background process:

```bash
# Run once manually
php bin/score_quality.php --limit=25

# Run in a loop (background)
while true; do php bin/score_quality.php --limit=25; sleep 120; done &
```

---

## Running Tests

### Unit and integration tests

```bash
composer test              # all tests
composer test:unit         # unit only
composer test:integration  # integration only (requires DB connection)
```

### Static analysis

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs --standard=PSR12 src/
```

### Playwright end-to-end tests

Requires Node.js (already installed above):

```bash
cd tests/Playwright
npm install
npx playwright install chromium
npx playwright test --project=chromium
```

---

## GrowthBook Feature Flags (optional)

The app works fine without GrowthBook — all feature flags default to off when `GROWTHBOOK_CLIENT_KEY` is empty. If you want to enable it locally, install MongoDB and GrowthBook manually, or leave it unconfigured.

---

## Stopping Services

```bash
brew services stop nginx
brew services stop php@8.4
brew services stop mysql@8.4
```

---

## Troubleshooting

**`php-fpm` won't start / port 9000 in use:**
```bash
lsof -i :9000    # find the conflicting process
brew services restart php@8.4
```

**MySQL connection refused:**
```bash
brew services list | grep mysql   # check it's running
mysql -u stratflow -pstratflow_secret -h 127.0.0.1 stratflow -e "SELECT 1"
```

**`pdo_mysql` not found:**
```bash
php -i | grep pdo_mysql
# If missing, check that extension=pdo_mysql is in your php.ini
# and restart PHP-FPM: brew services restart php@8.4
```

**Uploads not working — check directory permissions:**
```bash
chmod -R 775 public/uploads storage/
```

**`init-db.php` fails on migration already exists:**
The script is idempotent — each migration is recorded in a `migrations` table and skipped if already applied. Safe to re-run.
