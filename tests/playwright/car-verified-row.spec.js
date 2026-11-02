// Coverage for the Verified row and the admin-only Email on file row in the
// Vehicle Information card (issue #1897). The card is app/views/cars/_vehicle_info_card.php.
// Three pages draw it: the car details page, the account page, and the
// vericode landing page (app/verify/verify_car.php).
//
// Seeds three loginable owners with five cars through
// `seed-car-badges.php --verified-row` (see the fixture header for what each
// car shows). The delivery flags are on the owner profile (#2101), so the
// bounced car and the suppressed car each have their own flagged owner. The fixture has its own owner and chassis marker, so this spec
// can run in parallel with car-badges.spec.js. The fixture needs the
// application database. The local stack is Docker only, so the spec runs the
// fixture in the app container. It uses the host `php` only when the docker
// command is not installed.
//
// Two more cars show the Email on file states of #2361. They are under
// `stateCars` in the fixture output, with their owners under `stateOwners`,
// so the count of five cars stays the same: a blocked car, and a car whose owner
// has all three flags with every `_at` column NULL.
//
// The admin tests log in with E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD
// from .env.local. The editor tests use E2E_DEV_EDITOR_USERNAME and
// E2E_DEV_EDITOR_PASSWORD. Each group skips, with a reason, when its pair is not set.

const { test, expect } = require('@playwright/test');
const { login, isLoggedIn } = require('./auth-helper.js');
const { runPhpFixture } = require('./fixture-runner.js');

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-car-badges.php';
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December'];

const ADMIN_USERNAME = process.env.E2E_DEV_ADMIN_USERNAME;
const ADMIN_PASSWORD = process.env.E2E_DEV_ADMIN_PASSWORD;
const EDITOR_USERNAME = process.env.E2E_DEV_EDITOR_USERNAME;
const EDITOR_PASSWORD = process.env.E2E_DEV_EDITOR_PASSWORD;

// Flag dates that the fixture writes. They are literals, so the expected text
// does not come from the code under test.
const OPTED_OUT_DATE = 'April 1, 2026';
const BOUNCED_DATE = 'April 2, 2026';
const BLOCKED_DATE = 'April 3, 2026';

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
    expect(Object.keys(seeded.stateCars).sort()).toEqual(['blocked', 'nullDates']);
    expect(Object.keys(seeded.stateOwners).sort()).toEqual(['blocked', 'nullDates']);
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

    test('opted out, bounced, blocked, and all-flags cars show no Email on file row', async ({ page }) => {
        const carIds = [seeded.cars.suppressed, seeded.cars.bounced, seeded.stateCars.blocked, seeded.stateCars.nullDates];
        for (const carId of carIds) {
            await page.goto(detailsUrl(carId), { waitUntil: 'networkidle' });
            // Non-vacuous: the card rendered its Verified row for the same car.
            await expect(verifiedLabel(page)).toHaveCount(1);
            await expect(emailLabel(page)).toHaveCount(0);
            const html = await page.content();
            expect(html).not.toContain('Email on file');
            expect(html).not.toContain(seeded.bouncedAddress);
        }
    });
});

test.describe('Email on file row: owner who is not an admin', () => {
    test('own opted out, bounced, blocked, and all-flags cars show no Email on file row', async ({ page }) => {
        const cases = [
            [seeded.flaggedOwners.suppressed, seeded.cars.suppressed],
            [seeded.flaggedOwners.bounced, seeded.cars.bounced],
            [seeded.stateOwners.blocked, seeded.stateCars.blocked],
            [seeded.stateOwners.nullDates, seeded.stateCars.nullDates],
        ];
        for (const [ownerName, carId] of cases) {
            // Each flagged car has its own owner. All owners share one password.
            // Clear the session so the next login starts clean.
            await page.context().clearCookies();
            await login(page, ownerName, seeded.password);
            expect(await isLoggedIn(page)).toBe(true);
            await page.goto(detailsUrl(carId), { waitUntil: 'networkidle' });
            // Non-vacuous: the card rendered its Verified row for the same car.
            await expect(verifiedLabel(page)).toHaveCount(1);
            await expect(emailLabel(page)).toHaveCount(0);
            const html = await page.content();
            expect(html).not.toContain('Email on file');
            expect(html).not.toContain(seeded.bouncedAddress);
        }
    });
});

