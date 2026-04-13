#!/usr/bin/env -S python3 -S
"""PostToolUse: run `php -l` on any edited PHP file.

Tries the host PHP binary first (fast, ~50ms). Falls back to
`docker compose exec php` only if host PHP is unavailable. Skips
gracefully when neither is accessible.
"""
import json
import os
import shutil
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

    # --- Strategy 1: host PHP (fast path) ---
    host_php = shutil.which("php")
    if host_php:
        try:
            result = subprocess.run(
                [host_php, "-l", file_path],
                capture_output=True,
                text=True,
                timeout=5,
            )
            if result.returncode == 0:
                print(f"[post-edit-php-lint] OK: {rel}")
                return 0
            combined = (result.stdout or "") + (result.stderr or "")
            print(f"[post-edit-php-lint] SYNTAX ERROR in {rel}:", file=sys.stderr)
            print(combined.strip(), file=sys.stderr)
            return 2
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass  # Fall through to Docker

    # --- Strategy 2: Docker (fallback) ---
    container_path = f"/var/www/html/{rel}"
    try:
        result = subprocess.run(
            ["docker", "compose", "exec", "-T", "php", "php", "-l", container_path],
            cwd=project_dir,
            capture_output=True,
            text=True,
            timeout=5,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return 0  # Neither available — skip silently

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
        print(f"[post-edit-php-lint] SYNTAX ERROR in {rel}:", file=sys.stderr)
        print(combined.strip(), file=sys.stderr)
        return 2

    print(f"[post-edit-php-lint] OK: {rel}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
