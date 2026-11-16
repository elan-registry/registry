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
// See: app/admin/index.php?tab=owner-mgmt

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// Reads the score/count in the <h3> immediately following a summary card's
// h5.card-title — the same DOM relationship used by every stat card on this tab.
function nextStatValue(cardTitleLocator) {
    return cardTitleLocator.locator('xpath=following-sibling::h3[1]').textContent();
}

test.describe('Admin Manage Owners Tab', () => {
    let response;

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        response = await page.goto('app/admin/index.php?tab=owner-mgmt', { waitUntil: 'networkidle' });
    });

    test('page loads with the heading, owner search, and profile panel', async ({ page }) => {
        expect(response.status(), 'page returns 200').toBe(200);
        expect(await page.locator('body').textContent(), 'page has no fatal error').not.toContain('Fatal error');

        await expect.soft(page.getByRole('heading', { name: 'Manage Owners', level: 2 }), 'Manage Owners heading').toBeVisible();

        await expect.soft(page.locator('#ownerSearchInput'), 'owner search input').toBeVisible();
        await expect.soft(page.locator('#ownerSearchBtn'), 'owner search button').toBeVisible();
        await expect.soft(page.locator('#ownerClearBtn'), 'owner clear button').toBeVisible();
        await expect.soft(page.locator('#ownerSearchResults'), 'owner search results container').toBeAttached();

        await expect.soft(page.locator('#ownerProfilePanel'), 'owner profile panel').toBeAttached();
    });

    test('Data Health and owner data quality report cards render their values', async ({ page }) => {
        const dataHealthTitle = page.locator('h5.card-title', { hasText: 'Data Health' });
        await expect.soft(dataHealthTitle, 'Data Health card title').toBeAttached();
        const scoreText = await nextStatValue(dataHealthTitle);
        expect.soft(scoreText, 'Data Health quality score percentage').toMatch(/\d+(\.\d+)?%/);

        // Summary cards use h5.card-title; the same title text can also appear
        // in a detail-section h4 further down the page when count > 0, so
        // scope strictly to the h5 summary-card titles to avoid a strict-mode
        // violation matching both.
        const missingInfoTitle = page.locator('h5.card-title', { hasText: 'Car Owners Missing Information' });
        await expect.soft(missingInfoTitle, 'Missing Information card title').toBeAttached();
        const missingInfoCount = await nextStatValue(missingInfoTitle);
        expect.soft(missingInfoCount, 'Missing Information numeric count').toMatch(/\d+/);

        const duplicateEmailsTitle = page.locator('h5.card-title', { hasText: 'Duplicate Email Addresses' });
        await expect.soft(duplicateEmailsTitle, 'Duplicate Email Addresses card title').toBeAttached();
        const duplicateEmailsCount = await nextStatValue(duplicateEmailsTitle);
        expect.soft(duplicateEmailsCount, 'Duplicate Email Addresses numeric count').toMatch(/\d+/);
    });
});
