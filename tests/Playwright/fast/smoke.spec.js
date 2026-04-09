// @ts-check
const { test, expect } = require('@playwright/test');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Smoke — key pages return 200 not 500', () => {

  test('public /login page loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/login`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('public /pricing page loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/pricing`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/home loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page.locator('h1')).toContainText(/welcome/i);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  // These routes redirect to /app/home when no project exists — only assert no 500
  test('/app/upload loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/diagram loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/diagram`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/work-items loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/risks loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/risks`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/prioritisation loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/prioritisation`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/user-stories loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/user-stories`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/admin loads for admin user', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/admin`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

});