/**
 * Open a help button of the Email on file row and return its tooltip.
 * @param {import('@playwright/test').Page} page
 * @param {string} name Accessible name of the help button
 */
async function openTooltip(page, name) {
    await page.getByRole('button', { name }).focus();
    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();
    return tooltip;
}

/**
 * The span of one state in the Email on file value cell.
 * @param {import('@playwright/test').Locator} value
 * @param {string} state "Opted out", "Bounced" or "Blocked"
 */
const stateSpan = (value, state) => value.locator('span.me-3', { hasText: new RegExp(`^\\s*${state}\\b`) });

/**
 * Register the Email on file state tests for one privileged role. The tests
 * are the same for an admin and an editor, because the row gate is isRegistryAdmin().
 * @param {string} role Describe title suffix
 * @param {() => string|undefined} getUsername
 * @param {() => string|undefined} getPassword
 */
function emailOnFileStateTests(role, getUsername, getPassword) {
    test.describe(`Email on file row: ${role}`, () => {
        test.skip(
            !getUsername() || !getPassword(),
            `The ${role} credentials (E2E_DEV_${role.toUpperCase()}_USERNAME and E2E_DEV_${role.toUpperCase()}_PASSWORD) are not set in .env.local`
        );

        test.beforeEach(async ({ page }) => {
            await login(page, getUsername(), getPassword());
        });

        test('opted out car shows "Opted out" with its date, and its tooltip names Resume', async ({ page }) => {
            await page.goto(detailsUrl(seeded.cars.suppressed), { waitUntil: 'networkidle' });
            const label = emailLabel(page);
            await expect(label).toHaveCount(1);
            const value = valueOf(label);
            await expect(value.locator('span.me-3')).toHaveCount(1);
            await expect(stateSpan(value, 'Opted out')).toHaveText(`Opted out ${OPTED_OUT_DATE}`);
            await expect(value).not.toContainText('Suppressed');

            const tooltip = await openTooltip(page, 'What Opted out means');
            await expect(tooltip).toContainText('stopped all registry email except account email');
            await expect(tooltip).toContainText('Resume registry email');
            await expect(tooltip).not.toContainText('Clear Opt-out');
        });

        test('bounced car shows "Bounced" with its date, never prints the address, and its tooltip names the owner and admin mail', async ({ page }) => {
            await page.goto(detailsUrl(seeded.cars.bounced), { waitUntil: 'networkidle' });
            const label = emailLabel(page);
            await expect(label).toHaveCount(1);
            const value = valueOf(label);
            await expect(value.locator('span.me-3')).toHaveCount(1);
            await expect(stateSpan(value, 'Bounced')).toHaveText(`Bounced ${BOUNCED_DATE}`);
            await expect(value).not.toContainText('Suppressed');
            expect(await page.content()).not.toContain(seeded.bouncedAddress);

            const tooltip = await openTooltip(page, 'What Bounced means');
            await expect(tooltip).toContainText('messages from an admin');
            await expect(tooltip).toContainText('confirming a different email address');
            await expect(tooltip).not.toContainText('Resume registry email');
        });

        test('blocked car shows "Blocked" with its date, and its tooltip says an admin message is still sent', async ({ page }) => {
            await page.goto(detailsUrl(seeded.stateCars.blocked), { waitUntil: 'networkidle' });
            const label = emailLabel(page);
            await expect(label).toHaveCount(1);
            const value = valueOf(label);
            await expect(value.locator('span.me-3')).toHaveCount(1);
            await expect(stateSpan(value, 'Blocked')).toHaveText(`Blocked ${BLOCKED_DATE}`);
            await expect(value).not.toContainText('Suppressed');

            const tooltip = await openTooltip(page, 'What Blocked means');
            await expect(tooltip).toContainText('A message from an admin is still sent');
            await expect(tooltip).toContainText('confirming a different email address');
            await expect(tooltip).not.toContainText('Resume registry email');
            await expect(tooltip).not.toContainText('Clear Block');
        });

        test('owner with all three flags and no dates shows the three states in order, each with no date', async ({ page }) => {
            await page.goto(detailsUrl(seeded.stateCars.nullDates), { waitUntil: 'networkidle' });
            const label = emailLabel(page);
            await expect(label).toHaveCount(1);
            const value = valueOf(label);
            const spans = value.locator('span.me-3');
            await expect(spans).toHaveCount(3);
            // toHaveText on a locator list compares each span in document order.
            // The exact strings show that no date follows the state name.
            await expect(spans).toHaveText(['Opted out', 'Bounced', 'Blocked']);
            await expect(value).not.toContainText(/\d{4}/);
        });

        test('each state has its own tooltip text', async ({ page }) => {
            await page.goto(detailsUrl(seeded.stateCars.nullDates), { waitUntil: 'networkidle' });
            const texts = [];
            for (const name of ['What Opted out means', 'What Bounced means', 'What Blocked means']) {
                const button = page.getByRole('button', { name });
                await expect(button).toHaveCount(1);
                texts.push(await button.getAttribute('data-bs-title'));
            }
            expect(texts.every(text => text && text.length > 0)).toBe(true);
            expect(new Set(texts).size).toBe(3);
        });

        test('car with no delivery problem shows no Email on file row', async ({ page }) => {
            await page.goto(detailsUrl(seeded.cars.confirmed), { waitUntil: 'networkidle' });
            // Non-vacuous: the Verified row is there, and the tests above show the same session gets the row on the flagged cars.
            await expect(verifiedLabel(page)).toHaveCount(1);
            await expect(emailLabel(page)).toHaveCount(0);
        });
    });
}

