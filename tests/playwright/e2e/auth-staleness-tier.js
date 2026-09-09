const path = require('path');

/**
 * Resolve the per-(tier, role) auth file and setup command for a given
 * E2E_AUTH_TIER value and hardcoded role.
 *
 * Fails fast on an unset/misspelled tier or role rather than silently
 * falling through to a default — auth-staleness.setup.js /
 * auth-staleness-admin.setup.js exist specifically to eliminate silent
 * wrong-mode auth behavior, so defaulting to the more sensitive combination
 * here would be exactly the failure mode they're meant to prevent.
 *
 * Lives in its own module (rather than inside the *.setup.js files) so it
 * is importable for unit testing without also registering those files'
 * Playwright test() into the importing suite. Mirrors the "pure function
 * importable for testing" design of auth-staleness-check.js.
 *
 * @param {string|undefined} tier 'test' | 'prod'
 * @param {string|undefined} role 'admin' | 'nonadmin'
 * @param {string} authDir Directory holding the saved storageState files
 * @returns {{authFile: string, setupScriptPath: string}}
 */
function resolveTierConfig(tier, role, authDir) {
  if (tier !== 'test' && tier !== 'prod') {
    throw new Error(
      `auth-staleness.setup.js requires E2E_AUTH_TIER to be 'test' or 'prod' (got: ${tier || 'unset'}). ` +
      `It is set by playwright.config.test.js / playwright.config.prod.js.`
    );
  }
  if (role !== 'admin' && role !== 'nonadmin') {
    throw new Error(
      `resolveTierConfig requires role to be 'admin' or 'nonadmin' (got: ${role || 'unset'}). ` +
      `It is hardcoded by auth-staleness.setup.js (nonadmin) / auth-staleness-admin.setup.js (admin).`
    );
  }

  return {
    authFile: path.join(authDir, `user-${tier}-${role}.json`),
    setupScriptPath: `node scripts/playwright-auth-setup.js ${tier} ${role}`,
  };
}

module.exports = { resolveTierConfig };
