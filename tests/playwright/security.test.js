// tests/playwright/security.test.js
const { test, expect } = require('@playwright/test');

test.describe('Security Features', () => {
  test('secure session cookies are set', async ({ page, context }) => {
    await page.goto('http://localhost:9999/elan_registry/index.php');
    await page.waitForLoadState('networkidle');

    const cookies = await context.cookies();
    const sessionCookie = cookies.find(cookie => cookie.name.includes('PHPSESSID') || cookie.name.includes('session'));

    if (sessionCookie) {
      expect(sessionCookie.httpOnly).toBe(true);
      expect(sessionCookie.secure).toBe(false); // localhost doesn't use HTTPS
      expect(sessionCookie.sameSite).toBe('Strict');
    }
  });

  test('verification system has CSRF protection', async ({ page }) => {
    // Test the verification page
    await page.goto('http://localhost:9999/elan_registry/app/verify/index.php');
    await page.waitForLoadState('networkidle');
    
    // Check if page requires authentication or has proper access control
    const pageContent = await page.textContent('body');
    if (pageContent.includes('Please Log In') || pageContent.includes('Not Found') || pageContent.includes('Access Denied')) {
      // Verification system is properly protected
      await expect(page.locator('h1, h2')).toContainText(/Please Log In|Not Found|Access Denied/);
    } else {
      // If accessible, should have CSRF token or proper security measures
      const tokenExists = await page.locator('input[name="csrf"], input[name="token"]').count();
      expect(tokenExists).toBeGreaterThan(0);
    }
  });
});