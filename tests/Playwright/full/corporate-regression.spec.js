// @ts-check
/**
 * Corporate nightly regression coverage.
 *
 * Focuses on high-value corporate-app guarantees:
 * - executive and governance dashboards surface seeded risk/change-control data
 * - privileged state-changing endpoints reject missing CSRF tokens
 * - audit log and DSAR export paths remain available for compliance workflows
 *
 * DB-backed setup runs only against localhost or when E2E_DB_ACCESS=true.
 * Browser/API checks are staging-safe and run in the nightly staging suite.
 */
const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const {
  ADMIN_EMAIL,
  ADMIN_PASS,
  DB_CONFIG,
  canUseDb,
} = require('../test-constants');

const BASE = process.env.BASE_URL || 'http://localhost:8890';
const PROJECT_ID = 1;
const HAS_DB_ACCESS = canUseDb(BASE);

const seeded = {
  riskTitle: `PW Corp Risk ${Date.now()}`,
  alertMessage: `PW Corp Drift Alert ${Date.now()}`,
  governanceTitle: `PW Corp Scope Change ${Date.now()}`,
  originalAdminFlags: null,
  ids: {},
};

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => {
    if (typeof dismissOnboarding === 'function') dismissOnboarding();
  }).catch(() => false);
}

async function getCsrfToken(page) {
  const token = page.locator('input[name="_csrf_token"]').first();
  await expect(token).toBeAttached();
  return token.inputValue();
}

async function withDb(fn) {
  let conn;
  try {
    conn = await mysql.createConnection(DB_CONFIG);
    return await fn(conn);
  } finally {
    if (conn) await conn.end();
  }
}

