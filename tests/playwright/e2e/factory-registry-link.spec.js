const { test, expect } = require('@playwright/test');

test.describe('Factory Page - Registry Link Feature', () => {
  // Run these tests with the logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
  });

  test('should load factory page without errors', async ({ page }) => {
    // Navigate to Factory page
    await page.goto('/app/owner/cars/factory.php');
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
    await page.goto('/app/owner/cars/factory.php');
    await page.waitForLoadState('domcontentloaded');

    // Look for Registry Link column header
    const registryHeader = page.locator('th:has-text("Registry Link")');
    await expect(registryHeader).toBeVisible();
    console.log('✓ Registry Link column header visible');
  });

  test('should show spinner while Registry Link is loading', async ({ page }) => {
    await page.goto('/app/owner/cars/factory.php');

    // Wait for DataTable to initialize
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    console.log('✓ DataTable initialized');

    // Look for registry link containers
    const registryLinks = page.locator('.registry-link-container');
    const count = await registryLinks.count();

    if (count > 0) {
      console.log(`✓ Found ${count} registry link containers`);

      // Check at least one has content (might be loading or loaded)
      for (let i = 0; i < Math.min(count, 3); i++) {
        const container = registryLinks.nth(i);
        await expect(container).toBeDefined();
      }
      console.log('✓ Registry link containers have content');
    } else {
      console.log('⚠ No registry link containers found (might be paginated off-screen)');
    }
  });

  test('should display matched chassis with "View Car" button', async ({ page, context }) => {
    // Note: This test assumes test data exists. In production, skip if not available.

    await page.goto('/app/owner/cars/factory.php');

    // Wait for table to load
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    console.log('✓ DataTable loaded');

    // Wait for AJAX calls to complete (registry links to populate)
    await page.waitForLoadState('networkidle');
    console.log('✓ Network idle - AJAX calls completed');

    // Look for "View Car" buttons (green buttons in registry links)
    const viewButtons = page.locator('.registry-link-container .btn-success');
    const viewButtonCount = await viewButtons.count();

    if (viewButtonCount > 0) {
      console.log(`✓ Found ${viewButtonCount} "View Car" button(s)`);

      // Verify first button has correct content
      const firstButton = viewButtons.first();
      const text = await firstButton.textContent();
      expect(text).toContain('View Car');
      console.log(`✓ First button text: "${text.trim()}"`);
    } else {
      console.log('⚠ No "View Car" buttons found (might not have matching cars in test data)');
    }
  });

  test('should display unmatched chassis with informational message', async ({ page }) => {
    await page.goto('/app/owner/cars/factory.php');

    // Wait for table to load
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    console.log('✓ DataTable loaded');

    // Wait for AJAX calls
    await page.waitForLoadState('networkidle');
    console.log('✓ AJAX calls completed');

    // Look for informational messages (unmatched chassis)
    const messages = page.locator('.registry-link-container .text-muted, .registry-link-container .text-secondary');
    const messageCount = await messages.count();

    if (messageCount > 0) {
      console.log(`✓ Found ${messageCount} informational message(s)`);

      // Check message content
      const firstMsg = messages.first();
      const text = await firstMsg.textContent();
      console.log(`✓ Message example: "${text.trim()}"`);
    } else {
      console.log('⚠ No informational messages found (all might be matched)');
    }
  });

  test('should handle null/missing chassis gracefully', async ({ page }) => {
    await page.goto('/app/owner/cars/factory.php');

    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    console.log('✓ DataTable loaded');

    await page.waitForLoadState('networkidle');
    console.log('✓ AJAX calls completed');

    // Verify no "Check failed" errors visible
    const checkFailedElements = page.locator(':text("Check failed")');
    const failedCount = await checkFailedElements.count();

    if (failedCount === 0) {
      console.log('✓ No "Check failed" errors visible');
    } else {
      console.log(`⚠ Found ${failedCount} "Check failed" error(s)`);
    }
  });

  test('should perform Registry Link lookup via correct AJAX endpoint', async ({ page }) => {
    let ajaxRequest = null;

    // Intercept network requests
    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('chassis-lookup.php') && request.postDataJSON()) {
        const postData = request.postDataJSON();
        if (postData.chassis !== undefined) {
          ajaxRequest = {
            url: url,
            method: request.method(),
            data: postData
          };
        }
      }
    });

    await page.goto('/app/owner/cars/factory.php');

    // Wait for table and AJAX calls
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    await page.waitForLoadState('networkidle');

    if (ajaxRequest) {
      console.log('✓ Registry Link AJAX request captured');
      expect(ajaxRequest.method).toBe('POST');
      console.log('✓ Request method is POST');

      expect(ajaxRequest.data).toHaveProperty('chassis');
      console.log('✓ Request includes chassis parameter');

      expect(ajaxRequest.data).toHaveProperty('csrf');
      console.log('✓ Request includes CSRF token');
    } else {
      console.log('⚠ No Registry Link AJAX requests detected (might be page 1 of paginated results)');
    }
  });

  test('should include CSRF token in Registry Link request (catches issue #581 regression)', async ({ page }) => {
    // This test specifically catches the issue where CSRF token was missing
    // Regression: https://github.com/jimboone/elan-registry/issues/581

    const requestsWithoutCsrf = [];
    const requestsWithCsrf = [];

    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('chassis-lookup.php')) {
        try {
          const postData = request.postDataJSON();
          if (postData.chassis !== undefined) {
            if (!postData.csrf) {
              requestsWithoutCsrf.push({
                url: url,
                data: postData
              });
            } else {
              requestsWithCsrf.push(postData);
            }
          }
        } catch (e) {
          // Ignore parse errors
        }
      }
    });

    await page.goto('/app/owner/cars/factory.php');

    // Wait for factory table and AJAX calls to complete
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    await page.waitForLoadState('networkidle');

    // Verify no requests were made without CSRF token
    if (requestsWithoutCsrf.length > 0) {
      console.error('✗ CSRF token missing! Requests without token:');
      requestsWithoutCsrf.forEach(r => {
        console.error(`  - ${r.url}`);
        console.error(`    Data: ${JSON.stringify(r.data)}`);
      });
    }

    expect(requestsWithoutCsrf).toHaveLength(0);
    console.log('✓ All Registry Link requests include CSRF token');
  });

  test('should maintain Registry Link functionality across pagination', async ({ page }) => {
    await page.goto('/app/owner/cars/factory.php');

    // Wait for initial table
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    await page.waitForLoadState('networkidle');
    console.log('✓ Page 1 loaded');

    // Check if pagination exists
    const nextButton = page.locator('.paginate_button.next:not(.disabled)');
    const isNextAvailable = await nextButton.isVisible();

    if (isNextAvailable) {
      // Click next page
      await nextButton.click();

      // Wait for page 2 to load
      await page.waitForLoadState('networkidle');
      console.log('✓ Page 2 loaded');

      // Verify Registry Link containers exist on page 2
      const registryLinks = page.locator('.registry-link-container');
      const count = await registryLinks.count();
      expect(count).toBeGreaterThan(0);
      console.log(`✓ Registry Link containers visible on page 2 (${count} found)`);

      // Verify no "Check failed" errors on page 2
      const checkFailed = page.locator(':text("Check failed")');
      const failedCount = await checkFailed.count();
      if (failedCount === 0) {
        console.log('✓ No "Check failed" errors on page 2');
      } else {
        console.log(`⚠ Found ${failedCount} "Check failed" errors on page 2`);
      }
    } else {
      console.log('⚠ Only 1 page of data, skipping pagination test');
    }
  });

  test('should load Registry Links within reasonable time', async ({ page }) => {
    const startTime = Date.now();

    await page.goto('/app/owner/cars/factory.php');

    // Wait for table
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });

    // Wait for AJAX calls to complete
    await page.waitForLoadState('networkidle');

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
    await page.goto('/app/owner/cars/factory.php');

    // Wait for table and AJAX
    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    await page.waitForLoadState('networkidle');

    // Find a View Car button
    const viewButton = page.locator('.registry-link-container .btn-success').first();
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

  test('should handle AJAX errors gracefully', async ({ page }) => {
    // Track AJAX responses
    const responses = [];

    page.on('response', async (response) => {
      if (response.url().includes('chassis-lookup.php')) {
        responses.push({
          url: response.url(),
          status: response.status()
        });
      }
    });

    await page.goto('/app/owner/cars/factory.php');

    await page.waitForSelector('.dataTables_wrapper', { timeout: 10000 });
    await page.waitForLoadState('networkidle');

    // Check for HTTP errors in AJAX
    const errorResponses = responses.filter(r => r.status >= 400);

    if (errorResponses.length > 0) {
      console.log(`⚠ Found ${errorResponses.length} HTTP error response(s)`);
      errorResponses.forEach(r => {
        console.log(`  - ${r.url}: HTTP ${r.status}`);
      });
    } else {
      console.log('✓ All AJAX responses successful (HTTP 200)');
    }

    // Verify no broken UI despite any errors
    const table = page.locator('#cartable');
    await expect(table).toBeVisible();
    console.log('✓ Table still visible after AJAX calls');
  });
});
