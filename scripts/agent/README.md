# Agent Coordination Scripts

Scripts in this directory form the StratFlow multi-agent coordination system.
They manage agent sessions, enforce safe git usage, and provide recovery tooling.

All scripts are pure stdlib Python 3.9+. Run from the repo root or any subdirectory.

---

## Scripts

### `session-start.py`

**The only supported entry point for beginning work on the repo.**

- Fetches `origin/main` and enforces branch creation from it.
- Scans reflog (48h) and stash list for any unrecovered local work, printing a
  prominent `RECOVERY:` warning if found.
- Displays a table of all active sessions from `.github/agent-ledger.json`,
  marking entries with no activity in >4 hours as `[STALE]`.
- Prints the three most recent handoff documents from `docs/agent-handoffs/`.
- Prompts (or accepts flags) for: `--agent-id`, `--goal`, `--files`, `--exclusive`.
- Creates branch `agent/{session_id}/{slug}` from `origin/main`.
- Registers the session in the ledger and pushes the branch.

```sh
python scripts/agent/session-start.py \
    --agent-id claude-code-1 \
    --goal "refactor auth layer" \
    --files "src/Auth/**,tests/Unit/Auth/**" \
    --exclusive
```

---

### `safe-commit.py`

**A `git commit` wrapper that refuses broad adds.**

- Blocks `-A`, `--all`, bare `.`, and bare `*` in path arguments.
- Looks up the active session for the current branch and updates
  `last_activity_at` and `last_commit_sha` in the ledger on success.
- Auto-pushes the branch after 5 consecutive commits without a push
  (`push_counter` in the ledger entry tracks this).
- Prints a one-line summary on success.

```sh
python scripts/agent/safe-commit.py -m "feat: add X" src/Foo.php tests/Unit/FooTest.php
```

---

### `recover.py`

**Read-only scan for all recoverable local work.**

- Reflog (7 days): commits not reachable from any remote tracking branch,
  with a `Recover with: git checkout -b recover/{sha}` instruction for each.
- Stash list: all stashes with age and `git stash pop` instructions.
- Local branches with unpushed commits: branch name, count, most recent message.
- Ledger sessions whose branch doesn't exist remotely (local-only risk).
- Prints a summary count. Makes no changes.

```sh
python scripts/agent/recover.py
```

---

### `widen-scope.py`

**Expands the declared file scope for the current session with an audit trail.**

- `--add GLOB` (required): glob pattern to add to `files_glob`.
- `--reason TEXT` (optional): reason recorded in `scope_widening_log`.
- Appends the glob to the session's `files_glob` list.
- Records `{timestamp, added_glob, reason, sha}` in `scope_widening_log`.
- Commits the ledger update automatically.
- Idempotent: no-ops if the glob is already declared.

```sh
python scripts/agent/widen-scope.py \
    --add "src/Services/**" \
    --reason "discovered dependency on service layer"
```

---

## Ledger schema (`.github/agent-ledger.json`)

```json
{
  "sessions": [
    {
      "session_id": "claude-code-1-20260415-1030-a3f2",
      "agent_id": "claude-code-1",
      "goal": "refactor auth layer",
      "branch": "agent/claude-code-1-20260415-1030-a3f2/refactor-auth-layer",
      "files_glob": ["src/Auth/**", "tests/Unit/Auth/**"],
      "exclusive": false,
      "started_at": "2026-04-15T10:30:00+00:00",
      "last_activity_at": "2026-04-15T11:45:00+00:00",
      "last_commit_sha": "abc1234def5678",
      "status": "active",
      "role": "foreground",
      "push_counter": 2,
      "scope_widening_log": []
    }
  ]
}
```
