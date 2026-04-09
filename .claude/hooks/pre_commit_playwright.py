#!/usr/bin/env python3
"""PreToolUse: require a recent playwright-tester run before `git commit`.

Gates every `git commit` command. Looks for a marker file at
`.claude/.playwright-ok` modified within the last 5 minutes. If present
and fresh, the hook consumes it (deletes) and allows the commit. Otherwise
it blocks with a message instructing Claude to run the playwright-tester agent.

Exempted: `--dry-run`, merge commits (MERGE_HEAD present), and commits with
nothing staged (git will reject those anyway).
"""
import json
import os
import re
import subprocess
import sys
import time

MARKER_REL = ".claude/.playwright-ok"
MAX_AGE_SEC = 5 * 60  # 5 minutes


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0

    command = (payload.get("tool_input") or {}).get("command") or ""
    if not isinstance(command, str) or not command:
        return 0

    # Only gate real commit invocations
    if not re.search(r"\bgit\s+commit\b", command):
        return 0
    # Skip dry-runs
    if "--dry-run" in command:
        return 0

    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()

    # Skip merge commits — MERGE_HEAD indicates an in-progress merge
    merge_head = os.path.join(project_dir, ".git", "MERGE_HEAD")
    if os.path.isfile(merge_head):
        return 0

    marker_path = os.path.join(project_dir, MARKER_REL)

    # Nothing staged? Let git reject it naturally
    try:
        r = subprocess.run(
            ["git", "diff", "--cached", "--quiet"],
            cwd=project_dir,
            capture_output=True,
        )
        if r.returncode == 0:
            return 0
    except (FileNotFoundError, OSError):
        return 0

    # Fresh marker? Consume it and allow
    if os.path.isfile(marker_path):
        try:
            age = time.time() - os.path.getmtime(marker_path)
        except OSError:
            age = MAX_AGE_SEC + 1
        if age <= MAX_AGE_SEC:
            try:
                os.remove(marker_path)
            except OSError:
                pass
            print(f"[pre-commit-playwright] Playwright marker consumed (age {int(age)}s) — commit allowed")
            return 0

    # Block with actionable instructions
    print("[pre-commit-playwright] BLOCKED: Playwright tests not verified.", file=sys.stderr)
    print("", file=sys.stderr)
    print("Ask Claude to run the `playwright-tester` agent before committing.", file=sys.stderr)
    print("The agent will run the appropriate test tier, manage Docker, and write", file=sys.stderr)
    print("the marker if all tests pass.", file=sys.stderr)
    print("", file=sys.stderr)
    print("The marker is valid for 5 minutes and is consumed on the next commit.", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
