# Playwright Tester Sub-Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a tiered Playwright E2E test suite and a Claude Code sub-agent that runs it before every git commit, blocking commits on failure.

**Architecture:** A `playwright-tester` sub-agent inspects staged files, picks fast or full tier, manages the Docker stack lifecycle, runs `npx playwright test`, and writes a `.claude/.playwright-ok` marker on pass. A `pre_commit_playwright.py` hook (modelled on the existing `pre_commit_audit.py`) gates every `git commit` on that marker.

**Tech Stack:** Node.js 18+, `@playwright/test`, `mysql2` (globalSetup only), Docker Compose, Python 3 (hook), Claude Code sub-agent (Bash + Read)

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Create | `tests/Playwright/package.json` | Node deps (playwright, mysql2) |
| Create | `tests/Playwright/playwright.config.js` | Two projects: fast, full; globalSetup/Teardown |
| Create | `tests/Playwright/global-setup.js` | Create regular test user in DB before suite |
| Create | `tests/Playwright/global-teardown.js` | Remove test user after suite |
| Create | `tests/Playwright/fast/auth.spec.js` | Login, logout, wrong password, session expiry |
| Create | `tests/Playwright/fast/access-gates.spec.js` | Admin/superadmin route enforcement |
| Create | `tests/Playwright/fast/smoke.spec.js` | Key pages return 200, not 500 |
| Create | `tests/Playwright/full/strategy-flow.spec.js` | Create project → upload → diagram → work items |
| Create | `tests/Playwright/full/document-upload.spec.js` | Happy-path PDF upload |
| Create | `tests/Playwright/full/document-edge-cases.spec.js` | 0-byte, wrong MIME, oversized |
| Create | `tests/Playwright/full/multi-tenant.spec.js` | Cross-org IDOR attempt |
| Create | `tests/Playwright/full/stripe-flow.spec.js` | Billing page access control |
| Create | `.claude/agents/playwright-tester.md` | Sub-agent definition |
| Create | `.claude/hooks/pre_commit_playwright.py` | Commit gate hook |
| Modify | `.claude/settings.json` | Add hook to PreToolUse Bash matcher |
| Modify | `.gitignore` | Exclude test-results/ and Playwright node_modules/ |

---

## Task 1: Playwright Infrastructure

**Files:**
- Create: `tests/Playwright/package.json`
- Create: `tests/Playwright/playwright.config.js`
- Create: `tests/Playwright/global-setup.js`
- Create: `tests/Playwright/global-teardown.js`
- Modify: `.gitignore`

- [ ] **Step 1: Create `tests/Playwright/package.json`**

```json
{
  "name": "stratflow-playwright",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "test:fast": "npx playwright test --project=fast",
    "test:full": "npx playwright test --project=fast --project=full",
    "test": "npx playwright test"
  },
  "devDependencies": {
    "@playwright/test": "^1.52.0",
    "mysql2": "^3.11.0"
  }
}
```

- [ ] **Step 2: Install dependencies**

Run from `tests/Playwright/`:
```bash
cd tests/Playwright && npm install && npx playwright install chromium
```

Expected: `node_modules/` created, Chromium browser downloaded.

- [ ] **Step 3: Create `tests/Playwright/playwright.config.js`**

```js
// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  globalSetup: require.resolve('./global-setup'),
  globalTeardown: require.resolve('./global-teardown'),
  timeout: 30_000,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'test-results/report' }]],
  outputDir: 'test-results',
  use: {
    baseURL: 'http://localhost:8890',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'fast',
      testMatch: 'fast/**/*.spec.js',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'full',
      testMatch: 'full/**/*.spec.js',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['fast'],
    },
  ],
});
```

- [ ] **Step 4: Create `tests/Playwright/global-setup.js`**

