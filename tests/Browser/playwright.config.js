const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: 'admin.spec.js',
  timeout: 30000,
  expect: { timeout: 5000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['line']],
  outputDir: '/tmp/playwright-results',
  use: {
    baseURL: process.env.BROWSER_BASE_URL || 'http://browser-app:8081',
    browserName: 'chromium',
    headless: true,
    trace: 'off',
    screenshot: 'only-on-failure'
  }
});
