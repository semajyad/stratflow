# Nightly Status Artifact Schema

Every nightly CI workflow **must** emit a `nightly-status.json` file and
upload it as artifact `nightly-status-<job>` with `retention-days: 7`.

The `nightly-triage.yml` workflow downloads all `nightly-status-*` artifacts
the following morning and fails loudly if any are missing.

## Schema

```json
{
  "job":          "string — unique identifier matching the artifact name suffix",
  "status":       "pass | fail | warn",
  "metric":       "object | null — job-specific key metric(s) for trend tracking",
  "findings_url": "string | null — direct URL to the failing run or artifact",
  "run_id":       "string — GitHub Actions run ID (${{ github.run_id }})"
}
```

## Examples

```json
{ "job": "tests",     "status": "pass", "metric": { "coverage": 64.8 },  "findings_url": null,  "run_id": "12345678" }
{ "job": "mutation",  "status": "fail", "metric": { "msi": 58.1 },        "findings_url": "https://github.com/…/actions/runs/12345678", "run_id": "12345678" }
{ "job": "perf",      "status": "warn", "metric": { "p95_ms": 750 },      "findings_url": null,  "run_id": "12345678" }
{ "job": "zap",       "status": "fail", "metric": { "high_count": 2 },    "findings_url": "https://github.com/…/actions/runs/12345678", "run_id": "12345678" }
{ "job": "shannon",   "status": "pass", "metric": null,                    "findings_url": null,  "run_id": "12345678" }
{ "job": "snyk",      "status": "fail", "metric": { "critical_count": 1 },"findings_url": "https://github.com/…/actions/runs/12345678", "run_id": "12345678" }
{ "job": "e2e",       "status": "pass", "metric": null,                    "findings_url": null,  "run_id": "12345678" }
{ "job": "smoke",     "status": "pass", "metric": null,                    "findings_url": null,  "run_id": "12345678" }
```

## Registered jobs

| Artifact name           | Workflow file           | Cron (UTC) |
|-------------------------|-------------------------|------------|
| nightly-status-tests    | tests.yml               | on push/PR |
| nightly-status-mutation | mutation-testing.yml    | 13:00      |
| nightly-status-perf     | performance.yml         | 12:30      |
| nightly-status-perf-load| performance-load.yml    | Sun 20:00  |
| nightly-status-shannon  | security-shannon.yml    | 14:00      |
| nightly-status-snyk     | snyk.yml                | 15:00      |
| nightly-status-zap      | security-zap.yml        | 16:00      |
| nightly-status-e2e      | e2e.yml                 | on push/PR |
| nightly-status-smoke    | smoke-staging.yml       | 02:00      |

## Emitting the status file

Add these two steps to the end of every nightly job (adjust values as needed):

```yaml
- name: Write nightly status
  if: always()
  run: |
    python3 - <<'PYEOF'
    import json, os
    status = "fail" if os.environ.get("JOB_STATUS") != "success" else "pass"
    payload = {
        "job": "tests",          # <-- change per workflow
        "status": status,
        "metric": None,          # <-- populate with job-specific metric
        "findings_url": None,
        "run_id": os.environ["GITHUB_RUN_ID"],
    }
    with open("nightly-status.json", "w") as f:
        json.dump(payload, f)
    PYEOF
  env:
    JOB_STATUS: ${{ job.status }}

- name: Upload nightly status artifact
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: nightly-status-tests    # <-- change per workflow
    path: nightly-status.json
    retention-days: 7
```
