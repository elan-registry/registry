// Join form blocked-submit feedback (#2043), run in a real browser on
// join.php. The location gate (required field plus a selected result or GPS
// fix) must tell the visitor what to do, and the client_validation beacon
// must report field names only.

const { test, expect } = require('@playwright/test');

const LOCATION_INPUT = '#location-picker-registration-input';
const LOCATION_FEEDBACK = '#location-picker-registration-feedback';
const LOCATION_ERROR = '#location-picker-registration-error';
const SUMMARY = '#join-validation-summary';
const BEACON_URL = '**/app/api/shared/join-failure-report.php';
const SELECT_TEXT = 'Select your town or city from the list, or press Use My Current Location.';
const GPS_FALLBACK = 'Type your town or city in the Location box and select it from the list.';

const ROME = {
  city: 'Rome',
  state: 'Lazio',
  country: 'Italy',
  lat: '41.8933203',
  lon: '12.4829321',
  display: 'Rome, Lazio, Italy',
};

// The password meter keeps #next_button disabled until the password meets the
// site rules, so the fixed password below has upper and lower case, a number
// and a symbol.
// The meter listens for keyup, which fill() does not fire.
async function fillPasswords(page, password, confirm) {
  await page.locator('#password').fill(password);
  await page.locator('#confirm').fill(confirm);
  await page.locator('#password').dispatchEvent('keyup');
  await page.locator('#confirm').dispatchEvent('keyup');
}

// Fill every required field except location. Values are fixed literals.
async function fillValidFields(page) {
  await page.locator('#email').fill('blocked.submit@example.com');
  await page.locator('#fname').fill('Test');
  await page.locator('#lname').fill('Visitor');
  await fillPasswords(page, 'Xq7!vLm#92zRt&Kd', 'Xq7!vLm#92zRt&Kd');
}

// Record every POST to join.php and abort it, so no test creates an account.
async function abortJoinPosts(page) {
  const posts = [];
  await page.route(/\/join\.php(\?.*)?$/, async (route) => {
    if (route.request().method() === 'POST') {
      posts.push(route.request().postData());
      await route.abort();
    } else {
      await route.continue();
    }
  });
  return posts;
}

async function routeLocationSearch(page, results) {
  await page.route('**/app/api/shared/location-search.php', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, results }),
    });
  });
}

// Type a query and pick the first suggestion.
async function selectFirstResult(page, query) {
  await page.locator(LOCATION_INPUT).fill(query);
  const option = page.locator('#location-picker-registration-results [role="option"]').first();
  await expect(option).toBeVisible();
  await option.click();
}

