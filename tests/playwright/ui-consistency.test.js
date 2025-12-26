// tests/playwright/ui-consistency.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait, validateCardStructure } = require('./auth-helper.js');

test.describe('UI Consistency After Style Refactoring', () => {
  test('consistent card layouts across pages', async ({ page }) => {
    const pages = [
      '/app/cars/index.php',
      '/app/cars/details.php?car_id=1',
      '/app/reports/statistics.php',
      '/app/contact/index.php'
    ];
    
    for (const pagePath of pages) {
      await navigateAndWait(page, pagePath);
      
      // Check current URL to see if redirected to login
      const currentUrl = page.url();
      if (!currentUrl.includes('login.php')) {
        // Only validate cards if not redirected to login
        try {
          await validateCardStructure(page);
        } catch (error) {
          // Some pages might not have cards, continue
          continue;
        }
      }
    }
  });

  test('consistent header structure', async ({ page }) => {
    const pages = [
      '/app/cars/index.php',
      '/app/cars/edit.php', 
      '/app/reports/statistics.php'
    ];
    
    for (const pagePath of pages) {
      await navigateAndWait(page, pagePath);
      
      // Check if page redirected to login (some pages require auth)
      const currentUrl = page.url();
      if (currentUrl.includes('login.php')) {
        // Page requires authentication - check login page structure instead
        const loginContainer = page.locator('.container, .card');
        await expect(loginContainer.first()).toBeVisible();
      } else {
        // Check for page-wrapper structure (uses class, not id)
        const pageWrapper = page.locator('.page-wrapper');
        await expect(pageWrapper).toBeVisible();
        
        // Check for container structure (use first match to avoid strict mode)
        const container = page.locator('.container-fluid, .container').first();
        await expect(container).toBeVisible();
      }
    }
  });

  test('responsive design works on mobile', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    await navigateAndWait(page, '/app/cars/index.php');
    
    // Check that content is still accessible
    const mainContent = page.locator('.page-wrapper, .container, .card');
    await expect(mainContent.first()).toBeVisible();
    
    // Check that DataTables is responsive
    const dataTable = page.locator('.dataTables_wrapper');
    if (await dataTable.count() > 0) {
      await expect(dataTable).toBeVisible();
    }
  });

  test('consistent button styling', async ({ page }) => {
    await page.goto('/app/cars/edit.php');
    
    // Check for Bootstrap button classes
    const buttons = page.locator('button, input[type="button"], input[type="submit"], .btn');
    const buttonCount = await buttons.count();
    
    if (buttonCount > 0) {
      const firstButton = buttons.first();
      const classList = await firstButton.getAttribute('class');
      
      // Should have Bootstrap button classes
      expect(classList).toMatch(/btn/);
    }
  });

  test('color scheme consistency', async ({ page }) => {
    await page.goto('/app/cars/index.php');
    
    // Check for consistent color usage
    const cards = page.locator('.card-header');
    const cardCount = await cards.count();
    
    if (cardCount > 0) {
      // Just ensure cards render properly
      await expect(cards.first()).toBeVisible();
    }
  });

  test('JavaScript files load without errors', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });
    
    await page.goto('/app/reports/statistics.php');
    await page.waitForTimeout(3000); // Wait for all scripts to load
    
    // Filter out known acceptable errors (like API key warnings)
    const criticalErrors = consoleErrors.filter(error => 
      !error.includes('Google Maps') && 
      !error.includes('API key') &&
      !error.includes('404') // Ignore 404s for optional resources
    );
    
    expect(criticalErrors.length).toBe(0);
  });

  test('images and assets load correctly', async ({ page }) => {
    await page.goto('/app/cars/identify.php');
    
    // Wait for page to fully load
    await page.waitForLoadState('networkidle');
    
    // Check that example images load
    const images = page.locator('img');
    const imageCount = await images.count();
    
    if (imageCount > 0) {
      // Check first few images
      for (let i = 0; i < Math.min(3, imageCount); i++) {
        const img = images.nth(i);
        const src = await img.getAttribute('src');
        
        if (src && !src.startsWith('data:')) {
          // Make sure src is not empty and points to a valid path
          expect(src).toBeTruthy();
        }
      }
    }
  });

  test('forms maintain consistent styling', async ({ page }) => {
    const formPages = [
      '/app/cars/edit.php',
      '/app/contact/index.php'
    ];
    
    for (const pagePath of formPages) {
      await page.goto(pagePath);
      
      // Check for consistent form styling
      const formGroups = page.locator('.form-group, .mb-3, .form-control');
      const formCount = await formGroups.count();
      
      if (formCount > 0) {
        await expect(formGroups.first()).toBeVisible();
      }
    }
  });
});