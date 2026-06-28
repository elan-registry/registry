// tests/playwright/security.test.js
const { test, expect } = require('@playwright/test');

test.describe('Security Features', () => {
  test('secure session cookies are set', async ({ page, context }) => {
    await page.goto('index.php');
    await page.waitForLoadState('networkidle');

    const cookies = await context.cookies();
    const sessionCookie = cookies.find(cookie => cookie.name.includes('PHPSESSID') || cookie.name.includes('session'));

    if (sessionCookie) {
      expect(sessionCookie.httpOnly).toBe(true);
      expect(sessionCookie.secure).toBe(false); // localhost doesn't use HTTPS
      expect(sessionCookie.sameSite).toBe('Strict');
    }
  });

});
