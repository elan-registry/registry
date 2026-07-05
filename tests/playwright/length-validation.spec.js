// tests/playwright/length-validation.spec.js
//
// Regression tests for issue #1107: length-validation boundary coverage for
// chassis-availability.php and transfer-request.php (hardened in #1081).
//
// Strategy: navigate to app/owner/cars/edit.php to get a real CSRF token from the
// session, then POST directly to each endpoint via page.request with boundary
// values (at-limit and over-limit). The auth session from ensureLoggedIn()
// is shared with page.request, so the CSRF token extracted from the DOM is
// valid for subsequent API calls in the same session.
//
// transfer-request.php — model parsing: the endpoint splits the `model` POST
// field on '|' to derive series/variant/type. There are no separate POST
// fields for these derived values.
//
// transfer-request.php — rate limiting: recordRateLimit() fires at the top of
// the endpoint, before any length check. Each test call (even one that fails
// on length) consumes a rate-limit slot. The over-limit tests guard against
// this: if the endpoint responds with a rate-limit message the test skips
// rather than failing with a misleading assertion error.
//
// Requires local MAMP at http://localhost:9999/elan-registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Navigate to the car edit page and return a valid CSRF token from the DOM.
 * Returns null if the page redirects to login (unauthenticated).
 */
async function getCsrfFromEditPage(page) {
    await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
    const url = page.url();
    if (url.includes('login') || url.includes('Please Log In')) {
        return null;
    }
    const token = await page.locator('input#csrf').getAttribute('value');
    return token || null;
}

// ---------------------------------------------------------------------------
// chassis-availability.php — length validation
// ---------------------------------------------------------------------------

test.describe('chassis-availability.php — length validation', () => {
    let csrfToken;

    test.beforeEach(async ({ page }) => {
        if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
            test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
        }
        await ensureLoggedIn(page);
        csrfToken = await getCsrfFromEditPage(page);
        if (!csrfToken) {
            test.skip(true, 'Could not obtain CSRF token from car edit page');
        }
    });

    // Base payload with all fields at or under their limits.
    // model must have 3 pipe-separated parts for the format check.
    const baseValid = {
        command: 'chassis_check',
        chassis: '123456789012345', // exactly 15 chars (at limit)
        year: '1973',               // exactly 4 chars (at limit)
        model: 'S4|SE|FHC',         // 9 chars (under 30-char limit, valid format)
    };

    test('chassis exactly 15 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: { ...baseValid, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        // Must not fail with a chassis length error at the limit
        if (!body.success) {
            expect(body.message).not.toMatch(/chassis.*15/i);
        }
    });

    test('chassis 16 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: {
                ...baseValid,
                chassis: '1234567890123456', // 16 chars — over limit
                csrf: csrfToken,
            },
        });
        expect(resp.status()).toBe(400);
        const body = await resp.json();
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/chassis.*15/i);
    });

    test('year exactly 4 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: { ...baseValid, year: '1973', csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/year.*4/i);
        }
    });

    test('year 5 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: {
                ...baseValid,
                year: '19733', // 5 chars — over limit
                csrf: csrfToken,
            },
        });
        expect(resp.status()).toBe(400);
        const body = await resp.json();
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/year.*4/i);
    });

    test('model exactly 30 chars is accepted (at-limit)', async ({ page }) => {
        // 26 A's + |B|C = exactly 30 chars, valid 3-part format
        const model30 = 'A'.repeat(26) + '|B|C';
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: { ...baseValid, model: model30, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        // Must not fail with a model length error at the limit
        if (!body.success) {
            expect(body.message).not.toMatch(/model.*30/i);
        }
    });

    test('model 31 chars rejected with 400 (over-limit)', async ({ page }) => {
        // 27 A's + |B|C = exactly 31 chars (over the 30-char limit)
        const model31 = 'A'.repeat(27) + '|B|C';
        const resp = await page.request.post('app/api/cars/chassis-availability.php', {
            data: {
                ...baseValid,
                model: model31,
                csrf: csrfToken,
            },
        });
        expect(resp.status()).toBe(400);
        const body = await resp.json();
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/model.*30/i);
    });
});

// ---------------------------------------------------------------------------
// transfer-request.php — length validation
// ---------------------------------------------------------------------------

