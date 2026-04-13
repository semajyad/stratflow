#!/usr/bin/env -S python3 -S
"""PreToolUse: rewrite test commands to show only failures.

Large test runs can emit 10,000+ lines of output that burn tokens when
Claude reads them back. This hook detects PHPUnit/Composer test invocations
and rewrites them to grep only for failures and tracebacks, keeping the
context footprint tiny while still surfacing everything that matters.

Output contract (per Claude Code docs):
    {
      "hookSpecificOutput": {
        "hookEventName": "PreToolUse",
        "permissionDecision": "allow",
        "updatedInput": { "command": "<filtered command>" }
      }
    }

Skipped (pass-through) when:
  - The command already contains a pipe to head/tail/grep
  - The command explicitly requests verbose output (-v, --verbose, --debug)
  - The command contains `2>&1` (user is already managing its stderr)
  - The env var CLAUDE_CODE_NO_TEST_FILTER is set (escape hatch)
"""
import json
import os
import re
import sys

# Commands that look like test runs
TEST_PATTERNS = [
    r"\bvendor/bin/phpunit\b",
    r"\bphpunit\b",
    r"\bcomposer\s+(?:run\s+)?test\b",
]

# Filter pipeline: keep only lines near failures, cap at 100 lines
FILTER = (
    r" 2>&1 | grep -E -A 5 "
    r"'(FAIL|ERROR|Failed|Error|Exception|Traceback|at .+:\d+|Tests:|Assertions:)' "
    r"| head -n 100"
)


def should_pass_through(cmd: str) -> bool:
    if os.environ.get("CLAUDE_CODE_NO_TEST_FILTER"):
        return True
    # Already piped/redirected — don't interfere
    if "|" in cmd or "2>&1" in cmd or ">" in cmd:
        return True
    # User explicitly asked for verbose output
    if re.search(r"(\s|^)(-v|-vv|-vvv|--verbose|--debug)(\s|$)", cmd):
        return True
    return False


def is_test_command(cmd: str) -> bool:
    return any(re.search(p, cmd) for p in TEST_PATTERNS)


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0

    cmd = (payload.get("tool_input") or {}).get("command") or ""
    if not isinstance(cmd, str) or not cmd:
        return 0

    if not is_test_command(cmd) or should_pass_through(cmd):
        return 0

    filtered = cmd + FILTER
    out = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "allow",
            "updatedInput": {"command": filtered},
        }
    }
    print(json.dumps(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
