// LocationPicker search race and combobox behavior (#1512), run in a real
// browser on join.php. Every search is stubbed with page.route, so no test
// calls Photon.

const { test, expect } = require('@playwright/test');

const LOCATION_INPUT = '#location-picker-registration-input';
const RESULTS = '#location-picker-registration-results';
const OPTIONS = `${RESULTS} [role="option"]`;
const LIVE_REGION = '#location-picker-registration-live';
const SEARCH_URL = '**/app/api/shared/location-search.php';

const ROME_GA = {
  city: 'Rome', state: 'Georgia', country: 'United States',
  lat: '34.2570', lon: '-85.1647', display: 'Rome, Georgia, United States',
};
const ROME_IT = {
  city: 'Rome', state: 'Lazio', country: 'Italy',
  lat: '41.8933203', lon: '12.4829321', display: 'Rome, Lazio, Italy',
};
const ROSTOCK = {
  city: 'Rostock', state: 'Mecklenburg-Vorpommern', country: 'Germany',
  lat: '54.0924', lon: '12.0991', display: 'Rostock, Mecklenburg-Vorpommern, Germany',
};

// Answer searches by query text. A handler value is a result list, or a
// function that returns a promise of one, so a test can hold a response back.
// Returns the queries seen, in request order.
async function stubSearchByQuery(page, handlers) {
  const seen = [];
  await page.route(SEARCH_URL, async (route) => {
    const query = new URLSearchParams(route.request().postData()).get('query');
    seen.push(query);
    const handler = handlers[query];
    const results = typeof handler === 'function' ? await handler() : handler;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, results }),
    });
  });
  return seen;
}

// The query text of a search request.
function queryOf(request) {
  return new URLSearchParams(request.postData() ?? '').get('query');
}

// Wait until the held-back request for `query` is answered or aborted. The
// event that does not fire rejects when the page closes, so it is ignored.
function lateRequestSettled(page, query) {
  return Promise.race([
    page.waitForEvent('requestfinished', (req) => queryOf(req) === query).catch(() => null),
    page.waitForEvent('requestfailed', (req) => queryOf(req) === query).catch(() => null),
  ]);
}

// A response the test releases by hand.
function gate() {
  let release;
  const promise = new Promise((resolve) => { release = resolve; });
  return { promise, release };
}

// Two animation frames after a late response lets the picker's fetch
// continuation run, so an assertion that the DOM did not change is not
// checked before the stale response arrives.
async function settle(page) {
  await page.evaluate(() => new Promise((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(resolve));
  }));
}

test.beforeEach(async ({ page }) => {
  await page.goto('users/join.php');
  await expect(page.locator(LOCATION_INPUT)).toHaveCount(1);
});

