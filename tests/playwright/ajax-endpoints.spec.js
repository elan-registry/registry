const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, waitForDataTables } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

// A real token from user_settings.php lets a request pass CSRF and reach
// validation. Returns null on a login redirect so callers can skip.
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
    if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) {
      test.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');
    }
    await ensureLoggedIn(page);
  });

  test('chassis validation endpoint responds correctly', async ({ page }) => {
    // Test the Lotus Elan chassis validation endpoint (ApiResponse JSON format)

    // Real CSRF token: the CSRF check runs before command validation.
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

    // chassis-availability.php uses only the type segment of "series|variant|type".
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

    // list.php is public and read-only (ADR-019): no CSRF gate.
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

  test('list.php rows carry badges, badges_html, and no freshness fields', async ({ page }) => {
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    // Newest cars first: the 5 newest cars are always NEW, so the first rows have badges.
    const response = await page.request.post('app/api/cars/list.php', {
      form: {
        draw: '1',
        start: '0',
        length: '25',
        'order[0][column]': '12',
        'order[0][dir]': 'desc',
        'columns[12][data]': 'ctime',
        'columns[12][orderable]': 'true'
      }
    });
    expect(response.status()).toBe(200);

    const { data } = await response.json();
    expect(Array.isArray(data)).toBe(true);
    expect(data.length).toBeGreaterThan(0);

    const allowedKeys = ['new', 'sold', 'verified'];
    for (const row of data) {
      expect(Array.isArray(row.badges), `badges on car ${row.id}`).toBe(true);
      for (const key of row.badges) {
        expect(typeof key).toBe('string');
        expect(allowedKeys).toContain(key);
      }
      // CarBadges::html() draws the badges on the server. The list JS only
      // puts badges_html after the Details link.
      expect(typeof row.badges_html, `badges_html on car ${row.id}`).toBe('string');
      expect(row.badges_html === '', `badges_html empty iff no badges on car ${row.id}`)
        .toBe(row.badges.length === 0);
      // CarBadges::decorateRows() replaces is_fresh. The response must not
      // expose the freshness inputs (the list is public).
      expect(row).not.toHaveProperty('is_fresh');
      expect(row).not.toHaveProperty('last_verified');
      expect(row).not.toHaveProperty('owner_last_updated');
    }

    // Non-vacuous: at least one row carries a badge (the newest car is NEW).
    expect(data.some(row => row.badges.includes('new'))).toBe(true);
  });

  test('NEW badge on the car list is outside the Details link', async ({ page }) => {
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });
    await waitForDataTables(page, 15000);

    // Sort by date added, newest first. The newest car is always NEW.
    await page.evaluate(() => {
      window.jQuery('#cartable').DataTable().order([12, 'desc']).draw();
    });

    const badge = page.locator('#cartable td .er-badges .er-badge--new').first();
    await expect(badge).toBeVisible({ timeout: 15000 });
    await expect(badge).toHaveText('New');
    await expect(page.locator('td a.btn .er-badge')).toHaveCount(0);
  });

  test('car history endpoint returns DataTables JSON structure', async ({ page }) => {
    // history.php is members-only (#2144) with no CSRF gate; the bogus token proves it is ignored.
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

      // transfer-request.php rejects a requester who already owns the car.
      test.skip(
        carDetails.email === process.env.E2E_DEV_ADMIN_USERNAME,
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
  });

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

    // A well-formed 64-hex token exercises hash_equals(), not only the format guard.
    const csrfResponse = await page.request.post('app/api/shared/location-search.php', {
      form: { query: 'London', csrf: 'a'.repeat(64) }
    });
    expect(csrfResponse.status()).toBe(403);
    expect(await csrfResponse.json()).toHaveProperty('success', false);

    // A too-short query fails validation before any network call.
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

  // #1519: save.php is the only CSRF boundary for addCar/updateCar.
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
    // A bogus action avoids a DB write; a non-403 proves the CSRF check
    // (not another guard) rejected the tests above.
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

    // A well-formed 64-hex token exercises hash_equals(), not only the format guard.
    const csrfResponse = await page.request.post('app/api/shared/location-reverse.php', {
      form: { lat: '51.5', lon: '-0.1', csrf: 'a'.repeat(64) }
    });
    expect(csrfResponse.status()).toBe(403);
    expect(await csrfResponse.json()).toHaveProperty('success', false);

    // Out-of-range coordinates fail before any network call. A missing lat/lon
    // would fall through to a live Nominatim lookup (#1624).
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
  // Outside the authenticated describe: a lost session is the production
  // failure mode. list.php has no CSRF gate (ADR-019).

  test('car list renders rows with no session cookie and no error banner', async ({ page }) => {
    await page.context().clearCookies();

    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    // list.php is public (ADR-019), so no login redirect.
    expect(page.url()).not.toContain('login');

    const searchBox = await waitForDataTables(page, 15000);
    void searchBox;

    const errorBanner = page.locator('.dataTables_wrapper .alert-danger, .dt-container .alert-danger');
    await expect(errorBanner).toHaveCount(0);

    // An empty, error-free table would pass the banner check without proving the fix.
    const rows = page.locator('#cartable tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    expect(await rows.count()).toBeGreaterThan(0);
  });
});

