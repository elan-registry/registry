// tests/playwright/car-verified-row.spec.js
//
// Coverage for the Verified row and the admin-only Email on file row in the
// Vehicle Information card (issue #1897). The card is app/views/cars/_vehicle_info_card.php.
// Three pages draw it: the car details page, the account page, and the
// vericode landing page (app/verify/verify_car.php).
//
// Seeds one loginable owner with five cars through
// `seed-car-badges.php --verified-row` (see the fixture header for what each
// car shows). The fixture has its own owner and chassis marker, so this spec
// can run in parallel with car-badges.spec.js. The fixture needs the
// application database. The local stack is Docker only, so the spec runs the
// fixture in the app container. It uses the host `php` only when the docker
// command is not installed.
//
// The admin tests log in with E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD
// from .env.local. They skip, with a reason, when these are not set.

const { test, expect } = require('@playwright/test');
const { login, isLoggedIn } = require('./auth-helper.js');
const { runPhpFixture } = require('./fixture-runner.js');

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-car-badges.php';
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December'];

const ADMIN_USERNAME = process.env.E2E_DEV_ADMIN_USERNAME;
const ADMIN_PASSWORD = process.env.E2E_DEV_ADMIN_PASSWORD;

/**
 * Run the fixture and return its stdout.
 * @param {string[]} args Fixture arguments (for example ['--cleanup'])
 * @returns {string} The fixture stdout
 */
function runFixture(args = []) {
    return runPhpFixture(FIXTURE_REL, ['--verified-row', ...args]);
}

/**
 * Format a `Y-m-d` string as the card does (`F j, Y`), for example "March 5, 2026".
 * @param {string} ymd Date from the fixture
 * @returns {string}
 */
function longDate(ymd) {
    const [year, month, day] = ymd.split('-').map(Number);
    return `${MONTHS[month - 1]} ${day}, ${year}`;
}

// The fixture deletes and recreates its marker rows. Serial mode keeps one worker for this file.
test.describe.configure({ mode: 'serial' });

let seeded;

test.beforeAll(() => {
    seeded = JSON.parse(runFixture().trim().split('\n').pop());
    expect(seeded.userId).toBeGreaterThan(0);
    expect(Object.keys(seeded.cars)).toHaveLength(5);
});

test.afterAll(() => {
    try {
        runFixture(['--cleanup']);
    } catch (error) {
        // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
        console.warn('car-verified-row cleanup failed:', error.message);
    }
});

const detailsUrl = carId => `app/owner/cars/details.php?car_id=${carId}`;

/**
 * The Verified row label of the Vehicle Information card.
 * @param {import('@playwright/test').Page} page
 */
const verifiedLabel = page => page.locator('dl.row > dt', { hasText: /^\s*Verified/ });

/**
 * The value cell that follows a row label.
 * @param {import('@playwright/test').Locator} label
 */
const valueOf = label => label.locator('xpath=following-sibling::dd[1]');

/**
 * The Email on file row label.
 * @param {import('@playwright/test').Page} page
 */
const emailLabel = page => page.locator('dl.row > dt', { hasText: /^\s*Email on file/ });

test.describe('Verified row: anonymous visitor on the details page', () => {
    // No storage state: this context has no session.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('fresh car with last_verified shows the stamp and "Last confirmed <date>"', async ({ page }) => {
        await page.goto(detailsUrl(seeded.cars.confirmed), { waitUntil: 'networkidle' });
        const label = verifiedLabel(page);
        await expect(label).toHaveCount(1);
        const value = valueOf(label);
        await expect(value.locator('.er-badge--verified')).toHaveCount(1);
        await expect(value).toContainText(`Last confirmed ${longDate(seeded.dates.confirmed)}`);
        await expect(value.locator('input')).toHaveCount(0);
    });

    test('fresh car with only owner_last_updated shows the stamp and "Current since <date>"', async ({ page }) => {
        await page.goto(detailsUrl(seeded.cars.current), { waitUntil: 'networkidle' });
        const label = verifiedLabel(page);
        await expect(label).toHaveCount(1);
        const value = valueOf(label);
        await expect(value.locator('.er-badge--verified')).toHaveCount(1);
        await expect(value).toContainText(`Current since ${longDate(seeded.dates.current)}`);
        await expect(value).not.toContainText('Last confirmed');
    });

    test('stale car shows "Not specified" and no stamp', async ({ page }) => {
        await page.goto(detailsUrl(seeded.cars.stale), { waitUntil: 'networkidle' });
        const label = verifiedLabel(page);
        await expect(label).toHaveCount(1);
        const value = valueOf(label);
        await expect(value.locator('em.text-muted')).toHaveText('Not specified');
        await expect(value.locator('i.fa-square')).toHaveCount(1);
        await expect(value.locator('.er-badge')).toHaveCount(0);
    });

    test('help button has a role and a name, takes focus through Tab, and shows a tooltip', async ({ page }) => {
        await page.goto(detailsUrl(seeded.cars.confirmed), { waitUntil: 'networkidle' });
        const helpButton = page.getByRole('button', { name: 'What Verified means' });
        await expect(helpButton).toHaveCount(1);
        await expect(helpButton).toBeVisible();
        await expect(page.getByRole('tooltip')).toHaveCount(0);

        // Tab from the top of the page until the help button has focus. The cap makes
        // a button that cannot take focus fail the test instead of looping.
        await page.locator('body').click({ position: { x: 1, y: 1 } });
        let focused = false;
        for (let i = 0; i < 150 && !focused; i++) {
            await page.keyboard.press('Tab');
            focused = await helpButton.evaluate(el => el === document.activeElement);
        }
        expect(focused, 'The Verified help button never took focus through Tab').toBe(true);

        // A role=tooltip element exists only if the footer initialized the Bootstrap tooltip.
        const tooltip = page.getByRole('tooltip');
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toHaveText("The owner confirmed, added, or updated this car's record in the last 12 months.");
    });

    test('suppressed car and bounced car show no Email on file row', async ({ page }) => {
        for (const key of ['suppressed', 'bounced']) {
            await page.goto(detailsUrl(seeded.cars[key]), { waitUntil: 'networkidle' });
            // Non-vacuous: the card rendered its Verified row for the same car.
            await expect(verifiedLabel(page)).toHaveCount(1);
            await expect(emailLabel(page)).toHaveCount(0);
            expect(await page.content()).not.toContain(seeded.bouncedAddress);
        }
    });
});

