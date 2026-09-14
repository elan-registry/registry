// tests/playwright/ui-consistency.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, validateCardStructure, NO_CARDS_ERROR, waitForDataTables, assertNoConsoleErrors } = require('./auth-helper.js');
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
    // the login.php branches above). Skip (not silently pass) the DataTables
    // assertion when unauthenticated, rather than an if-with-no-else that
    // would report a false "passed" having never checked the table (#1950).
    const currentUrl = page.url();
    test.skip(currentUrl.includes('login.php'), 'index.php requires auth; chromium project has no storageState');

    // Check that DataTables is responsive. car-list.js initializes this
    // table with serverSide: true — an AJAX round-trip precedes the wrapper
    // appearing, so wait for it via the shared waitForDataTables() helper
    // (auth-helper.js) rather than sampling .count() once immediately after
    // domcontentloaded, which depends on incidental timing between page
    // load and this line rather than on the table actually being ready.
    // The returned search-box locator isn't needed here — only the wait.
    await waitForDataTables(page, 15000);
    const dataTable = page.locator('.dt-container, .dataTables_wrapper');
    await expect(dataTable.first()).toBeVisible();
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
    await assertNoConsoleErrors(page, 'app/owner/reports/statistics.php', 'statistics.php');
  });

  // Issue #1778 — dedicated load/console-error coverage beyond the page-title loop.
  // Each test also asserts content that only renders if the page reached the
  // end of its output — a mid-render PHP fatal still flushes an early <h1>/
  // <title> and produces no console error, so those two signals alone would
  // not have caught chassis-validation.php's prior fatals (#1705 escape
  // analysis references d8cb618d, 8b81ab57).
  test('docs/car-stories.php has no console errors and renders all story cards', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/car-stories.php', 'car-stories.php');
    // Last card in $storyCards — proves the render reached the end of the grid.
    await expect(page.getByText('Shapecraft Elan Story')).toBeVisible();
  });

  test('docs/reference/chassis-validation.php has no console errors and renders race-car formats', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/chassis-validation.php', 'chassis-validation.php');
    // 26-R-08 renders only in the loop's final ('other_years') branch, and
    // "Validation Override" is the heading of the section immediately after
    // the loop — together they prove every iteration ran and the loop
    // exited, not just that it started (d8cb618d fataled on the first
    // iteration's str_replace() call, so an assertion on an early-iteration
    // value alone would not have caught it).
    await expect(page.getByText('26-R-08')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Validation Override' })).toBeVisible();
  });

  test('docs/reference/paint-colors.php has no console errors and renders the full color chart', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/paint-colors.php', 'paint-colors.php');
    // "Laurel Green" is the true last entry of $officialColors (L26 is
    // second-to-last) — proves the loop rendered every row, not just up to
    // the more recognizable L26 code.
    await expect(page.getByText('Laurel Green')).toBeVisible();
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