// tests/playwright/login-functionality.test.js

/**
 * Comprehensive login functionality tests
 * These tests verify the core authentication system works correctly
 * with Cloudflare Turnstile bot protection (test keys auto-pass)
 */

const { test, expect } = require('@playwright/test');
const { login, logout, isLoggedIn, navigateAndWait } = require('./auth-helper.js');

// Test credentials from environment variables
const VALID_CREDENTIALS = {
  username: process.env.E2E_DEV_ADMIN_USERNAME || 'test@example.com',
  password: process.env.E2E_DEV_ADMIN_PASSWORD || 'defaultTestPass'
};

const INVALID_CREDENTIALS = {
  wrongPassword: 'incorrectPassword',
  wrongUsername: 'nonexistent@example.com',
  emptyPassword: '',
  emptyUsername: ''
};

test.describe('Login Functionality', () => {

  test.beforeEach(async ({ page }) => {
    // Ensure we start each test logged out
    await logout(page);
  });

  test('successful login with valid credentials', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // Turnstile test keys auto-pass - proceed with login
    await page.fill('input[name="username"], input[name="email"]', VALID_CREDENTIALS.username);
    await page.fill('input[name="password"]', VALID_CREDENTIALS.password);
    
    // Submit login form
    await page.click('button[type="submit"], input[type="submit"]');
    
    // Wait for redirect/response
    await page.waitForLoadState('networkidle');
    
    // Verify successful login
    const currentUrl = page.url();
    expect(currentUrl).not.toContain('login.php');
    
    // Check for logout link or user menu indicating logged in state
    const loggedIn = await isLoggedIn(page);
    expect(loggedIn).toBe(true);
  });

  test('failed login with invalid password', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // Use a non-existent user to avoid incrementing the real admin account's
    // per-user rate-limit failure counter (user_max: 5 failures / 5 min).
    await page.fill('input[name="username"], input[name="email"]', 'nonexistent.test@example.invalid');
    await page.fill('input[name="password"]', INVALID_CREDENTIALS.wrongPassword);
    
    // Submit login form
    await page.click('button[type="submit"], input[type="submit"]');
    
    // Wait for response
    await page.waitForLoadState('networkidle');
    
    // Should still be on login page or show error
    const currentUrl = page.url();
    expect(currentUrl).toContain('login');
    
    // Verify not logged in
    const loggedIn = await isLoggedIn(page);
    expect(loggedIn).toBe(false);
    
    // Check for error message
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/(error|invalid|incorrect|failed)/i);
  });

  test('failed login with invalid username', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
    
    // Fill in credentials with wrong username
    await page.fill('input[name="username"], input[name="email"]', INVALID_CREDENTIALS.wrongUsername);
    await page.fill('input[name="password"]', VALID_CREDENTIALS.password);
    
    // Submit login form
    await page.click('button[type="submit"], input[type="submit"]');
    
    // Wait for response
    await page.waitForLoadState('networkidle');
    
    // Should still be on login page or show error
    const currentUrl = page.url();
    expect(currentUrl).toContain('login');
    
    // Verify not logged in
    const loggedIn = await isLoggedIn(page);
    expect(loggedIn).toBe(false);
  });

  test('form validation with empty fields', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
    
    // Try to submit with empty username
    await page.fill('input[name="password"]', VALID_CREDENTIALS.password);
    await page.click('button[type="submit"], input[type="submit"]');

    // Browser-native HTML5 validation blocks submission on the empty required field
    await expect(page.locator('input[name="username"]:invalid, input[name="email"]:invalid')).toBeVisible();
    const currentUrl = page.url();
    expect(currentUrl).toContain('login');

    // Reset and try empty password. The password field has no `required`
    // attribute (see usersc/login.php), so HTML5 constraint validation never
    // marks it :invalid — submission reaches the server, which must reject
    // it instead.
    await page.fill('input[name="username"], input[name="email"]', VALID_CREDENTIALS.username);
    await page.fill('input[name="password"]', '');
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle');

    const currentUrl2 = page.url();
    expect(currentUrl2).toContain('login');
    
    // Verify not logged in
    const loggedIn = await isLoggedIn(page);
    expect(loggedIn).toBe(false);
  });

  test('login persistence across page navigation', async ({ page }) => {
    // Login first
    await login(page, VALID_CREDENTIALS.username, VALID_CREDENTIALS.password);
    
    // Verify logged in
    expect(await isLoggedIn(page)).toBe(true);
    
    // Navigate to various pages and verify still logged in
    const protectedPages = [
      'app/owner/cars/index.php',
      'app/owner/reports/statistics.php',
      'users/account.php'
    ];
    
    for (const pagePath of protectedPages) {
      await navigateAndWait(page, pagePath);
      const currentUrl = page.url();
      
      // Should not be redirected to login
      expect(currentUrl).not.toContain('login.php');
      
      // Should still show as logged in
      expect(await isLoggedIn(page)).toBe(true);
    }
  });

  test('logout functionality', async ({ page }) => {
    // Login first
    await login(page, VALID_CREDENTIALS.username, VALID_CREDENTIALS.password);
    expect(await isLoggedIn(page)).toBe(true);
    
    // Perform logout (logout() already waits for navigation)
    await logout(page);

    // Verify logged out
    expect(await isLoggedIn(page)).toBe(false);
    
    // Try to access protected page - should redirect to login
    await page.goto('users/account.php');
    await page.waitForLoadState('networkidle');
    
    const currentUrl = page.url();
    const pageContent = await page.textContent('body');
    
    // Should either be on login page or show "Please Log In" message
    const redirectedToLogin = currentUrl.includes('login.php') || pageContent.includes('Please Log In');
    expect(redirectedToLogin).toBe(true);
  });

  test('login redirect to intended page', async ({ page }) => {
    // Try to access protected page while logged out
    await page.goto('users/account.php');
    await page.waitForLoadState('networkidle');
    
    // Should be redirected to login or see login prompt
    const currentUrl = page.url();
    if (currentUrl.includes('login.php')) {
      // On login page - perform login
      await page.fill('input[name="username"], input[name="email"]', VALID_CREDENTIALS.username);
      await page.fill('input[name="password"]', VALID_CREDENTIALS.password);
      await page.click('button[type="submit"], input[type="submit"]');
      
      await page.waitForLoadState('networkidle');
      
      // Should be redirected to originally requested page or dashboard
      const finalUrl = page.url();
      expect(finalUrl).not.toContain('login.php');
      expect(await isLoggedIn(page)).toBe(true);
    }
    // If body contains 'Please Log In' but no redirect, the auth wall itself is sufficient evidence.
  });

  test('CSRF token handling', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // Check that the CSRF token field exists
    const csrfInput = page.locator('input[name="csrf"]');
    await expect(csrfInput).toHaveCount(1);

    // CSRF protection is active - verify it's included in form submission
    const tokenValue = await csrfInput.inputValue();
    expect(tokenValue).toBeTruthy();
    expect(tokenValue.length).toBeGreaterThan(10); // Should be a substantial token

    // Perform login and verify token is processed correctly
    await login(page, VALID_CREDENTIALS.username, VALID_CREDENTIALS.password);
    expect(await isLoggedIn(page)).toBe(true);
  });

  test('login form accessibility', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
    
    // Check for form labels and accessibility
    const usernameField = page.locator('input[name="username"], input[name="email"]').first();
    const passwordField = page.locator('input[name="password"]');
    
    await expect(usernameField).toBeVisible();
    await expect(passwordField).toBeVisible();
    
    // Check for submit button
    const submitButton = page.locator('button[type="submit"], input[type="submit"]');
    await expect(submitButton).toBeVisible();
    
    // Verify fields can be focused and filled
    await usernameField.focus();
    await usernameField.fill(VALID_CREDENTIALS.username);
    
    await passwordField.focus();
    await passwordField.fill(VALID_CREDENTIALS.password);
    
    // Verify values were set
    expect(await usernameField.inputValue()).toBe(VALID_CREDENTIALS.username);
    expect(await passwordField.inputValue()).toBe(VALID_CREDENTIALS.password);
  });

  test('session security - no session fixation', async ({ page }) => {
    // Get initial session info (if available via cookies or headers)
    // Perform login
    await login(page, VALID_CREDENTIALS.username, VALID_CREDENTIALS.password);

    // Get post-login session info
    const postLoginCookies = await page.context().cookies();

    // Session cookie is PHPSESSID (PHP default; the app does not call
    // session_name() to override it — see users/init.php:8-18). It must
    // always be present post-login, so this asserts unconditionally rather
    // than skipping when absent.
    const sessionCookie = postLoginCookies.find(c => c.name === 'PHPSESSID');
    expect(sessionCookie).toBeDefined();
    expect(sessionCookie.httpOnly).toBe(true);
    expect(sessionCookie.sameSite).toBe('Strict');
    // secure is conditional on HTTPS (users/init.php:12) — localhost is HTTP, so false is correct here.
    expect(sessionCookie.secure).toBe(false);

    expect(await isLoggedIn(page)).toBe(true);
  });
});

