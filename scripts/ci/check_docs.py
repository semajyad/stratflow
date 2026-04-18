#!/usr/bin/env python3
"""
check_docs.py — enforce that documentation is updated alongside source changes.

Blocks commits/PRs when tracked source files change without the corresponding
documentation files being updated in the same changeset.

Rules
-----
  routes.php changed          →  docs/API.md must change
  database/migrations/*.sql   →  docs/DATABASE.md must change
  database/schema.sql         →  docs/DATABASE.md must change
  .env.example changed        →  CONTRIBUTING.md must change
  docker-compose.yml changed  →  README.md must change
  NEW src/Controllers/*.php   →  docs/ARCHITECTURE.md must change
  NEW src/Models/*.php        →  docs/ARCHITECTURE.md must change
  NEW src/Services/*.php      →  docs/ARCHITECTURE.md must change
  NEW src/Middleware/*.php     →  docs/ARCHITECTURE.md must change
  NEW src/Services/Prompts/*.php  →  docs/GEMINI_PROMPTS.md must change

"New file" means the file was Added (A) in git status — not Modified (M).
Modifications to existing controllers/models are allowed without an
architecture update; the doc tracks the existence of the class, not its methods.

Modes
-----
  CI mode (default):
      python3 scripts/ci/check_docs.py --base origin/main --head HEAD

  Pre-commit mode (reads staged index):
      python3 scripts/ci/check_docs.py --staged

Exit codes
----------
  0  All doc requirements satisfied (or no trigger files changed)
  1  One or more required doc files are missing from the changeset
"""

import argparse
import os
import re
import subprocess
import sys
from dataclasses import dataclass, field

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

# ---------------------------------------------------------------------------
# Rules
# ---------------------------------------------------------------------------

@dataclass
class Rule:
    """One documentation requirement."""
    description: str            # human-readable trigger description
    doc_file: str               # doc that must also be changed
    trigger_pattern: str        # regex matched against file path
    new_files_only: bool = False  # only trigger on Added files, not Modified


RULES: list[Rule] = [
    Rule(
        description="src/Config/routes.php changed",
        doc_file="docs/API.md",
        trigger_pattern=r"^src/Config/routes\.php$",
    ),
    Rule(
        description="database migration added/modified",
        doc_file="docs/DATABASE.md",
        trigger_pattern=r"^database/migrations/.*\.sql$",
    ),
    Rule(
        description="database/schema.sql changed",
        doc_file="docs/DATABASE.md",
        trigger_pattern=r"^database/schema\.sql$",
    ),
    Rule(
        description=".env.example changed",
        doc_file="CONTRIBUTING.md",
        trigger_pattern=r"^\.env\.example$",
    ),
    Rule(
        description="docker-compose.yml changed",
        doc_file="README.md",
        trigger_pattern=r"^docker-compose(\.override)?\.yml$",
    ),
    Rule(
        description="new Controller added",
        doc_file="docs/ARCHITECTURE.md",
        trigger_pattern=r"^src/Controllers/[^/]+\.php$",
        new_files_only=True,
    ),
    Rule(
        description="new Model added",
        doc_file="docs/ARCHITECTURE.md",
        trigger_pattern=r"^src/Models/[^/]+\.php$",
        new_files_only=True,
    ),
    Rule(
        description="new Service added",
        doc_file="docs/ARCHITECTURE.md",
        trigger_pattern=r"^src/Services/[^/]+\.php$",
        new_files_only=True,
    ),
    Rule(
        description="new Middleware added",
        doc_file="docs/ARCHITECTURE.md",
        trigger_pattern=r"^src/Middleware/[^/]+\.php$",
        new_files_only=True,
    ),
    Rule(
        description="new AI Prompt class added",
        doc_file="docs/GEMINI_PROMPTS.md",
        trigger_pattern=r"^src/Services/Prompts/[^/]+\.php$",
        new_files_only=True,
    ),
]

# ---------------------------------------------------------------------------
# Git helpers
# ---------------------------------------------------------------------------

