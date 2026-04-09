// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs   = require('fs');
const os   = require('os');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';
// Seed project — always exists
const SEED_PROJECT_ID = '1';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Document upload — happy path', () => {

  test('upload a .txt file succeeds and shows no 500', async ({ page }) => {
    await loginAsAdmin(page);
    // Use seed project so upload page loads without redirect
    await page.goto(`${BASE}/app/upload?project_id=${SEED_PROJECT_ID}`);
    await expect(page).toHaveURL(new RegExp(`/app/upload`));

    // Create a temp .txt file
    const tmpFile = path.join(os.tmpdir(), `strat-test-${Date.now()}.txt`);
    fs.writeFileSync(tmpFile, 'Test strategy document content for Playwright upload test.');

    try {
      // Upload the file via the hidden file input (Playwright bypasses browser picker)
      await page.setInputFiles('input[type="file"][name="document"]', tmpFile);

      // Submit the upload form
      await page.click('#upload-form button[type="submit"]');

      // Wait for response — no 500 error
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
    } finally {
      fs.unlinkSync(tmpFile);
    }
  });

});
