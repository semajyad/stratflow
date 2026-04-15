#!/usr/bin/env python3
"""
widen-scope.py — Expands the declared file scope for the current session.

Appends the new glob to the session's files_glob list and records an audit
entry in scope_widening_log. Commits the ledger update.

Usage:
    python scripts/agent/widen-scope.py --add "src/Services/**" [--reason "need service layer"]
"""

import argparse
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


# === Constants ===

REPO_ROOT = Path(__file__).resolve().parents[2]
LEDGER_PATH = REPO_ROOT / ".github" / "agent-ledger.json"


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


def current_branch() -> str:
    """Return the current git branch name."""
    result = run(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    return result.stdout.strip()


def head_sha() -> str:
    """Return the current HEAD commit SHA."""
    result = run(["git", "rev-parse", "HEAD"])
    return result.stdout.strip()


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


# === Main ===

def parse_args() -> argparse.Namespace:
    """Parse CLI arguments."""
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--add",
        required=True,
        metavar="GLOB",
        help="Glob pattern to add to this session's file scope",
    )
    parser.add_argument(
        "--reason",
        default="",
        metavar="TEXT",
        help="Optional reason for the scope expansion (for audit trail)",
    )
    return parser.parse_args()


def main() -> None:
    """Entry point: extend file scope for the current session and commit."""
    args = parse_args()
    new_glob = args.add.strip()
    reason = args.reason.strip()

    if not new_glob:
        print("ERROR: --add requires a non-empty glob pattern.", file=sys.stderr)
        sys.exit(1)

    # 1. Identify current session
    branch = current_branch()
    ledger = load_ledger()
    sessions = ledger.get("sessions", [])
    session_entry = next(
        (s for s in sessions if s.get("branch") == branch and s.get("status") == "active"),
        None,
    )

    if session_entry is None:
        print(
            f"ERROR: No active session found for branch '{branch}'.\n"
            "Run session-start.py first.",
            file=sys.stderr,
        )
        sys.exit(1)

    # 2. Append new glob
    files_glob: list[str] = session_entry.setdefault("files_glob", [])
    if new_glob in files_glob:
        print(f"[widen-scope] Glob '{new_glob}' is already in scope. No change.")
        sys.exit(0)

    files_glob.append(new_glob)

    # 3. Append audit entry
    sha = head_sha()
    audit_entry = {
        "timestamp": now_iso(),
        "added_glob": new_glob,
        "reason": reason,
        "sha": sha,
    }
    session_entry.setdefault("scope_widening_log", []).append(audit_entry)
    session_entry["last_activity_at"] = now_iso()

    # 4. Save ledger
    save_ledger(ledger)

    # 5. Commit ledger update via safe-commit to update session bookkeeping
    safe_commit = REPO_ROOT / "scripts" / "agent" / "safe-commit.py"
    commit_msg = f"chore(ledger): widen scope +{new_glob}"
    result = run(
        [sys.executable, str(safe_commit), "-m", commit_msg, ".github/agent-ledger.json"],
        check=False,
    )
    if result.returncode != 0:
        print(
            f"[widen-scope] WARNING: Ledger saved but commit failed.\n{result.stderr.strip()}",
            file=sys.stderr,
        )
        sys.exit(1)

    # 6. Report
    session_id = session_entry.get("session_id", "unknown")
    all_globs = ", ".join(files_glob)
    print(f"[widen-scope] Scope expanded: now covers {all_globs}")
    print(f"  Session : {session_id}")
    print(f"  Added   : {new_glob}")
    if reason:
        print(f"  Reason  : {reason}")
    print(f"  Commit  : {head_sha()[:8]}")


if __name__ == "__main__":
    main()
