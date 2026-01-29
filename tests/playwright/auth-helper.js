// tests/playwright/auth-helper.js

/**
 * Enhanced authentication helper for Playwright tests
 * Consolidates all authentication patterns and page state detection
 */

const { expect } = require('@playwright/test');

/**
 * Login to the application with provided credentials
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @param {boolean} skipRecaptcha - Skip reCAPTCHA handling for testing (default: false)
 */
async function login(page, username = process.env.TEST_USERNAME || 'test@example.com', password = process.env.TEST_PASSWORD || 'defaultTestPass', skipRecaptcha = false) {
  // Navigate to login page directly (skip /users/login.php 302 redirect)
  await page.goto('usersc/login.php', { waitUntil: 'networkidle' });
  
  // Wait for login form to load
  await page.waitForSelector('input[name="username"], input[name="email"]', { timeout: 10000 });
  
  // Check if reCAPTCHA is present
  const recaptchaElement = await page.locator('.g-recaptcha').count();
  
  if (recaptchaElement > 0 && !skipRecaptcha) {
    throw new Error('reCAPTCHA is enabled on login form. Automated login not possible without solving CAPTCHA. Use manual testing for reCAPTCHA-protected forms.');
  }
  
  // Fill in credentials
  const usernameField = page.locator('input[name="username"], input[name="email"]').first();
  const passwordField = page.locator('input[name="password"]');
  
  await usernameField.fill(username);
  await passwordField.fill(password);
  
  // Submit the form
  await page.click('button[type="submit"], input[type="submit"]');
  
  // Wait for successful login (redirect or success indicator)
  await page.waitForTimeout(2000);
  
  // Verify login was successful by checking for logout link or user area
  try {
    await page.waitForSelector('a[href*="logout"], .user-menu, .account-menu', { timeout: 5000 });
  } catch (error) {
    // If no logout link found, check if we're on a dashboard/account page
    const currentUrl = page.url();
    if (!currentUrl.includes('login') && !currentUrl.includes('error')) {
      // Assume login was successful if we're not on login page
      return;
    }
    
    // Check if we failed due to reCAPTCHA
    if (recaptchaElement > 0) {
      throw new Error('Login failed - likely due to reCAPTCHA verification requirement');
    }
    
    throw new Error('Login may have failed - no logout link or account area found');
  }
}

/**
 * Check if user is already logged in
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {boolean} - True if user appears to be logged in
 */
async function isLoggedIn(page) {
  try {
    // Check for logout link or user menu
    const logoutLink = await page.locator('a[href*="logout"], .user-menu, .account-menu').count();
    return logoutLink > 0;
  } catch {
    return false;
  }
}

/**
 * Logout from the application
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function logout(page) {
  try {
    const logoutLink = page.locator('a[href*="logout"]').first();
    if (await logoutLink.count() > 0) {
      await logoutLink.click();
      await page.waitForTimeout(1000);
    }
  } catch (error) {
    // Ignore errors if logout link not found
  }
}

/**
 * Ensure user is logged in before running test
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 */
async function ensureLoggedIn(page, username = process.env.TEST_USERNAME || 'test@example.com', password = process.env.TEST_PASSWORD || 'defaultTestPass') {
  const alreadyLoggedIn = await isLoggedIn(page);
  if (!alreadyLoggedIn) {
    await login(page, username, password);
  }
}

/**
 * Check if page requires authentication and handle appropriately
 * Consolidates the repeated auth check pattern from all test files
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {Function} authenticatedTest - Function to run if authenticated
 * @param {Function} unauthenticatedTest - Function to run if auth required (optional)
 */
async function handleAuthRequired(page, authenticatedTest, unauthenticatedTest = null) {
  await page.waitForLoadState('networkidle');
  
  const pageContent = await page.textContent('body');
  
  if (pageContent.includes('Please Log In')) {
    // Page requires authentication
    if (unauthenticatedTest) {
      await unauthenticatedTest();
    } else {
      // Default behavior - verify login requirement
      await expect(page.locator('h2')).toContainText(/Please Log In/);
    }
  } else {
    // Page is accessible - run authenticated test
    await authenticatedTest();
  }
}

/**
 * Navigate to a path and wait for load, using baseURL
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} path - Path to navigate to (without baseURL)
 */
async function navigateAndWait(page, path) {
  await page.goto(path);
  await page.waitForLoadState('networkidle');
}

/**
 * Test backward compatibility redirect
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} oldPath - Old path that should redirect
 * @param {string} expectedNewPath - Expected new path in URL
 */
async function testRedirect(page, oldPath, expectedNewPath) {
  await page.goto(oldPath);
  await expect(page.url()).toContain(expectedNewPath);
}

/**
 * Wait for DataTables to initialize and be ready
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Timeout in milliseconds
 */
async function waitForDataTables(page, timeout = 10000) {
  await page.waitForSelector('.dataTables_wrapper', { timeout });
  
  // Ensure search box is visible and functional
  const searchBox = page.locator('input[type="search"]');
  await expect(searchBox).toBeVisible();
  return searchBox;
}

/**
 * Get the first visible card element on a page
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Locator} First visible card
 */
async function getFirstCard(page) {
  const cards = page.locator('.card, .registry-card');
  const cardCount = await cards.count();
  
  if (cardCount === 0) {
    throw new Error('No cards found on page');
  }
  
  return cards.first();
}

/**
 * Check for consistent Bootstrap card structure
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function validateCardStructure(page) {
  const firstCard = await getFirstCard(page);
  await expect(firstCard).toBeVisible();
  
  // Should have header and/or body
  const hasHeader = await firstCard.locator('.card-header').count();
  const hasBody = await firstCard.locator('.card-body').count();
  
  expect(hasHeader + hasBody).toBeGreaterThan(0);
}

module.exports = {
  login,
  isLoggedIn,
  logout,
  ensureLoggedIn,
  handleAuthRequired,
  navigateAndWait,
  testRedirect,
  waitForDataTables,
  getFirstCard,
  validateCardStructure
};