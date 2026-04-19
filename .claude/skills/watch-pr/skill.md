---
name: watch-pr
description: Watch a pull request until it MERGES — fixes CI failures, handles CodeRabbit CHANGES_REQUESTED cycles (fix or decline with reason, dismiss stale reviews, re-request review), and confirms auto-merge fires. Does not stop until state == MERGED.
allowed-tools: Bash, Read, Edit, Write, Glob, Grep, Agent
---

# Watch PR

Monitor a PR until it **MERGES**. Fix CI failures, handle every CodeRabbit review round, and confirm auto-merge fires. Never stop while the PR is still OPEN.

**Announce at start:** "Watching PR #[N] — will not stop until merged."

---

## Step 1 — Resolve PR + enable auto-merge immediately

```bash
gh pr view <N> --repo semajyad/stratflow \
  --json number,headRefName,headRefOid,state,mergeStateStatus
```

Enable auto-merge right away (idempotent — safe to run even if already set):

```bash
gh pr merge <N> --auto --squash --repo semajyad/stratflow
```

If this fails because the PR has no approving review yet, that's expected — auto-merge queues and fires the moment all requirements are satisfied.

---

## Step 2 — Main loop: poll until MERGED

```bash
gh pr view <N> --repo semajyad/stratflow --json state,mergeStateStatus \
  --jq '{state,mergeStateStatus}'
```

| state | mergeStateStatus | Action |
|---|---|---|
| MERGED | — | **Done — announce merge and stop** |
| OPEN | CLEAN | Auto-merge is queued — wait, re-poll in 30 s |
| OPEN | BLOCKED | Diagnose (Step 3) |
| OPEN | UNKNOWN / UNSTABLE | CI still running — wait (Step 4A) |

---

## Step 3 — Diagnose BLOCKED

Check both in parallel:

### 3A — Required CI failures

```bash
gh api repos/semajyad/stratflow/commits/<HEAD_SHA>/check-runs \
  --jq '.check_runs[] | select(.conclusion == "failure") | "\(.name)  \(.html_url)"'
```

Required checks (from branch ruleset — never accept failure in these):
- `PHPUnit unit (PHP 8.4)`
- `PHPUnit integration (PHP 8.4)`
- `Test-touch gate`
- `Python CI helper tests`
- `Playwright (fast — Chromium)`
- `Multi-agent guard`
- `trufflehog`
- `Semgrep (PR)`
- `Analyze JavaScript`
- `Checkov IaC`

For each failure, get logs:

```bash
gh run view <run_id> --repo semajyad/stratflow --log-failed 2>/dev/null | tail -80
```

Classify → fix → commit → push (see Step 5). After pushing, go back to Step 2.

### 3B — CodeRabbit CHANGES_REQUESTED

```bash
gh api repos/semajyad/stratflow/pulls/<N>/reviews \
  --jq '[.[] | select(.state == "CHANGES_REQUESTED")] | length'
```

If count > 0: go to Step 6 (CodeRabbit cycle).

If CI is green AND no CHANGES_REQUESTED but still BLOCKED: check review count:

```bash
gh api repos/semajyad/stratflow/pulls/<N>/reviews \
  --jq '[.[] | select(.state == "APPROVED")] | length'
```

If 0 approved reviews: post `@coderabbitai review` and wait (Step 7).

---

## Step 4 — Wait for CI to complete

**Wait pattern (background):**

```bash
until [ "$(gh run list --repo semajyad/stratflow --branch <branch> \
  --json status --jq '[.[] | select(.status=="in_progress" or .status=="queued")] | length')" \
  -eq "0" ]; do sleep 20; done
```

After CI completes, re-check HEAD SHA (it may have changed if a fix was pushed mid-run):

```bash
gh pr view <N> --repo semajyad/stratflow --json headRefOid --jq '.headRefOid'
```

---

## Step 5 — Apply a CI fix

- Fix the file(s), then commit **all fixes in one commit** (every push resets the CodeRabbit approval, so minimise pushes):

```bash
python scripts/agent/safe-commit.py -m "fix(scope): <description>" <files>
```

- Push:

```bash
git push origin <branch>
```

- Dismiss any stale CHANGES_REQUESTED reviews (they are now stale after the push anyway — Step 6A).
- Post `@coderabbitai review` to restart the review clock.
- Return to Step 2.

**Failure classification:**
- Transient infra (OOM, network timeout): re-trigger the run, don't change code
- Lint / syntax: fix the file
- Test failure: fix source or test
- Coverage gate: add tests — never reduce the threshold
- Workflow bug: fix `.github/workflows/`
- Unknown after reading logs: escalate (Step 8)