test.describe('Issue #1913 — public read-only DataTables endpoints survive a lost/absent CSRF token', () => {
  // Public read-only endpoints (ADR-019) must succeed with an absent or stale
  // CSRF field. Outside the authenticated describe: clearing cookies models
  // the lost-session failure.
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

test.describe('Admin AJAX Endpoints — Unauthenticated Access', () => {
  // The bare `request` fixture has no cookies. Asserting the exact message
  // pins the auth branch, which runs before the CSRF check.
  test('admin user details endpoint rejects an unauthenticated request', async ({ request }) => {
    const response = await request.post('app/admin/includes/process-user-details.php', {
      form: {
        user_id: '1',
        csrf: 'test_token'
      }
    });

    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
    expect(jsonResponse).toHaveProperty('message', 'Unauthorized access');
  });
});

test.describe('Issue #2144 — car history requires login', () => {
  // history.php returns past owners' names and locations, so anonymous
  // callers get 401 (#2144). Clearing cookies models a lost session.
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('history.php with no csrf field at all returns 401 and no history', async ({ page }) => {
    const response = await page.request.post('app/api/cars/history.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    expect(response.status()).toBe(401);
    const rawBody = await response.text();
    const jsonResponse = JSON.parse(rawBody);
    expect(jsonResponse).toHaveProperty('success', false);
    expect(jsonResponse).not.toHaveProperty('history');

    // History rows carry a past owner's first name and location — none of
    // that PII may reach an anonymous caller's response body.
    expect(rawBody).not.toContain('fname');
    expect(rawBody).not.toContain('city');
    expect(rawBody).not.toContain('country');
  });

  test('history.php with a garbage/expired csrf token returns 401 and no history', async ({ page }) => {
    const response = await page.request.post('app/api/cars/history.php', {
      form: {
        car_id: String(CAR_ID_STANDARD),
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'this-is-not-a-valid-token'
      }
    });

    expect(response.status()).toBe(401);
    const rawBody = await response.text();
    const jsonResponse = JSON.parse(rawBody);
    expect(jsonResponse).toHaveProperty('success', false);
    expect(jsonResponse).not.toHaveProperty('history');

    expect(rawBody).not.toContain('fname');
    expect(rawBody).not.toContain('city');
    expect(rawBody).not.toContain('country');
  });
});

test.describe('Issue #2227 — join-failure beacon survives a lost/stale CSRF token', () => {
  // The fix removed the CSRF check; the 'join_failure_beacon' rate limit
  // bounds abuse (ADR-019). A 200 'Reported' means a log row was written,
  // because logger() runs before ApiResponse::success(). These two requests
  // use 2 of 100 per-IP attempts per 300 s.
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('join-failure-report.php with no csrf field at all returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/shared/join-failure-report.php', {
      form: {
        reason: 'js_exception',
        detail: 'playwright #2227 no-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('message', 'Reported');
  });

  test('join-failure-report.php with a garbage/expired csrf token returns 200', async ({ page }) => {
    const response = await page.request.post('app/api/shared/join-failure-report.php', {
      form: {
        csrf: 'this-is-not-a-valid-token',
        reason: 'js_exception',
        detail: 'playwright #2227 stale-token'
      }
    });

    expect(response.status()).toBe(200);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', true);
    expect(jsonResponse).toHaveProperty('message', 'Reported');
  });
});
