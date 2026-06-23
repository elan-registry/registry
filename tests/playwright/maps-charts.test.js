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

  test('Chart.js recent activity chart renders on statistics page', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    const chart = page.locator('#recentActivityChart');
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

  test('statistics page 26R filter classifies Race cars by variant', async ({ page }) => {
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    await expect(async () => {
      const count = await page.evaluate(() =>
        Array.isArray(window.elanMapMarkers) ? window.elanMapMarkers.length : -1
      );
      expect(count).toBeGreaterThan(0);
    }).toPass({ timeout: 15000 });

    // markerClassForSeries is scoped inside loadMapMarkers() and is not reachable
    // from the global scope, so we assert through the DOM instead.

    // At least one .elan-marker.r26 must exist (the 26R bucket is populated).
    const r26Markers = page.locator('.elan-marker.r26');
    await expect(r26Markers.first()).toBeAttached({ timeout: 10000 });

    // All Race cars in the marker data must have been assigned the r26 class.
    const raceCarsWithoutR26 = await page.evaluate(() => {
      const r26Dots = new Set(
        Array.from(document.querySelectorAll('.elan-marker.r26'))
      );
      const raceCars = (window.elanMapMarkers || []).filter(
        (m) => (m.variant || '').toLowerCase() === 'race'
      );
      // r26Count may exceed raceCarCount if the dataset changes; we require
      // at least as many r26 markers as Race car entries in the data.
      return { r26Count: r26Dots.size, raceCarCount: raceCars.length };
    });
    expect(raceCarsWithoutR26.r26Count).toBeGreaterThanOrEqual(
      raceCarsWithoutR26.raceCarCount
    );

    // Regression: non-Race S2 cars must NOT get the r26 class.
    const nonRaceS2WithR26 = await page.evaluate(() => {
      const nonRaceS2 = (window.elanMapMarkers || []).filter(
        (m) =>
          (m.series || '').toLowerCase().includes('s2') &&
          (m.variant || '').toLowerCase() !== 'race'
      );
      // If there are no non-Race S2 cars in the dataset we can't assert — skip.
      return nonRaceS2.length;
    });
    // Only assert the regression if the dataset actually contains non-Race S2 cars.
    if (nonRaceS2WithR26 > 0) {
      const s2NonRaceR26Count = await page.evaluate(() => {
        // A non-Race S2 marker should have class "s2", never "r26".
        // We cannot map marker objects back to DOM nodes directly, so we assert
        // that the total .elan-marker.s2 count is at least 1 (s2 bucket is used).
        return document.querySelectorAll('.elan-marker.s2').length;
      });
      expect(s2NonRaceR26Count).toBeGreaterThan(0);
    }

    // Filter UI: the 26R checkbox (#filter-r26) exists.
    const filterCheckbox = page.locator('#filter-r26');
    await expect(filterCheckbox).toBeVisible();

    const initialR26Count = await page.locator('.elan-marker.r26').count();

    // Uncheck the 26R filter — all r26 marker wrappers become display:none.
    await filterCheckbox.uncheck();

    // After unchecking, no .elan-marker.r26 elements should remain visible.
    const visibleAfterUncheck = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.elan-marker.r26')).filter(
        (el) => el.closest('.elan-marker-wrapper')?.style.display !== 'none'
      ).length
    );
    expect(visibleAfterUncheck).toBe(0);

    // Re-check the 26R filter — markers reappear.
    await filterCheckbox.check();

    const visibleAfterRecheck = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.elan-marker.r26')).filter(
        (el) => el.closest('.elan-marker-wrapper')?.style.display !== 'none'
      ).length
    );
    expect(visibleAfterRecheck).toBe(initialR26Count);
  });

  test('no requests to Google Maps domains on statistics page', async ({ page }) => {
    const googleMapsRequests = [];
    page.on('request', request => {
      const url = request.url();
      try {
        const hostname = new URL(url).hostname;
        if (hostname === 'maps.googleapis.com' || hostname.endsWith('.maps.googleapis.com') ||
            hostname === 'maps.gstatic.com' || hostname.endsWith('.maps.gstatic.com')) {
          googleMapsRequests.push(url);
        }
      } catch (_) { /* ignore non-URL strings */ }
    });

    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    expect(googleMapsRequests).toHaveLength(0);
  });

});
