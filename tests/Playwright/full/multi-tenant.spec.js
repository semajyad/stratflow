// @ts-check
const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const { DB_CONFIG, ADMIN_EMAIL, ADMIN_PASS, REGULAR_EMAIL, REGULAR_PASS } = require('../test-constants');

const BASE           = 'http://localhost:8890';

async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Multi-tenant isolation (IDOR prevention)', () => {

  let org2Id;
  let project2Id;

  test.beforeAll(async () => {
    let conn;
    try {
      conn = await mysql.createConnection(DB_CONFIG);
      const [orgResult] = await conn.execute(
        "INSERT INTO organisations (name) VALUES ('Playwright Org 2')"
      );
      org2Id = orgResult.insertId;
      // created_by must reference a valid users.id; use the seed admin (id=1)
      // since no users belong to org2 yet — FK only requires a valid user row.
      const [projResult] = await conn.execute(
        'INSERT INTO projects (org_id, name, status, created_by) VALUES (?, ?, ?, ?)',
        [org2Id, 'Org2 Secret Project', 'active', 1]
      );
      project2Id = projResult.insertId;
    } finally {
      if (conn) await conn.end();
    }
  });

  test.afterAll(async () => {
    let conn;
    try {
      conn = await mysql.createConnection(DB_CONFIG);
      await conn.execute('DELETE FROM projects WHERE org_id = ?', [org2Id]);
      await conn.execute('DELETE FROM organisations WHERE id = ?', [org2Id]);
    } catch (err) {
      console.error(`[multi-tenant afterAll] DB cleanup failed: ${err.message}`);
    } finally {
      if (conn) await conn.end();
    }
  });

  test('org 1 user cannot view org 2 diagram by guessing project_id', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/diagram?project_id=${project2Id}`);
    await expect(page.locator('body')).not.toContainText('Org2 Secret Project');
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  });

  test('org 1 user cannot view org 2 work items by guessing project_id', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/work-items?project_id=${project2Id}`);
    await expect(page.locator('body')).not.toContainText('Org2 Secret Project');
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  });

  test('regular org 1 user cannot view org 2 upload page for org 2 project', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/upload?project_id=${project2Id}`);
    await expect(page).not.toHaveURL(/app\/upload\?project_id=/);
    await expect(page.locator('body')).not.toContainText('Org2 Secret Project');
  });

});
