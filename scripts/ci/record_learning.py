#!/usr/bin/env python3
"""
CLI tool to append a structured learning entry to the appropriate learnings file.

Usage:
    python scripts/ci/record_learning.py \\
        --category ci \\
        --title "Some failure" \\
        --symptom "What failed" \\
        --root-cause "Why it happened" \\
        --fix "What was done" \\
        --prevention "What prevents recurrence" \\
        [--follow-up "Remaining work"] \\
        [--pr 42]
"""

import argparse
import sys
from datetime import date
from pathlib import Path

# ===== Constants =====

REPO_ROOT = Path(__file__).resolve().parents[2]

CATEGORY_FILES = {
    "ci": REPO_ROOT / "docs" / "ci-learnings.md",
    "security": REPO_ROOT / "docs" / "security-learnings.md",
    "tests": REPO_ROOT / "docs" / "test-learnings.md",
    "quality": REPO_ROOT / "docs" / "ci-learnings.md",  # quality goes into ci file
}


# ===== Entry formatting =====

def format_entry(
    category: str,
    title: str,
    symptom: str,
    root_cause: str,
    fix: str,
    prevention: str,
    follow_up: str,
    pr: int | None,
) -> str:
    """Format a learning entry in the standard markdown block format."""
    today = date.today().strftime("%Y-%m-%d")
    pr_ref = f"#{pr}" if pr else "N/A"

    lines = [
        f"## [{today}] {category} — {title}",
        "",
        f"**Symptom:** {symptom}",
        "",
        f"**Root cause:** {root_cause}",
        "",
        f"**Fix applied:** {fix}",
        "",
        f"**Prevention:** {prevention}",
        "",
        f"**Follow-up:** {follow_up if follow_up else 'None.'}",
        "",
        f"**PR/Commit:** {pr_ref}",
    ]
    return "\n".join(lines)


# ===== File append =====

def append_to_file(path: Path, entry: str) -> None:
    """Append an entry to the learnings file, separated by a horizontal rule."""
    if not path.exists():
        print(f"Error: target file not found: {path}", file=sys.stderr)
        sys.exit(1)

    existing = path.read_text(encoding="utf-8")

    # Ensure file ends with a newline before we append
    separator = "\n\n---\n\n" if not existing.endswith("\n\n---\n\n") else ""
    with path.open("a", encoding="utf-8") as f:
        f.write(f"{separator}{entry}\n")


# ===== CLI =====

def build_parser() -> argparse.ArgumentParser:
    """Build the argument parser."""
    p = argparse.ArgumentParser(
        description="Record a CI/CD learning entry to the appropriate learnings file.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    p.add_argument(
        "--category",
        required=True,
        choices=list(CATEGORY_FILES.keys()),
        help="Category of the learning (determines which file is updated)",
    )
    p.add_argument("--title", required=True, help="Short descriptive title")
    p.add_argument("--symptom", required=True, help="What failed or was observed")
    p.add_argument("--root-cause", required=True, help="Why it happened")
    p.add_argument("--fix", required=True, help="What was done to resolve it")
    p.add_argument("--prevention", required=True, help="What now prevents recurrence")
    p.add_argument("--follow-up", default="", help="Remaining work or improvements triggered")
    p.add_argument("--pr", type=int, default=None, help="PR number or omit for SHA reference")
    return p


def main() -> None:
    """Entry point."""
    parser = build_parser()
    args = parser.parse_args()

    target_file = CATEGORY_FILES[args.category]

    entry = format_entry(
        category=args.category,
        title=args.title,
        symptom=args.symptom,
        root_cause=args.root_cause,
        fix=args.fix,
        prevention=args.prevention,
        follow_up=args.follow_up,
        pr=args.pr,
    )

    append_to_file(target_file, entry)

    print(f"Learning recorded in {target_file.relative_to(REPO_ROOT)}")


if __name__ == "__main__":
    main()
