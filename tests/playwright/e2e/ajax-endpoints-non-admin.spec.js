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
