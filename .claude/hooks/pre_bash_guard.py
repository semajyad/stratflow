#!/usr/bin/env -S python3 -S
"""PreToolUse guard: block destructive Bash commands in StratFlow.

Reads the tool invocation JSON from stdin, matches the command against
blocked patterns, exits 2 (block + surface stderr) on a match.
"""
import json
import re
import sys

BLOCKED = [
    (r"\bdocker\s+compose\s+down\s+.*-v\b",
     "`docker compose down -v` wipes the MySQL volume. Use `docker compose stop` instead."),
    (r"\bdrop\s+database\b",
     "DROP DATABASE is destructive. Run manually if truly intended."),
    (r"\bdrop\s+table\b",
     "DROP TABLE is destructive. Use a reversible migration instead."),
    (r"\btruncate\s+table\b",
     "TRUNCATE is destructive. Use a scoped DELETE instead."),
    (r"\brm\s+-rf?\s+/",
     "Rooted recursive delete. Refused."),
    (r"\brm\s+-rf?\s+.*\.env\b",
     "Deleting .env would remove live Stripe and Gemini credentials."),
    (r"\bgit\s+push\s+.*--force\b",
     "Force push. Run manually outside Claude if truly intended."),
    (r"\bgit\s+push\s+.*\s-f\b",
     "Force push (-f). Run manually outside Claude if truly intended."),
    (r"\bgit\s+reset\s+--hard\b",
     "`git reset --hard` discards uncommitted work. Investigate first."),
    (r"\bgit\s+clean\s+-[a-z]*f",
     "`git clean -f` deletes untracked files."),
    (r"--dangerously-skip-permissions",
     "--dangerously-skip-permissions is forbidden (live Stripe/Gemini keys in .env)."),
    (r"\bcurl\s+[^|;&]*api\.stripe\.com\b(?!.*sk_test)",
     "Direct call to api.stripe.com without a test key. Use sk_test_... only."),
]


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0
    command = (payload.get("tool_input") or {}).get("command") or ""
    if not isinstance(command, str) or not command:
        return 0
    for pattern, reason in BLOCKED:
        if re.search(pattern, command, re.IGNORECASE):
            print(f"[pre-bash-guard] BLOCKED: {reason}", file=sys.stderr)
            print(f"[pre-bash-guard] Command: {command}", file=sys.stderr)
            return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
