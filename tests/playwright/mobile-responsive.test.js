// tests/playwright/mobile-responsive.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, handleAuthRequired } = require('./auth-helper.js');

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
  '/',
  '/app/cars/index.php',
  '/app/cars/details.php?car_id=1',
  '/app/cars/factory.php',
  '/app/cars/identify.php',
  '/app/contact/index.php',
  '/app/contact/owner.php',
  '/app/reports/statistics.php',
  '/app/privacy.php',
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
    await navigateAndWait(page, '/app/cars/index.php');
    await page.waitForLoadState('networkidle');

    // Wait for DataTables to initialize
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });

    // Wait for the DataTables Responsive extension to inject its child-row
    // control cell (dtr-control class) — auto-retries until visible.
    const firstControl = page.locator('table.dataTable tbody tr td.dtr-control').first();
    await expect(firstControl).toBeVisible({ timeout: 10000 });
  });

  test('edit car form progress bar does not cause horizontal overflow', async ({ page }) => {
    await navigateAndWait(page, '/app/cars/edit.php');
    await page.waitForLoadState('networkidle');

    await handleAuthRequired(
      page,
      async () => {
        const overflow = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
        }));
        expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
      },
      async () => {
        const overflow = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
        }));
        expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
      }
    );
  });

  test('admin page tabs do not cause horizontal overflow', async ({ page }) => {
    // Admin is auth-protected. We don't log in here — instead we navigate
    // and let UserSpice render whatever it renders (login redirect or admin
    // page if a session exists). Either way, page-level horizontal overflow
    // should not occur at 375px.
    await navigateAndWait(page, '/users/admin.php');
    await page.waitForLoadState('networkidle');

    await handleAuthRequired(
      page,
      // Authenticated: verify admin tabs render without page overflow
      async () => {
        const overflow = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
        }));
        expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
      },
      // Unauthenticated: still verify the login/redirect page does not overflow
      async () => {
        const overflow = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
        }));
        expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);
      }
    );
  });
});
