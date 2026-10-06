// tests/playwright/car-badges.spec.js
//
// Coverage for the status badges (Sold, Verified, New) from issue #1900.
// CarBadges (PHP) picks the badge keys and draws them with CarBadges::html().
// The account page and details page call it directly. The cars list gets the
// HTML in the badges_html field of each list.php row (car-list.js).
//
// Seeds one loginable owner with four cars through
// tests/playwright/local/fixtures/seed-car-badges.php (see its header for what
// each car shows) plus 8 sold filler cars, so a search for the chassis marker
// gives 12 rows and a second page. The fixture needs the application database.
// The local stack is Docker only (DB_HOST=db does not resolve on the host), so
// the spec runs the fixture in the app container. It uses the host `php` only
// when the docker command is not installed.
//
// The list tests are public. The account test logs in as the seeded owner
// with the random password that the fixture prints.

const { test, expect } = require('@playwright/test');
const { login, waitForDataTables } = require('./auth-helper.js');
const { runPhpFixture } = require('./fixture-runner.js');

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-car-badges.php';
const CHASSIS_MARKER = 'PWBDG';

/**
 * Run the fixture and return its stdout.
 * @param {string[]} args Fixture arguments (for example ['--cleanup'])
 * @returns {string} The fixture stdout
 */
function runFixture(args = []) {
    return runPhpFixture(FIXTURE_REL, [...args]);
}

// The seed deletes and recreates the marker rows. Serial mode keeps one worker, so
// parallel workers do not seed and clean up over each other.
test.describe.configure({ mode: 'serial' });

let seeded;

test.beforeAll(() => {
    seeded = JSON.parse(runFixture().trim().split('\n').pop());
    expect(seeded.userId).toBeGreaterThan(0);
    expect(Object.keys(seeded.cars)).toHaveLength(4);
});

test.afterAll(() => {
    try {
        runFixture(['--cleanup']);
    } catch (error) {
        // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
        console.warn('car-badges cleanup failed:', error.message);
    }
});

/**
 * Open the public cars list and search for the seeded rows.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Locator>} The search box
 */
async function openSeededList(page) {
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });
    const searchBox = await waitForDataTables(page, 15000);
    await searchBox.fill(CHASSIS_MARKER);
    await expect(page.locator('#cartable tbody tr .er-badges').first()).toBeVisible();
    return searchBox;
}

/**
 * Get the list row for one seeded car, by exact chassis text.
 * @param {import('@playwright/test').Page} page
 * @param {string} suffix Chassis suffix after the marker (S, F, N, SN)
 */
function rowFor(page, suffix) {
    return page.locator('#cartable tbody tr').filter({
        has: page.locator('td', { hasText: new RegExp(`^${CHASSIS_MARKER}${suffix}$`) }),
    });
}

test.describe('Status badges: cars list', () => {
    test('sold row shows SOLD only', async ({ page }) => {
        await openSeededList(page);
        const badges = rowFor(page, 'S').locator('.er-badges .er-badge');
        await expect(badges).toHaveText(['Sold']);
        await expect(badges.first()).toHaveClass(/er-badge--sold/);
    });

    test('fresh row that is not NEW shows VERIFIED, with a checkmark', async ({ page }) => {
        await openSeededList(page);
        const row = rowFor(page, 'F');
        const badges = row.locator('.er-badges .er-badge');
        await expect(badges).toHaveCount(1);
        await expect(badges.first()).toHaveClass(/er-badge--verified/);
        await expect(badges.first()).toHaveText('✓ Verified');
        // The check mark is decorative: a screen reader reads only "Verified".
        const icon = badges.first().locator('span[aria-hidden="true"]');
        await expect(icon).toHaveCount(1);
        await expect(icon).toHaveText('✓');
        await expect(row.locator('.er-badge--new')).toHaveCount(0);
    });

    test('NEW row shows NEW and no VERIFIED', async ({ page }) => {
        await openSeededList(page);
        const row = rowFor(page, 'N');
        await expect(row.locator('td .er-badges .er-badge--new')).toHaveCount(1);
        await expect(row.locator('.er-badge')).toHaveCount(1);
        await expect(row.locator('.er-badge--verified')).toHaveCount(0);
    });

    test('sold and NEW row shows NEW then SOLD', async ({ page }) => {
        await openSeededList(page);
        const badges = rowFor(page, 'SN').locator('.er-badges .er-badge');
        await expect(badges).toHaveText(['New', 'Sold']);
        await expect(badges.nth(0)).toHaveClass(/er-badge--new/);
        await expect(badges.nth(1)).toHaveClass(/er-badge--sold/);
    });

    test('no badge is inside the Details link', async ({ page }) => {
        await openSeededList(page);
        // Non-vacuous: the seeded rows have a Details link and badges.
        await expect(page.locator('#cartable td a.btn')).not.toHaveCount(0);
        await expect(page.locator('#cartable .er-badge')).not.toHaveCount(0);
        await expect(page.locator('td a.btn .er-badge')).toHaveCount(0);
        await expect(page.locator('td a .er-badge')).toHaveCount(0);
        // The badges sit in the same cell, after the link.
        await expect(rowFor(page, 'S').locator('td:first-child > a.btn + .er-badges')).toHaveCount(1);
    });

    test('every badge has tooltip attributes and is keyboard focusable', async ({ page }) => {
        await openSeededList(page);
        const badges = page.locator('#cartable .er-badge');
        // sold 1, fresh 1, new 1, soldnew 2, filler 8 = 13 badges.
        await expect(badges).toHaveCount(13);

        const attributes = await badges.evaluateAll(els => els.map(el => ({
            toggle: el.getAttribute('data-bs-toggle'),
            tabindex: el.getAttribute('tabindex'),
            title: el.getAttribute('data-bs-title'),
        })));
        expect(attributes.length).toBeGreaterThan(0);
        for (const attribute of attributes) {
            expect(attribute.toggle).toBe('tooltip');
            expect(attribute.tabindex).toBe('0');
            expect((attribute.title || '').trim()).not.toBe('');
        }
    });

    test('focusing a badge shows its tooltip', async ({ page }) => {
        await openSeededList(page);
        const badge = rowFor(page, 'S').locator('.er-badge--sold');
        const title = await badge.getAttribute('data-bs-title');
        await badge.focus();
        const tooltip = page.locator('.tooltip.show');
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toContainText(title.slice(0, 20));
    });

    test('tooltips work after a page change', async ({ page }) => {
        await openSeededList(page);
        // The default page length is 15, so show 10 rows per page: 12 rows give 2 pages.
        await page.locator('.dt-length select, .dataTables_length select').selectOption('10');
        await expect(page.locator('#cartable tbody tr')).toHaveCount(10);
        await expect(page.locator('.dt-info, .dataTables_info')).toContainText('Showing 1 to 10 of 12');
        await page.locator('.dt-paging, .dataTables_paginate').getByRole('link', { name: 'Next', exact: true }).click();
        // Page 2 holds the last 2 rows: both are sold fillers.
        await expect(page.locator('#cartable tbody tr')).toHaveCount(2);
        const badge = page.locator('#cartable tbody .er-badge--sold').first();
        await expect(badge).toBeVisible();
        const title = await badge.getAttribute('data-bs-title');
        await badge.focus();
        await expect(page.locator('.tooltip.show')).toBeVisible();
        await expect(page.locator('.tooltip.show')).toContainText(title.slice(0, 20));
    });

    test('tooltips work after a search', async ({ page }) => {
        const searchBox = await openSeededList(page);
        // Narrow the search: this draw replaces every row.
        await searchBox.fill(`${CHASSIS_MARKER}F`);
        await expect(page.locator('#cartable tbody tr')).toHaveCount(1);
        const badge = page.locator('#cartable tbody .er-badge--verified');
        await expect(badge).toBeVisible();
        await badge.focus();
        const tooltip = page.locator('.tooltip.show');
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toHaveText("The owner confirmed, added, or updated this car's record in the last 12 months.");
    });
});