test.describe('Join form blocked submit feedback (#2043)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('users/join.php');
    await expect(page.locator(LOCATION_INPUT)).toHaveCount(1);
  });

  test('empty location blocks submit with a visible message, focus and summary', async ({ page }) => {
    const posts = await abortJoinPosts(page);
    await fillValidFields(page);

    await page.locator('#next_button').click();

    await expect(page.locator(LOCATION_FEEDBACK)).toBeVisible();
    await expect(page.locator(LOCATION_FEEDBACK)).toHaveText(SELECT_TEXT);
    await expect(page.locator(LOCATION_INPUT)).toBeFocused();
    await expect(page.locator(SUMMARY)).toBeVisible();
    await expect(page.locator(SUMMARY)).toHaveText('Please fix this field before you continue: Location.');
    expect(posts).toHaveLength(0);
    expect(new URL(page.url()).pathname).toMatch(/join\.php$/);
  });

  test('typed but unselected location is blocked like an empty one', async ({ page }) => {
    await routeLocationSearch(page, [ROME]);
    const posts = await abortJoinPosts(page);
    await fillValidFields(page);
    await page.locator(LOCATION_INPUT).fill('Rome');

    await page.locator('#next_button').click();

    await expect(page.locator(LOCATION_FEEDBACK)).toBeVisible();
    await expect(page.locator(LOCATION_FEEDBACK)).toHaveText(SELECT_TEXT);
    await expect(page.locator(LOCATION_INPUT)).toBeFocused();
    await expect(page.locator(SUMMARY)).toContainText('Location');
    expect(posts).toHaveLength(0);
  });

  test('selecting a result clears the message, the alert and the invalid state', async ({ page }) => {
    await routeLocationSearch(page, [ROME]);
    await abortJoinPosts(page);
    await fillValidFields(page);
    await page.locator('#next_button').click();
    await expect(page.locator(SUMMARY)).toBeVisible();
    await expect(page.locator(LOCATION_FEEDBACK)).toBeVisible();

    await selectFirstResult(page, 'Rome');

    await expect(page.locator(SUMMARY)).toBeHidden();
    await expect(page.locator(LOCATION_FEEDBACK)).toBeHidden();
    const valid = await page.locator(LOCATION_INPUT).evaluate((el) => el.checkValidity());
    expect(valid).toBe(true);
  });

  test('typing after a selection clears the hidden coordinates and invalidates the input', async ({ page }) => {
    await routeLocationSearch(page, [ROME]);
    await selectFirstResult(page, 'Rome');
    await expect(page.locator('#location-picker-registration-lat')).toHaveValue(ROME.lat);
    await expect(page.locator('#location-picker-registration-city')).toHaveValue(ROME.city);

    await page.locator(LOCATION_INPUT).pressSequentially('x');

    for (const field of ['city', 'state', 'country', 'lat', 'lon']) {
      await expect(page.locator(`#location-picker-registration-${field}`)).toHaveValue('');
    }
    const valid = await page.locator(LOCATION_INPUT).evaluate((el) => el.checkValidity());
    expect(valid).toBe(false);
  });

  const gpsCases = [
    {
      name: 'code 1',
      code: 1,
      text: 'Location access is off. ' + GPS_FALLBACK,
    },
    {
      name: 'code 2',
      code: 2,
      text: 'We could not find your location. ' + GPS_FALLBACK,
    },
    {
      name: 'code 3',
      code: 3,
      text: 'Finding your location took too long. ' + GPS_FALLBACK,
    },
    {
      name: 'unsupported browser',
      code: null,
      text: 'This browser cannot share your location. ' + GPS_FALLBACK,
    },
  ];

  for (const gpsCase of gpsCases) {
    test(`GPS failure (${gpsCase.name}) shows plain-language copy`, async ({ page }) => {
      await page.route(BEACON_URL, (route) => route.fulfill({
        status: 200, contentType: 'application/json', body: '{"success":true}',
      }));
      if (gpsCase.code === null) {
        await page.addInitScript(() => {
          Object.defineProperty(window.navigator, 'geolocation', { value: undefined, configurable: true });
        });
      } else {
        await page.addInitScript((code) => {
          window.navigator.geolocation.getCurrentPosition = (success, error) => {
            error({ code, message: 'stubbed geolocation error' });
          };
        }, gpsCase.code);
      }
      await page.reload();

      const gpsButton = page.locator('[id$="-gps-btn"]');
      await expect(gpsButton).toHaveCount(1);
      await gpsButton.click();

      const errorDiv = page.locator(LOCATION_ERROR);
      await expect(errorDiv).toBeVisible();
      await expect(errorDiv).toHaveText(gpsCase.text);
      await expect(errorDiv).not.toHaveText(/code|PositionError|unavailable|timed out/i);
    });
  }

  test('GPS success with a routed reverse geocode lets the submit through', async ({ page }) => {
    const posts = await abortJoinPosts(page);
    await page.route('**/app/api/shared/location-reverse.php', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, location: ROME }),
      });
    });
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success) => {
        success({ coords: { latitude: 41.8933203, longitude: 12.4829321 } });
      };
    });
    await page.reload();
    await fillValidFields(page);

    await page.locator('[id$="-gps-btn"]').click();
    await expect(page.locator(LOCATION_INPUT)).toHaveValue(ROME.display);

    const sent = page.waitForRequest((req) => req.method() === 'POST' && /\/join\.php/.test(req.url()));
    await page.locator('#next_button').click();
    await sent;

    expect(posts).toHaveLength(1);
    await expect(page.locator(SUMMARY)).toBeHidden();
  });

  test('manual selection with valid fields lets the submit through', async ({ page }) => {
    const posts = await abortJoinPosts(page);
    await routeLocationSearch(page, [ROME]);
    await fillValidFields(page);
    await selectFirstResult(page, 'Rome');

    const sent = page.waitForRequest((req) => req.method() === 'POST' && /\/join\.php/.test(req.url()));
    await page.locator('#next_button').click();
    await sent;

    expect(posts).toHaveLength(1);
    expect(posts[0]).toContain('lat=' + ROME.lat);
    await expect(page.locator(SUMMARY)).toBeHidden();
  });

  test('mismatched passwords send a client_validation beacon with field names only', async ({ page }) => {
    await abortJoinPosts(page);
    const typedEmail = 'beacon.tester@example.com';
    const typedPassword = 'Secr3t-Pass-Value';
    const typedConfirm = 'Different-Value9';

    const beaconBodies = [];
    await page.route(BEACON_URL, async (route) => {
      beaconBodies.push(route.request().postData());
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });

    await page.locator('#email').fill(typedEmail);
    await page.locator('#fname').fill('Test');
    await page.locator('#lname').fill('Visitor');
    await page.locator('#password').fill(typedPassword);
    await page.locator('#confirm').fill(typedConfirm);
    // The password meter disables #next_button while the passwords differ, so
    // submit the form the way the browser does after a click.
    await page.locator('#join-form').evaluate((form) => form.requestSubmit());

    await expect.poll(() => beaconBodies.length).toBeGreaterThan(0);
    const body = beaconBodies[0];
    const params = new URLSearchParams(body);
    expect(params.get('reason')).toBe('client_validation');
    expect(params.get('detail')).toContain('confirm');
    expect(params.get('detail')).toContain('location');
    expect(body).not.toContain(typedPassword);
    expect(body).not.toContain(typedConfirm);
    expect(body).not.toContain(typedEmail);
    expect(body).not.toContain(encodeURIComponent(typedEmail));
  });

  test('two invalid fields scroll the first into view, focus it and list both', async ({ page }) => {
    await abortJoinPosts(page);
    await page.locator('#fname').fill('Test');
    await page.locator('#lname').fill('Visitor');
    await fillPasswords(page, 'Xq7!vLm#92zRt&Kd', 'Xq7!vLm#92zRt&Kd');

    // Start at the bottom of the page so the email field is off-screen.
    await page.locator('#next_button').scrollIntoViewIfNeeded();
    await expect(page.locator('#email')).not.toBeInViewport();

    await page.locator('#next_button').click();

    await expect(page.locator('#email')).toBeFocused();
    await expect(page.locator('#email')).toBeInViewport();
    await expect(page.locator(SUMMARY)).toBeVisible();
    await expect(page.locator(SUMMARY)).toContainText('Email');
    await expect(page.locator(SUMMARY)).toContainText('Location');
  });

  test('a GPS fix after a blocked submit clears the message and the alert', async ({ page }) => {
    await abortJoinPosts(page);
    await page.route('**/app/api/shared/location-reverse.php', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, location: ROME }),
      });
    });
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success) => {
        success({ coords: { latitude: 41.8933203, longitude: 12.4829321 } });
      };
    });
    await page.reload();
    await fillValidFields(page);
    await page.locator('#next_button').click();
    await expect(page.locator(SUMMARY)).toBeVisible();
    await expect(page.locator(LOCATION_FEEDBACK)).toBeVisible();

    await page.locator('[id$="-gps-btn"]').click();

    await expect(page.locator(LOCATION_INPUT)).toHaveValue(ROME.display);
    await expect(page.locator(SUMMARY)).toBeHidden();
    await expect(page.locator(LOCATION_FEEDBACK)).toBeHidden();
  });

  test('a GPS error clears once a location is selected from the list', async ({ page }) => {
    await routeLocationSearch(page, [ROME]);
    await page.addInitScript(() => {
      window.navigator.geolocation.getCurrentPosition = (success, error) => {
        error({ code: 1, message: 'User denied Geolocation' });
      };
    });
    await page.reload();

    await page.locator('[id$="-gps-btn"]').click();
    await expect(page.locator(LOCATION_ERROR)).toBeVisible();

    await selectFirstResult(page, 'Rome');

    await expect(page.locator(LOCATION_ERROR)).toBeHidden();
  });

  test('the summary drops a field once it is fixed', async ({ page }) => {
    await abortJoinPosts(page);
    await routeLocationSearch(page, [ROME]);
    await page.locator('#fname').fill('Test');
    await page.locator('#lname').fill('Visitor');
    await fillPasswords(page, 'Xq7!vLm#92zRt&Kd', 'Xq7!vLm#92zRt&Kd');
    await page.locator('#next_button').click();
    await expect(page.locator(SUMMARY)).toHaveText('Please fix these fields before you continue: Email, Location.');

    await page.locator('#email').fill('blocked.submit@example.com');

    await expect(page.locator(SUMMARY)).toHaveText('Please fix this field before you continue: Location.');

    await selectFirstResult(page, 'Rome');

    await expect(page.locator(SUMMARY)).toBeHidden();
  });

  test('typing in a field does not rewrite an unchanged summary', async ({ page }) => {
    await abortJoinPosts(page);
    await page.locator('#fname').fill('Test');
    await page.locator('#lname').fill('Visitor');
    await fillPasswords(page, 'Xq7!vLm#92zRt&Kd', 'Xq7!vLm#92zRt&Kd');
    await page.locator('#next_button').click();
    await expect(page.locator(SUMMARY)).toHaveText('Please fix these fields before you continue: Email, Location.');

    await page.evaluate((selector) => {
      window.summaryMutations = 0;
      new MutationObserver((records) => {
        window.summaryMutations += records.length;
      }).observe(document.querySelector(selector), { childList: true, characterData: true, subtree: true });
    }, SUMMARY);
    await page.locator('#fname').pressSequentially('abc');

    expect(await page.evaluate(() => window.summaryMutations)).toBe(0);
  });

  test('repeated blocked submits send one beacon until the invalid fields change', async ({ page }) => {
    await abortJoinPosts(page);
    const beaconBodies = [];
    await page.route(BEACON_URL, async (route) => {
      beaconBodies.push(route.request().postData());
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });
    await fillValidFields(page);

    await page.locator('#next_button').click();
    await page.locator('#next_button').click();
    await page.locator('#next_button').click();
    await expect.poll(() => beaconBodies.length).toBe(1);

    await page.locator('#email').fill('');
    await page.locator('#next_button').click();

    await expect.poll(() => beaconBodies.length).toBe(2);
    expect(new URLSearchParams(beaconBodies[1]).get('detail')).toContain('email');
  });
});

