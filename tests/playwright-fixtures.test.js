'use strict';

/**
 * Unit tests for resolveCarIdWithHistory in tests/playwright/fixtures.js.
 * Each test calls the function with a fake env object, so no test depends
 * on the developer's real .env.local.
 *
 * Run with: node --test tests/playwright-fixtures.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
// fixtures.js resolves CAR_ID_WITH_HISTORY from process.env when it loads.
// Remove shell values, so a bad export cannot fail this file before a test runs.
delete process.env.CAR_ID_WITH_HISTORY;
delete process.env.E2E_AUTH_TIER;
const { resolveCarIdWithHistory } = require('./playwright/fixtures.js');

describe('resolveCarIdWithHistory', () => {
    for (const [tier, expected] of [['test', 2], ['prod', 1091], [undefined, 1091]]) {
        test(`tier ${tier ?? 'unset'} resolves to ${expected}`, () => {
            assert.equal(resolveCarIdWithHistory({ E2E_AUTH_TIER: tier }), expected);
        });
    }

    test('an override wins over the tier default', () => {
        assert.equal(resolveCarIdWithHistory({ E2E_AUTH_TIER: 'test', CAR_ID_WITH_HISTORY: '42' }), 42);
    });

    test('an empty override uses the tier default', () => {
        assert.equal(resolveCarIdWithHistory({ E2E_AUTH_TIER: 'test', CAR_ID_WITH_HISTORY: '' }), 2);
    });

    for (const bad of ['abc', '0', '-5', '1.5']) {
        test(`override ${JSON.stringify(bad)} throws and names the variable and value`, () => {
            assert.throws(
                () => resolveCarIdWithHistory({ CAR_ID_WITH_HISTORY: bad }),
                (err) => err.message.includes('CAR_ID_WITH_HISTORY') && err.message.includes(JSON.stringify(bad))
            );
        });
    }

    test('an empty tier throws and does not use the default', () => {
        assert.throws(
            () => resolveCarIdWithHistory({ E2E_AUTH_TIER: '' }),
            /E2E_AUTH_TIER.*got: ""/
        );
    });

    test('an unknown tier throws', () => {
        assert.throws(
            () => resolveCarIdWithHistory({ E2E_AUTH_TIER: 'staging' }),
            /E2E_AUTH_TIER.*staging/
        );
    });
});
