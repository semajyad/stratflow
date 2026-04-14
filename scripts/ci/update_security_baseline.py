#!/usr/bin/env python3
"""
update_security_baseline.py — Update a committed security baseline file.

Downloads the latest scan artifact from GitHub Actions and promotes it as
the new baseline. Run this when knowingly accepting existing findings so
that future CI only alerts on *new* findings above the baseline.

Usage:
  python3 scripts/ci/update_security_baseline.py zap
  python3 scripts/ci/update_security_baseline.py snyk
  python3 scripts/ci/update_security_baseline.py shannon

The updated baseline is written to tests/security/baseline-<scan>.json.
Commit and push the result in a PR with an explanation of why the finding
is accepted.
"""

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path
from datetime import datetime, timezone


WORKFLOW_MAP = {
    "zap":     "security-zap.yml",
    "snyk":    "snyk.yml",
    "shannon": "security-shannon.yml",
}

REPO = os.environ.get("GITHUB_REPOSITORY", "semajyad/stratflow")
BASELINE_DIR = Path("tests/security")


def fetch_latest_status(scan: str) -> dict | None:
    """Download the latest nightly-status artifact for the scan."""
    workflow = WORKFLOW_MAP[scan]
    result = subprocess.run(
        ["gh", "run", "list",
         "--repo", REPO,
         "--workflow", workflow,
         "--limit", "1",
         "--json", "databaseId,conclusion,createdAt"],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        print(f"ERROR: gh run list failed: {result.stderr}", file=sys.stderr)
        return None

    runs = json.loads(result.stdout or "[]")
    if not runs:
        print(f"No runs found for {workflow}")
        return None

    run = runs[0]
    run_id = run["databaseId"]
    print(f"Latest {scan} run: {run_id} ({run.get('conclusion','?')}) @ {run.get('createdAt','?')}")

    tmp = Path(f"/tmp/baseline-{scan}")
    tmp.mkdir(exist_ok=True)

    dl = subprocess.run(
        ["gh", "run", "download", str(run_id),
         "--repo", REPO,
         "--name", f"nightly-status-{scan}",
         "--dir", str(tmp)],
        capture_output=True, text=True
    )
    if dl.returncode != 0:
        print(f"WARNING: Could not download artifact: {dl.stderr}", file=sys.stderr)
        return None

    status_file = tmp / "nightly-status.json"
    if not status_file.exists():
        print("WARNING: nightly-status.json not in artifact", file=sys.stderr)
        return None

    return json.loads(status_file.read_text())


def main():
    parser = argparse.ArgumentParser(
        description="Promote current scan findings as the accepted security baseline"
    )
    parser.add_argument("scan", choices=list(WORKFLOW_MAP.keys()),
                        help="Which scan to update: zap, snyk, or shannon")
    parser.add_argument("--reason", default="",
                        help="Brief reason for accepting these findings")
    args = parser.parse_args()

    print(f"=== Updating {args.scan} baseline ===")
    status = fetch_latest_status(args.scan)
    if not status:
        print("Could not fetch scan status. Aborting.")
        sys.exit(1)

    BASELINE_DIR.mkdir(parents=True, exist_ok=True)
    baseline_path = BASELINE_DIR / f"baseline-{args.scan}.json"

    baseline = {
        "scan": args.scan,
        "accepted_at": datetime.now(timezone.utc).isoformat(),
        "accepted_run_id": status.get("run_id"),
        "reason": args.reason or "(none given)",
        "metric": status.get("metric"),
    }

    baseline_path.write_text(json.dumps(baseline, indent=2))
    print(f"Written: {baseline_path}")
    print(json.dumps(baseline, indent=2))
    print()
    print("Next steps:")
    print(f"  git add {baseline_path}")
    print(f"  git commit -m 'chore: accept {args.scan} baseline — {args.reason or 'see findings'}'")
    print("  git push && create PR with justification")


if __name__ == "__main__":
    main()
