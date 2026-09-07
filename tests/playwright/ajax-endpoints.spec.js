// tests/playwright/ajax-endpoints.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, waitForDataTables } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

// Extracts a real, valid CSRF token for the current session from the
// already-rendered <input name="csrf"> on usersc/user_settings.php — the
// same idiom as getCsrfFromEditPage() in length-validation.spec.js. Unlike
// the hardcoded literals used elsewhere in this file (which can only ever
// reach a 403), this lets a request pass the CSRF check and reach real
// validation logic. Returns null (rather than throwing) if the page
// redirects to login, so callers can test.skip() consistently with the
// rest of the suite's auth-failure handling.
async function getCsrfFromSettingsPage(page) {
  await page.goto('usersc/user_settings.php', { waitUntil: 'domcontentloaded' });
  const url = page.url();
  if (url.includes('login')) {
    return null;
  }
  const token = await page.locator('input[name="csrf"]').first().getAttribute('value');
  return token || null;
}

test.describe('Registry-Specific AJAX Endpoints', () => {
  test.beforeEach(async ({ page }) => {
    // Most AJAX endpoints require authentication.
    // Skip when test credentials are not configured in .env.local.
    if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
      test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
    }
    await ensureLoggedIn(page);
  });

  test('chassis validation endpoint responds correctly', async ({ page }) => {
    // Test the Lotus Elan chassis validation endpoint (ApiResponse JSON format)

    // Missing command parameter, with a REAL CSRF token — chassis-availability.php
    // checks CSRF (line 30-34) before command validation (line 37-39), so a fake
    // token would only ever prove CSRF rejection here, not command validation.
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    const missingCommandResponse = await page.request.post('app/api/cars/chassis-availability.php', {
      form: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        csrf
      }
    });
    expect(missingCommandResponse.status()).toBe(400);
    try {
      const jsonResponse = await missingCommandResponse.json();
      expect(jsonResponse).toHaveProperty('success', false);
    } catch (parseError) {
      throw new Error(`chassis-availability.php (missing command) returned non-JSON (status ${missingCommandResponse.status()}): ${parseError.message}`);
    }

    // Test CSRF validation failure (should return 403)
    const csrfFailResponse = await page.request.post('app/api/cars/chassis-availability.php', {
      form: {
        command: 'chassis_check',
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        csrf: 'invalid_token'
      }
    });
    expect(csrfFailResponse.status()).toBe(403);
    try {
      const jsonResponse = await csrfFailResponse.json();
      expect(jsonResponse).toHaveProperty('success', false);
    } catch (parseError) {
      throw new Error(`chassis-availability.php (CSRF fail) returned non-JSON (status ${csrfFailResponse.status()}): ${parseError.message}`);
    }
  });

  test('chassis_check with no matching car reports available', async ({ page }) => {
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    // Chassis/year/model combination extremely unlikely to match any real car.
    const response = await page.request.post('app/api/cars/chassis-availability.php', {
      form: {
        command: 'chassis_check',
        chassis: 'NOMATCH999999',
        year: '1970',
        model: 'S4|SE|FHC',
        csrf
      }
    });
    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('taken', false);
    expect(jsonResponse).toHaveProperty('available', true);
  });

  test('chassis_check with a fixture car chassis reports taken', async ({ page }) => {
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    // Look up CAR_ID_STANDARD's real chassis/year/type via the admin car-details
    // endpoint, then rebuild the combined "series|variant|type" model string
    // chassis-availability.php expects (it only uses the type — the third
    // segment — so series/variant are placeholders here).
    const carDetailsResponse = await page.request.post('app/admin/includes/process-car-details.php', {
      form: { car_id: String(CAR_ID_STANDARD), csrf }
    });
    const carDetailsJson = await carDetailsResponse.json();
    test.skip(!carDetailsJson.success, `Could not look up CAR_ID_STANDARD (${CAR_ID_STANDARD}) via process-car-details.php: ${carDetailsJson.message}`);
    const { chassis, year, type } = carDetailsJson.car;

    const response = await page.request.post('app/api/cars/chassis-availability.php', {
      form: {
        command: 'chassis_check',
        chassis,
        year: String(year),
        model: `X|Y|${type}`,
        csrf
      }
    });
    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('taken', true);
    expect(jsonResponse).toHaveProperty('available', false);
  });

  test('chassis_check rejects an invalid command with a real CSRF token', async ({ page }) => {
    // Confirms this is genuinely a command-validation 400, not an incidental
    // CSRF 403 — uses a real token so the request reaches command validation.
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    const response = await page.request.post('app/api/cars/chassis-availability.php', {
      form: {
        command: 'not_a_real_command',
        chassis: '12345678',
        year: '1973',
        model: 'S4|SE|FHC',
        csrf
      }
    });
    expect(response.status()).toBe(400);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('DataTables AJAX endpoint returns car data', async ({ page }) => {
    // Navigate to car listing page to establish session
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    // app/api/cars/list.php is public and read-only (per ADR-019); it carries
    // no CSRF gate — abuse is bounded by the `cars_list` rate limit instead.
    // No token is fetched or sent here.
    const response = await page.request.post('app/api/cars/list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    // The beforeEach hook already established an authenticated session, and
    // this endpoint requires no CSRF token, so it should always return 200.
    expect(response.status()).toBe(200);

    try {
      const jsonResponse = await response.json();

      // Should have DataTables structure
      expect(jsonResponse).toHaveProperty('draw');
      expect(jsonResponse).toHaveProperty('recordsTotal');
      expect(jsonResponse).toHaveProperty('recordsFiltered');
      expect(jsonResponse).toHaveProperty('data');

      // Data should be an array
      expect(Array.isArray(jsonResponse.data)).toBe(true);
    } catch (parseError) {
      throw new Error(`list.php returned non-JSON (status ${response.status()}): ${parseError.message}`);
    }
  });


  test('owner contact endpoint requires authentication', async ({ page }) => {
    // Test the owner-to-owner contact system
    const response = await page.request.post('app/api/contact/send-owner-email.php', {
      form: {
        car_id: '1',
        to_user_id: '1',
        message: 'Interest in your Lotus Elan',
        csrf: 'test_token'
      }
    });

    // Should either work (200) or require better authentication
    expect([200, 401, 403]).toContain(response.status());
  });

  test('NEW_CAR_IDS on car list page is a JSON int array', async ({ page }) => {
    // Verifies that CarShowcaseService::getNewCarIds() emits valid JSON to the page.
    // The const is embedded in the inline script block — shape must be int[].
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    const newCarIds = await page.evaluate(() => {
      if (typeof NEW_CAR_IDS === 'undefined') return null;
      return NEW_CAR_IDS;
    });

    // Skip when page requires auth and we're unauthenticated — same guard as functionality.spec.js
    if (newCarIds === null) {
      return;
    }

    expect(Array.isArray(newCarIds)).toBe(true);

    // Every element must be a positive integer (PHP json_encode on int[] produces JS numbers;
    // > 0 catches cast failures in getNewCarIds() that would produce 0 or negative values)
    newCarIds.forEach(id => {
      expect(typeof id).toBe('number');
      expect(Number.isInteger(id)).toBe(true);
      expect(id).toBeGreaterThan(0);
    });

    if (newCarIds.length > 0) {
      await waitForDataTables(page, 15000);
      const badge = page.locator('td a.btn .badge.er-badge-yellow').first();
      await expect(badge).toBeVisible();
      await expect(badge).toContainText('NEW');
    }
  });

  test('car history endpoint returns DataTables JSON structure', async ({ page }) => {
    // app/api/cars/history.php is public and read-only (per ADR-019); it
    // carries no CSRF gate — abuse is bounded by the `car_history` rate limit
    // instead. A deliberately bogus token is included here purely to prove
    // it is ignored, not required.
    const response = await page.request.post('app/api/cars/history.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'test_token'
      }
    });

    // No CSRF gate exists on this endpoint, so a bogus token must not cause
    // a rejection — this should always return 200.
    expect(response.status()).toBe(200);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', true);
      expect(jsonResponse).toHaveProperty('draw');
      expect(jsonResponse).toHaveProperty('recordsTotal');
      expect(jsonResponse).toHaveProperty('recordsFiltered');
      expect(jsonResponse).toHaveProperty('history');
      expect(Array.isArray(jsonResponse.history)).toBe(true);
    } catch (parseError) {
      throw new Error(`car history endpoint returned non-JSON (status ${response.status()}): ${parseError.message}`);
    }
  });

  test('validateChassis endpoint requires AJAX header and returns JSON', async ({ page }) => {
    // Test the chassis validation endpoint (different from check-chassis.php)

    // Test without X-Requested-With header (should fail)
    const noHeaderResponse = await page.request.post('app/api/cars/chassis-validate.php', {
      data: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        allow_override: '0',
        csrf: 'test_token'
      }
    });
    expect(noHeaderResponse.status()).not.toBe(500);

    // Test with X-Requested-With header
    const response = await page.request.post('app/api/cars/chassis-validate.php', {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      data: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        allow_override: '0',
        csrf: 'test_token'
      }
    });

    expect(response.status()).not.toBe(500);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success');
      expect(jsonResponse).toHaveProperty('message');

      // Should have validation result
      if (jsonResponse.success) {
        expect(jsonResponse).toHaveProperty('valid');
      } else {
        // Failed CSRF or other error
        expect(typeof jsonResponse.message).toBe('string');
      }
    } catch (parseError) {
      throw new Error(`chassis-validate.php returned non-JSON (status ${response.status()}): ${parseError.message}`);
    }
  });

  test('admin car details endpoint returns car data for an admin user', async ({ page }) => {
    // The configured TEST_USERNAME/TEST_PASSWORD account is itself an admin, so
    // requireAdminAjax()'s admin check passes — this is a real success-path test,
    // not a permission-rejection test (CSRF/admin-gate rejection for this endpoint
    // is exercised by other tests using invalid tokens/unauthenticated requests
    // elsewhere in the suite).
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    const response = await page.request.post('app/admin/includes/process-car-details.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        csrf
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('car');
    for (const field of ['id', 'year', 'type', 'chassis', 'color', 'series', 'fname', 'lname', 'email', 'city', 'state', 'country', 'ctime', 'mtime']) {
      expect(jsonResponse.car).toHaveProperty(field);
    }
  });

  test.describe('admin transfer approve/deny — real success-path coverage', () => {
    let csrf;
    let carDetails;

    test.beforeEach(async ({ page }) => {
      csrf = await getCsrfFromSettingsPage(page);
      test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

      const carDetailsResponse = await page.request.post('app/admin/includes/process-car-details.php', {
        form: { car_id: String(CAR_ID_STANDARD), csrf }
      });
      const carDetailsJson = await carDetailsResponse.json();
      test.skip(!carDetailsJson.success, `Could not look up CAR_ID_STANDARD (${CAR_ID_STANDARD}) via process-car-details.php: ${carDetailsJson.message}`);
      carDetails = carDetailsJson.car;

      // transfer-request.php rejects a requester who already owns the target car
      // ("You already own this car") — the admin test account being the same
      // account that owns CAR_ID_STANDARD would make every fixture-creation
      // request fail before a pending transfer ever exists. Detect that up
      // front by comparing the car's registered email to the logged-in
      // account rather than assuming either way.
      test.skip(
        carDetails.email === process.env.TEST_USERNAME,
        'CAR_ID_STANDARD is owned by the admin test account — cannot self-transfer to create a fixture'
      );
    });

    /**
     * Create a disposable pending transfer request for CAR_ID_STANDARD via the
     * public transfer-request.php endpoint, using CAR_ID_STANDARD's own
     * chassis/year/type so the "existing car" lookup matches it.
     * @returns {Promise<number>} the created transfer_request_id
     */
    async function createPendingTransfer(page) {
      const response = await page.request.post('app/api/cars/transfer-request.php', {
        form: {
          chassis: carDetails.chassis,
          year: String(carDetails.year),
          model: `X|Y|${carDetails.type}`,
          color: 'Red',
          engine: '',
          comments: 'Playwright ajax-endpoints.spec.js disposable fixture',
          csrf
        }
      });
      const json = await response.json();
      test.skip(!json.success, `Could not create disposable transfer request fixture: ${json.message}`);
      return json.transfer_request_id;
    }

    test('process-transfer-deny succeeds for a real pending transfer', async ({ page }) => {
      const transferId = await createPendingTransfer(page);

      const response = await page.request.post('app/admin/includes/process-transfer-deny.php', {
        form: {
          transfer_id: String(transferId),
          csrf
        }
      });

      expect(response.status()).toBe(200);
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', true);
      expect(jsonResponse).toHaveProperty('transfer_id', transferId);
      // No cleanup needed — deny is a terminal status change (matches the
      // PHPUnit TransferIntegrationTestCase idiom for terminal-status rows).
    });

    // process-transfer-approve.php calls $car->transfer(...), which permanently
    // reassigns car ownership (app/admin/includes/process-transfer-approve.php:81).
    // createPendingTransfer() above builds its transfer request from
    // CAR_ID_STANDARD's own chassis/year/type, so transfer-request.php's chassis
    // lookup resolves back to CAR_ID_STANDARD itself — there is no fixture car
    // here, only the shared reference fixture from fixtures.js. Approving that
    // request would permanently transfer CAR_ID_STANDARD's ownership to the
    // admin test account, corrupting a fixture reused across the whole suite
    // (see fixtures.js's own "must not change" comment).
    //
    // A safe version needs a disposable car owned by someone other than the
    // admin test account (transfer-request.php rejects a requester who already
    // owns the target car — see its "You already own this car" check), so the
    // admin can approve a transfer *to* itself without a self-transfer
    // rejection. That requires either:
    //   - a second Playwright-authenticated test account, or
    //   - a fixture-seeding endpoint/helper that can create a car owned by a
    //     non-admin user,
    // neither of which exists yet — the single TEST_USERNAME/TEST_PASSWORD
    // account is the only identity available to this suite, and no
    // direct-DB-insert helper (as PHPUnit's IntegrationTestCase::createTestCar())
    // is available from Playwright's HTTP-only test harness. Building that
    // infrastructure is out of scope for a single test fix.
    //
    // Filed as a follow-up: see GitHub issue tracking real Playwright coverage
    // for process-transfer-approve.php's success path with a disposable fixture.
    test.skip('process-transfer-approve succeeds for a separate real pending transfer — needs a disposable non-admin-owned car fixture; skipped to avoid mutating CAR_ID_STANDARD', () => {});
  });

  // The 'admin settings endpoint' describe block (process-settings.php,
  // elan_image_max mutation test) was removed in #1067 — that AJAX endpoint
  // and its backing tab-settings.php admin UI were deleted entirely.
  // ELAN_IMAGE_MAX and the other former settings are now config.php
  // constants, not web-editable DB rows, so there is nothing left to
  // exercise here.

  test('feedback endpoint requires CSRF and returns JSON', async ({ page }) => {
    const response = await page.request.post('app/api/contact/send-feedback.php', {
      form: {
        comments: 'Test feedback',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('contact owner endpoint requires CSRF and returns JSON', async ({ page }) => {
    const response = await page.request.post('app/api/contact/send-owner-email.php', {
      form: {
        action: 'send_message',
        to_user_id: '1',
        car_id: '1',
        message: 'Test message',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('location search endpoint enforces method, CSRF, and validation checks', async ({ page }) => {
    // Method check runs before the CSRF/validation checks
    const methodResponse = await page.request.get('app/api/shared/location-search.php');
    expect(methodResponse.status()).toBe(405);
    expect(await methodResponse.json()).toHaveProperty('success', false);

    // CSRF check runs before validation. A well-formed-but-wrong 64-hex-char
    // token exercises Token::check()'s hash_equals() comparison, not just
    // its length/format guard.
    const csrfResponse = await page.request.post('app/api/shared/location-search.php', {
      form: { query: 'London', csrf: 'a'.repeat(64) }
    });
    expect(csrfResponse.status()).toBe(403);
    expect(await csrfResponse.json()).toHaveProperty('success', false);

    // With a real token, a too-short query reaches LocationServiceException
    // handling without calling LocationService (no live network dependency,
    // deterministic).
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');
    const validationResponse = await page.request.post('app/api/shared/location-search.php', {
      form: { query: 'a', csrf }
    });
    expect(validationResponse.status()).toBe(400);
    const validationJson = await validationResponse.json();
    expect(validationJson).toHaveProperty('success', false);
    expect(validationJson.message).toContain('at least 2 characters');
  });

  // ---------------------------------------------------------------------
  // Regression coverage for issue #1519: Car::create()/Car::update() no
  // longer validate CSRF internally — enforcement now lives ONLY at
  // save.php's endpoint boundary (lines 66-71, before the action switch).
  // No prior test proved that boundary actually rejects a bad/missing
  // token for the addCar/updateCar actions specifically.
  // ---------------------------------------------------------------------
  test('save.php rejects addCar with an invalid CSRF token', async ({ page }) => {
    const response = await page.request.post('app/api/cars/save.php', {
      form: {
        action: 'addCar',
        year: '1965',
        model: 'S1|SE|DHC',
        chassis: '1234',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('save.php rejects updateCar with an invalid CSRF token', async ({ page }) => {
    const response = await page.request.post('app/api/cars/save.php', {
      form: {
        action: 'updateCar',
        car_id: String(CAR_ID_STANDARD),
        year: '1965',
        model: 'S1|SE|DHC',
        chassis: '1234',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('save.php rejects addCar with a missing CSRF token', async ({ page }) => {
    // Omitting csrf entirely exercises Token::check() with a null/empty
    // token, not just a well-formed-but-wrong one — a distinct guard path.
    const response = await page.request.post('app/api/cars/save.php', {
      form: {
        action: 'addCar',
        year: '1965',
        model: 'S1|SE|DHC',
        chassis: '1234'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('save.php reaches past the CSRF check with a real token (proves the check, not some other guard, rejected the tests above)', async ({ page }) => {
    // A real token but otherwise-incomplete/invalid data (bogus action) must
    // NOT 403 — if it did, that would mean some earlier guard (e.g. auth)
    // was responsible for the 403s above rather than the CSRF check itself.
    // Deliberately uses a bogus action (not addCar/updateCar) to avoid a real
    // DB write from this describe block — a genuine addCar/updateCar success
    // path with a valid token is exercised elsewhere: car-edit-text-save.spec.js
    // (real navigation-based session) and CarDatabaseOperationsTest.php's
    // PHPUnit integration suite (Token::generate() + real persistence asserts).
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');

    const response = await page.request.post('app/api/cars/save.php', {
      form: {
        action: 'not_a_real_action',
        csrf
      }
    });
    expect(response.status()).not.toBe(403);
    expect(response.status()).toBe(400);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('location reverse geocoding endpoint enforces method, CSRF, and validation checks', async ({ page }) => {
    // Method check runs before the CSRF/validation checks
    const methodResponse = await page.request.get('app/api/shared/location-reverse.php');
    expect(methodResponse.status()).toBe(405);
    expect(await methodResponse.json()).toHaveProperty('success', false);

    // CSRF check runs before validation. A well-formed-but-wrong 64-hex-char
    // token exercises Token::check()'s hash_equals() comparison, not just
    // its length/format guard.
    const csrfResponse = await page.request.post('app/api/shared/location-reverse.php', {
      form: { lat: '51.5', lon: '-0.1', csrf: 'a'.repeat(64) }
    });
    expect(csrfResponse.status()).toBe(403);
    expect(await csrfResponse.json()).toHaveProperty('success', false);

    // With a real token, out-of-range coordinates reach
    // LocationService::reverseGeocode()'s validateCoordinates() rejection
    // before any rate-limit check or network call — deterministic. (Missing
    // lat/lon is NOT used here: Input::get() returns '' rather than null for
    // an absent key, so location-reverse.php's own `$lat === null` guard
    // never fires and a missing-param request would fall through to a live
    // Nominatim lookup for 0,0 — see #1624.)
    const csrf = await getCsrfFromSettingsPage(page);
    test.skip(!csrf, 'Could not obtain CSRF token from user_settings.php');
    const validationResponse = await page.request.post('app/api/shared/location-reverse.php', {
      form: { lat: '999', lon: '999', csrf }
    });
    expect(validationResponse.status()).toBe(400);
    const validationJson = await validationResponse.json();
    expect(validationJson).toHaveProperty('success', false);
    expect(validationJson.message).toContain('Invalid coordinates');
  });
});

test.describe('Issue #1913 — car list survives session loss (no auth, no CSRF plumbing)', () => {
  // Deliberately outside the authenticated describe above and its
  // beforeEach — this reproduces the actual production failure mode: a
  // visitor whose session has been lost (idle GC, browser restart) loading
  // /app/owner/cars/index.php. Before this fix, car-list.js sent a
  // page-embedded CSRF token that list.php validated with Token::check();
  // a lost/absent session made that check fail, the DataTable's ajax.error
  // handler fired, and the table rendered no rows behind a
  // "Could not load the car list" .alert-danger banner. list.php now
  // carries no CSRF gate at all (ADR-019), so this must render successfully
  // regardless of session state.
  //
  // This test must FAIL against main (pre-#1913) and PASS on this branch.

  test('car list renders rows with no session cookie and no error banner', async ({ page }) => {
    // Clear cookies explicitly rather than relying on a fresh context having
    // none — makes the "no session" precondition an assertion of intent, not
    // an accident of test ordering.
    await page.context().clearCookies();

    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    // list.php is public (no securePage()/isLoggedIn() gate — see ADR-019),
    // so an anonymous, cookie-less request must still reach the page itself,
    // not a login redirect.
    expect(page.url()).not.toContain('login');

    const searchBox = await waitForDataTables(page, 15000);
    void searchBox;

    // The DataTable's ajax.error handler (car-list.js) prepends this banner
    // into the wrapper on any failed draw — a lost/absent session must not
    // trigger it.
    const errorBanner = page.locator('.dataTables_wrapper .alert-danger, .dt-container .alert-danger');
    await expect(errorBanner).toHaveCount(0);

    // Rows must actually render — an empty-but-error-free table would also
    // pass a bare "no banner" check without proving the fix.
    const rows = page.locator('#cartable tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    expect(await rows.count()).toBeGreaterThan(0);
  });
});

test.describe('Issue #1913 — public read-only DataTables endpoints survive a lost/absent CSRF token', () => {
  // These four endpoints (list.php, factory-list.php, history.php,
  // statistics.php) are public and read-only per ADR-019: no state change,
  // no login gate, no non-public data. They must succeed whether a CSRF
  // field is entirely absent (models a lost session — the actual
  // production failure mode observed in #1913) or present but
  // garbage/expired (models a stale page-embedded token). Abuse is bounded
  // by rate limiting instead, not asserted here.

  // Deliberately OUTSIDE the authenticated describe above. Nested inside it,
  // these ran only after ensureLoggedIn() — the one session state in which
  // the reported bug never occurred — and skipped entirely without
  // TEST_USERNAME/TEST_PASSWORD. Clearing cookies models the real failure:
  // a visitor whose session is gone.
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('list.php with no csrf field at all returns 200 with populated data', async ({ page }) => {
    const response = await page.request.post('app/api/cars/list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('data');
    expect(Array.isArray(jsonResponse.data)).toBe(true);
    expect(jsonResponse.data.length).toBeGreaterThan(0);
  });

  test('list.php with a garbage/expired csrf token returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/cars/list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'this-is-not-a-valid-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('data');
    expect(Array.isArray(jsonResponse.data)).toBe(true);
  });

  test('factory-list.php with no csrf field at all returns 200 with populated data', async ({ page }) => {
    const response = await page.request.post('app/api/cars/factory-list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('data');
    expect(Array.isArray(jsonResponse.data)).toBe(true);
    expect(jsonResponse.data.length).toBeGreaterThan(0);
  });

  test('factory-list.php with a garbage/expired csrf token returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/cars/factory-list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'this-is-not-a-valid-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('data');
    expect(Array.isArray(jsonResponse.data)).toBe(true);
  });

  test('history.php with no csrf field at all returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/cars/history.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('history');
    expect(Array.isArray(jsonResponse.history)).toBe(true);
  });

  test('history.php with a garbage/expired csrf token returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/cars/history.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'this-is-not-a-valid-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
  });

  test('statistics.php with no csrf field at all returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/shared/statistics.php', {
      form: {
        tab: 'production'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('data');
  });

  test('statistics.php with a garbage/expired csrf token returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/shared/statistics.php', {
      form: {
        tab: 'production',
        csrf: 'this-is-not-a-valid-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
  });
});
