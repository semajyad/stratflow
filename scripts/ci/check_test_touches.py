#!/usr/bin/env python3
"""
check_test_touches.py — Enforce that every modified src/*.php file
has a corresponding test file touched in the same PR.

Exit 0: all modified source files have matching test changes (or are exempt).
Exit 1: one or more source files are missing test coverage in this diff.

Usage:
  python3 scripts/ci/check_test_touches.py --base origin/main --head HEAD

Exemption:
  Add `no-test-required` label to the PR AND include a PR comment with
  the text `/no-test-required: <reason>`. This script checks for the
  label via the GITHUB_TOKEN + GH_PR_NUMBER env vars.
"""

import argparse
import json
import os
import subprocess
import sys
from pathlib import PurePosixPath


# Source files in these directories don't have matching test files
SKIP_DIRS = {
    "src/Config",
    "src/Services/Prompts",
}

# Source-to-test path mapping rules (ordered, first match wins)
MAPPING_RULES = [
    # src/Controllers/Foo.php → tests/Unit/Controllers/FooTest.php
    ("src/Controllers/", "tests/Unit/Controllers/", "Test"),
    # src/Models/Foo.php → tests/Unit/Models/FooTest.php
    ("src/Models/", "tests/Unit/Models/", "Test"),
    # src/Services/Foo.php → tests/Unit/Services/FooTest.php
    ("src/Services/", "tests/Unit/Services/", "Test"),
    # src/Middleware/Foo.php → tests/Unit/Middleware/FooTest.php
    ("src/Middleware/", "tests/Unit/Middleware/", "Test"),
    # src/*.php (top-level src files) → tests/Unit/FooTest.php
    ("src/", "tests/Unit/", "Test"),
]


def get_changed_files(base: str, head: str) -> list[str]:
    """Return list of changed files between base and head."""
    result = subprocess.run(
        ["git", "diff", "--name-only", f"{base}...{head}"],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        print(f"::error::git diff failed: {result.stderr}", file=sys.stderr)
        sys.exit(2)
    return [f for f in result.stdout.strip().splitlines() if f]


def source_to_test_path(src_path: str) -> str | None:
    """Map a src/ file path to its expected test file path."""
    # Normalise to forward slashes
    p = src_path.replace("\\", "/")

    # Skip non-PHP files
    if not p.endswith(".php"):
        return None

    # Skip exempt directories
    for skip in SKIP_DIRS:
        if p.startswith(skip + "/") or p == skip:
            return None

    for src_prefix, test_prefix, suffix in MAPPING_RULES:
        if p.startswith(src_prefix):
            remainder = p[len(src_prefix):]
            # Insert suffix before .php
            test_name = remainder[:-4] + suffix + ".php"
            return test_prefix + test_name

    return None  # no mapping — skip


def is_pr_exempt() -> bool:
    """Check if the PR has the no-test-required label via GH CLI."""
    pr_number = os.environ.get("GH_PR_NUMBER", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if not pr_number or not repo:
        return False

    result = subprocess.run(
        ["gh", "pr", "view", pr_number,
         "--repo", repo,
         "--json", "labels",
         "--jq", "[.labels[].name] | contains([\"no-test-required\"])"],
        capture_output=True, text=True
    )
    if result.returncode == 0 and result.stdout.strip() == "true":
        print("  PR has 'no-test-required' label — exempted from test-touch check.")
        return True
    return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="origin/main")
    parser.add_argument("--head", default="HEAD")
    args = parser.parse_args()

    print(f"=== Test-touch check: {args.base}...{args.head} ===")

    changed = get_changed_files(args.base, args.head)
    print(f"Changed files: {len(changed)}")

    # Check exemption early (saves unnecessary work on exempt PRs)
    if is_pr_exempt():
        sys.exit(0)

    # Identify source files and their expected test paths
    missing = []
    for f in changed:
        expected_test = source_to_test_path(f)
        if expected_test is None:
            continue  # not a trackable source file

        if expected_test in changed:
            print(f"  ✅ {f}  →  {expected_test}")
        else:
            print(f"  ❌ {f}  →  {expected_test}  (MISSING from diff)")
            missing.append((f, expected_test))

    if not missing:
        print("\nAll modified source files have corresponding test changes.")
        sys.exit(0)

    print(f"\n::error::Test-touch check FAILED — {len(missing)} source file(s) modified without touching tests:")
    for src, test in missing:
        print(f"  {src}  →  expected: {test}")
    print("\nOptions:")
    print("  1. Add or update the test file(s) listed above.")
    print("  2. Add the 'no-test-required' label to the PR and comment '/no-test-required: <reason>'.")
    sys.exit(1)


if __name__ == "__main__":
    main()
