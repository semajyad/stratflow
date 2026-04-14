#!/usr/bin/env python3
"""
triage_nightly.py — StratFlow nightly CI triage aggregator.

Reads all nightly-status.json files from /tmp/status/<job>/nightly-status.json,
classifies results, deduplicates GitHub issues, and writes:
  - triage-report.json  (machine-readable, consumed by morning-summary)
  - triage-report.md    (human-readable, committed to docs/ci-nightly-history.md row)

Usage (called by nightly-triage.yml):
  python3 scripts/ci/triage_nightly.py --status-dir /tmp/status --repo owner/repo
"""

import argparse
import hashlib
import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


EXPECTED_JOBS = [
    "tests", "mutation", "perf", "perf-load",
    "shannon", "snyk", "zap", "e2e", "smoke",
]

# Jobs that only run on specific schedules (not every night)
OPTIONAL_JOBS = {"perf-load"}  # weekly Sun


def load_statuses(status_dir: Path) -> dict:
    """Load all nightly-status.json files from sub-directories."""
    statuses = {}
    for job_dir in status_dir.iterdir():
        json_file = job_dir / "nightly-status.json"
        if json_file.exists():
            try:
                data = json.loads(json_file.read_text())
                job = data.get("job", job_dir.name)
                statuses[job] = data
            except Exception as e:
                print(f"::warning::Failed to parse {json_file}: {e}", file=sys.stderr)
    return statuses


def classify(statuses: dict) -> dict:
    """Classify each job and detect missing required jobs."""
    results = {}
    today_weekday = datetime.now(timezone.utc).weekday()  # 6 = Sunday
    is_sunday = today_weekday == 6

    for job in EXPECTED_JOBS:
        if job in OPTIONAL_JOBS and not is_sunday:
            continue  # skip weekly-only jobs on non-Sunday
        if job in statuses:
            results[job] = statuses[job]
        else:
            # Missing artifact = implicit failure
            results[job] = {
                "job": job,
                "status": "fail",
                "metric": None,
                "findings_url": None,
                "run_id": None,
                "_missing": True,
            }
    return results


def issue_dedup_key(job: str, run_id: str) -> str:
    """Stable dedup key for a failing job run."""
    raw = f"{job}:{run_id or 'unknown'}"
    return hashlib.sha256(raw.encode()).hexdigest()[:12]


def open_github_issue(repo: str, job: str, data: dict, dry_run: bool) -> str | None:
    """Create a GitHub issue for a failing job, deduped by title hash."""
    dedup = issue_dedup_key(job, data.get("run_id", ""))
    title = f"[ci-nightly:{dedup}] {job} failed in nightly regression"

    # Check for existing open issue with this dedup key
    search_result = subprocess.run(
        ["gh", "issue", "list", "--repo", repo,
         "--search", f"[ci-nightly:{dedup}]",
         "--state", "open", "--json", "number", "--limit", "1"],
        capture_output=True, text=True
    )
    if search_result.returncode == 0:
        existing = json.loads(search_result.stdout or "[]")
        if existing:
            issue_num = existing[0]["number"]
            print(f"  Dedup: existing issue #{issue_num} for {job} [{dedup}]")
            return f"https://github.com/{repo}/issues/{issue_num}"

    metric_str = ""
    if data.get("metric"):
        metric_str = "\n\n**Metric:** " + ", ".join(
            f"{k}: {v}" for k, v in data["metric"].items() if v is not None
        )

    missing_note = "\n\n> **Note:** No status artifact was uploaded — the job may have been skipped or timed out." \
        if data.get("_missing") else ""

    run_url = data.get("findings_url") or (
        f"https://github.com/{repo}/actions/runs/{data['run_id']}"
        if data.get("run_id") else "N/A"
    )

    body = f"""## Nightly regression failure: `{job}`

**Date:** {datetime.now(timezone.utc).strftime('%Y-%m-%d')}
**Run:** {run_url}{metric_str}{missing_note}

### Next steps
1. Open the run link above and review the failure output.
2. Fix the underlying issue or update the test/baseline.
3. Close this issue once fixed and verified on the next nightly run.

/cc @semajyad
"""

    cmd = [
        "gh", "issue", "create",
        "--repo", repo,
        "--title", title,
        "--body", body,
        "--label", "ci-nightly,auto-triaged",
    ]

    if dry_run:
        print(f"  DRY RUN: would create issue: {title}")
        return None

    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode == 0:
        url = result.stdout.strip()
        print(f"  Created issue: {url}")
        return url
    else:
        print(f"::warning::Failed to create issue for {job}: {result.stderr}", file=sys.stderr)
        return None


