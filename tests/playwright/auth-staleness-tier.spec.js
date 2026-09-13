// Pure-logic coverage for resolveTierConfig (#1935, #2035) — which auth file
// and setup command is selected for a given (tier, role) pair, and the
// fail-fast guard that refuses to default to a more sensitive combination.
//
// Lives at tests/playwright/ root (not e2e/) so the chromium project picks
// it up. resolveTierConfig is importable for testing without registering
// the setup files' Playwright test() into this suite.

const path = require('path');
const { test, expect } = require('@playwright/test');
const { resolveTierConfig } = require('./e2e/auth-staleness-tier');

const AUTH_DIR = path.join(__dirname, '.auth');

test.describe('resolveTierConfig (#1935, #2035)', () => {
  test("tier 'test' + role 'admin' resolves to the test-admin auth file and setup command", () => {
    const config = resolveTierConfig('test', 'admin', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user-test-admin.json'));
    expect(config.setupScriptPath).toBe('node scripts/playwright-auth-setup.js test admin');
  });

  test("tier 'test' + role 'nonadmin' resolves to the test-nonadmin auth file and setup command", () => {
    const config = resolveTierConfig('test', 'nonadmin', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user-test-nonadmin.json'));
    expect(config.setupScriptPath).toBe('node scripts/playwright-auth-setup.js test nonadmin');
  });

  test("tier 'prod' + role 'admin' resolves to the prod-admin auth file and setup command", () => {
    const config = resolveTierConfig('prod', 'admin', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user-prod-admin.json'));
    expect(config.setupScriptPath).toBe('node scripts/playwright-auth-setup.js prod admin');
  });

  test("tier 'prod' + role 'nonadmin' resolves to the prod-nonadmin auth file and setup command", () => {
    const config = resolveTierConfig('prod', 'nonadmin', AUTH_DIR);

    expect(config.authFile).toBe(path.join(AUTH_DIR, 'user-prod-nonadmin.json'));
    expect(config.setupScriptPath).toBe('node scripts/playwright-auth-setup.js prod nonadmin');
  });

  test('all four (tier, role) combinations resolve to distinct auth files and setup commands', () => {
    const combos = [
      resolveTierConfig('test', 'admin', AUTH_DIR),
      resolveTierConfig('test', 'nonadmin', AUTH_DIR),
      resolveTierConfig('prod', 'admin', AUTH_DIR),
      resolveTierConfig('prod', 'nonadmin', AUTH_DIR),
    ];

    const authFiles = combos.map((c) => c.authFile);
    const setupCommands = combos.map((c) => c.setupScriptPath);

    expect(new Set(authFiles).size).toBe(4);
    expect(new Set(setupCommands).size).toBe(4);
  });

  test('an unset tier throws the fail-fast error rather than defaulting to prod', () => {
    expect(() => resolveTierConfig(undefined, 'admin', AUTH_DIR))
      .toThrow(/requires E2E_AUTH_TIER to be 'test' or 'prod'/);
    expect(() => resolveTierConfig(undefined, 'admin', AUTH_DIR)).toThrow(/got: unset/);
    expect(() => resolveTierConfig('', 'admin', AUTH_DIR)).toThrow(/got: unset/);
  });

  test('a misspelled tier throws the fail-fast error rather than defaulting to prod', () => {
    expect(() => resolveTierConfig('bogus', 'admin', AUTH_DIR))
      .toThrow(/requires E2E_AUTH_TIER to be 'test' or 'prod'/);
    expect(() => resolveTierConfig('bogus', 'admin', AUTH_DIR)).toThrow(/got: bogus/);

    // Case sensitivity is deliberate — a near-miss must not silently pass.
    expect(() => resolveTierConfig('Prod', 'admin', AUTH_DIR)).toThrow(/got: Prod/);
    expect(() => resolveTierConfig('TEST', 'admin', AUTH_DIR)).toThrow(/got: TEST/);
  });

  test('an unset role throws the fail-fast error rather than defaulting to admin', () => {
    expect(() => resolveTierConfig('test', undefined, AUTH_DIR))
      .toThrow(/requires role to be 'admin' or 'nonadmin'/);
    expect(() => resolveTierConfig('test', undefined, AUTH_DIR)).toThrow(/got: unset/);
    expect(() => resolveTierConfig('test', '', AUTH_DIR)).toThrow(/got: unset/);
  });

  test('a misspelled role throws the fail-fast error rather than defaulting to admin', () => {
    expect(() => resolveTierConfig('test', 'bogus', AUTH_DIR))
      .toThrow(/requires role to be 'admin' or 'nonadmin'/);
    expect(() => resolveTierConfig('test', 'bogus', AUTH_DIR)).toThrow(/got: bogus/);

    // Case sensitivity is deliberate — a near-miss must not silently pass.
    expect(() => resolveTierConfig('test', 'Admin', AUTH_DIR)).toThrow(/got: Admin/);
    expect(() => resolveTierConfig('test', 'NONADMIN', AUTH_DIR)).toThrow(/got: NONADMIN/);
  });

  test('a valid tier with an invalid role still fails fast (tier check does not mask role check)', () => {
    expect(() => resolveTierConfig('prod', 'superadmin', AUTH_DIR))
      .toThrow(/requires role to be 'admin' or 'nonadmin'/);
  });
});
