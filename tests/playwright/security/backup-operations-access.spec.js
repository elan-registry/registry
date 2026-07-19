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
  test('unauthenticated list_backups POST does not return backup data', async ({ page }) => {
    const response = await page.request.post(ENDPOINT, {
      form: { action: 'list_backups' },
    });

    const body = await response.text();
    expect(body).not.toContain('"backups"');
    expect(body).not.toContain('"success":true');
  });

  test('unauthenticated create_manual_backup POST does not create a backup', async ({ page }) => {
    const response = await page.request.post(ENDPOINT, {
      form: { action: 'create_manual_backup', reason: 'security-test' },
    });

    const body = await response.text();
    expect(body).not.toContain('"success":true');
    expect(body).not.toContain('"filename"');
  });
});
