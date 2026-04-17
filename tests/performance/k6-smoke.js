import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// ============================================================
// StratFlow PR Smoke Test — 30 second, 1 VU baseline check.
// Catches per-PR performance regressions on critical paths.
// Thresholds: p95 < 2000ms, error rate < 5%.
// ============================================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8890';

const errorRate = new Rate('errors');

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-vus',
      vus: 1,
      duration: '30s',
    },
  },
  thresholds: {
    'http_req_duration': ['p(95)<2000'],
    'errors':            ['rate<0.05'],
    'http_req_failed':   ['rate<0.05'],
  },
};

export default function () {
  // 1. Health check — must return 200 in all states
  const health = http.get(`${BASE_URL}/healthz`, { tags: { path: 'healthz' } });
  const healthOk = check(health, {
    'healthz 200': (r) => r.status === 200,
    'healthz fast': (r) => r.timings.duration < 500,
  });
  errorRate.add(!healthOk);

  sleep(0.5);

  // 2. Login page — public, no auth required
  const login = http.get(`${BASE_URL}/login`, { tags: { path: 'login' } });
  const loginOk = check(login, {
    'login 200': (r) => r.status === 200,
    'login has form': (r) => r.body && r.body.includes('email'),
  });
  errorRate.add(!loginOk);

  sleep(0.5);

  // 3. Pricing page — public, no auth required
  const pricing = http.get(`${BASE_URL}/pricing`, { tags: { path: 'pricing' } });
  const pricingOk = check(pricing, {
    'pricing 200': (r) => r.status === 200,
  });
  errorRate.add(!pricingOk);

  sleep(0.5);

  // 4. App home redirect (unauthenticated → login) — tests router/middleware
  const appHome = http.get(`${BASE_URL}/app/home`, {
    redirects: 0,
    tags: { path: 'app_home_redirect' },
  });
  const appHomeOk = check(appHome, {
    'app/home redirects unauth': (r) => r.status === 302 || r.status === 301,
    'app/home redirects to login': (r) => (r.headers['Location'] || '').includes('/login'),
  });
  errorRate.add(!appHomeOk);

  sleep(0.5);
}
