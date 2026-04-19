# StratFlow CI/CD Operations Guide

Authoritative reference for the CI/CD pipeline. If you observe unexpected behaviour, check here first.

---

## Architecture Overview

```text
  PR opened by agent
    │
    ├─► multi-agent-guard       (branch naming + claim advisory)
    ├─► self-heal               (lockfile drift, stale baseline, rebase)
    │
    ├─► FAST PATH (<5 min p95)
    │     unit · integration · e2e-fast · PHPStan · test-touch-check · codecov/patch
    │
    ├─► CodeRabbit review ──► autofix (retry 2x) ──► re-review
    │
    └─► auto-merge (stale-review guard + serialised)
          │
          ▼
       main
          │
          ▼
  deploy.yml ──► Railway up ──► healthz poll
          ├─► pass → record LAST_GOOD_DEPLOY_ID → silent
          └─► fail → auto-rollback → ntfy HIGH

  NIGHTLY CHAIN (UTC)
    04:30  nightly-self-heal    (cancel stuck runs, fix main drift, close resolved issues)
    12:30  performance          k6 baseline
    13:00  mutation-testing     Infection MSI
    14:00  security-shannon     Shannon SAST
    15:00  snyk                 Dependency CVEs
    16:00  security-zap         DAST baseline scan
    16:30  e2e-full-nightly     Full Playwright matrix vs staging
    17:00  nightly-triage       Aggregate, open issues, ntfy on new failures
    17:30  morning-summary      Single ntfy digest (SILENT if all green)
```

---

## Required CI Checks (merge gate)

Managed in `.github/auto-merge-required.txt` and mirrored by the active GitHub
ruleset `main-protection`. These exact displayed check contexts must pass before
auto-merge and before main can advance.

| Check context | Workflow | Purpose |
|---------------|----------|---------|
| `PHPUnit unit (PHP 8.4)` | tests.yml | PHP unit suite |
| `PHPUnit integration (PHP 8.4)` | tests.yml | DB/service integration suite |
| `Test-touch gate` | tests.yml | src changes include matching tests |
| `Python CI helper tests` | tests.yml | Python helper scripts stay runnable |
| `Playwright (fast ? Chromium)` | e2e.yml | Fast browser smoke path |
| `Multi-agent guard` | multi-agent-guard.yml | Branch/claim checks and main push guard |
| `trufflehog` | secret-scan.yml | Secret scan |
| `Semgrep (PR)` | semgrep.yml | PHP SAST scan |
| `Analyze JavaScript` | codeql.yml | CodeQL analysis |
| `Checkov IaC` | checkov.yml | Infrastructure scan |

Nightly-only jobs (mutation, perf, Dependency Review, CodeRabbit Review) remain
advisory unless they are promoted into `.github/auto-merge-required.txt` and the
ruleset.

To add a new required check: add its exact name (as shown in GitHub UI) to
`.github/auto-merge-required.txt`, then update the `main-protection` ruleset.

## Nightly Schedule (UTC)

| Time | Workflow | Purpose |
|------|----------|---------|
| 02:00 | `smoke-staging.yml` | Playwright fast smoke against live staging |
| 04:30 | `nightly-self-heal.yml` | Cancel stuck runs, fix main drift, prune quarantine |
| 12:30 | `performance.yml` | k6 baseline (5→20→50 VU) |
| 13:00 | `mutation-testing.yml` | Infection PHP mutation testing |
| 14:00 | `security-shannon.yml` | Shannon AI pen test against staging |
| 15:00 | `snyk.yml` | Snyk PHP dependency CVE scan |
| 16:00 | `security-zap.yml` | OWASP ZAP authenticated baseline scan |
| 16:30 | `e2e-full-nightly.yml` | Playwright full matrix against staging |
| Sun 20:00 | `performance-load.yml` | k6 50-VU peak load (weekly) |
| 17:00 | `nightly-triage.yml` | Aggregate, open issues, ntfy on NEW failures |
| 17:30 | `morning-summary.yml` | Single ntfy (SILENT if all green) |

