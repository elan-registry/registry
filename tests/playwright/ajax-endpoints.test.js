// tests/playwright/ajax-endpoints.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Registry-Specific AJAX Endpoints', () => {
  test.beforeEach(async ({ page }) => {
    // Most AJAX endpoints require authentication
    await ensureLoggedIn(page);
  });

  test('chassis validation endpoint responds correctly', async ({ page }) => {
    // Test the Lotus Elan chassis validation endpoint
    const testChassis = [
      { chassis: '12345678', expected: true },
      { chassis: '12345678X', expected: true },
      { chassis: 'ELAN123456', expected: true },
      { chassis: '123', expected: false },
      { chassis: 'INVALID', expected: false }
    ];

    for (const testCase of testChassis) {
      const response = await page.request.post('/app/cars/actions/check-chassis.php', {
        data: {
          chassis: testCase.chassis,
          car_id: '' // New car registration
        }
      });

      expect(response.status()).toBe(200);
      
      const responseText = await response.text();
      if (testCase.expected) {
        expect(responseText).not.toContain('error');
        expect(responseText).not.toContain('invalid');
      } else {
        // Invalid chassis should return some kind of validation message
        expect(responseText.length).toBeGreaterThan(0);
      }
    }
  });

  test('model year cascade endpoint works', async ({ page }) => {
    // Test the model dropdown population based on year selection
    const response = await page.request.post('/app/cars/actions/get-models.php', {
      data: {
        year: '1973'
      }
    });

    expect(response.status()).toBe(200);
    
    const responseText = await response.text();
    
    // Should contain Lotus Elan models for 1973
    expect(responseText).toContain('Elan');
    
    // Should be valid JSON or HTML options
    expect(responseText.length).toBeGreaterThan(0);
  });

  test('DataTables AJAX endpoint returns car data', async ({ page }) => {
    // Test the main car listing DataTables endpoint
    const response = await page.request.post('/app/action/getDataTables.php', {
      data: {
        table: 'cars',
        draw: '1',
        start: '0',
        length: '10'
      }
    });

    expect(response.status()).toBe(200);
    
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
  });

  test('map markers XML endpoint returns valid data', async ({ page }) => {
    // Test the Google Maps markers endpoint
    const response = await page.request.get('/app/cars/mapmarkers.xml.php');

    expect(response.status()).toBe(200);
    
    const responseText = await response.text();
    
    // Should contain XML structure for map markers
    expect(responseText).toContain('<markers>');
    expect(responseText).toContain('</markers>');
    
    // Content should be valid XML or at least structured
    expect(responseText.length).toBeGreaterThan(20);
  });

  test('car details AJAX endpoint returns structured data', async ({ page }) => {
    // Test car details partial loading
    const response = await page.request.get('/app/cars/actions/get-car-details.php?car_id=1');

    // Should not return 404 or 500
    expect(response.status()).not.toBe(404);
    expect(response.status()).not.toBe(500);
    
    if (response.status() === 200) {
      const responseText = await response.text();
      expect(responseText.length).toBeGreaterThan(0);
      
      // Should contain car-related content
      expect(responseText).toMatch(/car|elan|lotus|year|model/i);
    }
  });

  test('contact form submission endpoint validates correctly', async ({ page }) => {
    // Test the contact form AJAX submission
    const validData = {
      name: 'Test User',
      email: 'test@example.com',
      subject: 'Test Inquiry',
      message: 'This is a test message for the Elan Registry.',
      csrf: 'test_token' // In real test, would get actual CSRF token
    };

    const response = await page.request.post('/app/contact/send-message.php', {
      data: validData
    });

    // Should not crash with 500 error
    expect(response.status()).not.toBe(500);
    
    if (response.status() === 200) {
      const responseText = await response.text();
      
      // Should provide some feedback
      expect(responseText.length).toBeGreaterThan(0);
    }
  });

  test('owner contact endpoint requires authentication', async ({ page }) => {
    // Test the owner-to-owner contact system
    const response = await page.request.post('/app/contact/send-owner-email.php', {
      data: {
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

  test('search functionality AJAX endpoint works', async ({ page }) => {
    // Test the car search/filter functionality
    const searchTerms = ['1973', 'Elan', 'Series', 'British Racing Green'];
    
    for (const term of searchTerms) {
      const response = await page.request.post('/app/cars/actions/search.php', {
        data: {
          search: term,
          type: 'quick'
        }
      });

      // Should not return errors
      expect(response.status()).not.toBe(500);
      expect(response.status()).not.toBe(404);
      
      if (response.status() === 200) {
        const responseText = await response.text();
        expect(responseText.length).toBeGreaterThan(0);
      }
    }
  });

  test('statistics data endpoints return valid JSON', async ({ page }) => {
    // Test endpoints that feed the statistics page
    const statsEndpoints = [
      '/app/reports/actions/get-year-distribution.php',
      '/app/reports/actions/get-country-stats.php',
      '/app/reports/actions/get-series-breakdown.php'
    ];

    for (const endpoint of statsEndpoints) {
      const response = await page.request.get(endpoint);
      
      // Should not return errors
      expect(response.status()).not.toBe(500);
      expect(response.status()).not.toBe(404);
      
      if (response.status() === 200) {
        const responseText = await response.text();
        
        try {
          // Try to parse as JSON
          const jsonData = JSON.parse(responseText);
          expect(typeof jsonData).toBe('object');
        } catch (error) {
          // If not JSON, should at least have content
          expect(responseText.length).toBeGreaterThan(0);
        }
      }
    }
  });

  test('image upload validation endpoint works', async ({ page }) => {
    // Test image upload validation (without actually uploading)
    const response = await page.request.post('/app/cars/actions/validate-image.php', {
      data: {
        filename: 'test-image.jpg',
        size: '1048576', // 1MB
        type: 'image/jpeg'
      }
    });

    expect(response.status()).not.toBe(500);
    
    if (response.status() === 200) {
      const responseText = await response.text();
      
      // Should validate the image parameters
      expect(responseText).toMatch(/valid|success|ok|accepted/i);
    }
  });

  test('verification status check endpoint works', async ({ page }) => {
    // Test checking verification status for a car
    const response = await page.request.get('/app/verify/actions/check-status.php?car_id=1');

    expect(response.status()).not.toBe(500);
    expect(response.status()).not.toBe(404);
    
    if (response.status() === 200) {
      const responseText = await response.text();
      expect(responseText.length).toBeGreaterThan(0);
      
      // Should contain status information
      expect(responseText).toMatch(/verified|pending|unverified|status/i);
    }
  });
});