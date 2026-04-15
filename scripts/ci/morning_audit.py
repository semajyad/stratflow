#!/usr/bin/env python3
"""
morning_audit.py — StratFlow morning CI audit (local dev box).

Mirrors the pattern from Sentinel's autonomous/morning_audit.py.
Fetches the latest nightly-triage report from GitHub Actions,
prints a terminal summary, and sends an ntfy ping.

Run via Windows Task Scheduler at 18:00 NZT (06:00 UTC):
  python scripts/ci/morning_audit.py

Environment variables (or .env):
  NTFY_URL      e.g. http://localhost:8090
  NTFY_TOPIC    e.g. stratflow-ci
  GITHUB_TOKEN  personal access token with repo/actions scope
"""

import json
import os
import subprocess
import sys
import textwrap
from datetime import datetime, timezone
from pathlib import Path


REPO = "semajyad/stratflow"
TRIAGE_WORKFLOW = "nightly-triage.yml"


def load_env():
    """Load .env file from repo root if present."""
    env_path = Path(__file__).parent.parent.parent / ".env"
    if env_path.exists():
        for line in env_path.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                os.environ.setdefault(k.strip(), v.strip())


def fetch_latest_triage() -> dict | None:
    """Download the latest nightly-triage run artifact and parse it."""
    print("Fetching latest nightly-triage run...")
    result = subprocess.run(
        ["gh", "run", "list",
         "--repo", REPO,
         "--workflow", TRIAGE_WORKFLOW,
         "--limit", "1",
         "--json", "databaseId,status,conclusion,createdAt"],
        capture_output=True, text=True
    )
    if result.returncode != 0 or not result.stdout.strip():
        print(f"  ERROR: gh run list failed: {result.stderr}", file=sys.stderr)
        return None

    runs = json.loads(result.stdout)
    if not runs:
        print("  No nightly-triage runs found.")
        return None

    run = runs[0]
    run_id = run["databaseId"]
    conclusion = run.get("conclusion", "unknown")
    created_at = run.get("createdAt", "")
    print(f"  Run {run_id} — {conclusion} — {created_at}")

    # Download triage-report artifact
    tmp_dir = Path("/tmp/stratflow-morning-audit")
    tmp_dir.mkdir(exist_ok=True)
    dl = subprocess.run(
        ["gh", "run", "download", str(run_id),
         "--repo", REPO,
         "--name", "triage-report",
         "--dir", str(tmp_dir)],
        capture_output=True, text=True
    )
    if dl.returncode != 0:
        print(f"  WARNING: Could not download triage-report artifact: {dl.stderr}", file=sys.stderr)
        return None

    report_file = tmp_dir / "triage-report.json"
    if not report_file.exists():
        print("  WARNING: triage-report.json not found in artifact.", file=sys.stderr)
        return None

    return json.loads(report_file.read_text())


def send_ntfy(title: str, body: str, priority: str = "default", tags: str = "ci"):
    """Send ntfy notification."""
    import urllib.request
    import urllib.error

    url = os.environ.get("NTFY_URL", "http://localhost:8090")
    topic = os.environ.get("NTFY_TOPIC", "stratflow-ci")

    req = urllib.request.Request(
        f"{url}/{topic}",
        data=body.encode(),
        headers={
            "Title": title,
            "Priority": priority,
            "Tags": tags,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=5):
            print(f"  ntfy sent: {title}")
    except urllib.error.URLError as e:
        print(f"  ntfy failed (non-fatal): {e}", file=sys.stderr)


def print_report(report: dict):
    """Print a formatted terminal summary."""
    s = report["summary"]
    date = report.get("date", "?")
    total = s["pass"] + s["fail"] + s["warn"]

    print()
    print("=" * 60)
    print(f"  StratFlow Nightly Audit — {date}")
    print(f"  {s['fail']} FAIL  {s['warn']} WARN  {s['pass']} PASS  ({total} total)")
    print("=" * 60)

    for job, data in report.get("jobs", {}).items():
        icon = {"pass": "✅", "fail": "❌", "warn": "⚠️"}.get(data["status"], "?")
        metric = ""
        if data.get("metric"):
            metric = "  [" + ", ".join(
                f"{k}={v}" for k, v in data["metric"].items() if v is not None
            ) + "]"
        issue_url = report.get("issue_urls", {}).get(job, "")
        issue_ref = f"\n      → {issue_url}" if issue_url else ""
        print(f"  {icon}  {job:<16}{metric}{issue_ref}")

    print()
    if s["fail"]:
        print(f"  ACTION REQUIRED: {s['fail']} failure(s). Fix before starting feature work.")
    else:
        print("  All nightly checks passed. Good to ship.")
    print("=" * 60)
    print()


def build_ntfy_body(report: dict) -> tuple[str, str, str]:
    """Returns (title, body, priority)."""
    s = report["summary"]
    date = report.get("date", "?")

    if s["fail"]:
        title = f"StratFlow nightly: {s['fail']} FAIL [{date}]"
        priority = "high"
    elif s["warn"]:
        title = f"StratFlow nightly: {s['warn']} WARN [{date}]"
        priority = "default"
    else:
        title = f"StratFlow nightly: all pass [{date}]"
        priority = "low"

    lines = [title]
    if s["pass_jobs"]:
        lines.append("✅ " + ", ".join(s["pass_jobs"]))
    for job in s["fail_jobs"]:
        issue = report.get("issue_urls", {}).get(job, "")
        metric = report.get("metrics", {}).get(job)
        m = f" ({', '.join(f'{k}={v}' for k,v in metric.items())})" if metric else ""
        lines.append(f"❌ {job}{m}" + (f" → {issue}" if issue else ""))
    for job in s["warn_jobs"]:
        lines.append(f"⚠️  {job}")

    return title, "\n".join(lines), priority


def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--no-ntfy", action="store_true",
                        help="Skip ntfy notification (used by session-start hook)")
    args = parser.parse_args()

    load_env()
    print(f"=== StratFlow morning audit — {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')} ===")

    report = fetch_latest_triage()
    if not report:
        print("Could not fetch triage report. Check GitHub Actions manually.")
        if not args.no_ntfy:
            send_ntfy(
                "StratFlow morning audit: FETCH FAILED",
                "Could not download nightly-triage report. Check GH Actions.",
                priority="high",
                tags="warning,ci",
            )
        sys.exit(1)

    print_report(report)

    if not args.no_ntfy:
        title, body, priority = build_ntfy_body(report)
        tags = "warning,ci" if report["summary"]["fail"] else "ci"
        send_ntfy(title, body, priority=priority, tags=tags)

    sys.exit(1 if report["summary"]["fail"] else 0)


if __name__ == "__main__":
    main()
