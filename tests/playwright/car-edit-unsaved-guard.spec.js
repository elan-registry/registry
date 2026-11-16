// Unsaved-changes guard on the car form (#1455): a beforeunload prompt shows
// when the fields differ from the page-ready snapshot, or FilePond holds a new
// file. A completed save turns the guard off.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_WITH_HISTORY } = require('./fixtures.js');
const { TINY_PNG, SAVE_OK, attemptLeave, giveUserGesture, openAddMode, openEditMode, routeSave } = require('./car-edit-helpers.js');

test.describe('Car form unsaved-changes guard (#1455)', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('Add mode: a typed field change asks before leaving', async ({ page }) => {
        await openAddMode(page);
        await page.locator('#year').selectOption('1965');

        const result = await attemptLeave(page);
        expect(result.dialogs, 'one beforeunload dialog').toEqual(['beforeunload']);
        expect(result.closed, 'the dismissed dialog keeps the page open').toBe(false);
    });

    test('Add mode: a new FilePond file alone asks before leaving', async ({ page }) => {
        await openAddMode(page);
        await page.locator('input.filepond--browser').setInputFiles({ name: 'new.png', mimeType: 'image/png', buffer: TINY_PNG });
        await expect(page.locator('.filepond--item').first()).toBeVisible();
        expect(await page.locator('#year').inputValue(), 'no text field changed').toBe('');

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual(['beforeunload']);
    });

    test('Add mode: an untouched form leaves without a dialog', async ({ page }) => {
        await openAddMode(page);
        await giveUserGesture(page);
        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual([]);
        expect(result.closed).toBe(true);
    });

    test('Add mode: typing the original value back leaves without a dialog', async ({ page }) => {
        await openAddMode(page);
        await page.locator('#year').selectOption('1965');
        await page.locator('#year').selectOption('');
        await giveUserGesture(page);

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual([]);
        expect(result.closed).toBe(true);
    });

    test('Edit mode: a field change asks before leaving', async ({ page }) => {
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit')).toBeEnabled({ timeout: 15000 });
        await page.locator('#comments').fill('changed by the guard test');

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual(['beforeunload']);
    });

    test('Edit mode: no change after prepopulation leaves without a dialog', async ({ page }) => {
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit'), 'prepopulation must finish before the check').toBeEnabled({ timeout: 15000 });
        await giveUserGesture(page);

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual([]);
        expect(result.closed).toBe(true);
    });

    test('Edit mode: typing the original comments back leaves without a dialog', async ({ page }) => {
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit')).toBeEnabled({ timeout: 15000 });
        const original = await page.locator('#comments').inputValue();
        await page.locator('#comments').fill(original + ' extra');
        await page.locator('#comments').fill(original);
        await giveUserGesture(page);

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual([]);
        expect(result.closed).toBe(true);
    });

    test('Edit mode: a successful save goes to details.php without a dialog', async ({ page }) => {
        await routeSave(page, () => SAVE_OK);
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit')).toBeEnabled({ timeout: 15000 });
        await page.locator('#comments').fill('changed, then saved');

        const dialogs = [];
        page.on('dialog', (dialog) => {
            dialogs.push(dialog.type());
            dialog.accept();
        });
        await page.locator('#submit').click();

        await page.waitForURL(/app\/owner\/cars\/details\.php\?car_id=1091/, { timeout: 15000 });
        expect(dialogs, 'a completed save must not prompt').toEqual([]);
    });

    test('Edit mode: a failed save keeps the guard', async ({ page }) => {
        const saves = await routeSave(page, () => ({ status: 500, body: { success: false, message: 'boom' } }));
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit')).toBeEnabled({ timeout: 15000 });
        await page.locator('#comments').fill('changed, save fails');
        await page.locator('#submit').click();
        await expect.poll(() => saves.calls.length, 'the save must reach the mock').toBe(1);
        await expect(page.locator('#submit')).toBeEnabled();

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual(['beforeunload']);
    });

    test('Edit mode: text typed while models.php is slow still asks before leaving', async ({ page }) => {
        await page.route('**/app/api/cars/models.php', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.fallback();
        });
        await openEditMode(page, CAR_ID_WITH_HISTORY);
        await expect(page.locator('#submit'), 'the list is still loading').toBeDisabled();
        await page.locator('#comments').fill('typed while the model list loads');
        await expect(page.locator('#submit'), 'wait for the list').toBeEnabled({ timeout: 15000 });

        const result = await attemptLeave(page);
        expect(result.dialogs).toEqual(['beforeunload']);
    });
});
