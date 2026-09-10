// tests/playwright/join-form-beacon.spec.js
//
// Browser-level regression tests for issue #1690: join form webview silent
// fail — client-side failure reporting.
//
// PHPUnit source-text tests (tests/unit/regression/Issue1690*.php) confirm
// the PHP/JS wiring is textually correct, but cannot confirm any of it
// actually executes in a real browser. These tests exercise the real
// join.php page and its loaded JS directly.
//
// What these tests verify:
//   - window.elanTurnstileError()/elanTurnstileExpired() update the visible
//     #turnstile-status-message element (not just log/report silently)
//   - A location-picker GPS failure (mocked geolocation denial) POSTs to
//     the join-failure-report.php beacon with reason=location_gps_failed
//   - A page-level JS exception scoped to the join form POSTs to the beacon
//     with reason=js_exception
//
// Requires local MAMP — see playwright.config.js's baseURL

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

  // Regression guard for #1798: turnstile-reset.js must load before
  // join-form-beacon.js on this page — the latter's elanTurnstileExpired
  // delegates to window.elanTurnstileReset() internally. Nothing enforces
  // this ordering except the two <script src> tags' document position (see
  // usersc/views/_join.php).
  test('turnstile-reset.js script tag appears before join-form-beacon.js in the DOM', async ({ page }) => {
    // Checking window.elanTurnstileReset's existence (or which handler
    // "won") after page load can't distinguish correct load order from any
    // order — both scripts have already executed by the time page.evaluate
    // runs, and the second-loaded script's assignment always wins
    // regardless of which one that is. Only the actual <script> tag
    // position in the DOM proves the enforced order.
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

  // Regression guard for #1798: the shared reset helper's guard clause
  // (window.turnstile && typeof window.turnstile.reset === 'function') must
  // hold even when Cloudflare's widget script never finished loading/
  // executing — the realistic scenario that originally prompted this fix,
  // not just the "stub it and confirm it's called" happy path the other
  // tests exercise.
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

    // Simulate denial via the Geolocation API directly — avoids relying on
    // browser-specific permission-prompt UI, which Playwright's geolocation
    // permission model doesn't uniformly support for "deny" across engines.
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success, error) => {
        error({ code: 2, message: 'Position unavailable' }); // POSITION_UNAVAILABLE
      };
    });
    await page.reload();

    const gpsButton = page.locator('[id$="-gps-btn"]');
    if (!(await gpsButton.count())) {
      test.skip(true, 'GPS button not rendered — geolocation not available in this browser context');
      return;
    }
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');
    expect(beaconRequestBody).toContain('detail=code%3D2');
  });

  test('a reverse-geocode failure (successful GPS fix, failed lookup) also POSTs to the beacon', async ({ page }) => {
    // Local review found reverseGeocode()'s own try/catch swallowed this
    // failure without rethrowing, so it never reached handleGPSClick()'s
    // catch (and therefore onGPSError) at all — the exact silent-blocker
    // pattern #1690 exists to fix, just one function down from the raw
    // geolocation-permission case already covered above.
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

    const gpsButton = page.locator('[id$="-gps-btn"]');
    if (!(await gpsButton.count())) {
      test.skip(true, 'GPS button not rendered — geolocation not available in this browser context');
      return;
    }
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');

    // handleGPSClick()'s catch only rebuilds a generic "Unable to get your
    // location" message when the caught error has a numeric .code (a raw
    // GeolocationPositionError) — a rethrown reverseGeocode() failure has
    // neither, so its own specific message (set before the rethrow) must
    // survive on screen, not get silently overwritten by the generic one.
    const errorDiv = page.locator('[id$="-error"]');
    await expect(errorDiv).toBeVisible();
    await expect(errorDiv).toContainText('Unable to determine address from GPS coordinates');
  });

  test('a throwing onGPSError callback does not leave the GPS button permanently disabled', async ({ page, context }) => {
    // callOnGPSError() (location-picker.js) wraps the caller-supplied
    // onGPSError callback in try/catch specifically so a bug in that
    // callback can't break handleGPSClick()'s own finally block (re-enabling
    // the button). Force window.elanReportJoinFailure — the function
    // join.php's real onGPSError callback calls — to throw, and confirm the
    // button still recovers rather than staying stuck in its disabled
    // "Getting location..." state.
    await context.clearPermissions();

    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success, error) => {
        error({ code: 2, message: 'Position unavailable' });
      };
    });
    await page.reload();

    const gpsButton = page.locator('[id$="-gps-btn"]');
    if (!(await gpsButton.count())) {
      test.skip(true, 'GPS button not rendered — geolocation not available in this browser context');
      return;
    }

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
    // Local review found the `!navigator.geolocation` early-return in
    // handleGPSClick() returned before the try/catch that invokes
    // onGPSError — a webview build missing the API entirely reported
    // nothing server-side.
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

    const gpsButton = page.locator('[id$="-gps-btn"]');
    if (!(await gpsButton.count())) {
      test.skip(true, 'GPS button not rendered — no way to trigger handleGPSClick() in this browser context');
      return;
    }
    await gpsButton.click();

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=location_gps_failed');
    // onGPSError's beacon call only forwards error.code (not .message), so
    // the synthetic { code: 0, ... } object surfaces as code=0 here — that's
    // the actual signal proving the report reached the beacon at all for
    // this path, which previously returned silently before onGPSError could
    // ever fire.
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

    // isJoinPageError() only excludes the browser's sanitized cross-origin
    // "Script error." report — a same-origin exception (regardless of
    // whether the throwing code has a real filename attached) is not that,
    // so a plain synthetic throw is a faithful reproduction here.
    await page.evaluate(() => {
      setTimeout(() => {
        throw new Error('synthetic-test-error-1690');
      }, 0);
    });

    await expect.poll(() => beaconRequestBody).not.toBeNull();
    expect(beaconRequestBody).toContain('reason=js_exception');
  });

  test('a sanitized cross-origin "Script error." does NOT reach the beacon', async ({ page }) => {
    // The one case isJoinPageError() deliberately excludes — an
    // unrelated third-party/extension error must not pollute the
    // RegistrationFailed log with noise unrelated to the join form.
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
    // isJoinPageError() is a deliberately narrow exact-string match (no
    // trim/case-fold) — confirm near-miss messages are NOT excluded, so a
    // future "helpful" loosening of the match doesn't silently widen what
    // gets filtered out and start dropping real, attributable errors.
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
  // Uses page.clock to fast-forward virtual time rather than waiting up to
  // 20 real seconds — the poll logic itself is what's under test, not wall
  // clock behavior.
  test('does NOT report failure if the widget renders between the first and second check', async ({ page }) => {
    await page.clock.install();
    await page.goto('users/join.php');

    // Simulate a widget that hasn't rendered yet (matches a real page with
    // Turnstile disabled/no env keys in this local environment, where
    // .cf-turnstile never exists at all — inject a stand-in div so the poll
    // has something to check against).
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
