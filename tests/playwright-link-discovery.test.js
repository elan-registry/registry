'use strict';

/**
 * Unit tests for toInternalPath() in tests/playwright/e2e/link-discovery.js.
 * The link crawl visits each path it returns, so an external href that it
 * returns as internal sends the crawl to another host.
 *
 * Run with: node --test tests/playwright-link-discovery.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const { toInternalPath } = require('./playwright/e2e/link-discovery');

const PAGE = 'https://elanregistry.org/app/cars/index.php';

describe('toInternalPath()', () => {
    const cases = [
        ['root-relative path', '/app/cars/', '/app/cars/'],
        ['same-host absolute URL', 'https://elanregistry.org/docs/x.php?a=1', '/docs/x.php'],
        ['www host absolute URL', 'https://www.elanregistry.org/docs/', '/docs/'],
        ['other host', 'https://evil.com/x', null],
        ['site name in the path of another host', 'https://evil.com/elanregistry.org', null],
        ['protocol-relative href', '//evil.com/x', null],
        ['backslash protocol-relative href', '/\\evil.com/x', null],
        ['relative href', 'details.php', null],
        ['fragment', '#top', null],
        ['missing href', null, null],
    ];

    for (const [name, href, expected] of cases) {
        test(name, () => {
            assert.equal(toInternalPath(href, PAGE), expected);
        });
    }
});
