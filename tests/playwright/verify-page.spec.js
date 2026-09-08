// tests/playwright/verify-page.spec.js
//
// Coverage for users/verify.php (issue #1253).
//
// users/verify.php is the email-verification landing page and is reachable
// unauthenticated — no ensureLoggedIn() needed here.
//
// Scope: this file covers ONLY the no-query-params fallthrough path. With no
// $_GET keys at all, Input::exists('get') is false, so the verification block
// (lines ~38-304) never runs and $verify_success stays FALSE, falling through
// directly to the else branch (~line 330) which renders
// users/views/_verify_error.php — an <h1><?=lang("VER_FAIL");?></h1> heading.
// VER_FAIL is a language-string (not a literal), and its value could vary if
// the site's configured language changes, so this test asserts the heading
// is visible and non-empty rather than asserting exact text.
//
// Deliberately NOT covered: the "verified" success-path state
// (_verify_success.php). That requires a real DB user row with a valid,
// unexpired vericode, and no such fixture exists today. Building one (a new
// fixture user + a hash_equals()-matching vericode) is separate, tracked work
// — this is a known, deliberate gap, not an oversight. See #1253.
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');

test.describe('Verify page — unverified/error-view path (#1253)', () => {

  test('no query params renders the error view, not a raw PHP error or blank page', async ({ page }) => {
    await page.goto('users/verify.php', { waitUntil: 'domcontentloaded' });

    // _verify_error.php's heading. Asserted on visibility + non-empty text
    // rather than an exact literal, since VER_FAIL is a language-string.
    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
    await expect(heading).not.toHaveText('');
  });

});
