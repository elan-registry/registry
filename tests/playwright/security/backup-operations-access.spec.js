const { test, expect } = require('@playwright/test');

/**
 * Regression guard: backup-operations.php must block unauthenticated access.
 *
 * `securePage()` redirects unauthenticated users to the login page.
 * `isAdmin()` returns 403 JSON for non-admin authenticated users.
 * These tests verify that unauthenticated POSTs never return backup data.
 *
 * Run with: npm run playwright:security (local config, base URL http://localhost:9999)
 *
 * Guards that require an authenticated session (isAdmin() guard, CSRF guard) are
 * not covered here — they require a logged-in session setup via auth-helper.js.
 *
 * @group security
 */

const ENDPOINT = 'app/admin/includes/system/backup-operations.php';

test.describe('Backup Operations Endpoint — Unauthenticated Access', () => {
  // securePage() redirects unauthenticated users to the login page (302).
  // maxRedirects: 0 ensures we assert on the redirect itself, not the login page.
  const noFollow = { maxRedirects: 0 };

  test('unauthenticated list_backups POST redirects to login', async ({ page }) => {
    const response = await page.request.post(ENDPOINT, {
      form: { action: 'list_backups' },
      ...noFollow,
    });

    expect(response.status()).toBe(302);
    const body = await response.text();
    expect(body).not.toContain('"backups"');
    expect(body).not.toContain('"success":true');
  });

  test('unauthenticated create_manual_backup POST redirects to login', async ({ page }) => {
    const response = await page.request.post(ENDPOINT, {
      form: { action: 'create_manual_backup', reason: 'security-test' },
      ...noFollow,
    });

    expect(response.status()).toBe(302);
    const body = await response.text();
    expect(body).not.toContain('"success":true');
    expect(body).not.toContain('"filename"');
  });
});