test.describe('transfer-request.php — length validation', () => {
    let csrfToken;

    test.beforeEach(async ({ page }) => {
        if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
            test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
        }
        await ensureLoggedIn(page);
        csrfToken = await getCsrfFromEditPage(page);
        if (!csrfToken) {
            test.skip(true, 'Could not obtain CSRF token from car edit page');
        }
    });

    // Base payload with all simple fields at their limits, and model derived
    // fields at or under limit (series='S4'=2, variant='SE'=2, type='FHC'=3).
    // model is split on '|' server-side — no separate series/variant/type fields.
    // chassis/year/type won't match any real car so validation-passing requests
    // end with "No car found" — but length checks fire before the DB lookup.
    const baseValid = {
        chassis: '123456789012345',             // 15 chars (at limit)
        year: '1973',                           // 4 chars (at limit)
        color: '0123456789012345678901234',      // 25 chars (at limit)
        engine: '123456789012345',              // 15 chars (at limit)
        comments: 'A'.repeat(1000),             // 1000 chars (at limit)
        model: 'S4|SE|FHC',                    // valid format; series='S4'(2), variant='SE'(2), type='FHC'(3 — at limit)
    };

    // -------------------------------------------------------------------
    // At-limit acceptance tests
    // -------------------------------------------------------------------
    // Verify that baseValid fields at the boundary are not rejected by length
    // validation. The response may fail for unrelated reasons (rate limit,
    // car not found) — the assertion only checks that no length error is returned.

    test('chassis exactly 15 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/chassis.*15/i);
        }
    });

    test('color exactly 25 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/color.*25/i);
        }
    });

    test('engine exactly 15 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/engine.*15/i);
        }
    });

    test('comments exactly 1000 chars is accepted (at-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/1000/i);
        }
    });

    test('model exactly 30 chars is accepted (at-limit)', async ({ page }) => {
        // 26 A's + |B|C = exactly 30 chars, valid 3-part format
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, model: 'A'.repeat(26) + '|B|C', csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/model.*30/i);
        }
    });

    test('series exactly 12 chars is accepted (at-limit)', async ({ page }) => {
        // 12 A's + |B|C = 16-char model (under 30), series='A'.repeat(12) is at limit
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, model: 'A'.repeat(12) + '|B|C', csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/series.*12/i);
        }
    });

    test('variant exactly 15 chars is accepted (at-limit)', async ({ page }) => {
        // S4 + 15 B's + C = 20-char model (under 30), variant='B'.repeat(15) is at limit
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: { ...baseValid, model: 'S4|' + 'B'.repeat(15) + '|C', csrf: csrfToken },
        });
        const body = await resp.json();
        expect(body).toHaveProperty('success');
        if (!body.success) {
            expect(body.message).not.toMatch(/variant.*15/i);
        }
    });

    // -------------------------------------------------------------------
    // Over-limit rejection tests
    // Rate limit guard: recordRateLimit() fires before length checks, so if the
    // rate limit is exhausted the endpoint returns a 400 "Too many transfer
    // requests" message. Skip (don't fail) in that case.
    // -------------------------------------------------------------------

    test('chassis 16 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                chassis: '1234567890123456', // 16 chars
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/chassis.*15/i);
    });

    test('year 5 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                year: '19733', // 5 chars
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/year.*4/i);
    });

    test('color 26 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                color: '01234567890123456789012345', // 26 chars
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/color.*25/i);
    });

    test('engine 16 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                engine: '1234567890123456', // 16 chars
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/engine.*15/i);
    });

    test('comments 1001 chars rejected with 400 (over-limit)', async ({ page }) => {
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                comments: 'A'.repeat(1001),
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        // Error: "Transfer explanation must be 1000 characters or less"
        expect(body.message).toMatch(/1000/i);
    });

    test('model 31 chars rejected with 400 (over-limit)', async ({ page }) => {
        // 27 A's + |B|C = 31 chars (over the 30-char limit); valid format
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                model: 'A'.repeat(27) + '|B|C',
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/model.*30/i);
    });

    test('series (model part 1) 13 chars rejected with 400 (over-limit)', async ({ page }) => {
        // 13 A's + |B|C = 17-char model (under 30), but series part is 13 chars (over 12-char limit)
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                model: 'A'.repeat(13) + '|B|C',
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/series.*12/i);
    });

    test('variant (model part 2) 16 chars rejected with 400 (over-limit)', async ({ page }) => {
        // S4 + 16 B's + C = 21-char model (under 30), but variant part is 16 chars (over 15-char limit)
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                model: 'S4|' + 'B'.repeat(16) + '|C',
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        expect(body.message).toMatch(/variant.*15/i);
    });

    test('type (model part 3) 4 chars rejected with 400 (over-limit)', async ({ page }) => {
        // S4|SE|ABCD = 10-char model (under 30), but type part is 4 chars (over 3-char limit)
        const resp = await page.request.post('app/api/cars/transfer-request.php', {
            data: {
                ...baseValid,
                model: 'S4|SE|ABCD',
                csrf: csrfToken,
            },
        });
        const body = await resp.json();
        if (body.message && /too many/i.test(body.message)) {
            test.skip(true, 'Rate limited — skipping; run in a fresh session or wait for the rate-limit window to reset');
            return;
        }
        expect(resp.status()).toBe(400);
        expect(body).toHaveProperty('success', false);
        // Error: "Type must be 3 characters or less"
        expect(body.message).toMatch(/must be 3 characters/i);
    });
});
