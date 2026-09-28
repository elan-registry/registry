'use strict';

/**
 * Unit tests for resolveBaseUrl and readEnvFileKey in
 * tests/playwright/resolve-base-url.js, and for the value base-url.js exports.
 * Each test calls the function with a fake env object and a temp .env
 * file, so no test depends on the developer's real .env or .env.local.
 *
 * Run with: node --test tests/playwright-base-url.test.js
 */

const { test, describe, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { resolveBaseUrl, readEnvFileKey } = require('./playwright/resolve-base-url.js');

const repoRoot = path.join(__dirname, '..');

let tmpDir;

function writeEnvFile(name, content) {
    const filePath = path.join(tmpDir, name);
    fs.writeFileSync(filePath, content);
    return filePath;
}

describe('resolveBaseUrl', () => {
    before(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'resolve-base-url-'));
    });

    after(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('PLAYWRIGHT_BASE_URL wins over APP_HOST_PORT in env and in .env', () => {
        const envFile = writeEnvFile('override.env', 'APP_HOST_PORT=8003\n');
        const env = { PLAYWRIGHT_BASE_URL: 'http://example.test:1234/sub/', APP_HOST_PORT: '8002' };
        assert.equal(resolveBaseUrl(env, envFile), 'http://example.test:1234/sub/');
    });

    test('APP_HOST_PORT in env wins over APP_HOST_PORT in .env', () => {
        const envFile = writeEnvFile('env-port.env', 'APP_HOST_PORT=8003\n');
        assert.equal(resolveBaseUrl({ APP_HOST_PORT: '8002' }, envFile), 'http://localhost:8002/');
    });

    test('APP_HOST_PORT only in .env is used', () => {
        const envFile = writeEnvFile('file-port.env', 'DB_PASS=secret\nAPP_HOST_PORT=8003\n');
        assert.equal(resolveBaseUrl({}, envFile), 'http://localhost:8003/');
    });

    test('a missing .env file does not throw and gives the default', () => {
        const missing = path.join(tmpDir, 'does-not-exist.env');
        assert.equal(resolveBaseUrl({}, missing), 'http://localhost:8001/');
    });

    test('a .env file without APP_HOST_PORT gives the default', () => {
        const envFile = writeEnvFile('no-port.env', 'DB_HOST=db\nDB_PASS=secret\n');
        assert.equal(resolveBaseUrl({}, envFile), 'http://localhost:8001/');
    });

    test('nothing set gives http://localhost:8001/', () => {
        assert.equal(resolveBaseUrl({}, path.join(tmpDir, 'none.env')), 'http://localhost:8001/');
    });

    test('empty strings count as not set', () => {
        const envFile = writeEnvFile('empty-port.env', 'APP_HOST_PORT=\n');
        const env = { PLAYWRIGHT_BASE_URL: '', APP_HOST_PORT: '' };
        assert.equal(resolveBaseUrl(env, envFile), 'http://localhost:8001/');
    });

    test('the result always ends with a trailing slash', () => {
        const missing = path.join(tmpDir, 'none.env');
        assert.equal(resolveBaseUrl({ PLAYWRIGHT_BASE_URL: 'http://localhost:9000' }, missing), 'http://localhost:9000/');
        assert.equal(resolveBaseUrl({ PLAYWRIGHT_BASE_URL: 'http://localhost:9000/app' }, missing), 'http://localhost:9000/app/');
        assert.ok(resolveBaseUrl({ APP_HOST_PORT: '8002' }, missing).endsWith('/'));
        assert.ok(resolveBaseUrl({}, missing).endsWith('/'));
    });

    test('the env argument and process.env are not changed', () => {
        const envFile = writeEnvFile('pollution.env', 'APP_HOST_PORT=8003\nRESOLVE_BASE_URL_TEST_SECRET=secret\n');
        const env = Object.freeze({ UNRELATED: 'x' });
        const processEnvBefore = { ...process.env };

        assert.equal(resolveBaseUrl(env, envFile), 'http://localhost:8003/');

        assert.deepEqual(env, { UNRELATED: 'x' });
        assert.deepEqual({ ...process.env }, processEnvBefore);
        assert.equal(process.env.RESOLVE_BASE_URL_TEST_SECRET, undefined);
    });

    test('an APP_HOST_PORT that is not an integer from 1 to 65535 throws a RangeError', () => {
        const missing = path.join(tmpDir, 'none.env');
        assert.throws(() => resolveBaseUrl({ APP_HOST_PORT: 'abc' }, missing), RangeError);
        assert.throws(() => resolveBaseUrl({ APP_HOST_PORT: '80.5' }, missing), RangeError);
        assert.throws(() => resolveBaseUrl({ APP_HOST_PORT: '0' }, missing), RangeError);
        assert.throws(() => resolveBaseUrl({ APP_HOST_PORT: '65536' }, missing), RangeError);
    });

    test('an invalid APP_HOST_PORT in .env throws a RangeError', () => {
        const envFile = writeEnvFile('bad-port.env', 'APP_HOST_PORT=eighty\n');
        assert.throws(() => resolveBaseUrl({}, envFile), RangeError);
    });

    test('wrong-typed arguments throw a TypeError', () => {
        assert.throws(() => resolveBaseUrl(null, '/x/.env'), TypeError);
        assert.throws(() => resolveBaseUrl('APP_HOST_PORT=8002', '/x/.env'), TypeError);
        assert.throws(() => resolveBaseUrl(['APP_HOST_PORT=8002'], '/x/.env'), TypeError);
        assert.throws(() => resolveBaseUrl({}, undefined), TypeError);
        assert.throws(() => resolveBaseUrl({}, ''), TypeError);
    });

    test('a non-string env value throws a TypeError that names the key and type', () => {
        const missing = path.join(tmpDir, 'none.env');
        const cases = [
            [{ APP_HOST_PORT: 8001 }, /APP_HOST_PORT must be a string, got number/],
            [{ APP_HOST_PORT: 0 }, /APP_HOST_PORT must be a string, got number/],
            [{ APP_HOST_PORT: {} }, /APP_HOST_PORT must be a string, got object/],
            [{ APP_HOST_PORT: null }, /APP_HOST_PORT must be a string, got object/],
            [{ PLAYWRIGHT_BASE_URL: 123 }, /PLAYWRIGHT_BASE_URL must be a string, got number/],
        ];
        for (const [env, message] of cases) {
            assert.throws(() => resolveBaseUrl(env, missing), { name: 'TypeError', message });
        }
    });

    test('an invalid PLAYWRIGHT_BASE_URL throws a TypeError', () => {
        const missing = path.join(tmpDir, 'none.env');
        for (const value of [' ', 'not a url', 'localhost:8001', 'ftp://localhost:8001/', '/relative/path']) {
            assert.throws(
                () => resolveBaseUrl({ PLAYWRIGHT_BASE_URL: value }, missing),
                { name: 'TypeError', message: /PLAYWRIGHT_BASE_URL/ },
                `expected a TypeError for ${JSON.stringify(value)}`
            );
        }
    });

    test('an https PLAYWRIGHT_BASE_URL is accepted', () => {
        const missing = path.join(tmpDir, 'none.env');
        assert.equal(resolveBaseUrl({ PLAYWRIGHT_BASE_URL: 'https://tunnel.example.test' }, missing), 'https://tunnel.example.test/');
    });

    test('a PLAYWRIGHT_BASE_URL with a query or fragment throws a TypeError', () => {
        const missing = path.join(tmpDir, 'none.env');
        for (const value of ['http://localhost:8001/?x=1', 'http://localhost:8001#a']) {
            assert.throws(() => resolveBaseUrl({ PLAYWRIGHT_BASE_URL: value }, missing), {
                name: 'TypeError',
                message: /query or fragment/,
            });
        }
    });

    test('a valid PLAYWRIGHT_BASE_URL is returned in its parsed form', () => {
        const missing = path.join(tmpDir, 'none.env');
        assert.equal(resolveBaseUrl({ PLAYWRIGHT_BASE_URL: ' http://localhost:8001' }, missing), 'http://localhost:8001/');
        assert.equal(resolveBaseUrl({ PLAYWRIGHT_BASE_URL: 'HTTP://LocalHost:8001/sub' }, missing), 'http://localhost:8001/sub/');
    });

    test('a read error other than ENOENT is thrown', () => {
        // tmpDir is a directory, so readFileSync fails with EISDIR.
        assert.throws(() => resolveBaseUrl({}, tmpDir), { code: 'EISDIR' });
    });
});

