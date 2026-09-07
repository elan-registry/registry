// tests/playwright/car-details-output-escaping.test.js
//
// Regression test for issue #840: missing htmlspecialchars() on car detail fields.
//
// Verifies that car field display areas in details.php and usersc/account.php
// render as plain text without raw HTML characters that would indicate unescaped
// output. These fields were "accidentally safe" before the encode-at-output reform
// because Input::sanitize() pre-encoded values at storage time.
//
// Requires local MAMP at http://localhost:9999/ElanRegistry/Registry

const { test, expect } = require('@playwright/test');
const {
    CAR_ID_WITH_HISTORY,
    CAR_ID_WITH_WEBSITE,
    CAR_ID_WITHOUT_OWNERSHIP_DATES,
    CAR_ID_WITH_OWNERSHIP_DATES,
} = require('./fixtures.js');

const DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITH_HISTORY}`;
const WEBSITE_DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITH_WEBSITE}`;
const NO_DATES_DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITHOUT_OWNERSHIP_DATES}`;
const WITH_DATES_DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITH_OWNERSHIP_DATES}`;

test.describe('Car details — output escaping (issue #840)', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(DETAILS_URL, { waitUntil: 'networkidle' });
    });

    test('page loads without XSS execution', async ({ page }) => {
        const dialogs = [];
        page.on('dialog', dialog => {
            dialogs.push(dialog.message());
            dialog.dismiss();
        });

        await page.goto(DETAILS_URL, { waitUntil: 'networkidle' });

        expect(dialogs).toHaveLength(0);
    });

    test('hero quick-facts fields do not contain raw HTML tag characters', async ({ page }) => {
        // .fw-bold.fs-5 divs inside the hero card hold chassis, color, engine, and registry ID
        const heroFields = page.locator('.card.bg-primary .fw-bold.fs-5');
        const count = await heroFields.count();
        expect(count).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            const text = await heroFields.nth(i).textContent();
            if (text && text.trim()) {
                expect(text).not.toMatch(/<script/i);
                expect(text).not.toMatch(/onerror=/i);
                expect(text).not.toContain('<img');
            }
        }
    });

    test('vehicle information dl/dd values do not contain injected markup', async ({ page }) => {
        const vehicleCard = page.locator('.registry-card').first();
        const ddElements = vehicleCard.locator('dd');
        const count = await ddElements.count();

        for (let i = 0; i < count; i++) {
            const text = await ddElements.nth(i).textContent();
            if (text && text.trim()) {
                expect(text).not.toMatch(/<script/i);
                expect(text).not.toMatch(/onerror=/i);
            }
        }
    });

    test('breadcrumb car title renders as plain text', async ({ page }) => {
        const breadcrumb = page.locator('.breadcrumb-item.active');
        await expect(breadcrumb).toBeVisible();

        const text = await breadcrumb.textContent();
        expect(text).not.toMatch(/<script/i);
        expect(text).not.toMatch(/onerror=/i);
    });

    test('website href does not contain unescaped quotes or javascript protocol', async ({ page }) => {
        // Website is owner-level (issue #1963) and now rendered in the Owner
        // Information card as a plain link (its own URL as link text), not the
        // "Visit Website" button that used to live in the vehicle info card's
        // Ownership & History section.
        //
        // Uses CAR_ID_WITH_WEBSITE rather than the describe block's default
        // CAR_ID_WITH_HISTORY: the latter has an empty owner website in the
        // test DB, which let this test previously pass having executed zero
        // real assertions in the loop below (count === 0). The
        // toBeGreaterThan(0) guard below turns that silent no-op into a
        // failure if the fixture ever again points at a car with no website.
        await page.goto(WEBSITE_DETAILS_URL, { waitUntil: 'networkidle' });

        const websiteLinks = page.locator('dl a[target="_blank"][rel="noopener noreferrer"]');
        const count = await websiteLinks.count();
        expect(count).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            const href = await websiteLinks.nth(i).getAttribute('href');
            if (href) {
                expect(href).not.toMatch(/javascript:/i);
                expect(href).not.toContain('"');
            }
        }
    });

    test('no injected script elements inside card bodies', async ({ page }) => {
        // Script tags inside .card-body would indicate XSS injection through car fields
        const injectedScripts = await page.locator('.registry-card .card-body script').count();
        expect(injectedScripts).toBe(0);
    });

});

test.describe('Car details — Ownership & History section visibility', () => {
    // Regression test for the empty-section bug fixed alongside issue #1963:
    // app/views/cars/_vehicle_info_card.php guards the entire "Ownership &
    // History" heading and its <dl> with `if ($purchaseDate || $soldDate)`.
    // Before that guard existed, a car with neither date still rendered the
    // heading with an empty body underneath it.

    test('heading is not shown for a car with no purchase date and no sold date', async ({ page }) => {
        await page.goto(NO_DATES_DETAILS_URL, { waitUntil: 'networkidle' });

        await expect(page.getByText('Ownership & History')).toHaveCount(0);
    });

    test('heading is shown for a car with a purchase or sold date', async ({ page }) => {
        await page.goto(WITH_DATES_DETAILS_URL, { waitUntil: 'networkidle' });

        await expect(page.getByText('Ownership & History')).toHaveCount(1);
    });
});
