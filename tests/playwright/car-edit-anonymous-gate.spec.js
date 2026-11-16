// edit.php must send an anonymous visitor to login before it reads or handles
// any car data.

const { test, expect } = require('@playwright/test');
const { CAR_ID_STANDARD, CAR_ID_NONEXISTENT } = require('./fixtures.js');

test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Car edit page — anonymous access gate', () => {
    test('GET as an anonymous visitor redirects to login and shows no edit form', async ({ page }) => {
        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

        // securePage() sends the visitor to users/login.php, which redirects
        // again to the project login page at usersc/login.php.
        expect(page.url()).toMatch(/\/usersc\/login\.php$/);
        await expect(page.locator('#editCar')).toHaveCount(0);
        await expect(page.locator('input[name="password"]')).toBeVisible();
    });

    test('POST as an anonymous visitor gets a redirect to login', async ({ request }) => {
        // This proves the redirect only. It does not prove that no write
        // happened: the car id does not exist, so no write is possible.
        const response = await request.post(`app/owner/cars/edit.php?car_id=${CAR_ID_NONEXISTENT}`, {
            form: { car_id: String(CAR_ID_NONEXISTENT) },
            maxRedirects: 0,
        });

        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toMatch(/users\/login\.php$/);
    });
});
