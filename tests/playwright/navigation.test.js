// tests/playwright/navigation.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, testRedirect, handleAuthRequired } = require('./auth-helper.js');

test.describe('Navigation and File Reorganization', () => {
  test('homepage loads successfully', async ({ page }) => {
    await navigateAndWait(page, '/index.php');
    // Check for the actual title that includes "Home"
    await expect(page).toHaveTitle(/Home Lotus Elan Registry|Lotus Elan Registry/, { timeout: 10000 });
  });

  test('car listing page loads (reorganized)', async ({ page }) => {
    await navigateAndWait(page, '/app/cars/index.php');

    // Wait for network to be idle (all resources loaded)
    await page.waitForLoadState('networkidle');

    // Check for List Cars header with increased timeout
    await expect(page.locator('h2')).toContainText(/List Cars/, { timeout: 10000 });

    // Test backward compatibility redirect
    await testRedirect(page, '/app/list_cars.php', 'app/cars/index.php');
  });

  test('car details page loads (reorganized)', async ({ page }) => {
    // Navigate to car details page
    await navigateAndWait(page, '/app/cars/details.php?car_id=1');
    
    // Handle authentication requirement or verify content
    await handleAuthRequired(
      page,
      // Authenticated test - verify car details content
      async () => {
        await expect(page.locator('h1, h2, .card-header').first()).toContainText(/Car|Details|Information/);
      }
    );
    
    // Test backward compatibility redirect
    await testRedirect(page, '/app/car_details.php?car_id=1', 'app/cars/details.php');
  });

  test('car edit page loads (reorganized)', async ({ page }) => {
    // Navigate to car edit page
    await navigateAndWait(page, '/app/cars/edit.php');
    
    // Handle authentication requirement or verify edit form
    await handleAuthRequired(
      page,
      // Authenticated test - verify edit form elements
      async () => {
        await expect(page.locator('#progressbar')).toBeVisible();
      }
    );
    
    // Test backward compatibility redirect
    await testRedirect(page, '/app/edit_car.php', 'app/cars/edit.php');
  });

  test('statistics page loads (reorganized)', async ({ page }) => {
    // Navigate to statistics page
    await navigateAndWait(page, '/app/reports/statistics.php');
    
    // Look for statistics page content (page loads successfully)
    await expect(page.getByRole('heading', { name: /Where are the cars around the world/i })).toBeVisible();
    
    // Test backward compatibility redirect
    await testRedirect(page, '/app/statistics.php', 'app/reports/statistics.php');
  });

  test('contact page loads (reorganized)', async ({ page }) => {
    // Navigate to contact page
    await navigateAndWait(page, '/app/contact/index.php');
    
    // Handle authentication requirement or verify contact form
    await handleAuthRequired(
      page,
      // Authenticated test - verify contact form elements
      async () => {
        await expect(page.locator('h2')).toContainText(/Contact/);
      }
    );
    
    // Test backward compatibility redirect
    await testRedirect(page, '/app/contact.php', 'app/contact/index.php');
  });

  test('identification guide loads (reorganized)', async ({ page }) => {
    await navigateAndWait(page, '/app/cars/identify.php');
    await expect(page.locator('h2')).toContainText(/Identification/);

    // Test backward compatibility redirect
    await testRedirect(page, '/app/identification.php', 'app/cars/identify.php');
  });

  // Issue #559 — Documentation Reorganization
  test('docs/reference-library.php redirects to docs/reference/index.php', async ({ page }) => {
    // Test backward compatibility redirect for renamed reference library page
    await testRedirect(page, '/docs/reference-library.php', 'docs/reference/index.php');
  });

  test('docs/faq/index.php redirects to docs/guides/index.php', async ({ page }) => {
    // Test backward compatibility redirect for FAQ moved under guides
    await testRedirect(page, '/docs/faq/index.php', 'docs/guides/index.php');
  });

  test('docs/reference/index.php loads without error', async ({ page }) => {
    await navigateAndWait(page, '/docs/reference/index.php');

    // Verify the page did not return a 404 or 500 error
    await expect(page).not.toHaveTitle(/404|500|Not Found|Server Error/i);

    // Verify a primary heading is present
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('nav contains Reference dropdown', async ({ page }) => {
    await navigateAndWait(page, '/index.php');

    // Verify the navigation contains a visible "Reference" entry
    await expect(page.getByText(/Reference/i, { exact: false }).first()).toBeVisible();
  });
});