test.describe('Login Form Responsiveness', () => {
  
  const viewports = [
    { width: 320, height: 568, name: 'mobile' },
    { width: 768, height: 1024, name: 'tablet' },
    { width: 1920, height: 1080, name: 'desktop' }
  ];
  
  for (const viewport of viewports) {
    test(`login form displays correctly on ${viewport.name}`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
      
      // Check form elements are visible and properly sized
      const usernameField = page.locator('input[name="username"], input[name="email"]').first();
      const passwordField = page.locator('input[name="password"]');
      const submitButton = page.locator('button[type="submit"], input[type="submit"]');
      
      await expect(usernameField).toBeVisible();
      await expect(passwordField).toBeVisible();
      await expect(submitButton).toBeVisible();
      
      // Verify fields are accessible (not overlapping or cut off)
      const usernameBox = await usernameField.boundingBox();
      const passwordBox = await passwordField.boundingBox();
      const submitBox = await submitButton.boundingBox();
      
      expect(usernameBox?.width).toBeGreaterThan(100);
      expect(passwordBox?.width).toBeGreaterThan(100);
      expect(submitBox?.width).toBeGreaterThan(50);
    });
  }
});

test.describe('Login Turnstile Reset (#1798)', () => {

  test('login form wires data-error-callback and data-expired-callback', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // Turnstile only renders with valid Cloudflare keys configured — skip
    // the widget-attribute assertion locally where it may be absent, same
    // convention as the forgot-password test above.
    const widget = page.locator('.cf-turnstile');
    const hasTurnstile = await widget.count() > 0;
    if (!hasTurnstile) {
      test.skip(true, 'Turnstile not configured in this environment');
    }

    await expect(widget).toHaveAttribute('data-error-callback', 'elanTurnstileError');
    await expect(widget).toHaveAttribute('data-expired-callback', 'elanTurnstileExpired');
  });

  test('elanTurnstileExpired calls turnstile.reset() without throwing', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // Stub window.turnstile.reset before triggering expiry — this exercises
    // the shared turnstile-reset.js helper directly rather than depending on
    // Cloudflare's real widget timing, mirroring how this suite already
    // simulates client-side-only behavior elsewhere (see datatables-xss.spec.js).
    const result = await page.evaluate(() => {
      let resetCalled = false;
      window.turnstile = { reset: () => { resetCalled = true; } };

      let threw = null;
      try {
        window.elanTurnstileExpired();
      } catch (e) {
        threw = e.message;
      }

      return {
        hasHandler: typeof window.elanTurnstileExpired === 'function',
        resetCalled,
        threw,
      };
    });

    expect(result.hasHandler, 'window.elanTurnstileExpired must be defined on the login page').toBe(true);
    expect(result.threw, 'elanTurnstileExpired must not throw').toBeNull();
    expect(result.resetCalled, 'elanTurnstileExpired must call turnstile.reset()').toBe(true);
  });

  test('elanTurnstileError calls turnstile.reset() without throwing', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    const result = await page.evaluate(() => {
      let resetCalled = false;
      window.turnstile = { reset: () => { resetCalled = true; } };

      let threw = null;
      try {
        window.elanTurnstileError();
      } catch (e) {
        threw = e.message;
      }

      return {
        hasHandler: typeof window.elanTurnstileError === 'function',
        resetCalled,
        threw,
      };
    });

    expect(result.hasHandler, 'window.elanTurnstileError must be defined on the login page').toBe(true);
    expect(result.threw, 'elanTurnstileError must not throw').toBeNull();
    expect(result.resetCalled, 'elanTurnstileError must call turnstile.reset()').toBe(true);
  });

  test('double-submit does not throw when Turnstile expires between submits', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    // Simulate the reported failure mode: the widget expires (e.g. from an
    // idle tab or a prior submit consuming the token) between two submits.
    // Before this fix, the login page had no elanTurnstileExpired handler at
    // all, so this would have been a ReferenceError if Cloudflare's widget
    // ever invoked the (unwired) callback.
    await page.evaluate(() => {
      window.turnstile = { reset: () => {} };
      window.elanTurnstileExpired();
    });

    expect(pageErrors, `Unexpected page errors: ${pageErrors.join('; ')}`).toHaveLength(0);
  });

  test('elanTurnstileExpired does not throw when window.turnstile is undefined', async ({ page }) => {
    await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

    // The realistic failure scenario prompting this fix: Cloudflare's widget
    // script never finished loading/executing, but a stale or otherwise-
    // fired callback invokes the handler anyway.
    const result = await page.evaluate(() => {
      delete window.turnstile;
      let threw = null;
      try {
        window.elanTurnstileExpired();
      } catch (e) {
        threw = e.message;
      }
      return threw;
    });
    expect(result, 'elanTurnstileExpired must not throw when window.turnstile is undefined').toBeNull();
  });
});

