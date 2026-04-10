import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ============================================================
// StratFlow Load Test Suite
// Tests key user journeys under concurrent load
// ============================================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8890';

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
      'home page 200': (r) => r.status === 200,
      'home page <2s': (r) => r.timings.duration < 2000,
    });
    errorRate.add(res.status !== 200);
    sleep(1);

    const login = http.get(`${BASE_URL}/login`, { tags: { type: 'public' } });
    check(login, { 'login page 200': (r) => r.status === 200 });
    errorRate.add(login.status !== 200);
    sleep(0.5);

    const pricing = http.get(`${BASE_URL}/pricing`, { tags: { type: 'public' } });
    check(pricing, { 'pricing page 200': (r) => r.status === 200 });
    errorRate.add(pricing.status !== 200);
    sleep(0.5);
  });
}

export function authenticatedPages() {
  // k6 cannot maintain PHP sessions across VUs easily.
  // This tests the auth wall response time (302 redirect) which is
  // representative of session middleware overhead.
  group('Auth wall (session middleware)', () => {
    const start = Date.now();
    const res = http.get(`${BASE_URL}/app/home`, {
      tags: { type: 'authenticated' },
      redirects: 0,  // don't follow redirect — measure middleware time
    });
    authDuration.add(Date.now() - start);
    appPageDuration.add(res.timings.duration);
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