---

## Auto-Merge

**Triggers:** `pull_request_review: submitted` or `check_run: completed`.

**Conditions:**
1. All checks in `.github/auto-merge-required.txt` have passed.
2. At least one APPROVED review on the **current HEAD commit** (stale-review guard).
3. No `CHANGES_REQUESTED` reviews present.
4. PR is not a draft.

**Dependabot:** auto-approved and merged when required checks pass.

**Audit trail:** Every run posts a PR comment explaining why it did or didn't merge.

**Override:** `gh workflow run auto-merge.yml -f pr_number=<N>`

---

## CodeRabbit Autofix

**Trigger:** CodeRabbit `CHANGES_REQUESTED` on a non-fork PR.

**Flow:**
1. Claude Code reads the review body + inline comments for the current review only.
2. Applies fixes (≤15 turns, 10-min timeout).
3. If no changes: retries once.
4. On exhaustion: labels `autofix-failed` + ntfy HIGH.
5. If fixes applied: commits, pushes, `@coderabbitai review`.

**Concurrency:** Newer review on same PR cancels in-flight autofix.

**`autofix-failed` label:** Read the workflow logs. Fix manually. Remove label to unblock.

---

## Test-Touch Rule

Every modified `src/**/*.php` must have a corresponding test change. Mapping:

| Source | Required test |
|--------|---------------|
| `src/Controllers/FooController.php` | `tests/Unit/Controllers/FooControllerTest.php` |
| `src/Models/Foo.php` | `tests/Unit/Models/FooTest.php` |
| `src/Services/FooService.php` | `tests/Unit/Services/FooServiceTest.php` |
| `src/Middleware/FooMiddleware.php` | `tests/Unit/Middleware/FooMiddlewareTest.php` |
| `src/Security/Foo.php` | `tests/Unit/Security/FooTest.php` |

Exempted directories: `src/Config/`, `src/Services/Prompts/`.

**Override:** Add `no-test-required` label + `/no-test-required: <reason>` comment.

---

## Self-Heal

### PR-time (`self-heal.yml`)

Runs before unit/integration on every PR.

| Issue | Action |
|-------|--------|
| `composer.lock` drift | `composer update --lock` → commit |
| Stale `phpstan-baseline.neon` | regenerate → commit |
| Branch behind main | rebase onto main |
| Hand-written code conflict | abort, post comment, let tests show real error |

Retry budget: 2. Exhaustion → `self-heal-failed` label + ntfy HIGH.

### Nightly (`nightly-self-heal.yml`, 04:30 UTC)

| Issue | Action |
|-------|--------|
| Stuck GHA runs (>2h in_progress) | cancel via API |
| Lockfile/baseline drift on main | open a self-heal PR (never direct push) |
| Resolved `ci-nightly` issues | auto-close |
| Playwright quarantine entries >30d | prune |

---

## Deploy & Rollback

Automatic deploys wait for the same commit SHA to pass the required main
workflows before Railway receives a deployment: Tests, E2E Tests, secret scan,
Semgrep, CodeQL, Checkov, and the multi-agent guard. Manual dispatch remains
available for controlled overrides.

**Trigger:** Tests pass on main, or `workflow_dispatch` with `confirm: deploy`.

**Flow:**
1. Gate checks `DEPLOY_PAUSED` variable.
2. `railway up --detach --service "StratFlow App"`.
3. Polls `/healthz` up to 2 minutes.
4. Success → records `LAST_GOOD_DEPLOY_ID` → silent.
5. Failure → `railway deployment redeploy <LAST_GOOD_DEPLOY_ID>`.
   - Rollback OK → ntfy HIGH.
   - Rollback failed → ntfy CRITICAL + `DEPLOY_PAUSED=1`.

**To resume after pause:**

```bash
gh workflow run deploy.yml -f confirm=deploy -f clear_pause=yes
```

**RAILWAY_SERVICE var:** Defaults to `StratFlow App`. Override via `vars.RAILWAY_SERVICE`.