test.describe('LocationPicker search race (#1512)', () => {
  test('a late response for an older query does not replace the newer results', async ({ page }) => {
    const oldGate = gate();
    const seen = await stubSearchByQuery(page, {
      Ro: () => oldGate.promise.then(() => [ROSTOCK]),
      Rome: [ROME_GA, ROME_IT],
    });

    await page.locator(LOCATION_INPUT).fill('Ro');
    await expect.poll(() => seen).toEqual(['Ro']);
    await page.locator(LOCATION_INPUT).fill('Rome');
    await expect(page.locator(OPTIONS)).toHaveCount(2);

    const lateResponse = page.waitForResponse((res) => res.url().includes('location-search.php'));
    oldGate.release();
    await lateResponse;
    await settle(page);

    await expect(page.locator(OPTIONS)).toHaveCount(2);
    await expect(page.locator(RESULTS)).toContainText('Rome, Georgia, United States');
    await expect(page.locator(RESULTS)).not.toContainText('Rostock');
    await expect(page.locator(LIVE_REGION)).toHaveText('2 locations found.');
  });

  // A refusal fails after the JSON read, a network error fails in fetch, so
  // each reaches a different stale check.
  const lateFailures = [
    {
      name: 'refusal',
      answer: (route) => route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'Rate limit exceeded. Please try again in a moment.' }),
      }),
    },
    { name: 'network failure', answer: (route) => route.abort() },
  ];

  for (const failure of lateFailures) {
    test(`a late ${failure.name} for an older query shows no error and sends no beacon`, async ({ page }) => {
      const oldGate = gate();
      const seen = [];
      await page.route(SEARCH_URL, async (route) => {
        const query = queryOf(route.request());
        seen.push(query);
        if (query === 'Ro') {
          await oldGate.promise;
          await failure.answer(route);
          return;
        }
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true, results: [ROME_GA, ROME_IT] }),
        });
      });
      const beaconBodies = [];
      await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
        beaconBodies.push(route.request().postData());
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
      });

      await page.locator(LOCATION_INPUT).fill('Ro');
      await expect.poll(() => seen).toEqual(['Ro']);
      await page.locator(LOCATION_INPUT).fill('Rome');
      await expect(page.locator(OPTIONS)).toHaveCount(2);

      const late = lateRequestSettled(page, 'Ro');
      oldGate.release();
      await late;
      await settle(page);

      await expect(page.locator('#location-picker-registration-error')).toBeHidden();
      await expect(page.locator(OPTIONS)).toHaveCount(2);
      expect(beaconBodies.filter((body) => body.includes('location_search_failed'))).toEqual([]);
    });
  }

  test('a late response that arrives while the next search waits for the debounce does not show', async ({ page }) => {
    const oldGate = gate();
    const seen = await stubSearchByQuery(page, {
      Ro: () => oldGate.promise.then(() => [ROSTOCK]),
      Rom: [ROME_GA],
    });

    await page.locator(LOCATION_INPUT).fill('Ro');
    await expect.poll(() => seen).toEqual(['Ro']);
    // The "Rom" search waits 300 ms for the debounce. The "Ro" response
    // arrives inside that window.
    await page.locator(LOCATION_INPUT).fill('Rom');
    const late = lateRequestSettled(page, 'Ro');
    oldGate.release();
    await late;
    await settle(page);

    // An instant check: a retrying assertion would wait until the "Rom"
    // results replace a stale list. The list is read first, so a "Rom" search
    // that already ran fails the seen check below.
    const listText = await page.locator(RESULTS).textContent();
    expect(seen).toEqual(['Ro']);
    expect(listText).not.toContain('Rostock');

    await expect(page.locator(OPTIONS)).toHaveCount(1);
    await expect(page.locator(RESULTS)).toContainText('Rome, Georgia, United States');
  });

  test('Escape cancels a search that is in flight, so its response does not open the list', async ({ page }) => {
    const romeGate = gate();
    const seen = await stubSearchByQuery(page, { Rome: () => romeGate.promise.then(() => [ROME_GA, ROME_IT]) });

    await page.locator(LOCATION_INPUT).fill('Rome');
    await expect.poll(() => seen).toEqual(['Rome']);
    await page.locator(LOCATION_INPUT).press('Escape');
    const late = lateRequestSettled(page, 'Rome');
    romeGate.release();
    await late;
    await settle(page);

    await expect(page.locator(RESULTS)).toBeHidden();
    await expect(page.locator(LOCATION_INPUT)).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#location-picker-registration-spinner')).toBeHidden();
  });

  test('a selection cancels the search for the newer text, so its response does not reopen the list', async ({ page }) => {
    const romeGate = gate();
    const seen = await stubSearchByQuery(page, {
      Rom: [ROME_GA, ROME_IT],
      Rome: () => romeGate.promise.then(() => [ROSTOCK]),
    });

    await page.locator(LOCATION_INPUT).fill('Rom');
    await expect(page.locator(OPTIONS)).toHaveCount(2);
    await page.locator(LOCATION_INPUT).fill('Rome');
    await expect.poll(() => seen).toEqual(['Rom', 'Rome']);
    await page.locator(OPTIONS).first().click();

    const late = lateRequestSettled(page, 'Rome');
    romeGate.release();
    await late;
    await settle(page);

    // The 200 ms blur timer from the click can hide a reopened list, but it
    // does not clear the list's content.
    await expect(page.locator(RESULTS)).not.toContainText('Rostock');
    await expect(page.locator('#location-picker-registration-lat')).toHaveValue(ROME_GA.lat);
  });

  test('a cached result hides the error of an earlier failed search', async ({ page }) => {
    const seen = [];
    await page.route(SEARCH_URL, async (route) => {
      const query = queryOf(route.request());
      seen.push(query);
      if (query === 'Zzzz') {
        await route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false}' });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, results: [ROME_GA, ROME_IT] }),
      });
    });
    const error = page.locator('#location-picker-registration-error');
    const input = page.locator(LOCATION_INPUT);

    await input.fill('Rome');
    await expect(page.locator(OPTIONS)).toHaveCount(2);
    await input.fill('Zzzz');
    await expect(error).toBeVisible();

    await input.fill('Rome');
    await expect(page.locator(OPTIONS)).toHaveCount(2);
    await expect(error).toBeHidden();
    // One request for "Rome" proves the second search came from the cache.
    expect(seen).toEqual(['Rome', 'Zzzz']);
  });

  test('a malformed result list is not cached, so the same search fails the same way again', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    const seen = [];
    await page.route(SEARCH_URL, async (route) => {
      seen.push(queryOf(route.request()));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, results: 'not a list' }),
      });
    });
    const error = page.locator('#location-picker-registration-error');
    const input = page.locator(LOCATION_INPUT);

    await input.fill('Zzzz');
    await expect(error).toBeVisible();
    await input.fill('Zzz');
    await expect.poll(() => seen).toEqual(['Zzzz', 'Zzz']);
    await input.fill('Zzzz');

    await expect.poll(() => seen).toEqual(['Zzzz', 'Zzz', 'Zzzz']);
    await expect(error).toBeVisible();
    expect(pageErrors).toEqual([]);
  });

  test('a GPS click cancels a search in flight, so its late failure shows no error and sends no beacon', async ({ page }) => {
    const searchGate = gate();
    const reverseGate = gate();
    const seen = [];
    await page.route(SEARCH_URL, async (route) => {
      seen.push(queryOf(route.request()));
      await searchGate.promise;
      await route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'Rate limit exceeded. Please try again in a moment.' }),
      });
    });
    await page.route('**/app/api/shared/location-reverse.php', async (route) => {
      await reverseGate.promise;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, location: ROME_IT }),
      });
    });
    const beaconBodies = [];
    await page.route('**/app/api/shared/join-failure-report.php', async (route) => {
      beaconBodies.push(route.request().postData());
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });
    await page.evaluate(() => {
      window.navigator.geolocation.getCurrentPosition = (success) => {
        success({ coords: { latitude: 41.8933203, longitude: 12.4829321 } });
      };
    });

    await page.locator(LOCATION_INPUT).fill('Ro');
    await expect.poll(() => seen).toEqual(['Ro']);
    await page.locator('#location-picker-registration-gps-btn').click();

    // The search fails while the reverse lookup is still held back.
    const late = lateRequestSettled(page, 'Ro');
    searchGate.release();
    await late;
    await settle(page);

    await expect(page.locator('#location-picker-registration-error')).toBeHidden();
    expect(beaconBodies.filter((body) => body.includes('location_search_failed'))).toEqual([]);

    reverseGate.release();
    await expect(page.locator('#location-picker-registration-lat')).toHaveValue(ROME_IT.lat);
  });

  test('a late response after the input drops below two characters does not reopen the list', async ({ page }) => {
    const oldGate = gate();
    const seen = await stubSearchByQuery(page, { Ro: () => oldGate.promise.then(() => [ROSTOCK]) });

    await page.locator(LOCATION_INPUT).fill('Ro');
    await expect.poll(() => seen).toEqual(['Ro']);
    await page.locator(LOCATION_INPUT).fill('R');
    await expect(page.locator(LOCATION_INPUT)).toHaveAttribute('aria-expanded', 'false');

    const lateResponse = page.waitForResponse((res) => res.url().includes('location-search.php'));
    oldGate.release();
    await lateResponse;
    await settle(page);

    await expect(page.locator(RESULTS)).toBeHidden();
    await expect(page.locator(OPTIONS)).toHaveCount(0);
    await expect(page.locator(LOCATION_INPUT)).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator(LIVE_REGION)).toHaveText('');
    await expect(page.locator('#location-picker-registration-spinner')).toBeHidden();
  });
});

