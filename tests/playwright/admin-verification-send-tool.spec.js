// Admin Verification tab: "Send Verification Emails" (#1884) and the
// Automatic Sending panel (#1885).
//
// The local DB holds more eligible cars than the batch-size cap (max 255) can
// show, so the seeded eligible car may not appear in the preview. Tests that
// need any eligible row use the first rendered row.
//
// SAFETY: never submit the "Send batch" form unless automatic sending is
// confirmed PAUSED. The pause-check in app/admin/index.php
// (`case "verification_send_batch"`) breaks out before VerificationBatchSender
// is constructed, so email() cannot run. Locally email() is undefined (no
// sendinblue override.php), so a send would fatal the request.

const { test, expect } = require('@playwright/test');
const { runPhpFixture } = require('./fixture-runner.js');
const { ensureLoggedIn, login, logout } = require('./auth-helper.js');

let eligibleCarId;
let eligibleUserId;
let _eligibleChassis;
let bouncedCarId;
let _bouncedUserId;
let suppressedCarId;
let _suppressedUserId;

test.beforeAll(() => {
    // The fixture needs the database, which is reachable only in the app container.
    const output = runPhpFixture('tests/playwright/local/fixtures/seed-verification-send-tool.php');

    const seeded = JSON.parse(output.trim());
    eligibleCarId = seeded.eligibleCarId;
    eligibleUserId = seeded.eligibleUserId;
    _eligibleChassis = seeded.eligibleChassis;
    bouncedCarId = seeded.bouncedCarId;
    _bouncedUserId = seeded.bouncedUserId;
    suppressedCarId = seeded.suppressedCarId;
    _suppressedUserId = seeded.suppressedUserId;

    expect(eligibleCarId).toBeGreaterThan(0);
    expect(eligibleUserId).toBeGreaterThan(0);
    expect(bouncedCarId).toBeGreaterThan(0);
    expect(suppressedCarId).toBeGreaterThan(0);
});

test.describe('Admin Verification Send Tool', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    // AC1: proves the populated state renders, not the empty state.
    test('Send Verification Emails section renders on GET with a populated preview', async ({ page }) => {
        const response = await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });
        expect(response.status()).toBe(200);
        expect(await page.locator('body').textContent()).not.toContain('Fatal error');

        const heading = page.getByRole('heading', { name: 'Send Verification Emails', level: 5 });
        await expect(heading).toBeVisible();
        const card = page.locator('div.card', { has: heading });

        await expect(card.locator('table tbody tr').first()).toBeVisible();
        await expect(card.locator('.alert-info', { hasText: 'No cars are currently due' })).toHaveCount(0);
    });

    // AC5/AC6 wiring only. Uses the first rendered row (see file header).
    test('owner-action buttons are wired to their sibling forms via form=', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

        const heading = page.getByRole('heading', { name: 'Send Verification Emails', level: 5 });
        const card = page.locator('div.card', { has: heading });

        const firstRow = card.locator('table tbody tr').first();
        await expect(firstRow).toBeVisible();
        const rowCarId = await firstRow.locator('td').first().textContent();
        const carId = rowCarId.trim();
        expect(carId).toMatch(/^\d+$/);

        const markBouncedBtn = firstRow.locator('button', { hasText: 'Mark Bounced' });
        const clearBouncedBtn = firstRow.locator('button', { hasText: 'Clear Bounced' });
        const clearSuppressionBtn = firstRow.locator('button', { hasText: 'Clear Suppression' });

        await expect(markBouncedBtn).toBeVisible();
        await expect(clearBouncedBtn).toBeVisible();
        await expect(clearSuppressionBtn).toBeVisible();

        const expectedFormId = `owner-action-${carId}`;
        await expect(markBouncedBtn).toHaveAttribute('form', expectedFormId);
        await expect(clearBouncedBtn).toHaveAttribute('form', expectedFormId);
        await expect(clearSuppressionBtn).toHaveAttribute('form', expectedFormId);

    // AC2 presence half.
        const siblingForm = page.locator(`form#${expectedFormId}`);
        await expect(siblingForm).toBeAttached();
        await expect(siblingForm.locator('input[name="car_id"]')).toHaveValue(carId);
        const csrfValue = await siblingForm.locator('input[name="csrf"]').getAttribute('value');
        expect(csrfValue).toBeTruthy();
    });

    test('batch send form carries a non-empty CSRF token and at least one car_ids[] entry', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

        const heading = page.getByRole('heading', { name: 'Send Verification Emails', level: 5 });
        const card = page.locator('div.card', { has: heading });

        const batchForm = card.locator('form', { has: page.locator('input[name="command"][value="verification_send_batch"]') });
        await expect(batchForm).toBeAttached();

        const csrfValue = await batchForm.locator('input[name="csrf"]').getAttribute('value');
        expect(csrfValue).toBeTruthy();

        const carIdInputs = batchForm.locator('input[name="car_ids[]"]');
        const values = await carIdInputs.evaluateAll(inputs => inputs.map(el => el.value));
        expect(values.length).toBeGreaterThan(0);
        expect(values.every(v => /^\d+$/.test(v))).toBe(true);
    });

    // AC2 rejection half.
    test('POST with an invalid CSRF token is rejected and makes no change', async ({ page }) => {
        // page.request shares the page's cookie jar, so the POST carries the admin session.
        const response = await page.request.post('app/admin/index.php?tab=verification', {
            form: {
                csrf: 'not-a-real-token',
                command: 'mark_bounced',
                car_id: String(eligibleCarId),
            },
        });

        // token_error.php returns 200 with plain text, so assert on the body.
        const body = await response.text();
        expect(body).toContain('There was an error with your form');

        // A rejected CSRF check never reaches the point that sets this flash.
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });
        await expect(page.locator('.alert-success', { hasText: 'marked as bounced' })).toHaveCount(0);
    });

    test('bounced and suppressed cars are excluded from the eligible preview', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=verification', { waitUntil: 'networkidle' });

        const heading = page.getByRole('heading', { name: 'Send Verification Emails', level: 5 });
        const card = page.locator('div.card', { has: heading });

        await expect(card.locator('td', { hasText: String(bouncedCarId) })).toHaveCount(0);
        await expect(card.locator('td', { hasText: String(suppressedCarId) })).toHaveCount(0);
    });
});

