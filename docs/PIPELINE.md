# StratFlow — CI/CD, Security & Quality Pipeline

_Last updated: 2026-04-15_

---

## 1. PR → Merge flow

```
PR opened
  │
  ├─ multi-agent-guard      branch naming, claim advisory (non-blocking)
  ├─ self-heal              lockfile drift · stale PHPStan baseline · rebase onto main
  │
  ├─ FAST PATH  ── all required for auto-merge, target p95 < 5 min ──────────────
  │   PHPUnit unit (PHP 8.4)          ~45 s   no DB
  │   PHPUnit integration (PHP 8.4)   ~2.5 m  MySQL
  │   Playwright fast (Chromium)      ~5 m    MySQL + PHP dev server
  │   PHPStan · PHPCS PSR-12          ~1 m    static only
  │   Test-touch gate                 instant changed src/ must have test diff
  │   Codecov patch coverage          ≥ 80% on new code
  │   k6 smoke (30 s, 1 VU)          p95 < 2 s  login · pricing · dashboard
  │
  ├─ ADVISORY (never blocks merge) ────────────────────────────────────────────
  │   TruffleHog secret scan          on every push + PR
  │   Semgrep PHP (changed files)     ~60 s   p/php + p/owasp-top-ten
  │   Hadolint                        ~5 s    Dockerfile lint
  │   Checkov IaC                     ~30 s   when Docker/GH Actions files change
  │   Syft + Grype SBOM               ~2 m    when composer.lock changes
  │   Lighthouse CI                   ~5 m    when src/templates change
  │   Security notes check            instant advisory comment if section missing
  │   CodeRabbit review + autofix     AI code review, auto-fixes findings
  │
  └─ auto-merge (oldest approved PR first, rebases next PR onto new main)
        │
        ▼
       main ──► deploy.yml ──► Railway up ──► /healthz poll (2 min)
                                    │
                          pass ─── record LAST_GOOD_DEPLOY_ID · silent ntfy
                          fail ─── auto-rollback → ntfy HIGH
                                   rollback fail → ntfy CRITICAL + DEPLOY_PAUSED
```

**Required checks** (`.github/auto-merge-required.txt`):
`PHPUnit unit (PHP 8.4)` · `PHPUnit integration (PHP 8.4)` · `Playwright (fast — Chromium)`

---

## 2. Nightly chain (UTC)

| Time  | Job | Purpose |
|-------|-----|---------|
| 04:30 | nightly-self-heal | Cancel stuck runs · fix main drift · prune quarantine |
| 12:00 | smoke-staging | Playwright fast smoke vs live Railway staging |
| 12:30 | performance | k6 baseline 5→20→50 VU vs staging |
| 13:00 | mutation-testing | Infection PHP MSI score |
| 13:30 | semgrep | Full `src/` scan, SARIF → Security tab |
| 14:00 | security-shannon | Shannon AI pen-test vs staging |
| 14:30 | sbom | Syft SBOM + Grype CVE scan (NVD + GitHub Advisories + OSV) |
| 15:00 | snyk | PHP dependency CVEs (Snyk advisory DB) |
| 15:30 | dependency-check | OWASP Dep-Check vs NVD (catches CVEs before Snyk syncs) |
| 16:00 | security-zap | OWASP ZAP authenticated baseline scan vs staging |
| 16:30 | e2e-full-nightly | Playwright full matrix (all browsers) vs staging |
| 17:00 | nightly-triage | Aggregate results · open GitHub issues · dedup |
| 17:30 | morning-summary | Single ntfy digest — **silent if all green** |
| Sun 20:00 | performance-load | k6 50-VU peak load (weekly) |

All nightly jobs emit `nightly-status.json` artifacts consumed by triage.

---

## 3. Security toolchain

### Static & code analysis
| Tool | What it catches | When |
|------|----------------|------|
| CodeQL | Deep inter-procedural SAST — injection, auth bypass, data flow | PR + push |
| Semgrep PHP | Fast SAST — OWASP Top 10, PHP-specific patterns | PR (changed files) + nightly full |
| PHPStan | Type errors, undefined methods, unreachable code | PR |
| Hadolint | Dockerfile anti-patterns (root user, unpinned tags, ADD vs COPY) | PR |
| Checkov | IaC misconfig — docker-compose, Dockerfile, GH Actions workflows | PR + weekly |

