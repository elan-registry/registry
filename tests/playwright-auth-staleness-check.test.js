'use strict';

/**
 * Unit tests for assertAuthStillValid in tests/playwright/e2e/auth-staleness-check.js
 * (#1935). No real browser, network, or site: the module takes an injected
 * `newContext`, so each of its three outcomes runs against fakes.
 *
 * `isLoggedIn` is a real import inside the module, so the stale and valid
 * cases go through it: the real isLoggedIn reads the fake page's
 * locator().count() (see auth-helper.js), so a count of 0 gives false and
 * 1 gives true without a stub.
 *
 * Run with: node --test tests/playwright-auth-staleness-check.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { assertAuthStillValid } = require('./playwright/e2e/auth-staleness-check');

const SETUP_SCRIPT = 'node scripts/playwright-auth-setup.js test admin';
const BASE_URL = 'https://example.invalid/';

/**
 * Build a fake Playwright context whose page reports `locatorCount` matches
 * for any locator, the one value the real isLoggedIn() reads. Records calls
 * so tests can check that the context is always closed.
 *
 * @param {number} locatorCount
 * @returns {{newContext: Function, calls: {newContextOpts: ?object, gotoPaths: string[], closed: boolean}}}
 */
function makeFakeContext(locatorCount) {
    const calls = { newContextOpts: null, gotoPaths: [], closed: false };

    const page = {
        goto: async (url) => { calls.gotoPaths.push(url); },
        locator: () => ({ count: async () => locatorCount }),
    };

    const context = {
        newPage: async () => page,
        close: async () => { calls.closed = true; },
    };

    const newContext = async (opts) => {
        calls.newContextOpts = opts;
        return context;
    };

    return { newContext, calls };
}

/**
 * Write a dummy storageState file to a unique temp path. The content does not
 * matter: assertAuthStillValid only checks that the file exists and gives the
 * path to the faked newContext.
 *
 * @returns {string}
 */
function makeTempAuthFile() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'auth-staleness-'));
    const authFile = path.join(dir, 'user-test.json');
    fs.writeFileSync(authFile, JSON.stringify({ cookies: [], origins: [] }));
    return authFile;
}

describe('assertAuthStillValid (#1935)', () => {
    test('missing auth file throws before creating a context', async () => {
        const missingFile = path.join(os.tmpdir(), 'auth-staleness-does-not-exist.json');
        assert.equal(fs.existsSync(missingFile), false);

        let contextCreated = false;
        const newContext = async () => {
            contextCreated = true;
            throw new Error('newContext should not be called when the auth file is missing');
        };

        await assert.rejects(
            assertAuthStillValid(null, { authFile: missingFile, setupScriptPath: SETUP_SCRIPT, baseURL: BASE_URL, newContext }),
            (err) => err.message.includes('Auth file not found')
                && err.message.includes(missingFile)
                && err.message.includes(SETUP_SCRIPT)
        );
        assert.equal(contextCreated, false, 'newContext must not be reached');
    });

    test('present auth file with a stale session throws the re-auth message', async () => {
        const authFile = makeTempAuthFile();
        const { newContext, calls } = makeFakeContext(0);

        await assert.rejects(
            assertAuthStillValid(null, { authFile, setupScriptPath: SETUP_SCRIPT, baseURL: BASE_URL, newContext }),
            (err) => err.message.includes('is stale/expired')
                && err.message.includes(authFile)
                && err.message.includes(SETUP_SCRIPT)
        );

        // The context is closed on the error path too.
        assert.deepEqual(calls.newContextOpts, { storageState: authFile, baseURL: BASE_URL });
        assert.deepEqual(calls.gotoPaths, ['usersc/account.php']);
        assert.equal(calls.closed, true, 'context must be closed via finally');
    });

    test('present auth file with a valid session resolves without throwing', async () => {
        const authFile = makeTempAuthFile();
        const { newContext, calls } = makeFakeContext(1);

        await assertAuthStillValid(null, { authFile, setupScriptPath: SETUP_SCRIPT, baseURL: BASE_URL, newContext });

        assert.deepEqual(calls.gotoPaths, ['usersc/account.php']);
        assert.equal(calls.closed, true, 'context must be closed on the success path');
    });
});
