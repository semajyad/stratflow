# CI/CD Orchestration — StratFlow

## Overview

StratFlow has a two-tier CI pipeline: fast per-PR gates that must pass before
merge, and a nightly full regression that runs overnight and is reviewed every
morning.

---

## Per-PR Gates (must pass to merge)

| Check | Workflow | Timing |
|---|---|---|
| PHPUnit unit suite (PHP 8.3 + 8.4) | `tests.yml / unit` | ~30s |
| PHPUnit integration suite (PHP 8.3 + 8.4) | `tests.yml / integration` | ~2min |
| Test-touch enforcement | `tests.yml / test-touch-check` | ~5s |
| Playwright fast (Chromium) | `e2e.yml / e2e-fast` | ~10min |
| Coverage delta (Codecov) | `tests.yml → Codecov` | async |
| PHP syntax lint, PHPStan, PHPCS | `tests.yml / unit` | included above |
| Composer security audit | `tests.yml / unit` | included above |
| Dependency review | `dependency-review.yml` | on PR |
| Secret scan | `secret-scan.yml` | on PR |

### Test-touch rule

Every modified `src/**/*.php` file in a PR must include a corresponding change
under `tests/`. The mapping:

| Source | Expected test |
|---|---|
| `src/Controllers/FooController.php` | `tests/Unit/Controllers/FooControllerTest.php` |
| `src/Models/Foo.php` | `tests/Unit/Models/FooTest.php` |
| `src/Services/FooService.php` | `tests/Unit/Services/FooServiceTest.php` |
| `src/Middleware/FooMiddleware.php` | `tests/Unit/Middleware/FooMiddlewareTest.php` |

**Exemption:** Add the `no-test-required` label to the PR and include a
comment `/no-test-required: <reason>` (e.g. for pure config changes,
documentation updates, or refactors with no behaviour change).

---

## Nightly Schedule (UTC)

| Time | Workflow | Purpose |
|---|---|---|
| 02:00 | `smoke-staging.yml` | Playwright fast smoke against live staging |
| 12:30 | `performance.yml` | k6 baseline (5→20→50 VU) against staging |
| 13:00 | `mutation-testing.yml` | Infection PHP mutation testing (full src/) |
| 14:00 | `security-shannon.yml` | Shannon AI pen test against staging |
| 15:00 | `snyk.yml` | Snyk PHP dependency CVE scan |
| 16:00 | `security-zap.yml` | OWASP ZAP authenticated baseline scan |
| 16:30 | `e2e-full-nightly.yml` | Playwright full matrix against staging |
| Sun 20:00 | `performance-load.yml` | k6 50-VU peak load (weekly) |
| 05:00 | `nightly-triage.yml` | Aggregate results, open GitHub issues for failures |
| 05:30 | `morning-summary.yml` | Single ntfy digest to James |

---

## Morning Triage

`nightly-triage.yml` downloads every `nightly-status-<job>` artifact from the
past 12h, then:

1. Classifies each job as `pass`, `warn`, or `fail`.
2. Marks any job with a **missing** artifact as `fail` (silently skipped jobs
   are treated as failures).
3. For each `fail`: opens a GitHub issue tagged `ci-nightly, auto-triaged`
   with a link to the failing run. Issues are deduped by `SHA-256(job+run_id)`
   so re-runs don't spam.
4. Appends a row to `docs/ci-nightly-history.md`.
5. Sends an ntfy push if any failures were found.

`morning-summary.yml` (05:30 UTC) sends a single consolidated ntfy digest
regardless of pass/fail — the one message James sees first thing in the
morning (≈ 17:30 NZT).

### Local morning audit

Run on the dev box to get the same summary in the terminal:

```bash
python scripts/ci/morning_audit.py
```

Requires `NTFY_URL`, `NTFY_TOPIC`, and a GitHub token in `.env` or env vars.

---

## Status Artifact Contract

Every nightly workflow emits a `nightly-status.json` artifact named
`nightly-status-<job>` with 7-day retention. Schema:

```json
{
  "job":          "string",
  "status":       "pass | fail | warn",
  "metric":       "object | null",
  "findings_url": "string | null",
  "run_id":       "string"
}
```

See `.github/workflows/_status-schema.md` for the full contract and examples.

---

## Security Baseline

Security scans emit findings that persist in the codebase as
`tests/security/baseline-{zap,snyk,shannon}.json`. The nightly-triage
workflow treats a finding as **new** only if it's absent from the baseline.

To accept new known-safe findings:

```bash
python scripts/ci/update_security_baseline.py zap --reason "false positive on error page"
git add tests/security/baseline-zap.json
git commit -m "chore: accept ZAP baseline — false positive on error page"
```

---

## Notification Surface

All notifications go to the `stratflow-ci` ntfy topic on the Sentinel ntfy
server. Secrets required in GitHub repo settings:

| Secret | Value |
|---|---|
| `NTFY_URL` | `http://<sentinel-host>:8090` |
| `NTFY_TOPIC` | `stratflow-ci` |

Priority levels used: `low` (all-pass), `default` (warn), `high` (test/perf
fail), `urgent` (security scan fail or staging down).

---

## Workflows NOT in scope for branch protection

These run nightly only and do not block PRs. Failures open GitHub issues
via `nightly-triage.yml`:

- `security-zap.yml`
- `security-shannon.yml`
- `snyk.yml`
- `mutation-testing.yml`
- `performance.yml`
- `performance-load.yml`
- `smoke-staging.yml`
- `e2e-full-nightly.yml`
