# Contributing to StratFlow

This guide covers everything a new developer needs to get StratFlow running locally and make their first contribution. Two setup paths are available: Docker (quickest) or bare-metal (no Docker required).

---

## Prerequisites

| Tool | Docker path | Bare-metal path | Install |
|------|-------------|-----------------|---------|
| [Git](https://git-scm.com/) | Required | Required | [git-scm.com](https://git-scm.com/) |
| [Docker Desktop](https://www.docker.com/products/docker-desktop/) | Required | Not needed | [docker.com](https://www.docker.com/products/docker-desktop/) |
| PHP 8.4 | Not needed | Required | See §B-1 below |
| MySQL 8.x | Not needed | Required | See §B-2 below |
| [Composer](https://getcomposer.org/) | Not needed | Required | [getcomposer.org](https://getcomposer.org/) |
| [Stripe CLI](https://stripe.com/docs/stripe-cli) | Optional | Optional | For local webhook testing |

---

## Install the git hooks

All hooks live in `scripts/hooks/` (versioned). Install them once after cloning — the install script is idempotent:

```bash
sh scripts/install-hooks.sh
```

Hooks installed:
- **pre-commit** — PHP lint, coverage gate, bug-test gate, and documentation currency check

The hook runs `scripts/ci/check_docs.py --staged` before every commit. If you change a tracked source file without updating the corresponding doc, the commit is blocked with a clear message explaining what to fix.

**Trigger → required doc:**

| If you change… | …update this doc |
|----------------|-----------------|
| `src/Config/routes.php` | `docs/API.md` |
| `database/migrations/*.sql` or `database/schema.sql` | `docs/DATABASE.md` |
| New `src/Controllers/*.php` | `docs/ARCHITECTURE.md` |
| New `src/Models/*.php` | `docs/ARCHITECTURE.md` |
| New `src/Services/*.php` | `docs/ARCHITECTURE.md` |
| New `src/Middleware/*.php` | `docs/ARCHITECTURE.md` |
| New `src/Services/Prompts/*.php` | `docs/GEMINI_PROMPTS.md` |
| `.env.example` | `CONTRIBUTING.md` |
| `docker-compose.yml` | `README.md` |

> **Emergency bypass:** `SKIP_DOCS_CHECK=1 git commit -m "..."` or `git commit --no-verify`. Use sparingly — the same check runs in CI and will block your PR.

---

## Path A — Docker (recommended for most developers)

### A-1. Clone and configure

```bash
git clone https://github.com/semajyad/stratflow.git
cd stratflow
cp .env.example .env
```

Open `.env` and set the minimum required values:

```env
GEMINI_API_KEY=your-key-here           # https://aistudio.google.com/apikey
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...        # from Stripe CLI (see §A-4)
STRIPE_PRICE_PRODUCT=price_...         # from your Stripe test products
STRIPE_PRICE_EVAL_BOARD=price_...
TOKEN_ENCRYPTION_KEY=replace-with-32-bytes  # any 32-char random string
```

All other variables in `.env.example` have working Docker defaults — leave them as-is.

### A-2. Start the Docker stack

```bash
docker compose up -d --build
```

Four containers start:

| Container | Port | Purpose |
|-----------|------|---------|
| `nginx` | **8890** | Web server |
| `php` | — | PHP-FPM app server |
| `mysql` | **3307** | MySQL 8.4 |
| `quality-worker` | — | Async quality scoring (runs every 2 min) |

MySQL auto-initialises from `database/schema.sql` plus all migrations on first start. Wait for it to be ready:

```bash
docker compose logs -f mysql
# Wait until you see: ready for connections
```

### A-3. Create your admin user

```bash
docker compose exec php php scripts/create-admin.php
```

Follow the prompts to set email, password, name, and organisation. Then open [http://localhost:8890](http://localhost:8890) and log in.

### A-4. Set up Stripe webhooks (optional)

```bash
stripe listen --forward-to http://localhost:8890/webhook/stripe
```

The CLI prints a `whsec_...` signing secret — paste it into `STRIPE_WEBHOOK_SECRET` in `.env`, then restart:

```bash
docker compose restart php
```

### A-5. Run the test suite

```bash
docker compose exec php vendor/bin/phpunit
# Or by suite:
docker compose exec php vendor/bin/phpunit --testsuite unit
docker compose exec php vendor/bin/phpunit --testsuite integration
```

### A-6. Database access (Docker)

MySQL is exposed on `localhost:3307` (non-standard port to avoid conflicts with a local MySQL install).

| Setting | Value |
|---------|-------|
| Host | `127.0.0.1` |
| Port | `3307` |
| Database | `stratflow` |
| User | `stratflow` |
| Password | `stratflow_secret` |

```bash
mysql -h 127.0.0.1 -P 3307 -u stratflow -pstratflow_secret stratflow
```

### A-7. Common Docker commands

```bash
docker compose logs -f              # All container logs
docker compose logs -f php          # PHP logs only
docker compose restart php          # Restart PHP after .env changes
docker compose exec php bash        # Shell inside PHP container
docker compose exec php composer install
docker compose exec php php -l src/Controllers/MyController.php
docker compose down                 # Stop stack
docker compose down -v              # Stop + destroy database (full reset)
```

### A-8. Applying schema changes (Docker)

```bash
docker compose exec mysql mysql -u stratflow -pstratflow_secret stratflow \
  < database/migrations/041_my_change.sql
```

---

## Path B — Bare-metal (no Docker)

Use this path if you cannot or prefer not to run Docker.

### B-1. Install PHP 8.4

**Windows:**
1. Download the latest PHP 8.4 Non-Thread Safe ZIP from [windows.php.net/download](https://windows.php.net/download/)
2. Extract to `C:\php`
3. Add `C:\php` to your `PATH`
4. Copy `php.ini-development` → `php.ini`
5. Enable the following extensions in `php.ini` (uncomment each line):
   ```ini
   extension=curl
   extension=fileinfo
   extension=gd
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=zip
   ```
6. Verify: `php -v`

**macOS (Homebrew):**
```bash
brew install php@8.4
brew link php@8.4 --force
```

**Ubuntu/Debian:**
```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.4 php8.4-cli php8.4-pdo php8.4-pdo-mysql \
     php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
     php8.4-gd php8.4-intl php8.4-bcmath
```

Verify: `php -v` (should show `PHP 8.4.x`)

### B-2. Install MySQL 8.x

**Windows:** Download MySQL 8.x Installer from [dev.mysql.com/downloads](https://dev.mysql.com/downloads/installer/)

**macOS:**
```bash
brew install mysql@8.4
brew services start mysql@8.4
```

**Ubuntu/Debian:**
```bash
sudo apt install mysql-server
sudo systemctl start mysql
sudo systemctl enable mysql
```

### B-3. Create the database and user

Connect to MySQL as root:
```bash
mysql -u root -p
```

Run these SQL statements (replace `stratflow_secret` with a password of your choice):
```sql
CREATE DATABASE stratflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stratflow'@'127.0.0.1' IDENTIFIED BY 'stratflow_secret';
GRANT ALL PRIVILEGES ON stratflow.* TO 'stratflow'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

### B-4. Clone and install dependencies

```bash
git clone https://github.com/semajyad/stratflow.git
cd stratflow
composer install
```

### B-5. Configure environment

```bash
cp .env.example .env
```

Edit `.env` — set these values for bare-metal (the defaults assume Docker hostnames):

```env
APP_ENV=local
APP_URL=http://localhost:8890
APP_DEBUG=true

# Database — use 127.0.0.1 not 'mysql' (Docker hostname)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stratflow
DB_USERNAME=stratflow
DB_PASSWORD=stratflow_secret

# AI — required
GEMINI_API_KEY=your-key-here           # https://aistudio.google.com/apikey
GEMINI_MODEL=gemini-3-flash-preview

# Stripe — required for billing flows
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_PRODUCT=price_...
STRIPE_PRICE_CONSULTANCY=price_...
STRIPE_PRICE_USER_PACK=price_...
STRIPE_PRICE_EVAL_BOARD=price_...

# Security — required, any 32-character random string
TOKEN_ENCRYPTION_KEY=replace-with-a-32-byte-random-secret

# Email (optional for local dev — leave blank to disable email sending)
RESEND_API_KEY=
MAIL_FROM_EMAIL=noreply@localhost
MAIL_FROM_NAME=StratFlow

# Optional integrations — leave blank to disable
GITHUB_APP_ID=
GITHUB_APP_SLUG=
GITHUB_APP_WEBHOOK_SECRET=
GITHUB_APP_PRIVATE_KEY=
JIRA_CLIENT_ID=
JIRA_CLIENT_SECRET=
XERO_CLIENT_ID=
XERO_CLIENT_SECRET=

UPLOAD_MAX_SIZE=52428800

# GrowthBook feature flags — leave blank to disable
GROWTHBOOK_API_HOST=
GROWTHBOOK_CLIENT_KEY=
```

### B-6. Initialise the database

Run the init script — it applies `database/schema.sql` and all 40 migrations automatically:

```bash
php scripts/init-db.php
```

Expected output:
```
Connected to database: stratflow@127.0.0.1
Schema applied successfully.
Running migration: 001_v1_completion.sql
Running migration: 002_admin_features.sql
...
Running migration: 040_org_soft_delete.sql
Database initialisation complete.
```

If you see `Skipped (already applied)` lines for individual statements — that is normal; the migrations are idempotent.

### B-7. Create your admin user

```bash
php scripts/create-admin.php
```

Follow the interactive prompts for email, password, name, and organisation name. Alternatively, pass arguments directly:

```bash
php scripts/create-admin.php \
  --email=admin@example.com \
  --password=YourSecurePassword1! \
  --name="Admin User" \
  --org="My Organisation"
```

### B-8. Start the development server

PHP has a built-in web server suitable for local development:

```bash
php -S localhost:8890 -t public public/router.php
```

Open [http://localhost:8890](http://localhost:8890) and log in with the credentials you just created.

> **Note:** The built-in server is single-threaded and not suitable for production. Use it only for local development.

### B-9. Start the quality worker (optional)

The quality scoring worker runs as a background process. For local development you can run it once manually, or in a loop in a separate terminal:

```bash
# Run one batch
php bin/score_quality.php

# Run continuously (Ctrl+C to stop)
php bin/score_quality.php --loop
```

### B-10. Run the test suite

```bash
vendor/bin/phpunit
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

---

## Project structure

```
stratflow/
├── src/
│   ├── Config/         routes.php + config.php
│   ├── Controllers/    One controller per page/feature
│   ├── Core/           Framework primitives (Router, Auth, CSRF, DB, ...)
│   ├── Middleware/      Auth, CSRF, workflow_write, billing, executive, api_auth, ...
│   ├── Models/         PDO data-access objects (one per table)
│   └── Services/
│       ├── GeminiService.php         AI client (Gemini primary, OpenAI fallback)
│       ├── SoundingBoardService.php  AI panel evaluation
│       ├── StoryQualityScorer.php    Async quality scoring
│       └── Prompts/                  AI prompt constants
├── templates/          PHP templates (layouts/, partials/, *.php)
├── public/             Web root served by Nginx/Apache (index.php + assets/)
├── database/
│   ├── schema.sql      Canonical full schema
│   └── migrations/     Sequential ALTER/CREATE scripts (001–040+)
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── phpunit.xml
├── bin/                Background worker scripts
├── scripts/            CLI tools (create-admin.php, init-db.php, ...)
└── docker/             Nginx config and PHP-FPM Dockerfile
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for a detailed walkthrough.

---

## Adding a new feature

### Backend (PHP)

1. **Route** — Add a route in `src/Config/routes.php` with appropriate middleware
2. **Controller** — Create or extend a controller in `src/Controllers/`
3. **Model** — Add static data-access methods in `src/Models/` (PDO prepared statements only)
4. **Migration** — Write a `database/migrations/NNN_description.sql` if new columns/tables are needed
5. **Test** — Write a PHPUnit test in `tests/Unit/` (required before PR — see Unit Test Rule below)

### Frontend (PHP templates + vanilla JS)

1. **Template** — Add or extend a `.php` file in `templates/`
2. **JS** — Add to `public/assets/js/app.js` (delegated event handlers in the existing click block)
3. **CSS** — Add to `public/assets/css/app.css`

### AI prompt

1. Create a new class in `src/Services/Prompts/` following the existing pattern (static constants, `buildPrompt()` if dynamic)
2. Document it in [docs/GEMINI_PROMPTS.md](docs/GEMINI_PROMPTS.md)

---

## Code standards

- **PHP 8.4** with strict types (`declare(strict_types=1)` at the top of every file)
- **PSR-4 autoloading** — namespace `StratFlow\Controllers\`, `StratFlow\Models\`, etc.
- **PDO prepared statements only** — never concatenate SQL strings
- **`org_id` on every query** — all tenant-scoped tables must filter by `org_id`
- **`htmlspecialchars()` on all template output** — no raw user input in HTML
- **CSRF on all state-changing routes** — use `['workflow_write', 'csrf']` middleware
- **No inline event handlers** in templates — use delegated JS in `app.js`
- **`Logger::warn/error()`** instead of `error_log()` in all new code

See [docs/SECURE_CODING.md](docs/SECURE_CODING.md) for the full security rules.

---

## Unit test rule

**Every new or modified `src/**/*.php` file requires a matching PHPUnit test.**

Test file location mirrors the source path:

| Source file | Test file |
|-------------|-----------|
| `src/Controllers/FooController.php` | `tests/Unit/Controllers/FooControllerTest.php` |
| `src/Models/Bar.php` | `tests/Unit/Models/BarTest.php` |
| `src/Services/BazService.php` | `tests/Unit/Services/BazServiceTest.php` |

Do not open a PR without the accompanying test file.

---

## Pull request checklist

- [ ] `vendor/bin/phpunit` passes with no failures
- [ ] Every modified `src/**/*.php` has a corresponding test
- [ ] No secrets, API keys, or credentials in the diff
- [ ] SQL queries use prepared statements; no string concatenation
- [ ] New routes include appropriate middleware (`auth`, `csrf`, `workflow_write`)
- [ ] Template output uses `htmlspecialchars()`
- [ ] Migration file added for any schema changes
- [ ] Relevant documentation updated (`docs/API.md`, `docs/DATABASE.md`, etc.)

---

## Environment variables reference

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_ENV` | No | `local`, `staging`, or `production` |
| `APP_URL` | No | Full URL including scheme (e.g. `http://localhost:8890`) |
| `APP_DEBUG` | No | `true` enables detailed error pages |
| `TOKEN_ENCRYPTION_KEY` | **Yes** | 32-byte key for encrypting OAuth tokens at rest |
| `ALLOW_EXTERNAL_AI_PROCESSING` | No | Default `false`; set `true` to allow AI on uploaded data |
| `DB_HOST` | No | Default `mysql` (Docker) or `127.0.0.1` (bare-metal) |
| `DB_PORT` | No | Default `3306` |
| `DB_DATABASE` | No | Default `stratflow` |
| `DB_USERNAME` | No | Default `stratflow` |
| `DB_PASSWORD` | No | Default `stratflow_secret` |
| `GEMINI_API_KEY` | **Yes** | Google AI Studio API key — [get one here](https://aistudio.google.com/apikey) |
| `GEMINI_MODEL` | No | Default `gemini-3-flash-preview` |
| `STRIPE_PUBLISHABLE_KEY` | **Yes** | Stripe publishable key (`pk_test_...`) |
| `STRIPE_SECRET_KEY` | **Yes** | Stripe secret key (`sk_test_...`) |
| `STRIPE_WEBHOOK_SECRET` | **Yes** | Stripe webhook signing secret (`whsec_...`) |
| `STRIPE_PRICE_PRODUCT` | **Yes** | Stripe price ID for the product plan |
| `STRIPE_PRICE_CONSULTANCY` | **Yes** | Stripe price ID for the consultancy plan |
| `STRIPE_PRICE_USER_PACK` | **Yes** | Stripe price ID for additional user packs |
| `STRIPE_PRICE_EVAL_BOARD` | **Yes** | Stripe price ID for evaluation board add-on |
| `RESEND_API_KEY` | No | Transactional email via Resend |
| `MAILERSEND_API_KEY` | No | Alternative transactional email provider |
| `MAIL_FROM_NAME` | No | Default `StratFlow System` |
| `MAIL_FROM_EMAIL` | No | Default `support@4168411.xyz` |
| `MAIL_SMTP_HOST` | No | SMTP server host |
| `MAIL_SMTP_PORT` | No | SMTP port (default `587`) |
| `MAIL_SMTP_ENCRYPTION` | No | `auto`, `tls`, or `ssl` |
| `MAIL_SMTP_USER` | No | SMTP username |
| `MAIL_SMTP_PASS` | No | SMTP password |
| `GITHUB_APP_ID` | No | Required for GitHub App integration |
| `GITHUB_APP_SLUG` | No | GitHub App slug name |
| `GITHUB_APP_WEBHOOK_SECRET` | No | Secret for verifying GitHub App webhook payloads |
| `GITHUB_APP_PRIVATE_KEY` | No | PEM content for GitHub App (newlines as `\n`) |
| `GITHUB_APP_PRIVATE_KEY_PATH` | No | Path to PEM file (Docker only — use instead of inline key) |
| `JIRA_CLIENT_ID` | No | Required for Jira OAuth integration |
| `JIRA_CLIENT_SECRET` | No | Required for Jira OAuth integration |
| `XERO_CLIENT_ID` | No | Required for Xero integration |
| `XERO_CLIENT_SECRET` | No | Required for Xero integration |
| `UPLOAD_MAX_SIZE` | No | Max file upload bytes (default `52428800` = 50 MB) |
| `GROWTHBOOK_API_HOST` | No | GrowthBook feature flag API host |
| `GROWTHBOOK_CLIENT_KEY` | No | GrowthBook client key (leave empty to disable all flags) |

---

## Getting help

- Read the docs in `docs/` — especially [ARCHITECTURE.md](docs/ARCHITECTURE.md) for the system design
- Check `docs/ci-learnings.md`, `docs/security-learnings.md`, and `docs/test-learnings.md` for known issues and their fixes
- Open a GitHub issue for bugs or feature requests
