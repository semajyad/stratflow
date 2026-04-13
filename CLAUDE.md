# CLAUDE.md - StratFlow

## Startup

Start with `MEMORY.md`.
Only open the specific docs linked from there that match the task.
Do not read the whole repo documentation set by default.
If the task touches auth, permissions, sessions, secrets, uploads, billing, webhooks, external providers, HTTP headers, middleware, controllers, or templates — **read `docs/SECURE_CODING.md` first**.

## Context Discipline

- Prefer the smallest relevant context slice.
- Read one targeted doc before several broad docs.
- For codebase work, use indexed/project search tools before manual repo-wide scans.
- If a task spans more than 3 files, plan first and keep the plan short.
- If the same fix attempt fails 3 times, stop and rescope instead of thrashing.
- Before finishing, prefer the simpler solution if it reduces code and context size safely.

## Project Rules

- Keep all tenant-scoped queries filtered by `org_id`.
- Use prepared statements only; never concatenate SQL.
- Escape all user-visible template output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Keep CSRF protection on every state-changing browser route except verified webhooks.
- Never log secrets, password-reset URLs, raw tokens, provider credentials, or raw API responses.
- Hash recovery tokens at rest; encrypt stored provider/customer secrets via SecretManager.
- Deny inactive users on every auth path and fail closed on unclear authorization checks.
- Prefer delegated JS in shared bundles; do not introduce inline handlers or new CSP regressions.
- Replace `error_log()` with `\StratFlow\Services\Logger::warn/error()` in all new code.

## Morning Security Check

**Applies to the first code session each calendar day.** See `docs/SECURE_CODING.md` → "Morning Security Check" for the full procedure (read Shannon/ZAP reports, triage findings, promote to permanent rules).

## Commands

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit
docker compose exec php php -l path/to/file.php
docker compose logs -f php
```

## Routing Docs

- Architecture: `docs/ARCHITECTURE.md`
- Database: `docs/DATABASE.md`
- Testing: `docs/TESTING.md`
- Deployment: `docs/DEPLOYMENT.md`
- Security rules: `docs/SECURE_CODING.md`
- Roles and access flags: `docs/USER_ROLES_GUIDE.md`
- AI prompt constants: `docs/GEMINI_PROMPTS.md`

## Local Overrides

Use `CLAUDE.local.md` for machine-specific or temporary personal instructions.
Keep repo-wide guidance here lean so it stays cheap to load.
