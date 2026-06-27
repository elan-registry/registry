// tests/playwright/mobile-responsive.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait } = require('./auth-helper.js');

/**
 * Mobile responsive tests at iPhone SE viewport (375x667).
 *
 * These tests assert that public pages render without introducing
 * horizontal page-level overflow on small screens, and that the
 * DataTables responsive plugin is active on the car listing page.
 *
 * The viewport is set explicitly via page.setViewportSize() in each
 * test so the file behaves consistently regardless of which Playwright
 * project (chromium / Mobile Chrome) it runs under.
 */

const MOBILE_VIEWPORT = { width: 375, height: 667 };

const PUBLIC_PAGES = [
  '',
  'app/cars/index.php',
  'app/cars/details.php?car_id=1091',
  'app/cars/factory.php',
  'app/cars/identify.php',
  'app/contact/index.php',
  'app/contact/owner.php',
  'app/reports/statistics.php',
  'app/privacy.php',
  'docs/guides/car-transfer-faq.php',
];

test.describe('Mobile Responsive (iPhone SE / 375px)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(MOBILE_VIEWPORT);
  });

  for (const pagePath of PUBLIC_PAGES) {
    test(`no horizontal overflow on ${pagePath}`, async ({ page }) => {
      await navigateAndWait(page, pagePath);
      await page.waitForLoadState('networkidle');

      // Page may redirect to login for auth-protected pages; still
      // verify no horizontal overflow on whatever rendered.
      const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
      }));

      expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
    });
  }

  test('DataTables responsive collapse indicator present on car listing', async ({ page }) => {
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    // DataTables 1.x uses .dataTables_wrapper; 2.x uses .dt-container
    await page.waitForSelector('table.dataTable, div.dt-container, div.dataTables_wrapper', { timeout: 15000 });

    const firstControl = page.locator('table.dataTable tbody tr td.dtr-control').first();
    await expect(firstControl).toBeVisible({ timeout: 15000 });
  });

  test('edit car form progress bar does not cause horizontal overflow', async ({ page }) => {
    await navigateAndWait(page, 'app/cars/form.php');
    await page.waitForLoadState('networkidle');

    // Check overflow regardless of auth state — both paths render a full page at 375px.
    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
  });

  test('admin page tabs do not cause horizontal overflow', async ({ page }) => {
    // Admin is auth-protected. We don't log in — UserSpice renders either the
    // admin page or a login redirect. Either way, no horizontal overflow at 375px.
    await navigateAndWait(page, 'users/admin.php');
    await page.waitForLoadState('networkidle');

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
  });
});