test.describe('Status badges: account page', () => {
    test('stamp is inside the car heading, and sold wins over verified', async ({ page }) => {
        await login(page, seeded.username, seeded.password);
        await page.goto('usersc/account.php', { waitUntil: 'networkidle' });

        const hero = year => page.locator('h3.card-header-er-primary-text', { hasText: `${year} Lotus Elan` });

        // Sold, stale data: Sold only.
        await expect(hero(1963).locator('.er-badge')).toHaveText(['Sold']);
        await expect(hero(1963).locator('.er-badge')).toHaveClass(/er-badge--stamp/);
        // Fresh, not sold: Verified. The account page has no NEW badge.
        await expect(hero(1964).locator('.er-badge')).toHaveText(['✓ Verified']);
        await expect(hero(1965).locator('.er-badge')).toHaveText(['✓ Verified']);
        // Sold and fresh: Sold only, no Verified (AC1, AC2).
        await expect(hero(1966).locator('.er-badge')).toHaveText(['Sold']);
        await expect(hero(1966).locator('.er-badge--verified')).toHaveCount(0);

        // The Verified checkmark is hidden from screen readers.
        await expect(hero(1964).locator('.er-badge span[aria-hidden="true"]')).toHaveText('✓');

        // Stamps have tooltip attributes too.
        const stampAttributes = await hero(1966).locator('.er-badge').first().evaluate(el => ({
            toggle: el.getAttribute('data-bs-toggle'),
            tabindex: el.getAttribute('tabindex'),
            title: el.getAttribute('data-bs-title'),
        }));
        expect(stampAttributes.toggle).toBe('tooltip');
        expect(stampAttributes.tabindex).toBe('0');
        expect((stampAttributes.title || '').trim()).not.toBe('');
    });
});

test.describe('Status badges: car details page', () => {
    test('Sold row is present for a sold car', async ({ page }) => {
        await page.goto(`app/owner/cars/details.php?car_id=${seeded.cars.sold}`, { waitUntil: 'networkidle' });
        const soldRow = page.locator('dt', { hasText: /^Sold$/ });
        await expect(soldRow).toHaveCount(1);
        await expect(soldRow.locator('xpath=following-sibling::dd[1]').locator('.er-badge--sold')).toHaveCount(1);
        // The old label is gone from the card. The history table keeps a "Sold Date" column header (th), not a dt.
        await expect(page.locator('dt', { hasText: 'Sold Date' })).toHaveCount(0);
    });

    test('Sold row is absent for a car that is not sold', async ({ page }) => {
        await page.goto(`app/owner/cars/details.php?car_id=${seeded.cars.fresh}`, { waitUntil: 'networkidle' });
        // Non-vacuous: the Purchase Date row proves the Ownership section rendered, so a
        // missing Sold row is the Sold logic and not a missing section. The section shows
        // for any car that is not sold (Verified row), with or without this date.
        await expect(page.locator('dt', { hasText: /^Purchase Date$/ })).toBeVisible();
        await expect(page.locator('dt', { hasText: /^Sold$/ })).toHaveCount(0);
    });
});
