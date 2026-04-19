# Codex Workflow

Codex follows the same repo rules as Claude Code, with one extra operating
constraint from the repository owner: every code commit must be represented by a
GitHub PR unless it is pushed to an already-open PR branch.

## Start

Use the Codex wrapper for new work:

```bash
python scripts/agent/codex-start.py \
  --goal "fix sounding board accordions" \
  --files "public/assets/js/**,public/assets/css/**,tests/Playwright/**"
```

The wrapper delegates to `session-start.py` with `--agent-id codex`, creates an
agent branch from `origin/main`, installs hooks, records the session in the
ledger, and pushes the branch.

If continuing an existing PR, switch to that PR branch instead of starting a new
session.

## Commit And PR Rule

- Never commit directly to `main`.
- Never merge or enable auto-merge. The repository owner merges after CI.
- After committing, push and open a PR immediately unless the branch already has
  an open PR.
- Watch CI and report the PR URL plus check status.
- If the commit belongs to an existing PR, push to that PR branch and report that
  PR.

## Before Final Reply

Codex should verify:

- The branch is pushed.
- A PR exists, or the commit was pushed to an existing PR.
- CI has been checked.
- Any preview URL needed for a browser check was retrieved with
  `python scripts/agent/preview-url.py --pr <number>`.
- Generated artifacts such as Playwright reports are not left in the working tree.
- Unrelated user changes were not staged, reverted, or cleaned.

## Local Review Markers

For PHP/template commits, the shared pre-commit hook requires the same markers
Claude Code uses:

```bash
touch .claude/.security-audit-ok
touch .claude/.review-ok
touch .claude/.playwright-ok
```

Only touch them after the matching review or test has actually been completed.
