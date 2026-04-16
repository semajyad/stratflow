#!/usr/bin/env python3
"""
check_test_touches.py — Enforce two test discipline rules:

1. SOURCE-TOUCH RULE: Every modified src/*.php file must have a corresponding
   test file touched in the same PR.

2. BUG-TEST RULE: Any commit whose message starts with `fix:` must include
   at least one test file change. Bugs must be accompanied by a regression
   test that proves the fix works and won't regress.

Exit 0: all rules pass (or PR is exempt).
Exit 1: one or more rules violated.

Usage:
  python3 scripts/ci/check_test_touches.py --base origin/main --head HEAD

Exemption:
  Add `no-test-required` label to the PR AND include a PR comment with
  the text `/no-test-required: <reason>`. This script checks for the
  label via the GITHUB_TOKEN + GH_PR_NUMBER env vars.
"""

import argparse
import os
import re
import subprocess
import sys


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

# Commit subject prefixes that trigger the bug-test rule
BUG_COMMIT_PREFIXES = re.compile(r"^fix(\([^)]+\))?!?:", re.IGNORECASE)


def get_changed_files(base: str, head: str) -> list[str]:
    """Return list of non-deleted changed files between base and head."""
    result = subprocess.run(
        ["git", "diff", "--name-status", f"{base}...{head}"],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        print(f"::error::git diff failed: {result.stderr}", file=sys.stderr)
        sys.exit(2)
    files = []
    for line in result.stdout.strip().splitlines():
        if not line:
            continue
        parts = line.split("\t", 1)
        if len(parts) == 2:
            status, path = parts
            if not status.startswith("D"):   # exclude deleted files
                files.append(path.strip())
    return files


def get_commits_in_range(base: str, head: str) -> list[tuple[str, str]]:
    """Return list of (sha, subject) for commits in base..head."""
    result = subprocess.run(
        ["git", "log", "--format=%H %s", f"{base}..{head}"],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        return []
    commits = []
    for line in result.stdout.strip().splitlines():
        if line:
            sha, _, subject = line.partition(" ")
            commits.append((sha, subject))
    return commits


def get_files_in_commit(sha: str) -> list[str]:
    """Return list of non-deleted files changed in a single commit."""
    result = subprocess.run(
        ["git", "diff-tree", "--no-commit-id", "-r", "--name-status", sha],
        capture_output=True, text=True
    )
    files = []
    for line in result.stdout.strip().splitlines():
        if not line:
            continue
        parts = line.split("\t", 1)
        if len(parts) == 2:
            status, path = parts
            if not status.startswith("D"):   # exclude deleted files
                files.append(path.strip())
    return files


def get_commits_in_range(base: str, head: str) -> list[tuple[str, str]]:
    """Return list of (sha, subject) for commits in base..head."""
    result = subprocess.run(
        ["git", "log", "--format=%H %s", f"{base}..{head}"],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        return []
    commits = []
    for line in result.stdout.strip().splitlines():
        if line:
            sha, _, subject = line.partition(" ")
            commits.append((sha, subject))
    return commits


def get_files_in_commit(sha: str) -> list[str]:
    """Return list of files changed in a single commit."""
    result = subprocess.run(
        ["git", "diff-tree", "--no-commit-id", "-r", "--name-only", sha],
        capture_output=True, text=True
    )
    return [f for f in result.stdout.strip().splitlines() if f]


def source_to_test_path(src_path: str) -> str | None:
    """Map a src/ file path to its expected test file path."""
    p = src_path.replace("\\", "/")

    if not p.endswith(".php"):
        return None

    for skip in SKIP_DIRS:
        if p.startswith(skip + "/") or p == skip:
            return None

    for src_prefix, test_prefix, suffix in MAPPING_RULES:
        if p.startswith(src_prefix):
            remainder = p[len(src_prefix):]
            test_name = remainder[:-4] + suffix + ".php"
            return test_prefix + test_name

    return None


def is_test_file(path: str) -> bool:
    """Return True if the path is a test file."""
    p = path.replace("\\", "/")
    return p.startswith("tests/") and p.endswith(".php")


def is_pr_exempt() -> bool:
    """
    Check if the PR is exempt: requires BOTH the no-test-required label AND
    a PR comment starting with '/no-test-required:' (the audit trail comment).
    """
    pr_number = os.environ.get("GH_PR_NUMBER", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if not pr_number or not repo:
        return False

    result = subprocess.run(
        ["gh", "pr", "view", pr_number,
         "--repo", repo,
         "--json", "labels,comments"],
        capture_output=True, text=True
    )
    if result.returncode != 0 or not result.stdout.strip():
        return False

    import json as _json
    try:
        data = _json.loads(result.stdout)
    except ValueError:
        return False

    label_names = [lbl["name"] for lbl in data.get("labels", [])]
    if "no-test-required" not in label_names:
        return False

    # Also require an audit-trail comment starting with /no-test-required:
    audit_comment = None
    for comment in data.get("comments", []):
        body = comment.get("body", "")
        if body.startswith("/no-test-required:"):
            audit_comment = body
            break

    if audit_comment is None:
        print(
            "  PR has 'no-test-required' label but no '/no-test-required: <reason>' "
            "comment — exemption denied. Add a comment with the reason.",
            file=sys.stderr,
        )
        return False

    reason = audit_comment[len("/no-test-required:"):].strip()
    print(f"  PR exempt — no-test-required label + audit comment: '{reason}'")
    return True


def check_source_touch_rule(changed: list[str]) -> list[tuple[str, str]]:
    """Return (src_file, expected_test_path) for each src file changed without a test change."""
    changed_set = {f.replace("\\", "/") for f in changed}
    missing = []
    for f in changed:
        f_norm = f.replace("\\", "/")
        test_path = source_to_test_path(f_norm)
        if test_path is None:
            continue  # not a mappable src/ PHP file
        if test_path not in changed_set:
            missing.append((f_norm, test_path))
    return missing


def check_bug_test_rule(base: str, head: str) -> list[tuple[str, str]]:
    """Return (sha, subject) for fix: commits that include no test file changes."""
    commits = get_commits_in_range(base, head)
    violations = []
    for sha, subject in commits:
        if not BUG_COMMIT_PREFIXES.match(subject):
            continue
        files = get_files_in_commit(sha)
        if not any(is_test_file(f) for f in files):
            violations.append((sha[:8], subject))
    return violations


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="origin/main")
    parser.add_argument("--head", default="HEAD")
    args = parser.parse_args()

    print(f"=== Test discipline check: {args.base}...{args.head} ===\n")

    changed = get_changed_files(args.base, args.head)
    print(f"Changed files: {len(changed)}")

    if is_pr_exempt():
        sys.exit(0)

    all_failures = False

    # ── Rule 1: Source-touch ──────────────────────────────────────────────────
    print("\n── Rule 1: Source-touch (every src change needs a test change) ──")
    missing_tests = check_source_touch_rule(changed)
    if missing_tests:
        all_failures = True
        print(f"\n  ✗ {len(missing_tests)} source file(s) modified without test changes:")
        for src, test in missing_tests:
            print(f"      {src}  →  expected: {test}")
    else:
        print("  All modified source files have corresponding test changes. ✅")

    # ── Rule 2: Bug-test ──────────────────────────────────────────────────────
    print("\n── Rule 2: Bug-test (every fix: commit must include a test) ──")
    bug_violations = check_bug_test_rule(args.base, args.head)
    if bug_violations:
        all_failures = True
        print(f"\n  ✗ {len(bug_violations)} fix commit(s) without test changes:")
        for sha, subject in bug_violations:
            print(f"      {sha}: {subject}")
        print("\n  Every bug fix must include a regression test that would have")
        print("  caught the bug. Add or extend a test file in tests/ to prove")
        print("  the fix works and won't regress.")
    else:
        print("  All fix: commits include test changes. ✅")

    if all_failures:
        print("\n── How to fix ────────────────────────────────────────────────────")
        print("  Rule 1: Add or update the test file(s) listed above.")
        print("  Rule 2: Add a regression test to any test file under tests/.")
        print("  Exempt: Add the 'no-test-required' label to the PR and comment")
        print("          '/no-test-required: <reason>' (use sparingly).")
        sys.exit(1)

    print("\n✅ All test discipline checks passed.")
    sys.exit(0)


if __name__ == "__main__":
    main()
