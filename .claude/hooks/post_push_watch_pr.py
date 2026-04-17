"""
Post-push hook: rewakes Claude after git push to invoke the watch-pr skill.
Exits 2 (asyncRewake trigger) only when the completed Bash command was a git push.
"""

import json
import sys


def main() -> None:
    try:
        data = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        sys.exit(0)

    cmd = data.get("tool_input", {}).get("command", "")
    # Match git push as a standalone command, not as a substring of other text.
    # Handles: "git push", "git push origin branch", "git push -u origin ..."
    import re
    if not re.search(r'\bgit\s+push\b', cmd):
        sys.exit(0)

    print(
        "You just ran git push. "
        "MANDATORY: Immediately invoke the watch-pr skill. "
        "Watch the PR until ALL CI checks pass. "
        "Investigate and fix any failures before reporting the work as done. "
        "Do not ask the user — just do it."
    )
    sys.exit(2)


if __name__ == "__main__":
    main()