test.describe('Corporate regression - executive and governance', () => {
  test.beforeAll(async () => {
    if (!HAS_DB_ACCESS) return;

    await withDb(async (conn) => {
      const [admins] = await conn.execute(
        'SELECT has_executive_access, has_billing_access FROM users WHERE id = 1 LIMIT 1'
      );
      seeded.originalAdminFlags = admins[0] ?? null;

      await conn.execute(
        'UPDATE users SET has_executive_access = 1, has_billing_access = 1 WHERE id = 1'
      );

      const [workItemResult] = await conn.execute(
        `INSERT INTO hl_work_items
          (project_id, priority_number, title, description, okr_title, okr_description, estimated_sprints, status, final_score, requires_review)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          PROJECT_ID,
          901,
          `PW Corp Work Item ${Date.now()}`,
          'Seeded for corporate regression coverage',
          'Improve enterprise execution confidence',
          'KR1: Reduce governance cycle time',
          2,
          'backlog',
          98.5,
          1,
        ]
      );
      seeded.ids.workItem = workItemResult.insertId;

      const [riskResult] = await conn.execute(
        'INSERT INTO risks (project_id, title, description, likelihood, impact, mitigation, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [PROJECT_ID, seeded.riskTitle, 'Seeded high-priority risk', 5, 5, 'Executive mitigation review', 'open']
      );
      seeded.ids.risk = riskResult.insertId;

      const [alertResult] = await conn.execute(
        'INSERT INTO drift_alerts (project_id, alert_type, severity, details_json, status) VALUES (?, ?, ?, ?, ?)',
        [
          PROJECT_ID,
          'scope_creep',
          'critical',
          JSON.stringify({ message: seeded.alertMessage }),
          'active',
        ]
      );
      seeded.ids.alert = alertResult.insertId;

      const [governanceResult] = await conn.execute(
        'INSERT INTO governance_queue (project_id, change_type, proposed_change_json, status) VALUES (?, ?, ?, ?)',
        [
          PROJECT_ID,
          'scope_change',
          JSON.stringify({
            title: seeded.governanceTitle,
            story_title: seeded.governanceTitle,
            work_item_id: seeded.ids.workItem,
            old_size: 3,
            new_size: 8,
            old_value: 'Current committed scope',
            new_value: 'Expanded executive scope',
          }),
          'pending',
        ]
      );
      seeded.ids.governance = governanceResult.insertId;

      const [baselineResult] = await conn.execute(
        'INSERT INTO strategic_baselines (project_id, snapshot_json) VALUES (?, ?)',
        [
          PROJECT_ID,
          JSON.stringify({
            work_items: [{ id: seeded.ids.workItem, title: 'baseline item' }],
            stories: { total_count: 1, total_size: 3 },
          }),
        ]
      );
      seeded.ids.baseline = baselineResult.insertId;
    });
  });

  test.afterAll(async () => {
    if (!HAS_DB_ACCESS) return;

    await withDb(async (conn) => {
      if (seeded.ids.baseline) await conn.execute('DELETE FROM strategic_baselines WHERE id = ?', [seeded.ids.baseline]);
      if (seeded.ids.governance) await conn.execute('DELETE FROM governance_queue WHERE id = ?', [seeded.ids.governance]);
      if (seeded.ids.alert) await conn.execute('DELETE FROM drift_alerts WHERE id = ?', [seeded.ids.alert]);
      if (seeded.ids.risk) await conn.execute('DELETE FROM risks WHERE id = ?', [seeded.ids.risk]);
      if (seeded.ids.workItem) await conn.execute('DELETE FROM hl_work_items WHERE id = ?', [seeded.ids.workItem]);
      if (seeded.originalAdminFlags) {
        await conn.execute(
          'UPDATE users SET has_executive_access = ?, has_billing_access = ? WHERE id = 1',
          [
            seeded.originalAdminFlags.has_executive_access,
            seeded.originalAdminFlags.has_billing_access,
          ]
        );
      }
    });
  });

  test('executive dashboard rolls up seeded risks, drift alerts, and governance queue', async ({ page }) => {
    test.skip(!HAS_DB_ACCESS, 'Executive rollup assertions need deterministic DB seed data.');

    await loginAsAdmin(page);
    const response = await page.goto(`${BASE}/app/executive`);

    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Executive Dashboard');
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
    await expect(page.locator('body')).toContainText('Open Risks');
    await expect(page.locator('body')).toContainText('Needs Attention');
    await expect(page.locator('body')).toContainText(seeded.riskTitle);
    await expect(page.locator('body')).toContainText(seeded.alertMessage);
    await expect(page.locator('body')).toContainText(seeded.governanceTitle);
  });

  test('governance dashboard supports alert acknowledgement and queue review', async ({ page }) => {
    test.skip(!HAS_DB_ACCESS, 'Governance mutation assertions need deterministic DB seed data.');

    await loginAsAdmin(page);
    const response = await page.goto(`${BASE}/app/governance?project_id=${PROJECT_ID}`);

    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Governance');
    await expect(page.locator('body')).toContainText('Active Alerts');
    await expect(page.locator('body')).toContainText('Pending Reviews');
    await expect(page.locator('body')).toContainText('Baseline History');
    await expect(page.locator('body')).toContainText(seeded.alertMessage);
    await expect(page.locator('body')).toContainText(seeded.governanceTitle);

    const csrf = await getCsrfToken(page);
    const alertRes = await page.request.post(`${BASE}/app/governance/alerts/${seeded.ids.alert}`, {
      form: {
        _csrf_token: csrf,
        action: 'acknowledge',
        redirect_to: `/app/governance?project_id=${PROJECT_ID}`,
      },
    });
    expect(alertRes.status()).toBeLessThan(400);

    const queueRes = await page.request.post(`${BASE}/app/governance/queue/${seeded.ids.governance}`, {
      form: {
        _csrf_token: csrf,
        action: 'reject',
        redirect_to: `/app/governance?project_id=${PROJECT_ID}`,
      },
    });
    expect(queueRes.status()).toBeLessThan(400);

    await withDb(async (conn) => {
      const [alerts] = await conn.execute('SELECT status FROM drift_alerts WHERE id = ?', [seeded.ids.alert]);
      const [items] = await conn.execute('SELECT status, reviewed_by FROM governance_queue WHERE id = ?', [seeded.ids.governance]);
      expect(alerts[0].status).toBe('acknowledged');
      expect(items[0].status).toBe('rejected');
      expect(items[0].reviewed_by).toBe(1);
    });
  });
});

test.describe('Corporate regression - compliance and write protection', () => {
  test('admin audit log page filters and exports CSV', async ({ page }) => {
    await loginAsAdmin(page);

    const response = await page.goto(`${BASE}/app/admin/audit-logs`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Audit Logs');
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);

    await page.selectOption('select[name="type"]', 'login_success');
    await page.click('button:has-text("Filter")');
    await expect(page).toHaveURL(/\/app\/admin\/audit-logs\?type=login_success/);
    await expect(page.locator('body')).toContainText(/events|No audit events found/i);

    const exportRes = await page.request.get(`${BASE}/app/admin/audit-logs/export?type=login_success`);
    expect(exportRes.status()).toBe(200);
    expect(exportRes.headers()['content-type']).toMatch(/text\/csv|application\/octet-stream/);
    expect(exportRes.headers()['content-disposition'] ?? '').toMatch(/audit-logs-/);
    const csv = await exportRes.text();
    expect(csv.split(/\r?\n/)[0]).toMatch(/^Timestamp,Event,User,Email,"?IP Address"?,Details$/);
  });

  test('DSAR data export streams an archive and omits secret fields', async ({ page }) => {
    await loginAsAdmin(page);

    const response = await page.goto(`${BASE}/app/account/export-data`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Download your data');
    const csrf = await getCsrfToken(page);

    const exportRes = await page.request.post(`${BASE}/app/account/export-data`, {
      form: { _csrf_token: csrf },
    });
    expect(exportRes.status()).toBe(200);
    expect(exportRes.headers()['content-type']).toMatch(/application\/zip/);
    expect(exportRes.headers()['content-disposition'] ?? '').toMatch(/stratflow-data-export-/);

    const body = Buffer.from(await exportRes.body()).toString('latin1');
    expect(body).not.toContain('password_hash');
    expect(body).not.toContain('mfa_secret');
  });

  test('privileged POST endpoints reject missing CSRF token', async ({ page }) => {
    await loginAsAdmin(page);

    const adminSettings = await page.request.post(`${BASE}/app/admin/settings`, {
      form: { sprint_length_weeks: '2' },
    });
    expect(adminSettings.status()).toBe(403);
    expect(await adminSettings.text()).toContain('Invalid CSRF Token');

    const governanceBaseline = await page.request.post(`${BASE}/app/governance/baseline`, {
      form: { project_id: String(PROJECT_ID) },
    });
    expect(governanceBaseline.status()).toBe(403);
    expect(await governanceBaseline.text()).toContain('Invalid CSRF Token');
  });
});
