#!/usr/bin/env -S python3 -S
"""PreCompact hook: inject the security regression checklist into the
compaction prompt so it survives Claude Code's auto-compaction.

When Claude compacts the conversation, the security rules from CLAUDE.md
are frequently lost from working memory. This hook prepends a compact
summary of the five non-negotiable checks to the compaction context.
"""
import json
import sys

SECURITY_CHECKLIST = """
## StratFlow Security Checklist (survive compaction)

Before committing any change to src/, templates/, or public/, verify:
1. Every new response path calls Response::applySecurityHeaders() — including error branches.
2. No new template output uses `echo $var` without htmlspecialchars($var, ENT_QUOTES, 'UTF-8').
3. No external script tag lacks an integrity="sha384-..." attribute.
4. No template uses filemtime() or a Unix timestamp as a cache-buster — use ASSET_VERSION.
5. No new binary file committed without an entry in .gitattributes.

Also: no 'unsafe-inline' in script-src, no SQL string interpolation, no missing org_id filter,
no missing CSRF (verifyCsrf()) on POST handlers, no hardcoded secrets.
""".strip()


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        # No input — output the preservation block for the compaction prompt
        print(SECURITY_CHECKLIST)
        return 0

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        print(SECURITY_CHECKLIST)
        return 0

    # Prepend to whatever summary the compaction engine provides
    summary = payload.get("summary", "")
    combined = SECURITY_CHECKLIST + "\n\n" + summary if summary else SECURITY_CHECKLIST
    print(combined)
    return 0


if __name__ == "__main__":
    sys.exit(main())
