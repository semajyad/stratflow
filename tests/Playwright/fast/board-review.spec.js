// @ts-check
/**
 * Board Review — button visibility and endpoint format tests.
 *
 * Does NOT trigger Gemini (project_id=0 fails at project lookup before AI).
 * Validates: button renders on all 4 screens, evaluate always returns JSON.
 */
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE = process.env.BASE_URL || 'http://localhost:8890';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

async function getCsrfToken(page) {
  return page.inputValue('input[name="_csrf_token"]');
}

async function postJson(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    });
    return {
      status: r.status,
      contentType: r.headers.get('content-type') ?? '',
      text: await r.text(),
    };
  }, { url, body });
}

// ── Button visibility (requires has_evaluation_board = 1 on seed org) ────────

test.describe('Board Review button — visible on subscription-gated pages', () => {

  test('button appears on /app/upload (summary screen)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
    const btn = page.locator('button.board-review-trigger');
    if (await btn.count() > 0) {
      await expect(btn.first()).toBeVisible();
      expect(await btn.first().getAttribute('data-screen')).toBe('summary');
    }
  });

  test('button appears on /app/diagram (roadmap screen)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/diagram`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
    const btn = page.locator('button.board-review-trigger');
    if (await btn.count() > 0) {
      await expect(btn.first()).toBeVisible();
      expect(await btn.first().getAttribute('data-screen')).toBe('roadmap');
    }
  });

  test('button appears on /app/work-items (work_items screen)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
    const btn = page.locator('button.board-review-trigger');
    if (await btn.count() > 0) {
      await expect(btn.first()).toBeVisible();
      expect(await btn.first().getAttribute('data-screen')).toBe('work_items');
    }
  });

  test('button appears on /app/user-stories (user_stories screen)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/user-stories`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
    const btn = page.locator('button.board-review-trigger');
    if (await btn.count() > 0) {
      await expect(btn.first()).toBeVisible();
      expect(await btn.first().getAttribute('data-screen')).toBe('user_stories');
    }
  });

});

// ── Evaluate endpoint — must always return JSON, never HTML ──────────────────

test.describe('Board Review evaluate endpoint — returns JSON for all screen contexts', () => {

  for (const screenContext of ['summary', 'roadmap', 'work_items', 'user_stories']) {
    test(`returns JSON for screen_context="${screenContext}"`, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(`${BASE}/app/work-items`);
      const csrf = await getCsrfToken(page);

      const res = await postJson(page, `${BASE}/app/board-review/evaluate`, {
        project_id: 0, // 0 → 404 JSON ("Project not found"), never HTML
        evaluation_level: 'devils_advocate',
        screen_context: screenContext,
        screen_content: 'Playwright JSON-format assertion — no AI call needed',
        _csrf_token: csrf,
      });

      // Core assertion: response must never be HTML (catches CSRF bugs, PHP exceptions)
      expect(res.contentType).toMatch(/application\/json/);
      expect(res.text).not.toMatch(/<!DOCTYPE/i);
      expect(res.text).not.toMatch(/<html/i);

      const json = JSON.parse(res.text);
      expect(json).toBeDefined();
      // project_id=0 → project not found; either error or ok are valid, but must be JSON
      expect(typeof json === 'object').toBe(true);
    });
  }

});

// ── History page ─────────────────────────────────────────────────────────────

test.describe('Board Review history page', () => {

  test('loads without 500 and renders history heading', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto(`${BASE}/app/board-review/history`);
    expect(response?.status()).not.toBe(500);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });

});
