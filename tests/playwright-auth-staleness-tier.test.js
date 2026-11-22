'use strict';

/**
 * Unit tests for resolveTierConfig in tests/playwright/e2e/auth-staleness-tier.js
 * (#1935, #2035): which auth file and setup command a (tier, role) pair
 * selects, and the fail-fast guard that refuses to default to a more
 * sensitive combination.
 *
 * Run with: node --test tests/playwright-auth-staleness-tier.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { resolveTierConfig } = require('./playwright/e2e/auth-staleness-tier');

const AUTH_DIR = path.join(__dirname, 'playwright', '.auth');

describe('resolveTierConfig (#1935, #2035)', () => {
    for (const [tier, role] of [['test', 'admin'], ['test', 'nonadmin'], ['prod', 'admin'], ['prod', 'nonadmin']]) {
        test(`tier '${tier}' + role '${role}' resolves to its own auth file and setup command`, () => {
            assert.deepEqual(resolveTierConfig(tier, role, AUTH_DIR), {
                authFile: path.join(AUTH_DIR, `user-${tier}-${role}.json`),
                setupScriptPath: `node scripts/playwright-auth-setup.js ${tier} ${role}`,
            });
        });
    }

    test('an unset tier throws the fail-fast error rather than defaulting to prod', () => {
        assert.throws(() => resolveTierConfig(undefined, 'admin', AUTH_DIR), /requires E2E_AUTH_TIER to be 'test' or 'prod'/);
        assert.throws(() => resolveTierConfig(undefined, 'admin', AUTH_DIR), /got: unset/);
        assert.throws(() => resolveTierConfig('', 'admin', AUTH_DIR), /got: ""/);
    });

    test('a misspelled tier throws the fail-fast error rather than defaulting to prod', () => {
        assert.throws(() => resolveTierConfig('bogus', 'admin', AUTH_DIR), /requires E2E_AUTH_TIER to be 'test' or 'prod'/);
        assert.throws(() => resolveTierConfig('bogus', 'admin', AUTH_DIR), /got: "bogus"/);

        // Case sensitivity is deliberate: a near-miss must not silently pass.
        assert.throws(() => resolveTierConfig('Prod', 'admin', AUTH_DIR), /got: "Prod"/);
        assert.throws(() => resolveTierConfig('TEST', 'admin', AUTH_DIR), /got: "TEST"/);
    });

    test('an unset role throws the fail-fast error rather than defaulting to admin', () => {
        assert.throws(() => resolveTierConfig('test', undefined, AUTH_DIR), /requires role to be 'admin' or 'nonadmin'/);
        assert.throws(() => resolveTierConfig('test', undefined, AUTH_DIR), /got: unset/);
        assert.throws(() => resolveTierConfig('test', '', AUTH_DIR), /got: unset/);
    });

    test('a misspelled role throws the fail-fast error rather than defaulting to admin', () => {
        assert.throws(() => resolveTierConfig('test', 'bogus', AUTH_DIR), /requires role to be 'admin' or 'nonadmin'/);
        assert.throws(() => resolveTierConfig('test', 'bogus', AUTH_DIR), /got: bogus/);

        // Case sensitivity is deliberate: a near-miss must not silently pass.
        assert.throws(() => resolveTierConfig('test', 'Admin', AUTH_DIR), /got: Admin/);
        assert.throws(() => resolveTierConfig('test', 'NONADMIN', AUTH_DIR), /got: NONADMIN/);
    });

    test('a valid tier with an invalid role still fails fast (tier check does not mask role check)', () => {
        assert.throws(() => resolveTierConfig('prod', 'superadmin', AUTH_DIR), /requires role to be 'admin' or 'nonadmin'/);
    });
});
