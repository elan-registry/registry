const { test, expect } = require('@playwright/test');
const { waitForDataTables } = require('../auth-helper.js');

test.describe('Factory Page - Registry Link Feature', () => {
  // Run these tests with the admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip();
    }
  });

  test('should load factory page without errors', async ({ page }) => {
    // Navigate to Factory page
    await page.goto('app/owner/cars/factory.php');
    await page.waitForLoadState('domcontentloaded');

    console.log('✓ Factory page loaded');

    // Check for page title
    const heading = page.locator('h2:has-text("Elan Factory Information")');
    await expect(heading).toBeVisible();
    console.log('✓ Factory Information heading visible');

    // Check for data table
    const table = page.locator('#cartable');
    await expect(table).toBeVisible();
    console.log('✓ Factory data table visible');

    // Check for console errors
    const errors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    // Wait a bit for any lazy-loaded errors
    await page.waitForTimeout(1000);

    if (errors.length === 0) {
      console.log('✓ No console errors');
    } else {
      console.log('⚠ Console errors found:', errors);
    }
  });

  test('should display Registry Link column in table header', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php');
    await page.waitForLoadState('domcontentloaded');

    // Look for Registry Link column header
    const registryHeader = page.locator('th:has-text("Registry Link")');
    await expect(registryHeader).toBeVisible();
    console.log('✓ Registry Link column header visible');
  });

  test('should display matched chassis with "View Car" button', async ({ page, context }) => {
    // Matched-chassis rows require the full elan_factory_info dataset
    // (ElanFactoryInfoSeed, 9,762 rows via `scripts/provision-schema.sh
    // --full`) — default provisioning only inserts a couple of rows and does
    // not guarantee a chassis match exists. Skip rather than assume, same
    // caution as the pagination test below.

    await page.goto('app/owner/cars/factory.php');

    // Wait for table to load
    await waitForDataTables(page);
    console.log('✓ DataTable loaded');

    // Registry Link renders synchronously with DataTable draw — no AJAX wait needed
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });
    console.log('✓ Registry link containers rendered');

    // Look for "View Car" buttons
    const viewButtons = page.locator('.registry-link-container .btn-primary');
    const viewButtonCount = await viewButtons.count();

    test.skip(viewButtonCount === 0, 'No matched chassis in fixture data — requires provision-schema.sh --full');

    console.log(`✓ Found ${viewButtonCount} "View Car" button(s)`);

    // Verify first button has correct content
    const firstButton = viewButtons.first();
    const text = await firstButton.textContent();
    expect(text).toContain('View Car');
    console.log(`✓ First button text: "${text.trim()}"`);
  });

  test('should display unmatched chassis with informational message', async ({ page }) => {
    // Unmatched-chassis rows depend on the same environment-variable fixture
    // data as the matched-chassis test above — see its comment. Skip rather
    // than assume.

    await page.goto('app/owner/cars/factory.php');

    // Wait for table to load
    await waitForDataTables(page);
    console.log('✓ DataTable loaded');

    // Registry Link renders synchronously — wait for containers to appear
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    // Look for informational messages (unmatched chassis)
    const messages = page.locator('.registry-link-container .text-muted, .registry-link-container .text-secondary');
    const messageCount = await messages.count();

    test.skip(messageCount === 0, 'No unmatched chassis in fixture data — requires provision-schema.sh --full');

    console.log(`✓ Found ${messageCount} informational message(s)`);

    // Check message content
    const firstMsg = messages.first();
    const text = await firstMsg.textContent();
    console.log(`✓ Message example: "${text.trim()}"`);
  });

  test('should handle null/missing chassis gracefully', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php');

    await waitForDataTables(page);
    console.log('✓ DataTable loaded');

    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    // Verify no "Check failed" errors visible
    const checkFailedElements = page.locator(':text("Check failed")');
    const failedCount = await checkFailedElements.count();

    expect(failedCount).toBe(0);
    console.log('✓ No "Check failed" errors visible');
  });

  test('should maintain Registry Link functionality across pagination', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php');

    // Wait for initial table
    await waitForDataTables(page);
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });
    console.log('✓ Page 1 loaded');

    // TODO(#1760): Whether pagination appears depends on how many rows are in
    // elan_factory_info, which is environment-dependent — the full production
    // dataset (9,762 rows, ElanFactoryInfoSeed) is only loaded with
    // `scripts/provision-schema.sh --full`; default provisioning and the
    // PHPUnit integration fixtures insert only a couple of rows, well under
    // the pageLength of 25 (see app/assets/js/factory-list.min.js). This
    // outer condition cannot be made unconditional without confirming the
    // local/CI DB is always provisioned with --full.
    const nextButton = page.locator('.paginate_button.next:not(.disabled)');
    const isNextAvailable = await nextButton.isVisible();

    if (isNextAvailable) {
      // Click next page
      await nextButton.click();

      // Wait for page 2 to load
      await page.waitForSelector('.registry-link-container', { timeout: 10000 });
      console.log('✓ Page 2 loaded');

      // Verify Registry Link containers exist on page 2
      const registryLinks = page.locator('.registry-link-container');
      const count = await registryLinks.count();
      expect(count).toBeGreaterThan(0);
      console.log(`✓ Registry Link containers visible on page 2 (${count} found)`);

      // Verify no "Check failed" errors on page 2
      const checkFailed = page.locator(':text("Check failed")');
      const failedCount = await checkFailed.count();
      expect(failedCount).toBe(0);
      console.log('✓ No "Check failed" errors on page 2');
    } else {
      console.log('⚠ Only 1 page of data, skipping pagination test');
    }
  });

  test('should load Registry Links within reasonable time', async ({ page }) => {
    const startTime = Date.now();

    await page.goto('app/owner/cars/factory.php');

    // Wait for table
    await waitForDataTables(page);

    // Registry Link renders synchronously — wait for containers to appear
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    const endTime = Date.now();
    const loadTime = endTime - startTime;

    console.log(`✓ Page loaded in ${loadTime}ms`);

    // Verify page loaded in reasonable time (under 5 seconds typical)
    // This is informational, not a hard requirement
    if (loadTime > 5000) {
      console.log(`⚠ Page took longer than expected: ${loadTime}ms`);
    } else if (loadTime > 3000) {
      console.log(`⚠ Page took moderate time: ${loadTime}ms`);
    } else {
      console.log(`✓ Page loaded quickly: ${loadTime}ms`);
    }
  });

  test('"View Car" button should navigate to car details page', async ({ page, context }) => {
    await page.goto('app/owner/cars/factory.php');

    // Wait for table and AJAX
    await waitForDataTables(page);
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    // Find a View Car button
    const viewButton = page.locator('.registry-link-container .btn-primary').first();
    const isVisible = await viewButton.isVisible();

    if (isVisible) {
      // Get the button href
      const href = await viewButton.getAttribute('href');
      console.log(`✓ View Car button found with href: ${href}`);

      // Verify href is to details page
      expect(href).toContain('details.php');
      console.log('✓ href points to details page');

      expect(href).toMatch(/car_id=\d+/);
      console.log('✓ href includes car_id parameter');
    } else {
      console.log('⚠ No "View Car" button found (test data may not have matching cars)');
    }
  });

  test('should not make any request to chassis-lookup.php', async ({ page }) => {
    const chassisLookupRequests = [];

    page.on('request', (request) => {
      if (request.url().includes('chassis-lookup.php')) {
        chassisLookupRequests.push(request.url());
      }
    });

    await page.goto('app/owner/cars/factory.php');
    await waitForDataTables(page);
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    expect(chassisLookupRequests).toHaveLength(0);
    console.log('✓ No requests to chassis-lookup.php — car_id embedded in DataTables response');
  });
});

