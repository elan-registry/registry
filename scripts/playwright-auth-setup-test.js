/**
 * One-time setup script to log in to TEST environment and save authentication state
 * Run once to capture auth cookies. Requires Turnstile to be disabled on
 * this environment first — this script fails fast (does not attempt to
 * solve or bypass it) if any Turnstile widget is present, including a
 * Cloudflare test/always-pass sitekey. See docs/testing/PLAYWRIGHT_E2E.md.
 *
 * Usage:
 *   export ELAN_USERNAME=$(op read "op://ElanRegistry/Elanregistry - Test Admin/username")
 *   export ELAN_PASSWORD=$(op read "op://ElanRegistry/Elanregistry - Test Admin/password")
 *   node scripts/playwright-auth-setup-test.js
 *
 * Or use the provided script:
 *   ./scripts/playwright-auth-1password-test.sh
 */

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

async function setupAuth() {
  const username = process.env.ELAN_USERNAME;
  const password = process.env.ELAN_PASSWORD;

  if (!username || !password) {
    console.error('❌ Error: ELAN_USERNAME and ELAN_PASSWORD environment variables must be set');
    console.log('\nRun this script with:');
    console.log('  ./scripts/playwright-auth-1password-test.sh');
    process.exit(1);
  }

  console.log('🔐 Starting TEST environment authentication setup...\n');

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    console.log('📝 Navigating to TEST environment login page...');
    // Use usersc/login.php (customized version with security validation)
    await page.goto('https://test.elanregistry.org/usersc/login.php');

    console.log('✍️  Filling in credentials...');

    // waitForLoadState('networkidle') can hang indefinitely on this page
    // even after the form has rendered — wait for the form directly instead.
    await page.waitForSelector('input[name="username"]', { timeout: 15000 });
    await page.waitForSelector('input[name="password"]', { timeout: 15000 });

    // Fail fast with a clear message if a real Turnstile challenge is
    // present. addTurnstile() (usersc/includes/turnstile.php) only emits a
    // .cf-turnstile div when Turnstile is enabled server-side — an
    // automated browser cannot solve a real challenge, and continuing here
    // previously produced a silent-looking hang (see docs/testing/PLAYWRIGHT_E2E.md
    // Troubleshooting) with no indication Turnstile was the cause.
    //
    // Bounded wait rather than an instantaneous page.$(): the div is
    // server-rendered (not injected later by Cloudflare's JS), but a
    // non-waiting query can still run before the browser has finished
    // parsing that part of the response body, even though the form fields
    // above already resolved — a false negative here would fall through to
    // filling and submitting the form, and a resulting Turnstile rejection
    // would be misattributed to bad credentials or 2FA in the catch block
    // below instead of reported as what it is.
    const turnstileWidget = await page
      .waitForSelector('.cf-turnstile', { timeout: 2000 })
      .catch(() => null);
    if (turnstileWidget) {
      throw new Error(
        'Turnstile is enabled on this environment — a real challenge widget is ' +
        'present on the login page. An automated browser cannot solve it, including ' +
        'a Cloudflare test/always-pass sitekey (this check does not distinguish sitekey ' +
        'types). Disable Turnstile entirely on this environment, re-run this script, ' +
        'then re-enable Turnstile once the auth file is saved. ' +
        'See docs/testing/PLAYWRIGHT_E2E.md Prerequisites/Troubleshooting.'
      );
    }

    // Fill in username and password using correct selectors
    console.log('  → Entering username...');
    await page.fill('input[name="username"]', username);
    console.log('  → Entering password...');
    await page.fill('input[name="password"]', password);

    // Click the submit button (Turnstile test keys auto-pass)
    console.log('Submitting login form...');
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });
    await page.click('button[type="submit"]');
    console.log('Login form submitted');

    // Wait for navigation away from login page
    try {
      console.log('Waiting for login redirect...');

      // Wait for URL to change away from login.php or dashboard elements to appear
      await Promise.race([
        page.waitForFunction(
          () => !window.location.href.includes('login.php'),
          { timeout: 30000 }
        ),
        page.waitForSelector('[data-testid="dashboard"], .account-page, .app-container',
          { timeout: 30000 }).catch(() => {})
      ]);

      await page.waitForLoadState('networkidle').catch(() => {});

      const currentUrl = page.url();
      console.log(`Login successful! Redirected to: ${currentUrl}`);
    } catch (_timeoutError) {
      const currentUrl = page.url();
      console.log(`Timeout waiting for login redirect. Current URL: ${currentUrl}`);

      if (currentUrl.includes('login')) {
        console.error('Still on login page. Possible issues:');
        console.error('- Invalid credentials (check 1Password vault)');
        console.error('- 2FA/TOTP required');
        console.error('- Network connectivity issue');
        console.error('- Turnstile rejected the submission (the pre-submit check above ' +
          'did not catch it — see docs/testing/PLAYWRIGHT_E2E.md Troubleshooting)');
        process.exit(1);
      }
    }

    // Save authentication state to TEST-specific file
    const authFile = path.join(__dirname, '../tests/playwright/.auth/user-test.json');
    const authDir = path.dirname(authFile);

    if (!fs.existsSync(authDir)) {
      fs.mkdirSync(authDir, { recursive: true });
    }

    await context.storageState({ path: authFile });
    console.log(`💾 TEST environment authentication state saved to: ${authFile}`);
    console.log('\n✅ Setup complete! You can now run TEST environment logged-in tests without manual login.\n');

  } catch (error) {
    console.error('\n❌ Error during authentication setup:', error.message);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

setupAuth();
