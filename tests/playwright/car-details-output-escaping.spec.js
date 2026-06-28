// tests/playwright/car-details-output-escaping.test.js
//
// Regression test for issue #840: missing htmlspecialchars() on car detail fields.
//
// Verifies that car field display areas in details.php and usersc/account.php
// render as plain text without raw HTML characters that would indicate unescaped
// output. These fields were "accidentally safe" before the encode-at-output reform
// because Input::sanitize() pre-encoded values at storage time.
//
// Requires local MAMP at http://localhost:9999/elan-registry

const { test, expect } = require('@playwright/test');

const DETAILS_URL = 'app/cars/details.php?car_id=1091';

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
        const websiteLinks = page.locator('a:has-text("Visit Website")');
        const count = await websiteLinks.count();

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
