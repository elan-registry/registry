const path = require('path');

/**
 * Validate E2E_AUTH_TIER: 'test', 'prod', or (with allowUnset) undefined.
 * Throws on anything else, so a stray shell export fails loudly.
 *
 * @param {string|undefined} tier
 * @param {{allowUnset?: boolean}} [opts] allowUnset accepts undefined (Local/Dev).
 * @param {string} [callerName] Included in the thrown message.
 */
function assertValidTier(tier, opts = {}, callerName = 'this file') {
  const { allowUnset = false } = opts;
  // An empty string is set-but-invalid, not unset.
  if (tier === undefined && allowUnset) {
    return;
  }
  if (tier !== 'test' && tier !== 'prod') {
    throw new Error(
      `${callerName} requires E2E_AUTH_TIER to be 'test'${allowUnset ? ", 'prod', or unset" : " or 'prod'"} ` +
      `(got: ${tier === undefined ? 'unset' : JSON.stringify(tier)}). playwright.config.test.js / playwright.config.prod.js set it. ` +
      'Also check .env.local and the shell environment.'
    );
  }
}

/**
 * Resolve the admin auth file and setup command for a tier. Fails fast
 * rather than defaulting, because a silent wrong-mode auth is what the
 * staleness setup file exists to prevent. A separate module so tests can
 * import it without registering the setup file's test().
 *
 * Only the admin role has a Test/Prod project (#2471 removed the non-admin
 * one), so the role is fixed.
 *
 * @param {string|undefined} tier 'test' | 'prod'
 * @param {string} authDir Directory holding the saved storageState files
 * @returns {{authFile: string, setupScriptPath: string}}
 */
function resolveTierConfig(tier, authDir) {
  assertValidTier(tier, { allowUnset: false }, 'auth-staleness-admin.setup.js');

  return {
    authFile: path.join(authDir, `user-${tier}-admin.json`),
    setupScriptPath: `node scripts/playwright-auth-setup.js ${tier} admin`,
  };
}

module.exports = { resolveTierConfig, assertValidTier };
