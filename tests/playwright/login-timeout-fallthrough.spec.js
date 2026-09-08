// tests/playwright/login-timeout-fallthrough.spec.js
//
// Coverage for the third — and only untested — outcome of the race in
// login() (tests/playwright/auth-helper.js, issue #1935).
//
// login() races two promises after clicking submit:
//   'navigated'       — waitForURL() resolved; the happy path
//   'toast'           — a UserSpice .us-bar-danger toast appeared; throws a
//                       diagnostic error embedding the toast text
//   'toast-not-seen'  — NEITHER resolved inside the toast waiter's own 15s
//                       timeout, so its internal .catch() wins the race. The
//                       code then falls through to `await navigationPromise`
//                       so the caller gets Playwright's real waitForURL
//                       timeout and its diagnostics, rather than a misleading
//                       generic message (or, worse, a silent resolve).
//
// login-failure-diagnostic.spec.js covers 'navigated' and 'toast' against the
// real login form. 'toast-not-seen' cannot be reproduced there: it requires a
// submit that neither navigates nor renders an error toast for a full 15
// seconds, which against the real usersc/login.php is timing-dependent and
// would be flaky (UserSpice always answers a submit with either a redirect or
// a toast). So this spec fulfills the same request with a synthetic page
// served by page.route() — a form that satisfies login()'s selectors but whose
// submit button deliberately does nothing at all. The route intercept means no
// local HTTP server and no dependence on MAMP serving usersc/login.php.
//
// The URL is still .../usersc/login.php so that login()'s
// `waitForURL(url => !url.includes('login.php'))` predicate stays unsatisfiable,
// exactly as it would be on a genuinely hung login.
//
// What this test does and does NOT prove. It exercises the 'toast-not-seen'
// path end to end and pins the caller-visible contract: login() rejects with
// Playwright's real waitForURL TimeoutError, never with the misleading
// "Login failed: ... toast appeared" message and never by resolving silently.
// It is NOT a mutation-proof test of the `await navigationPromise` line
// itself: deleting that line (verified by experiment) leaves the observable
// outcome identical, because navigationPromise rejects on its own at 15s and
// Playwright's runner promotes the resulting unhandled rejection into the
// same test failure. The `await` is still correct and worth keeping — it
// turns an unhandled rejection into a properly awaited one, which is what
// makes the failure deterministic rather than dependent on runner behavior —
// but no black-box test at this level can distinguish the two. Asserting the
// contract is the achievable coverage here.
//
// Runtime note: login()'s 15000ms timeouts are hardcoded and not parameterized,
// so this test takes ~16s. Both waiters are registered at the same moment and
// both expire at 15s, so once the toast waiter's .catch() wins the race the
// awaited navigationPromise has already rejected — the two waits overlap
// rather than adding up. Parameterizing the timeouts would be a design change
// to the helper, out of scope for a coverage-only test.

const { test, expect } = require('@playwright/test');
const { login } = require('./auth-helper');

// The overlapping 15s waits settle at ~16s; the rest is headroom for slow
// machines and CI. Must exceed 15s, or the test timeout would fire first and
// hide the very waitForURL error this test asserts on.
const FALLTHROUGH_TIMEOUT_MS = 45000;

// Minimal markup satisfying every selector login() depends on:
// input[name="username"], input[name="password"], button[type="submit"].
// The submit handler preventDefault()s, so the click neither navigates nor
// renders any .us-toast element — the exact conditions of 'toast-not-seen'.
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
    // Swallow the submit: no navigation, no toast, no feedback of any kind.
    document.getElementById('hung-login').addEventListener('submit', function (event) {
      event.preventDefault();
    });
  </script>
</body>
</html>`;

test.describe('login() timeout fallthrough', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/usersc/login.php', async (route) => {
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

    // The whole point of the fallthrough: the caller sees Playwright's own
    // waitForURL timeout (with its diagnostics), not login()'s hand-written
    // "Login failed: ... toast appeared" message, which would be misleading
    // here because no toast ever appeared.
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
