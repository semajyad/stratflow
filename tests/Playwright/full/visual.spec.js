// @ts-check
/**
 * Visual regression tests
 *
 * Uses Playwright's built-in toHaveScreenshot() to detect unintended UI changes.
 * Baseline screenshots are stored in tests/Playwright/__screenshots__/.
 *
 * Acceptance threshold: 0.2% pixel diff (maxDiffPixelRatio: 0.002).
 *
 * To update baselines after intentional visual changes:
 *   npx playwright test --project=full --update-snapshots full/visual.spec.js
 *
 * These tests run in the `full` project (merge-to-main only).
 */

const { test, expect } = require('@playwright/test');

const { REGULAR_EMAIL, REGULAR_PASS } = require('../test-constants');

const SNAPSHOT_OPTS = {
  maxDiffPixelRatio: 0.002,  // 0.2% pixel diff tolerance
  animations: 'disabled',    // avoid timing-sensitive snapshot failures
};

// ===========================
// PUBLIC PAGES
// ===========================

test.describe('Public pages — visual regression', () => {
  test('login page matches baseline', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('login.png', SNAPSHOT_OPTS);
  });

  test('home/marketing page matches baseline', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('home.png', SNAPSHOT_OPTS);
  });
});

// ===========================
// AUTHENTICATED APP SHELL
// ===========================

test.describe('Authenticated app shell — visual regression', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', REGULAR_EMAIL);
    await page.fill('input[name="password"]', REGULAR_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/app\//, { timeout: 10_000 });
  });

  test('app dashboard matches baseline', async ({ page }) => {
    await page.goto('/app/home');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('app-home.png', SNAPSHOT_OPTS);
  });

  test('projects list matches baseline', async ({ page }) => {
    await page.goto('/app/projects');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('app-projects.png', SNAPSHOT_OPTS);
  });

  test('account tokens page matches baseline', async ({ page }) => {
    await page.goto('/app/account/tokens');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('app-account-tokens.png', SNAPSHOT_OPTS);
  });

  test('account settings matches baseline', async ({ page }) => {
    await page.goto('/app/account');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('app-account.png', SNAPSHOT_OPTS);
  });

  test('nav sidebar matches baseline', async ({ page }) => {
    await page.goto('/app/home');
    await page.waitForLoadState('networkidle');
    // Capture sidebar only for stability
    const sidebar = page.locator('nav, aside, [data-testid="sidebar"]').first();
    if (await sidebar.count() > 0) {
      await expect(sidebar).toHaveScreenshot('nav-sidebar.png', SNAPSHOT_OPTS);
    } else {
      // Full page fallback if no dedicated nav element
      await expect(page).toHaveScreenshot('app-home-sidebar-fallback.png', SNAPSHOT_OPTS);
    }
  });
});
