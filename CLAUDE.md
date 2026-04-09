# CLAUDE.md — StratFlow

## Compact Instructions

When running `/compact`, preserve: schema migration decisions, controller/model patterns established,
Stripe webhook handler logic, security findings, Gemini prompt constants, and multi-tenant `org_id`
patterns. Drop: PHP lint output, directory listings, successful test transcripts.

## Session Startup

Read these at conversation start:
- `docs/01_PROJECT_STATUS.md` — current state and what's in progress
- `docs/02_IMPLEMENTATION_PLAN.md` — what's done and what's next
- `docs/learnings.md` — patterns and mistakes to avoid

Briefly summarise before proceeding.

## Directory Structure

```
stratflow/
  src/Controllers/    — MVC controllers (one per resource)
  src/Models/         — PDO models (multi-tenant, all queries filter org_id)
  src/Views/          — PHP templates (htmlspecialchars all user output)
  src/Config/         — routes.php, middleware stack definition
  src/Middleware/     — auth, csrf, admin, superadmin guards
  public/             — web root (index.php entry point only)
  migrations/         — SQL migration files (numbered, sequential)
  tests/              — PHPUnit test suite
  docker/             — Nginx, PHP-FPM, MySQL service configs
```

## Commands

```bash
docker compose up -d                               # Start full stack
docker compose exec php vendor/bin/phpunit        # Run tests
docker compose exec php composer install          # Install deps
docker compose logs -f php                        # PHP errors live
stripe listen --forward-to localhost/webhook      # Forward Stripe webhooks locally
```

## Code Style

See `.claude/skills/php-conventions/SKILL.md` for full rules. Critical points:

- PHP 8.4 strict types (`declare(strict_types=1)` in every file); PSR-12 formatting
- PDO prepared statements only — never string-concatenated SQL
- Every query filters by `org_id`: `WHERE org_id = ?` bound to `$_SESSION['user']['org_id']`
- Template output: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on every user value
- CSRF middleware on all state-changing POST routes (exception: `WebhookController`)
- Stripe keys: `sk_test_*` only in dev/test — `sk_live_*` never hardcoded

## Infrastructure

| Service | Address |
|---|---|
| MySQL 8.4 | `mysql:3306` (Docker internal), `localhost:3306` (host) |
| Nginx | `localhost:80` |
| PHP-FPM | `php:9000` (Docker internal) |

## Security Gate

Before every `git commit`, the pre-commit hook requires a fresh `.claude/.security-audit-ok`
marker file (≤5 min old). Create it by running the `security-auditor` subagent:
> Invoke the security-auditor agent, then: `touch .claude/.security-audit-ok`

See `.claude/agents/security-auditor.md` for what it checks.
