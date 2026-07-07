// tests/playwright/admin-unverified-account-cleanup.spec.js
//
// Behavioral tests for the Account Cleanup tab on the admin index page.
// Covers: threshold form, CSRF token, DataTables auto-load, confirmation modal.
//
// Requires local MAMP at http://localhost:9999/elan-registry
// See: app/admin/index.php?tab=account-cleanup

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const ADMIN_URL = 'http://localhost:9999/elan-registry/app/admin/index.php?tab=account-cleanup';

test.describe('Admin Account Cleanup Tab', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto(ADMIN_URL, { waitUntil: 'networkidle' });
    });

    test('page loads with threshold form and both table skeletons', async ({ page }) => {
        const acInput  = page.locator('input[name="ac_threshold"]');
        const acvInput = page.locator('input[name="acv_threshold"]');

        await expect(acInput).toBeVisible();
        await expect(acvInput).toBeVisible();

        await expect(acInput).toHaveValue('30');
        await expect(acvInput).toHaveValue('365');

        expect(await acInput.getAttribute('min')).toBe('30');
        expect(await acvInput.getAttribute('min')).toBe('1');

        await expect(page.locator('button[type="submit"]').filter({ hasText: 'Apply' })).toBeVisible();

        await expect(page.locator('#acuTable')).toBeAttached();
        await expect(page.locator('#acvTable')).toBeAttached();
    });

    test('CSRF tokens are present in delete forms', async ({ page }) => {
        // Two delete forms (acu + acv), each with a csrf hidden input
        const csrfInputs = page.locator('form[id$="DeleteForm"] input[name="csrf"]');

        await expect(csrfInputs).toHaveCount(2);

        for (const input of await csrfInputs.all()) {
            const value = await input.getAttribute('value');
            expect(value).toBeTruthy();
            expect(value.length).toBe(64);
            expect(value).toMatch(/^[0-9a-f]+$/);
        }
    });

    test('DataTables initializes without manual trigger', async ({ page }) => {
        // DataTables replaces the plain <table> — wait for the wrapper div
        await expect(page.locator('#acuTable_wrapper')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('#acvTable_wrapper')).toBeVisible({ timeout: 10000 });
    });

    test('confirmation modal structure and abort', async ({ page }) => {
        await page.evaluate(() => {
            const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
            modal.show();
        });

        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        await expect(page.locator('#confirmButton')).toBeVisible();
        await expect(page.locator('#confirmationModal .btn-secondary[data-bs-dismiss="modal"]')).toBeVisible();

        await page.locator('#confirmationModal .btn-secondary[data-bs-dismiss="modal"]').click();
        await expect(page.locator('#confirmationModal')).not.toBeVisible({ timeout: 3000 });
    });
});
