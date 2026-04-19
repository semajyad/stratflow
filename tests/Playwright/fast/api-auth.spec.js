// @ts-check
/**
 * API auth tests — /api/v1/* routes must return 401 JSON (never HTML) when
 * unauthenticated. This catches middleware bugs where auth failure returns an
 * HTML redirect instead of a JSON error.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://localhost:8890';

async function fetchApi(page, path, token) {
  return page.evaluate(async ({ url, token }) => {
    const headers = token ? { Authorization: `Bearer ${token}` } : {};
    const r = await fetch(url, { headers });
    return {
      status: r.status,
      contentType: r.headers.get('content-type') ?? '',
      text: await r.text(),
    };
  }, { url: `${BASE}${path}`, token: token ?? null });
}

test.describe('API auth — unauthenticated requests return 401 JSON', () => {

  for (const path of ['/api/v1/me', '/api/v1/stories', '/api/v1/projects', '/api/v1/stories/team']) {
    test(`GET ${path} without token → 401 JSON (not HTML redirect)`, async ({ page }) => {
      await page.goto(`${BASE}/login`); // establishes page context without session
      await page.evaluate(() => document.cookie.split(';').forEach(c => {
        document.cookie = c.replace(/^ +/, '').replace(/=.*/, '=;expires=' + new Date(0).toUTCString() + ';path=/');
      }));

      const res = await fetchApi(page, path, null);

      expect(res.status).toBe(401);
      expect(res.contentType).toMatch(/application\/json/);
      expect(res.text).not.toMatch(/<!DOCTYPE/i);
      expect(res.text).not.toMatch(/<html/i);

      const json = JSON.parse(res.text);
      expect(json).toHaveProperty('error');
    });
  }

  test('GET /api/v1/me with malformed token → 401 JSON', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    const res = await fetchApi(page, '/api/v1/me', 'sf_pat_invalid_garbage_token');

    expect(res.status).toBe(401);
    expect(res.contentType).toMatch(/application\/json/);
    expect(res.text).not.toMatch(/<!DOCTYPE/i);

    const json = JSON.parse(res.text);
    expect(json).toHaveProperty('error');
  });

});