```js
// global-setup.js — creates a regular (non-admin) test user before the suite runs.
// Uses the same password hash as the seed admin user ("password123").
const mysql = require('mysql2/promise');

const REGULAR_USER_EMAIL = 'pw_regular@test.invalid';
// bcrypt hash of "password123" — same as seed.sql admin user hash
const REGULAR_USER_HASH  = '$2y$12$iu6uq/e8YF48/fBVtgVgvOcavOH1KoGCGLTMfjxRDCy0aZrZgMor6';

async function globalSetup() {
  const conn = await mysql.createConnection({
    host: '127.0.0.1',
    port: 3307,
    user: 'stratflow',
    password: 'stratflow_secret',
    database: 'stratflow',
  });

  // Clean up in case a previous run left stale data
  await conn.execute('DELETE FROM users WHERE email = ?', [REGULAR_USER_EMAIL]);

  // Create regular user in org 1 (ThreePoints Demo from seed.sql)
  await conn.execute(
    'INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)',
    [1, REGULAR_USER_EMAIL, REGULAR_USER_HASH, 'Playwright Regular', 'user']
  );

  await conn.end();
  console.log('[globalSetup] regular test user created');
}

module.exports = globalSetup;
```

- [ ] **Step 5: Create `tests/Playwright/global-teardown.js`**

```js
const mysql = require('mysql2/promise');

async function globalTeardown() {
  const conn = await mysql.createConnection({
    host: '127.0.0.1',
    port: 3307,
    user: 'stratflow',
    password: 'stratflow_secret',
    database: 'stratflow',
  });

  await conn.execute('DELETE FROM users WHERE email = ?', ['pw_regular@test.invalid']);
  await conn.end();
  console.log('[globalTeardown] regular test user removed');
}

module.exports = globalTeardown;
```

- [ ] **Step 6: Update `.gitignore`**

Append to the existing `.gitignore`:

```
# Playwright
tests/Playwright/node_modules/
tests/Playwright/test-results/

# Claude Code — ephemeral markers
.claude/.playwright-ok
```

- [ ] **Step 7: Commit**

```bash
git add tests/Playwright/ .gitignore
git commit -m "feat: add Playwright infrastructure (config, globalSetup, package.json)"
```

---

## Task 2: Fast Suite — Auth Tests

**Files:**
- Create: `tests/Playwright/fast/auth.spec.js`

- [ ] **Step 1: Create `tests/Playwright/fast/auth.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

test.describe('Auth — login / logout / session', () => {

  test('valid credentials redirect to /app/home', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('wrong password shows error on login page', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/login`);
    await expect(page.locator('body')).toContainText(/invalid|incorrect|wrong|error/i);
  });

  test('non-existent user shows error on login page', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', 'nobody@test.invalid');
    await page.fill('input[name="password"]', 'whatever');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/login`);
    await expect(page.locator('body')).toContainText(/invalid|incorrect|wrong|error/i);
  });

  test('logout clears session and redirects to /login', async ({ page }) => {
    // Log in first
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/app/home`);

    // Submit logout form
    await page.click('button[data-action="logout"], form[action="/logout"] button, a[href="/logout"]');
    await expect(page).toHaveURL(/login|\/$/);
  });

  test('unauthenticated visit to /app/home redirects to /login', async ({ page }) => {
    // Fresh page context has no session cookie
    await page.goto(`${BASE}/app/home`);
    await expect(page).toHaveURL(`${BASE}/login`);
  });

});
```

- [ ] **Step 2: Run fast auth tests to verify they pass**

```bash
cd tests/Playwright && npx playwright test --project=fast fast/auth.spec.js --reporter=list
```

Expected: 5 passed. If logout selector doesn't match, inspect `templates/layouts/app.php` for the logout button and update the selector.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/fast/auth.spec.js
git commit -m "test: add Playwright fast auth suite"
```

---

## Task 3: Fast Suite — Access Gates

**Files:**
- Create: `tests/Playwright/fast/access-gates.spec.js`

