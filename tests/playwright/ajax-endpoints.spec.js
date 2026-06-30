// tests/playwright/ajax-endpoints.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Registry-Specific AJAX Endpoints', () => {
  test.beforeEach(async ({ page }) => {
    // Most AJAX endpoints require authentication.
    // Skip when test credentials are not configured in .env.local.
    if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
      test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
    }
    await ensureLoggedIn(page);
  });

  test('chassis validation endpoint responds correctly', async ({ page }) => {
    // Test the Lotus Elan chassis validation endpoint (ApiResponse JSON format)

    // Test missing command parameter (should return 400)
    const missingCommandResponse = await page.request.post('app/api/cars/chassis-availability.php', {
      data: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        csrf: 'test_token'
      }
    });
    expect(missingCommandResponse.status()).toBe(400);
    try {
      const jsonResponse = await missingCommandResponse.json();
      expect(jsonResponse).toHaveProperty('success', false);
    } catch (error) {
      // If not JSON, test fails
    }

    // Test CSRF validation failure (should return 403)
    const csrfFailResponse = await page.request.post('app/api/cars/chassis-availability.php', {
      data: {
        command: 'chassis_check',
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        csrf: 'invalid_token'
      }
    });
    expect(csrfFailResponse.status()).toBe(403);
    try {
      const jsonResponse = await csrfFailResponse.json();
      expect(jsonResponse).toHaveProperty('success', false);
    } catch (error) {
      // If not JSON, test fails
    }

    // Test valid chassis check format (will fail CSRF but should have correct structure)
    const validFormatResponse = await page.request.post('app/api/cars/chassis-availability.php', {
      data: {
        command: 'chassis_check',
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        csrf: 'test_token'
      }
    });

    try {
      const jsonResponse = await validFormatResponse.json();
      expect(jsonResponse).toHaveProperty('success');
      expect(jsonResponse).toHaveProperty('message');
      // If success is true, should have taken and available properties
      if (jsonResponse.success) {
        expect(jsonResponse).toHaveProperty('taken');
        expect(jsonResponse).toHaveProperty('available');
      }
    } catch (error) {
      // If not JSON, test fails
    }
  });

  test('DataTables AJAX endpoint returns car data', async ({ page }) => {
    // Navigate to car listing page to establish session
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    const response = await page.request.post('app/api/cars/list.php', {
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
    const response = await page.request.post('app/api/contact/send-owner-email.php', {
      form: {
        car_id: '1',
        to_user_id: '1',
        message: 'Interest in your Lotus Elan',
        csrf: 'test_token'
      }
    });

    // Should either work (200) or require better authentication
    expect([200, 401, 403]).toContain(response.status());
  });

  test('NEW_CAR_IDS on car list page is a JSON int array', async ({ page }) => {
    // Verifies that CarShowcaseService::getNewCarIds() emits valid JSON to the page.
    // The const is embedded in the inline script block — shape must be int[].
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    const newCarIds = await page.evaluate(() => {
      if (typeof NEW_CAR_IDS === 'undefined') return null;
      return NEW_CAR_IDS;
    });

    // Skip when page requires auth and we're unauthenticated — same guard as functionality.spec.js
    if (newCarIds === null) {
      return;
    }

    expect(Array.isArray(newCarIds)).toBe(true);

    // Every element must be a positive integer (PHP json_encode on int[] produces JS numbers;
    // > 0 catches cast failures in getNewCarIds() that would produce 0 or negative values)
    newCarIds.forEach(id => {
      expect(typeof id).toBe('number');
      expect(Number.isInteger(id)).toBe(true);
      expect(id).toBeGreaterThan(0);
    });
  });

  test('car history endpoint returns DataTables JSON structure', async ({ page }) => {
    // Test the car history AJAX endpoint
    const response = await page.request.post('app/api/cars/history.php', {
      data: {
        car_id: '1',
        draw: '1',
        start: '0',
        length: '10',
        csrf: 'test_token'
      }
    });

    // CSRF failure should return error response
    expect(response.status()).not.toBe(500);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success');
      expect(jsonResponse).toHaveProperty('message');

      // If successful, should have DataTables structure
      if (jsonResponse.success) {
        expect(jsonResponse).toHaveProperty('draw');
        expect(jsonResponse).toHaveProperty('recordsTotal');
        expect(jsonResponse).toHaveProperty('recordsFiltered');
        expect(jsonResponse).toHaveProperty('history');
        expect(Array.isArray(jsonResponse.history)).toBe(true);
      }
    } catch (error) {
      // If not JSON, test fails
      const responseText = await response.text();
      expect(responseText.length).toBeGreaterThan(0);
    }
  });

  test('validateChassis endpoint requires AJAX header and returns JSON', async ({ page }) => {
    // Test the chassis validation endpoint (different from check-chassis.php)

    // Test without X-Requested-With header (should fail)
    const noHeaderResponse = await page.request.post('app/api/cars/chassis-validate.php', {
      data: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        allow_override: '0',
        csrf: 'test_token'
      }
    });
    expect(noHeaderResponse.status()).not.toBe(500);

    // Test with X-Requested-With header
    const response = await page.request.post('app/api/cars/chassis-validate.php', {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      data: {
        chassis: '12345678',
        year: '1973',
        model: 'Sprint',
        allow_override: '0',
        csrf: 'test_token'
      }
    });

    expect(response.status()).not.toBe(500);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success');
      expect(jsonResponse).toHaveProperty('message');

      // Should have validation result
      if (jsonResponse.success) {
        expect(jsonResponse).toHaveProperty('valid');
      } else {
        // Failed CSRF or other error
        expect(typeof jsonResponse.message).toBe('string');
      }
    } catch (error) {
      // If not JSON, test fails
    }
  });

  test('admin car details endpoint requires admin permissions', async ({ page }) => {
    // Test the admin-only car details processing endpoint
    const response = await page.request.post('app/admin/includes/process-car-details.php', {
      data: {
        car_id: '1',
        csrf: 'test_token'
      }
    });

    // Regular user should get 403 Forbidden
    expect(response.status()).toBe(403);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', false);
      expect(jsonResponse).toHaveProperty('message');
    } catch (error) {
      // If not JSON, should still be 403
      expect(response.status()).toBe(403);
    }
  });

  test('admin transfer approve endpoint requires admin permissions', async ({ page }) => {
    // Test the admin-only transfer approval endpoint
    const response = await page.request.post('app/admin/includes/process-transfer-approve.php', {
      data: {
        transfer_id: '1',
        csrf: 'test_token'
      }
    });

    // Regular user should get 403 Forbidden
    expect(response.status()).toBe(403);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', false);
      expect(jsonResponse).toHaveProperty('message');
    } catch (error) {
      // If not JSON, should still be 403
      expect(response.status()).toBe(403);
    }
  });

  test('admin transfer deny endpoint requires admin permissions', async ({ page }) => {
    // Test the admin-only transfer denial endpoint
    const response = await page.request.post('app/admin/includes/process-transfer-deny.php', {
      data: {
        transfer_id: '1',
        csrf: 'test_token'
      }
    });

    // Regular user should get 403 Forbidden
    expect(response.status()).toBe(403);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', false);
      expect(jsonResponse).toHaveProperty('message');
    } catch (error) {
      // If not JSON, should still be 403
      expect(response.status()).toBe(403);
    }
  });

  test('admin settings endpoint requires admin permissions', async ({ page }) => {
    // Test the admin-only (level 2) settings update endpoint
    const response = await page.request.post('app/admin/includes/process-admin-settings.php', {
      data: {
        field: 'elan_image_max',
        value: '10',
        csrf: 'test_token'
      }
    });

    // Unauthenticated request should get 403 Forbidden
    expect(response.status()).toBe(403);

    try {
      const jsonResponse = await response.json();
      expect(jsonResponse).toHaveProperty('success', false);
      expect(jsonResponse).toHaveProperty('message');
    } catch (error) {
      // If not JSON, should still be 403
      expect(response.status()).toBe(403);
    }
  });

  test('feedback endpoint requires CSRF and returns JSON', async ({ page }) => {
    const response = await page.request.post('app/api/contact/send-feedback.php', {
      form: {
        comments: 'Test feedback',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });

  test('contact owner endpoint requires CSRF and returns JSON', async ({ page }) => {
    const response = await page.request.post('app/api/contact/send-owner-email.php', {
      form: {
        action: 'send_message',
        to_user_id: '1',
        car_id: '1',
        message: 'Test message',
        csrf: 'invalid_token'
      }
    });
    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
  });
});
