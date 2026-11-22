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
const { toInternalPath, splitDownloadableLinks } = require('./playwright/e2e/link-discovery');

const PAGE = 'https://elanregistry.org/app/cars/index.php';

describe('toInternalPath()', () => {
    const cases = [
        ['root-relative path', '/app/cars/', '/app/cars/'],
        ['same-host absolute URL', 'https://elanregistry.org/docs/x.php?a=1', '/docs/x.php?a=1'],
        ['www host absolute URL', 'https://www.elanregistry.org/docs/', '/docs/'],
        ['other host', 'https://evil.com/x', null],
        ['site name in the path of another host', 'https://evil.com/elanregistry.org', null],
        ['protocol-relative href', '//evil.com/x', null],
        ['backslash protocol-relative href', '/\\evil.com/x', null],
        ['tab inside a protocol-relative href', '/\t/evil.com/x', null],
        ['newline inside a protocol-relative href', '/\n/evil.com/x', null],
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

describe('splitDownloadableLinks()', () => {
    test('matches the file extension on the path, not the query or fragment', () => {
        const { navigableLinks, downloadableLinks } = splitDownloadableLinks([
            '/docs/manual.pdf?v=2',
            '/images/car.PNG#top',
            '/docs/pdf-viewer.php?doc=x.pdf',
        ]);
        assert.deepEqual(downloadableLinks, ['/docs/manual.pdf?v=2', '/images/car.PNG#top']);
        assert.deepEqual(navigableLinks, ['/docs/pdf-viewer.php?doc=x.pdf']);
    });
});
