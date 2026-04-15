#!/usr/bin/env python3
"""
manage_flake_quarantine.py — Track and manage flaky Playwright tests.

Reads the Playwright JSON report, records any tests that passed on retry
(flaky) to quarantine.jsonl, and auto-opens GitHub issues for tests that
have flaked 3 or more times in the last 7 days.

Usage:
  # Record flakes from a test run:
  python3 scripts/ci/manage_flake_quarantine.py record \
    --report tests/Playwright/test-results.json \
    --quarantine tests/Playwright/quarantine.jsonl \
    --commit-sha <sha>

  # List currently quarantined tests:
  python3 scripts/ci/manage_flake_quarantine.py list \
    --quarantine tests/Playwright/quarantine.jsonl

  # Prune entries older than --days (default 30):
  python3 scripts/ci/manage_flake_quarantine.py prune \
    --quarantine tests/Playwright/quarantine.jsonl \
    --days 30

The quarantine.jsonl format (one JSON object per line):
  {
    "test": "Auth > login with valid credentials",
    "file": "tests/Playwright/fast/auth.spec.js",
    "commit_sha": "abc1234",
    "timestamp": "2026-04-15T05:00:00Z",
    "run_id": "12345678"
  }
"""

import argparse
import json
import os
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path


def load_quarantine(path: Path) -> list[dict]:
    """Load all quarantine entries from the JSONL file."""
    if not path.exists():
        return []
    entries = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line:
            try:
                entries.append(json.loads(line))
            except json.JSONDecodeError:
                pass
    return entries


