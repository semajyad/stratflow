#!/usr/bin/env python3
"""Pre-commit hook: enforces code review gate via .claude/.review-ok marker.

Mirrors the pattern of pre_commit_audit.py and pre_commit_playwright.py.
Blocks git commit unless the code-reviewer agent has run and passed within 5 minutes.
"""
import json
import os
import sys
import subprocess
import time

MAX_AGE_SEC = 300  # 5 minutes

def get_repo_root():
    try:
        return subprocess.check_output(
            ["git", "rev-parse", "--show-toplevel"],
            stderr=subprocess.DEVNULL,
            text=True,
        ).strip()
    except Exception:
        return os.getcwd()

def main():
    input_data = json.load(sys.stdin) if not sys.stdin.isatty() else {}
    tool_input = input_data.get("tool_input", {})
    command = tool_input.get("command", "")

    # Only gate `git commit` commands
    if "git commit" not in command:
        sys.exit(0)

    # Bypass conditions
    if "--dry-run" in command:
        sys.exit(0)

    repo_root = get_repo_root()

    # Skip if nothing staged
    try:
        result = subprocess.run(
            ["git", "diff", "--cached", "--quiet"],
            cwd=repo_root,
            capture_output=True,
        )
        if result.returncode == 0:
            sys.exit(0)  # nothing staged
    except Exception:
        pass

    # Skip merge commits
    if os.path.exists(os.path.join(repo_root, ".git", "MERGE_HEAD")):
        sys.exit(0)

    # Skip if no PHP files staged
    try:
        staged = subprocess.check_output(
            ["git", "diff", "--cached", "--name-only"],
            cwd=repo_root,
            text=True,
        ).strip().splitlines()
        if not any(f.endswith(".php") for f in staged):
            sys.exit(0)
    except Exception:
        pass

    marker_path = os.path.join(repo_root, ".claude", ".review-ok")

    if os.path.exists(marker_path):
        age = time.time() - os.path.getmtime(marker_path)
        if age <= MAX_AGE_SEC:
            os.remove(marker_path)  # consume marker
            sys.exit(0)

    print(json.dumps({
        "decision": "block",
        "reason": (
            "Code review not verified. Ask Claude to run the code-reviewer agent first.\n"
            "Example: 'Run the code-reviewer agent on staged changes'"
        ),
    }))
    sys.exit(0)

if __name__ == "__main__":
    main()