def build_report(results: dict, issue_urls: dict, date_str: str) -> dict:
    """Build the triage-report.json payload."""
    pass_jobs = [j for j, d in results.items() if d["status"] == "pass"]
    fail_jobs = [j for j, d in results.items() if d["status"] == "fail"]
    warn_jobs = [j for j, d in results.items() if d["status"] == "warn"]

    # Extract trend metrics
    metrics = {}
    for job, data in results.items():
        if data.get("metric"):
            metrics[job] = data["metric"]

    return {
        "date": date_str,
        "summary": {
            "pass": len(pass_jobs),
            "fail": len(fail_jobs),
            "warn": len(warn_jobs),
            "pass_jobs": pass_jobs,
            "fail_jobs": fail_jobs,
            "warn_jobs": warn_jobs,
        },
        "metrics": metrics,
        "issue_urls": issue_urls,
        "jobs": results,
    }


def format_ntfy_body(report: dict) -> str:
    """Format a concise ntfy digest body."""
    s = report["summary"]
    total = s["pass"] + s["fail"] + s["warn"]
    lines = [f"StratFlow nightly — {s['fail']} FAIL / {s['pass']} PASS / {total} total"]

    if s["pass_jobs"]:
        lines.append("  ✅ " + ", ".join(s["pass_jobs"]))
    for job in s["fail_jobs"]:
        issue = report["issue_urls"].get(job, "")
        issue_ref = f" → {issue}" if issue else ""
        metric = report["metrics"].get(job)
        metric_str = f" ({', '.join(f'{k}={v}' for k, v in metric.items())})" if metric else ""
        lines.append(f"  ❌ {job}{metric_str}{issue_ref}")
    for job in s["warn_jobs"]:
        metric = report["metrics"].get(job)
        metric_str = f" ({', '.join(f'{k}={v}' for k, v in metric.items())})" if metric else ""
        lines.append(f"  ⚠️  {job}{metric_str}")

    # Trend deltas (format if available)
    if report["metrics"].get("perf", {}).get("p95_ms"):
        lines.append(f"  Δ p95: {report['metrics']['perf']['p95_ms']}ms")
    if report["metrics"].get("mutation", {}).get("msi"):
        lines.append(f"  Δ MSI: {report['metrics']['mutation']['msi']}%")
    if report["metrics"].get("tests", {}).get("coverage"):
        lines.append(f"  Δ cov: {report['metrics']['tests']['coverage']}%")

    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--status-dir", default="/tmp/status", type=Path)
    parser.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--output-dir", default=".", type=Path)
    args = parser.parse_args()

    date_str = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    print(f"=== StratFlow nightly triage — {date_str} ===")

    statuses = load_statuses(args.status_dir)
    print(f"Loaded {len(statuses)} status files: {list(statuses.keys())}")

    results = classify(statuses)

    # Open GitHub issues for failures
    issue_urls: dict = {}
    for job, data in results.items():
        if data["status"] == "fail" and args.repo:
            print(f"Processing failure: {job}")
            url = open_github_issue(args.repo, job, data, args.dry_run)
            if url:
                issue_urls[job] = url

    report = build_report(results, issue_urls, date_str)

    # Write outputs
    output_json = args.output_dir / "triage-report.json"
    output_md = args.output_dir / "triage-report.md"

    output_json.write_text(json.dumps(report, indent=2))
    print(f"Wrote {output_json}")

    s = report["summary"]
    md_lines = [
        f"# Nightly Triage — {date_str}",
        "",
        f"**{s['fail']} FAIL / {s['warn']} WARN / {s['pass']} PASS**",
        "",
        "| Job | Status | Metric | Issue |",
        "|-----|--------|--------|-------|",
    ]
    for job, data in results.items():
        icon = {"pass": "✅", "fail": "❌", "warn": "⚠️"}.get(data["status"], "?")
        metric = ", ".join(f"{k}={v}" for k, v in (data.get("metric") or {}).items() if v is not None)
        issue = f"[#{issue_urls[job].split('/')[-1]}]({issue_urls[job]})" if job in issue_urls else ""
        md_lines.append(f"| {job} | {icon} {data['status']} | {metric} | {issue} |")
    output_md.write_text("\n".join(md_lines) + "\n")
    print(f"Wrote {output_md}")

    # Print ntfy digest to stdout for the workflow to capture
    print("\n--- NTFY BODY ---")
    print(format_ntfy_body(report))
    print("--- END NTFY BODY ---")

    # Exit non-zero if any failures
    if s["fail"] > 0:
        print(f"\n{s['fail']} job(s) failed — triage complete, issues opened.")
        sys.exit(1)
    else:
        print("\nAll jobs passed or warned — no issues opened.")
        sys.exit(0)


if __name__ == "__main__":
    main()