---

## Step 6 — CodeRabbit CHANGES_REQUESTED cycle

This is the most common blocker. Follow this sequence exactly.

### 6A — Read all findings from the latest CHANGES_REQUESTED review

```bash
# Get the most recent CHANGES_REQUESTED review ID
REVIEW_ID=$(gh api repos/semajyad/stratflow/pulls/<N>/reviews \
  --jq '[.[] | select(.state == "CHANGES_REQUESTED")] | last | .id')

# Read its body (summary of all findings)
gh api repos/semajyad/stratflow/pulls/<N>/reviews/${REVIEW_ID} --jq '.body'

# Read all inline comments on the current HEAD SHA
gh api repos/semajyad/stratflow/pulls/<N>/comments \
  --jq '.[] | select(.commit_id | startswith("<HEAD_SHA_PREFIX>")) | "FILE: \(.path)\nLINE: \(.original_line)\nBODY: \(.body)\n---"'
```

### 6B — Triage each finding

For **each finding**, decide:

**Action (fix the code)** when:
- The finding points to a real bug, missing assertion, or genuine test weakness
- The suggestion improves correctness or catches a real regression

**Decline (reply with reason)** when:
- The suggestion conflicts with intentional design already explained to CodeRabbit
- It would add noise/fragility (e.g., asserting implementation details, not behaviour)
- It contradicts a previous decline that CodeRabbit already learned from

Reply declining via PR comment:

```bash
gh pr comment <N> --repo semajyad/stratflow \
  --body "Declining [Finding X]: <one sentence reason why the current code is correct>"
```

### 6C — Apply all fixes in ONE commit

Batch every fix into a single commit to minimise review-dismissal cycles:

```bash
python scripts/agent/safe-commit.py -m "test(scope): address CodeRabbit round-N findings

[no-test-required: test-only changes]" <files>
git push origin <branch>
```

### 6D — Dismiss ALL CHANGES_REQUESTED reviews (including stale ones)

```bash
for id in $(gh api repos/semajyad/stratflow/pulls/<N>/reviews \
  --jq '.[] | select(.state == "CHANGES_REQUESTED" and (.user.login | ascii_downcase) == "coderabbitai[bot]") | .id'); do
  gh api "repos/semajyad/stratflow/pulls/<N>/reviews/${id}/dismissals" \
    --method PUT \
    --field message="All findings addressed or declined with reason in latest commit." \
    2>/dev/null || true
done
```

### 6E — Request a fresh CodeRabbit review

```bash
gh pr comment <N> --repo semajyad/stratflow --body "@coderabbitai review"
```

Return to Step 7 to wait for the verdict.

---

## Step 7 — Wait for CodeRabbit APPROVED on HEAD

```bash
HEAD_SHA=$(gh pr view <N> --repo semajyad/stratflow --json headRefOid --jq '.headRefOid')
until gh api repos/semajyad/stratflow/pulls/<N>/reviews \
  --jq --arg sha "$HEAD_SHA" \
  '[.[] | select(.commit_id == $sha and .state == "APPROVED")] | length > 0' \
  2>/dev/null | grep -q "true"; do sleep 20; done
```

When APPROVED arrives: re-check mergeStateStatus (Step 2). If CLEAN, auto-merge will fire shortly. If BLOCKED with CI failures, fix those (Step 3A).

---

## Step 8 — Escalate conditions

Stop watching and report to user **only** when:

| Condition | Action |
|---|---|
| Same CI check failed **3 times** with the same root cause | Escalate |
| Fix requires secret / credential changes | Escalate |
| CodeRabbit has posted CHANGES_REQUESTED **4+ times** on the same finding | Escalate |
| Wall-clock time exceeds **40 minutes** without progress | Escalate |

**Escalation report format:**

```text
PR #N watch stopped — human needed.
Blocker: <CI check name OR "CodeRabbit round N">
Root cause: <one sentence>
Attempted fixes: <list>
Next step: <concrete suggestion>
```

---

## Hard Rules

- **Never stop while `state == "OPEN"`** — only escalation ends the watch early
- Never force-push — only fast-forward pushes
- Never bypass required status checks
- Never modify tests to reduce coverage — fix source
- Never use `SKIP_TEST_TOUCH=1` for PHP src changes — add the test
- **Batch all fixes into one commit per push** — every push resets the CodeRabbit approval requirement (`dismiss_stale_reviews_on_push: true`)
- If a fix touches >3 files, escalate instead of auto-fixing
- After every push: always dismiss stale reviews (Step 6D) + request re-review (Step 6E)
