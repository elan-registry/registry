// tests/playwright/admin-email-delivery.spec.js
//
// Coverage for the admin Email Delivery tab (#2102):
// app/admin/index.php?tab=email-delivery, rendered by
// app/admin/includes/tab-email_delivery.php.
//
// Golden path: an admin opens Registry Management, clicks the Email Delivery
// nav link, sees the plugin link, the counts line and either the table or the
// empty state, and a filter submit keeps tab=email-delivery in the URL.
// Edge: a recipient that matches no mail shows the empty state.
//
// The tab reads plg_sendinblue_messages. The local Docker database holds that
// table when the sendinblue plugin is installed. The tests need no seeded
// rows: they accept the table or the empty state, and the edge case asks for
// an address that no mail can have.
//
// Runs under the plain `chromium` project with a live admin login
// (auth-helper.js), as admin-verification-dashboard.spec.js does. Needs
// E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD in .env.local.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const ADMIN_PAGE = 'app/admin/index.php';
const TAB_URL = `${ADMIN_PAGE}?tab=email-delivery`;

const hasAdminCredentials = Boolean(process.env.E2E_DEV_ADMIN_USERNAME && process.env.E2E_DEV_ADMIN_PASSWORD);

test.describe('Admin Email Delivery tab', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!hasAdminCredentials, 'E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD are not set in .env.local');
        await ensureLoggedIn(page);
    });

    test('admin opens the tab from the nav and sees the plugin link, counts and mail or empty state', async ({ page }) => {
        await page.goto(ADMIN_PAGE, { waitUntil: 'networkidle' });

        const navLink = page.locator('a.nav-link[href="?tab=email-delivery"]');
        await expect(navLink).toHaveCount(1);
        await navLink.click();
        await page.waitForURL(/tab=email-delivery/);
        await expect(navLink).toHaveClass(/active/);

        const pluginLink = page.getByTestId('email-delivery-plugin-link');
        await expect(pluginLink).toHaveCount(1);
        await expect(pluginLink).toHaveAttribute('href', /usersc\/plugins\/sendinblue\/delivery_logs\.php$/);
        await expect(page.locator('#emailDeliveryLog')).toContainText('master accounts only');

        // The plugin table must exist on the local site, or the tab shows the
        // plugin-missing alert and this test fails on purpose.
        await expect(page.getByTestId('email-delivery-plugin-missing')).toHaveCount(0);
        await expect(page.getByTestId('email-delivery-error')).toHaveCount(0);
        await expect(page.getByTestId('email-delivery-filters')).toBeVisible();
        await expect(page.getByTestId('email-delivery-counts')).toBeVisible();
        await expect(page.getByTestId('email-delivery-counts')).toContainText('Total:');

        const tableCount = await page.getByTestId('email-delivery-table').count();
        const emptyCount = await page.getByTestId('email-delivery-empty').count();
        expect(tableCount + emptyCount, 'exactly one of the table and the empty state').toBe(1);
        await expect(page.locator('#emailDeliveryLog')).not.toContainText('%');
    });

    test('choosing an outcome and submitting keeps tab=email-delivery in the URL', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });

        await page.locator('#emailDeliveryOutcome').selectOption('delivered');
        await Promise.all([
            page.waitForURL(/outcome=delivered/),
            page.getByTestId('email-delivery-filters').getByRole('button', { name: 'Apply filters' }).click(),
        ]);

        const url = new URL(page.url());
        expect(url.searchParams.get('tab')).toBe('email-delivery');
        expect(url.searchParams.get('outcome')).toBe('delivered');
        await expect(page.locator('#emailDeliveryOutcome')).toHaveValue('delivered');
        await expect(page.getByTestId('email-delivery-counts')).toBeVisible();
    });

    test('a recipient with no mail shows the empty state', async ({ page }) => {
        const recipient = `nobody-${Math.random().toString(36).slice(2, 10)}@example.com`;
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });

        await page.locator('#emailDeliveryRecipient').fill(recipient);
        await Promise.all([
            page.waitForURL(/email=/),
            page.getByTestId('email-delivery-filters').getByRole('button', { name: 'Apply filters' }).click(),
        ]);

        await expect(page.getByTestId('email-delivery-empty')).toHaveText('No mail matches these filters');
        await expect(page.getByTestId('email-delivery-table')).toHaveCount(0);
        await expect(page.locator('#emailDeliveryRecipient')).toHaveValue(recipient);
        expect(new URL(page.url()).searchParams.get('tab')).toBe('email-delivery');
        await expect(page.locator('#emailDeliveryLog')).not.toContainText('%');
    });
});
