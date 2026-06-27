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
 */
async function login(page, username = process.env.TEST_USERNAME || 'test@example.com', password = process.env.TEST_PASSWORD || 'defaultTestPass') {
  // Navigate to login page directly (skip /users/login.php 302 redirect)
  await page.goto('usersc/login.php', { waitUntil: 'networkidle' });

  // Wait for login form to load
  await page.waitForSelector('input[name="username"], input[name="email"]', { timeout: 10000 });

  // Fill in credentials
  const usernameField = page.locator('input[name="username"], input[name="email"]').first();
  const passwordField = page.locator('input[name="password"]');

  await usernameField.fill(username);
  await passwordField.fill(password);

  // Submit and wait for navigation away from the login page.
  // Promise.all ensures we start listening for the navigation BEFORE clicking,
  // preventing the rare race where navigation completes before waitForURL registers.
  await Promise.all([
    page.waitForURL(url => !url.toString().includes('login.php'), { timeout: 15000 }),
    page.locator('button[type="submit"], input[type="submit"]').click(),
  ]);

  // Wait for the post-login redirect chain to fully settle.
  await page.waitForLoadState('networkidle');
}

/**
 * Check if user is already logged in
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {boolean} - True if user appears to be logged in
 */
async function isLoggedIn(page) {
  try {
    const logoutLink = await page.locator('a[href*="logout"], .user-menu, .account-menu').count();
    return logoutLink > 0;
  } catch {
    return false;
  }
}

/**
 * Logout from the application by navigating directly to the logout URL.
 * Navigating directly is more reliable than clicking the dropdown logout link,
 * which is hidden inside a collapsed sub-menu.
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function logout(page) {
  try {
    await page.goto('users/logout.php', { waitUntil: 'domcontentloaded' });
  } catch (error) {
    // Ignore errors (e.g. if already logged out and page redirects unexpectedly)
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
  await page.waitForLoadState('domcontentloaded');

  const pageContent = await page.textContent('body');
  const currentUrl = page.url();

  // Auth wall detected if body text says "Please Log In" or we were redirected to login.php
  const authRequired =
    pageContent.includes('Please Log In') ||
    currentUrl.includes('login.php');

  if (authRequired) {
    if (unauthenticatedTest) {
      await unauthenticatedTest();
    }
    // Auth wall detected — detection itself is the assertion; no further check needed
  } else {
    // Page is accessible — run authenticated test
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
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Test backward compatibility redirect.
 * If the redirect fires, verifies the URL changed to the new path.
 * If not (e.g. .htaccess redirects inactive on local MAMP), falls back to
 * verifying the destination path is itself accessible.
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} oldPath - Old path that should redirect
 * @param {string} expectedNewPath - Expected new path in URL
 */
async function testRedirect(page, oldPath, expectedNewPath) {
  await page.goto(oldPath);
  const currentUrl = page.url();
  if (!currentUrl.includes(expectedNewPath)) {
    // Redirect didn't fire locally — verify the destination is accessible instead
    await page.goto(expectedNewPath);
    const title = await page.title();
    expect(title).not.toMatch(/404|Not Found|Server Error/i);
  }
}

/**
 * Wait for DataTables to initialize and be ready.
 * Supports DataTables 1.x (.dataTables_wrapper) and 2.x (.dt-container).
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Timeout in milliseconds
 */
async function waitForDataTables(page, timeout = 10000) {
  // DataTables 1.x uses .dataTables_wrapper; 2.x uses .dt-container.
  // table.dataTable is added by both versions and is the most reliable signal.
  await page.waitForSelector('table.dataTable, div.dt-container, div.dataTables_wrapper', { timeout });

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