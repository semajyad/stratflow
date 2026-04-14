# Test Learnings

Structured knowledge base of test suite failures, flakiness patterns, and structural improvements
to the StratFlow testing pipeline.

## Purpose

Claude reads this file at session start to avoid repeating known test infrastructure mistakes.
Every non-trivial test failure, flake pattern, or test architecture decision should be recorded here.
Use `scripts/ci/record_learning.py` to append entries.

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

## [2026-04-14] tests — Playwright flake isolation blocking PRs

**Symptom:** Flaky Playwright E2E tests were blocking PR merges indefinitely. When a flaky test
failed, the PR gate turned red and required a manual re-run. On days with multiple flaky tests,
PRs could sit blocked for hours.

**Root cause:** No retry mechanism existed. Any single test failure in the E2E suite was fatal.
There was also no tracking of which tests were flaky, so the same tests were repeatedly causing
delays without being quarantined.

**Fix applied:**
1. Added `--retries=1` to the Playwright run command — one automatic retry before reporting failure.
2. Created `tests/e2e/quarantine.jsonl` — a newline-delimited JSON file tracking flaky test instances:
   ```json
   {"test": "LoginFlow > redirects after login", "date": "2026-04-14", "run_id": "12345"}
   ```
3. Added `scripts/ci/quarantine_manager.py` — reads quarantine.jsonl, counts flake occurrences
   per test in a 7-day rolling window. Tests flaking ≥3 times are automatically added to
   `.playwright-quarantine` and skipped in the PR gate (still run nightly).
4. Quarantined tests trigger a GitHub issue labelled `flaky-test` for human follow-up.

**Prevention:** Quarantine system is self-maintaining. `--retries=1` absorbs transient environment
noise. The 7-day rolling window means tests that stop flaking automatically fall out of quarantine.

**Follow-up:** Review quarantined tests monthly. A test quarantined for >30 days should be deleted
or fundamentally rewritten.

**PR/Commit:** #8

---

## [2026-04-14] tests — Unit vs integration suite runtime causing slow PR gates

**Symptom:** PR gates were taking 15–25 minutes because unit tests and integration tests ran
sequentially in the same job. Developers were waiting too long for CI feedback.

**Root cause:** `tests.yml` ran `pytest tests/` without splitting by test type. Integration tests
(hitting a real DB, making HTTP calls) dominated runtime. Unit tests that could run in 30 seconds
were blocked behind slow integration setup.

**Fix applied:** Split `tests.yml` into two parallel jobs:
```yaml
jobs:
  unit:
    runs-on: ubuntu-latest
    steps:
      - run: pytest tests/unit/ --timeout=30
  integration:
    runs-on: ubuntu-latest
    services:
      postgres: ...
    steps:
      - run: pytest tests/integration/ --timeout=120
```
Both jobs must pass for the PR gate to go green. Total PR gate time target: <5 minutes.

**Prevention:** New test files must be placed in either `tests/unit/` or `tests/integration/` —
the root `tests/` directory is disallowed for test files (enforced by `scripts/ci/check_test_touches.py`).

**Follow-up:** Profile slowest integration tests and identify candidates to mock at unit level.

**PR/Commit:** #9

---

## [2026-04-15] tests — Test-touch gate: agents modifying controllers without touching tests

**Symptom:** Agent-authored PRs were modifying controller logic without updating or adding any
corresponding tests. Bugs were reaching main that would have been caught if tests had been touched.

**Root cause:** No enforcement existed. Agents would make the narrowest change requested and stop,
leaving test coverage gaps. CodeRabbit would comment but not block.

**Fix applied:** Created `scripts/ci/check_test_touches.py` and wired it into `tests.yml`:
- Inspects the PR diff to identify which source files were changed.
- For each changed file in `app/` or `src/`, checks whether a corresponding file in `tests/`
  was also modified (either the same-named test file, or any file importing the changed module).
- Fails with a clear message listing untouched files if no test files were touched for a given change.
- Bypass: PR description must contain `<!-- test-touch-exempt: reason -->` for exception cases
  (e.g., pure config changes, documentation-only changes).

**Prevention:** Gate runs on every PR. Agents are instructed in `AGENT_WORKFLOW.md` to always
touch the corresponding test file or use the exempt comment with a reason.

**Follow-up:** Extend to track coverage delta — block PRs that decrease coverage by more than 2%.

**PR/Commit:** #13

---

## [2026-04-15] tests — Coverage threshold started at 12% to avoid blocking

**Symptom:** N/A — this is a deliberate decision record, not a failure.

**Symptom (context):** When pytest-cov was first introduced, overall test coverage was 12%.
Setting a meaningful threshold (e.g., 80%) would have immediately blocked all PRs.

**Root cause:** Coverage tooling was added to an existing codebase with low test density.

**Fix applied:** Coverage threshold set to current actual coverage (12%) at introduction. This
prevents regression without blocking forward progress. Each major PR that improves coverage
increases the threshold by 5 percentage points. The threshold is stored in `pytest.ini`:
```ini
[tool:pytest]
addopts = --cov=app --cov-fail-under=12
```

**Prevention:** Threshold is checked into source control and reviewed at each major PR.
The rule: never lower the threshold, always raise it when coverage improves. Target: 60% by EOY.

**Follow-up:** Track current threshold and coverage in the weekly pipeline-health issue. Automate
threshold bumping when coverage crosses the next 5% boundary.

**PR/Commit:** #13