def get_staged_changes() -> list[tuple[str, str]]:
    """Return [(status, path)] for the staged index.
    Status is 'A' (added), 'M' (modified), 'D' (deleted), 'R' (renamed), etc.
    """
    result = subprocess.run(
        ["git", "diff", "--cached", "--name-status"],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        print(f"check_docs: git diff --cached failed: {result.stderr.strip()}", file=sys.stderr)
        sys.exit(1)
    return _parse_name_status(result.stdout)


def get_diff_changes(base: str, head: str) -> list[tuple[str, str]]:
    """Return [(status, path)] for files changed between base and head."""
    result = subprocess.run(
        ["git", "diff", "--name-status", f"{base}...{head}"],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        print(f"check_docs: git diff failed: {result.stderr.strip()}", file=sys.stderr)
        sys.exit(1)
    return _parse_name_status(result.stdout)


def _parse_name_status(output: str) -> list[tuple[str, str]]:
    """Parse 'git diff --name-status' output into [(status, path)] pairs."""
    entries = []
    for line in output.splitlines():
        line = line.strip()
        if not line:
            continue
        parts = line.split("\t", 1)
        if len(parts) == 2:
            status = parts[0][0]  # first char: A/M/D/R/C
            path = parts[1].strip()
            # Renamed: "R100\told_path\tnew_path" — take the new path
            if status == "R" and "\t" in path:
                path = path.split("\t", 1)[1].strip()
            entries.append((status, path))
    return entries

# ---------------------------------------------------------------------------
# Core check
# ---------------------------------------------------------------------------

@dataclass
class Violation:
    rule: Rule
    triggering_files: list[str]


def check_changes(changes: list[tuple[str, str]]) -> list[Violation]:
    """Evaluate rules against a list of (status, path) changes.
    Returns a list of Violation objects for any missing doc updates.
    """
    # Build sets for quick lookup
    all_paths: set[str] = {path for _, path in changes}
    added_paths: set[str] = {path for status, path in changes if status == "A"}

    violations: list[Violation] = []

    for rule in RULES:
        # Find files that match the trigger pattern
        if rule.new_files_only:
            candidates = [p for p in added_paths if re.match(rule.trigger_pattern, p)]
        else:
            candidates = [p for _, p in changes if re.match(rule.trigger_pattern, p)]

        if not candidates:
            continue  # Rule not triggered

        # Doc must also be in the changeset (added or modified — not deleted)
        if rule.doc_file in all_paths and any(
            path == rule.doc_file and status != "D"
            for status, path in changes
        ):
            continue  # Satisfied

        violations.append(Violation(rule=rule, triggering_files=candidates))

    return violations

# ---------------------------------------------------------------------------
# Output / PR comment
# ---------------------------------------------------------------------------

def format_cli_output(violations: list[Violation]) -> str:
    lines = ["check_docs: ❌ Documentation not updated\n"]
    lines.append("The following source changes require documentation updates:\n")
    for v in violations:
        lines.append(f"  Rule   : {v.rule.description}")
        lines.append(f"  Doc    : {v.rule.doc_file}  ← must be updated")
        lines.append(f"  Files  : {', '.join(v.triggering_files)}")
        lines.append("")
    lines.append("Update the listed docs in the same commit and re-run.")
    if os.environ.get("SKIP_DOCS_CHECK") == "1":
        lines.append("\n  (hint: SKIP_DOCS_CHECK=1 to bypass in an emergency)")
    return "\n".join(lines)


def format_pr_comment(violations: list[Violation]) -> str:
    rows = []
    for v in violations:
        files_str = ", ".join(f"`{f}`" for f in v.triggering_files[:3])
        if len(v.triggering_files) > 3:
            files_str += f" (+{len(v.triggering_files) - 3} more)"
        rows.append(f"| {v.rule.description} | `{v.rule.doc_file}` | {files_str} |")

    table = "\n".join(rows)
    return (
        "## 📄 Documentation out of sync\n\n"
        "This PR changes source files that require documentation updates, "
        "but the corresponding doc files were not modified.\n\n"
        "| Trigger | Doc to update | Changed files |\n"
        "|---------|---------------|---------------|\n"
        f"{table}\n\n"
        "Please update the listed documentation files in this PR before merging.\n\n"
        "_Run `python3 scripts/ci/check_docs.py --staged` locally to verify._"
    )


def post_pr_comment(message: str) -> None:
    pr_number = os.environ.get("GH_PR_NUMBER") or os.environ.get("PR_NUMBER")
    repo = os.environ.get("GITHUB_REPOSITORY")
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if not all([pr_number, repo, token]):
        print("  (cannot post PR comment — missing GH_PR_NUMBER/GITHUB_REPOSITORY/GH_TOKEN)")
        return
    result = subprocess.run(
        ["gh", "pr", "comment", pr_number, "--body", message, "--repo", repo],
        env={**os.environ, "GH_TOKEN": token},
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        print(f"  (PR comment failed: {result.stderr.strip()})")

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    if os.environ.get("SKIP_DOCS_CHECK") == "1":
        print("check_docs: SKIP_DOCS_CHECK=1 — bypassed")
        return 0

    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base", default="origin/main", help="Base ref for CI diff (default: origin/main)")
    parser.add_argument("--head", default="HEAD", help="Head ref for CI diff (default: HEAD)")
    parser.add_argument("--staged", action="store_true", help="Pre-commit mode: check staged index instead of diff range")
    parser.add_argument("--post-comment", action="store_true", help="Post a GitHub PR comment on failure (CI only)")
    args = parser.parse_args()

    if args.staged:
        changes = get_staged_changes()
        mode = "pre-commit (staged)"
    else:
        changes = get_diff_changes(args.base, args.head)
        mode = f"CI ({args.base}...{args.head})"

    if not changes:
        print(f"check_docs: no changes detected [{mode}] — skip")
        return 0

    violations = check_changes(changes)

    if not violations:
        print(f"check_docs: ✓ all documentation requirements satisfied [{mode}]")
        return 0

    print(format_cli_output(violations))

    if args.post_comment:
        post_pr_comment(format_pr_comment(violations))

    # Emit GitHub Actions annotations for each violation
    if os.environ.get("GITHUB_ACTIONS"):
        for v in violations:
            print(f"::error file={v.rule.doc_file}::{v.rule.description} — {v.rule.doc_file} must be updated")

    return 1


if __name__ == "__main__":
    sys.exit(main())
