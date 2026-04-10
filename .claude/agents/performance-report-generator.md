---
name: performance-report-generator
description: Generates k6 load test scripts for StratFlow's key endpoints, runs them, and produces a formatted performance report with p50/p95/p99 metrics suitable for corporate clients. Run before enterprise demos or when clients request SLA documentation.
tools: Bash, Read, Write
model: sonnet
---

You are generating and running performance tests for StratFlow, then producing a formal performance report for corporate clients.

Output files:
- `tests/performance/k6-load-test.js` — reusable k6 test script
- `docs/compliance/performance-report-<YYYY-MM-DD>.md` — formal client report

## Step 1: Check k6 is available

```bash
k6 version 2>&1
```

If not installed:
```bash
# Windows (winget)
winget install k6 --accept-package-agreements 2>&1
# Or via choco
choco install k6 2>&1
# Or via npm
npm install -g @k6/k6 2>&1
```

If k6 cannot be installed, note it and use Apache Bench (ab) as fallback:
```bash
ab -V 2>&1
```

## Step 2: Check StratFlow is running

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:80/ 2>&1
```

If not running:
```bash
cd C:/Users/James/Scripts/stratflow && docker compose up -d 2>&1
sleep 5
curl -s -o /dev/null -w "%{http_code}" http://localhost:80/ 2>&1
```

## Step 3: Write the k6 test script

Create `tests/performance/k6-load-test.js`:

```javascript
import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ============================================================
// StratFlow Load Test Suite
// Tests key user journeys under concurrent load
// ============================================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:80';

// Custom metrics
const errorRate = new Rate('errors');
const authDuration = new Trend('auth_duration');
const appPageDuration = new Trend('app_page_duration');

export const options = {
  scenarios: {
    // Scenario 1: Baseline — light steady load
    baseline: {
      executor: 'constant-vus',
      vus: 5,
      duration: '1m',
      tags: { scenario: 'baseline' },
    },
    // Scenario 2: Normal load — simulates a typical business day
    normal_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 20 },   // ramp up
        { duration: '2m',  target: 20 },   // hold
        { duration: '30s', target: 0 },    // ramp down
      ],
      startTime: '1m30s',
      tags: { scenario: 'normal_load' },
    },
    // Scenario 3: Peak load — corporate all-hands / planning sprint
    peak_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },   // aggressive ramp
        { duration: '1m',  target: 50 },   // hold at peak
        { duration: '30s', target: 0 },
      ],
      startTime: '5m',
      tags: { scenario: 'peak_load' },
    },
  },
  thresholds: {
    // SLA targets for corporate clients
    'http_req_duration{type:public}':         ['p(95)<2000'],   // Public pages < 2s
    'http_req_duration{type:authenticated}':  ['p(95)<3000'],   // App pages < 3s (AI features)
    'http_req_duration{type:api}':            ['p(95)<5000'],   // AI endpoints < 5s
    'http_req_failed':                        ['rate<0.01'],    // < 1% error rate
    'errors':                                 ['rate<0.01'],
  },
};

// ---- Test scenarios ----

export function publicPages() {
  group('Public pages', () => {
    const res = http.get(`${BASE_URL}/`, { tags: { type: 'public' } });
    check(res, {
      'pricing page 200': (r) => r.status === 200,
      'pricing page <2s': (r) => r.timings.duration < 2000,
    });
    errorRate.add(res.status !== 200);
    sleep(1);

    const login = http.get(`${BASE_URL}/login`, { tags: { type: 'public' } });
    check(login, { 'login page 200': (r) => r.status === 200 });
    sleep(0.5);
  });
}

export function authenticatedPages() {
  // Note: k6 cannot maintain PHP sessions across VUs easily.
  // This tests the auth wall response time (302 redirect) which is
  // representative of session middleware overhead.
  group('Auth wall (session middleware)', () => {
    const start = Date.now();
    const res = http.get(`${BASE_URL}/app/home`, {
      tags: { type: 'authenticated' },
      redirects: 0,  // don't follow redirect — measure middleware time
    });
    authDuration.add(Date.now() - start);
    // Expect 302 redirect to login for unauthenticated request
    check(res, {
      'auth middleware responds': (r) => r.status === 302 || r.status === 200,
      'auth middleware <500ms': (r) => r.timings.duration < 500,
    });
    sleep(0.5);
  });
}

// Default function runs all scenarios
export default function () {
  publicPages();
  authenticatedPages();
  sleep(1);
}
```

Ensure the directory exists:
```bash
mkdir -p C:/Users/James/Scripts/stratflow/tests/performance
```

Write the script to `C:/Users/James/Scripts/stratflow/tests/performance/k6-load-test.js`.

## Step 4: Run tests and capture results

```bash
cd C:/Users/James/Scripts/stratflow

# Run baseline scenario only (faster, good for quick reports)
k6 run \
  --scenario baseline \
  --out json=tests/performance/results-latest.json \
  --summary-export tests/performance/summary-latest.json \
  tests/performance/k6-load-test.js 2>&1
