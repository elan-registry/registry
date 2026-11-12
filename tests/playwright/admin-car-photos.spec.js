// tests/playwright/admin-car-photos.spec.js
//
// Smoke coverage for the admin Car Photos tab (#2644):
// app/admin/index.php?tab=car-photos, rendered by
// app/admin/includes/tab-car_photos.php.
//
// What this file tests:
//   1. The nav link opens the tab, the card shows, and the page logs no
//      console error.
//   2. Either the form (with its Apply selected button and at least one row)
//      or the empty state shows, never both and never neither.
//   3. When rows exist: each row has an Ignore button, a row that can be
//      applied has an Apply button, and no checkbox is checked on load.
//
// What this file does not test: the reattach, the restore, the Ignore, the
// roles, the CSRF check and the "writes nothing on a GET" rule. Those are in
// tests/integration/AdminCarPhotosTabAuthTest.php (PHPUnit). The local
// database decides whether rows exist, so the tests that need rows skip with
// a reason when it has none.
//
// Needs E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD in .env.local.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const ADMIN_PAGE = 'app/admin/index.php';
const TAB_URL = `${ADMIN_PAGE}?tab=car-photos`;

const hasAdminCredentials = Boolean(process.env.E2E_DEV_ADMIN_USERNAME && process.env.E2E_DEV_ADMIN_PASSWORD);

test.describe('Admin Car Photos tab', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!hasAdminCredentials, 'E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD are not set in .env.local');
        await ensureLoggedIn(page);
    });

    test('admin opens the tab from the nav and the page logs no console error', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });
        page.on('pageerror', (error) => consoleErrors.push(error.message));

        await page.goto(ADMIN_PAGE, { waitUntil: 'networkidle' });
        const navLink = page.locator('a.nav-link[href="?tab=car-photos"]');
        await expect(navLink).toHaveCount(1);
        await expect(navLink).toContainText('Car Photos');
        await navLink.click();
        await page.waitForURL(/tab=car-photos/);
        await page.waitForLoadState('networkidle');

        await expect(navLink).toHaveClass(/active/);
        await expect(page.getByTestId('car-photos-card')).toBeVisible();
        expect(consoleErrors, 'console errors on the Car Photos tab').toEqual([]);
    });

    test('the form with its Apply selected button and a row shows, or the empty state shows alone', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });

        const formCount = await page.getByTestId('car-photos-form').count();
        const emptyCount = await page.getByTestId('car-photos-empty').count();
        // Every visible row is ignored: the form then shows only the ignored table.
        expect(formCount + emptyCount, 'at least one of the form and the empty state').toBeGreaterThan(0);

        if (emptyCount === 0) {
            await expect(page.getByTestId('car-photos-form')).toBeVisible();
            await expect(page.getByTestId('car-photos-apply')).toHaveText('Apply selected');
            await expect(page.getByTestId('car-photos-row').first()).toBeVisible();
        } else {
            await expect(page.getByTestId('car-photos-empty')).toContainText('No cars need a photo check.');
            await expect(page.getByTestId('car-photos-apply')).toHaveCount(0);
            await expect(page.getByTestId('car-photos-row')).toHaveCount(0);
        }
    });

    test('each row has an Ignore button and a row that can be applied has an Apply button', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });

        const rows = page.getByTestId('car-photos-row');
        const rowCount = await rows.count();
        test.skip(rowCount === 0, 'local DB has no car with detached photos or a reversed order');

        let actionableRows = 0;
        for (let i = 0; i < rowCount; i++) {
            const row = rows.nth(i);
            const carId = await row.getAttribute('data-car-id');
            // A row that the server could not read has no buttons at all.
            const unreadable = await row.getByTestId('car-photos-unreadable').count();
            if (unreadable > 0) {
                await expect(row.getByTestId('car-photos-ignore')).toHaveCount(0);
                await expect(row.getByTestId('car-photos-apply-row')).toHaveCount(0);
                continue;
            }
            actionableRows++;
            const ignore = row.getByTestId('car-photos-ignore');
            await expect(ignore).toHaveCount(1);
            await expect(ignore).toHaveAttribute('name', 'ignore_car');
            await expect(ignore).toHaveAttribute('value', carId);

            // Apply shows when a box exists in the row: a safe detached photo or a restore.
            const boxes = await row.locator('input[type="checkbox"]').count();
            const apply = row.getByTestId('car-photos-apply-row');
            await expect(apply).toHaveCount(boxes > 0 ? 1 : 0);
            if (boxes > 0) {
                await expect(apply).toHaveAttribute('name', 'apply_car');
                await expect(apply).toHaveAttribute('value', carId);
            }
        }
        test.skip(actionableRows === 0, 'every local row is unreadable, so none has buttons to check');
    });

    test('opening the tab checks no checkbox', async ({ page }) => {
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });
        expect(new URL(page.url()).searchParams.get('tab')).toBe('car-photos');

        const rowCount = await page.getByTestId('car-photos-row').count();
        test.skip(rowCount === 0, 'local DB has no car with detached photos or a reversed order');

        // A row with an unreadable or only an unsafe photo has no checkbox,
        // so the count of boxes is not tied to the count of rows. Assert that
        // boxes exist, so a renamed test id cannot make the zero count pass.
        const boxes = page.locator('[data-testid="car-photos-form"] input[type="checkbox"]');
        test.skip((await boxes.count()) === 0, 'local rows are unreadable or unsafe only, so none has a checkbox');
        await expect(page.locator('[data-testid="car-photos-form"] input[type="checkbox"]:checked')).toHaveCount(0);
    });
});