describe('readEnvFileKey', () => {
    before(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'read-env-file-key-'));
    });

    after(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('returns the value of the key', () => {
        const envFile = writeEnvFile('keys.env', 'DB_PASS=secret\nLANDING_HOST_PORT=8102\n');
        assert.equal(readEnvFileKey(envFile, 'LANDING_HOST_PORT'), '8102');
    });

    test('returns undefined for an inherited key such as toString', () => {
        const envFile = writeEnvFile('inherited.env', 'APP_HOST_PORT=8001\n');
        assert.equal(readEnvFileKey(envFile, 'toString'), undefined);
        assert.equal(readEnvFileKey(envFile, 'constructor'), undefined);
    });

    test('returns undefined for a key that is not in the file', () => {
        const envFile = writeEnvFile('no-key.env', 'DB_PASS=secret\n');
        assert.equal(readEnvFileKey(envFile, 'LANDING_HOST_PORT'), undefined);
    });

    test('returns an empty string for a key with no value', () => {
        const envFile = writeEnvFile('empty-key.env', 'LANDING_HOST_PORT=\n');
        assert.equal(readEnvFileKey(envFile, 'LANDING_HOST_PORT'), '');
    });

    test('returns undefined for a missing file', () => {
        assert.equal(readEnvFileKey(path.join(tmpDir, 'missing.env'), 'APP_HOST_PORT'), undefined);
    });

    test('throws a read error other than ENOENT', () => {
        assert.throws(() => readEnvFileKey(tmpDir, 'APP_HOST_PORT'), { code: 'EISDIR' });
    });

    test('does not write to process.env', () => {
        const envFile = writeEnvFile('pollution.env', 'READ_ENV_FILE_KEY_TEST_SECRET=secret\n');
        assert.equal(readEnvFileKey(envFile, 'READ_ENV_FILE_KEY_TEST_SECRET'), 'secret');
        assert.equal(process.env.READ_ENV_FILE_KEY_TEST_SECRET, undefined);
    });

    test('wrong-typed arguments throw a TypeError', () => {
        assert.throws(() => readEnvFileKey('', 'APP_HOST_PORT'), TypeError);
        assert.throws(() => readEnvFileKey(undefined, 'APP_HOST_PORT'), TypeError);
        assert.throws(() => readEnvFileKey('/x/.env', ''), TypeError);
        assert.throws(() => readEnvFileKey('/x/.env', 42), TypeError);
    });
});

