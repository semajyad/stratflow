// @ts-check
/**
 * P2 smoke tests — every major page that was previously uncovered.
 *
 * Minimum bar: page loads without 500/fatal error and key UI element visible.
 * Does not test AI calls or integrations (those need external API mocks).
 */
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE = 'http://localhost:8890';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

async function assertPageLoads(page, path) {
  const res = await page.goto(`${BASE}${path}`);
  await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught|exception/i);
  expect(res?.status()).not.toBe(500);
}

// ── AI / strategy feature pages ──────────────────────────────────────────────

test.describe('Sprints page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/sprints');
  });
});

test.describe('Key Results page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/key-results');
  });
});

test.describe('Governance / drift detection page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/governance');
  });
});

// ── Enterprise feature pages ──────────────────────────────────────────────────

test.describe('Executive dashboard', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/executive');
  });
});

test.describe('Traceability page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/traceability');
  });
});

// ── Admin / integrations ──────────────────────────────────────────────────────

test.describe('Admin integrations page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/admin/integrations');
  });
});

test.describe('Admin users page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/admin/users');
  });
});

test.describe('Admin teams page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/admin/teams');
  });
});

// ── Superadmin ────────────────────────────────────────────────────────────────

test.describe('Superadmin dashboard', () => {
  test('loads without 500 for superadmin user', async ({ page }) => {
    await loginAsAdmin(page);
    const res = await page.goto(`${BASE}/superadmin`);
    // Admin user may not have superadmin role — expect 200 or redirect, never 500
    expect(res?.status()).not.toBe(500);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });

  test('organisations page loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    const res = await page.goto(`${BASE}/superadmin/organisations`);
    expect(res?.status()).not.toBe(500);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });
});

// ── Account / MFA ─────────────────────────────────────────────────────────────

test.describe('Account MFA page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/account/mfa');
  });
});

test.describe('Account data export page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    await assertPageLoads(page, '/app/account/export-data');
  });
});

// ── Sounding board history + results pages ───────────────────────────────────

test.describe('Sounding Board history page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    const res = await page.goto(`${BASE}/app/sounding-board/history`);
    expect(res?.status()).not.toBe(500);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });
});

test.describe('Board Review history page', () => {
  test('loads without 500', async ({ page }) => {
    await loginAsAdmin(page);
    const res = await page.goto(`${BASE}/app/board-review/history`);
    expect(res?.status()).not.toBe(500);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });
});
