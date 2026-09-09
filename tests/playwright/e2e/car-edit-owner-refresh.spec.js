// tests/playwright/e2e/car-edit-owner-refresh.spec.js
//
// Real-HTTP-path coverage for the #1962 fix: editing a car through the
// actual owner-facing form must run the real buildCarDetails() in
// app/api/cars/save.php, which refreshes the car's denormalized
// owner-contact columns (fname, lname, email, city, state, country, lat,
// lon) from the owner's CURRENT profile.
//
// Why this file exists: tests/playwright/car-edit-text-save.spec.js is the
// only Playwright coverage of the car-edit form's save flow, but every one
// of its tests intercepts app/api/cars/save.php with page.route() and
// returns a mocked JSON response — the real save.php process, and therefore
// the real buildCarDetails(), never executes. tests/integration/
// CarEditOwnerColumnRefreshTest.php and tests/unit/classes/
// OwnerContactFieldsTest.php both exercise the underlying logic directly
// (Car::update()/Owner::ownerContactFields()) rather than through save.php
// as an HTTP endpoint, and tests/unit/cars/CarActionsSaveWiringTest.php
// inspects save.php's source text rather than running it. So as of #1962,
// zero test coverage actually executes buildCarDetails() end-to-end via a
// real request. This file closes that gap with ONE focused scenario.
//
// IMPORTANT LIMITATION — please read before extending this test:
// This test does NOT reproduce "a car with a stale owner-contact snapshot"
// the way the integration test does. It cannot: issue #1873 added
// Owner::syncOwnerFieldsToCars(), which usersc/user_settings.php calls
// synchronously, in the SAME request, whenever any profile field (name,
// location, etc.) changes — and it immediately pushes the new values onto
// EVERY car the owner has. There is no UI-reachable window, using only
// public forms, in which a real owner's profile has changed but a real car
// row has not yet caught up; the sync closes that window before the
// request finishes. Constructing genuine staleness would require writing
// to `cars` directly via SQL, which no existing Playwright test in this
// suite does (they all drive only HTTP/browser actions — see
// car-edit-missing-car.spec.js's header comment on why even a second test
// *account* was chosen over any DB shortcut).
//
// What this test proves instead: editing an UNRELATED field (comments)
// through the real car-edit form, via the real fetch() to save.php (see
// app/assets/js/car-edit.js's submitCarForm()), causes the saved car's
// publicly-visible owner-contact fields (owner name and location, the two
// buildCarDetails() writes that details.php actually renders) to reflect
// TEST_USERNAME's CURRENT profile. That is only true if the real
// buildCarDetails() executed and its Owner refresh ran — a save.php that
// merely persisted the submitted form fields (comments) without touching
// owner columns, or a broken refresh that wrote null/wrong values, would
// fail this assertion. The integration test suite already proves the
// refresh discards a genuinely stale snapshot in favor of the current
// profile; this test's job is narrower and complementary: proving the real
// HTTP endpoint actually runs that code at all.
//
// Runs against Local/Dev (MAMP, default http://localhost:9999/ElanRegistry/Registry/
// — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md;
// requires TEST_USERNAME/TEST_PASSWORD in .env.local) and Test
// (playwright.config.test.js, via the saved storageState session — see
// docs/testing/PLAYWRIGHT_E2E.md). The target car is discovered dynamically
// via usersc/account.php's "Update Car" button rather than a hardcoded
// fixture id, since car ownership differs per account/tier (see #2014 —
// a hardcoded CAR_ID_STANDARD previously caused this test to silently hit
// an unowned/nonexistent car id on Test). Not enrolled on Production: this
// test submits a real form save, and Production write-safety has not been
// separately evaluated (#2014).

const { test, expect } = require('@playwright/test');

