const { expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

/**
 * Logs in to the site and saves the authentication state
 * This allows reusing the session across tests without re-logging in
 */
async function loginAndSaveState(page, username, password) {
  const authFile = path.join(__dirname, '../.auth/user.json');

  // Check if we already have a saved session
  if (fs.existsSync(authFile)) {
    console.log('Using existing authentication state');
    return;
  }

  console.log('Logging in and saving authentication state...');

  await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

  // Fill in login form - adjust selectors based on actual form
  await page.fill('input[name="username"], input[type="text"]', username);
  await page.fill('input[name="password"], input[type="password"]', password);

  // If there's a CAPTCHA, you'll need to solve it manually the first time
  // The script will pause here to give you time
  console.log('If there is a CAPTCHA, please solve it manually...');

  // Wait for manual CAPTCHA solving if needed
  // You can adjust the timeout or add a specific check
  await page.click('button[type="submit"], input[type="submit"]');

  // Wait for successful login - adjust selector based on your site
  // For example, wait for a logout button or user menu to appear
  await page.waitForLoadState('domcontentloaded');

  // Save the authentication state
  await page.context().storageState({ path: authFile });
  console.log('Authentication state saved!');
}

/**
 * Simple login function for tests that don't use session persistence
 */
async function login(page, username, password) {
  await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

  await page.fill('input[name="username"], input[type="text"]', username);
  await page.fill('input[name="password"], input[type="password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');

  await page.waitForLoadState('domcontentloaded');
}

module.exports = {
  login,
  loginAndSaveState,
};
