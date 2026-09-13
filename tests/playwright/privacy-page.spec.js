// tests/playwright/privacy-page.spec.js
//
// Coverage for app/owner/privacy.php (issue #1253).
//
// Confirms the securePage()-gated privacy policy page renders for a logged-in
// owner: the card heading and at least one section of the static policy
// content (not just page chrome).
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Privacy page', () => {

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('renders the privacy policy card and content', async ({ page }) => {
    await page.goto('app/owner/privacy.php', { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    test.skip(
      currentUrl.includes('login') || currentUrl.includes('Please Log In'),
      'Session not established locally — skipping privacy page assertion'
    );

    expect(currentUrl).toContain('privacy.php');

    await expect(page.locator('h2:has-text("Privacy Policy")')).toBeVisible();

    // Confirms the static policy content actually rendered, not just page
    // chrome — the GDPR rights section heading is stable content from the
    // heredoc. (Its permalink anchor shares the id but is aria-hidden and
    // empty, so the visible assertion targets the heading text instead.)
    await expect(page.locator('h2:has-text("Your rights under GDPR")')).toBeVisible();
  });

});
