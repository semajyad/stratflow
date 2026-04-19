// @ts-check
/**
 * Key Results CRUD — create, verify, update, delete.
 *
 * Uses seed project_id=1 and creates a temporary HL work item when none exists.
 * Each test creates uniquely-named data to avoid cross-test pollution.
 */
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE         = process.env.BASE_URL || 'http://localhost:8890';
const PROJECT_ID   = 1;   // seed project

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

async function getCsrfToken(page) {
  return page.inputValue('input[name="_csrf_token"]');
}

/**
 * Returns { id, cleanup } where cleanup() deletes the work item only if
 * this helper created it. Callers must await cleanup() after their test.
 */
async function getOrCreateWorkItemId(page) {
  await page.goto(`${BASE}/app/work-items?project_id=${PROJECT_ID}`);
  const deleteForm = page.locator('form[action*="/app/work-items/"][action*="/delete"]').first();
  if (await deleteForm.count() > 0) {
    const action = await deleteForm.getAttribute('action') ?? '';
    const match = action.match(/\/work-items\/(\d+)\/delete/);
    if (match) {
      const id = parseInt(match[1], 10);
      return { id, cleanup: async () => {} }; // pre-existing — caller must not delete it
    }
  }

  // No existing item — create one and return a cleanup fn
  const csrf = await getCsrfToken(page);
  const createRes = await page.request.post(`${BASE}/app/work-items/store`, {
    form: {
      _csrf_token: csrf,
      project_id: String(PROJECT_ID),
      title: `KR Test Work Item ${Date.now()}`,
      description: 'Created by key-results-crud Playwright test',
      estimated_sprints: '1',
    },
  });
  expect(createRes.status()).toBeLessThan(400);

  await page.goto(`${BASE}/app/work-items?project_id=${PROJECT_ID}`);
  const form = page.locator('form[action*="/app/work-items/"][action*="/delete"]').first();
  const action = await form.getAttribute('action') ?? '';
  const match = action.match(/\/work-items\/(\d+)\/delete/);
  if (!match) {
    throw new Error(`Failed to recover fallback work item id after creation; action="${action}"`);
  }
  const id = parseInt(match[1], 10);

  return {
    id,
    cleanup: async () => {
      try {
        if (!id) return;
        await page.goto(`${BASE}/app/work-items?project_id=${PROJECT_ID}`);
        const delCsrf = await getCsrfToken(page);
        await page.request.post(`${BASE}/app/work-items/${id}/delete`, {
          form: { _csrf_token: delCsrf },
        }).catch(() => {});
      } catch (_) { /* best-effort cleanup */ }
    },
  };
}

async function deleteKr(page, krId) {
  try {
    await page.goto(`${BASE}/app/key-results`);
    const delCsrf = await getCsrfToken(page);
    await page.request.post(`${BASE}/app/key-results/${krId}/delete`, {
      form: { _csrf_token: delCsrf },
    }).catch(() => {});
  } catch (_) { /* best-effort cleanup */ }
}

test.describe('Key Results CRUD', () => {

  test('create a key result → verify it appears → delete it', async ({ page }) => {
    await loginAsAdmin(page);

    const { id: workItemId, cleanup: cleanupWorkItem } = await getOrCreateWorkItemId(page);
    expect(workItemId).toBeGreaterThan(0);

    let krId = null;
    try {
      await page.goto(`${BASE}/app/key-results`);
      const csrf = await getCsrfToken(page);

      const uniqueTitle = `PW Key Result ${Date.now()}`;

      // Create
      const createRes = await page.request.post(`${BASE}/app/key-results`, {
        form: {
          _csrf_token: csrf,
          hl_work_item_id: String(workItemId),
          title: uniqueTitle,
          metric_description: 'Automated test metric',
          baseline_value: '0',
          target_value: '100',
          current_value: '10',
          unit: '%',
        },
      });
      expect(createRes.status()).toBeLessThan(400);
      const createJson = await createRes.json();
      expect(createJson).toHaveProperty('id');
      krId = createJson.id;

      // Verify page loads without error and KR is present
      await page.goto(`${BASE}/app/key-results`);
      await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
      await expect(page.locator('body')).toContainText(uniqueTitle);

      // Delete (assertion step — proves endpoint works)
      await page.goto(`${BASE}/app/key-results`);
      const delCsrf = await getCsrfToken(page);
      const deleteRes = await page.request.post(`${BASE}/app/key-results/${krId}/delete`, {
        form: { _csrf_token: delCsrf },
      });
      expect(deleteRes.status()).toBeLessThan(400);
      krId = null; // deleted — skip finally cleanup
    } finally {
      if (krId) await deleteKr(page, krId);
      await cleanupWorkItem();
    }
  });

  test('create returns 400 with missing hl_work_item_id', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/key-results`);
    const csrf = await getCsrfToken(page);

    const res = await page.request.post(`${BASE}/app/key-results`, {
      form: {
        _csrf_token: csrf,
        hl_work_item_id: '0',
        title: 'Should fail',
      },
    });
    expect(res.status()).toBe(400);
    const json = await res.json();
    expect(json).toHaveProperty('error');
  });

  test('create returns 400 when title is empty', async ({ page }) => {
    await loginAsAdmin(page);
    const { id: workItemId, cleanup: cleanupWorkItem } = await getOrCreateWorkItemId(page);
    try {
      await page.goto(`${BASE}/app/key-results`);
      const csrf = await getCsrfToken(page);

      const res = await page.request.post(`${BASE}/app/key-results`, {
        form: {
          _csrf_token: csrf,
          hl_work_item_id: String(workItemId),
          title: '',
        },
      });
      expect(res.status()).toBe(400);
    } finally {
      await cleanupWorkItem();
    }
  });

  test('update changes current_value and returns ok', async ({ page }) => {
    await loginAsAdmin(page);
    const { id: workItemId, cleanup: cleanupWorkItem } = await getOrCreateWorkItemId(page);
    let krId = null;
    try {
      await page.goto(`${BASE}/app/key-results`);
      const csrf = await getCsrfToken(page);

      // Create
      const createRes = await page.request.post(`${BASE}/app/key-results`, {
        form: {
          _csrf_token: csrf,
          hl_work_item_id: String(workItemId),
          title: `PW KR Update ${Date.now()}`,
          baseline_value: '0',
          target_value: '50',
          current_value: '5',
          unit: 'pts',
        },
      });
      expect(createRes.status()).toBeLessThan(400);
      const createJson = await createRes.json();
      expect(createJson).toHaveProperty('id');
      krId = createJson.id;

      // Update
      await page.goto(`${BASE}/app/key-results`);
      const updateCsrf = await getCsrfToken(page);
      const updatedTitle = `PW KR Update ${krId}`;
      const updateRes = await page.request.post(`${BASE}/app/key-results/${krId}`, {
        form: {
          _csrf_token: updateCsrf,
          hl_work_item_id: String(workItemId),
          title: updatedTitle,
          current_value: '25',
          target_value: '50',
        },
      });
      expect(updateRes.status()).toBeLessThan(400);
      expect(await updateRes.json()).toEqual(expect.objectContaining({ ok: true }));
      await page.goto(`${BASE}/app/key-results`);
      await expect(page.locator('body')).toContainText(updatedTitle);
    } finally {
      if (krId) await deleteKr(page, krId);
      await cleanupWorkItem();
    }
  });

});
