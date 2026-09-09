/**
 * Consolidated one-time setup script to log in to Test or Prod and save
 * authentication state for Playwright's storageState-based auth (issue
 * #2035, replacing the separate 1Password-driven playwright-auth-setup.js /
 * playwright-auth-setup-test.js and their .sh wrappers).
 *
 * Both Test and Prod run HTTPS with an active Cloudflare Turnstile
 * challenge, which an automated browser cannot solve — this script fails
 * fast (does not attempt to solve or bypass it) if any Turnstile widget is
 * present, including a Cloudflare test/always-pass sitekey. A human must
 * manually disable Turnstile on the target environment before running this
 * script, then re-enable it once the auth file is saved. See
 * docs/testing/PLAYWRIGHT_E2E.md.
 *
 * Credentials are read from .env.local (never committed) as
 * E2E_<TIER>_<ROLE>_USERNAME / E2E_<TIER>_<ROLE>_PASSWORD — see
 * docs/development/ENVIRONMENT.md.
 *
 * Usage:
 *   node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>
 *
 * Examples:
 *   node scripts/playwright-auth-setup.js test admin
 *   node scripts/playwright-auth-setup.js prod nonadmin
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env.local') });

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const TIERS = ['test', 'prod'];
const ROLES = ['admin', 'nonadmin'];

const TIER_HOSTS = {
  test: 'https://test.elanregistry.org',
  prod: 'https://elanregistry.org',
};

function parseArgs(argv) {
  const [tier, role] = argv;

  if (!TIERS.includes(tier)) {
    console.error(`❌ Error: first argument must be 'test' or 'prod' (got: ${tier || 'unset'})`);
    console.log('\nUsage: node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>');
    process.exit(1);
  }
  if (!ROLES.includes(role)) {
    console.error(`❌ Error: second argument must be 'admin' or 'nonadmin' (got: ${role || 'unset'})`);
    console.log('\nUsage: node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>');
    process.exit(1);
  }

  return { tier, role };
}

function resolveCredentials(tier, role) {
  const prefix = `E2E_${tier.toUpperCase()}_${role.toUpperCase()}`;
  const username = process.env[`${prefix}_USERNAME`];
  const password = process.env[`${prefix}_PASSWORD`];

  if (!username || !password) {
    console.error(`❌ Error: ${prefix}_USERNAME and ${prefix}_PASSWORD must be set in .env.local`);
    console.log('\nSee docs/development/ENVIRONMENT.md and .env.example for the full E2E_* variable list.');
    process.exit(1);
  }

  return { username, password };
}

async function setupAuth() {
  const { tier, role } = parseArgs(process.argv.slice(2));
  const { username, password } = resolveCredentials(tier, role);
  const host = TIER_HOSTS[tier];

  console.log(`🔐 Starting ${tier.toUpperCase()} (${role}) authentication setup...\n`);

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    console.log(`📝 Navigating to ${tier} login page...`);
    // usersc/login.php is the customized login page (adds security
    // validation over UserSpice's own users/login.php) — see CLAUDE.md's
    // Template Customization Rules.
    await page.goto(`${host}/usersc/login.php`);

    // waitForLoadState('networkidle') can hang indefinitely on this page
    // even after the form has rendered — wait for the form directly instead
    // (see #2014).
    console.log('✍️  Waiting for login form...');
    await page.waitForSelector('input[name="username"]', { timeout: 15000 });
    await page.waitForSelector('input[name="password"]', { timeout: 15000 });

    // Fail fast if a real Turnstile challenge is present — an automated
    // browser cannot solve it. Bounded wait with state: 'attached' (not
    // 'visible') to detect the empty div before Cloudflare's JS renders it.
    // See docs/testing/PLAYWRIGHT_E2E.md Troubleshooting for full context.
    const turnstileWidget = await page
      .waitForSelector('.cf-turnstile', { state: 'attached', timeout: 2000 })
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

    console.log('  → Entering username...');
    await page.fill('input[name="username"]', username);
    console.log('  → Entering password...');
    await page.fill('input[name="password"]', password);

    console.log('Submitting login form...');
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });
    await page.click('button[type="submit"]');
    console.log('Login form submitted');

    try {
      console.log('Waiting for login redirect...');
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
        console.error('- Invalid credentials (check .env.local)');
        console.error('- 2FA/TOTP required');
        console.error('- Network connectivity issue');
        console.error('- Turnstile rejected the submission (the pre-submit check above ' +
          'did not catch it — see docs/testing/PLAYWRIGHT_E2E.md Troubleshooting)');
        process.exit(1);
      }
    }

    const authFile = path.join(__dirname, `../tests/playwright/.auth/user-${tier}-${role}.json`);
    const authDir = path.dirname(authFile);

    if (!fs.existsSync(authDir)) {
      fs.mkdirSync(authDir, { recursive: true });
    }

    await context.storageState({ path: authFile });
    console.log(`💾 ${tier.toUpperCase()} (${role}) authentication state saved to: ${authFile}`);
    console.log(`\n✅ Setup complete! Remember to re-enable Turnstile on ${tier} now.\n`);

  } catch (error) {
    console.error('\n❌ Error during authentication setup:', error.message);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

setupAuth();
