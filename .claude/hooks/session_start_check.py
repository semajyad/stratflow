#!/usr/bin/env -S python3 -S
"""SessionStart: verify StratFlow .env, surface security report freshness,
and flag any un-applied database migrations.

Checks (all read-only, no side effects):
  1. .env key presence
  2. Shannon pen-test report freshness (>24 h → stale warning)
  3. ZAP baseline report freshness (>24 h → stale warning)
  4. HIGH / MEDIUM finding count in both reports
  5. SQL migration files newer than .claude/.last-migration marker
"""
import os
import re
import sys
from datetime import datetime, timezone, timedelta
from pathlib import Path


REQUIRED_ENV = {
    "GEMINI_API_KEY":        "Gemini AI will not work",
    "STRIPE_SECRET_KEY":     "Stripe checkout will not work",
    "STRIPE_WEBHOOK_SECRET": "Stripe webhook verification will not work",
    "DB_HOST":               "Database connection will fail",
    "DB_DATABASE":           "Database connection will fail",
    "DB_USERNAME":           "Database connection will fail",
}

REPORT_STALE_HOURS = 24


def check_env(project_dir: str) -> list[str]:
    env_path = os.path.join(project_dir, ".env")
    if not os.path.isfile(env_path):
        return ["[session-start] .env is missing — copy .env.example to .env."]
    try:
        content = open(env_path, encoding="utf-8").read()
    except OSError as e:
        return [f"[session-start] could not read .env: {e}"]
    warnings = [
        f"  Missing {k} ({impact})"
        for k, impact in REQUIRED_ENV.items()
        if not re.search(rf"^{re.escape(k)}=.+", content, re.MULTILINE)
    ]
    if warnings:
        return ["[session-start] .env warnings:"] + warnings
    return ["[session-start] .env OK"]


