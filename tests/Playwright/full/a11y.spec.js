// @ts-check
/**
 * Accessibility (a11y) tests — WCAG 2.1 AA
 *
 * Uses @axe-core/playwright to scan authenticated app shell pages and the
 * marketing/public pages for serious/critical accessibility violations.
 *
 * Failure threshold: zero violations at severity serious or critical.
 * Violations at minor/moderate are logged as warnings but do not fail.
 *
 * These tests run in the `full` project (merge-to-main only).
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const { REGULAR_EMAIL, REGULAR_PASS } = require('../test-constants');

// ===========================
// HELPERS
// ===========================

/**
 * Run axe on the current page and assert zero serious/critical violations.
 * Returns the full results for inspection.
 */
async function assertNoA11yViolations(page, context = '') {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();

  const blocker = results.violations.filter(v =>
    v.impact === 'serious' || v.impact === 'critical'
  );

  if (blocker.length > 0) {
    const summary = blocker.map(v =>
      `[${v.impact.toUpperCase()}] ${v.id}: ${v.description}\n` +
      v.nodes.slice(0, 2).map(n => `  - ${n.html}`).join('\n')
    ).join('\n\n');
    throw new Error(
      `${context} — ${blocker.length} serious/critical WCAG 2.1 AA violation(s):\n\n${summary}`
    );
  }

  // Log minor/moderate for visibility without failing
  const minor = results.violations.filter(v =>
    v.impact !== 'serious' && v.impact !== 'critical'
  );
  if (minor.length > 0) {
    console.warn(`[a11y warn] ${context}: ${minor.length} minor/moderate violation(s) — not blocking`);
  }

  return results;
}

// ===========================
// PUBLIC PAGES
// ===========================

test.describe('Public pages — WCAG 2.1 AA', () => {
  test('login page has no serious a11y violations', async ({ page }) => {
    await page.goto('/login');
    await assertNoA11yViolations(page, 'login');
  });

  test('home/marketing page has no serious a11y violations', async ({ page }) => {
    await page.goto('/');
    await assertNoA11yViolations(page, 'home');
  });
});

// ===========================
// AUTHENTICATED APP SHELL
// ===========================

test.describe('Authenticated app shell — WCAG 2.1 AA', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', REGULAR_EMAIL);
    await page.fill('input[name="password"]', REGULAR_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/app\//, { timeout: 10_000 });
  });

  test('app home/dashboard has no serious a11y violations', async ({ page }) => {
    await page.goto('/app/home');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page, 'app/home');
  });

  test('projects list has no serious a11y violations', async ({ page }) => {
    await page.goto('/app/projects');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page, 'app/projects');
  });

  test('account/tokens page has no serious a11y violations', async ({ page }) => {
    await page.goto('/app/account/tokens');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page, 'app/account/tokens');
  });

  test('account settings has no serious a11y violations', async ({ page }) => {
    await page.goto('/app/account');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page, 'app/account');
  });
});
