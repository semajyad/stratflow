#!/usr/bin/env -S python3 -S
"""PreToolUse: unified commit gate — replaces pre_commit_audit.py,
pre_commit_code_review.py, and pre_commit_playwright.py.

Running three separate Python processes per commit added ~450 ms of cold-start
latency. This script consolidates all three marker checks into one process.

Gates every `git commit` command. Checks three markers (in order):
  1. .claude/.security-audit-ok   — security-auditor subagent ran and passed
  2. .claude/.review-ok           — code-reviewer subagent ran and passed
  3. .claude/.playwright-ok       — playwright-tester subagent ran and passed

Each marker must exist and be fresher than MAX_AGE_SEC (5 min). On success
all present markers are consumed. On failure, the first missing/stale marker
is reported with actionable instructions.

Exemptions: --dry-run, merge commits (MERGE_HEAD), nothing staged, no PHP files staged.
"""
import json
import os
import re
import subprocess
import sys
import time

MAX_AGE_SEC = 5 * 60  # 5 minutes

GATES = [
    {
        "marker": ".claude/.security-audit-ok",
        "label": "security audit",
        "instruction": (
            "Run the `security-auditor` subagent against the staged diff\n"
            "(`git diff --cached`). If it returns CLEAN — or after you\n"
            "have addressed every CRITICAL and HIGH finding — touch the marker:\n"
            "\n"
            "    touch .claude/.security-audit-ok"
        ),
    },
    {
        "marker": ".claude/.review-ok",
        "label": "code review",
        "instruction": (
            "Run the `code-reviewer` subagent against staged changes, then touch:\n"
            "\n"
            "    touch .claude/.review-ok"
        ),
    },
    {
        "marker": ".claude/.playwright-ok",
        "label": "Playwright tests",
        "instruction": (
            "Run the `playwright-tester` subagent. It will execute the appropriate\n"
            "test tier and write the marker if all tests pass."
        ),
    },
]


def get_project_dir(payload: dict) -> str:
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR", "")
    if project_dir:
        return project_dir
    try:
        return subprocess.check_output(
            ["git", "rev-parse", "--show-toplevel"],
            stderr=subprocess.DEVNULL,
            text=True,
        ).strip()
    except Exception:
        return os.getcwd()


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0

    command = (payload.get("tool_input") or {}).get("command") or ""
    if not isinstance(command, str):
        return 0

    # Only gate real commit invocations
    if not re.search(r"\bgit\s+commit\b", command):
        return 0
    if "--dry-run" in command:
        return 0

    project_dir = get_project_dir(payload)

    # Skip merge commits
    if os.path.isfile(os.path.join(project_dir, ".git", "MERGE_HEAD")):
        return 0

    # Skip if nothing staged
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

    # Skip if no PHP files staged (security/review checks are PHP-scoped)
    try:
        staged = subprocess.check_output(
            ["git", "diff", "--cached", "--name-only"],
            cwd=project_dir,
            text=True,
        ).strip().splitlines()
        if not any(f.endswith(".php") for f in staged):
            return 0
    except Exception:
        pass

    # Check each gate marker in order
    now = time.time()
    fresh_markers: list[str] = []
    for gate in GATES:
        marker_path = os.path.join(project_dir, gate["marker"])
        if os.path.isfile(marker_path):
            try:
                age = now - os.path.getmtime(marker_path)
            except OSError:
                age = MAX_AGE_SEC + 1
            if age <= MAX_AGE_SEC:
                fresh_markers.append(marker_path)
                continue  # This gate passes

        # Gate failed — block and report
        label = gate["label"]
        print(f"[pre-commit-gate] BLOCKED: {label} not verified.", file=sys.stderr)
        print("", file=sys.stderr)
        print(gate["instruction"], file=sys.stderr)
        print("", file=sys.stderr)
        print("Marker is valid for 5 minutes and consumed on next successful commit.", file=sys.stderr)

        # Consume markers that already passed to avoid confusion on retry
        for p in fresh_markers:
            try:
                os.remove(p)
            except OSError:
                pass
        return 2

    # All gates passed — consume all markers
    for p in fresh_markers:
        try:
            os.remove(p)
        except OSError:
            pass

    labels = ", ".join(g["label"] for g in GATES)
    print(f"[pre-commit-gate] All gates passed ({labels}) — commit allowed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
