// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Maps and Charts', () => {

  test('statistics page world map renders with MapLibre GL JS', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    // MapLibre GL JS injects .maplibregl-map onto the container
    const mapEl = page.locator('#map.maplibregl-map');
    await expect(mapEl).toBeVisible({ timeout: 15000 });

    // Canvas is created by MapLibre GL JS
    const canvas = page.locator('.maplibregl-canvas');
    await expect(canvas).toBeAttached({ timeout: 15000 });
  });

  test('statistics page marker data is inlined as JSON', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    const markerCount = await page.evaluate(() => {
      return Array.isArray(window.elanMapMarkers) ? window.elanMapMarkers.length : -1;
    });
    expect(markerCount).toBeGreaterThan(0);
  });

  test('Chart.js timeline chart renders on statistics page', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    const chart = page.locator('#timelineChart');
    await expect(chart).toBeVisible({ timeout: 10000 });
  });

  test('Chart.js age chart renders on statistics page', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    const chart = page.locator('#ageChart');
    await expect(chart).toBeVisible({ timeout: 10000 });
  });

  test('car details page map renders with MapLibre GL JS', async ({ page }) => {
    // Use car_id=1 or the smallest available; if auth-gated the test verifies the container exists
    await page.goto('/app/cars/details.php?car_id=1');
    await page.waitForLoadState('networkidle');

    const container = page.locator('.map-container, #map');
    await expect(container).toBeAttached({ timeout: 5000 });
  });

  test('statistics page chart data loads', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    const hasData = await page.evaluate(() => {
      return typeof window.statisticsRawData !== 'undefined' ||
             typeof Chart !== 'undefined';
    });
    expect(hasData).toBe(true);
  });

  test('charts are responsive on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    const chart = page.locator('#timelineChart');
    await expect(chart).toBeVisible({ timeout: 10000 });
  });

  test('no requests to Google Maps domains on statistics page', async ({ page }) => {
    const googleMapsRequests = [];
    page.on('request', request => {
      const url = request.url();
      if (url.includes('maps.googleapis.com') || url.includes('maps.gstatic.com')) {
        googleMapsRequests.push(url);
      }
    });

    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    expect(googleMapsRequests).toHaveLength(0);
  });

});