### Dependency & supply chain
| Tool | What it catches | When |
|------|----------------|------|
| Snyk | CVEs in Composer deps (Snyk advisory DB) | PR + nightly |
| Composer audit | PHP-native CVE check | PR (unit job) |
| OWASP Dep-Check | CVEs from NVD directly — catches pre-Snyk-sync findings | Nightly |
| Syft + Grype | Full SBOM (CycloneDX) + CVE scan across NVD/GH Advisories/OSV | PR (lock changes) + nightly |
| Dependabot | Automated PRs for outdated dependencies | Daily |
| TruffleHog | Leaked secrets in code and git history | PR + push |
| SLSA Level 3 | Signed provenance on release artifacts | Release |
| SHA-pinned actions | All 25 workflows pin `uses:` to commit SHAs | Always |

### Dynamic & runtime
| Tool | What it catches | When |
|------|----------------|------|
| OWASP ZAP | Authenticated DAST — XSS, injection, misconfigs on running app | Nightly |
| Shannon AI | AI-driven pen-test — auth flaws, business logic, API abuse | Nightly |
| Lighthouse CI | WCAG 2.1 accessibility violations, Core Web Vitals | PR (src changes) |

### Baselines
Accepted findings stored in `tests/security/baseline-{zap,shannon,snyk,grype}.json`.  
Update via: `python3 scripts/ci/update_security_baseline.py <scan>` → PR with human review.

---

## 4. Quality gates

| Gate | Threshold | Blocks merge? |
|------|-----------|--------------|
| PHPUnit unit coverage | ≥ 12% (unit suite only) | Yes |
| Codecov patch coverage | ≥ 80% on new lines | Yes |
| Test-touch rule | Every changed `src/*.php` needs a test diff | Yes (override: `no-test-required` label) |
| Infection PHP MSI | Tracked, not gated | No (advisory) |
| Playwright flake quarantine | 3 flakes in 7 days → skip + open issue | Auto-managed |
| Security notes | Section present on security-touching PRs | No (advisory comment) |

---

## 5. Performance testing

| Test | Config | Threshold | When |
|------|--------|-----------|------|
| k6 PR smoke | 1 VU, 30 s, public paths | p95 < 2 s, errors < 5% | Every PR |
| k6 nightly baseline | 5→20→50 VU, 1 min each | p95 < 800 ms, errors < 1% | Nightly |
| k6 weekly peak | 50 VU, 40 s | p95 < 800 ms | Sunday |

Results in `tests/performance/history/YYYY-MM-DD.json`.

---

## 6. Self-healing behaviours

| Failure | Automatic response | Retry budget |
|---------|--------------------|-------------|
| `composer.lock` drift | Regenerate + commit to PR branch | 2 |
| Stale `phpstan-baseline.neon` | Regenerate + commit | 2 |
| Branch behind main | Rebase onto main | 2 |
| Hand-written merge conflict | Stop, label `self-heal-failed`, ntfy | — |
| Stuck GHA runs (> 2 h) | Cancel via API | Nightly |
| Flaky Playwright test | Auto-retry once; quarantine after 3 flakes in 7 d | Per run |
| Bad deploy | Auto-rollback to `LAST_GOOD_DEPLOY_ID` | 1 |
| CodeRabbit CHANGES_REQUESTED | Claude autofix (≤ 15 turns); retry once | 2 |

---

## 7. Notification budget

| Priority | Fires on |
|----------|---------|
| CRITICAL | Deploy rollback failed · pipeline paused |
| HIGH | Deploy rolled back · self-heal exhausted · autofix-failed · new security HIGH CVE |
| DEFAULT | Morning summary when failures exist |
| **SILENT** | All nightly green · successful deploys · recurring (already-ticketed) failures |

Topic: `stratflow-ci` on local ntfy instance.

---

## 8. Overrides

| Situation | Override |
|-----------|---------|
| PR with no test change | `no-test-required` label + `/no-test-required: <reason>` comment |
| Accept a security finding | `python3 scripts/ci/update_security_baseline.py <scan>` → PR |
| Deploy paused | `gh workflow run deploy.yml -f confirm=deploy -f clear_pause=yes` |
| Force-merge a PR | `gh workflow run auto-merge.yml -f pr_number=<N>` |
| Autofix failed | Fix manually, remove `autofix-failed` label |
| Run any nightly on demand | `gh workflow run <workflow>.yml` |
