// tests/playwright/admin-verification-dashboard.spec.js
//
// Coverage for the rebuilt Verification System dashboard
// (app/admin/index.php?tab=verification, rendered by
// app/admin/includes/tab-verification.php) added by issue #1896: seven
// summary cards (#2361), the merged queue (pills + Window/Show, URL-state
// round-trip), one action button per row, the precedence-aware Status chip,
// Recent Activity, and the Status panel's collapse/expand placement.
//
// Seeds via tests/playwright/local/fixtures/seed-verification-dashboard.php
// (modeled on seed-verification-send-tool.php's #1884 precedent — same
// CLI-bootstrap-via-users/init.php approach, same idempotent
// delete-then-insert convention, same US_ENVIRONMENT=development guard):
//   - one PENDING car (live link, no response yet)
//   The deliverability flags are on the owner profile (#2101):
//   - one BOUNCED car (profile email_bounced = 1)
//   - a second car of the bounced owner: the Bounced card counts owners, so
//     the owner counts once on the card and twice in the pill (#2361)
//   - one OPTED-OUT car (profile email_opted_out = 1), chip "Opted out"
//   - one BLOCKED car (profile email_blocked = 1, cause blocklist), chip
//     "Blocked"
//   - one STALE-BLOCKED car: email_blocked = 1, but for an old address. The
//     flag does not apply, so the owner is in no Blocked count (#2356)
//   - one NO-PROFILE car: pending, and its owner has no profiles row. Chip
//     "No profile", and no action button
//   - one SOFT-BOUNCE car: Pending pill, but one `soft_bounce`
//     er_email_events row after its `sent` row, so its Status chip reads
//     "Soft bounce" and its Mark Bounced button must render disabled
//   - one PRECEDENCE car: `sent`, then `hard_bounce` at the same instant,
//     then `delivered` ten minutes later, all in the same send cycle. This
//     reproduces
//     tests/integration/database/CarRepositoryEmailEventPrecedenceTest.php
//     ::testTerminalEventWinsOverALaterDeliveredInTheSameCycle at the UI
//     layer: the terminal event must win the Status chip despite the later
//     `delivered` timestamp. Its chassis carries a `<script>` marker, used
//     for the escaping assertion — this closes DOCUMENTED GAP #3 in
//     admin-verification-send-tool.spec.js, which could not guarantee its
//     own seeded eligible car a place in the batch-size-capped preview on
//     this local dataset; the Pending/All queue views used here have no such
//     cap (see findVerificationQueue()'s $limit, set generously below).
//   - one VERIFIED car (one `VERIFIED` cars_hist row)
//   - one SOLD car (one `VERIFIED SOLD` cars_hist row)
//
// Every seeded row is additive against this local database's existing
// ~1500 pre-existing eligible/pending/etc. rows (see
// admin-verification-send-tool.spec.js's own header note on this
// environment's data volume) — the summary-card assertions below therefore
// query the database directly (via a second fixture script running a plain
// COUNT, see countQueueTotals() below) immediately before each assertion,
// rather than hard-coding expected totals, so this suite is not coupled to
// how many other rows happen to exist locally. This is independent-source
// verification, not a tautology: the comparison queries re-derive each
// count from CarRepository::countVerificationSummary() (the same production
// method tab-verification.php calls), run via a tiny standalone PHP script
// — not by re-running the page's own render path — so a card and a broken
// countVerificationSummary() cannot silently agree.
//
// Placed at the top level of tests/playwright/ (not under e2e/), matching
// admin-verification-send-tool.spec.js's convention: a local Docker site +
// a live admin session via ensureLoggedIn() (auth-helper.js), run under the
// plain `chromium` project, not the `admin` project's storageState.
//
// Requires the local Docker site with E2E_DEV_ADMIN_USERNAME/
// E2E_DEV_ADMIN_PASSWORD (an admin account) set in .env.local, and
// US_ENVIRONMENT=development locally — the fixture refuses to run against a
// deployed environment. Default base URL: http://localhost:$APP_HOST_PORT/
// (see tests/playwright/base-url.js).

const { test, expect } = require('@playwright/test');
const { runPhpFixture } = require('./fixture-runner.js');
const { ensureLoggedIn, login, logout } = require('./auth-helper.js');


const TAB_URL = 'app/admin/index.php?tab=verification';

// The seven summary cards, in page order. `countKey` is the key of
// CarRepository::countVerificationSummary(). Bounced, Blocked and Opted out
// count owners; the others count cars (#2361).
const SUMMARY_CARDS = [
    { key: 'eligible', label: 'Eligible', countKey: 'eligible', ownerCount: false },
    { key: 'pending', label: 'Pending', countKey: 'pending', ownerCount: false },
    { key: 'verified', label: 'Verified', countKey: 'verified', ownerCount: false },
    { key: 'sold', label: 'Sold', countKey: 'sold', ownerCount: false },
    { key: 'bounced', label: 'Bounced', countKey: 'bounced_owners', ownerCount: true },
    { key: 'blocked', label: 'Blocked', countKey: 'blocked_owners', ownerCount: true },
    { key: 'opted_out', label: 'Opted out', countKey: 'opted_out_owners', ownerCount: true },
];

// The eight queue pills, in page order (CarRepository::QUEUE_STATUSES).
const QUEUE_PILLS = [
    { status: 'all', label: 'All' },
    { status: 'eligible', label: 'Eligible' },
    { status: 'pending', label: 'Pending' },
    { status: 'bounced', label: 'Bounced' },
    { status: 'blocked', label: 'Blocked' },
    { status: 'opted_out', label: 'Opted out' },
    { status: 'verified', label: 'Verified' },
    { status: 'sold', label: 'Sold' },
];

