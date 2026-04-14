// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const isCI = !!process.env.CI;

module.exports = defineConfig({
  testDir: '.',
  globalSetup: require.resolve('./global-setup'),
  globalTeardown: require.resolve('./global-teardown'),
  workers: 1,                     // serialise — shared DB, single server instance
  timeout: 30_000,
  expect: { timeout: isCI ? 15_000 : 5_000 },  // PHP dev server is slower in CI
  retries: isCI ? 2 : 0,          // retry flaky specs in CI only
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ...(isCI ? [['github']] : []),
  ],
  outputDir: 'test-results',
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8890',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: isCI ? 'retain-on-failure' : 'off',
  },
  projects: [
    // ── fast: Chromium only, runs on every PR ──────────────────────────────
    {
      name: 'fast',
      testMatch: 'fast/**/*.spec.js',
      use: { ...devices['Desktop Chrome'] },
    },

    // ── full: Chromium extended suite (a11y + visual), merge-to-main only ─
    {
      name: 'full',
      testMatch: 'full/**/*.spec.js',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['fast'],
    },

    // ── Cross-browser: merge-to-main only ─────────────────────────────────
    {
      name: 'firefox',
      testMatch: 'fast/**/*.spec.js',
      use: { ...devices['Desktop Firefox'] },
      dependencies: ['fast'],
    },
    {
      name: 'webkit',
      testMatch: 'fast/**/*.spec.js',
      use: { ...devices['Desktop Safari'] },
      dependencies: ['fast'],
    },
    {
      name: 'mobile-chrome',
      testMatch: 'fast/**/*.spec.js',
      use: { ...devices['Pixel 5'] },
      dependencies: ['fast'],
    },
  ],
});