test.describe('LocationPicker combobox accessibility (#1512)', () => {
  test('the input is a combobox that names its listbox and follows the keyboard selection', async ({ page }) => {
    await stubSearchByQuery(page, { Rome: [ROME_GA, ROME_IT, ROSTOCK] });
    const input = page.locator(LOCATION_INPUT);
    await expect(page.getByRole('combobox', { name: 'Search location' })).toHaveCount(1);
    await expect(input).toHaveAttribute('aria-expanded', 'false');

    await input.fill('Rome');
    await expect(page.locator(OPTIONS)).toHaveCount(3);

    const listboxId = await input.getAttribute('aria-controls');
    expect(listboxId).toBe('location-picker-registration-results');
    await expect(page.locator(`#${listboxId}`)).toHaveAttribute('role', 'listbox');
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await expect(input).not.toHaveAttribute('aria-activedescendant', /.*/);
    for (let i = 0; i < 3; i += 1) {
      await expect(page.locator(OPTIONS).nth(i)).toHaveAttribute('id', `location-picker-registration-option-${i}`);
      await expect(page.locator(OPTIONS).nth(i)).toHaveAttribute('aria-selected', 'false');
    }

    await input.press('ArrowDown');
    await input.press('ArrowDown');

    const second = page.locator(OPTIONS).nth(1);
    await expect(second).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator(OPTIONS).nth(0)).toHaveAttribute('aria-selected', 'false');
    await expect(page.locator(OPTIONS).nth(2)).toHaveAttribute('aria-selected', 'false');
    await expect(input).toHaveAttribute('aria-activedescendant', 'location-picker-registration-option-1');
    await expect(input).toBeFocused();

    await input.press('Escape');

    await expect(input).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator(LIVE_REGION)).toHaveText('');
    await expect(input).not.toHaveAttribute('aria-activedescendant', /.*/);
  });

  test('Enter on the active option selects it and fills the hidden fields', async ({ page }) => {
    await stubSearchByQuery(page, { Rome: [ROME_GA, ROME_IT] });
    const input = page.locator(LOCATION_INPUT);
    await input.fill('Rome');
    await expect(page.locator(OPTIONS)).toHaveCount(2);
    const secondText = await page.locator(OPTIONS).nth(1).locator('small').innerText();

    await input.press('ArrowDown');
    await input.press('ArrowDown');
    await input.press('Enter');

    await expect(input).toHaveValue(secondText);
    await expect(page.locator('#location-picker-registration-lat')).not.toHaveValue('');
  });

  test('the live region announces the result count', async ({ page }) => {
    await stubSearchByQuery(page, { Rome: [ROME_GA, ROME_IT], Roma: [ROME_IT] });
    const input = page.locator(LOCATION_INPUT);
    const live = page.locator(LIVE_REGION);
    await expect(live).toHaveAttribute('role', 'status');
    await expect(live).toHaveAttribute('aria-live', 'polite');

    await input.fill('Rome');
    await expect(live).toHaveText('2 locations found.');

    await input.fill('Roma');
    await expect(live).toHaveText('1 location found.');
  });

  test('the live region announces an empty result, Enter on the empty row throws no page error, and Escape closes it', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    await stubSearchByQuery(page, { Zzzz: [] });
    const input = page.locator(LOCATION_INPUT);

    await input.fill('Zzzz');

    await expect(page.locator(LIVE_REGION)).toHaveText('No locations found.');
    await expect(page.locator(RESULTS)).toContainText('No locations found');
    await expect(page.locator(OPTIONS)).toHaveCount(0);

    await input.press('ArrowDown');
    await input.press('Enter');
    await settle(page);

    expect(pageErrors).toEqual([]);
    await expect(page.locator('#location-picker-registration-lat')).toHaveValue('');

    await input.press('Escape');
    await expect(page.locator(RESULTS)).toBeHidden();
  });
});