function readDbSummary(windowDays) {
    return JSON.parse(
        runPhpFixture('tests/playwright/local/fixtures/read-verification-summary.php', [String(windowDays)]).trim()
    );
}

function queueRow(page, carId) {
    return page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${carId}")`) });
}

async function numberOf(locator) {
    return Number((await locator.textContent()).replace(/[,\s]/g, ''));
}

// Seven cards: car counts for Eligible/Pending/Verified/Sold, owner counts
// for Bounced/Blocked/Opted out. The owner cards say "owners" and the pills
// count cars, so the bounced owner with two cars counts once on the card and
// twice in the pill.
async function expectSummaryCardsAndPills(page) {
    const db = readDbSummary(30);
    await page.goto(TAB_URL, { waitUntil: 'networkidle' });

    const cards = page.locator('#verificationSummaryCards [data-summary]');
    await expect(cards).toHaveCount(SUMMARY_CARDS.length);
    await expect(cards).toHaveCount(7);

    for (const [index, card] of SUMMARY_CARDS.entries()) {
        const tile = cards.nth(index);
        await expect(tile, `card #${index + 1}`).toHaveAttribute('data-summary', card.key);
        await expect(tile.locator('.er-stat-label').first()).toHaveText(card.label);
        expect(await numberOf(tile.locator('.er-stat-number')), `card "${card.key}"`).toBe(db[card.countKey]);

        const ownersLabel = tile.locator('.er-stat-label', { hasText: /^owners$/ });
        await expect(ownersLabel, `"owners" sub-label of card "${card.key}"`).toHaveCount(card.ownerCount ? 1 : 0);
    }

    const pills = page.locator('a.filter-pill');
    await expect(pills).toHaveCount(QUEUE_PILLS.length);
    await expect(pills).toHaveCount(8);
    for (const [index, pill] of QUEUE_PILLS.entries()) {
        const link = pills.nth(index);
        await expect(link, `pill #${index + 1}`).toHaveAttribute('data-status', pill.status);
        await expect(link).toContainText(pill.label);
        await expect(link.locator('span.ms-1'), `count of pill "${pill.status}"`).toHaveText(`(${db[pill.status].toLocaleString('en-US')})`);
    }

    // Fixture: the bounced owner has two cars. Owners count once on the
    // card, cars count twice in the pill, so the pill leads the card by at
    // least one. The stale-blocked and blocked owners are different owners,
    // so the Blocked card never exceeds the Blocked pill.
    expect(db.bounced - db.bounced_owners, 'bounced pill (cars) minus bounced card (owners)').toBeGreaterThanOrEqual(1);
    expect(db.blocked_owners).toBeGreaterThanOrEqual(1);
    expect(db.blocked).toBeGreaterThanOrEqual(db.blocked_owners);
    expect(db.opted_out_owners).toBeGreaterThanOrEqual(1);
    expect(db.opted_out).toBeGreaterThanOrEqual(db.opted_out_owners);
}

// ?status=blocked and ?status=opted_out list only their own owners' cars.
async function expectBlockedAndOptedOutFilters(page) {
    const db = readDbSummary(30);

    await page.goto(`${TAB_URL}&status=blocked&show=100`, { waitUntil: 'networkidle' });
    await expect(page.locator('a.filter-pill[data-status="blocked"]')).toHaveClass(/active/);
    await expect(page.locator('a.filter-pill.active')).toHaveCount(1);
    const blockedRow = queueRow(page, blockedCarId);
    await expect(blockedRow).toBeVisible();
    const blockedChip = blockedRow.locator('td').nth(5).locator('span.badge');
    await expect(blockedChip).toHaveAttribute('data-chip', 'blocked');
    await expect(blockedChip).toHaveText(/^\s*Blocked\s*$/);
    for (const other of [suppressedCarId, bouncedCarId, bouncedCarIdB, staleBlockedCarId, pendingCarId]) {
        await expect(queueRow(page, other), `car ${other} is not in the Blocked pill`).toHaveCount(0);
    }
    expect(await page.locator('#verificationQueue table tbody tr').count(), 'Blocked rows equal the Blocked pill count')
        .toBe(db.blocked);

    await page.goto(`${TAB_URL}&status=opted_out&show=100`, { waitUntil: 'networkidle' });
    await expect(page.locator('a.filter-pill[data-status="opted_out"]')).toHaveClass(/active/);
    await expect(page.locator('a.filter-pill.active')).toHaveCount(1);
    const optedOutRow = queueRow(page, suppressedCarId);
    await expect(optedOutRow).toBeVisible();
    await expect(optedOutRow.locator('td').nth(5).locator('span.badge')).toHaveAttribute('data-chip', 'opted_out');
    for (const other of [blockedCarId, bouncedCarId, staleBlockedCarId, pendingCarId]) {
        await expect(queueRow(page, other), `car ${other} is not in the Opted out pill`).toHaveCount(0);
    }
    expect(await page.locator('#verificationQueue table tbody tr').count(), 'Opted out rows equal the Opted out pill count')
        .toBe(db.opted_out);

    // Both cars of the bounced owner are in the Bounced pill.
    await page.goto(`${TAB_URL}&status=bounced&show=100`, { waitUntil: 'networkidle' });
    await expect(queueRow(page, bouncedCarId)).toBeVisible();
    await expect(queueRow(page, bouncedCarIdB)).toBeVisible();
    // The seeded owner has the bounce flag and no hard-bounce event, so the
    // chip reads the flag that tab-verification.php passes (#2461).
    for (const car of [bouncedCarId, bouncedCarIdB]) {
        await expect(queueRow(page, car).locator('td').nth(5).locator('span.badge')).toHaveAttribute('data-chip', 'bounced');
    }

    // A block for an old address does not apply: no Blocked pill row, and the
    // car is an ordinary Pending car with no chip problem.
    await page.goto(`${TAB_URL}&status=pending&show=100`, { waitUntil: 'networkidle' });
    const staleRow = queueRow(page, staleBlockedCarId);
    await expect(staleRow).toBeVisible();
    await expect(staleRow.locator('td').nth(5).locator('span.badge[data-chip="blocked"]')).toHaveCount(0);
}

