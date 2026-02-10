/**
 * One-time setup script to log in to TEST environment and save authentication state
 * This needs to be run once, and you'll need to manually solve any CAPTCHA
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

  const browser = await chromium.launch({ headless: false }); // Run in headed mode so you can solve CAPTCHA
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    console.log('📝 Navigating to TEST environment login page...');
    // Use usersc/login.php (customized version with security validation)
    await page.goto('https://test.elanregistry.org/usersc/login.php');
    await page.waitForLoadState('networkidle');

    console.log('✍️  Filling in credentials...');

    // Wait for form fields to be available
    await page.waitForSelector('input[name="username"]', { timeout: 5000 });
    await page.waitForSelector('input[name="password"]', { timeout: 5000 });

    // Fill in username and password using correct selectors
    console.log('  → Entering username...');
    await page.fill('input[name="username"]', username);
    console.log('  → Entering password...');
    await page.fill('input[name="password"]', password);

    // Click the submit button to submit the form
    console.log('🔓 Submitting login form...');
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });

    // Try to click the button - may be delayed by reCAPTCHA
    // Give it a longer timeout since reCAPTCHA might be blocking
    try {
      console.log('⏳ Attempting to submit form (may wait for reCAPTCHA)...');
      await page.click('button[type="submit"]', { timeout: 60000 }); // 60 second timeout for CAPTCHA
      console.log('✅ Login form submitted successfully');
    } catch (clickError) {
      console.log('⚠️  Could not auto-submit form (likely due to reCAPTCHA)');
    }

    console.log('\n' + '='.repeat(70));
    console.log('⚠️  IMPORTANT - PLEASE COMPLETE THESE STEPS:');
    console.log('='.repeat(70));
    console.log('1. 🤖 SOLVE reCAPTCHA - Click the "I\'m not a robot" checkbox');
    console.log('2. ⏳ Wait for reCAPTCHA verification (may take 10-30 seconds)');
    console.log('3. 📝 Verify credentials are still filled in (should be)');
    console.log('4. 🔓 Click the LOGIN button when reCAPTCHA is solved');
    console.log('5. 🔐 If 2FA/TOTP is required, enter your code');
    console.log('6. ✅ Wait for redirect to dashboard');
    console.log('\n⏳ This script will wait up to 5 minutes for successful redirect...');
    console.log('='.repeat(70) + '\n');

    // Wait for navigation away from login page (5 minute timeout)
    try {
      console.log('⏳ Waiting for login redirect (this may take a moment)...');

      // Wait for either:
      // 1. URL to change away from login.php, OR
      // 2. Dashboard/account page elements to appear
      await Promise.race([
        page.waitForFunction(
          () => !window.location.href.includes('login.php'),
          { timeout: 300000 } // 5 minutes
        ),
        page.waitForSelector('[data-testid="dashboard"], .account-page, .app-container',
          { timeout: 300000 }).catch(() => {}) // Catch but allow other condition to win
      ]);

      await page.waitForLoadState('networkidle').catch(() => {});

      const currentUrl = page.url();
      console.log(`\n✅ Login successful! Redirected to: ${currentUrl}`);
    } catch (timeoutError) {
      const currentUrl = page.url();
      console.log(`\n⏱️  Timeout or error waiting for login redirect.`);
      console.log(`Current URL: ${currentUrl}`);

      if (currentUrl.includes('login')) {
        console.log('❌ Still on login page after timeout.');
        console.log('\nPossible issues:');
        console.log('- Invalid credentials (check 1Password vault)');
        console.log('- CAPTCHA present (and not auto-solved)');
        console.log('- 2FA/TOTP required (browser window timed out waiting for input)');
        console.log('- Network connectivity issue');
        console.log('\n📝 The browser window should still be open. Please check and complete login if needed.');
        console.log('Script will continue waiting for 2 more minutes...\n');

        // Give user 2 more minutes to complete login manually if needed
        try {
          await page.waitForFunction(
            () => !window.location.href.includes('login.php'),
            { timeout: 120000 } // 2 more minutes
          );
          console.log('✅ Login completed successfully!');
        } catch (finalError) {
          console.error('❌ Login failed. Please try again.');
          process.exit(1);
        }
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
