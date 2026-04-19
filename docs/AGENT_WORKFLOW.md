# Agent Workflow — StratFlow

Onboarding guide for any new agent (or human) starting work on the StratFlow repository.
Read this before touching any file. It is short by design — the longer docs are in `docs/CI.md`,
`docs/SECURE_CODING.md`, and the scripts themselves.

---

## 1. Before You Start

**Codex should use its wrapper:**

```bash
python scripts/agent/codex-start.py \
  --goal "Short description of your goal" \
  --files "src/**,tests/**"
```

**Other agents should run session-start directly:**

```bash
python scripts/agent/session-start.py \
  --agent-id "agent-name" \
  --goal "Short description of your goal" \
  --files "src/Controllers/FooController.php,tests/Unit/Controllers/FooControllerTest.php"
```

What it does:
- Fetches `origin/main` and creates your branch from it (never from whatever HEAD happens to be).
- Registers your session in `.github/agent-ledger.json` with your declared file scope.
- Shows a briefing of other active agents and their scopes so you can spot conflicts early.
- Prints the correct branch name you should use for all subsequent commands.

What it requires:
- Write access to the repo (for ledger update).
- `GH_TOKEN` or `GITHUB_TOKEN` in environment (only needed if you intend to open PRs).
- No existing unmerged branch with the same slug (it will tell you if there is one).

**Skipping `session-start.py` will cause `multi-agent-guard.yml` to block your PR.**

---

## 2. Branch Naming

Session-start creates the branch automatically. The format is:

```
agent/<session-id>/<slug>
```

Examples:
```
agent/a1b2/fix-login-redirect
agent/c3d4/add-stripe-webhook-handler
agent/e5f6/refactor-auth-middleware
```

Rejected patterns (PR will be blocked):
- `fix/...` — human hotfix namespace, not for agents
- `feature/...` — legacy naming
- `agent/...` with no session ID segment (e.g., `agent/fix-login` — missing ID)
- Any branch not starting with `agent/`

If you need to work on an existing human branch, ask a human to merge your changes in — do not push directly.

---

## 3. Committing

Use `safe-commit.py` instead of `git commit`. It refuses broad adds and keeps the ledger updated:

```bash
# Stage specific files first
git add app/Http/Controllers/FooController.php tests/Unit/FooTest.php

# Then commit via the wrapper
python scripts/agent/safe-commit.py -m "feat: add foo validation" \
  app/Http/Controllers/FooController.php tests/Unit/FooTest.php
```

Why `-A` is forbidden:
- `git add -A` or `git commit -A` can silently include unrelated files from other agents' work
  or temporary files, polluting the diff and triggering the scope-guard check.
- safe-commit.py enforces that every path argument is explicitly named.
- It also auto-pushes after 5 consecutive local-only commits to prevent work loss.