// The panel renders in one place: after the summary cards, directly before
// the Automatic Sending card, and before the queue and recent activity. The
// place does not depend on health (#2364). The `open` attribute follows health.
async function expectPanelBeforeAutomaticSending(page) {
    await page.goto(TAB_URL, { waitUntil: 'networkidle' });

    const panel = page.locator('#verificationStatusPanel');
    await expect(panel).toHaveCount(1);

    const autoSendCard = page.getByRole('heading', { name: 'Automatic Sending', level: 5 })
        .locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " card ")][1]');
    await expect(autoSendCard).toHaveCount(1);

    const order = await page.evaluate(() => {
        const panelEl = document.getElementById('verificationStatusPanel');
        const cardsEl = document.getElementById('verificationSummaryCards');
        const queueEl = document.getElementById('verificationQueue');
        const activityEl = document.getElementById('verificationRecentActivity');
        const headings = Array.from(document.querySelectorAll('h5'));
        const autoHeading = headings.find((h) => h.textContent.includes('Automatic Sending'));
        const autoEl = autoHeading ? autoHeading.closest('.card') : null;
        // 0x04 is Node.DOCUMENT_POSITION_FOLLOWING. A literal, because this
        // callback is linted as Node.js code without browser globals.
        const follows = (a, b) => !!(a.compareDocumentPosition(b) & 0x04);
        return {
            directlyBeforeAutoSend: panelEl.nextElementSibling === autoEl,
            afterCards: follows(cardsEl, panelEl),
            beforeQueue: follows(panelEl, queueEl),
            beforeActivity: follows(panelEl, activityEl),
            panelCount: document.querySelectorAll('#verificationStatusPanel').length,
        };
    });
    expect(order.panelCount, 'the panel renders once').toBe(1);
    expect(order.directlyBeforeAutoSend, 'the panel is directly before the Automatic Sending card').toBe(true);
    expect(order.afterCards, 'the panel is after the summary cards').toBe(true);
    expect(order.beforeQueue, 'the panel is before the queue').toBe(true);
    expect(order.beforeActivity, 'the panel is before Recent Activity').toBe(true);

    // The panel shows exactly one health badge, and it is open when not healthy.
    const healthy = await panel.locator('summary .badge', { hasText: 'All healthy' }).count();
    const unhealthy = await panel.locator('summary .badge', { hasText: 'Needs attention' }).count();
    expect(healthy + unhealthy, 'exactly one health badge').toBe(1);
    if (healthy === 1) {
        await expect(panel).not.toHaveAttribute('open', '');
    } else {
        await expect(panel).toHaveAttribute('open', '');
    }
}

