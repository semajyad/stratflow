#!/usr/bin/env python3
"""Enforce StratFlow security rules on staged PHP/HTML changes.

This is the repo-level equivalent of the Claude Code post-edit security hook.
It is intentionally small and deterministic so every agent path can run the
same checks through the shared git pre-commit hook.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from dataclasses import dataclass


@dataclass(frozen=True)
class Rule:
    id: str
    pattern: re.Pattern[str]
    message: str
    path_filter: re.Pattern[str] | None = None


RULES: tuple[Rule, ...] = (
    Rule(
        id="ZAP-10055-unsafe-inline",
        pattern=re.compile(r"script-src[^'\"\n]*'unsafe-inline'", re.IGNORECASE),
        message=(
            "'unsafe-inline' was added to script-src. Put JavaScript in bundled "
            "files under public/assets/js/ instead."
        ),
    ),
    Rule(
        id="ZAP-10096-filemtime",
        pattern=re.compile(r"\bfilemtime\s*\(|\btime\s*\(\s*\)"),
        path_filter=re.compile(r"(^|/)templates/"),
        message=(
            "filemtime() / time() cannot be used as template cache-busters. "
            "Use the ASSET_VERSION constant."
        ),
    ),
    Rule(
        id="ZAP-10055-sri",
        pattern=re.compile(
            r'<script\s[^>]*src=["\'][^"\']*://(?:(?!integrity=).)*>',
            re.IGNORECASE,
        ),
        message=(
            "External <script src=...> is missing integrity=. Add SRI and "
            "crossorigin=\"anonymous\"."
        ),
    ),
    Rule(
        id="ZAP-10031-xss",
        pattern=re.compile(r"echo\s+\$(?!this\b)\w+(?!\s*[;,)].*htmlspecialchars)"),
        path_filter=re.compile(r"(^|/)templates/"),
        message=(
            "Template echo of a variable must use htmlspecialchars(..., "
            "ENT_QUOTES, 'UTF-8')."
        ),
    ),
)


def staged_files() -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--cached", "--name-only", "--diff-filter=ACMR"],
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        print(f"check_security_rules: git diff failed: {result.stderr.strip()}", file=sys.stderr)
        return []
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def target_files(paths: list[str]) -> list[str]:
    return [
        path
        for path in paths
        if path.replace("\\", "/").lower().endswith((".php", ".html"))
        and os.path.isfile(path)
    ]


def check_file(path: str) -> list[Rule]:
    norm_path = path.replace("\\", "/")
    try:
        content = open(path, encoding="utf-8", errors="replace").read()
    except OSError as exc:
        print(f"check_security_rules: cannot read {path}: {exc}", file=sys.stderr)
        return []

    violations: list[Rule] = []
    for rule in RULES:
        if rule.path_filter is not None and rule.path_filter.search(norm_path) is None:
            continue
        if rule.pattern.search(content):
            violations.append(rule)
    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--staged", action="store_true", help="check staged file paths")
    args = parser.parse_args()

    if os.environ.get("SKIP_SECURITY_RULES") == "1":
        print("check_security_rules: SKIP_SECURITY_RULES=1 - bypassed")
        return 0

    paths = staged_files() if args.staged else []
    files = target_files(paths)
    if not files:
        return 0

    failed = False
    for path in files:
        violations = check_file(path)
        if not violations:
            continue
        failed = True
        print(f"\n[security-rules] BLOCKED: {path}")
        for violation in violations:
            print(f"  [{violation.id}] {violation.message}")

    if failed:
        print("\nFix the security rule violations before committing.")
        print("Emergency bypass: SKIP_SECURITY_RULES=1 git commit ...")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
