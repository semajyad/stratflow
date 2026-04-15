"""
check_destructive_migrations.py — detect destructive SQL in database/migrations/.

Flags DROP COLUMN, RENAME COLUMN, DROP TABLE, TRUNCATE TABLE and similar
irreversible operations. These require:

  1. An expand/contract migration strategy (column/table still present when
     rolled back to the previous deploy).
  2. The `safe-migration-reviewed` label on the PR to confirm a human has
     verified backward compatibility.

Exit 1 if destructive statements found AND the label is absent.
Exit 0 otherwise (including if label present).

Usage:
  python3 scripts/ci/check_destructive_migrations.py \
      --base origin/main \
      --head HEAD

Env vars (optional, for PR label check):
  GH_PR_NUMBER      PR number
  GITHUB_REPOSITORY owner/repo
  GH_TOKEN          GitHub token with pull_requests:read
"""

import re
import subprocess
import sys
import os
import json
import urllib.request

# ── Patterns that indicate a destructive/irreversible schema operation ────────
DESTRUCTIVE_PATTERNS = [
    # Column removal / rename
    r"\bDROP\s+COLUMN\b",
    r"\bRENAME\s+COLUMN\b",
    # Table removal / rename / truncation
    r"\bDROP\s+TABLE\b",
    r"\bRENAME\s+TABLE\b",
    r"\bTRUNCATE\s+TABLE\b",
    r"\bTRUNCATE\b",
    # Type narrowing — VARCHAR(500) → VARCHAR(10) etc (can't detect here, skip)
    # Index dropping is fine (non-destructive to data)
]

DESTRUCTIVE_RE = re.compile(
    "|".join(DESTRUCTIVE_PATTERNS),
    re.IGNORECASE | re.MULTILINE,
)

SAFE_MIGRATION_LABEL = "safe-migration-reviewed"


def changed_migration_files(base: str, head: str) -> list[str]:
    """Return added/modified files under database/migrations/ vs base."""
    result = subprocess.run(
        ["git", "diff", "--name-only", "--diff-filter=AM", f"{base}...{head}",
         "--", "database/migrations/"],
        capture_output=True, text=True, check=True,
    )
    return [f.strip() for f in result.stdout.splitlines() if f.strip()]


def scan_file(path: str) -> list[tuple[int, str]]:
    """Return list of (line_number, line) for destructive statements."""
    hits: list[tuple[int, str]] = []
    with open(path, encoding="utf-8") as fh:
        for i, line in enumerate(fh, 1):
            if DESTRUCTIVE_RE.search(line):
                hits.append((i, line.rstrip()))
    return hits


def has_safe_label() -> bool:
    """Check if the PR has the safe-migration-reviewed label via GitHub API."""
    pr_number = os.environ.get("GH_PR_NUMBER")
    repo = os.environ.get("GITHUB_REPOSITORY")
    token = os.environ.get("GH_TOKEN")

    if not (pr_number and repo and token):
        return False

    url = f"https://api.github.com/repos/{repo}/pulls/{pr_number}"
    req = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github+json",
    })
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read())
        labels = [lbl["name"] for lbl in data.get("labels", [])]
        return SAFE_MIGRATION_LABEL in labels
    except Exception:
        return False


def main() -> int:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="origin/main")
    parser.add_argument("--head", default="HEAD")
    args = parser.parse_args()

    files = changed_migration_files(args.base, args.head)
    if not files:
        print("No new migration files — nothing to check.")
        return 0

    findings: dict[str, list[tuple[int, str]]] = {}
    for f in files:
        hits = scan_file(f)
        if hits:
            findings[f] = hits

    if not findings:
        print(f"Scanned {len(files)} migration file(s) — no destructive statements found.")
        return 0

    print("::warning::Destructive migration statement(s) detected:\n")
    for path, hits in findings.items():
        for line_no, line in hits:
            print(f"  {path}:{line_no}: {line}")

    print()
    print("These operations cannot be automatically rolled back if Railway auto-rollback fires.")
    print("Required actions before merging:")
    print("  1. Use expand/contract strategy: keep old column/table until next deploy cycle.")
    print("  2. Verify the code at LAST_GOOD_DEPLOY_ID still works with the new schema.")
    print(f"  3. Add the '{SAFE_MIGRATION_LABEL}' label to this PR to confirm.")
    print("  See docs/DEPLOYMENT.md §  'Database migration strategy'.")
    print()

    if has_safe_label():
        print(f"Label '{SAFE_MIGRATION_LABEL}' is present — proceeding with human sign-off.")
        return 0

    print(f"::error::Label '{SAFE_MIGRATION_LABEL}' is missing. Add it after confirming backward compatibility.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