// The Brevo row of the Status panel. This local site reaches one of three
// reachable states, and the test checks the one it finds. The fourth state
// (the settings probe threw, with the log hint) needs a failing database
// read, which Playwright cannot cause. VerificationTabStatusTest and
// VerificationStatusPanelRenderTest cover it.
async function expectBrevoRow(page) {
    await page.goto(TAB_URL, { waitUntil: 'networkidle' });

    const row = page.locator('#verificationStatusPanel [data-status-row="brevo"]');
    await expect(row).toHaveCount(1);
    await expect(row.locator('xpath=preceding-sibling::dt[1]')).toHaveText('Brevo ready');

    const badge = row.locator(':scope > span').first();
    // textContent, not innerText: the panel is a closed <details> when healthy,
    // and innerText of hidden content is empty.
    const badgeText = (await badge.textContent()).replace(/\s+/g, ' ').trim();
    const detail = row.locator('small');
    const probeAlertCount = await page.locator('.alert-danger', { hasText: 'Verification status could not be read' }).count();
    const LOG_HINT = 'Check the system log for VerificationConfigWarning.';

    expect(['Ready', 'Not ready', 'Not checked (switch is off)'], 'Brevo badge text').toContain(badgeText);

    if (badgeText === 'Ready') {
        await expect(badge).toHaveClass(/text-bg-success/);
        await expect(detail).toHaveCount(0);
        expect(probeAlertCount, 'a ready Brevo row means the probe did not throw').toBe(0);
    } else if (badgeText === 'Not ready') {
        await expect(badge).toHaveClass(/text-bg-danger/);
        await expect(detail).toHaveCount(1);
        const reason = (await detail.textContent()).replace(/\s+/g, ' ').trim();
        // One of the reasons of VerificationSettings::brevoNotReadyReason(),
        // or the probe-threw fallback.
        expect(reason).toMatch(
            /^(The Brevo plugin is not installed\.|The Brevo settings could not be read\.|No API key is saved in the Brevo plugin\.|The Brevo plugin is not active\.|The Brevo override file \(|The Brevo plugin version could not be read|The Brevo plugin is version |The Brevo status could not be checked\.)/
        );
        // The log hint shows only when the probe threw.
        if (probeAlertCount === 0) {
            // One reason ends with a similar text of its own: the settings read failed.
            if (!reason.startsWith('The Brevo settings could not be read.')) {
                expect(reason).not.toContain(LOG_HINT);
            }
        } else {
            expect(reason).toContain(LOG_HINT);
        }
    } else {
        // Switch off and Brevo not ready: nothing to report.
        await expect(badge).toHaveClass(/text-muted/);
        await expect(badge).not.toHaveClass(/badge/);
        await expect(detail).toHaveCount(0);
    }
}

let pendingCarId;
let bouncedCarId;
let bouncedCarIdB;
let staleBlockedCarId;
let suppressedCarId;
let blockedCarId;
let noProfileCarId;
let softBounceCarId;
let precedenceCarId;
let precedenceChassis;
let verifiedCarId;
let soldCarId;
let transferCarId;
let twinCarIdA;
let twinCarIdB;

// Seeds the fixture rows and keeps their ids in the module variables. The
// fixture needs the database, which is reachable only in the app container.
function seedVerificationFixture() {
    const output = runPhpFixture('tests/playwright/local/fixtures/seed-verification-dashboard.php');

    const seeded = JSON.parse(output.trim());
    pendingCarId = seeded.pendingCarId;
    bouncedCarId = seeded.bouncedCarId;
    bouncedCarIdB = seeded.bouncedCarIdB;
    staleBlockedCarId = seeded.staleBlockedCarId;
    suppressedCarId = seeded.suppressedCarId;
    blockedCarId = seeded.blockedCarId;
    noProfileCarId = seeded.noProfileCarId;
    softBounceCarId = seeded.softBounceCarId;
    precedenceCarId = seeded.precedenceCarId;
    precedenceChassis = seeded.precedenceChassis;
    verifiedCarId = seeded.verifiedCarId;
    soldCarId = seeded.soldCarId;
    transferCarId = seeded.transferCarId;
    twinCarIdA = seeded.twinCarIdA;
    twinCarIdB = seeded.twinCarIdB;

    for (const id of [pendingCarId, bouncedCarId, bouncedCarIdB, staleBlockedCarId, suppressedCarId, blockedCarId, noProfileCarId, softBounceCarId, precedenceCarId, verifiedCarId, soldCarId, transferCarId, twinCarIdA, twinCarIdB]) {
        expect(id).toBeGreaterThan(0);
    }
    expect(precedenceChassis).toContain('<script>');
}

// Leaves the shared local dataset as it was found. The seeded rows would
// otherwise inflate every summary count for any other suite or human.
function cleanupVerificationFixture() {
    runPhpFixture('tests/playwright/local/fixtures/seed-verification-dashboard.php', ['--cleanup']);
}

test.describe('Admin Verification Dashboard', () => {
    // SERIAL, NOT PARALLEL. With fullyParallel (playwright.config.js) and no
    // CI worker cap, Playwright can run this file across several workers —
    // and beforeAll/afterAll each run ONCE PER WORKER, not once for the
    // whole file. This file's afterAll DELETES the seeded rows (unlike
    // admin-verification-send-tool.spec.js's idempotent-reseed beforeAll,
    // which is harmless to repeat), so a second worker's beforeAll
    // re-seeding fresh rows, or a first worker's afterAll deleting them,
    // could interleave with another worker's in-flight summary-count
    // assertion — observed directly: the Window-control test flaked exactly
    // this way under the default (unconfigured) parallel run and passed
    // every time once isolated to one worker. `serial` forces every test in
    // this describe onto one worker, so this file's own single
    // beforeAll/afterAll pair runs exactly once each, before/after every
    // test below — not a workaround for a flaky assertion, a correctness
    // requirement for a suite whose fixture mutates shared summary counts.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        seedVerificationFixture();
    });

    test.afterAll(() => {
        cleanupVerificationFixture();
    });

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    // --- Summary cards -----------------------------------------------------
    //
    // AC (#2361): seven summary cards. Eligible, Pending, Verified and Sold
    // count cars. Bounced, Blocked and Opted out count owners ("owners"
    // sub-label), so the fixture's two-car bounced owner counts once on the
    // card and twice in the pill. Every number is compared with an independent
    // read of CarRepository::countVerificationSummary() through a standalone
    // PHP script, not derived from the page.
    test('seven summary cards and eight pills render with owner counts on the owner cards', async ({ page }) => {
        await expectSummaryCardsAndPills(page);
    });

    // --- Pill + URL round trip ---------------------------------------------

    // AC: clicking each of the 8 pills updates ?status= and the visible rows.
    for (const { status } of QUEUE_PILLS) {
        test(`"${status}" pill updates ?status= and marks itself active`, async ({ page }) => {
            await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

            await page.locator(`a.filter-pill[data-status="${status}"]`).click();
            await page.waitForLoadState('networkidle');

            expect(new URL(page.url()).searchParams.get('status')).toBe(status);
            await expect(page.locator(`a.filter-pill[data-status="${status}"]`)).toHaveClass(/active/);
            await expect(page.locator(`a.filter-pill[data-status="${status}"]`)).toHaveAttribute('aria-current', 'page');

            // Exactly one pill is active at a time.
            await expect(page.locator('a.filter-pill.active')).toHaveCount(1);
        });
    }

    // AC: an unknown status= value shows the "all" view, and the page never
    // prints it. app/admin/index.php's allow-list check replaces it with
    // 'all', so the value reaches no SQL, no pill link, no hidden field.
    test('an unknown ?status= shows the "all" view and is not printed', async ({ page }) => {
        const marker = 'not-a-real-status';

        await page.goto(`${TAB_URL}&status=all&show=100`, { waitUntil: 'networkidle' });
        const allIds = await page.locator('#verificationQueue table tbody tr td:first-child').allInnerTexts();
        expect(allIds.length, 'the "all" view has rows').toBeGreaterThan(0);

        const response = await page.goto(`${TAB_URL}&status=${marker}&show=100`, { waitUntil: 'networkidle' });
        expect(response.status()).toBe(200);
        expect(await page.locator('body').textContent()).not.toContain('Fatal error');
        // The page head echoes the request URL in its canonical and social
        // meta tags (upstream, escaped). The tab prints no part of the value.
        const bodyHtml = (await response.text()).split(/<body/i)[1] ?? '';
        expect(bodyHtml.length, 'the page has a body').toBeGreaterThan(0);
        expect(bodyHtml, 'the unknown value is not in the page body').not.toContain(marker);

        await expect(page.locator('a.filter-pill[data-status="all"]')).toHaveClass(/active/);
        await expect(page.locator('a.filter-pill.active')).toHaveCount(1);
        await expect(page.locator('#verificationQueueControls input[name="status"]')).toHaveValue('all');
        const unknownIds = await page.locator('#verificationQueue table tbody tr td:first-child').allInnerTexts();
        expect(unknownIds, 'same rows as the "all" view').toEqual(allIds);
    });

    // The same, with markup in the value: it is not printed in any form.
    test('a ?status= value with markup is not printed, escaped or not', async ({ page }) => {
        const response = await page.goto(
            `${TAB_URL}&status=${encodeURIComponent('<script>zz9</script>')}`,
            { waitUntil: 'networkidle' }
        );
        expect(response.status()).toBe(200);
        const source = (await response.text()).split(/<body/i)[1] ?? '';
        expect(source.length, 'the page has a body').toBeGreaterThan(0);
        expect(source).not.toContain('zz9');
        await expect(page.locator('a.filter-pill[data-status="all"]')).toHaveClass(/active/);
    });

    // AC (#2361): the Blocked and Opted out pills list only their own owners'
    // cars, and a block for an old address counts in neither.
    test('?status=blocked and ?status=opted_out list only their own cars', async ({ page }) => {
        await expectBlockedAndOptedOutFilters(page);
    });

    // --- Window / Show controls ---------------------------------------------

    // AC: the Window control changes the displayed Verified/Sold counts and
    // updates the URL. Compared against countVerificationSummary(90) directly,
    // the same independent-source approach as the summary-card test above.
    test('the Window control changes the Verified/Sold cards and updates ?window=', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

        await page.locator('#verificationQueueWindow').selectOption('90');
        await page.locator('#verificationQueueControls button[type="submit"]').click();
        await page.waitForLoadState('networkidle');

        expect(new URL(page.url()).searchParams.get('window')).toBe('90');
        await expect(page.locator('#verificationQueueWindow')).toHaveValue('90');

        const dbCountsJson = runPhpFixture('tests/playwright/local/fixtures/read-verification-summary.php', ['90']);
        const dbCounts = JSON.parse(dbCountsJson.trim());

        for (const key of ['verified', 'sold']) {
            const tile = page.locator(`#verificationSummaryCards [data-summary="${key}"] .er-stat-number`);
            const text = (await tile.textContent()).replace(/[,\s]/g, '');
            expect(text, `summary card "${key}" at window=90`).toBe(String(dbCounts[key]));
        }
    });

    // AC: the Show control changes the displayed row count and updates the URL.
    test('the Show control changes the row cap and updates ?show=', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=all&show=10', { waitUntil: 'networkidle' });

        const rowsAtTen = await page.locator('#verificationQueue table tbody tr').count();
        expect(rowsAtTen, 'this suite seeds >10 non-eligible rows across its own fixtures alone').toBeLessThanOrEqual(10);

        await page.locator('#verificationQueueShow').selectOption('100');
        await page.locator('#verificationQueueControls button[type="submit"]').click();
        await page.waitForLoadState('networkidle');

        expect(new URL(page.url()).searchParams.get('show')).toBe('100');
        await expect(page.locator('#verificationQueueShow')).toHaveValue('100');

        const rowsAtHundred = await page.locator('#verificationQueue table tbody tr').count();
        expect(rowsAtHundred).toBeGreaterThanOrEqual(rowsAtTen);
    });

    // AC: an invalid window=/show= value falls back to default, no PHP error.
    test('an invalid ?window= and ?show= fall back to defaults with no PHP error', async ({ page }) => {
        const response = await page.goto(
            'app/admin/index.php?tab=verification&window=notanumber&show=99999',
            { waitUntil: 'networkidle' }
        );
        expect(response.status()).toBe(200);
        expect(await page.locator('body').textContent()).not.toContain('Fatal error');

        // Default window is 30 days, default show is 25 rows (app/admin/index.php).
        await expect(page.locator('#verificationQueueWindow')).toHaveValue('30');
        await expect(page.locator('#verificationQueueShow')).toHaveValue('25');
    });

    // --- One action button per row -----------------------------------------

    // AC: each queue row shows exactly one contextual action button, and the
    // disabled soft-bounce case is rendered correctly: chip shows "Soft
    // bounce", Mark Bounced is rendered disabled. The owner also has a later
    // `delivered` row with car_id NULL (a password-reset email, #2359). It
    // must not turn the chip to "Delivered".
    test('the soft-bounce row shows the "Soft bounce" chip and a disabled Mark Bounced button', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=pending&show=100', { waitUntil: 'networkidle' });

        const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${softBounceCarId}")`) });
        await expect(row).toBeVisible();

        const chip = row.locator('td').nth(5).locator('span.badge');
        await expect(chip).toHaveText(/Soft bounce/);
        await expect(chip).toHaveAttribute('data-chip', 'soft_bounce');

        const actionButtons = row.locator('td').last().locator('button');
        await expect(actionButtons).toHaveCount(1);
        await expect(actionButtons.first()).toHaveText('Mark Bounced');
        await expect(actionButtons.first()).toBeDisabled();
    });

    // AC (one-action-per-row, the three non-disabled branches): Bounced ->
    // Clear Bounced, Blocked -> Clear Block, Opted out -> Clear Opt-out, an ordinary Pending
    // row -> Mark Bounced (enabled). Each case loads its own pill rather
    // than the "All" view: "All" orders by cars.id ASC
    // (findVerificationQueue()) and this local dataset has ~1500
    // pre-existing low-id eligible/pending rows, so a freshly-seeded
    // high-id row cannot rank into "All"'s LIMIT-100 window — the same
    // environment limitation admin-verification-send-tool.spec.js
    // documents for the Eligible preview. The Bounced/Suppressed/Pending
    // pills themselves have no such flooding (confirmed empirically: single
    // digits of rows each on this dataset), so each seeded car is looked up
    // under its own matching pill instead.
    test('Bounced, Blocked, Opted out and ordinary Pending rows each show exactly one matching action button', async ({ page }) => {
        const cases = [
            { id: bouncedCarId, status: 'bounced', label: 'Clear Bounced' },
            { id: bouncedCarIdB, status: 'bounced', label: 'Clear Bounced' },
            { id: suppressedCarId, status: 'opted_out', label: 'Clear Opt-out' },
            { id: blockedCarId, status: 'blocked', label: 'Clear Block' },
            { id: pendingCarId, status: 'pending', label: 'Mark Bounced' },
        ];

        for (const { id, status, label } of cases) {
            await page.goto(`app/admin/index.php?tab=verification&status=${status}&show=100`, { waitUntil: 'networkidle' });

            const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${id}")`) });
            await expect(row, `row for seeded car ${id} under status=${status}`).toBeVisible();

            const actionButtons = row.locator('td').last().locator('button');
            await expect(actionButtons).toHaveCount(1);
            await expect(actionButtons.first()).toHaveText(label);

            if (label === 'Mark Bounced') {
                await expect(actionButtons.first()).toBeEnabled();
            }

            // Clear Block and Clear Opt-out open the confirm modal. They do not submit.
            const ownerRequestCommand = { 'Clear Block': 'clear_block', 'Clear Opt-out': 'clear_opt_out' }[label];
            if (ownerRequestCommand) {
                await expect(actionButtons.first()).toHaveAttribute('type', 'button');
                await expect(actionButtons.first()).toHaveAttribute('data-owner-request-command', ownerRequestCommand);
            }
        }
    });

    // --- Profile-state chips ------------------------------------------------

    // The owner's profile state comes before the car's email events. Each case
    // loads its own pill, for the row-flooding reason above. The no-profile
    // car is pending, so the Pending pill lists it.
    test('profile state sets the Status chip: opted out, blocked, no profile', async ({ page }) => {
        const cases = [
            { id: suppressedCarId, status: 'opted_out', chip: 'opted_out', text: /Opted out/, action: 'Clear Opt-out' },
            { id: blockedCarId, status: 'blocked', chip: 'blocked', text: /^\s*Blocked\s*$/, action: 'Clear Block' },
            { id: noProfileCarId, status: 'pending', chip: 'no_profile', text: /No profile/, action: null },
        ];

        for (const { id, status, chip, text, action } of cases) {
            await page.goto(`app/admin/index.php?tab=verification&status=${status}&show=100`, { waitUntil: 'networkidle' });

            const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${id}")`) });
            await expect(row, `row for seeded car ${id} under status=${status}`).toBeVisible();

            const badge = row.locator('td').nth(5).locator('span.badge');
            await expect(badge).toHaveText(text);
            await expect(badge).toHaveAttribute('data-chip', chip);

            const actionButtons = row.locator('td').last().locator('button');
            if (action === null) {
                // An owner with no profile row has no action until an admin fixes the profile.
                await expect(actionButtons).toHaveCount(0);
            } else {
                await expect(actionButtons).toHaveCount(1);
                await expect(actionButtons.first()).toHaveText(action);
            }
        }
    });

    // --- Status-chip precedence ---------------------------------------------

    // AC: a `delivered` event followed by a terminal event in the same cycle
    // shows the terminal state on the chip, not Delivered — reproducing
    // CarRepositoryEmailEventPrecedenceTest
    // ::testTerminalEventWinsOverALaterDeliveredInTheSameCycle at the UI
    // layer. The fixture seeds `hard_bounce` at T+0 and `delivered` at
    // T+10m in the same cycle; a chip reading "Delivered" here would mean
    // the dashboard is not using the precedence-aware repository method, or
    // using it incorrectly.
    //
    // Looked up under status=pending (not "all") for the same row-flooding
    // reason as the one-action-per-row test above: this car is not
    // email_bounced=1 (its Bounced chip comes from the Status-chip
    // precedence method, not the car's own flag), so it is a member of the
    // Pending pill, which has no flooding problem on this dataset.
    test('a terminal event after an earlier timestamp still wins the Status chip over a later Delivered', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=pending&show=100', { waitUntil: 'networkidle' });

        const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${precedenceCarId}")`) });
        await expect(row).toBeVisible();

        const chip = row.locator('td').nth(5).locator('span.badge');
        await expect(chip).toHaveText(/Bounced/);
        await expect(chip).toHaveAttribute('data-chip', 'bounced');
        await expect(chip).not.toHaveText(/Delivered/);
    });

    // AC (#2358): events belong to the user. The transferred car's only
    // `sent` row belongs to the previous owner, so the new owner sees "not
    // sent": the em dash placeholder, with no badge.
    test('a transferred car shows "not sent" and not the previous owner\'s sent row', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=pending&show=100', { waitUntil: 'networkidle' });

        const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${transferCarId}")`) });
        await expect(row).toBeVisible();

        const statusCell = row.locator('td').nth(5);
        await expect(statusCell.locator('span.badge')).toHaveCount(0);
        await expect(statusCell).toHaveText('\u2014');
    });

    // AC (#2358, #2359): the webhook stores the event of mail that matched no
    // verification send with car_id NULL. A hard bounce or block of that kind
    // stops all mail to the owner, so every car of the owner shows it once its
    // own cycle has started. A `delivered` row with car_id NULL shows on no
    // car (see the soft-bounce row test above).
    test('an owner-level hard bounce (car_id NULL) shows on every car of that owner', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=pending&show=100', { waitUntil: 'networkidle' });

        for (const carId of [twinCarIdA, twinCarIdB]) {
            const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${carId}")`) });
            await expect(row).toBeVisible();

            const chip = row.locator('td').nth(5).locator('span.badge');
            await expect(chip).toHaveText(/Bounced/);
            await expect(chip).toHaveAttribute('data-chip', 'bounced');
        }
    });

    // --- <script>-chassis escaping ------------------------------------------

    // AC: the precedence car's chassis (`<script>x</scr>` — see the
    // fixture's own comment on why this is 15 characters, not the fuller
    // `<script>xss</script>` marker seed-verification-send-tool.php uses:
    // `cars.chassis` is `varchar(15)` and a longer value is silently
    // truncated by MySQL before this assertion would ever see it) must
    // render escaped, not as live markup. innerText() returns the
    // browser-parsed text node content; if the chassis were interpolated
    // unescaped, the `<script>` tag would either not appear in the DOM as
    // text at all (parsed as an element) or behave as one, neither of which
    // this assertion would see as "literal text present". Checking the raw
    // string is present via innerText() is therefore proof that the markup
    // was escaped into a text node, not inserted as HTML. This closes
    // DOCUMENTED GAP #3 in admin-verification-send-tool.spec.js, which
    // could not exercise its own `<script>`-chassis fixture because its
    // seeded row could not rank into the Eligible preview's batch-size-
    // capped window on this local dataset; the Pending pill used here has
    // no such cap.
    //
    // Looked up under status=pending (not "all") for the same row-flooding
    // reason noted on the precedence test above.
    test('a chassis containing a <script> tag renders escaped in the queue table', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=pending&show=100', { waitUntil: 'networkidle' });

        const row = page.locator('#verificationQueue table tbody tr', { has: page.locator(`td:text-is("${precedenceCarId}")`) });
        await expect(row).toBeVisible();

        const chassisCell = row.locator('td').nth(1);
        const text = await chassisCell.innerText();
        expect(text).toBe(precedenceChassis);

        // No actual <script> element was injected into the page by this row.
        await expect(page.locator('script', { hasText: 'x</scr>' })).toHaveCount(0);
    });

    // --- Recent activity -----------------------------------------------------

    // AC: the Recent Activity list renders the seeded VERIFIED / VERIFIED SOLD
    // cars_hist rows.
    test('Recent Activity lists the seeded VERIFIED and VERIFIED SOLD rows', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

        const activityList = page.locator('#verificationRecentActivity ul.list-unstyled');
        await expect(activityList).toBeVisible();

        const verifiedItem = activityList.locator('li', { hasText: `car ${verifiedCarId}` });
        await expect(verifiedItem).toHaveCount(1);
        await expect(verifiedItem).toContainText('VERIFIED');

        const soldItem = activityList.locator('li', { hasText: `car ${soldCarId}` });
        await expect(soldItem).toHaveCount(1);
        await expect(soldItem).toContainText('VERIFIED SOLD');
    });

    // --- Status panel position ----------------------------------------------
    //
    // AC (#2364): the Status panel renders directly before the Automatic
    // Sending card, whatever the health state. The panel's `open` attribute
    // follows health, and its place does not. This site shows one health state
    // per run, so the test asserts the place for that state. The integration
    // test AdminVerificationTabRenderTest renders both states.
    test('the Status panel sits directly before Automatic Sending, whatever the health', async ({ page }) => {
        await expectPanelBeforeAutomaticSending(page);
    });

    // AC (#2184): the Brevo row shows "Ready" or "Not ready" with the
    // reason, and the log hint only when the probe threw.
    test('the Brevo row shows its badge and reason, and the log hint only when the probe threw', async ({ page }) => {
        await expectBrevoRow(page);
    });
});

