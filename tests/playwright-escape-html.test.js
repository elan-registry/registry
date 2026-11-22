'use strict';

/**
 * Unit tests for escapeHtml() in app/admin/assets/admin-core.js, the XSS
 * guard for user content that admin pages insert into the DOM. The admin
 * pages load the committed admin-core.min.js, so the cases run against both
 * files. A stale or broken build then fails here too.
 *
 * The file is a browser script, so the test runs it in a vm context with
 * no-op stubs for the jQuery and document calls at its top level. The test
 * then does not depend on where those calls are in the file.
 *
 * Run with: node --test tests/playwright-escape-html.test.js
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ASSET_DIR = path.join(__dirname, '..', 'app', 'admin', 'assets');

const CASES = [
    ['<script> tags', '<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
    ['double quotes', '"', '&quot;'],
    ['single quotes', "'", '&#039;'],
    ['ampersands', '&', '&amp;'],
    ['greater-than character', '>', '&gt;'],
    ['combined attribute-injection vector', '<img src=x onerror="alert(\'xss\')">', '&lt;img src=x onerror=&quot;alert(&#039;xss&#039;)&quot;&gt;'],
    ['non-string values pass through unchanged', 42, 42],
    ['already-escaped strings are escaped again', '&lt;b&gt;', '&amp;lt;b&amp;gt;'],
];

/**
 * Run one admin-core file in a stub context and return its escapeHtml().
 *
 * @param {string} fileName File name in app/admin/assets/
 * @returns {Function}
 */
function loadEscapeHtml(fileName) {
    const source = fs.readFileSync(path.join(ASSET_DIR, fileName), 'utf8');
    const context = vm.createContext({
        $: () => ({ ready: () => {} }),
        document: { addEventListener: () => {} },
    });
    vm.runInContext(source, context);
    assert.equal(typeof context.escapeHtml, 'function', `${fileName} must define escapeHtml() as a top-level function`);
    return context.escapeHtml;
}

for (const fileName of ['admin-core.js', 'admin-core.min.js']) {
    describe(`escapeHtml() XSS prevention (${fileName})`, () => {
        const escapeHtml = loadEscapeHtml(fileName);

        for (const [name, input, expected] of CASES) {
            test(name, () => {
                assert.equal(escapeHtml(input), expected);
            });
        }
    });
}
