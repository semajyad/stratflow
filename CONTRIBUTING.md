# Contributing to StratFlow

This guide covers everything a new developer needs to get StratFlow running locally and make their first contribution.

## Prerequisites

| Tool | Purpose | Install |
|------|---------|---------|
| [Docker Desktop](https://www.docker.com/products/docker-desktop/) | Run the full stack (PHP, MySQL, Nginx) | Required |
| [Git](https://git-scm.com/) | Version control | Required |
| PHP 8.4 (local) | IDE autocomplete and `php -l` linting | Optional |
| [Stripe CLI](https://stripe.com/docs/stripe-cli) | Test Stripe webhooks locally | Optional |
| [Composer](https://getcomposer.org/) | Install PHP dependencies locally (IDE support) | Optional |

---

## 1. Clone and configure

```bash
git clone https://github.com/semajyad/stratflow.git
cd stratflow
cp .env.example .env
```

Open `.env` and fill in the required values (minimum for local dev):

```env
GEMINI_API_KEY=your-key-here          # https://aistudio.google.com/apikey
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...       # from Stripe CLI (see step 4)
TOKEN_ENCRYPTION_KEY=replace-with-32-bytes   # any 32-char random string for dev
```

Everything else in `.env.example` has working defaults for local Docker.

---

## 2. Start the Docker stack

```bash
docker compose up -d --build
```

This starts four containers:

| Container | Port | Purpose |
|-----------|------|---------|
| `nginx` | **8890** | Web server |
| `php` | — | PHP-FPM app server |
| `mysql` | **3307** | MySQL 8.4 |
| `quality-worker` | — | Async quality scoring (background loop) |

MySQL auto-initialises from `database/schema.sql` on first start. Watch for readiness:

```bash
docker compose logs -f mysql
# Wait until you see: ready for connections
```

---

## 3. Create your admin user

```bash
docker compose exec php php scripts/create-admin.php
```

Follow the prompts to set email, password, and name. Then open [http://localhost:8890](http://localhost:8890) and log in.

---

## 4. Set up Stripe webhooks (optional)

To test subscription flows locally, forward Stripe events to your local server:

```bash
stripe listen --forward-to http://localhost:8890/webhook/stripe
```

The CLI prints a `whsec_...` signing secret — paste it into `STRIPE_WEBHOOK_SECRET` in `.env`, then restart:

```bash
docker compose restart php
```

---

## 5. Run the test suite

```bash
docker compose exec php vendor/bin/phpunit
```

Or run specific suites:

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit
docker compose exec php vendor/bin/phpunit --testsuite integration
```

Tests are in `tests/Unit/` and `tests/Integration/`. See [docs/TESTING.md](docs/TESTING.md) for details.

---

## 6. Database access

MySQL is exposed on `localhost:3307` (non-standard port to avoid conflicts with a local MySQL install).

| Setting | Value |
|---------|-------|
| Host | `localhost` |
| Port | `3307` |
| Database | `stratflow` |
| User | `stratflow` |
| Password | `stratflow_secret` |

Connect with any MySQL client (TablePlus, DBeaver, DataGrip, etc.) or via CLI:

```bash
mysql -h 127.0.0.1 -P 3307 -u stratflow -pstratflow_secret stratflow
```

---

## 7. Applying schema changes

New migrations go in `database/migrations/` following the existing sequential naming convention (e.g. `041_my_change.sql`). Migrations run automatically during Railway deploys via `scripts/init-db.php`.

For local development, apply migrations manually:

```bash
docker compose exec mysql mysql -u stratflow -pstratflow_secret stratflow < database/migrations/041_my_change.sql
```

To reset from scratch (destroys all data):

```bash
docker compose down -v
docker compose up -d --build
```

---

## 8. Common development commands

```bash
# View all container logs
docker compose logs -f

# View PHP application logs only
docker compose logs -f php

# Restart PHP after config changes
docker compose restart php

# Open a shell in the PHP container
docker compose exec php bash

# Run Composer commands
docker compose exec php composer install
docker compose exec php composer require vendor/package

# Lint a PHP file
docker compose exec php php -l src/Controllers/MyController.php

# Stop the stack
docker compose down

# Stop and destroy database (full reset)
docker compose down -v
```

---

## 9. Project structure

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
├── public/             Web root (index.php + assets/)
├── database/
│   ├── schema.sql      Canonical schema
│   └── migrations/     Sequential ALTER/CREATE scripts
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── phpunit.xml
├── bin/                Background worker scripts
├── scripts/            CLI tools (create-admin.php, init-db.php, ...)
└── docker/             Nginx and PHP-FPM Dockerfile
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for a detailed walkthrough.

---

## 10. Adding a new feature

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

## 11. Code standards

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

## 12. Unit test rule

**Every new or modified `src/**/*.php` file requires a matching PHPUnit test.**

Test file location mirrors the source path:

| Source file | Test file |
|-------------|-----------|
| `src/Controllers/FooController.php` | `tests/Unit/Controllers/FooControllerTest.php` |
| `src/Models/Bar.php` | `tests/Unit/Models/BarTest.php` |
| `src/Services/BazService.php` | `tests/Unit/Services/BazServiceTest.php` |

Do not open a PR without the accompanying test file.

---

## 13. Pull request checklist

Before submitting a PR:

- [ ] `vendor/bin/phpunit` passes with no failures
- [ ] Every modified `src/**/*.php` has a corresponding test
- [ ] No secrets, API keys, or credentials in the diff
- [ ] SQL queries use prepared statements; no string concatenation
- [ ] New routes include appropriate middleware (`auth`, `csrf`, `workflow_write`)
- [ ] Template output uses `htmlspecialchars()`
- [ ] Migration file added for any schema changes
- [ ] Relevant documentation updated (`docs/API.md`, `docs/DATABASE.md`, etc.)

---

## 14. Environment variables reference

| Variable | Required | Description |
|----------|----------|-------------|
| `GEMINI_API_KEY` | Yes | Google AI Studio API key |
| `GEMINI_MODEL` | No | Default: `gemini-3-flash-preview` |
| `STRIPE_PUBLISHABLE_KEY` | Yes | Stripe publishable key (`pk_test_...`) |
| `STRIPE_SECRET_KEY` | Yes | Stripe secret key (`sk_test_...`) |
| `STRIPE_WEBHOOK_SECRET` | Yes | Stripe webhook signing secret |
| `STRIPE_PRICE_PRODUCT` | Yes | Stripe price ID for product plan |
| `STRIPE_PRICE_EVAL_BOARD` | Yes | Stripe price ID for evaluation board add-on |
| `TOKEN_ENCRYPTION_KEY` | Yes | 32-byte key for encrypting OAuth tokens at rest |
| `DB_HOST` | No | Default: `mysql` (Docker service name) |
| `DB_DATABASE` | No | Default: `stratflow` |
| `DB_USERNAME` | No | Default: `stratflow` |
| `DB_PASSWORD` | No | Default: `stratflow_secret` |
| `RESEND_API_KEY` | No | Transactional email via Resend |
| `GITHUB_APP_ID` | No | Required for GitHub App integration |
| `GITHUB_APP_PRIVATE_KEY` | No | PEM content for GitHub App (newlines as `\n`) |
| `JIRA_CLIENT_ID` | No | Required for Jira OAuth integration |
| `JIRA_CLIENT_SECRET` | No | Required for Jira OAuth integration |
| `XERO_CLIENT_ID` | No | Required for Xero integration |
| `XERO_CLIENT_SECRET` | No | Required for Xero integration |
| `APP_ENV` | No | `local`, `staging`, or `production` |
| `APP_DEBUG` | No | `true` enables detailed error pages |

---

## 15. Getting help

- Read the docs in `docs/` — especially [ARCHITECTURE.md](docs/ARCHITECTURE.md) for the system design
- Check `docs/ci-learnings.md`, `docs/security-learnings.md`, and `docs/test-learnings.md` for known issues and their fixes
- Open a GitHub issue for bugs or feature requests