// The picker is shared with user_settings.php and the admin owner profile.
// Neither sets requireSelection. The admin owner profile pre-fills with
// setLocation().
test.describe('LocationPicker selection state outside the join form (#2043)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('users/join.php');
    await page.evaluate(() => {
      const div = document.createElement('div');
      div.id = 'picker-under-test';
      document.body.appendChild(div);
    });
  });

  for (const requireSelection of [false, true]) {
    test(`setLocation() writes the hidden fields and leaves the input valid (requireSelection: ${requireSelection})`, async ({ page }) => {
      const state = await page.evaluate(({ location, requireSelection }) => {
        const picker = new LocationPicker({ containerId: 'picker-under-test', requireSelection });
        picker.setLocation(location, location.display);
        const input = document.getElementById('picker-under-test-input');
        input.value = location.display;
        const fields = {};
        ['city', 'state', 'country', 'lat', 'lon'].forEach((f) => {
          fields[f] = document.getElementById('picker-under-test-' + f).value;
        });
        return { fields, valid: input.checkValidity() };
      }, { location: ROME, requireSelection });

      expect(state.fields).toEqual({
        city: ROME.city, state: ROME.state, country: ROME.country, lat: ROME.lat, lon: ROME.lon,
      });
      expect(state.valid).toBe(true);
    });
  }

  test('without requireSelection, typing keeps a pre-filled location and the input stays valid', async ({ page }) => {
    await page.evaluate((location) => {
      const picker = new LocationPicker({ containerId: 'picker-under-test' });
      picker.setLocation(location, location.display);
    }, ROME);
    // A single character is below the search threshold, so no search request runs.
    await page.locator('#picker-under-test-input').fill('R');

    const state = await page.evaluate(() => ({
      lat: document.getElementById('picker-under-test-lat').value,
      valid: document.getElementById('picker-under-test-input').checkValidity(),
    }));
    expect(state.lat).toBe(ROME.lat);
    expect(state.valid).toBe(true);
  });
});
