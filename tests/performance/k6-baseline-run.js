import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ============================================================
// StratFlow Baseline Load Test — standalone runner
// Runs all three scenarios sequentially in a single execution
// ============================================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8890';

const errorRate = new Rate('errors');
const authDuration = new Trend('auth_duration');
const appPageDuration = new Trend('app_page_duration');

export const options = {
  scenarios: {
    baseline: {
      executor: 'constant-vus',
      vus: 5,
      duration: '1m',
      tags: { scenario: 'baseline' },
    },
    normal_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: 20 },
        { duration: '1m',  target: 20 },
        { duration: '10s', target: 0 },
      ],
      startTime: '1m10s',
      tags: { scenario: 'normal_load' },
    },
    peak_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: 50 },
        { duration: '40s', target: 50 },
        { duration: '10s', target: 0 },
      ],
      startTime: '3m30s',
      tags: { scenario: 'peak_load' },
    },
  },
  thresholds: {
    // Single source of truth: p95 < 800ms, error rate < 1%
    // Matches the gate in .github/workflows/performance.yml
    'http_req_duration':                      ['p(95)<800'],
    'http_req_duration{type:public}':         ['p(95)<800'],
    'http_req_duration{type:authenticated}':  ['p(95)<800'],
    'http_req_failed':                        ['rate<0.01'],
    'errors':                                 ['rate<0.01'],
  },
};

export default function () {
  group('Public pages', () => {
    const home = http.get(`${BASE_URL}/`, { tags: { type: 'public' } });
    check(home, {
      'home 200': (r) => r.status === 200,
      'home <800ms': (r) => r.timings.duration < 800,
    });
    errorRate.add(home.status !== 200);
    sleep(0.5);

    const login = http.get(`${BASE_URL}/login`, { tags: { type: 'public' } });
    check(login, { 'login 200': (r) => r.status === 200 });
    errorRate.add(login.status !== 200);
    sleep(0.5);

    const pricing = http.get(`${BASE_URL}/pricing`, { tags: { type: 'public' } });
    check(pricing, { 'pricing 200': (r) => r.status === 200 });
    errorRate.add(pricing.status !== 200);
    sleep(0.5);
  });

  group('Auth wall', () => {
    const start = Date.now();
    const res = http.get(`${BASE_URL}/app/home`, {
      tags: { type: 'authenticated' },
      redirects: 0,
    });
    authDuration.add(Date.now() - start);
    appPageDuration.add(res.timings.duration);
    check(res, {
      'auth middleware responds': (r) => r.status === 302 || r.status === 200,
      'auth middleware <500ms':   (r) => r.timings.duration < 500,
    });
    sleep(0.5);
  });

  sleep(1);
}
