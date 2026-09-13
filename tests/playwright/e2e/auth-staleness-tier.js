const path = require('path');

/**
 * Validate a raw E2E_AUTH_TIER value against the one vocabulary this suite
 * recognizes: exactly 'test', 'prod', or undefined (unset — meaning
 * Local/Dev, which doesn't set this var at all). Throws on anything else,
 * including a truthy-but-wrong value — an unrelated shell export landing on
 * this name must fail loudly rather than be silently treated as a tier or
 * silently treated as unset.
 *
 * Shared by resolveTierConfig() below (requires exactly 'test'/'prod') and
 * by not-logged-in.spec.js (also accepts 'unset', since that file needs to
 * distinguish Local/Dev from Test/Prod, not just validate a specific tier).
 *
 * @param {string|undefined} tier
 * @param {{allowUnset?: boolean}} [opts] Pass {allowUnset: true} to accept
 *   undefined as valid (Local/Dev). Default: undefined is invalid — callers
 *   that always run under a tier-setting config (the *.setup.js files) want
 *   an unset tier to fail just as loudly as a garbage one.
 * @param {string} [callerName] Included in the thrown message for context.
 */
function assertValidTier(tier, opts = {}, callerName = 'this file') {
  const { allowUnset = false } = opts;
  // Only a genuinely unset var (undefined) is treated as "no tier" — an
  // empty string is a distinct, invalid value (someone set the var to
  // nothing) and must still fail loudly, not be silently accepted here.
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
  assertValidTier(tier, { allowUnset: false }, 'auth-staleness.setup.js');
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
