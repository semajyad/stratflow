# StratFlow — CI/CD, Security & Quality Pipeline

_Last updated: 2026-04-15 (rev 2)_

---

## 1. PR → Merge flow

```text
PR opened
  │
  ├─ multi-agent-guard      branch naming, claim advisory (non-blocking)
  ├─ self-heal              lockfile drift · stale PHPStan baseline · rebase onto main
  │
  ├─ FAST PATH  ── all required for auto-merge, target p95 < 5 min ──────────────
  │   PHPUnit unit (PHP 8.4)          ~30 s   no DB
  │   PHPUnit integration (PHP 8.4)   ~3 m    MySQL
  │   Playwright fast (Chromium)      ~10 m   MySQL + PHP dev server (timeout: 15 min)
  │   PHPStan · PHPCS PSR-12          ~1 m    static only
  │   Destructive migration gate      instant  DROP/RENAME ops need safe-migration-reviewed label
  │   Codecov patch coverage          ≥ 80% on new lines (enforced via codecov.yml)
  │
  ├─ ADVISORY (never blocks merge) ────────────────────────────────────────────
  │   TruffleHog secret scan          on every push + PR
  │   Semgrep PHP (changed files)     ~60 s   p/php + p/owasp-top-ten
  │   k6 smoke (30 s, 1 VU)          p95 < 800 ms  nightly baseline / advisory
  │   Hadolint                        ~5 s    Dockerfile lint
  │   Checkov IaC                     ~30 s   when Docker/GH Actions files change
  │   Syft + Grype SBOM               ~2 m    when composer.lock changes
  │   Lighthouse CI                   ~5 m    when src/templates change
  │   Security notes check            instant  advisory comment if section missing
  │   CodeRabbit review + autofix     AI code review, auto-fixes findings
  │
  └─ auto-merge (gh pr merge --auto; branch ruleset enforces required checks)
        │
        ▼
       main ──► deploy.yml ──► Railway up ──► /healthz poll (2 min)
                                    │
                          pass ─── record LAST_GOOD_SHA · silent ntfy
                          fail ─── auto-rollback → ntfy HIGH
                                   rollback fail → ntfy CRITICAL + DEPLOY_PAUSED

```

**Required checks** (enforced by `main-protection` branch ruleset, ruleset ID 15091891):  
`PHPUnit unit (PHP 8.4)` · `PHPUnit integration (PHP 8.4)` · `Playwright (fast — Chromium)`

**Auto-merge mechanics**: `auto-merge.yml` calls `gh pr merge --auto --squash` on approval.
GitHub merges atomically once required checks pass in the `merge_group` context.
CHANGES_REQUESTED disables auto-merge on that PR until re-approved.

---

## 2. Nightly chain (UTC)

| Time      | Job               | Purpose |
|-----------|-------------------|---------|
| 04:30     | nightly-self-heal | Cancel stuck runs · fix main drift · prune quarantine |
| 12:00     | smoke-staging     | Playwright fast smoke vs live Railway staging |
| 12:30     | performance       | k6 baseline 5→20→50 VU vs staging |
| 13:00     | mutation-testing  | Infection PHP MSI score |
| 13:30     | semgrep           | Full `src/` scan, SARIF → Security tab |
| 14:00     | security-shannon  | Shannon AI pen-test vs staging |
| 15:00     | snyk              | PHP dependency CVEs (Snyk advisory DB) |
| 16:00     | security-zap      | OWASP ZAP authenticated baseline scan vs staging |
| 16:30     | e2e-full-nightly  | Staging DB re-seeded → Playwright full matrix (all browsers) |
| 17:00     | nightly-triage    | Aggregate results · open GitHub issues · dedup |
| 17:30     | morning-summary   | Single ntfy digest — **silent if all green** |
| Sun 20:00 | performance-load  | k6 50-VU peak load (weekly) |

Seven jobs emit `nightly-status.json` artifacts consumed by `nightly-triage.yml`:
`nightly-status-smoke`, `nightly-status-shannon`, `nightly-status-snyk`,
`nightly-status-perf`, `nightly-status-e2e`, `nightly-status-mutation`,
`nightly-status-perf-load`. `nightly-self-heal` and `morning-summary` emit no
artifacts; `nightly-triage` emits `triage-report`. Missing artifacts are handled
gracefully by the triage workflow — jobs that did not run are not treated as failures.

> **Staging data hygiene**: Shannon AI (14:00) and ZAP (16:00) are authenticated and mutate
> staging state. The e2e-full-nightly job re-seeds the staging database at 16:30 via
> `STAGING_SEED_URL` before Playwright runs to prevent state bleed.

---

## 3. Security toolchain

### Static & code analysis
| Tool | What it catches | When |
|------|----------------|------|
| CodeQL | Deep inter-procedural SAST — injection, auth bypass, data flow | PR + push |
| Semgrep PHP | Fast SAST — OWASP Top 10, PHP-specific patterns | PR (changed files) + nightly full |
| PHPStan | Type errors, undefined methods, unreachable code | PR |
| Hadolint | Dockerfile anti-patterns (root user, unpinned tags, ADD vs COPY) | Planned |
| Checkov | IaC misconfig — docker-compose, Dockerfile, GH Actions workflows | Planned |

