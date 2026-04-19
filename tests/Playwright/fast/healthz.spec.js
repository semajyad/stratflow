// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://localhost:8890';

test.describe('/healthz endpoint', () => {

  test('returns HTTP 200', async ({ page }) => {
    const response = await page.goto(`${BASE}/healthz`);
    expect(response?.status()).toBe(200);
  });

  test('returns JSON with ok status and no fatal errors', async ({ page }) => {
    const response = await page.goto(`${BASE}/healthz`);
    expect(response?.status()).toBe(200);
    const contentType = response?.headers()['content-type'] ?? '';
    expect(contentType).toMatch(/application\/json/);
    const body = await response?.json();
    expect(body).toHaveProperty('status');
    expect(body.status).toBe('ok');
    expect(body).toHaveProperty('db_ms');
  });

});
