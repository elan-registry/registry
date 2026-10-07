// "Verification & Email" card on users/admin.php?view=user (#1924). Seeds a
// bounced owner and a clean owner, so both the warning and the empty state
// are checked. The seed fixture refuses to run unless US_ENVIRONMENT=development.

const { test, expect } = require('@playwright/test');
const { runPhpFixture } = require('./fixture-runner.js');
const { ensureLoggedIn } = require('./auth-helper.js');

let bouncedUserId;
let cleanUserId;

test.beforeAll(() => {
    // The fixture needs the database, which is reachable only in the app container.
    const output = runPhpFixture('tests/playwright/local/fixtures/seed-bounced-car.php');

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

        // Scope to the card: UserSpice renders an unrelated .alert-warning on this page.
        const heading = page.getByRole('heading', { name: 'Verification & Email', level: 6 });
        await expect(heading).toBeVisible();
        const card = page.locator('div.card', { has: heading });

        const summary = card.locator('.alert-warning');
        await expect(summary).toBeVisible();
        await expect(summary).toContainText('1 car bounced,');
        await expect(summary).toContainText('0 suppressed');

        const bouncedBadge = card.locator('span.badge.text-bg-danger', { hasText: 'Bounced' });
        await expect(bouncedBadge).toBeVisible();
        await expect(card.locator('td', { hasText: 'bounced@example.invalid' })).toBeVisible();

        await expect(card.locator('.alert-primary', { hasText: 'No delivery problems recorded' })).toHaveCount(0);
    });

    test('renders the empty state for an owner with no delivery problems', async ({ page }) => {
        await page.goto(`users/admin.php?view=user&id=${cleanUserId}`, { waitUntil: 'networkidle' });

        const heading = page.getByRole('heading', { name: 'Verification & Email', level: 6 });
        await expect(heading).toBeVisible();
        const card = page.locator('div.card', { has: heading });

        const emptyState = card.locator('.alert-primary', { hasText: 'No delivery problems recorded' });
        await expect(emptyState).toBeVisible();

        await expect(card.locator('.alert-warning')).toHaveCount(0);

        await expect(card.locator('span.badge.text-bg-danger', { hasText: 'Bounced' })).toHaveCount(0);
        await expect(card.locator('span.badge.text-bg-warning', { hasText: 'Suppressed' })).toHaveCount(0);
    });
});
