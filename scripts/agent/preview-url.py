#!/usr/bin/env python3
"""Print the Railway preview URL for a PR.

The PR preview workflow posts a stable "Preview Environment" PR comment. This
helper reads that comment through gh CLI and prints the URL agents should use
for browser checks.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
URL_RE = re.compile(r"https://stratflow-pr-\d+\.up\.railway\.app")


def run_gh(args: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["gh", *args],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )


def current_pr_number() -> str | None:
    result = run_gh(["pr", "view", "--json", "number"])
    if result.returncode != 0:
        return None
    try:
        return str(json.loads(result.stdout)["number"])
    except (KeyError, ValueError, TypeError):
        return None


def preview_from_comments(pr_number: str) -> str | None:
    result = run_gh(["pr", "view", pr_number, "--json", "comments"])
    if result.returncode != 0:
        print(result.stderr.strip(), file=sys.stderr)
        return None
    data = json.loads(result.stdout)
    comments = data.get("comments", [])
    for comment in reversed(comments):
        body = comment.get("body", "")
        if "Preview Environment" not in body:
            continue
        match = URL_RE.search(body)
        if match:
            return match.group(0)
    return None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pr", help="PR number. Defaults to the current branch PR.")
    parser.add_argument("--fallback", action="store_true", help="Print the conventional URL if no comment exists")
    args = parser.parse_args()

    pr_number = args.pr or current_pr_number()
    if not pr_number:
        print("preview-url: no PR found for the current branch. Pass --pr <number>.", file=sys.stderr)
        return 1

    url = preview_from_comments(pr_number)
    if url:
        print(url)
        return 0

    fallback = f"https://stratflow-pr-{pr_number}.up.railway.app"
    if args.fallback:
        print(fallback)
        return 0

    print(
        f"preview-url: no preview comment found for PR {pr_number}. "
        f"Expected fallback would be {fallback}",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