emailOnFileStateTests('admin', () => ADMIN_USERNAME, () => ADMIN_PASSWORD);
emailOnFileStateTests('editor', () => EDITOR_USERNAME, () => EDITOR_PASSWORD);

test.describe('Verified row: account page', () => {
    test('every car of the owner shows a Verified row, and no car shows an Email on file row', async ({ page }) => {
        await login(page, seeded.username, seeded.password);
        await page.goto('usersc/account.php', { waitUntil: 'networkidle' });

        // The owner has 3 cars, so each details section starts collapsed. Open one
        // car and read its card.
        const carId = seeded.cars.current;
        const toggle = page.locator(`button[data-bs-target="#car-details-${carId}"]`);
        await expect(toggle).toHaveCount(1);
        await toggle.click();
        const details = page.locator(`#car-details-${carId}`);
        await expect(details).toBeVisible();

        const label = verifiedLabel(details);
        await expect(label).toBeVisible();
        await expect(valueOf(label).locator('.er-badge--verified')).toHaveCount(1);

        // One Verified row for each of the owner's 3 cars. The page has no Email on file row.
        await expect(page.locator('dl.row > dt', { hasText: /^\s*Verified/ })).toHaveCount(3);
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

    // #2495 is the first change that loads MapLibre on this page. The scripts
    // load inside the page body, and the inline map config script takes the
    // page's own CSP nonce, so only a browser shows a blocked script or a map
    // that does not start.
    test('car with a position shows a working Location map and no CSP violation', async ({ page }) => {
        const pageErrors = [];
        const cspMessages = [];
        page.on('pageerror', (err) => pageErrors.push(String(err)));
        page.on('console', (msg) => {
            const text = msg.text();
            if (text.includes('Content Security Policy') || text.includes('Refused to')) {
                cspMessages.push(text);
            }
        });

        await page.goto(`app/verify/verify_car.php?vericode=${seeded.vericodes.confirmed}`);

        await expect(page.locator('#map .maplibregl-canvas')).toBeAttached({ timeout: 15000 });
        // The marker is added only in map.on('load'), so it proves the map
        // started and did not fall back to "Map unavailable".
        await expect(page.locator('#map .elan-marker')).toBeVisible({ timeout: 15000 });
        await expect(page.getByText('Map unavailable')).toHaveCount(0);
        expect(cspMessages).toEqual([]);
        expect(pageErrors).toEqual([]);
    });

    test('car with no position shows the Location placeholder and loads no map', async ({ page }) => {
        await page.goto(`app/verify/verify_car.php?vericode=${seeded.vericodes.suppressed}`, { waitUntil: 'networkidle' });

        // Non-vacuous: the landing rendered the Location card for this car.
        await expect(page.getByText('Location information not available for this car.')).toBeVisible();
        await expect(page.locator('#map')).toHaveCount(0);
        expect(await page.evaluate(() => typeof window.maplibregl)).toBe('undefined');
    });
});
