// @ts-check
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS, REGULAR_EMAIL, REGULAR_PASS } = require('../test-constants');

const BASE           = 'http://localhost:8890';

async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Stripe billing UI', () => {

  test('admin user can view /app/admin/billing without 500', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/admin/billing`);
    await expect(page).toHaveURL(`${BASE}/app/admin/billing`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });

  test('regular user is redirected away from /app/admin/billing', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin/billing`);
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('/success page loads without 500', async ({ page }) => {
    const response = await page.goto(`${BASE}/success`);
    expect(response?.status()).toBeLessThan(500);
    await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught/i);
  });

});
