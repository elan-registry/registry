// Behavioral verification for #1430: app/admin/index.php,
// app/admin/maintenance.php, and app/admin/design-system.php set
// $pageTitle/$pageDescription before requiring users/init.php (previously
// they were set after, so the page-specific title never took effect and the
// generic site title rendered instead). This confirms the actual rendered
// <title> now reflects the page-specific title for each tab.

const { test } = require('@playwright/test');
const { ensureLoggedIn, assertPageTitle } = require('./auth-helper.js');

const ADMIN_PAGE_TITLES = [
    { path: 'app/admin/index.php', expectedTitle: 'Registry Management - Car/Owner Relationships' },
    { path: 'app/admin/index.php?tab=manage-cars', expectedTitle: 'Registry Management - Manage Cars' },
    { path: 'app/admin/maintenance.php', expectedTitle: 'Registry Maintenance' },
    // head_tags.php and the upstream header read $pageTitle and $pageDescription
    // at render time. The loader only checks $pageTitle at init and sets a
    // fallback when it is not set. This page has static values that are easy
    // to assert, so this row also checks that the description reaches the
    // head tags.
    {
        path: 'app/admin/design-system.php',
        expectedTitle: 'Color Preview — Elan Registry Token System',
        expectedDescription: 'Preview of the Elan Registry design system color tokens and UI components.',
    },
];

test.describe('Admin page titles (#1430)', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    for (const { path, ...expected } of ADMIN_PAGE_TITLES) {
        test(`${path} renders page-specific title`, async ({ page }) => {
            await page.goto(path, { waitUntil: 'networkidle' });
            await assertPageTitle(page, expected);
        });
    }
});
