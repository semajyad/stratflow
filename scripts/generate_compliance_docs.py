#!/usr/bin/env python3
"""StratFlow compliance documentation generator.

Orchestrates all compliance agents on a schedule:
  - Weekly:  dependency-auditor (CVE scan → ntfy)
  - Monthly: security-assessment-report + performance-report-generator
  - Quarterly: caiq-responder + security-policy-pack

Designed to run via Windows Task Scheduler. Each agent runs headlessly
via claude CLI and outputs dated documents to docs/compliance/.

Usage:
  python scripts/generate_compliance_docs.py --mode weekly
  python scripts/generate_compliance_docs.py --mode monthly
  python scripts/generate_compliance_docs.py --mode quarterly
  python scripts/generate_compliance_docs.py --mode all
  python scripts/generate_compliance_docs.py --mode security-report
  python scripts/generate_compliance_docs.py --mode performance-report
  python scripts/generate_compliance_docs.py --mode caiq
"""

import argparse
import logging
import os
import subprocess
import sys
from datetime import datetime

# ===== CONFIG =====

REPO = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))

# Claude CLI — Windows Store install isn't on PATH for subprocesses; find latest version dir
def _find_claude_bin() -> str:
    base = os.path.expandvars(r"%LOCALAPPDATA%\Packages\Claude_pzs8sxrjxfjjc\LocalCache\Roaming\Claude\claude-code")
    if os.path.isdir(base):
        versions = sorted(os.listdir(base), reverse=True)
        for v in versions:
            candidate = os.path.join(base, v, "claude.exe")
            if os.path.isfile(candidate):
                return candidate
    return "claude"  # fallback: hope it's on PATH

CLAUDE_BIN = _find_claude_bin()
ENV_FILE = os.path.join(REPO, "..", ".env")  # Sentinel .env, one dir up

LOG_DIR = os.path.join(REPO, "logs")
os.makedirs(LOG_DIR, exist_ok=True)
os.makedirs(os.path.join(REPO, "docs", "compliance"), exist_ok=True)

log_file = os.path.join(LOG_DIR, f"compliance_{datetime.now().strftime('%Y%m%d_%H%M')}.log")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(log_file), logging.StreamHandler()],
)
logger = logging.getLogger(__name__)

NTFY_URL = "http://localhost:8090/james_homelab_alerts_2026"

# Load .env for ANTHROPIC_API_KEY
env = dict(os.environ)
if os.path.exists(ENV_FILE):
    with open(ENV_FILE) as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip()


# ===== HELPERS =====