test.describe('Email on file row: owner who is not an admin', () => {
    test('own suppressed car and own bounced car show no Email on file row', async ({ page }) => {
        await login(page, seeded.username, seeded.password);
        expect(await isLoggedIn(page)).toBe(true);

        for (const key of ['suppressed', 'bounced']) {
            await page.goto(detailsUrl(seeded.cars[key]), { waitUntil: 'networkidle' });
            // Non-vacuous: the card rendered its Verified row for the same car.
            await expect(verifiedLabel(page)).toHaveCount(1);
            await expect(emailLabel(page)).toHaveCount(0);
            const html = await page.content();
            expect(html).not.toContain('Email on file');
            expect(html).not.toContain(seeded.bouncedAddress);
        }
    });
});

test.describe('Email on file row: admin', () => {
    test.skip(
        !ADMIN_USERNAME || !ADMIN_PASSWORD,
        'E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD are not set in .env.local'
    );

    test('suppressed car shows "Suppressed", and its tooltip names Clear Suppression', async ({ page }) => {
        await login(page, ADMIN_USERNAME, ADMIN_PASSWORD);
        await page.goto(detailsUrl(seeded.cars.suppressed), { waitUntil: 'networkidle' });
        const label = emailLabel(page);
        await expect(label).toHaveCount(1);
        const value = valueOf(label);
        await expect(value).toContainText('Suppressed');
        await expect(value).not.toContainText('Bounced');

        await page.getByRole('button', { name: 'What Suppressed means' }).focus();
        await expect(page.getByRole('tooltip')).toContainText('Clear Suppression on the Verification System tab.');
    });

    test('bounced car shows "Bounced" and never prints the bounced address', async ({ page }) => {
        await login(page, ADMIN_USERNAME, ADMIN_PASSWORD);
        await page.goto(detailsUrl(seeded.cars.bounced), { waitUntil: 'networkidle' });
        const label = emailLabel(page);
        await expect(label).toHaveCount(1);
        const value = valueOf(label);
        await expect(value).toContainText('Bounced');
        await expect(value).not.toContainText('Suppressed');
        expect(await page.content()).not.toContain(seeded.bouncedAddress);
    });

    test('car with no delivery problem shows no Email on file row', async ({ page }) => {
        await login(page, ADMIN_USERNAME, ADMIN_PASSWORD);
        await page.goto(detailsUrl(seeded.cars.confirmed), { waitUntil: 'networkidle' });
        // Non-vacuous: the Verified row is there, and the two tests above show the same admin session gets the row on the flagged cars.
        await expect(verifiedLabel(page)).toHaveCount(1);
        await expect(emailLabel(page)).toHaveCount(0);
    });
});

test.describe('Verified row: account page', () => {
    test('every car shows a Verified row, and no car shows an Email on file row', async ({ page }) => {
        await login(page, seeded.username, seeded.password);
        await page.goto('usersc/account.php', { waitUntil: 'networkidle' });

        // The owner has 5 cars, so each details section starts collapsed. Open the
        // suppressed car, which has a delivery flag, and read its card.
        const carId = seeded.cars.suppressed;
        const toggle = page.locator(`button[data-bs-target="#car-details-${carId}"]`);
        await expect(toggle).toHaveCount(1);
        await toggle.click();
        const details = page.locator(`#car-details-${carId}`);
        await expect(details).toBeVisible();

        const label = verifiedLabel(details);
        await expect(label).toBeVisible();
        await expect(valueOf(label).locator('.er-badge--verified')).toHaveCount(1);

        // One Verified row for each of the 5 cars. The page has no Email on file row.
        await expect(page.locator('dl.row > dt', { hasText: /^\s*Verified/ })).toHaveCount(5);
        await expect(emailLabel(page)).toHaveCount(0);
        expect(await page.content()).not.toContain('Email on file');
    });
});

test.describe('Verified row: vericode landing page', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('confirmed car shows the Verified row', async ({ page }) => {
        await page.goto(`app/verify/verify_car.php?vericode=${seeded.vericodes.confirmed}`, { waitUntil: 'networkidle' });
        const label = verifiedLabel(page);
        await expect(label).toHaveCount(1);
        await expect(valueOf(label)).toContainText(`Last confirmed ${longDate(seeded.dates.confirmed)}`);
        await expect(emailLabel(page)).toHaveCount(0);
    });

    test('suppressed car shows the Verified row and no Email on file row', async ({ page }) => {
        await page.goto(`app/verify/verify_car.php?vericode=${seeded.vericodes.suppressed}`, { waitUntil: 'networkidle' });
        // Non-vacuous: the landing page rendered the card for this car.
        const label = verifiedLabel(page);
        await expect(label).toHaveCount(1);
        await expect(valueOf(label).locator('.er-badge--verified')).toHaveCount(1);
        await expect(emailLabel(page)).toHaveCount(0);
        expect(await page.content()).not.toContain('Email on file');
    });
});
