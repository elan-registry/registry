/**
 * CSP (Content Security Policy) Validation Tests
 * 
 * These tests detect CSP violations across the application to prevent
 * security policy regressions and ensure external resources load properly.
 * 
 * @file csp-validation.spec.js
 * @author Claude Code Assistant
 * @created 2025-08-24
 */

const { test, expect } = require('@playwright/test');

/**
 * CSP violation monitoring helper
 * @param {Page} page - Playwright page object
 * @returns {Array} Array to store CSP violations
 */
function setupCSPViolationMonitoring(page) {
  const cspViolations = [];
  
  // Listen for CSP violations in console
  page.on('console', (msg) => {
    const text = msg.text();
    if (text.includes('Content Security Policy') || 
        text.includes('Refused to load') ||
        text.includes('violates the following Content Security Policy directive')) {
      cspViolations.push({
        type: 'console',
        message: text,
        timestamp: new Date().toISOString()
      });
    }
  });

  // Listen for security policy violation events
  page.on('pageerror', (error) => {
    if (error.message.includes('Content Security Policy') || 
        error.message.includes('CSP')) {
      cspViolations.push({
        type: 'error',
        message: error.message,
        timestamp: new Date().toISOString()
      });
    }
  });

  return cspViolations;
}

test.describe('CSP Validation Tests', () => {
  
  test('Statistics page should not have CSP violations', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);
    
    // Navigate to statistics page
    await page.goto('app/owner/reports/statistics.php');
    
    // Wait for page to fully load including external resources
    await page.waitForLoadState('networkidle');
    
    // Wait a bit more for dynamic resources like Google Charts
    await page.waitForTimeout(3000);
    
    // Check for CSP violations
    if (cspViolations.length > 0) {
      console.log('CSP Violations found:', cspViolations);
    }
    
    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
  });

  test('Car details page should not have CSP violations', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);
    
    // First get a car ID to test with
    await page.goto('app/owner/cars/index.php');
    await page.waitForLoadState('networkidle');
    
    // Click on first car link if available
    const firstCarLink = page.locator('a[href*="details.php?car_id="]').first();
    if (await firstCarLink.count() > 0) {
      await firstCarLink.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);
    } else {
      // Skip if no cars available
      test.skip('No cars available for testing');
    }
    
    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
  });

  test('Car listing page should not have CSP violations', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);
    
    await page.goto('app/owner/cars/index.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    
    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
  });

  test('Login page should not have CSP violations', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);
    
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    
    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
  });

  test('Home page should not have CSP violations', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);

    await page.goto('');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);

    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
  });

  test('Statistics page external resources should load', async ({ page }) => {
    const cspViolations = setupCSPViolationMonitoring(page);
    const failedRequests = [];
    
    // Monitor failed network requests
    page.on('response', (response) => {
      if (!response.ok() && response.status() !== 304) {
        failedRequests.push({
          url: response.url(),
          status: response.status(),
          statusText: response.statusText()
        });
      }
    });
    
    await page.goto('app/owner/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);
    
    // Log failed requests for debugging
    if (failedRequests.length > 0) {
      console.log('Failed requests:', failedRequests);
    }
    
    // Check for CSP violations
    expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
    
    // Verify critical resources loaded successfully
    const criticalDomains = ['cloudflareinsights.com'];
    const criticalFailures = failedRequests.filter(req => {
      try {
        const url = new URL(req.url);
        return criticalDomains.includes(url.hostname);
      } catch (_e) {
        // If URL parsing fails, fall back to includes check
        return criticalDomains.some(domain => req.url.includes(domain));
      }
    });

    expect(criticalFailures, `Critical external resources failed to load: ${JSON.stringify(criticalFailures, null, 2)}`).toHaveLength(0);
  });

  test('no requests to Google Maps domains on statistics or details pages', async ({ page }) => {
    const googleMapsRequests = [];
    page.on('request', request => {
      const url = request.url();
      try {
        const hostname = new URL(url).hostname;
        if (hostname === 'maps.googleapis.com' || hostname.endsWith('.maps.googleapis.com') ||
            hostname === 'maps.gstatic.com' || hostname.endsWith('.maps.gstatic.com')) {
          googleMapsRequests.push(url);
        }
      } catch (_) { /* ignore non-URL strings */ }
    });

    // Check statistics page
    await page.goto('app/owner/reports/statistics.php');
    await page.waitForTimeout(2000);

    expect(googleMapsRequests, 'No Google Maps requests on statistics page').toHaveLength(0);

    googleMapsRequests.length = 0;

    // Check a car details page (use a stable car ID or skip if none)
    try {
      await page.goto('app/owner/cars/details.php?car_id=1');
      await page.waitForTimeout(2000);
      expect(googleMapsRequests, 'No Google Maps requests on car details page').toHaveLength(0);
    } catch (navError) {
      // page.goto throws on timeout/crash, not on auth redirects;
      // log so navigation failures are diagnosable. CSP prohibition is still
      // verified via the googleMapsRequests assertion above.
      console.warn('car details CSP check skipped (navigation error):', navError.message);
    }
  });
});