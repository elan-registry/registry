// tests/playwright/auth-staleness-tier.spec.js
// Pure-logic coverage for e2e/auth-staleness-tier.js's resolveTierConfig
// (#1935) — no real browser, no network, no real site. That function is the
// only actual logic behind auth-staleness.setup.js: which saved storageState
// and which 1Password re-auth script a given E2E_AUTH_TIER selects, and the
// fail-fast guard that refuses to default to the prod credentials.
//
// Lives at tests/playwright/ root (not e2e/) so the `chromium` project picks
// it up — that project's testIgnore excludes '**/e2e/**' entirely. Mirrors
// auth-staleness-check.spec.js and fixtures.spec.js.
//
// resolveTierConfig takes the tier and auth directory as arguments rather
// than reading process.env/__dirname itself, so these are plain calls: no env
// mutation, no require-cache juggling, and no import of the setup file (which
// would register its Playwright test() into this suite).

const path = require('path');
const { test, expect } = require('@playwright/test');
const { resolveTierConfig } = require('./e2e/auth-staleness-tier');

const AUTH_DIR = path.join(__dirname, '.auth');

test.describe('resolveTierConfig (#1935)', () => {
  test("tier 'test' resolves to the test auth file and test setup script", () => {
    const config = resolveTierConfig('test', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user-test.json'));
    expect(config.setupScriptPath).toBe('./scripts/playwright-auth-1password-test.sh');
  });

  test("tier 'prod' resolves to the prod auth file and prod setup script", () => {
    const config = resolveTierConfig('prod', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user.json'));
    expect(config.setupScriptPath).toBe('./scripts/playwright-auth-1password.sh');
  });

  test('the two tiers never share an auth file or setup script', () => {
    const testTier = resolveTierConfig('test', AUTH_DIR);
    const prodTier = resolveTierConfig('prod', AUTH_DIR);

    expect(testTier.authFile).not.toBe(prodTier.authFile);
    expect(testTier.setupScriptPath).not.toBe(prodTier.setupScriptPath);
  });

  test('an unset tier throws the fail-fast error rather than defaulting to prod', () => {
    expect(() => resolveTierConfig(undefined, AUTH_DIR))
      .toThrow(/requires E2E_AUTH_TIER to be 'test' or 'prod'/);
    expect(() => resolveTierConfig(undefined, AUTH_DIR)).toThrow(/got: unset/);
    expect(() => resolveTierConfig('', AUTH_DIR)).toThrow(/got: unset/);
  });

  test('a misspelled tier throws the fail-fast error rather than defaulting to prod', () => {
    expect(() => resolveTierConfig('bogus', AUTH_DIR))
      .toThrow(/requires E2E_AUTH_TIER to be 'test' or 'prod'/);
    expect(() => resolveTierConfig('bogus', AUTH_DIR)).toThrow(/got: bogus/);

    // Case sensitivity is deliberate — a near-miss must not silently pass.
    expect(() => resolveTierConfig('Prod', AUTH_DIR)).toThrow(/got: Prod/);
    expect(() => resolveTierConfig('TEST', AUTH_DIR)).toThrow(/got: TEST/);
  });
});
