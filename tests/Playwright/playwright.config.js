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
