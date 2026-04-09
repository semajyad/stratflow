// @ts-check
const { test, expect } = require('@playwright/test');

const BASE           = 'http://localhost:8890';
const ADMIN_EMAIL    = 'admin@stratflow.test';
const ADMIN_PASS     = 'password123';
const REGULAR_EMAIL  = 'pw_regular@test.invalid';
const REGULAR_PASS   = 'password123';

// Helper: log in as a given user and return the page at /app/home
async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  // Dismiss onboarding wizard if present
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Access gates — admin and superadmin routes', () => {

  test('regular user visiting /app/admin is redirected to /app/home', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('regular user visiting /app/admin/users is redirected to /app/home', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin/users`);
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('regular user visiting /superadmin is redirected', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/superadmin`);
    await expect(page).not.toHaveURL(`${BASE}/superadmin`);
  });

  test('org_admin user can access /app/admin', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/admin`);
    await expect(page.locator('body')).not.toContainText(/500|error|exception/i);
  });

  test('org_admin user cannot access /superadmin', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/superadmin`);
    await expect(page).not.toHaveURL(`${BASE}/superadmin`);
  });

  test('unauthenticated request to admin route redirects to /login', async ({ page }) => {
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/login`);
  });

});
