// Kept apart from base-url.js because base-url.js must export a plain
// string. A string export cannot also carry a function that tests can call
// with a fake env and a temp .env file.

const fs = require('node:fs');
const path = require('node:path');
const dotenv = require('dotenv');

// Matches the docker-compose.yml fallback `${APP_HOST_PORT:-8001}`.
const DEFAULT_PORT = '8001';

// base-url.js and global-setup.js both read the repo .env.
const REPO_ENV_PATH = path.join(__dirname, '..', '..', '.env');

/**
 * Read one key from a dotenv file.
 *
 * The file can hold DB credentials, so return only `key`; never log the file.
 *
 * @param {string} envFilePath - Path of the dotenv file. Must be a non-empty string.
 * @param {string} key - The key to read. Must be a non-empty string.
 * @returns {string | undefined} The value ('' is possible), or undefined if the file or key is missing.
 * @throws {TypeError} If `envFilePath` or `key` is not a non-empty string.
 * @throws {Error} Any read error other than ENOENT, unchanged.
 */
function readEnvFileKey(envFilePath, key) {
    if (typeof envFilePath !== 'string' || envFilePath === '') {
        throw new TypeError('readEnvFileKey: envFilePath must be a non-empty string');
    }
    if (typeof key !== 'string' || key === '') {
        throw new TypeError('readEnvFileKey: key must be a non-empty string');
    }

    let content;
    try {
        content = fs.readFileSync(envFilePath, 'utf8');
    } catch (error) {
        if (error.code === 'ENOENT') {
            return undefined;
        }
        throw error;
    }
    // Object.hasOwn skips inherited keys such as 'toString', so the result
    // stays string | undefined.
    const parsed = dotenv.parse(content);
    return Object.hasOwn(parsed, key) ? parsed[key] : undefined;
}

/**
 * Resolve the base URL for local Playwright runs and name the step that set it.
 *
 * Resolution order:
 *   1. `env.PLAYWRIGHT_BASE_URL`, if it is set.
 *   2. `env.APP_HOST_PORT`, if it is set.
 *   3. `APP_HOST_PORT` in the file at `envFilePath`, if it is set.
 *   4. Port 8001.
 * Steps 2-4 give `http://localhost:<port>/`.
 * A key is "not set" if its value is `undefined` or the empty string. This
 * matches the `${APP_HOST_PORT:-8001}` fallback in docker-compose.yml.
 *
 * The .env file holds DB credentials: only APP_HOST_PORT is read from it,
 * and nothing is copied into `env` or logged. An invalid port throws rather
 * than falling back to 8001, which would point the tests at a missing site.
 * A missing .env file is skipped.
 *
 * @param {Record<string, string | undefined>} env - The environment to read, usually `process.env`. It is not changed.
 * @param {string} envFilePath - Absolute path of the repo `.env` file.
 * @returns {{url: string, source: string}} `url` ends with `/`. `source` names
 *   the step that set it, for global-setup.js's "Cannot reach" error.
 * @throws {TypeError} For a wrong-typed argument, a non-string env value, or an invalid `PLAYWRIGHT_BASE_URL`.
 * @throws {RangeError} If `APP_HOST_PORT` is set to a value that is not a valid port.
 * @throws {Error} A read error other than ENOENT for `envFilePath`.
 */
function resolveBaseUrlWithSource(env, envFilePath) {
    if (env === null || typeof env !== 'object' || Array.isArray(env)) {
        throw new TypeError('resolveBaseUrl: env must be a non-null, non-array object');
    }
    if (typeof envFilePath !== 'string' || envFilePath === '') {
        throw new TypeError('resolveBaseUrl: envFilePath must be a non-empty string');
    }

    const baseUrl = readEnvValue(env, 'PLAYWRIGHT_BASE_URL');
    if (baseUrl !== undefined) {
        return { url: validatedUrl(baseUrl), source: 'PLAYWRIGHT_BASE_URL' };
    }

    const envPort = readEnvValue(env, 'APP_HOST_PORT');
    if (envPort !== undefined) {
        const source = 'APP_HOST_PORT in the environment';
        return { url: portUrl(envPort, source), source };
    }

    const filePort = readEnvFileKey(envFilePath, 'APP_HOST_PORT');
    if (filePort !== undefined && filePort !== '') {
        // The error names the full path, but the source stays short for the
        // "Cannot reach" message.
        return { url: portUrl(filePort, `APP_HOST_PORT in ${envFilePath}`), source: 'APP_HOST_PORT in .env' };
    }

    const source = 'the default port';
    return { url: portUrl(DEFAULT_PORT, source), source };
}

/**
 * Resolve the base URL for local Playwright runs.
 *
 * Same rules and errors as resolveBaseUrlWithSource.
 *
 * @param {Record<string, string | undefined>} env - The environment to read, usually `process.env`. It is not changed.
 * @param {string} envFilePath - Absolute path of the repo `.env` file.
 * @returns {string} The base URL. It always ends with `/`.
 */
function resolveBaseUrl(env, envFilePath) {
    return resolveBaseUrlWithSource(env, envFilePath).url;
}

// A wrong-typed value is a caller bug, so it throws instead of falling back
// to the default port and hiding the bug.
function readEnvValue(env, key) {
    const value = env[key];
    if (value === undefined || value === '') {
        return undefined;
    }
    if (typeof value !== 'string') {
        throw new TypeError(`resolveBaseUrl: ${key} must be a string, got ${typeof value}`);
    }
    return value;
}

function validatedUrl(value) {
    let url;
    try {
        url = new URL(value);
    } catch {
        throw new TypeError(`resolveBaseUrl: PLAYWRIGHT_BASE_URL must be an absolute http or https URL, got "${value}"`);
    }
    // 'localhost:8001' parses with the protocol 'localhost:', so check it.
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        throw new TypeError(`resolveBaseUrl: PLAYWRIGHT_BASE_URL must use http or https, got "${value}"`);
    }
    // Playwright joins test paths onto the base URL, so a query or fragment
    // would end up in the wrong place.
    if (url.search !== '' || url.hash !== '') {
        throw new TypeError(`resolveBaseUrl: PLAYWRIGHT_BASE_URL must not have a query or fragment, got "${value}"`);
    }
    // url.href is the parsed form, so stray spaces and case are removed.
    return url.href.endsWith('/') ? url.href : `${url.href}/`;
}

function portUrl(port, source) {
    const number = Number(port);
    if (!/^\d+$/.test(port) || number < 1 || number > 65535) {
        throw new RangeError(`resolveBaseUrl: ${source} must be an integer from 1 to 65535, got "${port}"`);
    }
    return `http://localhost:${number}/`;
}

module.exports = { resolveBaseUrl, resolveBaseUrlWithSource, readEnvFileKey, REPO_ENV_PATH };