### Dependency & supply chain
| Tool | What it catches | When |
|------|----------------|------|
| Snyk | CVEs in Composer deps (Snyk advisory DB) | PR + nightly |
| Composer audit | PHP-native CVE check | PR (unit job) |
| OWASP Dep-Check | CVEs from NVD directly — catches pre-Snyk-sync findings (~24 h lag) | Planned |
| Syft + Grype | Full SBOM (CycloneDX) + CVE scan across NVD/GH Advisories/OSV | Planned |
| Dependabot | Automated PRs for outdated dependencies | Daily |
| TruffleHog | Leaked secrets in code and git history | PR + push |
| SLSA Level 3 | Signed provenance on release artifacts | Release |
| SHA-pinned actions | All workflows pin `uses:` to full commit SHAs | Always |

### Dynamic & runtime
| Tool | What it catches | When |
|------|----------------|------|
| OWASP ZAP | Authenticated DAST — XSS, injection, misconfigs on running app | Nightly |
| Shannon AI | AI-driven pen-test — auth flaws, business logic, API abuse | Nightly |
| Lighthouse CI | WCAG 2.1 accessibility violations, Core Web Vitals | Planned |

### Baselines
Accepted findings stored in `tests/security/baseline-zap.json`,
`tests/security/baseline-snyk.json`, and `tests/security/baseline-shannon.json`.
Update via: `python3 scripts/ci/update_security_baseline.py <scan>` → PR with human review.

---

## 4. Quality gates

| Gate | Threshold | Blocks merge? |
|------|-----------|--------------|
| PHPUnit unit coverage | ≥ 12% line coverage (CI check, not hard-gated in phpunit.xml) | Advisory |
| Codecov patch coverage | ≥ 80% on new lines (enforced via `codecov.yml`) | Yes |
| Destructive migration gate | `DROP`/`RENAME` ops require `safe-migration-reviewed` label | Yes |
| Infection PHP MSI | Tracked, not gated | No (advisory) |
| Playwright flake quarantine | 3 flakes in 7 days → skip + open issue | Auto-managed |
| Security notes | Section present on security-touching PRs | No (advisory comment) |

---

## 5. Database migration safety

Auto-rollback undoes the **code** but not the **schema**. A destructive migration
without backward compatibility → production outage on rollback.

**Rule: every migration must be compatible with the previous deploy (expand/contract).**

| Phase | Action | Rollback safe? |
|-------|--------|----------------|
| Expand | Add nullable column / new table | Yes — old code ignores it |
| Migrate | Backfill + deploy code using new column | Yes |
| Contract | Remove old column in a *later* PR once LAST\_GOOD\_SHA is the new code | Yes |

**Forbidden in a single deploy**: `DROP COLUMN`, `RENAME COLUMN`, `RENAME TABLE`, `TRUNCATE`.

`scripts/ci/check_destructive_migrations.py` blocks PRs containing these unless
the `safe-migration-reviewed` label is present (manual confirmation of backward compatibility).

After a deliberate destructive migration merges, update `LAST_GOOD_SHA` immediately:

```bash
gh variable set LAST_GOOD_SHA --body "<new SHA>" --repo semajyad/stratflow
```

---

## 6. Performance testing

| Test | Config | Threshold | When |
|------|--------|-----------|------|
| k6 PR smoke | 1 VU, 30 s, public paths | p95 < 2 s, errors < 5% | Every PR |
| k6 nightly baseline | 5→20→50 VU, 1 min each | p95 < 800 ms, errors < 1% | Nightly |
| k6 weekly peak | 50 VU, 40 s | p95 < 800 ms | Sunday |

Results in `tests/performance/history/YYYY-MM-DD.json`.

---

## 7. Self-healing behaviours

| Failure | Automatic response | Retry budget |
|---------|--------------------|-------------|
| `composer.lock` drift | Regenerate + commit to PR branch | 2 |
| Stale `phpstan-baseline.neon` | Regenerate + commit | 2 |
| Branch behind main | Rebase onto main | 2 |
| Hand-written merge conflict | Stop, label `self-heal-failed`, ntfy | — |
| Stuck GHA runs (> 2 h) | Cancel via API | Nightly |
| Flaky Playwright test | Auto-retry once; quarantine after 3 flakes in 7 d | Per run |
| Bad deploy | Auto-rollback to `LAST_GOOD_SHA` | 1 |
| CodeRabbit CHANGES_REQUESTED | Claude autofix (≤ 15 turns); retry once | 2 |

---

## 8. Notification budget

| ntfy priority | Fires on |
|---------------|---------|
| `urgent` | Deploy rollback failed · pipeline paused (`DEPLOY_PAUSED` set) |
| `high` | Deploy rolled back to last-good SHA · any failure in morning summary |
| `default` | Morning summary when warnings only (no failures) |
| `min` | Successful deploy (silent low-priority confirmation) |
| **silent (no ntfy)** | All nightly jobs green · recurring already-ticketed failures |

Topic: `stratflow-ci` on local ntfy instance.

---

## 9. Overrides

| Situation | Override |
|-----------|---------|
| Destructive migration | `safe-migration-reviewed` label; update `LAST_GOOD_SHA` post-merge |
| Accept a security finding | `python3 scripts/ci/update_security_baseline.py <scan>` → PR |
| Deploy paused | `gh workflow run deploy.yml -f confirm=deploy -f clear_pause=yes` |
| Force-enable auto-merge | `gh workflow run auto-merge.yml -f pr_number=<N>` |
| Autofix failed | Fix manually, remove `autofix-failed` label |
| Run any nightly on demand | `gh workflow run <workflow>.yml` |
