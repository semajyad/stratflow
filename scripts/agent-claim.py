#!/usr/bin/env python3
"""
agent-claim.py — Agent work-claim ledger for concurrent agent safety.

Manages .github/agent-claims.json to track which agent owns which files.
Advisory-only: another agent touching claimed files gets a warning, not a block.

Usage:
  python3 scripts/agent-claim.py claim --agent "claude-sonnet-4-6" \\
      --branch "agent/abc/feature" --patterns "src/Controllers/**" "src/Models/Foo.php"

  python3 scripts/agent-claim.py release --branch "agent/abc/feature"

  python3 scripts/agent-claim.py list

  python3 scripts/agent-claim.py prune   # Remove expired claims (>4h old)

Typical CI usage:
  # At PR open:
  python3 scripts/agent-claim.py claim --agent "$AGENT_ID" --branch "$PR_BRANCH" \
    --patterns $(git diff --name-only origin/main...HEAD | tr '\n' ' ')

  # At PR close:
  python3 scripts/agent-claim.py release --branch "$PR_BRANCH"
"""

import argparse
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

CLAIMS_PATH = Path(".github/agent-claims.json")
TTL_HOURS = 4


def load_claims() -> dict:
    if CLAIMS_PATH.exists():
        try:
            return json.loads(CLAIMS_PATH.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {"claims": []}


def save_claims(data: dict) -> None:
    CLAIMS_PATH.parent.mkdir(parents=True, exist_ok=True)
    CLAIMS_PATH.write_text(
        json.dumps(data, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8"
    )


def prune_expired(claims: list[dict]) -> list[dict]:
    cutoff = datetime.now(timezone.utc) - timedelta(hours=TTL_HOURS)
    active = []
    for c in claims:
        try:
            ts = datetime.fromisoformat(c["claimed_at"].replace("Z", "+00:00"))
            if ts >= cutoff:
                active.append(c)
        except Exception:
            pass  # Drop malformed entries
    return active


def cmd_claim(args: argparse.Namespace) -> int:
    data = load_claims()
    claims = prune_expired(data.get("claims", []))

    # Remove any existing claim for this branch
    claims = [c for c in claims if c.get("branch") != args.branch]

    now = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    claim = {
        "agent": args.agent,
        "branch": args.branch,
        "patterns": args.patterns,
        "claimed_at": now,
    }
    if args.session_id:
        claim["session_id"] = args.session_id

    claims.append(claim)
    data["claims"] = claims
    save_claims(data)
    print(f"Claimed {len(args.patterns)} pattern(s) for {args.agent} on {args.branch}")
    return 0


def cmd_release(args: argparse.Namespace) -> int:
    data = load_claims()
    before = len(data.get("claims", []))
    data["claims"] = [c for c in data.get("claims", []) if c.get("branch") != args.branch]
    released = before - len(data["claims"])
    save_claims(data)
    print(f"Released {released} claim(s) for branch {args.branch}")
    return 0


def cmd_list(args: argparse.Namespace) -> int:
    data = load_claims()
    claims = prune_expired(data.get("claims", []))
    if not claims:
        print("No active claims")
        return 0
    now = datetime.now(timezone.utc)
    for c in claims:
        try:
            ts = datetime.fromisoformat(c["claimed_at"].replace("Z", "+00:00"))
            age_min = int((now - ts).total_seconds() / 60)
        except Exception:
            age_min = -1
        patterns = ", ".join(c.get("patterns", []))
        print(f"  [{age_min}m ago] {c['agent']} ({c['branch']}): {patterns}")
    return 0


def cmd_prune(args: argparse.Namespace) -> int:
    data = load_claims()
    before = len(data.get("claims", []))
    data["claims"] = prune_expired(data.get("claims", []))
    removed = before - len(data["claims"])
    save_claims(data)
    print(f"Pruned {removed} expired claim(s). {len(data['claims'])} active.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Agent work-claim ledger")
    subparsers = parser.add_subparsers(dest="command", required=True)

    claim_p = subparsers.add_parser("claim", help="Claim file patterns for an agent")
    claim_p.add_argument("--agent", required=True, help="Agent identifier")
    claim_p.add_argument("--branch", required=True, help="PR branch name")
    claim_p.add_argument("--patterns", nargs="+", required=True, help="File glob patterns")
    claim_p.add_argument("--session-id", default="", help="Optional session identifier")

    release_p = subparsers.add_parser("release", help="Release claims for a branch")
    release_p.add_argument("--branch", required=True, help="PR branch name to release")

    subparsers.add_parser("list", help="List active claims")
    subparsers.add_parser("prune", help="Remove expired claims")

    args = parser.parse_args()

    if args.command == "claim":
        return cmd_claim(args)
    elif args.command == "release":
        return cmd_release(args)
    elif args.command == "list":
        return cmd_list(args)
    elif args.command == "prune":
        return cmd_prune(args)
    return 1


if __name__ == "__main__":
    sys.exit(main())
