// tests/playwright/e2e/car-edit-workflow.spec.js
//
// Replacement coverage for two tests removed from
// tests/playwright/functionality.spec.js (#1949), each of which passed
// while asserting nothing real:
//
// - "car edit form workflow functions" gated its assertions behind
//   #editCarAccordion, which app/owner/cars/edit.php no longer renders (the
//   form is a flat, single-page layout now) — so it early-returned on every
//   run, on every tier, forever.
// - "chassis validation works" ran unauthenticated, so it always landed on
//   edit.php's "Add Car" fallback rather than genuine UPDATE mode. #year
//   exists in both modes so its guard opened, but chassis-validate.php's
//   real validation requires a valid year AND model (see car-edit.js's
//   #chassis blur handler), and Add Car mode starts with no model selected
//   — so its `#chassis_icon` assertion was trivially true regardless of
//   whether real validation logic ran.
//
// These two tests instead authenticate, discover a genuinely owned car in
// real edit mode (window.editCarConfig.isUpdate === true), and exercise the
// #year -> #model repopulation and chassis-validate.php AJAX flow for real.
// See each test body below for its specific assertions and the mechanisms
// behind them.
//
// Both are deliberately READ-ONLY against the database — neither clicks
// #submit. Unlike car-edit-owner-refresh.spec.js (whose entire purpose is
// exercising the real save.php path), this file's scope is client-side form
// interactions only, so it doesn't inherit that file's write-safety
// concerns (#2045/#2014).
//
// Runs against Local/Dev only (MAMP, default http://localhost:9999/ElanRegistry/Registry/
// — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md;
// requires TEST_USERNAME/TEST_PASSWORD in .env.local).
//
// NOT enrolled on Test or Production (see playwright.config.test.js /
// playwright.config.prod.js testMatch, which excludes this file) — deferred
// pending #2045, whose SUBMIT-time #model blocker may not apply here since
// this file never submits, but that assumption needs re-verifying against
// whatever #2045 concludes before enrolling independently.
//
// The target car is discovered dynamically via usersc/account.php's "Update
// Car" button rather than a hardcoded fixture id, and the credential gate
// below is tier-aware (E2E_AUTH_TIER), both for the reasons documented at
// length in car-edit-owner-refresh.spec.js — kept here so this file is ready
// for enrollment the moment #2045 lands.

const { test, expect } = require('@playwright/test');

