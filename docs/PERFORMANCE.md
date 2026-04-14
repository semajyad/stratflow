# Performance Testing — StratFlow

## SLO Targets

| Metric | Threshold | Enforced by |
|---|---|---|
| p95 response time (all routes) | < 800ms | `performance.yml` + k6 thresholds |
| Error rate | < 1% | `performance.yml` + k6 thresholds |
| Peak p95 (50-VU load) | < 800ms | `performance-load.yml` |

## k6 Scripts

| Script | Scenarios | Trigger |
|---|---|---|
| `tests/performance/k6-baseline-run.js` | 5-VU baseline → 20-VU ramp → 50-VU peak | Daily + weekly |

Thresholds in the k6 script and CI gate are kept in sync at `p95 < 800ms`.
Do not change one without updating the other.

## CI Workflows

- **`performance.yml`** — runs daily against staging; appends result to `tests/performance/history/YYYY-MM-DD.json`.
- **`performance-load.yml`** — runs weekly (Sunday 20:00 UTC) peak scenario; appends to `history/YYYY-MM-DD-peak.json`.

Both write `summary-latest.json` / `summary-peak-latest.json` as the most-recent snapshot.

## History

Results are appended to `tests/performance/history/` with date-stamped filenames. This directory tracks p95 trend over time and is committed to the repo by the CI bot.

```
tests/performance/history/
  2025-01-15.json          ← daily baseline
  2025-01-19-peak.json     ← weekly peak
  ...
```

## Interpreting Results

k6 `summary-latest.json` key metrics:
- `metrics.http_req_duration.values.p(95)` — 95th percentile response time (ms)
- `metrics.http_req_failed.values.rate` — fraction of requests that failed
- `metrics.iterations.values.count` — total request count for the run

## Running Locally

```bash
# Requires k6 installed: https://k6.io/docs/get-started/installation/
k6 run --env BASE_URL=http://localhost:8890 tests/performance/k6-baseline-run.js
```

## Target Trend (Wave 5 baseline)

| Date | p95 (ms) | Error rate |
|---|---|---|
| (run CI to populate) | — | — |
