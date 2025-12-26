/**
 * One-time setup script to log in and save authentication state
 * This needs to be run once, and you'll need to manually solve any CAPTCHA
 *
 * Usage:
 *   export ELAN_USERNAME=$(op read "op://ElanRegistry/elanregistry - test account/username")
 *   export ELAN_PASSWORD=$(op read "op://ElanRegistry/elanregistry - test account/password")
 *   node scripts/setup-auth.js
 *
 * Or use the provided script:
 *   ./scripts/setup-auth-with-1password.sh
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
    console.log('  ./scripts/setup-auth-with-1password.sh');
    process.exit(1);
  }

  console.log('🔐 Starting authentication setup...\n');

  const browser = await chromium.launch({ headless: false }); // Run in headed mode so you can solve CAPTCHA
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    console.log('📝 Navigating to login page...');
    await page.goto('https://elanregistry.org/users/login.php');
    await page.waitForLoadState('networkidle');

    console.log('✍️  Filling in credentials...');
    await page.fill('input[name="username"], input[type="text"]', username);
    await page.fill('input[name="password"], input[type="password"]', password);

    console.log('\n' + '='.repeat(70));
    console.log('⚠️  IMPORTANT INSTRUCTIONS:');
    console.log('='.repeat(70));
    console.log('1. Look at the browser window that just opened');
    console.log('2. If there is a CAPTCHA, solve it now');
    console.log('3. Click the LOGIN/SUBMIT button');
    console.log('4. Wait for the page to redirect after successful login');
    console.log('\n⏳ This script will wait up to 5 minutes for you to complete the login...');
    console.log('='.repeat(70) + '\n');

    // Wait for navigation away from login page (5 minute timeout)
    try {
      await page.waitForFunction(
        () => !window.location.href.includes('login.php'),
        { timeout: 300000 } // 5 minutes
      );

      await page.waitForLoadState('networkidle');

      const currentUrl = page.url();
      console.log(`\n✅ Login successful! Redirected to: ${currentUrl}`);
    } catch (timeoutError) {
      const currentUrl = page.url();
      console.log(`\n⏱️  Timeout waiting for login. Current URL: ${currentUrl}`);

      if (currentUrl.includes('login')) {
        console.log('❌ Still on login page. Login may have failed or timed out.');
        console.log('Please try running the script again.');
        process.exit(1);
      } else {
        console.log('✅ Login appears successful (moved away from login page)');
      }
    }

    // Save authentication state
    const authFile = path.join(__dirname, '../tests/playwright/.auth/user.json');
    const authDir = path.dirname(authFile);

    if (!fs.existsSync(authDir)) {
      fs.mkdirSync(authDir, { recursive: true });
    }

    await context.storageState({ path: authFile });
    console.log(`💾 Authentication state saved to: ${authFile}`);
    console.log('\n✅ Setup complete! You can now run logged-in tests without manual login.\n');

  } catch (error) {
    console.error('\n❌ Error during authentication setup:', error.message);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

setupAuth();
