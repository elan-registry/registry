// tests/playwright/admin-fix-script-close-button.spec.js
//
// Regression test for issue #1777: admin_script_close_button() (shared by all
// fix/maintenance scripts via app/admin/includes/fix-script-core.php) must
// call window.close() unconditionally rather than gating on window.opener.
// Modern browsers set an implicit noopener on target="_blank" links, so an
// opener check silently no-ops the button — the popup never closes.
//
// This test reproduces the real launch path: click the "Run Script" link for
// script #25 (Cleanup Rate Limits, the simplest live maintenance script) on
// the admin maintenance tab, capturing the resulting target="_blank" popup as
// its own Playwright Page via context.waitForEvent('page'). It then clicks
// the Close Window button on that popup and asserts the popup page actually
// closes.
//
// Script #25's close button renders on the initial GET/landing view (outside
// the $is_exec conditional in 25-Cleanup-Rate-Limits.php), so this test never
// submits the POST+CSRF execute form and never runs the actual rate-limit
// cleanup logic.
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Admin fix-script Close Window button', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('Close Window button closes the popup rather than navigating it', async ({ page, context }) => {
        const runScriptLink = page.locator('a[href*="25-Cleanup-Rate-Limits.php"]');
        await expect(runScriptLink).toBeAttached();

        const [popup] = await Promise.all([
            context.waitForEvent('page'),
            runScriptLink.click(),
        ]);

        await popup.waitForLoadState('networkidle');

        const closeButton = popup.locator('[data-action="adminScriptClose"]');
        await expect(closeButton).toBeVisible();

        await Promise.all([
            popup.waitForEvent('close', { timeout: 5000 }),
            closeButton.click(),
        ]);
        expect(popup.isClosed()).toBe(true);

        // Original tab must remain on the maintenance page — the popup closing
        // should have no effect on the opener's location.
        expect(page.url()).toContain('maintenance.php');
    });
});
