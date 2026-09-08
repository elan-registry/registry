const path = require('path');

/**
 * Resolve the per-tier auth file and 1Password setup script for a given
 * E2E_AUTH_TIER value.
 *
 * Fails fast on an unset/misspelled tier rather than silently falling through
 * to 'prod' — auth-staleness.setup.js exists specifically to eliminate silent
 * wrong-mode auth behavior, so defaulting to the more sensitive tier here
 * would be exactly the failure mode it's meant to prevent.
 *
 * Lives in its own module (rather than inside auth-staleness.setup.js) so it
 * is importable for unit testing without also registering that file's
 * Playwright test() into the importing suite. Mirrors the "pure function
 * importable for testing" design of auth-staleness-check.js.
 *
 * @param {string|undefined} tier 'test' | 'prod'
 * @param {string} authDir Directory holding the saved storageState files
 * @returns {{authFile: string, setupScriptPath: string}}
 */
function resolveTierConfig(tier, authDir) {
  if (tier !== 'test' && tier !== 'prod') {
    throw new Error(
      `auth-staleness.setup.js requires E2E_AUTH_TIER to be 'test' or 'prod' (got: ${tier || 'unset'}). ` +
      `It is set by playwright.config.test.js / playwright.config.prod.js.`
    );
  }

  return {
    authFile: path.join(authDir, tier === 'test' ? 'user-test.json' : 'user.json'),
    setupScriptPath: tier === 'test'
      ? './scripts/playwright-auth-1password-test.sh'
      : './scripts/playwright-auth-1password.sh',
  };
}

module.exports = { resolveTierConfig };
