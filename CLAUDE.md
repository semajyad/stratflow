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

## Starting a New Work Session

**Every agent starting work must run:**
```bash
python scripts/agent/session-start.py --agent-id <your-id> --goal "<short goal>" --files "<glob>"
```
This creates your branch from `origin/main`, registers your session in the ledger, and shows a briefing of other active sessions. See `docs/AGENT_WORKFLOW.md` for full details.

**If you think work is "missing"**, run `python scripts/agent/recover.py` first — it scans reflog, stash, and unpushed branches before assuming data loss.

**Instead of `git commit`, use:**
```bash
python scripts/agent/safe-commit.py -m "feat: ..." src/Foo.php tests/Unit/FooTest.php
```

## Continuous Improvement — Learnings

When any CI/CD, security, or test failure occurs that reveals a non-obvious root cause, record it:
```bash
python scripts/ci/record_learning.py --category ci --title "..." --symptom "..." --root-cause "..." --fix "..." --prevention "..."
```

**Read these files when working on CI/CD, security, or testing — they contain institutional knowledge about what's failed and been fixed:**
- `docs/ci-learnings.md` — CI/CD failure patterns and fixes
- `docs/security-learnings.md` — security scan issues and accepted patterns
- `docs/test-learnings.md` — test flakiness, coverage patterns, anti-patterns

## Morning CI Triage

**Applies to the first code session each calendar day.**

Run the nightly CI audit before any other work:

```bash
python scripts/ci/morning_audit.py
```

This fetches the latest `nightly-triage` artifact from GitHub Actions, prints a pass/fail table for every nightly job (tests, perf, mutation, Shannon, Snyk, ZAP, smoke), and shows any auto-opened GitHub issues. If a job failed, investigate and fix before starting other work.

If the script is unavailable, read `docs/ci-nightly-history.md` for yesterday's row and check for open `ci-nightly` labelled issues on GitHub.

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
