const { test, expect } = require('@playwright/test');
const { waitForDataTables, assertNoConsoleErrors } = require('../auth-helper.js');

// Index of the `serial` column in app/assets/js/factory-list.js (column 0 is
// the hidden id).
const SERIAL_COLUMN = 5;

/**
 * Find a factory serial that matches a registry car.
 *
 * Throws instead of skipping, so a missing factory dataset fails the run
 * rather than passing with no check.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @returns {Promise<{serial: string, carId: number}>}
 */
async function findRegisteredFactorySerial(request) {
  const carsResponse = await request.post('app/api/cars/list.php', {
    form: { draw: '1', start: '0', length: '100' },
  });
  expect(carsResponse.ok(), `cars list API returned HTTP ${carsResponse.status()}`).toBe(true);
  const cars = await carsResponse.json();
  const chassisValues = [...new Set(cars.data.map((car) => car.chassis).filter(Boolean))];

  for (const chassis of chassisValues) {
    const factoryResponse = await request.post('app/api/cars/factory-list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '1',
        [`columns[${SERIAL_COLUMN}][data]`]: 'serial',
        [`columns[${SERIAL_COLUMN}][search][value]`]: chassis,
      },
    });
    expect(
      factoryResponse.ok(),
      `factory list API returned HTTP ${factoryResponse.status()} for chassis ${chassis}`
    ).toBe(true);
    const [row] = (await factoryResponse.json()).data;
    if (row) {
      // An unfiltered row would pair this chassis with another serial's car.
      expect(row.serial, 'factory-list.php ignored the serial column filter').toBe(chassis);
    }
    const carId = Number.parseInt(row?.car_id, 10);
    if (carId > 0) {
      return { serial: chassis, carId };
    }
  }

  throw new Error(
    `None of the ${chassisValues.length} chassis values from the first 100 registry cars has an elan_factory_info row. `
      + 'On a local Docker target, load production cars and factory data: run '
      + '`./scripts/refresh-local-db.sh --fetch --skip-images` from the host (needs the a2hosting SSH alias), '
      + 'or `./scripts/refresh-local-db.sh <DUMP_FILE>`. See scripts/README.md, "refresh-local-db.sh". '
      + 'On a remote target, check its elan_factory_info data.'
  );
}

test.describe('Factory Page - Registry Link Feature', () => {
  // Run these tests with the admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  test('should load factory page without errors', async ({ page }) => {
    // The helper registers its console listener before goto, so errors thrown
    // during page load are captured.
    await assertNoConsoleErrors(page, 'app/owner/cars/factory.php', 'factory.php');
    console.log('✓ Factory page loaded with no console errors');

    await expect(page.locator('h2:has-text("Elan Factory Information")')).toBeVisible();
    await expect(page.locator('#cartable')).toBeVisible();
  });

  test('should display Registry Link column in table header', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php');
    await waitForDataTables(page);

    // scrollX makes DataTables keep a zero-height copy of the header inside
    // #cartable, so a text or role match alone resolves to 2 elements (#2622).
    const registryHeader = page
      .getByRole('columnheader', { name: 'Registry Link', exact: true })
      .filter({ visible: true });
    await expect(registryHeader).toBeVisible();
    console.log('✓ Registry Link column header visible');
  });

  test('should display unmatched chassis with informational message', async ({ page }) => {
    // Unmatched-chassis rows need elan_factory_info rows in the app database.
    // `scripts/refresh-local-db.sh --fetch --skip-images` (run from the host)
    // loads them. Skip rather than assume.

    await page.goto('app/owner/cars/factory.php');

    // Wait for table to load
    await waitForDataTables(page);
    console.log('✓ DataTable loaded');

    // Registry Link renders synchronously — wait for containers to appear
    await page.waitForSelector('.registry-link-container', { timeout: 10000 });

    // Look for informational messages (unmatched chassis)
    const messages = page.locator('.registry-link-container .text-muted, .registry-link-container .text-secondary');
    const messageCount = await messages.count();

    test.skip(messageCount === 0, 'No unmatched chassis in fixture data — requires elan_factory_info rows (scripts/refresh-local-db.sh --fetch --skip-images, from the host)');

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

    // Assert instead of skipping: a guard here once hid a DataTables 1.x
    // selector that matched nothing, so the test passed with no check (#2771).
    const onePageHint = 'No enabled DataTables Next button. Either the paging markup changed, or the factory '
      + 'table has one page: load the full elan_factory_info data with '
      + '`./scripts/refresh-local-db.sh --fetch --skip-images` from the host. See scripts/README.md.';
    const nextButton = page.locator('#cartable_wrapper').getByRole('link', { name: 'Next', exact: true });
    await expect(nextButton, onePageHint).toBeVisible();
    await expect(nextButton, onePageHint).not.toHaveAttribute('aria-disabled', 'true');

    // The table is server-side with pageLength 25 (app/assets/js/factory-list.js),
    // so page 2 is the request with start=25. Page 1 rows already match
    // .registry-link-container, so a selector wait would not prove page 2 loaded.
    const page2 = page.waitForResponse((response) => response.url().includes('factory-list.php')
      && /(?:^|&)start=25(?:&|$)/.test(response.request().postData() ?? ''));
    await nextButton.click();
    const page2Response = await page2;
    expect(page2Response.ok(), `page 2 of the factory list returned HTTP ${page2Response.status()}`).toBe(true);

    await expect(page.locator('#cartable_wrapper [aria-current="page"]')).toHaveText('2');
    console.log('✓ Page 2 loaded');

    await expect(page.locator('.registry-link-container').first()).toBeVisible();
    console.log('✓ Registry Link containers visible on page 2');
  });

  test('matched chassis shows a View Car button that links to the car', async ({ page }) => {
    const { serial, carId } = await findRegisteredFactorySerial(page.request);

    await page.goto('app/owner/cars/factory.php');
    await waitForDataTables(page);

    // Page 1 of the unfiltered list can hold no matched row, so filter to the
    // matched serial. The serial column filter is an exact match, so the row
    // is always on page 1.
    // Match the filtered request by its body: the initial unfiltered draw can
    // still be in flight, and its response must not end the wait.
    const encodedSerial = encodeURIComponent(serial).replace(/%20/g, '+');
    const filtered = page.waitForResponse((response) => response.url().includes('factory-list.php')
      && (response.request().postData() ?? '').includes(`%5Bvalue%5D=${encodedSerial}`));
    await page.evaluate(
      ({ column, value }) => window.jQuery('#cartable').DataTable().column(column).search(value).draw(),
      { column: SERIAL_COLUMN, value: serial }
    );
    const filteredResponse = await filtered;
    expect(filteredResponse.ok(), `filtered factory list returned HTTP ${filteredResponse.status()}`).toBe(true);
    const filteredRows = (await filteredResponse.json()).data;
    expect(filteredRows.length, `filtered factory list returned no row for serial ${serial}`).toBeGreaterThan(0);
    for (const row of filteredRows) {
      expect(row.serial, 'factory-list.php ignored the serial column filter').toBe(serial);
    }

    const viewButton = page.locator('.registry-link-container .btn-primary').first();
    await expect(viewButton).toBeVisible();
    await expect(viewButton).toContainText(`View Car #${carId}`);
    await expect(viewButton).toHaveAttribute('href', new RegExp(`details\\.php\\?car_id=${carId}$`));
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