Commit message format:
```
<type>: <short summary>

[optional body]

Decision: <if you made an architectural choice>
Follow-up: <if there's remaining work>
Known-issue: <if you're aware of a bug you're not fixing>
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `ci`, `chore`

---

## 4. Recovering Lost Work

If you lose track of changes (e.g., wrong branch, accidental reset):

```bash
python scripts/agent/recover.py
```

It searches:
- `git reflog` for recent HEADs you've been at
- `git stash list` for any stashed work
- `.github/agent-ledger.json` for your last known commit SHA

Output lists candidate commits with timestamps. Pick the right one and cherry-pick or reset to it.

---

## 5. Widening Scope

If you discover you need to touch files outside your declared scope, **do not just edit them** —
widen your scope first or `multi-agent-guard.yml` will reject the PR:

```bash
python scripts/agent/widen-scope.py --add "app/Services/**" "tests/Feature/**"
```

This updates your entry in `.github/agent-ledger.json`. If another agent already has an
overlapping exclusive lock on those paths, the command will tell you and suggest coordination options.

---

## 6. CI Pipeline

**Fast path (target: <5 minutes):**

| Job | What it checks | Blocks merge? |
|---|---|---|
| `unit` | PHPUnit unit tests (no DB) | Yes |
| `check_test_touches` | Did you touch a test file for each changed source file? | Yes |
| `dependency-review` | New dependency vulnerabilities | Yes |
| `multi-agent-guard` | Branch lineage, scope compliance | Yes |

**Slower jobs (run in parallel, don't block fast path):**

| Job | What it checks |
|---|---|
| `integration` | PHPUnit integration tests (MySQL) |
| `e2e` | Playwright browser tests |
| `security-shannon` | Entropy-based secret scan |
| `snyk` | Dependency CVE scan |

**On failure:**
- Unit/guard failures block the PR immediately — fix and push.
- Integration failures open a GitHub issue via nightly-triage if they persist overnight.
- E2E failures get one automatic retry (`--retries=1`). If still failing, check `tests/e2e/quarantine.jsonl`.

---

## 7. CodeRabbit Autofix

CodeRabbit reviews every PR and may post inline suggestions. The `coderabbit-autofix.yml` workflow
can apply some fixes automatically.

**How it works:**
1. CodeRabbit posts review comments.
2. `coderabbit-autofix.yml` reads comments labelled `autofix`.
3. It applies the suggested patch, commits on your branch, and pushes.

**If the `autofix-failed` label appears on your PR:**
- Check the `coderabbit-autofix` workflow run logs.
- The patch may have had a conflict or syntax error.
- Fix manually: apply the suggestion CodeRabbit described, commit with `python scripts/agent/safe-commit.py`.
- Remove the `autofix-failed` label once resolved.

---

## 8. Multi-Agent Coordination

The agent ledger (`.github/agent-ledger.json`) tracks all active agents and their declared file scopes.

**Overlapping scope** means two agents have declared they will touch the same file or glob pattern.

- Non-exclusive overlap: both agents may proceed, but merge order matters — merge whichever PR is closer to done first.
- Exclusive overlap: the later session-start call will warn you. You must either narrow your scope or wait.

**How to coordinate:**
1. Check the ledger briefing printed by `session-start.py` — it lists all active agents.
2. If you see overlap, ping the other agent (or human) via a GitHub comment on their PR.
3. Use `python scripts/agent/widen-scope.py` to adjust your scope rather than silently editing locked files.

Sessions older than 4 hours without a commit are marked stale and their locks are released automatically.

---

## 9. Background Agents

If you are a background agent (not interacting with a human in real time):

1. Register with `role: "background"` when calling session-start:
   ```bash
   python scripts/agent/session-start.py --role background --goal "nightly refactor pass"
   ```

2. Use `git worktree add` for full isolation from the main working tree:
   ```bash
   git worktree add /tmp/bg-agent-work agent/<session-id>/<slug>
   cd /tmp/bg-agent-work
   ```

3. Never push directly to `main`. Always open a PR even for trivial changes.

4. Background agents have lower priority in scope conflicts — foreground (human-supervised) agents take precedence.

---

## 10. Session End

Before ending your session, ensure:

1. All changes are committed and pushed.
2. A PR is open (or your branch is ready for one).
3. Codex must open a PR for any committed code unless the commit was pushed to
   an existing PR branch.
4. The repository owner is the only person who merges into `main` after CI.
5. Write a handoff file in `docs/agent-handoffs/`:
   ```bash
   # File name: YYYY-MM-DD-<slug>.md
   # See docs/agent-handoffs/README.md for the format
   ```

Commit trailers to use in your final commit message:

| Trailer | When to use |
|---|---|
| `Decision: <text>` | You made an architectural or approach choice that should persist |
| `Follow-up: <text>` | There is remaining work you did not complete |
| `Known-issue: <text>` | You are aware of a bug you are not fixing in this PR |

Example:
```
fix: resolve stale-review dedup in auto-merge

Decision: pre-filter COMMENTED reviews before group_by — decisive states only.
Follow-up: add unit tests for DISMISSED review edge case.
Known-issue: APPROVED after CHANGES_REQUESTED from the same user not yet handled.
```

---

## 11. Emergency Procedures

### You pushed to the wrong branch

Do not force-push. Instead:
1. Cherry-pick your commits onto the correct branch:
   ```bash
   git checkout agent/<correct-id>/<slug>
   git cherry-pick <sha1> <sha2>
   python scripts/agent/safe-commit.py --push
   ```
2. Close the wrong-branch PR without merging.
3. Open a PR from the correct branch.

### Stale-review block (PR is stuck waiting for re-review)

If a PR has a CHANGES_REQUESTED review and the reviewer is unavailable:
1. Check if the reviewer's comments have been addressed.
2. Post a comment on the PR: `@reviewer — changes applied, requesting re-review`.
3. If still blocked after 24 hours, add label `review-stale` — nightly-triage will escalate.
4. Do not dismiss the review yourself unless you have maintainer rights and the changes are trivially correct.

### Overriding the test-touch gate

If your change genuinely does not require a test update (config change, docs, CI-only):
Add this exact comment to your PR description:
```html
<!-- test-touch-exempt: reason goes here -->
```
The gate will pass. The reason is required — a bare exempt comment is rejected.