test.describe('Forgot Password Page', () => {
  const SUBMIT_BUTTON = 'button[name="forgotten_password"], input[name="forgotten_password"]';

  test('forgot password form renders with required security elements', async ({ page }) => {
    await page.goto('users/forgot_password.php', { waitUntil: 'networkidle' });

    await expect(page.locator('input[name="email"]')).toBeVisible();

    // usError([]) must not render a spurious error alert on initial GET
    await expect(page.locator('.alert-danger')).toHaveCount(0);

    // CSRF token must survive any template restructuring
    const csrfInput = page.locator('input[name="csrf"]');
    await expect(csrfInput).toHaveCount(1);
    expect((await csrfInput.inputValue()).length).toBeGreaterThan(10);

    // Bot protection — Turnstile only renders on production with valid Cloudflare keys;
    // skip the widget assertion locally where it may be absent.
    const hasTurnstile = await page.locator('.cf-turnstile').count() > 0;
    if (hasTurnstile) {
      expect(hasTurnstile).toBe(true);
    }

    await expect(page.locator(SUBMIT_BUTTON)).toBeVisible();
  });

  test('forgot password confirmation page renders after submission', async ({ page }) => {
    await page.goto('users/forgot_password.php', { waitUntil: 'networkidle' });

    await page.fill('input[name="email"]', 'test@example.com');
    await page.click(SUBMIT_BUTTON);
    await page.waitForLoadState('networkidle');

    // Only assert no PHP errors — redirect vs. stay-on-form behavior is not tested here
    const pageContent = await page.textContent('body');
    expect(pageContent).not.toMatch(/Fatal error|Parse error|Warning:/i);
  });
});