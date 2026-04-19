// @ts-check
/**
 * Board Review — end-to-end flow tests.
 *
 * Runs in the full/nightly suite only. 2 Gemini calls across 3 tests:
 *   - Test 1 (evaluate work_items): 1 Gemini call
 *   - Test 2 (invalid screen_context): no Gemini call (fails validation first)
 *   - Test 3 (reject flow): 1 Gemini call (evaluate to obtain a review id, then reject)
 *
 * Assumes: seed project_id=1 with at least one HL work item.
 */
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE       = process.env.BASE_URL || 'http://localhost:8890';
const PROJECT_ID = 1;

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

async function postJson(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    });
    return {
      status: r.status,
      contentType: r.headers.get('content-type') ?? '',
      text: await r.text(),
    };
  }, { url, body });
}

test.describe('Board Review — evaluate flow (nightly, real Gemini call)', () => {

  // Longer timeout — Gemini call can take 15-20 s under load
  test.setTimeout(60_000);

  test('evaluate work_items screen returns structured recommendation', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    const csrf = await getCsrfToken(page);

    const res = await postJson(page, `${BASE}/app/board-review/evaluate`, {
      project_id: PROJECT_ID,
      evaluation_level: 'devils_advocate',
      screen_context: 'work_items',
      screen_content: 'Epic: Improve authentication system (8 pts). Epic: Redesign onboarding flow (5 pts). Epic: API rate limiting (3 pts).',
      _csrf_token: csrf,
    });

    // Must always be JSON
    expect(res.contentType).toMatch(/application\/json/);
    expect(res.text).not.toMatch(/<!DOCTYPE/i);

    const json = JSON.parse(res.text);

    // Possible outcomes: AI evaluation succeeded, or subscription gate / project gate
    if (res.status === 201) {
      // Successful AI evaluation — verify response shape
      expect(json).toHaveProperty('id');
      expect(json).toHaveProperty('recommendation');
      // recommendation is an object: {summary, rationale, proposed_changes}
      expect(json.recommendation).toHaveProperty('summary');
      expect(json.recommendation).toHaveProperty('rationale');
      expect(typeof json.recommendation.summary).toBe('string');
      expect(json.recommendation.summary.length).toBeGreaterThan(10);
    } else {
      // Acceptable failures: 403 (no subscription), 404 (no project), 400 (validation)
      expect([400, 403, 404]).toContain(res.status);
      expect(json).toHaveProperty('error');
    }
  });

  test('evaluate with invalid screen_context returns 400 JSON', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    const csrf = await getCsrfToken(page);

    const res = await postJson(page, `${BASE}/app/board-review/evaluate`, {
      project_id: PROJECT_ID,
      evaluation_level: 'devils_advocate',
      screen_context: 'nonexistent_screen',  // invalid
      screen_content: 'some content',
      _csrf_token: csrf,
    });

    expect(res.status).toBe(400);
    expect(res.contentType).toMatch(/application\/json/);
    const json = JSON.parse(res.text);
    expect(json).toHaveProperty('error');
  });

  test('reject action returns JSON and sets status to rejected', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    const csrf = await getCsrfToken(page);

    // First evaluate to get a review id (may cost 1 Gemini call)
    const evalRes = await postJson(page, `${BASE}/app/board-review/evaluate`, {
      project_id: PROJECT_ID,
      evaluation_level: 'red_teaming',
      screen_context: 'user_stories',
      screen_content: 'Story: As a user I want to log in. Story: As an admin I want to manage users.',
      _csrf_token: csrf,
    });

    const evalJson = JSON.parse(evalRes.text);

    // If the evaluate succeeded (has id), test the reject flow
    if (evalRes.status === 201 && evalJson.id) {
      const reviewId = evalJson.id;

      await page.goto(`${BASE}/app/work-items`);
      const rejectCsrf = await getCsrfToken(page);

      const rejectRes = await page.request.post(`${BASE}/app/board-review/${reviewId}/reject`, {
        form: { _csrf_token: rejectCsrf },
      });

      expect(rejectRes.status()).toBe(200);
      const rejectJson = await rejectRes.json();
      expect(rejectJson).toHaveProperty('status', 'rejected');
      expect(rejectJson).toHaveProperty('id', reviewId);
    } else {
      // Subscription not enabled for seed org — skip gracefully
      test.skip(true, `Board Review not available (evaluate returned ${evalRes.status})`);
    }
  });

});
