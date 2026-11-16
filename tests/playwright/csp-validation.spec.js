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
const { CAR_ID_STANDARD } = require('./fixtures.js');
const { assertNoGoogleMapsRequests } = require('./auth-helper.js');

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
  
  // Each row opens one page and expects zero CSP violations. `open` returns
  // after the page and its late resources (charts, maps) have loaded.
  const zeroViolationPages = [
    {
      name: 'Statistics page',
      open: async (page) => {
        await page.goto('app/owner/reports/statistics.php');
        await page.waitForLoadState('networkidle');
        // Charts load their resources after networkidle.
        await page.waitForTimeout(3000);
      },
    },
    {
      name: 'Car details page',
      open: async (page) => {
        await page.goto('app/owner/cars/index.php');
        await page.waitForLoadState('networkidle');
        const firstCarLink = page.locator('a[href*="details.php?car_id="]').first();
        test.skip(await firstCarLink.count() === 0, 'The car list has no cars in this database, so no details page can open');
        await firstCarLink.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
      },
    },
    {
      name: 'Car listing page',
      open: async (page) => {
        await page.goto('app/owner/cars/index.php');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
      },
    },
    {
      name: 'Login page',
      open: async (page) => {
        await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
      },
    },
    {
      name: 'Home page',
      open: async (page) => {
        await page.goto('');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
      },
    },
    {
      // An anonymous visit may redirect to login. The check then covers the
      // redirect target.
      name: 'Contact feedback page',
      open: async (page) => {
        await page.goto('app/owner/contact/index.php');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
      },
    },
  ];

  // A deleted row would silently drop a page from the CSP check.
  expect(zeroViolationPages.length).toBe(6);

  for (const { name, open } of zeroViolationPages) {
    test(`${name} should not have CSP violations`, async ({ page }) => {
      const cspViolations = setupCSPViolationMonitoring(page);

      await open(page);

      expect(cspViolations, `Found ${cspViolations.length} CSP violations: ${JSON.stringify(cspViolations, null, 2)}`).toHaveLength(0);
    });
  }

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

  test('script-src header must not contain unsafe-inline and must include nonce', async ({ page }) => {
    const response = await page.goto('');
    const cspHeader = (response.headers()['content-security-policy'] ?? '');
    const scriptSrc = cspHeader.match(/script-src[^;]*/)?.[0] ?? '';
    expect(scriptSrc, "CSP script-src must not contain 'unsafe-inline'").not.toContain("'unsafe-inline'");
    expect(scriptSrc, "CSP script-src must contain a nonce").toMatch(/'nonce-/);
  });

  test('nonce must rotate between requests', async ({ page }) => {
    const extractNonce = (headers) => {
      const csp = headers['content-security-policy'] ?? '';
      const match = csp.match(/'nonce-([^']+)'/);
      return match ? match[1] : null;
    };

    const r1 = await page.goto('');
    const nonce1 = extractNonce(r1.headers());

    const r2 = await page.goto('');
    const nonce2 = extractNonce(r2.headers());

    expect(nonce1, 'First response must contain a nonce').not.toBeNull();
    expect(nonce2, 'Second response must contain a nonce').not.toBeNull();
    expect(nonce1, 'Nonce must be different on each request — static nonce breaks the entire CSP strategy').not.toBe(nonce2);
  });

  test('no requests to Google Maps domains on car details page', async ({ page }) => {
    await assertNoGoogleMapsRequests(page, `app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`, 'car details page');
  });
});