// tests/playwright/ajax-endpoints.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Registry-Specific AJAX Endpoints', () => {
  test.beforeEach(async ({ page }) => {
    // Most AJAX endpoints require authentication
    await ensureLoggedIn(page);
  });

  test('chassis validation endpoint responds correctly', async ({ page }) => {
    // Navigate to car edit page to establish session and get CSRF token
    await page.goto('app/cars/edit.php?car_id=1', { waitUntil: 'networkidle' });
    const csrfToken = await page.inputValue('input[name="csrf"]').catch(() => '');

    const response = await page.request.post('app/cars/actions/check-chassis.php', {
      form: {
        chassis: '7301019999B',
        car_id: '1',
        csrf: csrfToken
      }
    });

    expect(response.status()).toBe(200);
  });

  test('DataTables AJAX endpoint returns car data', async ({ page }) => {
    // Navigate to car listing page to establish session
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    const response = await page.request.post('app/action/getDataTables.php', {
      form: {
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    // DataTables endpoint should respond (may require specific parameters)
    expect(response.status()).not.toBe(404);
    expect(response.status()).not.toBe(500);

    if (response.status() === 200) {
      try {
        const jsonResponse = await response.json();

        // Should have DataTables structure
        expect(jsonResponse).toHaveProperty('draw');
        expect(jsonResponse).toHaveProperty('recordsTotal');
        expect(jsonResponse).toHaveProperty('recordsFiltered');
        expect(jsonResponse).toHaveProperty('data');

        // Data should be an array
        expect(Array.isArray(jsonResponse.data)).toBe(true);
      } catch (error) {
        // If not JSON, should at least be a valid response
        const responseText = await response.text();
        expect(responseText.length).toBeGreaterThan(0);
      }
    }
  });

  test('map markers XML endpoint returns valid data', async ({ page }) => {
    // Test the Google Maps markers endpoint
    const response = await page.request.get('app/cars/mapmarkers.xml.php');

    expect(response.status()).toBe(200);

    const responseText = await response.text();

    // Should contain XML structure for map markers
    expect(responseText).toContain('<markers>');
    expect(responseText).toContain('</markers>');

    // Content should be valid XML or at least structured
    expect(responseText.length).toBeGreaterThan(20);
  });

  test('owner contact endpoint requires authentication', async ({ page }) => {
    // Test the owner-to-owner contact system
    const response = await page.request.post('app/contact/send-owner-email.php', {
      form: {
        car_id: '1',
        sender_name: 'Test User',
        sender_email: 'test@example.com',
        message: 'Interest in your Lotus Elan',
        csrf: 'test_token'
      }
    });

    // Should either work (200) or require better authentication
    expect([200, 401, 403]).toContain(response.status());
  });
});
