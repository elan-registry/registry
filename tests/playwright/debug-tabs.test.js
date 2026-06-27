// tests/playwright/debug-tabs.test.js
// Debug test to examine tab display issue in admin console

const { test, expect } = require('@playwright/test');
const { navigateAndWait, handleAuthRequired } = require('./auth-helper');

test.describe('Admin Consolidated Tabs Debug', () => {
  test('Debug tab display issues on manage-consolidated.php', async ({ page }) => {
    console.log('Starting tab debug test...');

    // Set up console error tracking
    const consoleErrors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
        console.log('Console error:', msg.text());
      }
    });

    // Set up network error tracking
    const networkErrors = [];
    page.on('requestfailed', request => {
      networkErrors.push(`${request.method()} ${request.url()} - ${request.failure()?.errorText}`);
      console.log('Network error:', request.url(), request.failure()?.errorText);
    });

    // Navigate to the admin page - use full URL to bypass baseURL issues
    await page.goto('app/admin/manage-consolidated.php');
    await page.waitForLoadState('networkidle');

    // Take initial screenshot regardless of auth state
    await page.screenshot({ path: 'test-results/admin-tabs-initial.png', fullPage: true });
    console.log('Initial screenshot taken');

    // Check what we actually got
    const pageContent = await page.content();
    const pageTitle = await page.title();
    console.log('Page title:', pageTitle);

    if (pageTitle === '404 Not Found') {
      console.log('Got 404 error - checking URL and path');
      const currentUrl = page.url();
      console.log('Current URL:', currentUrl);

      // Try alternate paths
      const alternatePaths = [
        'http://localhost:9999/elan-registry/app/admin/manage-consolidated.php',
        'http://localhost:9999/elan-registry/admin/manage-consolidated.php',
        'http://localhost:9999/elan-registry/manage-consolidated.php'
      ];

      for (const path of alternatePaths) {
        try {
          console.log(`Trying path: ${path}`);
          await page.goto(path);
          const title = await page.title();
          const url = page.url();
          console.log(`Path ${path} - Title: ${title}, URL: ${url}`);
          if (title !== '404 Not Found') {
            console.log(`Success with path: ${path}`);
            break;
          }
        } catch (error) {
          console.log(`Error with path ${path}:`, error.message);
        }
      }
    }

    // Handle authentication if required, or just analyze what we have
    await handleAuthRequired(page, async () => {
      console.log('User authenticated, examining page...');

      // Wait for page to fully load
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000); // Allow JS to execute

      // Look for tab structure in DOM
      console.log('Examining tab structure...');

      // Check for Bootstrap tab navigation
      const tabNavs = await page.locator('.nav-tabs, .nav, [role="tablist"]').count();
      console.log(`Found ${tabNavs} tab navigation elements`);

      // Check for tab content containers
      const tabContents = await page.locator('.tab-content, [role="tabpanel"]').count();
      console.log(`Found ${tabContents} tab content containers`);

      // Check for individual tabs
      const tabs = await page.locator('.nav-link, [role="tab"]').count();
      console.log(`Found ${tabs} individual tabs`);

      // Look for specific tab-related elements mentioned in recent commits
      const carMgmtElements = await page.locator('[id*="car"], [class*="car"]').count();
      console.log(`Found ${carMgmtElements} car management related elements`);

      // Check if JavaScript files are loaded
      const scriptTags = await page.locator('script[src]').count();
      console.log(`Found ${scriptTags} external script tags`);

      // Check for manage-consolidated.js specifically
      const consolidatedJs = await page.locator('script[src*="manage-consolidated"]').count();
      console.log(`Found ${consolidatedJs} manage-consolidated.js script references`);

      // Check for Bootstrap JS
      const bootstrapJs = await page.locator('script[src*="bootstrap"]').count();
      console.log(`Found ${bootstrapJs} Bootstrap JS references`);

      // Check for jQuery
      const jqueryJs = await page.locator('script[src*="jquery"]').count();
      console.log(`Found ${jqueryJs} jQuery references`);

      // Take screenshot showing current state
      await page.screenshot({ path: 'test-results/admin-tabs-analysis.png', fullPage: true });

      // Try to find and highlight any hidden tabs
      await page.evaluate(() => {
        // Look for elements that might be hidden tabs
        const allElements = document.querySelectorAll('*');
        let hiddenTabCount = 0;

        allElements.forEach(el => {
          const style = window.getComputedStyle(el);
          const classes = el.className || '';
          const id = el.id || '';

          // Check if element looks like it should be a tab but is hidden
          if ((classes.includes('tab') || classes.includes('nav') || id.includes('tab'))
              && (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0')) {
            console.log('Found potentially hidden tab element:', el.tagName, classes, id, 'Display:', style.display, 'Visibility:', style.visibility, 'Opacity:', style.opacity);
            hiddenTabCount++;

            // Add a bright red border to highlight it
            el.style.border = '3px solid red';
            el.style.display = 'block';
            el.style.visibility = 'visible';
            el.style.opacity = '1';
          }
        });

        console.log(`Found and highlighted ${hiddenTabCount} potentially hidden tab elements`);
        return hiddenTabCount;
      });

      // Take final screenshot with highlighted elements
      await page.screenshot({ path: 'test-results/admin-tabs-highlighted.png', fullPage: true });

    }, async () => {
      console.log('Authentication required - user not logged in');

      // Even if not authenticated, let's check what we can see
      const bodyText = await page.textContent('body');
      console.log('Page body text (first 500 chars):', bodyText?.substring(0, 500));

      await page.screenshot({ path: 'test-results/admin-tabs-auth-required.png', fullPage: true });

      // Check if there are any tabs visible even without authentication
      const tabNavs = await page.locator('.nav-tabs, .nav, [role="tablist"]').count();
      const tabs = await page.locator('.nav-link, [role="tab"]').count();
      console.log(`Without auth - Found ${tabNavs} tab navigation elements, ${tabs} individual tabs`);
    });

    // Log all findings
    console.log(`Console errors found: ${consoleErrors.length}`);
    consoleErrors.forEach((error, index) => {
      console.log(`  ${index + 1}. ${error}`);
    });

    console.log(`Network errors found: ${networkErrors.length}`);
    networkErrors.forEach((error, index) => {
      console.log(`  ${index + 1}. ${error}`);
    });
  });
});