// Automatic Sending panel (#1885). Serial: both tests change the one
// er_cron_job_runs row that the tests above read. Every test leaves the job
// paused (the seeded state), so the real cron shim stays off.
test.describe('Admin Verification Automatic Sending panel', () => {
    test.describe.configure({ mode: 'serial' });

    const TAB_URL = 'app/admin/index.php?tab=verification';

    function autoSendCard(page) {
        const heading = page.getByRole('heading', { name: 'Automatic Sending', level: 5 });
        return page.locator('div.card', { has: heading });
    }

    function toggleForm(page) {
        return autoSendCard(page).locator('form', {
            has: page.locator('input[name="command"][value="verification_toggle_cron"]'),
        });
    }

    /**
     * Read the badge text and the toggle form's desired_state. Tests check
     * both, so neither is trusted to imply the other.
     */
    async function readPanelState(page) {
        const card = autoSendCard(page);
        await expect(card).toBeVisible();

        const badgeText = (await card.locator('dd span.badge').first().textContent()).trim();
        const desiredState = await toggleForm(page)
            .locator('input[name="desired_state"]')
            .getAttribute('value');

        return { badgeText, desiredState };
    }

    /**
     * Submit the toggle form and return the flash toast text ('' if none).
     * The toast auto-dismisses after 6s, so the wait starts before the click.
     */
    async function clickToggle(page, barClass = 'us-bar-success') {
        const toast = page.locator(`.us-toast:has(.${barClass}) .toast-body`).first();
        const toastText = toast
            .waitFor({ state: 'visible', timeout: 10000 })
            .then(() => toast.textContent())
            .catch(() => '');

        await toggleForm(page).locator('button[type="submit"]').click();
        const text = await toastText;
        await page.waitForLoadState('networkidle');

        return (text || '').trim();
    }

    /** Force the paused state. Idempotent: the handler takes an explicit desired_state. */
    async function ensurePaused(page) {
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });
        const { desiredState } = await readPanelState(page);
        if (desiredState === 'disable') {
            await clickToggle(page);
        }
        await expect(autoSendCard(page).locator('dd span.badge').first()).toHaveText(/Paused/);
    }

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    // Restore the seeded/paused state no matter how a test above exited —
    // an assertion failure mid-test must not leave automatic sending armed.
    test.afterEach(async ({ page }) => {
        await ensurePaused(page);
    });

    test('Pause/Resume toggle flips automatic sending and the new state survives a reload', async ({ page }) => {
        let originalBadge;
        let originalDesiredState;

        await test.step('read the starting state', async () => {
            await page.goto(TAB_URL, { waitUntil: 'networkidle' });
            const state = await readPanelState(page);
            originalBadge = state.badgeText;
            originalDesiredState = state.desiredState;

            // Any other value means the panel did not render its real state.
            expect(['enable', 'disable']).toContain(originalDesiredState);

            // 'disable' is offered only when ENABLED ('Ran' or 'Never run').
            if (originalDesiredState === 'disable') {
                expect(originalBadge).toMatch(/Ran|Never run/);
            } else {
                expect(originalBadge).toMatch(/Paused|Status unavailable/);
            }
        });

        await test.step('click the toggle and assert the badge flipped', async () => {
            const flash = await clickToggle(page);

            // The handler reports the persisted value, so the flash proves the write.
            const expectedFlash = originalDesiredState === 'disable'
                ? 'Automatic sending paused.'
                : 'Automatic sending resumed.';
            expect(flash).toContain(expectedFlash);

            const afterToggle = await readPanelState(page);
            expect(afterToggle.desiredState).not.toBe(originalDesiredState);
            expect(afterToggle.badgeText).not.toBe(originalBadge);

            if (originalDesiredState === 'disable') {
                // Was enabled, now paused.
                expect(afterToggle.badgeText).toMatch(/Paused/);
                expect(afterToggle.desiredState).toBe('enable');
                // The warning banner ($autoSendPaused) is a separate signal from the badge.
                await expect(
                    autoSendCard(page).locator('.alert-warning', { hasText: 'Automatic sending is paused' })
                ).toHaveCount(1);
            } else {
                // Was paused (or unreadable), now enabled.
                expect(afterToggle.badgeText).toMatch(/Ran|Never run/);
                expect(afterToggle.desiredState).toBe('disable');
                await expect(
                    autoSendCard(page).locator('.alert-warning', { hasText: 'Automatic sending is paused' })
                ).toHaveCount(0);
            }
        });

        await test.step('reload and assert the flipped state persisted', async () => {
            // A fresh GET re-reads er_cron_job_runs.
            await page.goto(TAB_URL, { waitUntil: 'networkidle' });

            const afterReload = await readPanelState(page);
            expect(afterReload.desiredState).not.toBe(originalDesiredState);
            expect(afterReload.badgeText).not.toBe(originalBadge);
        });

        await test.step('restore the original state', async () => {
            // Toggle back explicitly so the round trip must land on the start state.
            await clickToggle(page);

            const restored = await readPanelState(page);
            expect(restored.desiredState).toBe(originalDesiredState);
            expect(restored.badgeText).toBe(originalBadge);
        });
    });

    // SAFETY: see the file header. Do not relax the "ensure paused" step.
    test('Send Batch Now is blocked while automatic sending is paused', async ({ page }) => {
        await test.step('ensure automatic sending is paused', async () => {
            await ensurePaused(page);

            // Hard precondition: if not paused, the submit could reach the real send path.
            const { badgeText, desiredState } = await readPanelState(page);
            expect(badgeText).toMatch(/Paused/);
            expect(desiredState).toBe('enable');
        });

        await test.step('submit the batch form and assert it was refused', async () => {
            const sendHeading = page.getByRole('heading', { name: 'Send Verification Emails', level: 5 });
            const sendCard = page.locator('div.card', { has: sendHeading });
            const batchForm = sendCard.locator('form', {
                has: page.locator('input[name="command"][value="verification_send_batch"]'),
            });
            await expect(batchForm).toBeAttached();

            // Same auto-dismiss race as clickToggle().
            const errorToast = page.locator('.us-toast:has(.us-bar-danger) .toast-body').first();
            const errorText = errorToast
                .waitFor({ state: 'visible', timeout: 10000 })
                .then(() => errorToast.textContent())
                .catch(() => '');

            await batchForm.locator('button[type="submit"]').click();
            const flash = (await errorText || '').trim();
            await page.waitForLoadState('networkidle');

            expect(flash).toContain(
                'Automatic sending is paused — resume it on this tab before sending a batch manually.'
            );
        });

        await test.step('assert no send report was produced', async () => {
            // The report sections render only after the pause-check, so their absence proves no send ran.
            await expect(page.getByRole('heading', { name: /^Sent \(/ })).toHaveCount(0);
            await expect(page.getByRole('heading', { name: /^Skipped \(/ })).toHaveCount(0);
            await expect(page.getByRole('heading', { name: /^Failed \(/ })).toHaveCount(0);

            await expect(
                page.locator('.us-toast:has(.us-bar-success) .toast-body', { hasText: 'Batch complete' })
            ).toHaveCount(0);
        });
    });
});

// Non-admin refusal for the #1885 commands (hasPerm([2]) gate). Uses the
// persistent non-admin account (see car-edit-missing-car.spec.js).
test.describe('Admin Verification non-admin access (#1885 commands)', () => {
    const TAB_URL = 'app/admin/index.php?tab=verification';

    const hasNonAdminCredentials = !!(
        process.env.E2E_DEV_NONADMIN_USERNAME && process.env.E2E_DEV_NONADMIN_PASSWORD
    );

    test.beforeEach(async ({ page, browserName }) => {
        test.skip(
            !hasNonAdminCredentials,
            'E2E_DEV_NONADMIN_USERNAME/E2E_DEV_NONADMIN_PASSWORD are not set in .env.local — a genuine '
            + 'non-admin Playwright session is not available on this environment, so the '
            + 'verification_toggle_cron/verification_set_batch_size non-admin-refusal checks below cannot run. '
            + 'This is a known test-infrastructure gap, not a skipped assertion about the app itself.'
        );
        test.skip(browserName !== 'chromium', 'Login/logout dance only needs to run once, not per-browser-project');

        // Swap the admin session for the non-admin account.
        await ensureLoggedIn(page);
        await logout(page);
        await login(page, process.env.E2E_DEV_NONADMIN_USERNAME, process.env.E2E_DEV_NONADMIN_PASSWORD);
    });

    test('verification_toggle_cron is refused for a non-admin session', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded' });
        const preUrl = page.url();
        // The tab may render for editors; this test targets only the command gate.
        test.skip(
            preUrl.includes('login') || preUrl.includes('Please Log In'),
            'Non-admin session could not reach the verification tab at all — cannot exercise the command gate'
        );

        const csrfToken = await page.locator('input[name="csrf"]').first().getAttribute('value').catch(() => null);

        const response = await page.request.post(TAB_URL, {
            form: {
                csrf: csrfToken || '',
                command: 'verification_toggle_cron',
                desired_state: 'enable',
            },
        });

        const body = await response.text();

        // Rejection by CSRF or by the admin gate is acceptable; a success flash is not.
        const rejectedByCsrf = body.includes('There was an error with your form');
        const rejectedByPermission = body.includes('Administrator access is required for this action.');
        expect(
            rejectedByCsrf || rejectedByPermission,
            'A non-admin POST to verification_toggle_cron must be rejected either by CSRF or by the admin-only gate'
        ).toBe(true);

        expect(body).not.toContain('Automatic sending resumed.');
        expect(body).not.toContain('Automatic sending paused.');
    });

    test('verification_set_batch_size is refused for a non-admin session', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded' });
        const preUrl = page.url();
        test.skip(
            preUrl.includes('login') || preUrl.includes('Please Log In'),
            'Non-admin session could not reach the verification tab at all — cannot exercise the command gate'
        );

        const csrfToken = await page.locator('input[name="csrf"]').first().getAttribute('value').catch(() => null);

        const response = await page.request.post(TAB_URL, {
            form: {
                csrf: csrfToken || '',
                command: 'verification_set_batch_size',
                batch_size: '10',
            },
        });

        const body = await response.text();

        const rejectedByCsrf = body.includes('There was an error with your form');
        const rejectedByPermission = body.includes('Administrator access is required for this action.');
        expect(
            rejectedByCsrf || rejectedByPermission,
            'A non-admin POST to verification_set_batch_size must be rejected either by CSRF or by the admin-only gate'
        ).toBe(true);

        expect(body).not.toContain('Batch size updated to');
    });
});

// Not covered here:
// 1. The "Send batch" happy path: email() is undefined locally (see header).
// 2. Full Mark Bounced / Clear Bounced / Clear Suppression round trips: each
//    needs its own seeded car so the tests do not change shared data.
// 3. XSS escaping in the preview: the seeded <script> chassis cannot rank into
//    the capped preview on the local dataset.
