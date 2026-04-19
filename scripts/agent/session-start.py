#!/usr/bin/env python3
"""
session-start.py — The ONLY supported way for an agent to begin work on the repo.

Creates a branch from origin/main (enforced), registers the session in
.github/agent-ledger.json, and shows a briefing of active sessions and
recent handoffs.

Usage:
    python scripts/agent/session-start.py [--agent-id ID] [--goal GOAL]
                                           [--files GLOB] [--exclusive]
"""

import argparse
import json
import os
import random
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


# === Constants ===

REPO_ROOT = Path(__file__).resolve().parents[2]
LEDGER_PATH = REPO_ROOT / ".github" / "agent-ledger.json"
HANDOFFS_DIR = REPO_ROOT / "docs" / "agent-handoffs"
STALE_HOURS = 4


# === Helpers ===

def run(cmd: list[str], check: bool = True, capture: bool = True) -> subprocess.CompletedProcess:
    """Run a subprocess command, returning the result."""
    return subprocess.run(
        cmd,
        cwd=str(REPO_ROOT),
        capture_output=capture,
        text=True,
        check=check,
    )


def now_iso() -> str:
    """Return current UTC time as ISO 8601 string."""
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def slugify(text: str, max_len: int = 30) -> str:
    """Convert a string to a URL-safe slug."""
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9]+", "-", text)
    text = text.strip("-")
    return text[:max_len].rstrip("-")


def rand4hex() -> str:
    """Return 4 random hex characters."""
    return f"{random.randint(0, 0xFFFF):04x}"


def hours_since(iso_ts: str) -> float:
    """Return hours elapsed since an ISO 8601 timestamp."""
    try:
        dt = datetime.fromisoformat(iso_ts)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        delta = datetime.now(timezone.utc) - dt
        return delta.total_seconds() / 3600
    except (ValueError, TypeError):
        return float("inf")


