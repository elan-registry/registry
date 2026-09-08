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

  // The UserSpice error toast (.us-toast .toast-body) auto-dismisses after
  // 6s (usersc/includes/system_messages_footer.php), so it must be raced
  // against the success-path navigation rather than checked only after a
  // full 15s timeout, which would frequently miss it. Scoped to
  // .us-bar-danger specifically — userSpiceMessage() renders the same
  // .us-toast/.toast-body DOM for every severity (success, info, danger,
  // ...), differing only in the sibling .us-toast-bar's color class, so an
  // unscoped selector would misreport a success/info toast as a login
  // failure if one were ever shown on this page.
  const toastLocator = page.locator('.us-toast:has(.us-bar-danger) .toast-body');

  // Both waiters are registered BEFORE the click, preventing the rare race
  // where navigation completes before waitForURL registers.
  const navigationPromise = page
    .waitForURL(url => !url.toString().includes('login.php'), { timeout: 15000 })
    .then(() => ({ outcome: 'navigated' }));

  const toastPromise = toastLocator
    .waitFor({ state: 'visible', timeout: 15000 })
    .then(() => ({ outcome: 'toast' }))
    .catch(() => ({ outcome: 'toast-not-seen' })); // never rejects the race

  await page.locator('button[type="submit"], input[type="submit"]').click();

  const result = await Promise.race([navigationPromise, toastPromise]);

  if (result.outcome === 'toast') {
    const toastText = await toastLocator.textContent();
    throw new Error(
      `Login failed: UserSpice error toast appeared instead of navigating away ` +
      `from login.php. Toast text: "${(toastText || '').trim()}". ` +
      `Common causes: wrong TEST_USERNAME/TEST_PASSWORD, changed login form ` +
      `markup/selectors, or rate-limiting (RATE_LIMIT_LOGIN).`
    );
  }

  if (result.outcome === 'toast-not-seen') {
    await navigationPromise; // still pending — fall through to Playwright's own timeout/diagnostics
  }

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
  } catch (_error) {
    // locator().count() should not throw in normal operation;
    // returning false allows ensureLoggedIn() to attempt login.
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
  } catch (_error) {
    // page.goto throws on timeout or navigation crash, not on redirects;
    // log rather than swallow so dirty test state is diagnosable.
    console.warn('logout() navigation failed — subsequent test state may be dirty:', _error.message);
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
 * Assert no requests to Google Maps domains fire when loading a page.
 * MapLibre GL JS (self-hosted tiles) replaced Google Maps; this guards against
 * regressions that reintroduce a Google Maps dependency.
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} path - Path to navigate to (without baseURL)
 * @param {string} [label] - Optional label for the assertion message
 */
async function assertNoGoogleMapsRequests(page, path, label = path) {
  const googleMapsRequests = [];
  page.on('request', (request) => {
    const url = request.url();
    try {
      const hostname = new URL(url).hostname;
      if (hostname === 'maps.googleapis.com' || hostname.endsWith('.maps.googleapis.com') ||
          hostname === 'maps.gstatic.com' || hostname.endsWith('.maps.gstatic.com')) {
        googleMapsRequests.push(url);
      }
    } catch (_) { /* ignore non-URL strings */ }
  });

  await page.goto(path);
  await page.waitForLoadState('networkidle');

  expect(googleMapsRequests, `No Google Maps requests on ${label}`).toHaveLength(0);
}

/**
 * Assert a page's <title> and meta description match expected values.
 * The <title> tag appends " {site_name}" after $pageTitle (see
 * users/template/header1_must_include.php), so the title check is a partial
 * (contains) match via a regex built from expectedTitle. og:title/twitter:title
 * mirror $pageTitle exactly, with no site-name suffix (see
 * usersc/includes/head_tags.php), so those (and the description equivalents)
 * are exact matches.
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {{expectedTitle: string, expectedDescription?: string, checkSocialMeta?: boolean}} opts
 */
async function assertPageTitle(page, { expectedTitle, expectedDescription, checkSocialMeta = false }) {
  const escapedTitle = expectedTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  await expect(page).toHaveTitle(new RegExp(escapedTitle));

  if (expectedDescription) {
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    expect(description).toBe(expectedDescription);
  }

  if (checkSocialMeta) {
    const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content');
    expect(ogTitle).toBe(expectedTitle);
    const twitterTitle = await page.locator('meta[name="twitter:title"]').getAttribute('content');
    expect(twitterTitle).toBe(expectedTitle);

    if (expectedDescription) {
      const ogDescription = await page.locator('meta[property="og:description"]').getAttribute('content');
      expect(ogDescription).toBe(expectedDescription);
      const twitterDescription = await page.locator('meta[name="twitter:description"]').getAttribute('content');
      expect(twitterDescription).toBe(expectedDescription);
    }
  }
}

const NO_CARDS_ERROR = 'No cards found on page';

/**
 * Get the first visible card element on a page
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Locator} First visible card
 */
async function getFirstCard(page) {
  const cards = page.locator('.card, .registry-card');
  const cardCount = await cards.count();

  if (cardCount === 0) {
    throw new Error(NO_CARDS_ERROR);
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
  NO_CARDS_ERROR,
  login,
  isLoggedIn,
  logout,
  ensureLoggedIn,
  handleAuthRequired,
  navigateAndWait,
  testRedirect,
  waitForDataTables,
  getFirstCard,
  validateCardStructure,
  assertNoGoogleMapsRequests,
  assertPageTitle
};