def check_security_report(report_path: str, label: str) -> list[str]:
    """Return freshness and finding-count lines for a security report."""
    lines_out = []
    if not os.path.isfile(report_path):
        lines_out.append(f"  [{label}] report missing — scan may not have run yet")
        return lines_out

    # Parse run timestamp from HTML comment at top of file
    try:
        head = open(report_path, encoding="utf-8", errors="replace").read(512)
    except OSError:
        lines_out.append(f"  [{label}] could not read report")
        return lines_out

    run_time = None
    m = re.search(r"<!-- (?:Shannon|ZAP) run: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) UTC", head)
    if m:
        try:
            run_time = datetime.strptime(m.group(1), "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc)
        except ValueError:
            pass

    if run_time:
        age_h = (datetime.now(timezone.utc) - run_time).total_seconds() / 3600
        freshness = f"{age_h:.0f}h ago"
        stale = age_h > REPORT_STALE_HOURS
        flag = "⚠️ STALE" if stale else "✓"
        lines_out.append(f"  [{label}] last run: {freshness} {flag}")
    else:
        lines_out.append(f"  [{label}] run timestamp not found in report header")

    # Count HIGH / MEDIUM headings
    try:
        content = open(report_path, encoding="utf-8", errors="replace").read()
    except OSError:
        return lines_out

    high_count = len(re.findall(r"^#+\s+HIGH\b", content, re.MULTILINE | re.IGNORECASE))
    medium_count = len(re.findall(r"^#+\s+MEDIUM\b", content, re.MULTILINE | re.IGNORECASE))

    if high_count or medium_count:
        lines_out.append(f"  [{label}] ⚠️  {high_count} HIGH, {medium_count} MEDIUM findings — address before other work")
    else:
        lines_out.append(f"  [{label}] no HIGH/MEDIUM findings")

    return lines_out


def check_migrations(project_dir: str) -> list[str]:
    """List migration files newer than the .last-migration marker."""
    marker_path = os.path.join(project_dir, ".claude", ".last-migration")
    migrations_dir = Path(project_dir) / "database" / "migrations"

    if not migrations_dir.is_dir():
        return []

    if os.path.isfile(marker_path):
        marker_mtime = os.path.getmtime(marker_path)
    else:
        marker_mtime = 0  # No marker → all migrations are "new"

    new_files = sorted(
        f.name for f in migrations_dir.glob("*.sql")
        if f.stat().st_mtime > marker_mtime
    )

    if not new_files:
        return ["  [migrations] up to date"]

    lines = [f"  [migrations] ⚠️  {len(new_files)} unapplied migration(s):"]
    for name in new_files:
        lines.append(f"    - {name}")
    lines.append("    Run them with: docker compose exec php php scripts/migrate.php")
    return lines


def check_nightly_ci(project_dir: str) -> list[str]:
    """Run morning_audit.py once per calendar day and surface any nightly failures.

    On failure outputs a MANDATORY directive so Claude fixes everything before
    starting other work. Skips if already run today (marker file in .claude/).
    """
    import subprocess

    marker = os.path.join(project_dir, ".claude", ".last-nightly-audit")
    today = datetime.now().strftime("%Y-%m-%d")

    if os.path.isfile(marker):
        try:
            if open(marker).read().strip() == today:
                return []  # already ran this calendar day
        except OSError:
            pass

    script = os.path.join(project_dir, "scripts", "ci", "morning_audit.py")
    if not os.path.isfile(script):
        return ["  [nightly-ci] morning_audit.py not found"]

    try:
        result = subprocess.run(
            [sys.executable, script, "--no-ntfy"],
            capture_output=True, text=True, timeout=45, cwd=project_dir,
        )
        # Record that we ran today regardless of outcome
        try:
            open(marker, "w").write(today)
        except OSError:
            pass

        lines = [l for l in result.stdout.strip().splitlines() if l.strip()]

        if result.returncode == 0:
            return ["[session-start] Nightly CI: all pass ✅"]

        # Failures — output the report AND a hard mandate
        output = ["[session-start] Nightly CI: FAILURES DETECTED ❌"]
        output += [f"  {l}" for l in lines]
        output += [
            "",
            "  !! MANDATORY — fix all nightly CI failures before any other work !!",
            "  1. git checkout -b fix/nightly-" + today,
            "  2. Investigate and fix each failing job above",
            "  3. gh pr create, wait for green CI, merge",
            "  Do NOT start user-requested tasks until every failure is resolved.",
            "  Open ci-nightly issues are linked in the report above.",
        ]
        return output

    except subprocess.TimeoutExpired:
        return ["  [nightly-ci] morning_audit.py timed out — check GH Actions manually"]
    except Exception as e:
        return [f"  [nightly-ci] error: {e}"]


def check_quality_health(project_dir: str) -> list[str]:
    """Run a quick status query via docker compose and report quality scoring health."""
    import subprocess
    lines_out = []

    try:
        result = subprocess.run(
            [
                "docker", "compose", "exec", "-T", "mysql",
                "mysql", "-u", "stratflow", "-pstratflow_secret", "stratflow",
                "-N", "-e",
                "SELECT quality_status, COUNT(*) FROM user_stories GROUP BY quality_status"
                " UNION ALL "
                "SELECT CONCAT('wi_', quality_status), COUNT(*) FROM hl_work_items GROUP BY quality_status;",
            ],
            capture_output=True,
            text=True,
            timeout=8,
            cwd=project_dir,
        )
        if result.returncode != 0:
            lines_out.append("  [quality] docker compose exec failed (stack may be down)")
            return lines_out

        counts: dict[str, int] = {}
        for line in result.stdout.strip().splitlines():
            parts = line.split("\t")
            if len(parts) == 2:
                counts[parts[0].strip()] = int(parts[1].strip())

        story_total = sum(v for k, v in counts.items() if not k.startswith("wi_"))
        story_failed = counts.get("failed", 0)
        story_pending = counts.get("pending", 0)
        story_scored = counts.get("scored", 0)

        wi_total = sum(v for k, v in counts.items() if k.startswith("wi_"))
        wi_failed = counts.get("wi_failed", 0)

        total_failed = story_failed + wi_failed
        grand_total = story_total + wi_total

        if grand_total == 0:
            lines_out.append("  [quality] no rows found")
            return lines_out

        pct_failed = total_failed / grand_total * 100

        badge = "⚠️ HIGH FAILURE RATE — investigate GEMINI_API_KEY" if pct_failed > 10 else "✓"
        lines_out.append(
            f"  [quality] stories: {story_scored} scored / {story_pending} pending / {story_failed} failed"
            f"  work-items: {wi_failed} failed  {badge}"
        )
    except subprocess.TimeoutExpired:
        lines_out.append("  [quality] health check timed out (MySQL may be starting)")
    except FileNotFoundError:
        lines_out.append("  [quality] docker not found — skipping health check")
    except Exception as e:
        lines_out.append(f"  [quality] health check error: {e}")

    return lines_out


def main() -> int:
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()

    output: list[str] = []

    # 1. Nightly CI audit — FIRST so failures are the first thing Claude sees
    nightly = check_nightly_ci(project_dir)
    if nightly:
        output.extend(nightly)
        output.append("")

    # 2. .env check
    output.extend(check_env(project_dir))

    # 3. Security reports
    output.append("[session-start] Security report status:")
    output.extend(check_security_report(
        os.path.join(project_dir, "security-reports", "shannon-latest.md"),
        "Shannon"
    ))
    output.extend(check_security_report(
        os.path.join(project_dir, "security-reports", "zap-latest.md"),
        "ZAP"
    ))

    # 4. Migrations
    output.append("[session-start] Migration status:")
    output.extend(check_migrations(project_dir))

    # 5. Quality scoring health
    output.append("[session-start] Quality scoring health:")
    output.extend(check_quality_health(project_dir))

    print("\n".join(output))
    return 0


if __name__ == "__main__":
    sys.exit(main())
