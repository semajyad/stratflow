// @ts-check
const { test, expect } = require('@playwright/test');
const { ADMIN_EMAIL, ADMIN_PASS } = require('../test-constants');

const BASE        = process.env.BASE_URL || 'http://localhost:8890';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
  await page.evaluate(() => { if (typeof dismissOnboarding === 'function') dismissOnboarding(); }).catch(() => false);
}

test.describe('Strategy E2E flow', () => {

  test('create project → upload document → view diagram page → view work items page', async ({ page }) => {
    await loginAsAdmin(page);

    // --- Step 1: Create a project via the modal ---
    const projectName = `E2E Test Project ${Date.now()}`;
    await page.goto(`${BASE}/app/home`);

    // Wait until app.js has fully executed — openProjectModalById is defined globally
    await page.waitForFunction(() => typeof window.openProjectModalById === 'function');

    // Open the New Project modal
    await page.click('button:has-text("+ New Project"), button:has-text("New Project")');

    // Wait for the modal to be visible (hidden class removed by JS)
    await expect(page.locator('#new-project-modal')).not.toHaveClass(/hidden/);

    // Fill the project name input inside the modal
    await page.fill('#new-project-name', projectName);

    // Submit the modal form
    await page.click('#new-project-modal button[type="submit"]');

    // After creation, the project should appear (redirect to home or upload)
    await page.waitForLoadState('networkidle');
    // Check that we didn't get an error
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    // --- Step 2: Find the new project's ID from the page and navigate to upload ---
    // The app redirects to /app/upload?project_id=X after project creation,
    // or we find the project link on the home page.
    const currentUrl = page.url();

    let projectId = null;

    if (/project_id=(\d+)/.test(currentUrl)) {
      // Redirected to upload with project_id in URL
      projectId = currentUrl.match(/project_id=(\d+)/)[1];
    } else {
      // On home page — find the new project and its Open Project link
      const projectLink = page.locator(`.project-card:has-text("${projectName}") a[href*="project_id"]`).first();
      const href = await projectLink.getAttribute('href');
      const match = href && href.match(/project_id=(\d+)/);
      if (match) {
        projectId = match[1];
      }
    }

    if (!projectId) {
      throw new Error('Project creation failed — could not extract project_id from URL or page. Check the create-project modal flow.');
    }

    await page.goto(`${BASE}/app/upload?project_id=${projectId}`);
    await expect(page).toHaveURL(new RegExp(`/app/upload`));

    // Paste text instead of a file (avoids Gemini API dependency)
    const sampleText = `Company vision: Build the future of strategy execution.
Goals: 1) Increase revenue 20% 2) Expand to APAC 3) Launch mobile app.
Key results: Ship v2 by Q3. Hire 10 engineers. Close 5 enterprise deals.`;

    await page.fill('textarea[name="paste_text"]', sampleText);

    // Submit the upload form
    await page.click('#upload-form button[type="submit"]');

    // Should not show a server error
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    // --- Step 3: Visit diagram page — should load without 500 ---
    await page.goto(`${BASE}/app/diagram?project_id=${projectId}`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    // --- Step 4: Visit work items page — should load without 500 ---
    await page.goto(`${BASE}/app/work-items?project_id=${projectId}`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  }, { timeout: 60_000 });

});