- [ ] **Step 1: Create `tests/Playwright/fast/access-gates.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');

const BASE           = 'http://localhost:8890';
const ADMIN_EMAIL    = 'admin@stratflow.test';
const ADMIN_PASS     = 'password123';
const REGULAR_EMAIL  = 'pw_regular@test.invalid';
const REGULAR_PASS   = 'password123';

// Helper: log in as a given user and return the page at /app/home
async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Access gates — admin and superadmin routes', () => {

  test('regular user visiting /app/admin is redirected to /app/home', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('regular user visiting /app/admin/users is redirected to /app/home', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin/users`);
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('regular user visiting /superadmin is redirected', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/superadmin`);
    // AdminMiddleware redirects non-superadmin to /app/home
    await expect(page).not.toHaveURL(`${BASE}/superadmin`);
  });

  test('org_admin user can access /app/admin', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/admin`);
    await expect(page.locator('body')).not.toContainText(/500|error|exception/i);
  });

  test('org_admin user cannot access /superadmin', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/superadmin`);
    // org_admin is not superadmin — should be redirected
    await expect(page).not.toHaveURL(`${BASE}/superadmin`);
  });

  test('unauthenticated request to admin route redirects to /login', async ({ page }) => {
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/login`);
  });

});
```

- [ ] **Step 2: Run access gate tests**

```bash
cd tests/Playwright && npx playwright test --project=fast fast/access-gates.spec.js --reporter=list
```

Expected: 6 passed.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/fast/access-gates.spec.js
git commit -m "test: add Playwright fast access-gates suite"
```

---

## Task 4: Fast Suite — Smoke Tests

**Files:**
- Create: `tests/Playwright/fast/smoke.spec.js`