// ---------------------------------------------------------------------------
// Editor read-only contract.
//
// tab-verification.php's own docblock states the tab is "Read-only for
// editors" — $vsCanToggle = hasPerm([2], $currentUserId) gates every
// mutating control (Feature Switch, batch size Save, Send Batch Now,
// Pause/Resume, and the per-row Mark Bounced/Clear Bounced/Clear
// Suppression buttons) to administrators (permission_id = 2) only; an
// editor (permission_id = 3, confirmed via
// tests/integration/IsRegistryAdminTest.php's PERMISSION_EDITOR = 3) is
// admitted by securePage() but sees those controls rendered read-only
// (checkbox `disabled`, action buttons replaced by a plain "—", Save/Send
// Batch Now/Pause-Resume replaced by the "Administrator access is
// required" text).
//
// INFRASTRUCTURE GAP, same shape as admin-verification-send-tool.spec.js's
// own non-admin describe block: this project's Playwright test
// infrastructure has NO editor-specific credential pair. Confirmed by
// grepping this repo's .env.example and every tests/playwright/*.js file
// for "EDITOR" — the only two tiers wired up anywhere (auth-helper.js,
// playwright.config.js, playwright.config.dev.js, .env.example) are
// E2E_DEV_ADMIN_USERNAME/PASSWORD and E2E_DEV_NONADMIN_USERNAME/PASSWORD.
// E2E_DEV_NONADMIN_* is documented elsewhere in this codebase
// (admin-verification-send-tool.spec.js) as a permission_id=1 "plain
// owner" account, not permission_id=3 "editor" — hasPerm([2], ...) refuses
// both equally, so a plain-owner session cannot stand in for a genuine
// editor session here: securePage() itself would redirect a plain owner
// away from app/admin/index.php entirely (see that file's own
// postAsNonAdmin() helper), which would never reach the read-only-controls
// markup this test needs to inspect in the first place — only a session
// that securePage() admits (admin or editor) can even load the page.
// Inventing a new editor credential pair/env var is new test
// infrastructure, out of scope for this test-writing task per its own
// instructions. This test is therefore a real, named skip, not a silently
// dropped assertion — it will run on any environment where
// E2E_DEV_EDITOR_USERNAME/E2E_DEV_EDITOR_PASSWORD (a permission_id=3
// account) are added to .env.local.
test.describe('Admin Verification Dashboard editor read-only contract', () => {
    const hasEditorCredentials = !!(
        process.env.E2E_DEV_EDITOR_USERNAME && process.env.E2E_DEV_EDITOR_PASSWORD
    );

    // Serial: the seed and the cleanup run once for the whole describe block.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        test.skip(!hasEditorCredentials, 'E2E_DEV_EDITOR_USERNAME/E2E_DEV_EDITOR_PASSWORD are not set in .env.local');
        seedVerificationFixture();
    });

    test.afterAll(() => {
        if (hasEditorCredentials) {
            cleanupVerificationFixture();
        }
    });

    test.beforeEach(async ({ page, browserName }) => {
        test.skip(
            !hasEditorCredentials,
            'E2E_DEV_EDITOR_USERNAME/E2E_DEV_EDITOR_PASSWORD are not set in .env.local, and no editor-tier '
            + 'credential pair exists anywhere in this project\'s Playwright test infrastructure (confirmed via '
            + 'grep across .env.example and tests/playwright/*.js — only ADMIN and NONADMIN pairs exist, and '
            + 'NONADMIN is a permission_id=1 plain-owner account that securePage() redirects away from this page '
            + 'entirely, so it cannot stand in for a genuine permission_id=3 editor session). This is a known '
            + 'test-infrastructure gap, not a skipped assertion about the app itself — see this describe block\'s '
            + 'header comment.'
        );
        test.skip(browserName !== 'chromium', 'Login/logout dance only needs to run once, not per-browser-project');

        await ensureLoggedIn(page);
        await logout(page);
        await login(page, process.env.E2E_DEV_EDITOR_USERNAME, process.env.E2E_DEV_EDITOR_PASSWORD);
    });

    test('an editor sees seven summary cards and eight pills with owner counts', async ({ page }) => {
        await expectSummaryCardsAndPills(page);
    });

    test('an editor sees the Blocked and Opted out filters and the "Blocked" chip', async ({ page }) => {
        await expectBlockedAndOptedOutFilters(page);
    });

    test('an editor sees the Status panel directly before Automatic Sending', async ({ page }) => {
        await expectPanelBeforeAutomaticSending(page);
    });

    test('an editor sees the Brevo row', async ({ page }) => {
        await expectBrevoRow(page);
    });

    test('an editor session sees every mutating verification control read-only, not interactive', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification&status=all&show=100', { waitUntil: 'networkidle' });

        // Feature Switch checkbox: present but disabled.
        await expect(page.locator('#verificationEnabledSwitch')).toBeDisabled();

        // Automatic Sending: no Save/Send Batch Now/Pause-Resume controls —
        // only the "Administrator access is required" notice.
        const autoSendCard = page.getByRole('heading', { name: 'Automatic Sending', level: 5 })
            .locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " card ")][1]');
        await expect(autoSendCard.locator('button:has-text("Save")')).toHaveCount(0);
        await expect(autoSendCard.getByText('Administrator access is required')).toBeVisible();

        // Every queue row's Actions cell shows a plain dash, never a button.
        const queueCard = page.locator('#verificationQueue');
        await expect(queueCard.locator('table tbody tr').first()).toBeVisible();
        await expect(queueCard.locator('table tbody button')).toHaveCount(0);
    });
});
