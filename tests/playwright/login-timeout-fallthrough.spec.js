// login() 'toast-not-seen' outcome (#1935): the submit neither navigates nor
// shows a toast within 15s. The real login page always answers, so a
// page.route() stub serves a form whose submit does nothing. The URL stays
// .../usersc/login.php so login()'s waitForURL predicate cannot resolve.
//
// This pins the contract (a real waitForURL TimeoutError), but it cannot
// detect removal of `await navigationPromise`: the unhandled rejection fails
// the test the same way.

const { test, expect } = require('@playwright/test');
const { login } = require('./auth-helper');

// login() hardcodes 15s waits. Must exceed 15s, or the test timeout fires
// first and hides the waitForURL error.
const FALLTHROUGH_TIMEOUT_MS = 45000;

const HUNG_LOGIN_HTML = `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Hung Login</title></head>
<body>
  <form id="hung-login">
    <input type="text" name="username">
    <input type="password" name="password">
    <button type="submit">Log In</button>
  </form>
  <script>
    document.getElementById('hung-login').addEventListener('submit', function (event) {
      event.preventDefault();
    });
  </script>
</body>
</html>`;

test.describe('login() timeout fallthrough', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/usersc/login.php*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: HUNG_LOGIN_HTML,
      });
    });
  });

  test('surfaces the waitForURL timeout when neither navigation nor toast occurs', async ({ page }) => {
    test.setTimeout(FALLTHROUGH_TIMEOUT_MS);

    let thrownError = null;

    try {
      await login(page, 'someone@example.com', 'somepassword');
    } catch (error) {
      thrownError = error;
    }

    expect(
      thrownError,
      'login() must throw when the submit neither navigates nor shows a toast, not resolve silently'
    ).not.toBeNull();

    expect(
      thrownError.message,
      'The error must come from the awaited waitForURL, not the toast diagnostic branch'
    ).not.toContain('Login failed');

    expect(
      thrownError.message,
      'The error must be a Playwright waitForURL timeout carrying real diagnostics'
    ).toMatch(/Timeout .*exceeded|waitForURL/);
  });
});
