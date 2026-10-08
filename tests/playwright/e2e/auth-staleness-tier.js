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
      `(got: ${tier || 'unset'}). It is set by playwright.config.test.js / playwright.config.prod.js.`
    );
  }
}

/**
 * Resolve the auth file and setup command for a tier and role. Fails fast
 * rather than defaulting, because a silent wrong-mode auth is what the
 * staleness setup files exist to prevent. A separate module so tests can
 * import it without registering the setup files' test().
 *
 * @param {string|undefined} tier 'test' | 'prod'
 * @param {string|undefined} role 'admin' | 'nonadmin'
 * @param {string} authDir Directory holding the saved storageState files
 * @returns {{authFile: string, setupScriptPath: string}}
 */
function resolveTierConfig(tier, role, authDir) {
  const callerFile = role === 'admin' ? 'auth-staleness-admin.setup.js' : 'auth-staleness.setup.js';
  assertValidTier(tier, { allowUnset: false }, callerFile);
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

module.exports = { resolveTierConfig, assertValidTier };
