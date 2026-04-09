#!/usr/bin/env python3
"""PostToolUse: run `php -l` via Docker on any edited PHP file.

Gracefully skips if the php container isn't running — we don't want to
block Claude when the stack is down.
"""
import json
import os
import subprocess
import sys


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0
    file_path = (payload.get("tool_input") or {}).get("file_path") or ""
    if not isinstance(file_path, str) or not file_path.lower().endswith(".php"):
        return 0
    if not os.path.isfile(file_path):
        return 0

    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    try:
        rel = os.path.relpath(file_path, project_dir).replace(os.sep, "/")
    except ValueError:
        return 0
    if rel.startswith(".."):
        return 0

    container_path = f"/var/www/html/{rel}"
    try:
        result = subprocess.run(
            ["docker", "compose", "exec", "-T", "php", "php", "-l", container_path],
            cwd=project_dir, capture_output=True, text=True, timeout=15,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return 0  # Docker not available or timed out — skip silently

    stderr = (result.stderr or "").lower()
    if result.returncode != 0 and any(
        s in stderr for s in (
            "no such service", "is not running",
            "cannot connect to the docker daemon",
        )
    ):
        return 0  # Stack down — skip silently

    if result.returncode != 0:
        combined = (result.stdout or "") + (result.stderr or "")
        print(f"[post-edit-php-lint] SYNTAX ERROR in {file_path}:", file=sys.stderr)
        print(combined.strip(), file=sys.stderr)
        return 2

    print(f"[post-edit-php-lint] OK: {rel}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
