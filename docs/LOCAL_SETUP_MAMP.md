# Local Setup with MAMP (macOS)

This guide gets StratFlow running locally on macOS using MAMP as the MySQL host
and PHP runtime. Docker is not required.

---

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| [MAMP](https://www.mamp.info/) | 6.x or later | Provides MySQL 8.x and a web server |
| PHP CLI | 8.2+ | MAMP includes one; confirm with `php -v` |
| [Composer](https://getcomposer.org/) | 2.x | Install via `brew install composer` |
| Git | any | Install via `brew install git` |

MAMP ships a PHP 8.x binary at `/Applications/MAMP/bin/php/php8.x.x/bin/php`.
Add it to your PATH so the terminal uses it:

```bash
# Add this to ~/.zshrc (replace 8.3.x with your actual version)
export PATH="/Applications/MAMP/bin/php/php8.3.x/bin:$PATH"
```

Restart your terminal, then confirm: `php -v`

---

## 1 — Clone the repo

```bash
git clone https://github.com/semajyad/stratflow.git
cd stratflow
```

> **Renaming the folder?** You can rename the directory to anything you like
> (`StratFlow_v2`, `my-stratflow`, etc.). The PHP code uses the namespace
> `StratFlow\` which is defined in **one place only** — `composer.json`. You
> never need to change any PHP file when renaming the directory; see the
> [Namespace note](#namespace-note-renaming-the-project) at the bottom.

---

## 2 — Start MAMP and configure MySQL

1. Open MAMP, click **Start**.
2. Go to **Preferences → Ports** and note the MySQL port (usually **8889** for MAMP,
   or **3306** for MAMP Pro).
3. Open **phpMyAdmin** (the "Open WebStart page" button → phpMyAdmin link).
4. Create a new database:
   - Database name: `stratflow`
   - Collation: `utf8mb4_unicode_ci`
5. Create a dedicated user (or use `root` for local dev only):
   - User: `stratflow`
   - Password: `stratflow_secret`
   - Grant all privileges on the `stratflow` database.

---

## 3 — Configure the virtual host

MAMP's built-in Apache can serve StratFlow directly. The document root must
point to the `public/` subdirectory.

### Option A — MAMP Pro (recommended)

1. Go to **Hosts → Add host**.
2. Set document root to `/path/to/stratflow/public`.
3. Set the host name to `stratflow.local` (or any local domain).
4. Save and restart.

### Option B — Plain MAMP (free)

1. Open MAMP → **Preferences → Web Server**.
2. Set document root to `/path/to/stratflow/public`.
3. Click **OK** → **Start** (restarts Apache).
4. Access the app at `http://localhost:8888` (MAMP's default HTTP port).

---

## 4 — Install PHP dependencies

```bash
cd stratflow
composer install
```

This downloads all libraries into `vendor/`. Do not commit `vendor/`.

---

## 5 — Create the `.env` file

```bash
cp .env.example .env
```

Open `.env` and update at minimum these values:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8888        # or http://stratflow.local if using MAMP Pro

DB_HOST=127.0.0.1
DB_PORT=8889                         # 8889 for MAMP free; 3306 for MAMP Pro
DB_DATABASE=stratflow
DB_USERNAME=stratflow
DB_PASSWORD=stratflow_secret

TOKEN_ENCRYPTION_KEY=<base64-encoded 32-byte key>   # see below
```

Generate a secure key:

```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

Paste the output as `TOKEN_ENCRYPTION_KEY`.

Leave `STRIPE_*`, `GEMINI_API_KEY`, and other third-party keys empty or as
placeholder values — the app starts without them for local development.

---

## 6 — Initialise the database

```bash
php scripts/init-db.php
```

This runs `database/schema.sql` (creates all tables) and then applies any
pending migrations from `database/migrations/`. It is safe to run multiple
times — already-applied migrations are skipped.

Expected output:

```text
Connected to database: stratflow@127.0.0.1
Schema applied successfully.
All migrations applied.
Tables present: 42 (users, projects, hl_work_items, ...)
Database initialization complete.
```

---

## 7 — Access the app

Open `http://localhost:8888` (or your MAMP Pro host name).

Register a new account. The first registered user in a new database is
automatically assigned the `org_admin` role.

---

## Troubleshooting

### "Access denied for user 'stratflow'@'localhost'"

MAMP's MySQL socket path differs from the system default. Use `127.0.0.1`
(not `localhost`) as `DB_HOST`. PHP resolves `localhost` to a Unix socket
that MAMP does not listen on.

### "Connection refused" on port 3306

MAMP free uses port **8889**. Set `DB_PORT=8889` in `.env`.

### PHP version mismatch

Confirm `php -v` matches the MAMP PHP version. If not, update your PATH (see Prerequisites above).

### Composer not found

Install via Homebrew: `brew install composer`. If Homebrew is not installed:
`/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`

---

## Namespace note: renaming the project

StratFlow uses the PHP namespace `StratFlow\` throughout the codebase.
**This namespace is not tied to the directory name** — it is registered in
`composer.json`:

```json
"autoload": {
    "psr-4": {
        "StratFlow\\": "src/"
    }
}
```

Composer's autoloader maps `StratFlow\Core\Response` → `src/Core/Response.php`
regardless of what you named the parent folder.

**If you rename the directory**, you only need to update one line — the path in
`composer.json` if you also move the `src/` folder (you usually won't):

```json
"StratFlow\\": "src/"   ← this path is relative to composer.json, not the folder name
```

**You do not need to change any PHP file** to rename the project directory.
The namespace `StratFlow\` is the application's internal identity, separate from
the folder name on disk.

If you want to rename the namespace itself (e.g. to `Acme\` for a white-label),
that is a global find-and-replace across `src/`, `tests/`, and `composer.json`,
plus a `composer dump-autoload`. This is intentional — PHP namespaces are
explicit per-file declarations, not a single config value, because they affect
IDE autocompletion, static analysis, and reflection.
