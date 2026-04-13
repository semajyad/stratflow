#!/usr/bin/env -S python3 -S
"""PostToolUse: enforce StratFlow security rules on every PHP/HTML write.

Checks the written file for four non-negotiable ZAP-sourced rules from
CLAUDE.md (lines 50-88). Returns exit 2 with an actionable message if any
violation is found, blocking the edit from being accepted silently.

Rules checked:
  ZAP-10055  — script-src must not contain 'unsafe-inline'
  ZAP-10096  — templates must not use filemtime() or time() as cache-busters
  ZAP-10055  — external <script src=...> tags must include integrity= attribute
  ZAP-10031  — template echo of $var must be wrapped with htmlspecialchars()

Only runs on .php and .html file edits.
"""
import json
import os
import re
import sys


RULES = [
    {
        "id": "ZAP-10055-unsafe-inline",
        "pattern": re.compile(r"script-src[^'\"\n]*'unsafe-inline'"),
        "path_filter": None,  # Any PHP/HTML file
        "message": (
            "SECURITY VIOLATION ZAP-10055: 'unsafe-inline' added to script-src.\n"
            "All JS must be in bundled files under /assets/js/. Remove unsafe-inline."
        ),
    },
    {
        "id": "ZAP-10096-filemtime",
        "pattern": re.compile(r"\bfilemtime\s*\(|\btime\s*\(\s*\)"),
        "path_filter": re.compile(r"templates/"),
        "message": (
            "SECURITY VIOLATION ZAP-10096: filemtime() / time() used as asset cache-buster in a template.\n"
            "Use the ASSET_VERSION constant instead."
        ),
    },
    {
        "id": "ZAP-10055-sri",
        "pattern": re.compile(r'<script\s[^>]*src=["\'][^"\']*://[^>]*(?<!integrity=)[^>]*>'),
        "path_filter": None,
        "message": (
            "SECURITY VIOLATION ZAP-10055 SRI: External <script src=...> is missing integrity= attribute.\n"
            "Add integrity=\"sha384-...\" and crossorigin=\"anonymous\" to the tag."
        ),
    },
    {
        "id": "ZAP-10031-xss",
        "pattern": re.compile(r"echo\s+\$(?!this\b)\w+(?!\s*[;,)].*htmlspecialchars)"),
        "path_filter": re.compile(r"templates/"),
        "message": (
            "SECURITY VIOLATION ZAP-10031: echo $var in a template without htmlspecialchars().\n"
            "Wrap every echoed value: htmlspecialchars($var, ENT_QUOTES, 'UTF-8')."
        ),
    },
]


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0

    file_path = (payload.get("tool_input") or {}).get("file_path") or ""
    if not isinstance(file_path, str):
        return 0

    lower = file_path.lower()
    if not (lower.endswith(".php") or lower.endswith(".html")):
        return 0

    if not os.path.isfile(file_path):
        return 0

    try:
        content = open(file_path, encoding="utf-8", errors="replace").read()
    except OSError:
        return 0

    # Normalise path separators for filter matching
    norm_path = file_path.replace("\\", "/")

    violations = []
    for rule in RULES:
        if rule["path_filter"] and not rule["path_filter"].search(norm_path):
            continue
        if rule["pattern"].search(content):
            violations.append(rule)

    if not violations:
        return 0

    print(f"[post-edit-security-rules] {len(violations)} violation(s) found in {os.path.basename(file_path)}:", file=sys.stderr)
    for v in violations:
        print("", file=sys.stderr)
        print(f"  [{v['id']}] {v['message']}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Fix the violation(s) before proceeding. See stratflow/CLAUDE.md §Security Rules.", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
