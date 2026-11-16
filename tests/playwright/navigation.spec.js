const { test, expect } = require('@playwright/test');
const { navigateAndWait, testRedirect, handleAuthRequired } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

test.describe('Navigation and File Reorganization', () => {
  test('car details page loads (reorganized)', async ({ page }) => {
    // Navigate to car details page
    await navigateAndWait(page, `app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`);

    // Handle authentication requirement or verify content
    await handleAuthRequired(
      page,
      // Authenticated test - verify car details content. The heading now
      // renders the car's actual identity (e.g. "1966 Lotus Elan S3
      // (FHC-preairflow)") rather than a generic "Car Details" label, so
      // assert structure (a non-empty heading) instead of stale wording —
      // matches this test's stated intent ("car details page loads").
      async () => {
        const heading = page.locator('h1, h2, .card-header').first();
        await expect(heading).toBeVisible();
        const headingText = await heading.textContent();
        expect(headingText.trim().length).toBeGreaterThan(0);
      }
    );
  });

  test('car form page loads (reorganized)', async ({ page }) => {
    // Navigate to car form page — verify it loads at the new URL (not 404/500)
    await navigateAndWait(page, 'app/owner/cars/edit.php');
    await expect(page).not.toHaveTitle(/404|Not Found|Server Error/i);
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

  // Issue #1040 — app/owner/ Phase 2 Migration
  test('app/cars/ legacy paths redirect to their new locations', async ({ page }) => {
    await testRedirect(page, 'app/cars/index.php', 'app/owner/cars/index.php');
    await testRedirect(page, 'app/cars/details.php', 'app/owner/cars/details.php');
    await testRedirect(page, 'app/cars/edit.php', 'app/owner/cars/edit.php');
    await testRedirect(page, 'app/cars/factory.php', 'app/owner/cars/factory.php');
    await testRedirect(page, 'app/cars/identify.php', 'docs/reference/identification-guide.php');
  });

  // Issue #1803 — retired usersc/templates/ElanRegistry/assets/images/ path
  test('retired template image path redirects to usersc/images/', async ({ page }) => {
    await testRedirect(
      page,
      'usersc/templates/ElanRegistry/assets/images/apple-touch-icon.png',
      'usersc/images/apple-touch-icon.png'
    );
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

  test('nav contains Reference dropdown', async ({ page }) => {
    await navigateAndWait(page, 'index.php');

    // The Reference dropdown toggle is an <a role="button"> in file_nav_custom.php.
    // Not exact: the accessible name starts with the icon font glyph.
    await expect(page.getByRole('button', { name: 'Reference' })).toBeVisible();
  });
});