test.describe('Car edit — year/model form workflow (#1949)', () => {
  // Skip unless running in the authenticated `admin` project AND, for
  // Local/Dev only, real credentials are configured. On Local/Dev, per
  // playwright.config.js's own `hasCredentials` guard, missing credentials
  // make auth.setup.js skip cleanly (no storageState) while `admin`
  // still runs unauthenticated (see CLAUDE.md's "Local Playwright tests"
  // note) — without this check, this test's precondition (an owned car
  // reachable from account.php) would fail rather than skip, misreporting a
  // missing local credential as a real regression. Test/Production instead
  // authenticate via a saved storageState and are already gated by
  // check-auth-admin failing loudly beforehand (docs/testing/PLAYWRIGHT_E2E.md),
  // so the credential check only applies when E2E_AUTH_TIER is unset
  // (Local/Dev) — kept here in preparation for #2045 enrollment (see file
  // header) so an unconditional gate doesn't incorrectly skip it there.
  test.beforeEach(async ({}, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip();
    }
    const usesLiveLogin = !process.env.E2E_AUTH_TIER;
    if (usesLiveLogin && (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD)) {
      testInfo.skip();
    }
  });

  // Discover a car the logged-in account actually owns via
  // usersc/account.php's per-car "Update Car" button (same pattern as
  // tests/playwright/e2e/admin.spec.js's "Update Car" test and
  // car-edit-owner-refresh.spec.js) rather than a hardcoded
  // CAR_ID_STANDARD fixture — car ownership on Local/Dev differs from
  // Test/Production, and CAR_ID_STANDARD (fixtures.js, defaults to 1) is
  // not guaranteed to belong to whichever account each tier's
  // storageState/credentials authenticate as. This matters more here than
  // it looks: edit.php silently falls back to "Add Car" mode for an
  // unowned/nonexistent id, and both tests below need genuine UPDATE-mode
  // behavior — reaching it via the account's own button is the only way to
  // guarantee that mode without a DB fixture. Shared by both tests in this
  // file since they need the identical precondition.
  async function openOwnedCarInEditMode(page) {
    await page.goto('usersc/account.php');
    await page.waitForLoadState('domcontentloaded');

    const updateCarButton = page.locator('button:has-text("Update Car"), a:has-text("Update Car")');
    const hasCarToUpdate = await updateCarButton.count() > 0;
    test.skip(!hasCarToUpdate, 'Logged-in account has no registered cars — nothing to edit');

    await updateCarButton.first().click();
    await page.waitForLoadState('domcontentloaded');

    // Capture the actual car id the edit page loaded (edit.php's hidden
    // #car_id field — app/owner/cars/edit.php:157) rather than assuming
    // CAR_ID_STANDARD, since the button above may resolve to any car the
    // account owns.
    const editedCarId = await page.locator('#car_id').inputValue();
    expect(editedCarId, 'Precondition: edit.php must render a car_id for the discovered car').not.toBe('');

    // Assert genuine edit mode rather than the Add Car fallback. edit.php
    // emits window.editCarConfig in an inline nonce'd <script> (edit.php:634)
    // and sets isUpdate from `$action === 'updateCar'` — car-edit.js reads it
    // as `cfg.isUpdate` (car-edit.js:5, :334) to decide whether to
    // pre-populate the dropdowns at all. If this is false, the interaction
    // under test is a different code path and the assertions below would be
    // testing the wrong thing, so fail here with a clear message instead.
    const isUpdateMode = await page.evaluate(() => window.editCarConfig && window.editCarConfig.isUpdate);
    expect(
      isUpdateMode,
      'Precondition: edit.php must load in update mode (window.editCarConfig.isUpdate) — ' +
      `a false value means it fell back to Add Car mode for car ${editedCarId}`
    ).toBe(true);

    // Returned for future callers that need it; neither current test uses it.
    return editedCarId;
  }

  // Drives a genuine year change and waits for #model to repopulate for
  // real — shared by both tests below since each needs a valid year+model
  // pair before its own assertions (year->model repopulation itself; a
  // real chassis-validate.php call, which requires both to be set). See
  // the individual comments at each call site's original location for the
  // full "why" — kept once here rather than duplicated to avoid the two
  // copies drifting.
  async function selectYearAndAwaitModels(page) {
    const yearSelect = page.locator('#year');
    const modelSelect = page.locator('#model');
    const modelOptions = page.locator('#model option');

    // Baseline the model dropdown. In update mode car-edit.js already fires
    // $('#year').val(year).trigger('change') at load (car-edit.js:339) and,
    // 500ms later, sets the saved model value — so #model may already carry
    // options for the car's own year by the time we get here. Recording the
    // pre-change option list lets the assertion below key off an observed
    // change rather than an assumed empty starting state.
    const optionsBeforeChange = await modelOptions.allTextContents();

    // Pick a year different from whatever is currently selected so the change
    // event genuinely fires — selectOption() with the already-selected value
    // does not dispatch `change` in jQuery's handler, which would make the
    // wait below hang on a repopulate that never started. 1973 and 1971 are
    // both real <option> values in edit.php's hardcoded year list
    // (edit.php:190-194), so either choice is always selectable.
    const currentYear = await yearSelect.inputValue();
    const targetYear = currentYear === '1973' ? '1971' : '1973';
    await yearSelect.selectOption(targetYear);

    // Wait for a REAL signal that the async repopulate finished, not a fixed
    // timeout. car-edit.js's handler is `$('#year').change(async function() {
    // ... await ModelLoader.populateModelDropdown(validYear, $('#model')); })`
    // (car-edit.js:383, :406), and populateModelDropdown() fetches
    // app/api/cars/models.php, strips every option but the placeholder, then
    // appends one <option> per model for the year (model-loader.js:73-97).
    // A `page.waitForTimeout()` guess here is exactly the mistake #2045
    // documents as blocking car-edit-owner-refresh.spec.js from Test/Prod —
    // it races the network and passes or fails on machine speed. Polling for
    // "more than just the placeholder option, and a different list than
    // before" observes the completed DOM write instead, so this is
    // deterministic on any tier whenever it is eventually enrolled.
    await expect.poll(
      async () => modelOptions.allTextContents(),
      {
        message:
          `Selecting year ${targetYear} must repopulate #model via ModelLoader.populateModelDropdown() ` +
          '— more than the "--Please Select Model--" placeholder, and a different list than before the change',
        timeout: 10000,
      }
    ).not.toEqual(optionsBeforeChange);

    const optionsAfterChange = await modelOptions.allTextContents();
    expect(
      optionsAfterChange.length,
      `#model must offer at least one real model for year ${targetYear} in addition to the placeholder`
    ).toBeGreaterThan(1);

    return { yearSelect, modelSelect, targetYear };
  }

  test('selecting a year repopulates the model dropdown on a real edit-mode page', async ({ page }) => {
    await openOwnedCarInEditMode(page);

    // The replaced test gated this field behind an accordion expand step
    // ('#heading-section2 button'). There is no accordion: #year sits in the
    // flat form body and is visible and enabled on load, with no reveal
    // interaction required. Asserting that directly is the regression guard
    // against the layout silently regressing back to a gated field.
    const yearSelectPreCheck = page.locator('#year');
    await expect(yearSelectPreCheck, '#year must be visible on load with no expand step').toBeVisible();
    await expect(yearSelectPreCheck, '#year must be interactive on load').toBeEnabled();

    const { modelSelect } = await selectYearAndAwaitModels(page);

    // populateModelDropdown() enables the dropdown only when the year yielded
    // at least one model (model-loader.js:93). Having just asserted the year
    // did yield models, #model must now be interactive — this catches a
    // repopulate that added options but left the control disabled, which
    // would look correct in the option list yet be unusable by an owner.
    await expect(
      modelSelect,
      '#model must be enabled once its year yields models'
    ).toBeEnabled();

    // Deliberately NO #submit click — see the file header. This test verifies
    // the year->model interaction only and leaves the car row untouched.
  });

  test('entering a chassis number triggers real validation on a real edit-mode page', async ({ page }) => {
    await openOwnedCarInEditMode(page);

    // car-edit.js's #chassis blur handler (car-edit.js:448-473) only calls
    // the real chassis-validate.php endpoint when BOTH a valid year and a
    // valid model are set (line 452: `if (!_chassis || !validYear ||
    // !validModel) { ...skip AJAX, call updateChassisUI(false, '')... }`) —
    // so, like the year->model test above, this must first drive a genuine
    // year selection and wait for the model to repopulate before touching
    // #chassis at all. The replaced test's unauthenticated "Add Car" run
    // started with an empty, unselected model, so its chassis assertion
    // below ran without ever satisfying this precondition — meaning it
    // could only ever observe the SKIP branch, not real
    // chassis-validate.php validation. Note the skip branch is not
    // invisible: updateChassisUI(false, '') sets fa-thumbs-down
    // unconditionally, so a naive "some thumbs class is present" check
    // cannot distinguish it from a real validation response — see the
    // waitForResponse() assertion below, which is what actually does.
    const { modelSelect } = await selectYearAndAwaitModels(page);

    // Select the first real model (index 0 is the "--Please Select
    // Model--" placeholder — see model-loader.js:78-82).
    await modelSelect.selectOption({ index: 1 });

    const chassisField = page.locator('#chassis');
    await expect(chassisField, '#chassis must be enabled once year and model are set').toBeEnabled();

    // A distinctive, syntactically plausible chassis number — this test
    // does not assert a specific valid/invalid outcome (whether THIS
    // number is taken is real registry data this test has no control
    // over), only that real validation genuinely ran against THIS input.
    //
    // Two things this assertion must NOT rely on alone:
    // - #chassis_icon's resulting class alone: the skip branch (taken when
    //   year/model aren't both set) calls updateChassisUI(false, '')
    //   unconditionally, which sets fa-thumbs-down just as validly as a
    //   real "invalid chassis" response would — a "some thumbs class is
    //   present" check can't tell the two apart.
    // - waitForResponse() matched on the endpoint URL alone: edit.php's own
    //   page-load sequence independently fires #chassis's blur handler
    //   twice on load (car-edit.js's isUpdate pre-population block, for the
    //   car's ALREADY-SAVED chassis value, and again from the #year change
    //   handler's re-validation) — either can produce an unrelated
    //   chassis-validate.php response that a URL-only matcher can't
    //   distinguish from this test's own request.
    //
    // So: match on the actual POST body containing THIS test's distinctive
    // chassis value (server receives it as FormData's `chassis` field —
    // see car-edit.js's ElanRegistryAPI.post() call), which only the
    // response to this exact request can satisfy.
    const chassisMarker = `TEST${Date.now()}`;
    const validateResponse = page.waitForResponse(
      async (response) => {
        if (!response.url().includes('app/api/cars/chassis-validate.php') || response.status() !== 200) {
          return false;
        }
        const postData = response.request().postData() || '';
        return postData.includes(chassisMarker);
      },
      { timeout: 10000 }
    );
    await chassisField.fill(chassisMarker);
    await chassisField.blur();
    await validateResponse;

    // Secondary check: the response was actually applied to the DOM.
    // updateChassisUI() (car-edit.js:481-507) toggles fa-thumbs-up XOR
    // fa-thumbs-down based on chassis-validate.php's response — assert
    // exactly one is present rather than either specific class, since which
    // one depends on live registry data this test doesn't control.
    const chassisIcon = page.locator('#chassis_icon');
    await expect
      .poll(
        async () => {
          const classes = (await chassisIcon.getAttribute('class')) || '';
          const hasUp = classes.includes('fa-thumbs-up');
          const hasDown = classes.includes('fa-thumbs-down');
          return hasUp !== hasDown ? 'settled' : 'unsettled';
        },
        {
          message: '#chassis_icon must settle to exactly one of fa-thumbs-up/fa-thumbs-down ' +
            'after chassis-validate.php responds',
          timeout: 10000,
        }
      )
      .toBe('settled');
  });
});
