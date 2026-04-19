#!/usr/bin/env python3
"""Start a StratFlow Codex session with the standard agent workflow.

This is a thin, stable wrapper around session-start.py that sets
--agent-id codex and keeps Codex branch/session naming consistent.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SESSION_START = REPO_ROOT / "scripts" / "agent" / "session-start.py"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--goal", required=True, help="Short description of the work")
    parser.add_argument(
        "--files",
        required=True,
        help="Comma-separated file globs expected in scope, for example 'src/**,tests/**'",
    )
    parser.add_argument("--exclusive", action="store_true", help="Claim exclusive access to the scope")
    args = parser.parse_args()

    cmd = [
        sys.executable,
        str(SESSION_START),
        "--agent-id",
        "codex",
        "--goal",
        args.goal,
        "--files",
        args.files,
    ]
    if args.exclusive:
        cmd.append("--exclusive")

    return subprocess.call(cmd, cwd=str(REPO_ROOT))


if __name__ == "__main__":
    sys.exit(main())
