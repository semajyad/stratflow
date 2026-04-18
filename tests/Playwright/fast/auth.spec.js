// @ts-check
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE = process.env.BASE_URL || 'http://localhost:8890';

test.describe('Auth — login / logout / session', () => {

  test('valid credentials redirect to /app/home', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('wrong password shows error on login page', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/login`);
    await expect(page.locator('body')).toContainText(/invalid|incorrect|wrong|error|too many/i);
  });

  test('non-existent user shows error on login page', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', 'nobody@test.invalid');
    await page.fill('input[name="password"]', 'whatever');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/login`);
    await expect(page.locator('body')).toContainText(/invalid|incorrect|wrong|error|too many/i);
  });

  test('logout clears session and redirects to /login', async ({ page }) => {
    // Log in first
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/app/home`);

    // Dismiss onboarding wizard if present (shown on first login, intercepts clicks)
    await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);

    // Submit logout form
    await page.click('form[action="/logout"] button[type="submit"]');
    await expect(page).toHaveURL(/login|\/$/);
  });

  test('unauthenticated visit to /app/home redirects to /login', async ({ page }) => {
    // Fresh page context has no session cookie
    await page.goto(`${BASE}/app/home`);
    await expect(page).toHaveURL(`${BASE}/login`);
  });

});
