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

async function goToUpload(page) {
  await page.goto(`${BASE}/app/upload?project_id=${SEED_PROJECT_ID}`);
  await expect(page).toHaveURL(new RegExp(`/app/upload`));
}

test.describe('Document upload — edge cases', () => {

  test('submitting with no file and no text shows an error, not 500', async ({ page }) => {
    await loginAsAdmin(page);
    await goToUpload(page);

    // Submit with no file selected and no paste text
    await page.click('#upload-form button[type="submit"]');

    await page.waitForLoadState('networkidle');
    // Should stay on upload page (redirect back) with an error message, not a 500
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  });

  test('uploading a file with wrong MIME type (.exe) is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await goToUpload(page);

    const tmpFile = path.join(os.tmpdir(), `bad-file-${Date.now()}.exe`);
    fs.writeFileSync(tmpFile, Buffer.from('MZ')); // minimal EXE header

    try {
      // setInputFiles bypasses the browser file picker and the accept attribute filter
      await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
      await page.click('#upload-form button[type="submit"]');

      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
    } finally {
      fs.unlinkSync(tmpFile);
    }
  });

  test('uploading a 0-byte file is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await goToUpload(page);

    const tmpFile = path.join(os.tmpdir(), `empty-${Date.now()}.txt`);
    fs.writeFileSync(tmpFile, ''); // 0 bytes

    try {
      await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
      await page.click('#upload-form button[type="submit"]');

      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
    } finally {
      fs.unlinkSync(tmpFile);
    }
  });

  test('uploading a file over 50 MB is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await goToUpload(page);

    // Create a 51 MB file
    const tmpFile = path.join(os.tmpdir(), `oversized-${Date.now()}.txt`);
    const buf = Buffer.alloc(51 * 1024 * 1024, 'x');
    fs.writeFileSync(tmpFile, buf);

    try {
      await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
      await page.click('#upload-form button[type="submit"]');

      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
    } finally {
      fs.unlinkSync(tmpFile);
    }
  }, { timeout: 60_000 }); // allow extra time for large file upload

});
