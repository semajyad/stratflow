# Security Learnings

Structured knowledge base of security scan failures, false positives, and patterns established
for the StratFlow pipeline.

## Purpose

Claude reads this file at session start to avoid repeating known security missteps. Every
non-trivial security finding, false positive resolution, or scanning configuration change
should be recorded here. Use `scripts/ci/record_learning.py` to append entries.

## Format

```
## [YYYY-MM-DD] Category — Short title
**Symptom:** What failed / what was observed
**Root cause:** Why it happened
**Fix applied:** What was done to resolve it
**Prevention:** What now prevents recurrence
**Follow-up:** Any remaining work or improvements triggered
**PR/Commit:** #N or SHA reference
```

Categories: `ci`, `security`, `tests`, `quality`

---

## [2026-04-14] security — Secret scanning false positives on test fixtures

**Symptom:** TruffleHog flagged multiple findings in the secret-scan workflow, blocking PRs.
All findings were fake API keys embedded in test fixtures (e.g., `tests/fixtures/api_keys.json`
containing `FAKE_KEY_FOR_TESTING_ONLY`).

**Root cause:** TruffleHog was configured to scan the full git history (`--since-commit HEAD~50`)
rather than just the current HEAD. Historical test fixture commits triggered pattern matches on
strings that were never real secrets.

**Fix applied:** Changed TruffleHog invocation from history scan to HEAD-only scan:
```yaml
# Before
trufflehog git file://. --since-commit HEAD~50 --only-verified

# After
trufflehog filesystem . --only-verified
```
Added `--exclude-paths .trufflehog-ignore` with test fixture directories listed.

**Prevention:** `.trufflehog-ignore` file committed to repo root listing paths excluded from scanning.
Pattern: test fixtures containing fake keys must be listed in `.trufflehog-ignore`. Real secrets
must never appear in test fixtures regardless — use environment variable references instead.

**Follow-up:** Audit all test fixtures and replace any hardcoded fake-looking keys with clearly
labelled placeholder strings (`TEST_PLACEHOLDER_NOT_A_REAL_KEY`).

**PR/Commit:** #10

---

## [2026-04-14] security — Baseline pattern for security scan tools

**Symptom:** ZAP, Shannon, and Snyk scans were failing the CI gate on every run because there
was no distinction between "new findings" and "pre-existing accepted risk". Every pre-existing
finding triggered a block.

**Root cause:** No baseline file existed. Security tools defaulted to "fail on any finding" mode.

**Fix applied:** Established baseline files for each tool:
- `tests/security/baseline-zap.json` — ZAP accepted findings
- `tests/security/baseline-shannon.json` — Shannon entropy scan accepted findings
- `tests/security/baseline-snyk.json` — Snyk accepted vulnerabilities

Each tool is now run in "new findings only" mode, diffing against its baseline:
```bash
# ZAP example
zap-cli report --output zap-report.json
python scripts/ci/diff_security_baseline.py --tool zap \
  --baseline tests/security/baseline-zap.json \
  --current zap-report.json
```

The diff script exits 0 if all current findings are in the baseline, non-zero if any new
finding appears.

**Prevention:** Baseline files are committed and reviewed in PRs. A new finding that is accepted
risk requires a conscious PR to update the baseline with a justification comment. `update_security_baseline.py`
handles the update with a required `--reason` argument.

**Follow-up:** Add a periodic workflow to review whether baseline items can be resolved (quarterly).

**PR/Commit:** #11

---

## [2026-04-15] security — CSRF exemption pattern for webhooks

**Symptom:** Stripe and CodeRabbit webhook endpoints were returning 419 CSRF token mismatch errors
in production after CSRF middleware was added globally.

**Root cause:** Incoming webhooks are server-to-server requests. They cannot include a CSRF token
because they originate from third-party services, not from a browser session.

**Fix applied:** Added explicit CSRF exemptions for webhook routes in the middleware config.
Pattern documented in `docs/SECURE_CODING.md`:
- Webhooks that receive POST from third parties must be listed in `$csrf_exempt_routes`.
- Every webhook exemption must validate the request with a signature instead (e.g., `X-Stripe-Signature`, `X-CodeRabbit-Signature`).
- State-changing routes reachable from the browser must never be exempt.

**Prevention:** `SECURE_CODING.md` now contains the CSRF decision matrix. Code review checklist
includes: "Does this POST route need CSRF? Is it a webhook with signature validation instead?"
The PHP linter flags any `csrf_exempt` without a co-located signature verification call.

**Follow-up:** Audit all existing POST routes for CSRF coverage — generate a coverage report
with `scripts/ci/csrf_audit.php`.

**PR/Commit:** #12
