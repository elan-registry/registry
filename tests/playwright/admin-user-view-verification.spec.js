// tests/playwright/admin-user-view-verification.spec.js
//
// Coverage for the "Verification & Email" card rendered on the admin user
// view page (users/admin.php?view=user&id=<id>) by
// usersc/plugins/hooker/hooks/user_form_hook.php (#1924).
//
// Seeds two unrelated owners via tests/playwright/local/fixtures/seed-bounced-car.php
// (run once in beforeAll via execSync, matching this project's DB-seeding
// fixture convention — see that script's own header for why it needs a real
// users/init.php boot rather than a hand-rolled PDO connection):
//   - one owner with one car that has email_bounced = 1, to assert the
//     warning-path rendering (bounced badge, bounced address, "1 car bounced,
//     0 suppressed" summary line);
//   - a second, unrelated owner with one clean car (no bounce/suppression
//     flags), to assert the "No delivery problems recorded" empty-state path
//     — proving the block doesn't just always render the warning state.
//
// This is genuinely new test infrastructure: no existing Playwright spec in
// this repo seeds rows directly into the local MAMP database from within the
// spec itself (the closest precedent, local/email-button-row-responsive.spec.js,
// only shells out to a fixture that generates static HTML — it never touches
// a database). Flagged here, and in the PR description, so a reviewer isn't
// surprised by the new pattern.
//
// Deviation from the plan's literal file path: the plan names
// tests/playwright/admin-user-view-verification.spec.js (not under local/).
// This file is placed at that exact path deliberately — despite living next
// to other top-level specs that require local MAMP + an authenticated admin
// session (e.g. admin-owner-mgmt.spec.js, admin-maintenance-smoke.spec.js),
// which is the established convention for admin-page specs in this repo.
// tests/playwright/local/ is not a distinct "requires MAMP" bucket (it is
// also matched by the plain `chromium` project in playwright.config.js —
// see local/email-button-row-responsive.spec.js, which runs under the same
// project as this file); it is only where PHP CLI *fixture scripts* live.
// The `logged-in` project's narrow testMatch allowlist does NOT need to be
// touched for this file: like admin-owner-mgmt.spec.js and
// admin-maintenance-smoke.spec.js, this spec logs in live per-test via
// ensureLoggedIn() (auth-helper.js) inside the plain `chromium` project,
// rather than depending on the `logged-in` project's storageState.
//
// Requires local MAMP with TEST_USERNAME/TEST_PASSWORD (an admin account) set
// in .env.local. Default base URL: http://localhost:9999/ElanRegistry/Registry/
// — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md.
// Also requires US_ENVIRONMENT=development locally (see the seed fixture's
// own guard) — the fixture refuses to run against a deployed environment.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { ensureLoggedIn } = require('./auth-helper.js');

let bouncedUserId;
let cleanUserId;

test.beforeAll(() => {
    const fixturePath = path.join(__dirname, 'local', 'fixtures', 'seed-bounced-car.php');
    // execFileSync (not execSync) — no shell involved, matching this repo's
    // existing PHP-fixture-invocation precedent in
    // local/email-button-row-responsive.spec.js. The plan's Test Plan
    // section says "via execSync"; execFileSync here achieves the same
    // effect (run the fixture, capture stdout) without shelling out, which
    // this project's own tooling flags as unnecessary risk even though
    // fixturePath is a fixed, non-user-controlled path.
    const output = execFileSync('php', [fixturePath], { encoding: 'utf8' });

    const seeded = JSON.parse(output.trim());
    bouncedUserId = seeded.bouncedUserId;
    cleanUserId = seeded.cleanUserId;

    expect(bouncedUserId).toBeGreaterThan(0);
    expect(cleanUserId).toBeGreaterThan(0);
});

test.describe('Admin User View — Verification & Email card', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('renders the bounced state for an owner with a bounced car', async ({ page }) => {
        await page.goto(`users/admin.php?view=user&id=${bouncedUserId}`, { waitUntil: 'networkidle' });

        // Scope every assertion to the card itself: the admin user-view page
        // also renders UserSpice's own unrelated .alert-warning ("this user
        // has not verified their email address") outside this card, which a
        // page-wide `.alert-warning` locator would collide with.
        const heading = page.getByRole('heading', { name: 'Verification & Email', level: 6 });
        await expect(heading).toBeVisible();
        const card = page.locator('div.card', { has: heading });

        // Summary line: "<N> car<s> bounced, <M> suppressed" inside .alert-warning.
        // Exactly one bounced car and zero suppressed, per the seed fixture.
        const summary = card.locator('.alert-warning');
        await expect(summary).toBeVisible();
        await expect(summary).toContainText('1 car bounced,');
        await expect(summary).toContainText('0 suppressed');

        // Per-car table: Bounced column shows the badge + bounced address.
        const bouncedBadge = card.locator('span.badge.text-bg-danger', { hasText: 'Bounced' });
        await expect(bouncedBadge).toBeVisible();
        await expect(card.locator('td', { hasText: 'bounced@example.invalid' })).toBeVisible();

        // The empty-state alert must NOT be present when there is a bounce.
        await expect(card.locator('.alert-primary', { hasText: 'No delivery problems recorded' })).toHaveCount(0);
    });

    test('renders the empty state for an owner with no delivery problems', async ({ page }) => {
        await page.goto(`users/admin.php?view=user&id=${cleanUserId}`, { waitUntil: 'networkidle' });

        const heading = page.getByRole('heading', { name: 'Verification & Email', level: 6 });
        await expect(heading).toBeVisible();
        const card = page.locator('div.card', { has: heading });

        const emptyState = card.locator('.alert-primary', { hasText: 'No delivery problems recorded' });
        await expect(emptyState).toBeVisible();

        // The warning summary line must NOT be present, within the card, for
        // a clean owner (page-wide UserSpice alerts, e.g. an unverified-email
        // notice unrelated to this card, are out of scope for this assertion).
        await expect(card.locator('.alert-warning')).toHaveCount(0);

        // No Bounced/Suppressed badges should render for the clean car's row.
        await expect(card.locator('span.badge.text-bg-danger', { hasText: 'Bounced' })).toHaveCount(0);
        await expect(card.locator('span.badge.text-bg-warning', { hasText: 'Suppressed' })).toHaveCount(0);
    });
});
