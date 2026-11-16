const { test, expect } = require('@playwright/test');
const { navigateAndWait, waitForDataTables, assertNoConsoleErrors } = require('./auth-helper.js');

test.describe('UI Consistency After Style Refactoring', () => {
  test('responsive design works on mobile', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    await navigateAndWait(page, 'app/owner/cars/index.php');

    // Check that content is still accessible
    const mainContent = page.locator('.page-wrapper, .container, .card');
    await expect(mainContent.first()).toBeVisible();

    // This test runs in the plain `chromium` project, which has no
    // storageState — index.php's securePage() redirects an unauthenticated
    // visit to login.php. Skip (not silently pass) the DataTables
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

  test('JavaScript files load without errors', async ({ page }) => {
    await assertNoConsoleErrors(page, 'app/owner/reports/statistics.php', 'statistics.php');
  });

  // Issue #1778 — dedicated console-error coverage for three static reference
  // pages that previously only had incidental page-title-loop coverage. See
  // assertNoConsoleErrors() in auth-helper.js for why status + full-render
  // assertions are needed together (chassis-validation.php has a history of
  // mid-render fatals: d8cb618d, 8b81ab57). Each test below also asserts a
  // row/card count so a silently truncated loop fails, not just a hard fatal.
  test('docs/car-stories.php has no console errors and renders all story cards', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/car-stories.php', 'car-stories.php');
    // $storyCards has 3 entries — count proves every card rendered, not just the last.
    await expect(page.locator('.row.mt-4 .registry-card')).toHaveCount(3);
    await expect(page.getByRole('heading', { name: 'Shapecraft Elan Story' })).toBeVisible();
  });

  test('docs/reference/chassis-validation.php has no console errors and renders race-car formats', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/chassis-validation.php', 'chassis-validation.php');
    // $validationRules['race_cars'] has 3 entries (1963, 1964, 1965-1966) —
    // count proves every iteration ran. The "Validation Override" heading
    // renders only after the loop's endforeach, proving the loop exited too.
    await expect(page.locator('.card.border-left-dark')).toHaveCount(3);
    await expect(page.getByRole('heading', { name: 'Validation Override' })).toBeVisible();
  });

  test('docs/reference/paint-colors.php has no console errors and renders the full color chart', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/paint-colors.php', 'paint-colors.php');
    // $officialColors has 27 entries — count proves every row rendered, not
    // just that the loop reached "Laurel Green" (the true last entry).
    await expect(page.locator('.card:has(#official-colors) table tbody tr')).toHaveCount(27);
    await expect(page.getByText('Laurel Green')).toBeVisible();
  });
});
