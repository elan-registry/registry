// Join form client-side failure reporting (#1690), run in a real browser on
// join.php: Turnstile status messages and join-failure-report.php beacons.

const { test, expect } = require('@playwright/test');

test.describe('Join form client-side failure beacon (#1690)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('users/join.php');
  });

  test('elanTurnstileError() shows a visible status message', async ({ page }) => {
    await page.evaluate(() => {
      window.elanTurnstileError();
    });

    const status = page.locator('#turnstile-status-message');
    await expect(status).toBeVisible();
    await expect(status).toContainText('Verification failed to load');
  });

  test('elanTurnstileExpired() shows a visible status message and resets the widget', async ({ page }) => {
    // Stub window.turnstile so the reset() call (real Turnstile API) doesn't
    // need the live Cloudflare widget to have finished rendering.
    await page.evaluate(() => {
      window.turnstile = { reset: () => { window.__turnstileWasReset = true; } };
      window.elanTurnstileExpired();
    });

    const status = page.locator('#turnstile-status-message');
    await expect(status).toBeVisible();
    await expect(status).toContainText('Verification expired');

    const wasReset = await page.evaluate(() => window.__turnstileWasReset === true);
    expect(wasReset).toBe(true);
  });

  // #1798: elanTurnstileExpired calls window.elanTurnstileReset(), and only
  // the <script> tag order in usersc/views/_join.php enforces the load order.
  test('turnstile-reset.js script tag appears before join-form-beacon.js in the DOM', async ({ page }) => {
    // After load the later script always wins, so only the DOM tag order proves the order.
    const scriptOrder = await page.evaluate(() => {
      const scripts = Array.from(document.querySelectorAll('script[src]'));
      return scripts
        .map((s) => s.src)
        .filter((src) => src.includes('turnstile-reset') || src.includes('join-form-beacon'));
    });

    expect(scriptOrder.length, 'both turnstile-reset and join-form-beacon script tags must be present').toBe(2);
    expect(scriptOrder[0], 'turnstile-reset.js must appear before join-form-beacon.js').toContain('turnstile-reset');
    expect(scriptOrder[1]).toContain('join-form-beacon');
  });

  test('join-form-beacon.js\'s elanTurnstileExpired delegates to window.elanTurnstileReset()', async ({ page }) => {
    const result = await page.evaluate(() => {
      let sharedResetCalled = false;
      window.elanTurnstileReset = () => { sharedResetCalled = true; };
      window.elanTurnstileExpired();
      return sharedResetCalled;
    });
    expect(result, 'join-form-beacon.js\'s elanTurnstileExpired must call window.elanTurnstileReset()').toBe(true);
  });

  // #1798: the realistic case is Cloudflare's script never loading.
  test('elanTurnstileExpired does not throw when window.turnstile is undefined', async ({ page }) => {
    const result = await page.evaluate(() => {
      delete window.turnstile;
      let threw = null;
      try {
        window.elanTurnstileExpired();
      } catch (e) {
        threw = e.message;
      }
      return threw;
    });
    expect(result, 'elanTurnstileExpired must not throw when window.turnstile is undefined').toBeNull();
  });

  test('a GPS failure POSTs to join-failure-report.php with reason=location_gps_failed', async ({ page, context }) => {
    // Deny geolocation so handleGPSClick()'s catch branch (and therefore
    // onGPSError) actually fires.
    await context.clearPermissions();

    let beaconRequestBody = null;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconRequestBody = route.request().postData();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'Reported' }),
      });
    });

    // Stub the Geolocation API: Playwright cannot deny the permission uniformly.
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success, error) => {
        error({ code: 2, message: 'Position unavailable' }); // POSITION_UNAVAILABLE
      };
    });
    await page.reload();

    // In Chromium navigator.geolocation always exists, so the GPS button
    // must render. Its absence is a real init failure: fail, do not skip (#1950).
    const gpsButton = page.locator('[id$="-gps-btn"]');
    await expect(gpsButton, 'join.php must render a -gps-btn element via LocationPicker').toHaveCount(1);
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');
    expect(beaconRequestBody).toContain('detail=code%3D2');
  });

  test('a reverse-geocode failure (successful GPS fix, failed lookup) also POSTs to the beacon', async ({ page }) => {
    // reverseGeocode() once swallowed this failure, so onGPSError never ran.
    let beaconRequestBody = null;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconRequestBody = route.request().postData();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'Reported' }),
      });
    });

    // GPS lookup succeeds; the reverse-geocode API call fails.
    await page.route('**/app/api/shared/location-reverse.php', async (route) => {
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false}' });
    });
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success) => {
        success({ coords: { latitude: 49.2827, longitude: -123.1207 } });
      };
    });
    await page.reload();

    // GPS button must render in Chromium (see the first GPS test).
    const gpsButton = page.locator('[id$="-gps-btn"]');
    await expect(gpsButton, 'join.php must render a -gps-btn element via LocationPicker').toHaveCount(1);
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');

    // A rethrown reverseGeocode() error has no numeric .code, so its own
    // message must not be replaced by the generic one.
    const errorDiv = page.locator('[id$="-error"]');
    await expect(errorDiv).toBeVisible();
    await expect(errorDiv).toContainText('Unable to determine address from GPS coordinates');
  });

  test('a throwing onGPSError callback does not leave the GPS button permanently disabled', async ({ page, context }) => {
    // callOnGPSError() wraps the callback in try/catch so a throwing callback
    // cannot leave the button stuck disabled.
    await context.clearPermissions();

    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success, error) => {
        error({ code: 2, message: 'Position unavailable' });
      };
    });
    await page.reload();

    // GPS button must render in Chromium (see the first GPS test).
    const gpsButton = page.locator('[id$="-gps-btn"]');
    await expect(gpsButton, 'join.php must render a -gps-btn element via LocationPicker').toHaveCount(1);

    await page.evaluate(() => {
      window.elanReportJoinFailure = function () {
        throw new Error('synthetic-onGPSError-throw-1690');
      };
    });

    const consoleErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    await gpsButton.click();

    // The button must recover (not stay stuck disabled/"Getting location...")
    // even though the callback it invoked mid-flow threw.
    await expect(gpsButton).toBeEnabled();
    await expect(gpsButton).not.toContainText('Getting location');

    // The throw must not escape as an unhandled page-level error either —
    // it should be caught and logged via console.error, not propagate.
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));
    await page.waitForTimeout(200);
    expect(pageErrors).toHaveLength(0);
  });

  test('a webview with no Geolocation API also POSTs to the beacon', async ({ page }) => {
    // The `!navigator.geolocation` early return once skipped onGPSError.
    let beaconRequestBody = null;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconRequestBody = route.request().postData();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'Reported' }),
      });
    });

    await page.addInitScript(() => {
      Object.defineProperty(window.navigator, 'geolocation', { value: undefined, configurable: true });
    });
    await page.reload();

    // GPS button must render in Chromium (see the first GPS test).
    const gpsButton = page.locator('[id$="-gps-btn"]');
    await expect(gpsButton, 'join.php must render a -gps-btn element via LocationPicker').toHaveCount(1);
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');
    // The beacon forwards only error.code, so code=0 proves this path reported.
    expect(beaconRequestBody).toContain('detail=code%3D0');
  });

  test('an uncaught JS exception scoped to the join form POSTs to the beacon with reason=js_exception', async ({ page }) => {
    let beaconRequestBody = null;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconRequestBody = route.request().postData();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'Reported' }),
      });
    });

    // isJoinPageError() excludes only the cross-origin "Script error." text.
    await page.evaluate(() => {
      setTimeout(() => {
        throw new Error('synthetic-test-error-1690');
      }, 0);
    });

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=js_exception');
  });

  test('a sanitized cross-origin "Script error." does NOT reach the beacon', async ({ page }) => {
    // The one excluded case: third-party noise must not reach the log.
    let beaconCalled = false;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconCalled = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });

    await page.evaluate(() => {
      window.dispatchEvent(new ErrorEvent('error', {
        message: 'Script error.',
        filename: '',
        lineno: 0,
        colno: 0,
        error: null,
      }));
    });

    await page.waitForTimeout(500);
    expect(beaconCalled).toBe(false);
  });

  test('isJoinPageError() boundary cases — only the exact "Script error." string is excluded', async ({ page }) => {
    // Exact-string match: a looser match would drop real errors.
    const nearMissMessages = [
      'Script error',       // no trailing period
      'script error.',      // different case
      'Script error.  ',    // trailing whitespace
      'Uncaught Error: something real',
    ];

    for (const message of nearMissMessages) {
      let beaconCalled = false;
      await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
        beaconCalled = true;
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
      });

      await page.evaluate((msg) => {
        window.dispatchEvent(new ErrorEvent('error', {
          message: msg,
          filename: '',
          lineno: 0,
          colno: 0,
          error: null,
        }));
      }, message);

      await expect.poll(() => beaconCalled, `message "${message}" should NOT be excluded`).toBe(true);
      await page.unroute('**/app/api/shared/join-failure-report.php');
    }
  });
});