def save_quarantine(path: Path, entries: list[dict]) -> None:
    """Write quarantine entries back to JSONL file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        for entry in entries:
            f.write(json.dumps(entry, separators=(",", ":")) + "\n")


def record_flakes(args: argparse.Namespace) -> int:
    """Record flaky tests from a Playwright JSON report."""
    report_path = Path(args.report)
    quarantine_path = Path(args.quarantine)

    if not report_path.exists():
        print(f"Report not found: {report_path}")
        return 0

    try:
        report = json.loads(report_path.read_text(encoding="utf-8"))
    except Exception as e:
        print(f"Could not parse report: {e}")
        return 1

    flaky_tests: list[dict] = []

    def walk_suites(suites, file_path=""):
        for suite in suites:
            current_file = suite.get("file", file_path)
            for spec in suite.get("specs", []):
                for test in spec.get("tests", []):
                    if test.get("status") == "flaky":
                        flaky_tests.append({
                            "test": spec.get("title", "unknown"),
                            "file": current_file,
                        })
            walk_suites(suite.get("suites", []), current_file)

    walk_suites(report.get("suites", []))

    if not flaky_tests:
        print("No flaky tests detected")
        return 0

    now = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    run_id = os.environ.get("GITHUB_RUN_ID", "local")
    commit_sha = args.commit_sha or os.environ.get("GITHUB_SHA", "unknown")

    existing = load_quarantine(quarantine_path)
    new_entries = []

    for test in flaky_tests:
        entry = {
            "test": test["test"],
            "file": test["file"],
            "commit_sha": commit_sha,
            "timestamp": now,
            "run_id": run_id,
        }
        print(f"Recording flake: {test['test']}")
        new_entries.append(entry)

    all_entries = existing + new_entries
    save_quarantine(quarantine_path, all_entries)
    print(f"Recorded {len(new_entries)} flake(s) to {quarantine_path}")

    # Check if any test has flaked ≥3 times in the last 7 days
    cutoff = datetime.now(timezone.utc) - timedelta(days=7)
    flake_counts: dict[str, int] = {}
    for entry in all_entries:
        ts_str = entry.get("timestamp", "")
        try:
            ts = datetime.fromisoformat(ts_str.replace("Z", "+00:00"))
        except ValueError:
            continue
        if ts >= cutoff:
            key = entry["test"]
            flake_counts[key] = flake_counts.get(key, 0) + 1

    repo = os.environ.get("GITHUB_REPOSITORY", "")
    gh_token = os.environ.get("GH_TOKEN", os.environ.get("GITHUB_TOKEN", ""))

    for test_name, count in flake_counts.items():
        if count >= 3:
            print(f"::warning::'{test_name}' has flaked {count} times in 7 days — opening issue")
            if repo and gh_token:
                _open_flake_issue(test_name, count, repo)

    return 0


def _open_flake_issue(test_name: str, count: int, repo: str) -> None:
    """Open a GitHub issue for a chronically flaky test (deduped by title)."""
    import hashlib
    title_hash = hashlib.sha256(test_name.encode()).hexdigest()[:12]
    issue_title = f"[flaky-test:{title_hash}] Playwright test flaked {count}x in 7 days"

    # Check for existing open issue
    result = subprocess.run(
        ["gh", "issue", "list", "--repo", repo,
         "--search", f"[flaky-test:{title_hash}]", "--state", "open",
         "--json", "number"],
        capture_output=True, text=True
    )
    if result.returncode == 0:
        issues = json.loads(result.stdout or "[]")
        if issues:
            print(f"Issue already open for '{test_name}' (#{issues[0]['number']})")
            return

    body = (
        f"## Flaky Playwright test\n\n"
        f"**Test:** `{test_name}`\n"
        f"**Flake count (last 7 days):** {count}\n\n"
        f"This test was automatically quarantined by the flake-management system.\n"
        f"It will be skipped on PR runs until this issue is resolved and the test\n"
        f"is removed from `tests/Playwright/quarantine.jsonl`.\n\n"
        f"### Steps to resolve\n"
        f"1. Reproduce the flake locally with `--repeat-each=20`.\n"
        f"2. Fix the root cause (usually a missing `waitFor`, race condition, or test isolation issue).\n"
        f"3. Remove the entry from `quarantine.jsonl` and close this issue.\n"
    )

    subprocess.run(
        ["gh", "issue", "create", "--repo", repo,
         "--title", issue_title,
         "--body", body,
         "--label", "flaky-test"],
        capture_output=True
    )


def list_quarantined(args: argparse.Namespace) -> int:
    """List currently quarantined tests."""
    quarantine_path = Path(args.quarantine)
    entries = load_quarantine(quarantine_path)

    if not entries:
        print("No quarantined tests")
        return 0

    cutoff = datetime.now(timezone.utc) - timedelta(days=args.days)
    recent = [e for e in entries if
              datetime.fromisoformat(e.get("timestamp", "2000-01-01T00:00:00Z")
                                     .replace("Z", "+00:00")) >= cutoff]

    print(f"Quarantine entries (last {args.days} days): {len(recent)}")
    for e in recent:
        print(f"  [{e['timestamp'][:10]}] {e['test']} ({e['file']})")
    return 0


def prune_old(args: argparse.Namespace) -> int:
    """Remove quarantine entries older than --days."""
    quarantine_path = Path(args.quarantine)
    entries = load_quarantine(quarantine_path)
    cutoff = datetime.now(timezone.utc) - timedelta(days=args.days)

    kept = [e for e in entries if
            datetime.fromisoformat(e.get("timestamp", "2000-01-01T00:00:00Z")
                                   .replace("Z", "+00:00")) >= cutoff]

    removed = len(entries) - len(kept)
    save_quarantine(quarantine_path, kept)
    print(f"Pruned {removed} entries older than {args.days} days. {len(kept)} remaining.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Manage Playwright flake quarantine")
    subparsers = parser.add_subparsers(dest="command", required=True)

    record_p = subparsers.add_parser("record", help="Record flakes from a test run")
    record_p.add_argument("--report", required=True, help="Path to Playwright JSON report")
    record_p.add_argument("--quarantine", required=True, help="Path to quarantine.jsonl")
    record_p.add_argument("--commit-sha", default="", help="Commit SHA for this run")

    list_p = subparsers.add_parser("list", help="List quarantined tests")
    list_p.add_argument("--quarantine", required=True, help="Path to quarantine.jsonl")
    list_p.add_argument("--days", type=int, default=7, help="Show entries from last N days")

    prune_p = subparsers.add_parser("prune", help="Remove old quarantine entries")
    prune_p.add_argument("--quarantine", required=True, help="Path to quarantine.jsonl")
    prune_p.add_argument("--days", type=int, default=30, help="Keep entries from last N days")

    args = parser.parse_args()

    if args.command == "record":
        return record_flakes(args)
    elif args.command == "list":
        return list_quarantined(args)
    elif args.command == "prune":
        return prune_old(args)
    return 1


if __name__ == "__main__":
    sys.exit(main())
