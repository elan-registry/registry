// tests/playwright/login-failure-diagnostic.spec.js
//
// Coverage for the diagnostic-on-failure path added to login() in
// tests/playwright/auth-helper.js (issue #1935).
//
// Before the fix, a failed login (wrong credentials, changed form markup,
// rate-limiting) simply hung on page.waitForURL() until Playwright's 15s
// timeout, producing a generic "Timeout exceeded" message with no hint as to
// why. login() now races that navigation against the UserSpice error toast
// (.us-toast .toast-body) and throws an error embedding the toast's actual
// text.
//
// This test locks in that behavior by asserting on login()'s thrown error —
// it deliberately does NOT re-read the toast itself (login() already does
// that internally); duplicating the toast-reading logic here would test the
// spec's own copy rather than the helper's. The toast selector is quoted
// above only for reference; the assertions below target err.message.
//
// Deterministic and self-contained: UserSpice always rejects unknown
// credentials with the SIGNIN_FAIL/SIGNIN_PLEASE_CHK message pair
// (usersc/login.php:355-357), so no valid credentials, storageState, or
// auth setup project is required. This spec runs in the default `chromium`
// project against the local login form.

const { test, expect } = require('@playwright/test');
const { login } = require('./auth-helper');

test.describe('login() failure diagnostics', () => {
  test('throws an error embedding the UserSpice toast text on bad credentials', async ({ page }) => {
    let thrownError = null;

    try {
      await login(page, 'wrong@example.com', 'wrongpassword');
    } catch (error) {
      thrownError = error;
    }

    expect(
      thrownError,
      'login() must throw when the credentials are rejected, not resolve silently'
    ).not.toBeNull();

    // Context marker: identifies this as a login failure rather than a
    // generic Playwright timeout, which is the whole point of the change.
    expect(
      thrownError.message,
      'The error must identify itself as a login failure'
    ).toContain('Login failed');

    // The real toast text, proving the diagnostic embeds live page content
    // rather than a canned string. Matches lang("SIGNIN_FAIL") as rendered
    // by usersc/login.php into .us-toast .toast-body — OR, if this spec has
    // already run several times against the same local login_attempt rate
    // limit (usersc/includes/rate_limits.php), the RATE_LIMIT_LOGIN message
    // instead. Both are real toast content proving the same thing; a
    // failure on this assertion after many runs in a short window means
    // rate-limiting, not a regression in login()'s diagnostic behavior.
    expect(
      thrownError.message,
      'The error must embed real UserSpice toast content, not a generic timeout message'
    ).toMatch(/\*\* FAILED LOGIN \*\*|Too many failed login attempts/);
  });

  // The counterpart to the test above: the race in login() has two arms, and
  // pinning only the toast arm would let a regression that makes login()
  // throw (or hang) on a *successful* sign-in pass unnoticed here. Other
  // specs call login() with valid credentials, but none asserts the resolve
  // itself — a failure there surfaces as an unrelated test's timeout rather
  // than as "login()'s success path broke".
  test('resolves without throwing and navigates away on valid credentials', async ({ page }) => {
    if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
      test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run this test');
    }

    await expect(
      login(page, process.env.TEST_USERNAME, process.env.TEST_PASSWORD),
      'login() must resolve, not throw, when the credentials are accepted'
    ).resolves.toBeUndefined();

    expect(
      page.url(),
      'login() must not resolve while still on the login page'
    ).not.toContain('login.php');
  });
});
