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

## Security Notes — MANDATORY on security-touching PRs

When opening a PR that touches auth, sessions, permissions, billing, webhooks,
file uploads, external APIs, HTTP headers, middleware, controllers, or any new
stored data: **fill in the `## Security notes` section** of the PR description.

Answer whichever of these apply (3–5 bullet points, not an essay):
- What data does this touch, and who should be able to access it?
- What is the worst-case abuse by a malicious user or outsider?
- What existing controls cover it, and is anything left unguarded?

For pure refactors, tests, docs, or config-only changes: delete the section.

## Unit Test Rule — MANDATORY

**Every new or modified `src/**/*.php` file must have a unit test written before the task is considered complete.**

After writing or modifying any source file:
1. Invoke the `unit-test-writer` skill immediately.
2. The skill spawns a **Haiku** agent with full context of the new code.
3. The Haiku agent writes the test file to `tests/Unit/<matching path>Test.php`.
4. You review the output and commit both files together.

This is non-negotiable. Do not open a PR, commit source changes, or mark a task done without the accompanying test file.

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
This creates your branch from `origin/main`, registers your session in the ledger, shows a briefing of other active sessions, and installs git hooks automatically. See `docs/AGENT_WORKFLOW.md` for full details.

**On a fresh clone (or if hooks are missing), install them manually:**
```bash
sh scripts/install-hooks.sh
```
Hooks live in `scripts/hooks/` (versioned) and are installed to `.git/hooks/`.

**If you think work is "missing"**, run `python scripts/agent/recover.py` first — it scans reflog, stash, and unpushed branches before assuming data loss.

**Instead of `git commit`, use:**
```bash
python scripts/agent/safe-commit.py -m "feat: ..." src/Foo.php tests/Unit/FooTest.php
```

## CI / Branch Hygiene Rules

**Rebase, never merge main into a feature branch.**
`git merge main` creates a merge commit that makes GitHub report `CONFLICTING`
for squash-merge PRs, requiring a clean linear branch to be recreated. Always use:
```bash
git fetch origin && git rebase origin/main
```

**When `pull_request:synchronize` events don't fire**, manually trigger Tests with:
```bash
gh workflow run tests.yml --repo semajyad/stratflow --ref <branch-name>
```

**Coverage threshold** lives in `.github/coverage-threshold.txt` — not hardcoded
in `tests.yml`. Edit that file, not the workflow, when raising the threshold.

**Playwright CI result** is parsed from `test-results.json` (written by
`PLAYWRIGHT_JSON_OUTPUT_NAME` env var). Never trust `--reporter=json` stdout output
or a grep-based fallback — both false-positive when git diff metadata contains "failed".

**Admin merge is appropriate** when:
- Playwright (fast) fails but the run shows 0 unexpected / 0 flaky (known false positive)
- The branch is clean and the only failing check is a status check from a stale commit

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