- [ ] **Step 1: Create `tests/Playwright/fast/smoke.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Smoke — key pages return 200 not 500', () => {

  test('public /login page loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/login`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('public /pricing page loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/pricing`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/home loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page.locator('h1')).toContainText(/welcome/i);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/upload loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);
    await expect(page).toHaveURL(`${BASE}/app/upload`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/diagram loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/diagram`);
    await expect(page).toHaveURL(`${BASE}/app/diagram`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/work-items loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/work-items`);
    await expect(page).toHaveURL(`${BASE}/app/work-items`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/risks loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/risks`);
    await expect(page).toHaveURL(`${BASE}/app/risks`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/prioritisation loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/prioritisation`);
    await expect(page).toHaveURL(`${BASE}/app/prioritisation`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/user-stories loads after login', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/user-stories`);
    await expect(page).toHaveURL(`${BASE}/app/user-stories`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

  test('/app/admin loads for admin user', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/admin`);
    await expect(page).toHaveURL(`${BASE}/app/admin`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|exception/i);
  });

});
```

- [ ] **Step 2: Run smoke tests**

```bash
cd tests/Playwright && npx playwright test --project=fast fast/smoke.spec.js --reporter=list
```

Expected: 10 passed.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/fast/smoke.spec.js
git commit -m "test: add Playwright fast smoke suite"
```

---

## Task 5: Full Suite — Strategy E2E Flow

**Files:**
- Create: `tests/Playwright/full/strategy-flow.spec.js`

- [ ] **Step 1: Create `tests/Playwright/full/strategy-flow.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs   = require('fs');
const os   = require('os');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Strategy E2E flow', () => {

  test('create project → upload document → view diagram page → view work items page', async ({ page }) => {
    await loginAsAdmin(page);

    // --- Step 1: Create a project ---
    const projectName = `E2E Test Project ${Date.now()}`;
    await page.goto(`${BASE}/app/home`);

    // Fill the create-project form (look for the project name input)
    await page.fill('input[name="name"], input[placeholder*="project" i]', projectName);
    await page.click('form button[type="submit"], button:has-text("Create")');

    // After creation, should still be on home or redirect — project appears in list
    await expect(page.locator('body')).toContainText(projectName);

    // --- Step 2: Navigate to upload for the new project ---
    await page.goto(`${BASE}/app/upload`);
    await expect(page).toHaveURL(/\/app\/upload/);

    // Paste text instead of a file (avoids Gemini API dependency)
    const sampleText = `Company vision: Build the future of strategy execution.
Goals: 1) Increase revenue 20% 2) Expand to APAC 3) Launch mobile app.
Key results: Ship v2 by Q3. Hire 10 engineers. Close 5 enterprise deals.`;

    await page.fill('textarea[name="paste_text"]', sampleText);

    // Select the project (if a dropdown exists)
    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      // Select the first option (seed project or our new one)
      await projectSelect.selectOption({ index: 0 });
    }

    await page.click('button[type="submit"]:has-text("Upload"), button[type="submit"]:has-text("Submit"), form#upload-form button[type="submit"]');

    // Should redirect to upload page or show success indicator
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    // --- Step 3: Visit diagram page —- should load without 500 ---
    await page.goto(`${BASE}/app/diagram`);
    await expect(page).toHaveURL(`${BASE}/app/diagram`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    // --- Step 4: Visit work items page — should load without 500 ---
    await page.goto(`${BASE}/app/work-items`);
    await expect(page).toHaveURL(`${BASE}/app/work-items`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  });

});
```

- [ ] **Step 2: Run strategy flow test**

```bash
cd tests/Playwright && npx playwright test --project=full full/strategy-flow.spec.js --reporter=list
```

Expected: 1 passed. Note: this test pastes text rather than uploading a PDF to avoid triggering Gemini API calls that require a live key.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/full/strategy-flow.spec.js
git commit -m "test: add Playwright full strategy E2E flow"
```

---

## Task 6: Full Suite — Document Upload & Edge Cases

**Files:**
- Create: `tests/Playwright/full/document-upload.spec.js`
- Create: `tests/Playwright/full/document-edge-cases.spec.js`

- [ ] **Step 1: Create `tests/Playwright/full/document-upload.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs   = require('fs');
const os   = require('os');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Document upload — happy path', () => {

  test('upload a .txt file succeeds and shows in document list', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);

    // Create a temp .txt file
    const tmpFile = path.join(os.tmpdir(), `strat-test-${Date.now()}.txt`);
    fs.writeFileSync(tmpFile, 'Test strategy document content for Playwright upload test.');

    // Set the project (seed project id=1)
    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ value: '1' });
    }

    // Upload the file
    await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
    await page.click('button[type="submit"]:visible');

    // No 500 error
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    fs.unlinkSync(tmpFile);
  });

});
```

- [ ] **Step 2: Create `tests/Playwright/full/document-edge-cases.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs   = require('fs');
const os   = require('os');

const BASE        = 'http://localhost:8890';
const ADMIN_EMAIL = 'admin@stratflow.test';
const ADMIN_PASS  = 'password123';

async function loginAsAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Document upload — edge cases', () => {

  test('submitting with no file and no text shows an error, not 500', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);

    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 0 });
    }

    // Submit with no file selected and no paste text
    await page.click('button[type="submit"]:visible');

    // Should stay on upload page (redirect back) with an error message, not a 500
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);
  });

  test('uploading a file with wrong MIME type (.exe) is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);

    const tmpFile = path.join(os.tmpdir(), `bad-file-${Date.now()}.exe`);
    fs.writeFileSync(tmpFile, Buffer.from('MZ')); // minimal EXE header

    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 0 });
    }

    await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
    await page.click('button[type="submit"]:visible');

    // App should redirect back or show error — no 500
    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    fs.unlinkSync(tmpFile);
  });

  test('uploading a 0-byte file is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);

    const tmpFile = path.join(os.tmpdir(), `empty-${Date.now()}.txt`);
    fs.writeFileSync(tmpFile, ''); // 0 bytes

    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 0 });
    }

    await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
    await page.click('button[type="submit"]:visible');

    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    fs.unlinkSync(tmpFile);
  });

  test('uploading a file over 50 MB is rejected gracefully', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE}/app/upload`);

    // Create a 51 MB file
    const tmpFile = path.join(os.tmpdir(), `oversized-${Date.now()}.txt`);
    const buf = Buffer.alloc(51 * 1024 * 1024, 'x');
    fs.writeFileSync(tmpFile, buf);

    const projectSelect = page.locator('select[name="project_id"]');
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 0 });
    }

    await page.setInputFiles('input[type="file"][name="document"]', tmpFile);
    await page.click('button[type="submit"]:visible');

    await expect(page.locator('body')).not.toContainText(/500|Fatal error/i);

    fs.unlinkSync(tmpFile);
  }, { timeout: 60_000 }); // allow extra time for large file upload

});
```

- [ ] **Step 3: Run document tests**

```bash
cd tests/Playwright && npx playwright test --project=full full/document-upload.spec.js full/document-edge-cases.spec.js --reporter=list
```

Expected: 5 passed.

- [ ] **Step 4: Commit**

```bash
git add tests/Playwright/full/document-upload.spec.js tests/Playwright/full/document-edge-cases.spec.js
git commit -m "test: add Playwright full document upload and edge cases suite"
```

---

## Task 7: Full Suite — Multi-Tenant Isolation

**Files:**
- Create: `tests/Playwright/full/multi-tenant.spec.js`

- [ ] **Step 1: Create `tests/Playwright/full/multi-tenant.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');

