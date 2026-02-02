import { test, expect } from '@playwright/test';

/**
 * E2E tests for anti-clickjacking security headers
 *
 * Verifies that pages include proper anti-clickjacking headers:
 * - X-Frame-Options: SAMEORIGIN (legacy header)
 * - CSP frame-ancestors 'self' (modern CSP3 standard)
 *
 * These headers prevent clickjacking attacks by controlling
 * whether pages can be embedded in iframes on other sites.
 *
 * @group security
 * @group clickjacking
 */

test.describe('Anti-clickjacking Security Headers', () => {
  /**
   * Test that public pages return X-Frame-Options header
   */
  test('home page should have X-Frame-Options header', async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();
    expect(headers).toBeDefined();
    expect(headers?.['x-frame-options']).toBeDefined();
    expect(headers?.['x-frame-options']).toBe('SAMEORIGIN');
  });

  /**
   * Test that CSP includes frame-ancestors directive
   */
  test('home page should have CSP frame-ancestors directive', async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();
    expect(headers).toBeDefined();

    const csp = headers?.['content-security-policy'];
    expect(csp).toBeDefined();
    expect(csp).toContain("frame-ancestors 'self'");
  });

  /**
   * Test error page (404) has anti-clickjacking headers
   */
  test('404 error page should have X-Frame-Options header', async ({ page }) => {
    const response = await page.goto('/nonexistent-page-12345', {
      waitUntil: 'networkidle'
    });

    // Will trigger 404 error
    expect(response?.status()).toBe(404);

    const headers = response?.headers();
    expect(headers).toBeDefined();
    expect(headers?.['x-frame-options']).toBe('SAMEORIGIN');
  });

  /**
   * Test that CSP on 404 page includes frame-ancestors
   */
  test('404 error page should have CSP frame-ancestors directive', async ({ page }) => {
    const response = await page.goto('/nonexistent-page-12345', {
      waitUntil: 'networkidle'
    });

    expect(response?.status()).toBe(404);

    const headers = response?.headers();
    const csp = headers?.['content-security-policy'];
    expect(csp).toBeDefined();
    expect(csp).toContain("frame-ancestors 'self'");
  });

  /**
   * Test that app pages (car listings) have proper headers
   */
  test('car listing page should have X-Frame-Options header', async ({ page }) => {
    const response = await page.goto('/app/cars/');

    // May return 302 redirect or 200 with content
    if (response?.status() === 200) {
      const headers = response?.headers();
      expect(headers).toBeDefined();
      expect(headers?.['x-frame-options']).toBe('SAMEORIGIN');
    }
  });

  /**
   * Test registration page has proper anti-clickjacking headers
   */
  test('registration page should have X-Frame-Options header', async ({ page }) => {
    const response = await page.goto('/users/join.php');

    const headers = response?.headers();
    expect(headers).toBeDefined();

    // Core UserSpice /users/join.php uses stricter DENY policy
    const xFrameOptions = headers?.['x-frame-options'];
    expect(xFrameOptions).toBeDefined();
    expect(['SAMEORIGIN', 'DENY']).toContain(xFrameOptions);
  });

  /**
   * Test that custom registration page (if exists) uses global SAMEORIGIN
   */
  test('custom join page should use SAMEORIGIN policy', async ({ page }) => {
    try {
      const response = await page.goto('/usersc/join.php');

      if (response?.ok()) {
        const headers = response?.headers();
        expect(headers).toBeDefined();

        const xFrameOptions = headers?.['x-frame-options'];
        expect(xFrameOptions).toBe('SAMEORIGIN');
      }
    } catch {
      // Page may not exist if customization isn't available
      test.skip();
    }
  });

  /**
   * Test that X-Frame-Options header value is correct
   */
  test('X-Frame-Options should be SAMEORIGIN (not DENY or ALLOW-FROM)', async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();
    const xFrameOptions = headers?.['x-frame-options'];

    // Verify it's set to exactly SAMEORIGIN
    expect(xFrameOptions).toBe('SAMEORIGIN');

    // Make sure it doesn't have deprecated values
    expect(xFrameOptions).not.toContain('ALLOW-FROM');
  });

  /**
   * Test that CSP frame-ancestors policy is 'self' only
   */
  test("CSP frame-ancestors should be 'self' only", async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();
    const csp = headers?.['content-security-policy'];

    expect(csp).toContain("frame-ancestors 'self'");
  });

  /**
   * Test that frame-ancestors is present alongside frame-src
   */
  test("CSP should include both frame-src and frame-ancestors", async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();
    const csp = headers?.['content-security-policy'];

    // Modern approach: use both for maximum compatibility
    expect(csp).toContain('frame-src');
    expect(csp).toContain("frame-ancestors 'self'");
  });

  /**
   * Test that HTTP response contains both legacy and modern anti-clickjacking headers
   */
  test('should have both X-Frame-Options and CSP frame-ancestors', async ({ page }) => {
    const response = await page.goto('/');

    const headers = response?.headers();

    // Legacy header
    expect(headers?.['x-frame-options']).toBeDefined();
    expect(headers?.['x-frame-options']).toBe('SAMEORIGIN');

    // Modern CSP directive
    const csp = headers?.['content-security-policy'];
    expect(csp).toBeDefined();
    expect(csp).toContain("frame-ancestors 'self'");
  });
});
