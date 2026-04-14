"""
Unit tests for scripts/ci/triage_nightly.py
Run with: python -m pytest tests/Python/test_triage_nightly.py -v
"""

import json
import sys
import tempfile
from pathlib import Path
from unittest.mock import patch

import pytest

# Add repo root to path so imports work regardless of working directory
sys.path.insert(0, str(Path(__file__).parent.parent.parent))
from scripts.ci.triage_nightly import (
    build_report,
    classify,
    format_ntfy_body,
    issue_dedup_key,
    load_statuses,
)


@pytest.fixture
def status_dir(tmp_path):
    """Create a temp status dir with sample nightly-status.json files."""
    def _make(job, status, metric=None, run_id="12345"):
        job_dir = tmp_path / f"nightly-status-{job}"
        job_dir.mkdir()
        payload = {"job": job, "status": status, "metric": metric,
                   "findings_url": None, "run_id": run_id}
        (job_dir / "nightly-status.json").write_text(json.dumps(payload))
    return _make, tmp_path


class TestLoadStatuses:
    def test_loads_all_jobs(self, status_dir):
        make, tmp_path = status_dir
        make("tests", "pass", {"coverage": 64.8})
        make("mutation", "fail", {"msi": 58.1})
        result = load_statuses(tmp_path)
        assert "tests" in result
        assert "mutation" in result
        assert result["tests"]["status"] == "pass"
        assert result["mutation"]["metric"]["msi"] == 58.1

    def test_tolerates_invalid_json(self, tmp_path):
        bad_dir = tmp_path / "nightly-status-bad"
        bad_dir.mkdir()
        (bad_dir / "nightly-status.json").write_text("not json{{{")
        result = load_statuses(tmp_path)
        assert "bad" not in result  # gracefully skipped

    def test_empty_dir(self, tmp_path):
        result = load_statuses(tmp_path)
        assert result == {}


class TestClassify:
    def test_marks_missing_required_job_as_fail(self):
        # provide all jobs except 'zap'
        statuses = {j: {"job": j, "status": "pass", "metric": None, "findings_url": None, "run_id": "1"}
                    for j in ["tests", "mutation", "perf", "shannon", "snyk", "e2e", "smoke"]}
        results = classify(statuses)
        assert results["zap"]["status"] == "fail"
        assert results["zap"].get("_missing") is True

    def test_pass_through_known_jobs(self):
        statuses = {"tests": {"job": "tests", "status": "pass", "metric": None, "findings_url": None, "run_id": "1"}}
        results = classify(statuses)
        assert results["tests"]["status"] == "pass"

    def test_optional_jobs_excluded_on_weekdays(self):
        """perf-load is weekly — should not appear on non-Sunday runs."""
        statuses = {}
        # 2025-01-13 is a Monday (weekday=0), so perf-load should be skipped
        from datetime import datetime, timezone
        fixed_monday = datetime(2025, 1, 13, tzinfo=timezone.utc)
        assert fixed_monday.weekday() == 0, "test date must be a Monday"
        with patch("scripts.ci.triage_nightly.datetime") as mock_dt:
            mock_dt.now.return_value = fixed_monday
            results = classify(statuses)
        assert "perf-load" not in results


class TestBuildReport:
    def test_counts_pass_fail_warn(self):
        results = {
            "tests":    {"job": "tests",    "status": "pass", "metric": {"coverage": 65.0}, "findings_url": None, "run_id": "1"},
            "mutation": {"job": "mutation", "status": "fail", "metric": {"msi": 55.0},       "findings_url": "http://x", "run_id": "1"},
            "zap":      {"job": "zap",      "status": "warn", "metric": {"high_count": 1},   "findings_url": None,       "run_id": "1"},
        }
        report = build_report(results, {"mutation": "https://github.com/issues/42"}, "2025-01-15")
        assert report["summary"]["pass"] == 1
        assert report["summary"]["fail"] == 1
        assert report["summary"]["warn"] == 1
        assert "mutation" in report["summary"]["fail_jobs"]
        assert report["metrics"]["tests"]["coverage"] == 65.0

    def test_empty_results(self):
        report = build_report({}, {}, "2025-01-15")
        assert report["summary"]["pass"] == 0
        assert report["summary"]["fail"] == 0


class TestFormatNtfyBody:
    def test_includes_fail_jobs(self):
        results = {
            "tests": {"job": "tests", "status": "pass", "metric": None, "findings_url": None, "run_id": "1"},
            "zap":   {"job": "zap",   "status": "fail", "metric": {"high_count": 2}, "findings_url": None, "run_id": "1"},
        }
        report = build_report(results, {"zap": "https://github.com/issues/99"}, "2025-01-15")
        body = format_ntfy_body(report)
        assert "❌" in body
        assert "zap" in body
        assert "✅" in body
        assert "tests" in body

    def test_all_pass_message(self):
        results = {
            "tests": {"job": "tests", "status": "pass", "metric": None, "findings_url": None, "run_id": "1"},
        }
        report = build_report(results, {}, "2025-01-15")
        body = format_ntfy_body(report)
        assert "FAIL" not in body or "0 FAIL" in body


class TestIssueDedupKey:
    def test_stable_for_same_input(self):
        k1 = issue_dedup_key("zap", "12345678")
        k2 = issue_dedup_key("zap", "12345678")
        assert k1 == k2

    def test_different_for_different_jobs(self):
        k1 = issue_dedup_key("zap", "12345678")
        k2 = issue_dedup_key("snyk", "12345678")
        assert k1 != k2

    def test_returns_12_char_hex(self):
        k = issue_dedup_key("tests", "99999")
        assert len(k) == 12
        assert all(c in "0123456789abcdef" for c in k)
