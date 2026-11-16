// Model list failure on the car form (#1455): a models.php failure shows an
// inline error with Retry and blocks Model and Save. Retry loads the list.
// Edit mode (reselect of the saved model) is in car-edit-text-save.spec.js.
//
// The "phone width" describe runs the same flow at the iPhone SE viewport.
// The Mobile Chrome project matches only mobile-responsive.spec.js, so the
// viewport is set here and the chromium project runs both describes.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { SAVE_OK, openAddMode, routeSave, routeModels } = require('./car-edit-helpers.js');

function defineModelRetryTests() {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('Add mode: a models.php failure shows Retry, and Retry loads the model list', async ({ page }) => {
        const models = await routeModels(page);
        await openAddMode(page);

        await page.locator('#year').selectOption('1965');

        const error = page.getByTestId('model-load-error');
        await expect(error, 'the inline error must show').toBeVisible();
        await expect(page.getByTestId('model-load-retry')).toBeVisible();
        await expect(page.locator('#model'), 'Model stays disabled').toBeDisabled();
        await expect(page.locator('#submit'), 'Save stays disabled').toBeDisabled();

        models.fail = false;
        await page.getByTestId('model-load-retry').click();

        await expect(error, 'a good Retry hides the error').toBeHidden();
        await expect(page.locator('#model')).toBeEnabled();
        await expect(
            page.locator('#model option[value*="|"]').first(),
            'a real model option (value has "|"), not only the placeholder'
        ).toBeAttached();
    });

    test('Add mode: after Retry, the saved request carries the picked model', async ({ page }) => {
        const models = await routeModels(page);
        const saves = await routeSave(page, () => SAVE_OK);
        await openAddMode(page);

        await page.locator('#year').selectOption('1965');
        await expect(page.getByTestId('model-load-error')).toBeVisible();
        models.fail = false;
        await page.getByTestId('model-load-retry').click();
        await expect(page.getByTestId('model-load-error')).toBeHidden();
        await page.waitForFunction(
            () => Array.from(document.querySelectorAll('#model option')).some((o) => o.value.includes('|')),
            { timeout: 15000 }
        );

        const model = await page.evaluate(
            () => Array.from(document.querySelectorAll('#model option')).find((o) => o.value.includes('|')).value
        );
        await page.locator('#model').selectOption(model);
        await expect(page.locator('#submit'), 'a picked model after Retry must unblock Save').toBeEnabled();
        await page.locator('#submit').click();

        await expect.poll(() => saves.calls.length, 'the save must reach the mock').toBe(1);
        expect(saves.calls[0].model).toBe(model);
        expect(model, 'a real model value, not blank').toContain('|');
    });
}

test.describe('Car form model list retry (#1455)', defineModelRetryTests);

test.describe('Car form model list retry at phone width (#1455)', () => {
    test.use({ viewport: { width: 375, height: 667 } });
    defineModelRetryTests();
});
