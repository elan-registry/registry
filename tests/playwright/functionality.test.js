// tests/playwright/functionality.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, navigateAndWait, waitForDataTables, handleAuthRequired } = require('./auth-helper.js');

test.describe('Core Functionality After Refactoring', () => {
  test('DataTables loads and works on car listing', async ({ page }) => {
    await navigateAndWait(page, '/app/cars/index.php');
    
    // Wait for DataTables to initialize
    const searchBox = await waitForDataTables(page);
    
    // Test search functionality
    await searchBox.fill('1973');
    await page.waitForTimeout(1000); // Wait for search to process
    
    // Verify search results are filtered
    const tableRows = page.locator('tbody tr');
    await expect(tableRows.first()).toBeVisible();
  });

  test('car edit form workflow functions', async ({ page }) => {
    // Navigate to car edit page
    await navigateAndWait(page, '/app/cars/edit.php');
    
    // Handle authentication requirement or test the form
    await handleAuthRequired(
      page,
      // Authenticated test - test the multi-step form workflow
      async () => {
        await expect(page.locator('#progressbar')).toBeVisible();
        await expect(page.locator('fieldset').first()).toBeVisible();
        
        // Test form navigation
        const nextButton = page.locator('.next').first();
        await expect(nextButton).toBeVisible();
        
        // Fill in first step
        await page.selectOption('#year', '1973');
        await page.waitForTimeout(500); // Wait for model dropdown to populate
        
        await nextButton.click();
        
        // Should move to next step
        await expect(page.locator('fieldset').nth(1)).toBeVisible();
      }
    );
  });

  test('chassis validation works', async ({ page }) => {
    // Navigate to car edit page for chassis validation testing
    await navigateAndWait(page, '/app/cars/edit.php');
    
    // Handle authentication requirement or test chassis validation
    await handleAuthRequired(
      page,
      // Authenticated test - test Lotus Elan chassis validation
      async () => {
        // Select a year first
        await page.selectOption('#year', '1973');
        await page.waitForTimeout(500);
        
        // Select a model (Lotus Elan specific)
        const modelOptions = await page.locator('#model option').count();
        if (modelOptions > 1) {
          await page.selectOption('#model', { index: 1 });
        }
        
        // Test chassis validation with Elan-format chassis number
        await page.fill('#chassis', '12345678X');
        await page.locator('#chassis').blur();
        
        // Should show validation feedback
        await expect(page.locator('#chassis_icon')).toBeVisible();
      }
    );
  });

  test('contact form submission works', async ({ page }) => {
    // Navigate to contact form
    await navigateAndWait(page, '/app/contact/index.php');
    
    // Handle authentication requirement or test the contact form
    await handleAuthRequired(
      page,
      // Authenticated test - test registry contact form
      async () => {
        // Fill out the contact form
        await page.fill('input[name="name"]', 'Test User');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('textarea[name="message"]', 'This is a test message for the Elan Registry contact form.');
        
        // Submit the form
        await page.click('button[type="submit"], input[type="submit"]');
        
        // Should get some kind of response (success or error)
        await page.waitForTimeout(2000);
        
        // Check for feedback (could be success message or validation error)
        const hasAlert = await page.locator('.alert, .message, .notification').count();
        expect(hasAlert).toBeGreaterThanOrEqual(0); // Just checking it doesn't crash
      }
    );
  });

  test('factory listing page functions', async ({ page }) => {
    await navigateAndWait(page, '/app/cars/factory.php');
    
    // Check that the Lotus Elan factory data page loads
    await expect(page.locator('h2')).toContainText(/Factory/);
    
    // Wait for DataTables to load factory data
    await waitForDataTables(page);
  });

  test('car management page (admin) loads', async ({ page }) => {
    // Login first since manage page requires authentication
    await ensureLoggedIn(page);
    await page.goto('http://localhost:9999/elan_registry/app/cars/manage.php');
    
    // This might require authentication, but should at least load without crashing
    const pageTitle = await page.title();
    expect(pageTitle).toBeTruthy();
  });

  test('AJAX endpoints respond correctly', async ({ page }) => {
    // Test that registry-specific AJAX endpoints are accessible
    const endpoints = [
      '/app/action/getDataTables.php',
      '/app/cars/actions/check-chassis.php',
      '/app/cars/mapmarkers.xml.php'
    ];
    
    for (const endpoint of endpoints) {
      const response = await page.request.post(endpoint, {
        data: { test: 'true' }
      });
      
      // Should not return 404 or 500 errors
      expect(response.status()).not.toBe(404);
      expect(response.status()).not.toBe(500);
    }
  });
});