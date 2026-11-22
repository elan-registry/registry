'use strict';

/**
 * Unit tests for resolveTierConfig in tests/playwright/e2e/auth-staleness-tier.js
 * (#1935, #2035, #2471): which admin auth file and setup command a tier
 * selects, and the fail-fast guard that refuses to default to a more
 * sensitive tier.
 *
 * Run with: node --test tests/playwright-auth-staleness-tier.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { resolveTierConfig } = require('./playwright/e2e/auth-staleness-tier');

const AUTH_DIR = path.join(__dirname, 'playwright', '.auth');

describe('resolveTierConfig (#1935, #2035, #2471)', () => {
    for (const tier of ['test', 'prod']) {
        test(`tier '${tier}' resolves to its own admin auth file and setup command`, () => {
            assert.deepEqual(resolveTierConfig(tier, AUTH_DIR), {
                authFile: path.join(AUTH_DIR, `user-${tier}-admin.json`),
                setupScriptPath: `node scripts/playwright-auth-setup.js ${tier} admin`,
            });
        });
    }

    test('an unset tier throws the fail-fast error rather than defaulting to prod', () => {
        assert.throws(() => resolveTierConfig(undefined, AUTH_DIR), /requires E2E_AUTH_TIER to be 'test' or 'prod'/);
        assert.throws(() => resolveTierConfig(undefined, AUTH_DIR), /got: unset/);
        assert.throws(() => resolveTierConfig('', AUTH_DIR), /got: ""/);
    });

    test('a misspelled tier throws the fail-fast error rather than defaulting to prod', () => {
        assert.throws(() => resolveTierConfig('bogus', AUTH_DIR), /requires E2E_AUTH_TIER to be 'test' or 'prod'/);
        assert.throws(() => resolveTierConfig('bogus', AUTH_DIR), /got: "bogus"/);

        // Case sensitivity is deliberate: a near-miss must not silently pass.
        assert.throws(() => resolveTierConfig('Prod', AUTH_DIR), /got: "Prod"/);
        assert.throws(() => resolveTierConfig('TEST', AUTH_DIR), /got: "TEST"/);
    });
});
