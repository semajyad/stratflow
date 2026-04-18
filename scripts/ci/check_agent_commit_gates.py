#!/usr/bin/env python3
"""Require Claude/Codex review markers before committing staged PHP changes.

Claude Code enforces these markers in a PreToolUse hook. Codex and other tools
do not run Claude lifecycle hooks, so this shared pre-commit check makes the
same workflow apply at the repository boundary.
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path


MAX_AGE_SEC = 5 * 60


@dataclass(frozen=True)
class Gate:
    marker: str
    label: str
    instruction: str


GATES: tuple[Gate, ...] = (
    Gate(
        marker=".claude/.security-audit-ok",
        label="security audit",
        instruction=(
            "Review the staged diff for CRITICAL/HIGH security issues. After all "
            "findings are addressed or the diff is clean, touch .claude/.security-audit-ok."
        ),
    ),
    Gate(
        marker=".claude/.review-ok",
        label="code review",
        instruction=(
            "Review the staged diff for correctness, return types, silent failures, "
            "and missing tests. After it is clean, touch .claude/.review-ok."
        ),
    ),
    Gate(
        marker=".claude/.playwright-ok",
        label="Playwright check",
        instruction=(
            "Run the appropriate browser test tier for the change. After it passes "
            "or is clearly not applicable, touch .claude/.playwright-ok."
        ),
    ),
)


def repo_root() -> Path:
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        return Path.cwd()
    return Path(result.stdout.strip())


def has_merge_head(root: Path) -> bool:
    result = subprocess.run(
        ["git", "rev-parse", "--git-dir"],
        cwd=root,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        return False
    git_dir = Path(result.stdout.strip())
    if not git_dir.is_absolute():
        git_dir = root / git_dir
    return (git_dir / "MERGE_HEAD").is_file()


def staged_files(root: Path) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--cached", "--name-only", "--diff-filter=ACMR"],
        cwd=root,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        return []
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def requires_gates(files: list[str]) -> bool:
    return any(path.replace("\\", "/").endswith(".php") for path in files)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--staged", action="store_true", help="check staged files")
    args = parser.parse_args()

    if os.environ.get("SKIP_AGENT_GATES") == "1":
        print("agent-gates: SKIP_AGENT_GATES=1 - bypassed")
        return 0

    root = repo_root()
    if has_merge_head(root):
        return 0

    files = staged_files(root) if args.staged else []
    if not files or not requires_gates(files):
        return 0

    now = time.time()
    fresh: list[Path] = []
    for gate in GATES:
        marker_path = root / gate.marker
        if marker_path.is_file():
            age = now - marker_path.stat().st_mtime
            if age <= MAX_AGE_SEC:
                fresh.append(marker_path)
                continue

        print(f"\n[agent-gates] BLOCKED: {gate.label} not verified.", file=sys.stderr)
        print(gate.instruction, file=sys.stderr)
        print("Markers are valid for 5 minutes and consumed on successful commit.", file=sys.stderr)
        for marker in fresh:
            try:
                marker.unlink()
            except OSError:
                pass
        return 1

    for marker in fresh:
        try:
            marker.unlink()
        except OSError:
            pass

    print("[agent-gates] all review markers passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
