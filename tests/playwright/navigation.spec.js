// tests/playwright/navigation.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, testRedirect, handleAuthRequired } = require('./auth-helper.js');

test.describe('Navigation and File Reorganization', () => {
  test('homepage loads successfully', async ({ page }) => {
    await navigateAndWait(page, 'index.php');
    // Check for the actual title that includes "Home"
    await expect(page).toHaveTitle(/Home Lotus Elan Registry|Lotus Elan Registry/, { timeout: 10000 });
  });

  test('car listing page loads (reorganized)', async ({ page }) => {
    await navigateAndWait(page, 'app/owner/cars/index.php');

    // Wait for network to be idle (all resources loaded)
    await page.waitForLoadState('networkidle');

    // Check that the car listing heading is present
    await expect(page.locator('h2')).toContainText(/Registry Cars/, { timeout: 10000 });

    // Test backward compatibility redirect
    await testRedirect(page, 'app/list_cars.php', 'app/owner/cars/index.php');
  });

  test('car details page loads (reorganized)', async ({ page }) => {
    // Navigate to car details page
    await navigateAndWait(page, 'app/owner/cars/details.php?car_id=1');

    // Handle authentication requirement or verify content
    await handleAuthRequired(
      page,
      // Authenticated test - verify car details content
      async () => {
        await expect(page.locator('h1, h2, .card-header').first()).toContainText(/Car|Details|Information/);
      }
    );

    // Test backward compatibility redirect
    await testRedirect(page, 'app/car_details.php?car_id=1', 'app/owner/cars/details.php');
  });

  test('car form page loads (reorganized)', async ({ page }) => {
    // Navigate to car form page — verify it loads at the new URL (not 404/500)
    await navigateAndWait(page, 'app/owner/cars/edit.php');
    await expect(page).not.toHaveTitle(/404|Not Found|Server Error/i);
  });

  test('statistics page loads (reorganized)', async ({ page }) => {
    // Navigate to statistics page
    await navigateAndWait(page, 'app/owner/reports/statistics.php');

    // Look for statistics page heading (page loads successfully)
    await expect(page.getByRole('heading', { name: /Registry Analytics|Statistics/i })).toBeVisible();

    // Test backward compatibility redirect
    await testRedirect(page, 'app/statistics.php', 'app/owner/reports/statistics.php');
  });

  test('contact page loads (reorganized)', async ({ page }) => {
    // Navigate to contact page
    await navigateAndWait(page, 'app/owner/contact/index.php');

    // Handle authentication requirement or verify contact form
    await handleAuthRequired(
      page,
      // Authenticated test - verify contact form elements
      async () => {
        await expect(page.locator('h2')).toContainText(/Contact/);
      }
    );

    // Test backward compatibility redirect
    await testRedirect(page, 'app/contact.php', 'app/owner/contact/index.php');
  });

  test('identification guide loads (reorganized)', async ({ page }) => {
    await navigateAndWait(page, 'docs/reference/identification-guide.php');
    await expect(page.locator('h1')).toContainText(/Identification Guide/);
    expect(page.url()).toContain('docs/reference/identification-guide.php');

    // Test backward compatibility redirect chains
    await testRedirect(page, 'app/cars/identify.php', 'docs/reference/identification-guide.php');
    await testRedirect(page, 'app/identification.php', 'docs/reference/identification-guide.php');
  });

  // Issue #1040 — app/owner/ Phase 2 Migration
  test('app/cars/ paths redirect to app/owner/cars/ equivalents', async ({ page }) => {
    await testRedirect(page, 'app/cars/index.php', 'app/owner/cars/index.php');
    await testRedirect(page, 'app/cars/details.php', 'app/owner/cars/details.php');
    await testRedirect(page, 'app/cars/edit.php', 'app/owner/cars/edit.php');
    await testRedirect(page, 'app/cars/factory.php', 'app/owner/cars/factory.php');
  });

  test('app/contact/ paths redirect to app/owner/contact/ equivalents', async ({ page }) => {
    await testRedirect(page, 'app/contact/index.php', 'app/owner/contact/index.php');
    await testRedirect(page, 'app/contact/owner.php', 'app/owner/contact/owner.php');
  });

  test('app/reports/statistics.php and app/privacy.php redirect to app/owner/ equivalents', async ({ page }) => {
    await testRedirect(page, 'app/reports/statistics.php', 'app/owner/reports/statistics.php');
    await testRedirect(page, 'app/privacy.php', 'app/owner/privacy.php');
  });

  // Issue #559 — Documentation Reorganization
  test('docs/reference-library.php redirects to docs/reference/index.php', async ({ page }) => {
    // Test backward compatibility redirect for renamed reference library page
    await testRedirect(page, 'docs/reference-library.php', 'docs/reference/index.php');
  });

  test('docs/faq/index.php redirects to docs/guides/index.php', async ({ page }) => {
    // Test backward compatibility redirect for FAQ moved under guides
    await testRedirect(page, 'docs/faq/index.php', 'docs/guides/index.php');
  });

  test('docs/reference/index.php loads without error', async ({ page }) => {
    await navigateAndWait(page, 'docs/reference/index.php');

    // Verify the page did not return a 404 or 500 error
    await expect(page).not.toHaveTitle(/404|500|Not Found|Server Error/i);

    // Verify a primary heading is present
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('nav contains Reference dropdown', async ({ page }) => {
    await navigateAndWait(page, 'index.php');

    // Verify the navigation contains a visible "Reference" entry
    await expect(page.getByText(/Reference/i, { exact: false }).first()).toBeVisible();
  });
});