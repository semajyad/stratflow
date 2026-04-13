#!/usr/bin/env -S python3 -S
"""Stop hook: append a one-line session summary to .claude/session-log.md.

Captures: timestamp, branch, files changed count, commits made this session.
Purely an append-only audit trail — helps the morning check rule understand
what the previous session changed without reading git log manually.
"""
import os
import subprocess
import sys
from datetime import datetime, timezone


LOG_FILE = ".claude/session-log.md"
COLUMNS = "Date | Branch | Files Changed | Commits | Summary"


def run(cmd: list[str], cwd: str) -> str:
    try:
        return subprocess.check_output(cmd, cwd=cwd, text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:
        return ""


def main() -> int:
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    log_path = os.path.join(project_dir, LOG_FILE)

    branch = run(["git", "rev-parse", "--abbrev-ref", "HEAD"], project_dir) or "unknown"
    diff_stat = run(["git", "diff", "--stat", "HEAD~1..HEAD"], project_dir)

    # Count files changed and commits in the last hour
    files_changed = len([
        l for l in diff_stat.splitlines()
        if "|" in l
    ]) if diff_stat else 0

    recent_commits = run(
        ["git", "log", "--since=2 hours ago", "--oneline"],
        project_dir,
    )
    commit_count = len(recent_commits.splitlines()) if recent_commits else 0

    # Build summary from recent commit messages
    summary = recent_commits.splitlines()[0] if recent_commits else "no commits"
    if len(summary) > 72:
        summary = summary[:69] + "..."

    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    line = f"| {now} | {branch} | {files_changed} | {commit_count} | {summary} |"

    # Initialise file with header if it doesn't exist
    if not os.path.isfile(log_path):
        try:
            with open(log_path, "w", encoding="utf-8") as f:
                f.write("# Claude Code Session Log\n\n")
                f.write(f"| {COLUMNS} |\n")
                f.write("|---|---|---|---|---|\n")
        except OSError:
            return 0

    try:
        with open(log_path, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass

    return 0


if __name__ == "__main__":
    sys.exit(main())
