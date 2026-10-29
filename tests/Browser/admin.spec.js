const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function expectAccessiblePage(page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();

  expect(results.violations, results.violations.map(violation => (
    violation.id + ': ' + violation.nodes.map(node => node.target.join(' ')).join(', ')
  )).join('\n')).toEqual([]);
}

test('admin lifecycle, safety states and mobile navigation', async ({ page }) => {
  const username = 'browser-admin';
  const password = 'Browser-Smoke-Password-2026!';

  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

  await page.getByRole('button', { name: 'Bootstrap', exact: true }).click();
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Email').fill('browser-admin@example.test');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Create admin' }).click();
  await expect(page.locator('[role="status"][aria-atomic="true"]')).toContainText('Admin created');

  await page.getByRole('navigation', { name: 'Primary navigation' }).getByRole('button', { name: 'Login' }).click();
  await page.getByLabel('Username or email').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.locator('#admin-content').getByRole('button', { name: 'Login', exact: true }).click();
  await expect(page.locator('.topbar')).toContainText(username);

  await page.reload();
  await expect(page.locator('.topbar')).toContainText(username);
  await expectAccessiblePage(page);

  const dashboardNavigation = page.getByRole('button', { name: 'Dashboard', exact: true });
  await dashboardNavigation.focus();
  await dashboardNavigation.press('End');
  await expect(page.getByRole('button', { name: 'Audit', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Audit' })).toBeVisible();

  await page.getByRole('button', { name: 'Operations', exact: true }).click();
  const dialog = page.waitForEvent('dialog');
  const rotateClick = page.getByRole('button', { name: 'Rotate signing key' }).click();
  const confirmation = await dialog;
  expect(confirmation.message()).toContain('Rotate the active signing key?');
  await confirmation.dismiss();
  await rotateClick;

  await page.route('**/api/v1/audit*', async route => {
    await new Promise(resolve => setTimeout(resolve, 400));
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'browser smoke failure' })
    });
  });
  await page.getByRole('button', { name: 'Audit', exact: true }).click();
  await page.getByRole('button', { name: 'Refresh audit' }).click();
  await expect(page.getByText('Loading…')).toBeVisible();
  await expect(page.getByRole('alert')).toHaveText('browser smoke failure');

  await page.setViewportSize({ width: 375, height: 812 });
  await page.getByRole('button', { name: 'Dashboard', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  const mobileLayout = await page.evaluate(() => ({
    sidebarDisplay: getComputedStyle(document.querySelector('.sidebar')).display,
    bodyOverflow: document.body.scrollWidth - document.body.clientWidth
  }));
  expect(mobileLayout.sidebarDisplay).toBe('flex');
  expect(mobileLayout.bodyOverflow).toBeLessThanOrEqual(1);
  await expectAccessiblePage(page);
});
