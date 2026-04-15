#!/usr/bin/env python3
"""
recover.py — Shows any "lost but recoverable" work.

Safe to run at any time — performs no destructive operations. Reports:
  - Commits reachable from reflog but not from any remote tracking branch.
  - git stash entries.
  - Local branches with unpushed commits.
  - Ledger sessions whose branch doesn't exist remotely.

Usage:
    python scripts/agent/recover.py
"""

import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


# === Constants ===

REPO_ROOT = Path(__file__).resolve().parents[3]
LEDGER_PATH = REPO_ROOT / ".github" / "agent-ledger.json"
REFLOG_WINDOW = "7 days ago"


# === Helpers ===

def run(cmd: list[str], check: bool = False) -> subprocess.CompletedProcess:
    """Run a subprocess command from REPO_ROOT, never raising by default."""
    return subprocess.run(
        cmd,
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=check,
    )


def age_str(iso_or_git_date: str) -> str:
    """
    Convert an ISO 8601 timestamp to a human-readable age string.
    Falls back gracefully on parse failure.
    """
    try:
        dt = datetime.fromisoformat(iso_or_git_date)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        delta = datetime.now(timezone.utc) - dt
        seconds = int(delta.total_seconds())
        if seconds < 3600:
            return f"{seconds // 60}m ago"
        if seconds < 86400:
            return f"{seconds // 3600}h ago"
        return f"{seconds // 86400}d ago"
    except (ValueError, TypeError):
        return "unknown age"


def get_remote_shas() -> set[str]:
    """Return the full set of SHAs reachable from any remote tracking branch."""
    result = run(["git", "log", "--remotes", "--format=%H", "--since=90 days ago"])
    if result.returncode != 0:
        return set()
    return set(result.stdout.split())


def get_remote_branches() -> set[str]:
    """Return a set of remote branch names (stripped of 'origin/' prefix)."""
    result = run(["git", "branch", "-r", "--format=%(refname:short)"])
    if result.returncode != 0:
        return set()
    return {b.strip() for b in result.stdout.splitlines() if b.strip()}


# === Recovery sections ===

def check_orphaned_commits(remote_shas: set[str]) -> int:
    """
    Print reflog entries from the past 7 days not reachable from any remote.

    Returns count of items found.
    """
    print("\n--- Orphaned Commits (reflog, not on any remote) ---")
    result = run(["git", "reflog", f"--since={REFLOG_WINDOW}", "--format=%H %ci %s"])
    if result.returncode != 0:
        print("  (Could not read reflog)")
        return 0

    found = 0
    for line in result.stdout.splitlines():
        parts = line.split(" ", 3)
        if len(parts) < 4:
            continue
        sha, date, time, msg = parts[0], parts[1], parts[2], parts[3]
        if sha in remote_shas:
            continue
        # Skip stash refs
        if "stash" in msg.lower():
            continue
        try:
            dt = datetime.fromisoformat(f"{date}T{time}")
        except ValueError:
            dt = None
        age = age_str(f"{date}T{time}") if dt else "unknown age"
        print(f"  {age:<12}  {sha[:8]}  {msg}")
        print(f"              Recover with: git checkout -b recover/{sha[:8]} {sha}")
        found += 1

    if found == 0:
        print("  (none)")
    return found


def check_stashes() -> int:
    """
    Print all git stash entries with age and restore command.

    Returns count of items found.
    """
    print("\n--- Stash Entries ---")
    result = run(["git", "stash", "list"])
    if result.returncode != 0:
        print("  (Could not read stash list)")
        return 0

    lines = [l for l in result.stdout.splitlines() if l.strip()]
    if not lines:
        print("  (none)")
        return 0

    for i, line in enumerate(lines):
        # Line format: stash@{N}: WIP on branch: message
        print(f"  {line}")
        print(f"    Restore with: git stash pop stash@{{{i}}}")

    return len(lines)


def check_unpushed_branches() -> int:
    """
    Print local branches that have commits not pushed to their upstream.

    Returns count of items found.
    """
    print("\n--- Local Branches with Unpushed Commits ---")
    result = run(["git", "branch", "--format=%(refname:short)"])
    if result.returncode != 0:
        print("  (Could not list branches)")
        return 0

    branches = [b.strip() for b in result.stdout.splitlines() if b.strip()]
    found = 0

    for branch in branches:
        # Count commits ahead of upstream
        ahead_result = run([
            "git", "rev-list", "--count", f"origin/{branch}..{branch}",
        ])
        if ahead_result.returncode != 0:
            # Branch may have no upstream — count commits vs origin/main
            ahead_result = run([
                "git", "rev-list", "--count", f"origin/main..{branch}",
            ])
        if ahead_result.returncode != 0:
            continue

        count_str = ahead_result.stdout.strip()
        try:
            count = int(count_str)
        except ValueError:
            continue

        if count == 0:
            continue

        # Get most recent commit message on this branch
        log_result = run(["git", "log", "-1", "--format=%s", branch])
        last_msg = log_result.stdout.strip() if log_result.returncode == 0 else "(unknown)"

        print(f"  {branch}")
        print(f"    {count} unpushed commit(s). Latest: {last_msg}")
        found += 1

    if found == 0:
        print("  (none)")
    return found


def check_ledger_orphans(remote_branches: set[str]) -> int:
    """
    Find ledger sessions marked active whose branch doesn't exist remotely.

    Returns count of items found.
    """
    print("\n--- Ledger Sessions with Missing Remote Branches ---")
    if not LEDGER_PATH.exists():
        print("  (agent-ledger.json not found)")
        return 0

    try:
        with open(LEDGER_PATH, "r", encoding="utf-8") as f:
            ledger = json.load(f)
    except (json.JSONDecodeError, OSError) as exc:
        print(f"  (Could not read ledger: {exc})")
        return 0

    active = [s for s in ledger.get("sessions", []) if s.get("status") == "active"]
    found = 0

    for session in active:
        branch = session.get("branch", "")
        # Remote branches include 'origin/' prefix; strip for comparison
        remote_bare = {b.removeprefix("origin/") for b in remote_branches}
        bare_branch = branch.removeprefix("origin/")
        if bare_branch not in remote_bare and branch not in remote_branches:
            print(f"  Session: {session.get('session_id', '?')}")
            print(f"    Branch '{branch}' — session branch may be local-only")
            print(f"    Agent: {session.get('agent_id', '?')}  Goal: {session.get('goal', '?')}")
            found += 1

    if found == 0:
        print("  (none)")
    return found


# === Main ===

def main() -> None:
    """Entry point: run all recovery scans and print summary."""
    print("=== StratFlow Agent Recovery Report ===")
    print(f"(Read-only scan — no changes made)\n")

    remote_shas = get_remote_shas()
    remote_branches = get_remote_branches()

    total = 0
    total += check_orphaned_commits(remote_shas)
    total += check_stashes()
    total += check_unpushed_branches()
    total += check_ledger_orphans(remote_branches)

    print(f"\n=== Summary: Found {total} recoverable item(s). No changes made. ===")


if __name__ == "__main__":
    main()
