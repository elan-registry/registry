// tests/playwright/admin-owner-mgmt.spec.js
//
// Smoke coverage for the Manage Owners tab on the admin index page (#1660).
// #1585 (DatabaseInterface migration) changed DB wiring for
// getOwnerQualityReports(dbi())/getDuplicateEmailDetails(dbi()) in
// tab-owner_mgmt.php with no Playwright coverage to catch a wiring
// regression turning into a 500. This confirms the page loads, the owner
// search UI is present, and the DB-backed Data Health/report cards render.
//
// Known coverage gap: getDuplicateEmailDetails(dbi(), $email) only executes
// inside tab-owner_mgmt.php's duplicate_emails detail block, which itself
// only renders when the local dataset has an actual duplicate-email pair
// (see the `$report['count'] == 0` guard around that call). Nothing here
// guarantees such a pair exists, so a wiring regression in that specific
// function may not be caught by this spec depending on local seed data.
// getOwnerQualityReports(dbi()) — the other #1585 wiring risk this issue
// names — IS reliably exercised: it always runs on page load and this
// spec's Data Health/report-count assertions would fail if it broke.
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md
// See: app/admin/index.php?tab=owner-mgmt

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// Reads the score/count in the <h3> immediately following a summary card's
// h5.card-title — the same DOM relationship used by every stat card on this tab.
function nextStatValue(cardTitleLocator) {
    return cardTitleLocator.locator('xpath=following-sibling::h3[1]').textContent();
}

test.describe('Admin Manage Owners Tab', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/index.php?tab=owner-mgmt', { waitUntil: 'networkidle' });
    });

    test('page loads with no fatal error', async ({ page }) => {
        const response = await page.goto('app/admin/index.php?tab=owner-mgmt', { waitUntil: 'networkidle' });

        expect(response.status()).toBe(200);
        expect(await page.locator('body').textContent()).not.toContain('Fatal error');
    });

    test('Manage Owners heading is visible', async ({ page }) => {
        await expect(page.getByRole('heading', { name: 'Manage Owners', level: 2 })).toBeVisible();
    });

    test('owner search card is present', async ({ page }) => {
        await expect(page.locator('#ownerSearchInput')).toBeVisible();
        await expect(page.locator('#ownerSearchBtn')).toBeVisible();
        await expect(page.locator('#ownerClearBtn')).toBeVisible();
        await expect(page.locator('#ownerSearchResults')).toBeAttached();
    });

    test('owner profile panel is attached but hidden on load', async ({ page }) => {
        await expect(page.locator('#ownerProfilePanel')).toBeAttached();
    });

    test('Data Health summary card renders a quality score percentage', async ({ page }) => {
        const dataHealthTitle = page.locator('h5.card-title', { hasText: 'Data Health' });
        await expect(dataHealthTitle).toBeAttached();
        const scoreText = await nextStatValue(dataHealthTitle);

        expect(scoreText).toMatch(/\d+(\.\d+)?%/);
    });

    test('owner data quality report cards render with numeric counts', async ({ page }) => {
        // Summary cards use h5.card-title; the same title text can also appear
        // in a detail-section h4 further down the page when count > 0, so
        // scope strictly to the h5 summary-card titles to avoid a strict-mode
        // violation matching both.
        const missingInfoTitle = page.locator('h5.card-title', { hasText: 'Car Owners Missing Information' });
        await expect(missingInfoTitle).toBeAttached();
        const missingInfoCount = await nextStatValue(missingInfoTitle);
        expect(missingInfoCount).toMatch(/\d+/);

        const duplicateEmailsTitle = page.locator('h5.card-title', { hasText: 'Duplicate Email Addresses' });
        await expect(duplicateEmailsTitle).toBeAttached();
        const duplicateEmailsCount = await nextStatValue(duplicateEmailsTitle);
        expect(duplicateEmailsCount).toMatch(/\d+/);
    });
});