def run_claude_agent(agent_name: str, prompt: str, timeout: int = 600) -> bool:
    """Run a Claude agent headlessly. Returns True on success."""
    if not env.get("ANTHROPIC_API_KEY"):
        logger.error("ANTHROPIC_API_KEY not set — cannot run claude")
        return False

    logger.info(f"Running agent: {agent_name}")
    result = subprocess.run(
        [
            CLAUDE_BIN, "--print",
            "--allowedTools", "Bash,Read,Write,Glob,Grep",
            "--max-turns", "25",
            "-p", prompt,
        ],
        cwd=REPO,
        env=env,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    if result.returncode != 0:
        logger.error(f"{agent_name} failed (exit {result.returncode}): {result.stderr[:300]}")
        return False
    logger.info(f"{agent_name} completed successfully")
    return True


def ntfy_post(title: str, body: str, priority: str = "default"):
    """Post notification to ntfy."""
    try:
        import requests
        requests.post(
            NTFY_URL,
            data=body.encode(),
            headers={"X-Title": title, "X-Priority": priority},
            timeout=10,
        )
    except Exception as e:
        logger.warning(f"ntfy post failed: {e}")


def latest_report(subdir: str, prefix: str) -> str | None:
    """Return path of most recent report file."""
    comp_dir = os.path.join(REPO, "docs", "compliance", subdir) if subdir else os.path.join(REPO, "docs", "compliance")
    if not os.path.isdir(comp_dir):
        return None
    matches = sorted(
        [f for f in os.listdir(comp_dir) if f.startswith(prefix) and f.endswith(".md")],
        reverse=True,
    )
    return os.path.join(comp_dir, matches[0]) if matches else None


# ===== TASKS =====

def run_dependency_audit():
    """Weekly: CVE scan via composer audit → ntfy."""
    logger.info("=== Weekly: Dependency audit ===")
    ok = run_claude_agent(
        "dependency-auditor",
        "Use the dependency-auditor agent to scan StratFlow's Composer and npm dependencies "
        "for CVEs and post results to ntfy.",
        timeout=300,
    )
    if not ok:
        ntfy_post("Compliance: Dependency Audit FAILED", "dependency-auditor agent failed. Check logs.", "high")


def run_security_report():
    """Monthly: Full security assessment report."""
    logger.info("=== Monthly: Security assessment report ===")
    ok = run_claude_agent(
        "security-report-generator",
        "Use the security-report-generator agent to run a full security assessment of StratFlow "
        "and save the dated report to docs/compliance/.",
        timeout=900,
    )
    report = latest_report("", "security-assessment-")
    if ok and report:
        ntfy_post(
            "Compliance: Security Report Ready",
            f"Monthly security assessment complete.\nReport: {os.path.basename(report)}\n"
            "Review at: stratflow/docs/compliance/",
        )
    elif not ok:
        ntfy_post("Compliance: Security Report FAILED", "security-report-generator failed. Check logs.", "high")


def run_performance_report():
    """Monthly: Performance testing and report."""
    logger.info("=== Monthly: Performance report ===")
    ok = run_claude_agent(
        "performance-report-generator",
        "Use the performance-report-generator agent to run k6 load tests against StratFlow "
        "and produce a formal performance report in docs/compliance/.",
        timeout=900,
    )
    report = latest_report("", "performance-report-")
    if ok and report:
        ntfy_post(
            "Compliance: Performance Report Ready",
            f"Monthly performance test complete.\nReport: {os.path.basename(report)}",
        )
    elif not ok:
        ntfy_post("Compliance: Performance Report FAILED", "performance-report-generator failed. Check logs.", "high")


def run_caiq():
    """Quarterly: CAIQ questionnaire response."""
    logger.info("=== Quarterly: CAIQ response ===")
    ok = run_claude_agent(
        "caiq-responder",
        "Use the caiq-responder agent to generate an updated CAIQ v4 questionnaire response "
        "for StratFlow based on the current codebase and save to docs/compliance/.",
        timeout=1200,
    )
    if ok:
        ntfy_post("Compliance: CAIQ Response Updated", "Quarterly CAIQ update complete. Review before sending to clients.")
    else:
        ntfy_post("Compliance: CAIQ Update FAILED", "caiq-responder agent failed. Check logs.", "high")


def run_policy_pack():
    """Quarterly: Security policy documents."""
    logger.info("=== Quarterly: Security policy pack ===")
    ok = run_claude_agent(
        "security-policy-pack",
        "Use the security-policy-pack skill to generate or update all 5 security policy "
        "documents in docs/compliance/policies/.",
        timeout=600,
    )
    if not ok:
        ntfy_post("Compliance: Policy Pack FAILED", "security-policy-pack failed. Check logs.", "high")


# ===== MAIN =====

TASKS = {
    "weekly":           [run_dependency_audit],
    "monthly":          [run_security_report, run_performance_report],
    "quarterly":        [run_caiq, run_policy_pack],
    "all":              [run_dependency_audit, run_security_report, run_performance_report, run_caiq, run_policy_pack],
    "security-report":  [run_security_report],
    "performance-report": [run_performance_report],
    "caiq":             [run_caiq],
    "dependency-audit": [run_dependency_audit],
    "policy-pack":      [run_policy_pack],
}


def main():
    parser = argparse.ArgumentParser(description="StratFlow compliance documentation generator")
    parser.add_argument(
        "--mode",
        choices=list(TASKS.keys()),
        default="weekly",
        help="Which tasks to run",
    )
    args = parser.parse_args()

    logger.info(f"Compliance run starting — mode: {args.mode}")
    tasks = TASKS[args.mode]
    failures = 0

    for task in tasks:
        try:
            task()
        except Exception as e:
            logger.error(f"Task {task.__name__} raised exception: {e}")
            failures += 1

    if failures:
        logger.error(f"{failures} task(s) failed")
        sys.exit(1)
    else:
        logger.info("All compliance tasks completed successfully")


if __name__ == "__main__":
    main()