test.describe('Turnstile-not-rendered poll — staged 10s/20s threshold (#1690)', () => {
  // page.clock fast-forwards the 20s poll.
  test('does NOT report failure if the widget renders between the first and second check', async ({ page }) => {
    await page.clock.install();
    await page.goto('users/join.php');

    // Locally .cf-turnstile never exists, so inject a stand-in.
    await page.evaluate(() => {
      const div = document.createElement('div');
      div.className = 'cf-turnstile';
      document.body.appendChild(div);
    });

    let beaconCalled = false;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconCalled = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });

    await page.clock.runFor(10_000); // first check fires — widget still empty
    expect(beaconCalled).toBe(false); // must not report on the FIRST check alone

    // Widget renders between the two checks (e.g. a slow-but-working load).
    await page.evaluate(() => {
      document.querySelector('.cf-turnstile').innerHTML = '<iframe></iframe>';
    });

    await page.clock.runFor(10_000); // second check fires — widget now has content
    await page.waitForTimeout(100);
    expect(beaconCalled).toBe(false); // must not have reported at all
  });

  test('reports failure if the widget is still empty at the second check', async ({ page }) => {
    await page.clock.install();
    await page.goto('users/join.php');

    await page.evaluate(() => {
      const div = document.createElement('div');
      div.className = 'cf-turnstile';
      document.body.appendChild(div);
    });

    let beaconRequestBody = null;
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconRequestBody = route.request().postData();
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });

    await page.clock.runFor(20_000); // both checks fire — widget never renders

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=turnstile_not_loaded');
  });
});
