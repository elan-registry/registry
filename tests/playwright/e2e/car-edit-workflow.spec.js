// Car edit year/model and chassis-validation flows in real UPDATE mode
// (#1949). Read-only: neither test clicks #submit.
//
// Local/Dev only for now; Test/Production enrollment is #2301.

const { test, expect } = require('@playwright/test');

test.describe('Car edit — year/model form workflow (#1949)', () => {
  // Skip outside the admin project. On Local/Dev without credentials,
  // auth.setup.js skips but `admin` still runs unauthenticated, so skip here
  // rather than report a false failure. E2E_AUTH_TIER tiers use storageState.
  test.beforeEach(async ({}, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
    const usesLiveLogin = !process.env.E2E_AUTH_TIER;
    if (usesLiveLogin && (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD)) {
      testInfo.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated locally');
    }
  });

  // Find an owned car from account.php, not a fixture id: ownership differs
  // per tier, and edit.php falls back to "Add Car" mode for an unowned id.
  async function openOwnedCarInEditMode(page) {
    await page.goto('usersc/account.php');
    await page.waitForLoadState('domcontentloaded');

    const updateCarButton = page.locator('button:has-text("Update Car"), a:has-text("Update Car")');
    const hasCarToUpdate = await updateCarButton.count() > 0;
    test.skip(!hasCarToUpdate, 'Logged-in account has no registered cars — nothing to edit');

    await updateCarButton.first().click();
    await page.waitForLoadState('domcontentloaded');

    const editedCarId = await page.locator('#car_id').inputValue();
    expect(editedCarId, 'Precondition: edit.php must render a car_id for the discovered car').not.toBe('');

    // Fail fast if edit.php fell back to Add Car mode: that is a different code path.
    const isUpdateMode = await page.evaluate(() => window.editCarConfig && window.editCarConfig.isUpdate);
    expect(
      isUpdateMode,
      'Precondition: edit.php must load in update mode (window.editCarConfig.isUpdate) — ' +
      `a false value means it fell back to Add Car mode for car ${editedCarId}`
    ).toBe(true);

    return editedCarId;
  }

  async function selectYearAndAwaitModels(page) {
    const yearSelect = page.locator('#year');
    const modelSelect = page.locator('#model');
    const modelOptions = page.locator('#model option');

    // car-edit.js repopulates #model for the saved year at load and keeps
    // #submit disabled until done (#2295). Wait for that, so the load-time
    // repopulation is not mistaken for the one this year change causes.
    await expect(modelSelect).not.toHaveValue('');
    await expect(page.locator('#submit')).toBeEnabled();

    const optionsBeforeChange = await modelOptions.allTextContents();

    // selectOption() with the current value fires no `change` event.
    const currentYear = await yearSelect.inputValue();
    const targetYear = currentYear === '1973' ? '1971' : '1973';
    await yearSelect.selectOption(targetYear);

    // Poll the DOM, not a fixed timeout: a blind wait raced the network (#2045).
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

    // Regression guard: #year was once gated behind an accordion.
    const yearSelectPreCheck = page.locator('#year');
    await expect(yearSelectPreCheck, '#year must be visible on load with no expand step').toBeVisible();
    await expect(yearSelectPreCheck, '#year must be interactive on load').toBeEnabled();

    const { modelSelect } = await selectYearAndAwaitModels(page);

    // Catches a repopulate that adds options but leaves the control disabled.
    await expect(
      modelSelect,
      '#model must be enabled once its year yields models'
    ).toBeEnabled();
  });

  test('entering a chassis number triggers real validation on a real edit-mode page', async ({ page }) => {
    await openOwnedCarInEditMode(page);

    // The blur handler calls chassis-validate.php only when year and model
    // are both set; otherwise it shows fa-thumbs-down without a request.
    const { modelSelect } = await selectYearAndAwaitModels(page);

    // Index 0 is the placeholder.
    await modelSelect.selectOption({ index: 1 });

    const chassisField = page.locator('#chassis');
    await expect(chassisField, '#chassis must be enabled once year and model are set').toBeEnabled();

    // Match on this test's own chassis value in the POST body: the icon class
    // cannot tell the skip branch from a real response, and the page fires
    // its own chassis-validate.php requests on load and on year change.
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

    // Exactly one thumbs class; which one depends on live registry data.
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