```

If k6 is unavailable, use curl timing as fallback:
```bash
echo "=== Public page response times (10 requests) ==="
for i in $(seq 1 10); do
  curl -s -o /dev/null -w "%{time_total}\n" http://localhost:80/
done

echo "=== Login page response times (10 requests) ==="
for i in $(seq 1 10); do
  curl -s -o /dev/null -w "%{time_total}\n" http://localhost:80/login
done
```

Capture all output. Extract:
- `http_req_duration` p50, p95, p99
- `http_req_failed` rate
- `vus_max`
- Requests per second (throughput)
- Error count

## Step 5: Write the performance report

Create `docs/compliance/performance-report-<YYYY-MM-DD>.md`:

```markdown
# StratFlow Performance Report

**Classification:** Confidential — For recipient organisation only
**Prepared by:** StratFlow Engineering Team
**Test Date:** [today]
**Environment:** [local Docker / staging / production — specify]
**Codebase Commit:** [git hash]
**Report Version:** [1.0 or increment]
**Next Review:** [today + 3 months, or after significant architecture changes]

---

## 1. Executive Summary

This report documents performance characteristics of StratFlow under simulated
concurrent load. Tests were conducted using k6 (industry-standard load testing tool)
against [environment].

**Key Results:**

| Metric | Result | SLA Target | Status |
|---|---|---|---|
| p95 response time (public pages) | [Xms] | < 2,000ms | ✅/❌ |
| p95 response time (app pages) | [Xms] | < 3,000ms | ✅/❌ |
| Error rate | [X%] | < 1% | ✅/❌ |
| Peak concurrent users tested | [N] | — | — |
| Throughput | [X req/s] | — | — |

---

## 2. Test Configuration

| Parameter | Value |
|---|---|
| Tool | k6 v[version] |
| Test duration | [total duration] |
| Peak virtual users | [N] |
| Scenarios | Baseline (5 VUs), Normal Load (20 VUs), Peak Load (50 VUs) |
| Test environment | [Docker local / Railway staging] |
| Infrastructure | PHP-FPM + Nginx + MySQL 8.4 |

**Scenarios tested:**
- **Baseline** — 5 concurrent users for 1 minute (daily minimum load)
- **Normal load** — ramp to 20 concurrent users over 30s, hold 2 minutes
- **Peak load** — ramp to 50 concurrent users (enterprise planning session simulation)

---

## 3. Results

### 3.1 Response Time Distribution

| Endpoint | p50 | p95 | p99 | Max |
|---|---|---|---|---|
| GET / (pricing) | | | | |
| GET /login | | | | |
| GET /app/home (auth wall) | | | | |
| [additional endpoints] | | | | |

### 3.2 Error Rate

| Scenario | Total Requests | Errors | Error Rate |
|---|---|---|---|
| Baseline | | | |
| Normal Load | | | |
| Peak Load | | | |

### 3.3 Throughput

| Scenario | Requests/second |
|---|---|
| Baseline | |
| Normal Load | |
| Peak Load | |

---

## 4. SLA Commitments

Based on this testing, StratFlow can commit to the following SLAs:

| Metric | Commitment |
|---|---|
| Uptime | 99.5% monthly (managed by Railway infrastructure) |
| p95 response time (public pages) | < 2,000ms under normal load |
| p95 response time (app pages) | < 3,000ms under normal load |
| AI-assisted features | < 10,000ms (dependent on Gemini API latency) |
| Error rate | < 1% under tested load |
| Concurrent users | Tested up to [N] — [result] |

**Note on AI features:** Strategy generation, diagram AI, and story quality analysis
invoke the Gemini API. Response times for these endpoints are bounded by Gemini's
latency (typically 2-8s) and are excluded from the standard p95 SLA above.

---

## 5. Infrastructure Notes

| Component | Specification |
|---|---|
| Runtime | PHP-FPM 8.4 |
| Web server | Nginx (latest) |
| Database | MySQL 8.4 |
| Hosting | Railway (managed cloud) |
| CDN/Edge | [Cloudflare / None] |
| Scaling | [Horizontal scaling available via Railway — note if configured] |

---

## 6. Limitations

- Tests conducted against [local/staging] environment — production performance may vary
- AI endpoint performance depends on third-party Gemini API latency
- Database performance at scale depends on data volume per tenant
- These results reflect single-region deployment

---

## 7. Recommendations

[List any performance improvements recommended based on findings]

---

## 8. Document Control

| Version | Date | Changes |
|---|---|---|
| [version] | [today] | [description] |
```

## Step 6: Save files and update index

```bash
mkdir -p C:/Users/James/Scripts/stratflow/docs/compliance
mkdir -p C:/Users/James/Scripts/stratflow/tests/performance
```

Update `docs/compliance/README.md` with the new report link.

Print summary:
```
Performance report saved: docs/compliance/performance-report-<date>.md
k6 script saved: tests/performance/k6-load-test.js
p95 (public): <Xms> | p95 (app): <Xms> | error rate: <X%>
SLA targets: <MET / NOT MET>
```
