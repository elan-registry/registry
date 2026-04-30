// tests/playwright/admin-modal-confirmation.test.js
//
// Behavioral tests for the Bootstrap 5 #confirmationModal used on admin pages.
// Covers: modal DOM presence, Cancel/Confirm behavior, XSS prevention via
// textContent, and CSRF token availability for modal-triggered operations.
//
// Requires local MAMP at http://localhost:9999/elan_registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Area 1: manage-consolidated.php — modal DOM and car merge tab
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — manage-consolidated', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('/app/admin/manage-consolidated.php?tab=car-mgmt', { waitUntil: 'networkidle' });
    });

    test('confirmation modal element is present in DOM', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).toBeAttached();
        await expect(page.locator('#confirmTitle')).toBeAttached();
        await expect(page.locator('#confirmMessage')).toBeAttached();
        await expect(page.locator('#confirmButton')).toBeAttached();
    });

    test('CSRF token is present for modal-triggered forms', async ({ page }) => {
        const csrfInput = page.locator('input[name="csrf"]');
        await expect(csrfInput).toBeAttached();
        const value = await csrfInput.getAttribute('value');
        expect(value).toBeTruthy();
        expect(value.length).toBeGreaterThan(10);
    });

    test('modal is hidden on page load', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).not.toBeVisible();
    });

    test('no JS errors on page load', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                errors.push(msg.text());
            }
        });
        await page.reload({ waitUntil: 'networkidle' });
        const jsErrors = errors.filter(e => !e.includes('favicon') && !e.includes('404'));
        expect(jsErrors).toHaveLength(0);
    });
});

// ---------------------------------------------------------------------------
// Area 2: manage-maintenance.php — modal DOM and schema maintenance tab
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — manage-maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('/app/admin/manage-maintenance.php?tab=maintenance', { waitUntil: 'networkidle' });
    });

    test('confirmation modal element is present in DOM', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).toBeAttached();
    });

    test('CSRF token is present for schema maintenance', async ({ page }) => {
        const csrfInput = page.locator('input[name="csrf"]');
        await expect(csrfInput).toBeAttached();
        const value = await csrfInput.getAttribute('value');
        expect(value).toBeTruthy();
    });

    test('schema maintenance button triggers confirmation modal', async ({ page }) => {
        const maintenanceBtn = page.locator('button[onclick*="runSchemaMaintenance"]');
        const count = await maintenanceBtn.count();
        if (count === 0) {
            test.skip('Schema maintenance button not found — tab may not have loaded');
            return;
        }

        await maintenanceBtn.first().click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('#confirmTitle')).toContainText('Schema Maintenance');
    });

    test('Cancel button dismisses the modal without action', async ({ page }) => {
        const maintenanceBtn = page.locator('button[onclick*="runSchemaMaintenance"]');
        if (await maintenanceBtn.count() === 0) {
            test.skip('Schema maintenance button not found');
            return;
        }

        await maintenanceBtn.first().click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#confirmationModal .btn-secondary').click();
        await expect(page.locator('#confirmationModal')).not.toBeVisible({ timeout: 3000 });
        await expect(page.locator('#maintenance-result')).not.toBeAttached();
    });

    test('modal message rendered as plain text (XSS prevention)', async ({ page }) => {
        const maintenanceBtn = page.locator('button[onclick*="runSchemaMaintenance"]');
        if (await maintenanceBtn.count() === 0) {
            test.skip('Schema maintenance button not found');
            return;
        }

        await maintenanceBtn.first().click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        // Message must not contain rendered HTML — textContent only
        const msgEl = page.locator('#confirmMessage');
        const innerHTML = await msgEl.evaluate(el => el.innerHTML);
        // Should not contain HTML tags from interpolated content
        expect(innerHTML).not.toMatch(/<script/i);
        expect(innerHTML).not.toMatch(/<img/i);
        // The message body should be plain text, not HTML markup
        const textContent = await msgEl.textContent();
        expect(textContent.trim().length).toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// Area 3: manage-maintenance.php — input modal DOM (#inputModal)
// ---------------------------------------------------------------------------

test.describe('Admin input modal — manage-maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('/app/admin/manage-maintenance.php?tab=maintenance', { waitUntil: 'networkidle' });
    });

    test('input modal and all required child elements are present in DOM', async ({ page }) => {
        await expect(page.locator('#inputModal')).toBeAttached();
        await expect(page.locator('#inputModalTitle')).toBeAttached();
        await expect(page.locator('#inputModalMessage')).toBeAttached();
        await expect(page.locator('#inputModalValue')).toBeAttached();
        await expect(page.locator('#inputModalConfirm')).toBeAttached();
    });

    test('input modal is hidden on page load', async ({ page }) => {
        await expect(page.locator('#inputModal')).not.toBeVisible();
    });

    test('Create Manual Backup button opens input modal', async ({ page }) => {
        const backupBtn = page.locator('button[onclick*="createManualBackup"]');
        if (await backupBtn.count() === 0) {
            test.skip('Create Manual Backup button not found');
            return;
        }

        await backupBtn.first().click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('#inputModalTitle')).toContainText('Create Manual Backup');
        await expect(page.locator('#inputModalValue')).toHaveValue('Admin Panel Manual Backup');
    });

    test('Cancel dismisses the input modal without triggering backup', async ({ page }) => {
        const backupBtn = page.locator('button[onclick*="createManualBackup"]');
        if (await backupBtn.count() === 0) {
            test.skip('Create Manual Backup button not found');
            return;
        }

        await backupBtn.first().click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#inputModal .btn-secondary').click();
        await expect(page.locator('#inputModal')).not.toBeVisible({ timeout: 3000 });
    });
});
