// Regression test for issue #840: missing htmlspecialchars() on car detail fields.
//
// Seeds a car whose color, engine, and chassis hold HTML payloads, then checks
// that details.php shows each payload as text and creates no element from it.
// The fields were "accidentally safe" before the encode-at-output reform
// because Input::sanitize() pre-encoded values at storage time.
//
// The seed owner's website holds a quote-break payload, to check the escaping
// of the website href.

const { test, expect } = require('@playwright/test');
const BASE_URL = require('./base-url.js');
const { runPhpFixture } = require('./fixture-runner.js');
const {
    CAR_ID_WITHOUT_OWNERSHIP_DATES,
    CAR_ID_WITH_OWNERSHIP_DATES,
} = require('./fixtures.js');

const NO_DATES_DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITHOUT_OWNERSHIP_DATES}`;
const WITH_DATES_DETAILS_URL = `app/owner/cars/details.php?car_id=${CAR_ID_WITH_OWNERSHIP_DATES}`;

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-output-escaping-car.php';
// Must match the payloads in the seed fixture.
const PAYLOADS = {
    color: '<script>alert(1)</script>',
    engine: '<img onerror=1>',
    chassis: '<i onerror=1>',
    website: 'https://x.test/"onmouseover="alert(1)',
};
const isRemoteBase = !/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(BASE_URL);

test.describe('Car details — output escaping of seeded HTML payloads (issue #840)', () => {
    test.skip(isRemoteBase, 'The seed fixture writes to the local Docker database, so the base URL must be the local site');

    // One worker: one seed for all tests, and the afterAll cleanup runs once.
    test.describe.configure({ mode: 'serial' });

    let seeded;

    test.beforeAll(() => {
        seeded = JSON.parse(runPhpFixture(FIXTURE_REL).trim().split('\n').pop());
        expect(seeded.userId).toBeGreaterThan(0);
        expect(seeded.carId).toBeGreaterThan(0);
    });

    test.afterAll(() => {
        try {
            runPhpFixture(FIXTURE_REL, ['--cleanup']);
        } catch (error) {
            // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
            console.warn('output-escaping cleanup failed:', error.message);
        }
    });

    test('details page shows the payloads as text and creates no element from them', async ({ page }) => {
        const dialogs = [];
        page.on('dialog', dialog => {
            dialogs.push(dialog.message());
            dialog.dismiss();
        });

        await page.goto(`app/owner/cars/details.php?car_id=${seeded.carId}`, { waitUntil: 'networkidle' });

        // Positive control: the page shows the seeded car, not an error or another car.
        const hero = page.locator('.card.bg-primary');
        await expect(hero.locator('.fw-bold.fs-5').first()).toHaveText(String(seeded.carId));
        await expect(page.locator('.breadcrumb-item.active')).toContainText('1968 S4');

        // The card scope leaves out the JSON-LD script in the page head area.
        const cards = page.locator('.registry-card');
        await expect(cards.locator('script')).toHaveCount(0);
        await expect(cards.locator('[onerror]')).toHaveCount(0);

        // Raw output would turn each payload into an element, so its literal
        // text would not appear.
        await expect(hero).toContainText(PAYLOADS.color);
        await expect(hero).toContainText(PAYLOADS.engine);
        await expect(hero).toContainText(PAYLOADS.chassis);

        expect(dialogs).toHaveLength(0);
    });

    test('website href keeps the quote-break payload inside the attribute', async ({ page }) => {
        // Website is owner-level (issue #1963). The Owner Information card
        // renders it as a link with its own URL as the link text. The payload
        // has an https scheme, so OwnerView::websiteUrl() keeps it, and only
        // the href escaping stops the attribute breakout. Unit tests in
        // OwnerViewTest cover the javascript: scheme filter.
        await page.goto(`app/owner/cars/details.php?car_id=${seeded.carId}`, { waitUntil: 'networkidle' });

        const ownerCard = page.locator('.registry-card', { hasText: 'Owner Information' });
        // Positive control: the owner card and its website link rendered.
        await expect(ownerCard).toHaveCount(1);
        const websiteLink = ownerCard.locator('dl a[target="_blank"][rel="noopener noreferrer"]');
        await expect(websiteLink).toHaveCount(1);

        // Escaped output decodes back to the full payload. Raw output ends the
        // href at the first quote and adds an onmouseover attribute.
        await expect(websiteLink).toHaveAttribute('href', PAYLOADS.website);
        await expect(ownerCard.locator('[onmouseover]')).toHaveCount(0);
    });
});

test.describe('Car details — Ownership & History section visibility', () => {
    // Regression test for the empty-section bug fixed alongside issue #1963:
    // the "Ownership & History" heading must never render over an empty <dl>.
    // Issue #1897 added the Verified row, which every car that is not sold
    // has. So a car with no purchase date and no sold date now shows the
    // section, with the Verified row as its only content. The section is
    // hidden only when it would be empty, and that case cannot happen on a
    // real record: a car with no sold date is not sold, so it has a Verified row.

    test('heading is shown for a car with no purchase date and no sold date, with only the Verified row', async ({ page }) => {
        await page.goto(NO_DATES_DETAILS_URL, { waitUntil: 'networkidle' });

        await expect(page.getByText('Ownership & History')).toHaveCount(1);
        // The section is not empty: the Verified row is its content.
        await expect(page.locator('dl.row > dt', { hasText: /^\s*Verified/ })).toHaveCount(1);
        // The dates this car does not have do not draw rows.
        await expect(page.locator('dl.row > dt', { hasText: /^Purchase Date$/ })).toHaveCount(0);
        await expect(page.locator('dl.row > dt', { hasText: /^Sold$/ })).toHaveCount(0);
    });

    test('heading is shown for a car with a purchase or sold date', async ({ page }) => {
        await page.goto(WITH_DATES_DETAILS_URL, { waitUntil: 'networkidle' });

        await expect(page.getByText('Ownership & History')).toHaveCount(1);
    });
});
