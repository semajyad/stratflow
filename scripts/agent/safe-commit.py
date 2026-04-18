#!/usr/bin/env python3
"""
safe-commit.py — Wraps `git commit` and refuses broad adds.

Enforces explicit file paths. Updates the agent ledger on success.
Auto-pushes after 5 consecutive commits without a push.

Usage:
    python scripts/agent/safe-commit.py -m "feat: add X" src/Foo.php tests/Unit/FooTest.php

FORBIDDEN path patterns:
    -A / --all       git commit -A ...
    bare .           path argument equal to '.'
    bare *           path argument equal to '*'
"""

import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


# === Constants ===

REPO_ROOT = Path(__file__).resolve().parents[2]
LEDGER_PATH = REPO_ROOT / ".github" / "agent-ledger.json"
AUTO_PUSH_THRESHOLD = 5

FORBIDDEN_FLAGS = {"-A", "--all"}
FORBIDDEN_PATHS = {".", "*"}


# === Helpers ===

def run(cmd: list[str], check: bool = True, capture: bool = True) -> subprocess.CompletedProcess:
    """Run a subprocess command from REPO_ROOT."""
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


def load_ledger() -> dict:
    """Load agent ledger JSON, returning empty structure if missing."""
    if LEDGER_PATH.exists():
        with open(LEDGER_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    return {"sessions": []}


def save_ledger(ledger: dict) -> None:
    """Write agent ledger JSON to disk."""
    with open(LEDGER_PATH, "w", encoding="utf-8") as f:
        json.dump(ledger, f, indent=2)
        f.write("\n")


def current_branch() -> str:
    """Return the current git branch name."""
    result = run(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    return result.stdout.strip()


def head_sha() -> str:
    """Return the current HEAD commit SHA."""
    result = run(["git", "rev-parse", "HEAD"])
    return result.stdout.strip()


def count_staged_files() -> int:
    """Return number of files staged for commit."""
    result = run(["git", "diff", "--cached", "--name-only"])
    lines = [l for l in result.stdout.splitlines() if l.strip()]
    return len(lines)


# === Broad-add detection ===

def detect_broad_add(argv: list[str]) -> tuple[bool, str]:
    """
    Inspect argv for forbidden broad-add patterns.

    Returns (is_forbidden, reason). Separates flags from file paths by
    treating args after '--' as paths and skipping known option values.
    """
    skip_next = False
    # Options that consume the next token as their value
    value_options = {
        "-m", "--message",
        "-C", "--reuse-message",
        "-c", "--reedit-message",
        "--fixup", "--squash",
        "--author", "--date",
        "-F", "--file",
        "--trailer",
    }
    paths = []
    past_double_dash = False

    i = 0
    while i < len(argv):
        token = argv[i]

        if token == "--":
            past_double_dash = True
            i += 1
            continue

        if past_double_dash:
            paths.append(token)
            i += 1
            continue

        if skip_next:
            skip_next = False
            i += 1
            continue

        if token in FORBIDDEN_FLAGS:
            return True, f"'{token}' is a broad-add flag."

        # Check for combined short options like -am
        if token.startswith("-") and not token.startswith("--") and len(token) > 2:
            for ch in token[1:]:
                if f"-{ch}" in FORBIDDEN_FLAGS:
                    return True, f"'-{ch}' (in '{token}') is a broad-add flag."

        if token in value_options:
            skip_next = True
            i += 1
            continue

        # If it looks like a flag, skip; otherwise treat as path
        if not token.startswith("-"):
            paths.append(token)

        i += 1

    for p in paths:
        if p in FORBIDDEN_PATHS:
            return True, f"Path '{p}' is too broad. Specify explicit file paths."

    return False, ""


# === Main ===

def main() -> None:
    """Entry point: validate args, run git commit, update ledger."""
    argv = sys.argv[1:]

    if not argv:
        print("Usage: python scripts/agent/safe-commit.py -m 'message' file1 file2 ...", file=sys.stderr)
        sys.exit(1)

    # 1. Broad-add guard
    is_forbidden, reason = detect_broad_add(argv)
    if is_forbidden:
        print(f"[safe-commit] BLOCKED: {reason}", file=sys.stderr)
        print("", file=sys.stderr)
        print("  -A / --all / '.' / '*' are forbidden. Specify files explicitly:", file=sys.stderr)
        print("    python scripts/agent/safe-commit.py -m 'message' src/Foo.php tests/Unit/FooTest.php", file=sys.stderr)
        sys.exit(1)

    # 2. Look up current session
    branch = current_branch()
    ledger = load_ledger()
    sessions = ledger.get("sessions", [])
    session_entry = next(
        (s for s in sessions if s.get("branch") == branch and s.get("status") == "active"),
        None,
    )

    if session_entry is None:
        print(
            f"[safe-commit] WARNING: No active session found for branch '{branch}'. "
            "Proceeding without ledger update.",
            file=sys.stderr,
        )

    # 3. Count staged files before committing
    n_files = count_staged_files()

    # 4. Run git commit
    cmd = ["git", "commit"] + argv
    result = subprocess.run(cmd, cwd=str(REPO_ROOT), text=True)
    if result.returncode != 0:
        sys.exit(result.returncode)

    # 5. Get new HEAD SHA
    sha = head_sha()

    # 6. Update ledger
    if session_entry is not None:
        session_entry["last_activity_at"] = now_iso()
        session_entry["last_commit_sha"] = sha
        push_counter = session_entry.get("push_counter", 0) + 1
        session_entry["push_counter"] = push_counter
        save_ledger(ledger)

        session_id = session_entry.get("session_id", "unknown")
        print(f"[safe-commit] committed {n_files} file(s) | session {session_id} | {sha[:8]}")

        # 7. Auto-push if threshold reached
        if push_counter >= AUTO_PUSH_THRESHOLD:
            print(f"[safe-commit] Auto-pushing ({push_counter} commits since last push)...")
            push_result = subprocess.run(
                ["git", "push"],
                cwd=str(REPO_ROOT),
                text=True,
            )
            if push_result.returncode == 0:
                session_entry["push_counter"] = 0
                save_ledger(ledger)
                print("[safe-commit] Push successful. Counter reset.")
            else:
                print("[safe-commit] WARNING: Auto-push failed.", file=sys.stderr)
    else:
        print(f"[safe-commit] committed {n_files} file(s) | (no session) | {sha[:8]}")


if __name__ == "__main__":
    main()
