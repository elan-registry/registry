// Behavioral verification for #1430: app/admin/index.php,
// app/admin/maintenance.php, and app/admin/design-system.php set
// $pageTitle/$pageDescription before requiring users/init.php (previously
// they were set after, so the page-specific title never took effect and the
// generic site title rendered instead). This confirms the actual rendered
// <title> now reflects the page-specific title for each tab.

const { test } = require('@playwright/test');
const { ensureLoggedIn, assertPageTitle } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Area 1: index.php — Registry Management tabs
// ---------------------------------------------------------------------------

test.describe('Admin page titles — index (#1430)', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('default tab (car-mgmt) renders page-specific title', async ({ page }) => {
        await page.goto('app/admin/index.php', { waitUntil: 'networkidle' });
        await assertPageTitle(page, { expectedTitle: 'Registry Management - Car/Owner Relationships' });
    });

    test('manage-cars tab renders page-specific title', async ({ page }) => {
        await page.goto('app/admin/index.php?tab=manage-cars', { waitUntil: 'networkidle' });
        await assertPageTitle(page, { expectedTitle: 'Registry Management - Manage Cars' });
    });
});

// ---------------------------------------------------------------------------
// Area 2: maintenance.php — single page, no tabs (#1225)
// ---------------------------------------------------------------------------

test.describe('Admin page titles — maintenance (#1430)', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('renders static page title', async ({ page }) => {
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
        await assertPageTitle(page, { expectedTitle: 'Registry Maintenance' });
    });
});

// ---------------------------------------------------------------------------
// Area 3: design-system.php — static page-specific title
// ---------------------------------------------------------------------------

test.describe('Admin page titles — design system (#1430)', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('renders page-specific title and description', async ({ page }) => {
        await page.goto('app/admin/design-system.php', { waitUntil: 'networkidle' });

        // head_tags.php and the upstream header read $pageTitle and $pageDescription
        // at render time. The loader only checks $pageTitle at init and sets a
        // fallback when it is not set. This page has static values that are easy
        // to assert, so the test checks that both values reach the head tags.
        await assertPageTitle(page, {
            expectedTitle: 'Color Preview — Elan Registry Token System',
            expectedDescription: 'Preview of the Elan Registry design system color tokens and UI components.',
        });
    });
});
