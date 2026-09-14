const { test, expect } = require('@playwright/test');

test.describe('Admin AJAX Endpoints — Non-Admin Access', () => {
  // Skip unless running under the non-admin authenticated project — this
  // file's whole point is to exercise requireAdminAjax()'s isRegistryAdmin()
  // branch with a real, logged-in, non-admin session (not the unauthenticated
  // branch, already covered elsewhere — see ajax-endpoints.spec.js).
  test.beforeEach(async ({}, testInfo) => {
    if (testInfo.project.name !== 'logged-in-non-admin' && testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
    // On Dev, 'logged-in-non-admin' is registered unconditionally
    // (playwright.config.dev.js) — only its storageState is conditional on
    // E2E_DEV_NONADMIN_USERNAME/PASSWORD, and a missing setup dependency does
    // not cascade-skip this project. Without this guard, the spec would run
    // fully unauthenticated and still get 403 'Unauthorized access' from
    // requireAdminAjax()'s !isLoggedIn() clause — a false pass that proves
    // nothing about isRegistryAdmin(). Test/Prod's 'logged-in' project only
    // ever exists when a real non-admin auth file is present, so this check
    // is a no-op there. Same idiom as ajax-endpoints.spec.js's beforeEach.
    if (testInfo.project.name === 'logged-in-non-admin' &&
        (!process.env.E2E_DEV_NONADMIN_USERNAME || !process.env.E2E_DEV_NONADMIN_PASSWORD)) {
      test.skip(true, 'Set E2E_DEV_NONADMIN_USERNAME and E2E_DEV_NONADMIN_PASSWORD in .env.local to run this test authenticated');
    }
  });

  // Same idiom as ajax-endpoints.spec.js's unauthenticated-403 test: a dummy
  // CSRF token is enough because requireAdminAjax()'s login/role check
  // (custom_functions.php:224) runs before the CSRF check (:231) and
  // short-circuits first — this pins the 403 to isRegistryAdmin() specifically,
  // not to a coincidental CSRF failure. Asserting the exact 'Unauthorized
  // access' message (not just the status code) means the test can't silently
  // start passing on a different branch if the guard is ever reordered.
  test('admin user details endpoint rejects a logged-in non-admin user', async ({ request }) => {
    const response = await request.post('app/admin/includes/process-user-details.php', {
      form: {
        user_id: '1',
        csrf: 'test_token'
      }
    });

    expect(response.status()).toBe(403);
    const jsonResponse = await response.json();
    expect(jsonResponse).toHaveProperty('success', false);
    expect(jsonResponse).toHaveProperty('message', 'Unauthorized access');
  });
});