describe('base-url.js', () => {
    // Guards the .env path in base-url.js. A wrong path gives ENOENT, which
    // falls back to 8001. The copy runs in a temp tree whose .env sets a
    // port that is not the default, so a wrong path cannot pass by chance.
    test('reads APP_HOST_PORT from the .env two levels above it', () => {
        const tree = fs.mkdtempSync(path.join(os.tmpdir(), 'base-url-tree-'));
        try {
            const dir = path.join(tree, 'tests', 'playwright');
            fs.mkdirSync(dir, { recursive: true });
            for (const name of ['base-url.js', 'resolve-base-url.js']) {
                fs.copyFileSync(path.join(repoRoot, 'tests', 'playwright', name), path.join(dir, name));
            }
            fs.writeFileSync(path.join(tree, '.env'), 'APP_HOST_PORT=9123\n');

            const childEnv = { ...process.env, NODE_PATH: path.join(repoRoot, 'node_modules') };
            delete childEnv.PLAYWRIGHT_BASE_URL;
            delete childEnv.APP_HOST_PORT;

            const output = execFileSync(
                process.execPath,
                ['-e', "process.stdout.write(require('./tests/playwright/base-url.js'))"],
                { cwd: tree, env: childEnv, encoding: 'utf8' }
            );

            assert.equal(output, 'http://localhost:9123/');
        } finally {
            fs.rmSync(tree, { recursive: true, force: true });
        }
    });
});
