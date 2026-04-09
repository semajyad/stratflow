#!/usr/bin/env python3
"""SessionStart: verify StratFlow .env exists and has required keys."""
import os
import re
import sys

REQUIRED = {
    "GEMINI_API_KEY":        "Gemini AI will not work",
    "STRIPE_SECRET_KEY":     "Stripe checkout will not work",
    "STRIPE_WEBHOOK_SECRET": "Stripe webhook verification will not work",
    "DB_HOST":               "Database connection will fail",
    "DB_DATABASE":           "Database connection will fail",
    "DB_USERNAME":           "Database connection will fail",
}


def main() -> int:
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    env_path = os.path.join(project_dir, ".env")

    if not os.path.isfile(env_path):
        print("[session-start] .env is missing — copy .env.example to .env.")
        return 0

    try:
        content = open(env_path, encoding="utf-8").read()
    except OSError as e:
        print(f"[session-start] could not read .env: {e}", file=sys.stderr)
        return 0

    warnings = [
        f"Missing {k} ({impact})"
        for k, impact in REQUIRED.items()
        if not re.search(rf"^{re.escape(k)}=.+", content, re.MULTILINE)
    ]

    if warnings:
        print("[session-start] StratFlow .env warnings:")
        for w in warnings:
            print(f"  - {w}")
    else:
        print("[session-start] StratFlow .env looks healthy.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
