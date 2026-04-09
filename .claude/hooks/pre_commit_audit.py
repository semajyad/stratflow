#!/usr/bin/env python3
"""PreToolUse: require a recent security-auditor run before `git commit`.

Gates every `git commit` command. Looks for a marker file at
`.claude/.security-audit-ok` modified within the last 5 minutes. If present
and fresh, the hook consumes it (deletes) and allows the commit. Otherwise
it blocks with a message instructing Claude to run the security-auditor
subagent, touch the marker on CLEAN, and retry.

Exempted: `--dry-run`, `--amend` without changes, and commits with nothing
staged (git will reject those anyway).
"""
import json
import os
import re
import subprocess
import sys
import time

MARKER_REL = ".claude/.security-audit-ok"
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
    # Skip dry-runs and interactive verifications
    if "--dry-run" in command or "--no-verify-only" in command:
        return 0

    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    marker_path = os.path.join(project_dir, MARKER_REL)

    # Is there actually anything staged? If not, let git reject the commit
    # naturally so the user isn't nagged about empty commits.
    try:
        r = subprocess.run(
            ["git", "diff", "--cached", "--quiet"],
            cwd=project_dir,
            capture_output=True,
        )
        if r.returncode == 0:
            return 0  # nothing staged
    except (FileNotFoundError, OSError):
        return 0  # git unavailable — don't block

    # Fresh marker? Consume it and allow.
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
            print(f"[pre-commit-audit] security audit marker consumed (age {int(age)}s) — commit allowed")
            return 0

    # Block with actionable instructions
    print("[pre-commit-audit] BLOCKED: commit requires a recent security audit.", file=sys.stderr)
    print("", file=sys.stderr)
    print("Run the `security-auditor` subagent against the staged diff", file=sys.stderr)
    print("(`git diff --cached`) first. If it returns CLEAN — or after you", file=sys.stderr)
    print("have addressed every CRITICAL and HIGH finding — create the marker", file=sys.stderr)
    print("and retry the commit:", file=sys.stderr)
    print("", file=sys.stderr)
    print(f"    touch {MARKER_REL}", file=sys.stderr)
    print("", file=sys.stderr)
    print("The marker is valid for 5 minutes and is consumed on the next successful commit.", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