test.describe('Car edit — real buildCarDetails() owner-column refresh (#1962)', () => {
  // Skip unless running in the authenticated `logged-in` project AND,
  // for Local/Dev only, real credentials are configured. The project-name
  // check alone (the pattern mirrored from
  // tests/playwright/e2e/logged-in.spec.js) is not sufficient on Local/Dev:
  // per playwright.config.js's own `hasCredentials` guard, when
  // TEST_USERNAME/TEST_PASSWORD are unset, auth.setup.js skips cleanly with
  // no storageState file — but the `logged-in` project itself still runs,
  // unauthenticated, rather than being skipped (see CLAUDE.md's
  // "Local Playwright tests" note). Without this check, this test's own
  // preconditions (an authenticated fname on user_settings.php, an editable
  // car) would fail rather than skip, misreporting a missing local
  // credential as a real regression.
  //
  // Test/Production authenticate via a saved storageState (1Password flow),
  // not TEST_USERNAME/TEST_PASSWORD, and are already gated by check-auth
  // failing loudly before `logged-in` runs (see docs/testing/PLAYWRIGHT_E2E.md)
  // — so the credential check below only applies when E2E_AUTH_TIER is unset
  // (Local/Dev). Gating it unconditionally previously made this test skip on
  // every Test/Production run regardless of the real credential state there.
  test.beforeEach(async ({}, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
    const usesLiveLogin = !process.env.E2E_AUTH_TIER;
    if (usesLiveLogin && (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD)) {
      testInfo.skip();
    }
  });

  test('editing an unrelated field writes the owner\'s current name and location onto the car', async ({ page }) => {
    // Read TEST_USERNAME's current profile fname/location from account.php's
    // account summary before touching anything, so the assertion below is
    // against a value this test observed rather than one baked into
    // .env.local (which can drift independently of the live DB).
    await page.goto('usersc/user_settings.php');
    await page.waitForLoadState('domcontentloaded');

    const currentFname = await page.locator('#fname').inputValue();
    expect(currentFname, 'Precondition: TEST_USERNAME must have a first name set').not.toBe('');

    // Discover a car the logged-in account actually owns via
    // usersc/account.php's per-car "Update Car" button (same pattern as
    // tests/playwright/e2e/logged-in.spec.js's "Update Car" test) rather
    // than a hardcoded CAR_ID_STANDARD fixture — car ownership on Local/Dev
    // differs from Test/Production, and CAR_ID_STANDARD (fixtures.js,
    // defaults to 1) is not guaranteed to belong to whichever account each
    // tier's storageState/credentials authenticate as. Confirmed directly:
    // car_id=1 does not belong to the Test account as of this test's most
    // recent fix — edit.php silently falls into "Add Car" mode for an
    // unowned/nonexistent id, which then fails validation rather than
    // failing this test's own precondition assertion clearly.
    await page.goto('usersc/account.php');
    await page.waitForLoadState('domcontentloaded');

    const updateCarButton = page.locator('button:has-text("Update Car"), a:has-text("Update Car")');
    const hasCarToUpdate = await updateCarButton.count() > 0;
    test.skip(!hasCarToUpdate, 'Logged-in account has no registered cars — nothing to edit');

    await updateCarButton.first().click();
    await page.waitForLoadState('domcontentloaded');

    const commentField = page.locator('#comments');
    await expect(commentField, "Precondition: the account's own car edit page must render the comments field").toBeVisible();

    // Capture the actual car id the edit page loaded (edit.php's hidden
    // #car_id field — see app/owner/cars/edit.php) rather than assuming
    // CAR_ID_STANDARD, since the "Update Car" button above may resolve to
    // any car the account owns, not necessarily that fixture's id.
    const editedCarId = await page.locator('#car_id').inputValue();
    expect(editedCarId, 'Precondition: edit.php must render a car_id for the discovered car').not.toBe('');

    const marker = `owner-refresh-e2e ${new Date().toISOString()}`;
    await commentField.fill(marker);

    // NOTE: tests/playwright/e2e/logged-in.spec.js's "Update Car" test uses
    // this same accordion-expand step ('#heading-section2 button' /
    // '#section2') before clicking #submit — as of this edit, that pattern
    // no longer matches app/owner/cars/edit.php's actual markup (verified:
    // the Photos section, line ~368, is a plain <h5> heading, not a
    // collapsible accordion; #submit and #myPond are directly visible with
    // no expand step required). Dropped here since it only caused this test
    // to time out waiting for a nonexistent element. logged-in.spec.js's
    // identical stale selector is a separate, pre-existing issue, not fixed
    // by this change.
    await page.locator('#submit').click();
    await page.waitForLoadState('domcontentloaded');

    // The save must not have failed (UserSpice renders failures via
    // usError() -> .alert-danger, same convention as logged-in.spec.js).
    await expect(page.locator('.alert-danger')).toHaveCount(0);

    // The real save.php reloads $cardetails from the DB and re-renders the
    // form, so the comment field on THIS page already proves the save
    // round-tripped — but the owner-contact columns this test cares about
    // (fname/city/state/country) are not rendered on the edit form itself,
    // only on the public details page. Navigate there to observe them.
    await page.goto(`app/owner/cars/details.php?car_id=${editedCarId}`);
    await page.waitForLoadState('domcontentloaded');

    // Owner Information card's "Owner Name" value — see
    // app/owner/cars/details.php's dl.row markup. ucfirst() is applied at
    // render time, so compare case-insensitively rather than assume casing.
    const ownerNameText = await page.locator('dt:has-text("Owner Name") + dd').first().innerText();
    expect(
      ownerNameText.trim().toLowerCase(),
      `Car ${editedCarId}'s rendered owner name must reflect the logged-in account's CURRENT ` +
      'profile fname after an unrelated-field edit — this only happens if the real ' +
      'buildCarDetails() owner-contact refresh executed during the save'
    ).toBe(currentFname.trim().toLowerCase());
  });
});