def load_ledger() -> dict:
    """Load the agent ledger JSON, returning empty structure if missing."""
    if LEDGER_PATH.exists():
        with open(LEDGER_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    return {"sessions": []}


def save_ledger(ledger: dict) -> None:
    """Write the agent ledger JSON to disk."""
    LEDGER_PATH.parent.mkdir(parents=True, exist_ok=True)
    with open(LEDGER_PATH, "w", encoding="utf-8") as f:
        json.dump(ledger, f, indent=2)
        f.write("\n")


def install_hooks() -> None:
    """Install git hooks using sh/Git Bash when available."""
    install_script = REPO_ROOT / "scripts" / "install-hooks.sh"
    if not install_script.exists():
        return

    candidates = ["sh"]
    if os.name == "nt":
        candidates.extend([
            r"C:\Program Files\Git\bin\bash.exe",
            r"C:\Program Files (x86)\Git\bin\bash.exe",
        ])

    for shell in candidates:
        try:
            result = subprocess.run(
                [shell, str(install_script), "--quiet"],
                cwd=str(REPO_ROOT),
                capture_output=True,
                text=True,
            )
        except FileNotFoundError:
            continue
        if result.returncode == 0:
            print("Git hooks installed.")
            return
        print(f"Warning: install-hooks.sh failed with {shell}: {result.stderr.strip()}", file=sys.stderr)
        return

    print("Warning: no sh/Git Bash found; git hooks were not installed.", file=sys.stderr)


# === Recovery scan ===

def check_local_recovery() -> None:
    """Scan for uncommitted local work and stashes, printing warnings if found."""
    print("\n--- Recovery Check ---")

    # Reflog commits not yet pushed
    try:
        reflog = run(["git", "reflog", "--since=48 hours ago", "--format=%H %s"])
        remote_shas = set()
        branches_r = run(["git", "branch", "-r", "--format=%(objectname:short)"], check=False)
        if branches_r.returncode == 0:
            # Get full SHAs for remote tracking commits
            rev_list = run(
                ["git", "log", "--remotes", "--format=%H", "--since=90 days ago"],
                check=False,
            )
            if rev_list.returncode == 0:
                remote_shas = set(rev_list.stdout.split())

        lost_commits = []
        for line in reflog.stdout.splitlines():
            parts = line.split(" ", 1)
            if len(parts) == 2:
                sha, msg = parts
                if sha not in remote_shas and not msg.startswith("stash@"):
                    lost_commits.append((sha, msg))

        if lost_commits:
            print("  *** RECOVERY: uncommitted local work found ***")
            for sha, msg in lost_commits[:10]:
                print(f"    {sha[:8]}  {msg}")
            if len(lost_commits) > 10:
                print(f"    ... and {len(lost_commits) - 10} more")
        else:
            print("  No unrecovered local commits found.")
    except subprocess.CalledProcessError:
        print("  (Could not inspect reflog)")

    # Stash list
    try:
        stash = run(["git", "stash", "list"])
        if stash.stdout.strip():
            print("  Stashes found:")
            for line in stash.stdout.splitlines():
                print(f"    {line}")
        else:
            print("  No stashes found.")
    except subprocess.CalledProcessError:
        print("  (Could not inspect stash list)")


# === Ledger display ===

def print_active_sessions(sessions: list[dict]) -> None:
    """Print a formatted table of active sessions from the ledger."""
    active = [s for s in sessions if s.get("status") == "active"]
    print(f"\n--- Active Sessions ({len(active)}) ---")
    if not active:
        print("  (none)")
        return

    col_w = [14, 18, 30, 35, 14, 14]
    headers = ["session_id", "agent_id", "goal", "branch", "started_at", "last_activity"]
    header_line = "  " + "  ".join(h.ljust(col_w[i]) for i, h in enumerate(headers))
    print(header_line)
    print("  " + "-" * (sum(col_w) + 2 * len(col_w)))

    for s in active:
        stale = hours_since(s.get("last_activity_at", "")) > STALE_HOURS
        tag = " [STALE]" if stale else ""
        row = [
            s.get("session_id", "")[:col_w[0]],
            s.get("agent_id", "")[:col_w[1]],
            s.get("goal", "")[:col_w[2]],
            s.get("branch", "")[:col_w[3]],
            (s.get("started_at") or "")[:10],
            (s.get("last_activity_at") or "")[:14],
        ]
        print("  " + "  ".join(v.ljust(col_w[i]) for i, v in enumerate(row)) + tag)


def print_recent_handoffs() -> None:
    """Print the last 3 handoff documents from docs/agent-handoffs/."""
    print("\n--- Recent Handoffs ---")
    if not HANDOFFS_DIR.exists():
        print("  (handoffs directory not found)")
        return

    files = sorted(HANDOFFS_DIR.glob("*"), key=lambda p: p.stat().st_mtime, reverse=True)
    recent = files[:3]
    if not recent:
        print("  (none)")
        return

    for f in recent:
        print(f"  {f.name}")
        try:
            lines = f.read_text(encoding="utf-8").splitlines()
            for line in lines[:3]:
                print(f"    {line}")
        except OSError:
            print("    (unreadable)")


# === Branch & ledger operations ===

def create_branch(session_id: str, goal: str) -> str:
    """Create agent branch from origin/main and return the branch name."""
    slug = slugify(goal)
    branch = f"agent/{session_id}/{slug}"
    cmd = ["git", "checkout", "-b", branch, "origin/main"]
    print(f"\n  Running: {' '.join(cmd)}")
    result = run(cmd, check=False, capture=False)
    if result.returncode != 0:
        print(f"ERROR: Failed to create branch '{branch}'.", file=sys.stderr)
        sys.exit(1)
    return branch


def register_session(
    ledger: dict,
    session_id: str,
    agent_id: str,
    goal: str,
    branch: str,
    files_glob: list[str],
    exclusive: bool,
) -> dict:
    """Append a new session entry to the ledger and return the updated ledger."""
    ts = now_iso()
    entry = {
        "session_id": session_id,
        "agent_id": agent_id,
        "goal": goal,
        "branch": branch,
        "files_glob": files_glob,
        "exclusive": exclusive,
        "started_at": ts,
        "last_activity_at": ts,
        "last_commit_sha": None,
        "status": "active",
        "role": "foreground",
        "push_counter": 0,
        "scope_widening_log": [],
    }
    ledger.setdefault("sessions", []).append(entry)
    return ledger


def commit_and_push_ledger(session_id: str, branch: str) -> None:
    """Stage, commit, and push the ledger file."""
    run(["git", "add", ".github/agent-ledger.json"])
    run(["git", "commit", "-m", f"chore(ledger): register session {session_id}"])
    print(f"\n  Pushing branch '{branch}' to origin...")
    result = run(["git", "push", "-u", "origin", branch], check=False, capture=False)
    if result.returncode != 0:
        print("  WARNING: Push failed. Branch is local-only for now.", file=sys.stderr)


# === CLI ===

def parse_args() -> argparse.Namespace:
    """Parse CLI arguments, prompting interactively for any missing required fields."""
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--agent-id", help="Agent identifier, e.g. claude-code-1")
    parser.add_argument("--goal", help="Short description of session goal")
    parser.add_argument("--files", help="Glob pattern(s) for files in scope, comma-separated")
    parser.add_argument("--exclusive", action="store_true", help="Claim exclusive access to declared file scope")
    args = parser.parse_args()

    if not args.agent_id:
        args.agent_id = input("Agent ID (e.g. claude-code-1): ").strip()
    if not args.goal:
        args.goal = input("Goal (short description): ").strip()
    if not args.files:
        args.files = input("File scope glob(s) (comma-separated): ").strip()
    if not args.exclusive:
        ans = input("Exclusive scope? [y/N]: ").strip().lower()
        args.exclusive = ans in ("y", "yes")

    return args


# === Main ===

def main() -> None:
    """Entry point: create session branch, register in ledger, print briefing."""
    print("=== StratFlow Agent Session Start ===")

    # 0. Install/refresh git hooks from scripts/hooks/
    install_hooks()

    # 1. Fetch origin/main
    print("\nFetching origin/main...")
    run(["git", "fetch", "origin", "main", "--prune"], capture=False)

    # 2. Recovery check
    check_local_recovery()

    # 3. Load and display current ledger
    ledger = load_ledger()
    print_active_sessions(ledger.get("sessions", []))

    # 4. Recent handoffs
    print_recent_handoffs()

    # 5. Gather args (CLI or interactive)
    args = parse_args()

    if not args.agent_id or not args.goal:
        print("ERROR: --agent-id and --goal are required.", file=sys.stderr)
        sys.exit(1)

    files_glob = [g.strip() for g in args.files.split(",") if g.strip()] if args.files else []

    # 6. Generate session ID
    ts_str = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M")
    session_id = f"{args.agent_id}-{ts_str}-{rand4hex()}"
    print(f"\n  Session ID: {session_id}")

    # 7. Create branch
    branch = create_branch(session_id, args.goal)

    # 8. Register session in ledger
    ledger = register_session(
        ledger,
        session_id=session_id,
        agent_id=args.agent_id,
        goal=args.goal,
        branch=branch,
        files_glob=files_glob,
        exclusive=args.exclusive,
    )
    save_ledger(ledger)

    # 9. Commit and push ledger
    commit_and_push_ledger(session_id, branch)

    # 10. Final briefing
    print("\n=== Session Briefing ===")
    print(f"  Session ID  : {session_id}")
    print(f"  Agent       : {args.agent_id}")
    print(f"  Goal        : {args.goal}")
    print(f"  Branch      : {branch}")
    print(f"  File scope  : {', '.join(files_glob) or '(none declared)'}")
    print(f"  Exclusive   : {args.exclusive}")
    print(f"  Started at  : {now_iso()}")
    print("\nSession registered. You may begin work.")


if __name__ == "__main__":
    main()
