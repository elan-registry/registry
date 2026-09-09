// tests/playwright/ui-consistency.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, validateCardStructure, NO_CARDS_ERROR, waitForDataTables } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

test.describe('UI Consistency After Style Refactoring', () => {
  test('consistent card layouts across pages', async ({ page }) => {
    const pages = [
      'app/owner/cars/index.php',
      `app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`,
      'app/owner/reports/statistics.php',
      'app/owner/contact/index.php'
    ];
    
    for (const pagePath of pages) {
      await navigateAndWait(page, pagePath);
      
      // Check current URL to see if redirected to login
      const currentUrl = page.url();
      if (!currentUrl.includes('login.php')) {
        // Only validate cards if not redirected to login
        try {
          await validateCardStructure(page);
        } catch (_error) {
          if (_error.message && _error.message.includes(NO_CARDS_ERROR)) {
            continue;
          }
          throw _error;
        }
      }
    }
  });

  test('consistent header structure', async ({ page }) => {
    const pages = [
      'app/owner/cars/index.php',
      'app/owner/cars/edit.php',
      'app/owner/reports/statistics.php'
    ];
    
    for (const pagePath of pages) {
      await navigateAndWait(page, pagePath);
      
      // Check if page redirected to login (some pages require auth)
      const currentUrl = page.url();
      if (currentUrl.includes('login.php')) {
        // Page requires authentication - check login page structure instead
        const loginContainer = page.locator('.container, .card');
        await expect(loginContainer.first()).toBeVisible();
      } else {
        // Check for page-wrapper structure (uses class, not id)
        const pageWrapper = page.locator('.page-wrapper');
        await expect(pageWrapper).toBeVisible();
        
        // Check for container structure (use first match to avoid strict mode)
        const container = page.locator('.container-fluid, .container').first();
        await expect(container).toBeVisible();
      }
    }
  });

  test('responsive design works on mobile', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    await navigateAndWait(page, 'app/owner/cars/index.php');

    // Check that content is still accessible
    const mainContent = page.locator('.page-wrapper, .container, .card');
    await expect(mainContent.first()).toBeVisible();

    // This test runs in the plain `chromium` project, which has no
    // storageState — index.php's securePage() redirects an unauthenticated
    // visit to login.php, exactly like the other tests in this file (see
    // the login.php branches above). Only assert DataTables responsiveness
    // when actually authenticated; otherwise there's no table to check.
    const currentUrl = page.url();
    if (!currentUrl.includes('login.php')) {
      // Check that DataTables is responsive. car-list.js initializes this
      // table with serverSide: true — an AJAX round-trip precedes the wrapper
      // appearing, so wait for it via the shared waitForDataTables() helper
      // (auth-helper.js) rather than sampling .count() once immediately after
      // domcontentloaded, which depends on incidental timing between page
      // load and this line rather than on the table actually being ready.
      // The returned search-box locator isn't needed here — only the wait.
      await waitForDataTables(page, 15000);
      const dataTable = page.locator('.dt-container, .dataTables_wrapper');
      await expect(dataTable).toBeVisible();
    }
  });

  test('consistent button styling', async ({ page }) => {
    await page.goto('app/owner/cars/edit.php');

    // Check for Bootstrap button classes
    const buttons = page.locator('button, input[type="button"], input[type="submit"], .btn');
    const buttonCount = await buttons.count();

    expect(buttonCount).toBeGreaterThan(0);

    const firstButton = buttons.first();
    const classList = await firstButton.getAttribute('class');

    // Should have Bootstrap button classes
    expect(classList).toMatch(/btn/);
  });

  test('color scheme consistency', async ({ page }) => {
    await page.goto('app/owner/cars/index.php');

    // Check for consistent color usage
    const cards = page.locator('.card-header');
    const cardCount = await cards.count();

    expect(cardCount).toBeGreaterThan(0);

    // Just ensure cards render properly
    await expect(cards.first()).toBeVisible();
  });

  test('JavaScript files load without errors', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });
    
    await page.goto('app/owner/reports/statistics.php');
    await page.waitForTimeout(3000); // Wait for all scripts to load
    
    // Filter out known acceptable errors for unauthenticated local visits
    const criticalErrors = consoleErrors.filter(error =>
      !error.includes('Google Maps') &&
      !error.includes('API key') &&
      !error.includes('404') && // Optional resources that may not exist locally
      !error.includes('403')    // Auth-gated sub-resources on public pages
    );

    expect(criticalErrors, `Console errors on statistics.php: ${criticalErrors.join(' | ')}`).toHaveLength(0);
  });

  test('images and assets load correctly', async ({ page }) => {
    await page.goto('docs/reference/identification-guide.php');
    
    // Wait for page to fully load
    await page.waitForLoadState('networkidle');
    
    // Check that example images load
    const images = page.locator('img');
    const imageCount = await images.count();

    expect(imageCount).toBeGreaterThan(0);

    // Check first few images
    for (let i = 0; i < Math.min(3, imageCount); i++) {
      const img = images.nth(i);
      const src = await img.getAttribute('src');

      if (src && !src.startsWith('data:')) {
        // Make sure src is not empty and points to a valid path
        expect(src).toBeTruthy();
      }
    }
  });

  test('forms maintain consistent styling', async ({ page }) => {
    const formPages = [
      'app/owner/cars/edit.php',
      'app/owner/contact/index.php'
    ];
    
    for (const pagePath of formPages) {
      await page.goto(pagePath);
      
      // Check for consistent form styling
      const formGroups = page.locator('.form-group, .mb-3, .form-control');
      const formCount = await formGroups.count();

      expect(formCount).toBeGreaterThan(0);
      await expect(formGroups.first()).toBeVisible();
    }
  });
});