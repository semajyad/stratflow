#!/usr/bin/env python3
"""
check_security_notes.py — warn when security-sensitive files are changed
but the PR description has no Security notes section.

Exit 0 always (advisory only — never blocks merge).
Posts a GitHub PR comment if the section is missing.

Usage:
    python3 scripts/ci/check_security_notes.py \
        --base origin/main --head HEAD \
        --pr-body-file /tmp/pr_body.txt
"""

import argparse
import os
import re
import subprocess
import sys

# Paths that warrant a security note when changed
SECURITY_SENSITIVE = [
    r"^src/Controllers/Auth",
    r"^src/Middleware/",
    r"^src/Security/",
    r"^src/Services/Auth",
    r"^src/Services/Billing",
    r"^src/Services/Payment",
    r"^src/Services/Webhook",
    r"^src/Services/Upload",
    r"^src/Services/Email",
    r"^src/Config/routes\.php",
    r"^database/migrations/",
    r"^database/schema\.sql",
    r"^public/index\.php",
]

SECTION_HEADER = "## Security notes"

# If the section body still looks like the untouched template placeholder, treat as missing
PLACEHOLDER_PATTERN = re.compile(
    r"<!--.*?-->",
    re.DOTALL,
)


def get_changed_files(base: str, head: str) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--name-only", f"{base}...{head}"],
        capture_output=True, text=True,
    )
    return [l.strip() for l in result.stdout.splitlines() if l.strip()]


def is_security_sensitive(path: str) -> bool:
    return any(re.match(pattern, path) for pattern in SECURITY_SENSITIVE)


def has_security_notes(body: str) -> bool:
    if SECTION_HEADER not in body:
        return False
    # Find the section content after the header
    idx = body.index(SECTION_HEADER) + len(SECTION_HEADER)
    # Get text until the next ## heading or end of string
    rest = body[idx:]
    next_heading = re.search(r"^##\s", rest, re.MULTILINE)
    section_body = rest[:next_heading.start()] if next_heading else rest
    # Strip HTML comments (template placeholders)
    stripped = PLACEHOLDER_PATTERN.sub("", section_body).strip()
    # Require at least 20 chars of real content
    return len(stripped) >= 20


def post_pr_comment(message: str) -> None:
    pr_number = os.environ.get("GH_PR_NUMBER") or os.environ.get("PR_NUMBER")
    repo = os.environ.get("GITHUB_REPOSITORY")
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if not all([pr_number, repo, token]):
        print(f"  (cannot post comment — missing GH_PR_NUMBER/GITHUB_REPOSITORY/GH_TOKEN)")
        return
    subprocess.run(
        ["gh", "pr", "comment", pr_number, "--body", message, "--repo", repo],
        env={**os.environ, "GH_TOKEN": token},
        capture_output=True,
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="origin/main")
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--pr-body-file", default="")
    args = parser.parse_args()

    # Read PR body
    body = ""
    if args.pr_body_file and os.path.isfile(args.pr_body_file):
        body = open(args.pr_body_file).read()
    elif os.environ.get("PR_BODY"):
        body = os.environ["PR_BODY"]

    # Get changed files
    changed = get_changed_files(args.base, args.head)
    sensitive = [f for f in changed if is_security_sensitive(f)]

    if not sensitive:
        print("check_security_notes: no security-sensitive files changed — skip")
        return 0

    print(f"check_security_notes: {len(sensitive)} security-sensitive file(s) changed:")
    for f in sensitive:
        print(f"  {f}")

    if not body:
        print("  WARNING: no PR body available to check — cannot verify security notes")
        return 0

    if has_security_notes(body):
        print("  ✓ Security notes section present and filled in")
        return 0

    # Missing — warn via output and PR comment
    files_list = "\n".join(f"  - `{f}`" for f in sensitive)
    message = (
        "## ⚠️ Security notes missing\n\n"
        "This PR changes security-sensitive files but the **Security notes** section "
        "in the PR description appears empty or contains only the template placeholder.\n\n"
        f"**Changed files that triggered this:**\n{files_list}\n\n"
        "Please fill in the security notes (3–5 bullets):\n"
        "- What data does this touch, and who should be able to access it?\n"
        "- What is the worst-case abuse by a malicious user or outsider?\n"
        "- What existing controls cover it, and is anything left unguarded?\n\n"
        "_This is advisory — it will not block your PR from merging._"
    )
    print(f"\n::warning::{message.splitlines()[2]}")
    post_pr_comment(message)
    # Exit 0 — advisory only, never blocks merge
    return 0


if __name__ == "__main__":
    sys.exit(main())
