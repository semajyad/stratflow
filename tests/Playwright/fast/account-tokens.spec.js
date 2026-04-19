// @ts-check
/**
 * Personal Access Token management page — smoke tests.
 *
 * Does NOT create real tokens (avoids leaving API keys in test DB state).
 * Verifies the page loads and the create form is present.
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

test.describe('Account tokens page', () => {

  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto(`${BASE}/app/account/tokens`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught|exception/i);
  });

  test('token creation form is visible', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/account/tokens`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
    // Form should exist — either visible or within a toggle/section
    const form = page.locator('form[action*="/account/tokens"]');
    expect(await form.count()).toBeGreaterThan(0);
  });

  test('unauthenticated request redirects to /login', async ({ page }) => {
    const response = await page.goto(`${BASE}/app/account/tokens`);
    await expect(page).toHaveURL(`${BASE}/login`);
    expect(response?.status()).not.toBe(500);
  });

});