const BASE           = 'http://localhost:8890';
const ADMIN_EMAIL    = 'admin@stratflow.test';   // org_id=1
const ADMIN_PASS     = 'password123';
const REGULAR_EMAIL  = 'pw_regular@test.invalid'; // also org_id=1

// Helper
async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

async function getDbConn() {
  return mysql.createConnection({
    host: '127.0.0.1', port: 3307,
    user: 'stratflow', password: 'stratflow_secret', database: 'stratflow',
  });
}

test.describe('Multi-tenant isolation (IDOR prevention)', () => {

  let org2Id;
  let project2Id;

  test.beforeAll(async () => {
    const conn = await getDbConn();
    // Create a second org and project owned by it
    const [orgResult] = await conn.execute(
      "INSERT INTO organisations (name) VALUES ('Playwright Org 2')"
    );
    org2Id = orgResult.insertId;
    const [projResult] = await conn.execute(
      'INSERT INTO projects (org_id, name, status, created_by) VALUES (?, ?, ?, ?)',
      [org2Id, 'Org2 Secret Project', 'active', 0]
    );
    project2Id = projResult.insertId;
    await conn.end();
  });

  test.afterAll(async () => {
    const conn = await getDbConn();
    await conn.execute('DELETE FROM projects WHERE org_id = ?', [org2Id]);
    await conn.execute('DELETE FROM organisations WHERE id = ?', [org2Id]);
    await conn.end();
  });

  test('org 1 user cannot view org 2 diagram by guessing project_id', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    // Attempt to access org 2's project via URL parameter
    await page.goto(`${BASE}/app/diagram?project_id=${project2Id}`);
    // The controller should either redirect to home or render a blank/own-org diagram
    // — the org 2 project name must NOT appear in the response
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
    await loginAs(page, REGULAR_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/upload?project_id=${project2Id}`);
    // Should redirect to home (project not found for this org_id)
    await expect(page).not.toHaveURL(/app\/upload\?project_id=/);
    await expect(page.locator('body')).not.toContainText('Org2 Secret Project');
  });

});
```

- [ ] **Step 2: Run multi-tenant tests**

```bash
cd tests/Playwright && npx playwright test --project=full full/multi-tenant.spec.js --reporter=list
```

Expected: 3 passed.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/full/multi-tenant.spec.js
git commit -m "test: add Playwright full multi-tenant IDOR isolation suite"
```

---

## Task 8: Full Suite — Stripe Billing UI

**Files:**
- Create: `tests/Playwright/full/stripe-flow.spec.js`

- [ ] **Step 1: Create `tests/Playwright/full/stripe-flow.spec.js`**

```js
// @ts-check
const { test, expect } = require('@playwright/test');

const BASE           = 'http://localhost:8890';
const ADMIN_EMAIL    = 'admin@stratflow.test';
const ADMIN_PASS     = 'password123';
const REGULAR_EMAIL  = 'pw_regular@test.invalid';
const REGULAR_PASS   = 'password123';

async function loginAs(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(`${BASE}/app/home`);
}

test.describe('Stripe billing UI', () => {

  test('admin user can view /app/admin/billing without 500', async ({ page }) => {
    await loginAs(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`${BASE}/app/admin/billing`);
    await expect(page).toHaveURL(`${BASE}/app/admin/billing`);
    await expect(page.locator('body')).not.toContainText(/500|Fatal error|Uncaught/i);
  });

  test('regular user is redirected away from /app/admin/billing', async ({ page }) => {
    await loginAs(page, REGULAR_EMAIL, REGULAR_PASS);
    await page.goto(`${BASE}/app/admin/billing`);
    // AdminMiddleware redirects non-admin to /app/home
    await expect(page).toHaveURL(`${BASE}/app/home`);
  });

  test('/success page loads without 500', async ({ page }) => {
    // Success page is public — confirm it renders
    const response = await page.goto(`${BASE}/success`);
    expect(response?.status()).toBeLessThan(500);
    await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught/i);
  });

});
```

- [ ] **Step 2: Run stripe tests**

```bash
cd tests/Playwright && npx playwright test --project=full full/stripe-flow.spec.js --reporter=list
```

Expected: 3 passed.

- [ ] **Step 3: Commit**

```bash
git add tests/Playwright/full/stripe-flow.spec.js
git commit -m "test: add Playwright full Stripe billing UI suite"
```

---

## Task 9: Sub-Agent Definition

**Files:**
- Create: `.claude/agents/playwright-tester.md`

- [ ] **Step 1: Create `.claude/agents/playwright-tester.md`**

```markdown
---
name: playwright-tester
description: Run Playwright E2E tests before a git commit. Picks fast or full tier based on staged files, manages Docker lifecycle, writes .claude/.playwright-ok on pass, posts ntfy alert on fail. Invoke before any git commit.
tools: Bash, Read
model: sonnet
color: green
---

You are a Playwright test runner for StratFlow. Your job is to run the right tier of E2E tests before a commit, manage Docker, and either write the commit-gate marker or report failures.

## Step-by-step execution

### 1. Determine tier

Run:
```
git diff --cached --name-only
```

Read the list of changed files. Apply these rules:

- If ANY changed file matches `src/Controllers/`, `src/Services/FileProcessor`, or `templates/` → tier = **full**
- Otherwise → tier = **fast**

Print: `[playwright-tester] Tier: <fast|full> — reason: <which rule matched or "no full triggers">`

### 2. Check Docker state

Run:
```
docker compose ps --services --filter status=running
```

If the output contains both `php` and `mysql` (or `nginx`), Docker is already running. Set `DOCKER_STARTED=false`.

If output is empty or missing those services, set `DOCKER_STARTED=true` and run:
```
docker compose up -d
```

Then poll every 3 seconds for up to 60 seconds:
```
docker compose ps
```
Wait until both `mysql` and `nginx` show `running`. If 60 seconds elapse without both healthy, print an error and stop without writing the marker.

### 3. Run tests

Always run:
```
cd tests/Playwright && npx playwright test --project=fast --reporter=list 2>&1
```

Capture exit code and output. If exit code is non-zero, record failures and go to Step 5 (failure path).

If tier is **full** and fast passed:
```
cd tests/Playwright && npx playwright test --project=full --reporter=list 2>&1
```

Capture exit code and output. If exit code is non-zero, record failures and go to Step 5.

### 4. Pass path

Write the marker:
```
echo "" > .claude/.playwright-ok
```

Print a summary:
```
[playwright-tester] PASSED — <N> tests (fast|fast+full)
Marker written to .claude/.playwright-ok (valid 5 min).
You may now commit.
```

### 5. Failure path

Do NOT write the marker.

Post ntfy alert:
```
curl -s -X POST http://localhost:8090/stratflow-alerts \
  -H "Title: stratflow playwright FAILED" \
  -H "Priority: high" \
  -H "Tags: x,rotating_light" \
  -d "Failed tests:
<paste failing test names here>

Screenshots: tests/Playwright/test-results/
Run: cd tests/Playwright && npx playwright show-report"
```

Print all failing test names and screenshot paths from the Playwright output.
Tell the user: "Playwright tests failed. Fix the failures and re-run the playwright-tester agent before committing."

### 6. Docker teardown

If `DOCKER_STARTED=true` (you started Docker in Step 2):
```
docker compose down
```

If `DOCKER_STARTED=false`, leave Docker running.

## Notes

- The marker `.claude/.playwright-ok` is consumed (deleted) by the pre-commit hook on the next commit. It is valid for 5 minutes.
- Never skip tests or write the marker if any test failed.
- If `npx` is not found, run `cd tests/Playwright && npm install` first.
- If Playwright browsers are not installed, run `cd tests/Playwright && npx playwright install chromium`.
```

- [ ] **Step 2: Verify agent file is valid YAML frontmatter**

```bash
head -8 .claude/agents/playwright-tester.md
```

Expected output:
```
---
name: playwright-tester
description: Run Playwright E2E tests before a git commit...
tools: Bash, Read
model: sonnet
color: green
---
```

- [ ] **Step 3: Commit**

```bash
git add .claude/agents/playwright-tester.md
git commit -m "feat: add playwright-tester Claude Code sub-agent"
```

---

## Task 10: Pre-Commit Hook

**Files:**
- Create: `.claude/hooks/pre_commit_playwright.py`

- [ ] **Step 1: Create `.claude/hooks/pre_commit_playwright.py`**

```python
#!/usr/bin/env python3
"""PreToolUse: require a recent playwright-tester run before `git commit`.

Gates every `git commit` command. Looks for a marker file at
`.claude/.playwright-ok` modified within the last 5 minutes. If present
and fresh, the hook consumes it (deletes) and allows the commit. Otherwise
it blocks with a message instructing Claude to run the playwright-tester agent.

Exempted: `--dry-run`, merge commits (MERGE_HEAD present), and commits with
nothing staged (git will reject those anyway).
"""
import json
import os
import re
import subprocess
import sys
import time

MARKER_REL = ".claude/.playwright-ok"
MAX_AGE_SEC = 5 * 60  # 5 minutes


def main() -> int:
    raw = sys.stdin.read()
    if not raw:
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0

    command = (payload.get("tool_input") or {}).get("command") or ""
    if not isinstance(command, str) or not command:
        return 0

    # Only gate real commit invocations
    if not re.search(r"\bgit\s+commit\b", command):
        return 0
    # Skip dry-runs
    if "--dry-run" in command:
        return 0

    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()

    # Skip merge commits — MERGE_HEAD indicates an in-progress merge
    merge_head = os.path.join(project_dir, ".git", "MERGE_HEAD")
    if os.path.isfile(merge_head):
        return 0

    marker_path = os.path.join(project_dir, MARKER_REL)

    # Nothing staged? Let git reject it naturally
    try:
        r = subprocess.run(
            ["git", "diff", "--cached", "--quiet"],
            cwd=project_dir,
            capture_output=True,
        )
        if r.returncode == 0:
            return 0
    except (FileNotFoundError, OSError):
        return 0

    # Fresh marker? Consume it and allow
    if os.path.isfile(marker_path):
        try:
            age = time.time() - os.path.getmtime(marker_path)
        except OSError:
            age = MAX_AGE_SEC + 1
        if age <= MAX_AGE_SEC:
            try:
                os.remove(marker_path)
            except OSError:
                pass
            print(f"[pre-commit-playwright] Playwright marker consumed (age {int(age)}s) — commit allowed")
            return 0

    # Block with actionable instructions
    print("[pre-commit-playwright] BLOCKED: Playwright tests not verified.", file=sys.stderr)
    print("", file=sys.stderr)
    print("Ask Claude to run the `playwright-tester` agent before committing.", file=sys.stderr)
    print("The agent will run the appropriate test tier, manage Docker, and write", file=sys.stderr)
    print("the marker if all tests pass.", file=sys.stderr)
    print("", file=sys.stderr)
    print("The marker is valid for 5 minutes and is consumed on the next commit.", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 2: Commit**

```bash
git add .claude/hooks/pre_commit_playwright.py
git commit -m "feat: add pre_commit_playwright hook (marker gate)"
```

---

## Task 11: Wire Hook into Settings + Update .gitignore

**Files:**
- Modify: `.claude/settings.json`

- [ ] **Step 1: Add playwright hook to `.claude/settings.json`**

Open `.claude/settings.json`. In the `PreToolUse` array, add the playwright hook entry inside the `Bash` matcher, after the existing `pre_commit_audit.py` entry:

```json
{
  "type": "command",
  "command": "python \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_commit_playwright.py\""
}
```

The full `PreToolUse` Bash matcher block should look like this after editing:

```json
{
  "matcher": "Bash",
  "hooks": [
    {
      "type": "command",
      "command": "python \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_bash_guard.py\""
    },
    {
      "type": "command",
      "command": "python \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_commit_audit.py\""
    },
    {
      "type": "command",
      "command": "python \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_commit_playwright.py\""
    },
    {
      "type": "command",
      "command": "python \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_test_filter.py\""
    }
  ]
}
```

- [ ] **Step 2: Verify JSON is valid**

```bash
python -m json.tool .claude/settings.json > /dev/null && echo "JSON valid"
```

Expected: `JSON valid`

- [ ] **Step 3: Commit**

```bash
git add .claude/settings.json
git commit -m "feat: wire pre_commit_playwright hook into Claude Code settings"
```

---

## Task 12: End-to-End Verification

- [ ] **Step 1: Run the full Playwright suite manually**

With Docker running:
```bash
cd tests/Playwright && npx playwright test --reporter=list
```

Expected: all fast + full tests pass.

- [ ] **Step 2: Simulate the commit gate**

Stage a file and attempt a commit without the marker:
```bash
touch .gitignore && git add .gitignore
git commit -m "test commit"
```

Expected: blocked with `[pre-commit-playwright] BLOCKED: Playwright tests not verified.`

- [ ] **Step 3: Write marker and retry**

```bash
echo "" > .claude/.playwright-ok
git commit -m "test: verify playwright gate works"
```

Expected: `[pre-commit-playwright] Playwright marker consumed` — commit succeeds.

- [ ] **Step 4: Final commit**

```bash
git add .
git commit -m "test: verify full playwright gate integration"
```

---

## Self-Review Against Spec

| Spec requirement | Task |
|---|---|
| Fast tier: auth gates, login/logout, wrong password, session expiry | Task 2 |
| Fast tier: admin-only 403, superadmin gates | Task 3 |
| Fast tier: key pages 200 not 500 | Task 4 |
| Full tier: create org → strategy → work items → board | Task 5 |
| Full tier: document upload PDF + summarise | Task 6 (upload) |
| Full tier: 0-byte, wrong MIME, oversized, duplicate | Task 6 (edge cases) |
| Full tier: Stripe billing page, upgrade UI | Task 8 |
| Full tier: org A cannot see org B data | Task 7 |
| Tier trigger: controllers/templates/FileProcessor → full | Task 9 (agent step 1) |
| Docker lifecycle: check before up, only down if we started it | Task 9 (agent steps 2, 6) |
| ntfy alert on failure | Task 9 (agent step 5) |
| Marker gate hook (5 min, consumed on allow) | Task 10 |
| Merge commit exemption | Task 10 |
| Wired into settings.json | Task 11 |
| .gitignore updated for test-results/ and node_modules/ | Task 1 |
| Agent tools: Bash, Read; model: sonnet | Task 9 |