---

## Security Baselines

Scans fail only on findings **absent from** the committed baselines.

| Scan | Baseline file |
|------|--------------|
| ZAP | `tests/security/baseline-zap.json` |
| Shannon | `tests/security/baseline-shannon.json` |
| Snyk | `tests/security/baseline-snyk.json` |

**Accept a new finding:**

```bash
python3 scripts/ci/update_security_baseline.py zap   # or shannon / snyk
git add tests/security/baseline-zap.json
git commit -m "chore: accept ZAP finding — <reason>"
# Open a PR; baseline changes require human review
```

---

## Status Artifact Contract

Every nightly workflow emits `nightly-status.json` (artifact `nightly-status-<job>`, 7-day retention):

```json
{
  "job":          "tests",
  "status":       "pass | fail | warn",
  "metric":       { "coverage": 68.4 },
  "findings_url": "https://github.com/.../actions/runs/12345",
  "run_id":       "12345"
}
```

See `.github/workflows/_status-schema.md` for the full contract.

---

## Notification Budget

| Priority | Event |
|----------|-------|
| CRITICAL | Deploy rollback failed; pipeline paused |
| HIGH | Deploy rolled back; self-heal budget exhausted; `autofix-failed`; new security HIGH |
| DEFAULT | Morning summary with failures/warnings |
| MIN | Successful deploys |
| **SILENT** | All nightly green; routine green CI; recurring (already-ticketed) failures |

To add a new notification: update this table in a PR and justify why human attention is needed.

---

## Multi-Agent Safety

### Branch naming (advisory)
`agent/<id>/<topic>`, `feat/`, `fix/`, `chore/`, `docs/`, `refactor/`, `test/`, `security-`, `hotfix/`, `dependabot/`

### Work-claim ledger

```bash
python3 scripts/agent-claim.py claim --agent "claude" --branch "agent/abc/feat" \
  --patterns "src/Controllers/**"
python3 scripts/agent-claim.py list
python3 scripts/agent-claim.py release --branch "agent/abc/feat"
python3 scripts/agent-claim.py prune
```

Ledger at `.github/agent-claims.json`. Claims expire after 4h. Advisory only.

### Lockfile merge drivers
`.gitattributes` declares `merge=ours` for `composer.lock`, `package-lock.json`, `phpstan-baseline.neon`. Rebase conflicts on these files self-resolve. `self-heal.yml` regenerates correct content.

### Session hand-off
`pr-closed-handoff.yml` writes `docs/agent-handoffs/<pr>.md` on PR close. Extracts decisions from commit trailers. Releases claims.

---

## Flake Quarantine

Playwright `--retries=1` auto-retries each failing test once. Persistent flakes:
- Logged to `tests/Playwright/quarantine.jsonl`
- ≥3 flakes in 7 days → GitHub issue with `flaky-test` label

```bash
python3 scripts/ci/manage_flake_quarantine.py list \ list \
  --quarantine tests/Playwright/quarantine.jsonl
```

---

## Morning Audit (local)

```bash
python3 scripts/ci/morning_audit.py
```

Fetches the latest `triage-report.json` from `nightly-triage` and prints a terminal summary. Runs automatically via Task Scheduler at 06:00 NZT.

---

## Override Reference

| Situation | Override |
|-----------|---------|
| PR with no test change | `no-test-required` label + `/no-test-required: <reason>` comment |
| Autofix failed | Fix manually, remove `autofix-failed` label |
| Self-heal failed | Fix drift manually, remove `self-heal-failed` label |
| Deploy paused | `gh workflow run deploy.yml -f confirm=deploy -f clear_pause=yes` |
| Force-merge a PR | `gh workflow run auto-merge.yml -f pr_number=<N>` |
| Accept security finding | `python3 scripts/ci/update_security_baseline.py <scan>` → PR |
| Run nightly job on demand | `gh workflow run <workflow>.yml` (all support `workflow_dispatch`) |
