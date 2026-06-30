// tests/playwright/admin-escapeHtml.test.js
//
// Tests for escapeHtml() XSS-prevention helper in manage-consolidated.js.
// Verifies that user-supplied content is safely escaped for DOM insertion.
// The function is loaded globally on /app/admin/index.php and
// is exercised here via page.evaluate() with a battery of XSS vectors.
//
// Requires local MAMP at http://localhost:9999/elan-registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('escapeHtml() — XSS prevention', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/index.php?tab=car-mgmt', { waitUntil: 'networkidle' });
    });

    test('escapes <script> tags', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('<script>alert(1)</script>'));
        expect(result).toContain('&lt;script&gt;');
        expect(result).toContain('&lt;/script&gt;');
    });

    test('escapes double quotes', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('"'));
        expect(result).toBe('&quot;');
    });

    test('escapes single quotes', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml("'"));
        expect(result).toBe('&#039;');
    });

    test('escapes ampersands', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('&'));
        expect(result).toBe('&amp;');
    });

    test('escapes greater-than character', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('>'));
        expect(result).toBe('&gt;');
    });

    test('escapes combined attribute-injection vector', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('<img src=x onerror="alert(\'xss\')">'));
        expect(result).not.toContain('<img');
        expect(result).not.toContain('"');
        expect(result).not.toContain("'");
        expect(result).toContain('&lt;img');
        expect(result).toContain('&quot;');
        expect(result).toContain('&#039;');
        expect(result).toContain('&gt;');
    });

    test('passes non-string values through unchanged', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml(42));
        expect(result).toBe(42);
    });

    test('double-escapes already-escaped strings (idempotency check)', async ({ page }) => {
        const result = await page.evaluate(() => escapeHtml('&lt;b&gt;'));
        expect(result).toBe('&amp;lt;b&amp;gt;');
    });
});
