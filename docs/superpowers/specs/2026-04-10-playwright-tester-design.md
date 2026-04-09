# Playwright Tester Sub-Agent — Design Spec

**Date:** 2026-04-10  
**Status:** Approved  
**Scope:** Pre-commit Playwright E2E test gate for StratFlow

---

## Overview

A Claude Code sub-agent (`playwright-tester`) runs before every git commit. It inspects staged
files, picks a test tier (fast or full), manages the Docker stack, executes Playwright tests, and
either writes a freshness marker (pass) or sends an ntfy alert and blocks the commit (fail).

The pre-commit hook (`pre_commit_playwright.py`) enforces the marker — same pattern as the
existing `security-auditor` gate.

---

## Architecture

```
git commit
    │
    ▼
pre_commit_playwright.py  — checks .claude/.playwright-ok (≤5 min fresh)
    │ absent/stale → blocks: "Run the playwright-tester agent first"
    │ present      → consumes marker, allows commit
    ▼
commit proceeds


Claude invokes playwright-tester sub-agent
    │
    ▼
1. Read `git diff --cached --name-only`
2. Determine tier (see Tier Rules)
3. Check Docker state — record if already running
4. docker compose up -d (if not already running)
5. Poll healthcheck until MySQL + Nginx ready (max 60s)
6. npx playwright test --project=<tier>
7a. PASS → write .claude/.playwright-ok → report summary
7b. FAIL → post ntfy alert → do NOT write marker → report failures
8. docker compose down (only if agent started Docker)
```

---

## Test Structure

```
tests/Playwright/
├── playwright.config.js              # baseURL=http://localhost:8890, 2 projects
├── package.json                      # playwright dependency
├── test-results/                     # screenshots + traces (gitignored)
├── fast/
│   ├── auth.spec.js                  # login, logout, wrong password, session expiry
│   ├── access-gates.spec.js          # admin-only 403 for regular user, superadmin gates
│   └── smoke.spec.js                 # dashboard loads, key pages return 200 not 500
└── full/
    ├── strategy-flow.spec.js         # create org → strategy → work items → board view
    ├── document-upload.spec.js       # upload PDF, parse, attach to strategy item
    ├── document-edge-cases.spec.js   # 0-byte, wrong MIME, oversized, duplicate upload
    ├── stripe-flow.spec.js           # billing page, upgrade UI (Stripe test mode)
    └── multi-tenant.spec.js          # org A cannot access org B data via URL params
```

### Tier Trigger Rules

Agent inspects `git diff --cached --name-only` and applies these rules in order:

| Changed path pattern | Tier |
|---|---|
| `src/Controllers/**` | full |
| `src/Services/FileProcessor*` | full |
| `templates/**` | full |
| `public/assets/**` | fast only |
| anything else | fast only |

If any file matches a "full" trigger, the full suite runs (fast is always included).

---

## Sub-Agent Definition

**File:** `.claude/agents/playwright-tester.md`  
**Model:** `sonnet`  
**Tools:** `Bash`, `Read`

### Behaviour

1. Run `git diff --cached --name-only` → determine tier
2. Run `docker compose ps --services --filter status=running` → record pre-existing state
3. If Docker not running: `docker compose up -d`, then poll until healthy (max 60s)
4. `cd tests/Playwright && npx playwright test --project=fast` (always)
5. If full tier: `npx playwright test --project=full`
6. On **pass**: write `.claude/.playwright-ok` with current timestamp, print summary
7. On **fail**: post ntfy alert (see below), print failing test names + screenshot paths, exit without writing marker
8. If agent started Docker: `docker compose down`

### ntfy Alert (on failure)

```
Topic:   localhost:8090/stratflow-alerts
Title:   stratflow playwright FAILED
Body:    <failing test names, one per line>
         Screenshots: tests/Playwright/test-results/<name>/<file>.png
Priority: high
```

---

## Pre-Commit Hook

**File:** `.claude/hooks/pre_commit_playwright.py`

- Marker path: `.claude/.playwright-ok`
- Freshness window: 5 minutes (300 seconds)
- On valid marker: delete it (consumed), return allow
- On absent/stale marker: return deny with message:
  `"Playwright tests not verified. Ask Claude to run the playwright-tester agent."`
- Bypass conditions (same as security-auditor):
  - `--dry-run` in commit args
  - nothing staged (`git diff --cached --quiet` exits 0)
  - merge commit in progress (`MERGE_HEAD` exists)

**Wired into `.claude/settings.json`** as an additional entry in the PreToolUse `Bash` matcher,
alongside the existing `pre_commit_audit.py`.

---

## Docker Lifecycle

| Scenario | Startup | Teardown |
|---|---|---|
| Docker was already running | skip `up` | skip `down` |
| Docker was not running | `docker compose up -d` + health poll | `docker compose down` |

Health poll: check `docker compose ps` every 2s, wait for `php` and `mysql` services to show
`running` (healthy). Timeout 60s → fail with message "Docker did not become healthy in time."

---

## File Changes Summary

| File | Action |
|---|---|
| `tests/Playwright/package.json` | create |
| `tests/Playwright/playwright.config.js` | create |
| `tests/Playwright/fast/auth.spec.js` | create |
| `tests/Playwright/fast/access-gates.spec.js` | create |
| `tests/Playwright/fast/smoke.spec.js` | create |
| `tests/Playwright/full/strategy-flow.spec.js` | create |
| `tests/Playwright/full/document-upload.spec.js` | create |
| `tests/Playwright/full/document-edge-cases.spec.js` | create |
| `tests/Playwright/full/stripe-flow.spec.js` | create |
| `tests/Playwright/full/multi-tenant.spec.js` | create |
| `.claude/agents/playwright-tester.md` | create |
| `.claude/hooks/pre_commit_playwright.py` | create |
| `.claude/settings.json` | update — add hook entry |
| `.gitignore` | update — add `tests/Playwright/test-results/` and `tests/Playwright/node_modules/` |

---

## Out of Scope

- Visual regression testing (screenshots as baselines)
- API-level contract testing (PHPUnit integration tests already cover this)
- CI/CD pipeline integration (pre-commit only for now)
- Parallel test sharding
