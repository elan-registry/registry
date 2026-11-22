'use strict';

/**
 * Unit tests for escapeHtml() in app/admin/assets/admin-core.js, the XSS
 * guard for user content that admin pages insert into the DOM.
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

const source = fs.readFileSync(path.join(__dirname, '..', 'app', 'admin', 'assets', 'admin-core.js'), 'utf8');
const context = vm.createContext({
    $: () => ({ ready: () => {} }),
    document: { addEventListener: () => {} },
});
vm.runInContext(source, context);
const { escapeHtml } = context;
assert.equal(typeof escapeHtml, 'function', 'admin-core.js must define escapeHtml() as a top-level function');

describe('escapeHtml() XSS prevention', () => {
    const cases = [
        ['<script> tags', '<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
        ['double quotes', '"', '&quot;'],
        ['single quotes', "'", '&#039;'],
        ['ampersands', '&', '&amp;'],
        ['greater-than character', '>', '&gt;'],
        ['combined attribute-injection vector', '<img src=x onerror="alert(\'xss\')">', '&lt;img src=x onerror=&quot;alert(&#039;xss&#039;)&quot;&gt;'],
        ['non-string values pass through unchanged', 42, 42],
        ['already-escaped strings are escaped again', '&lt;b&gt;', '&amp;lt;b&amp;gt;'],
    ];

    for (const [name, input, expected] of cases) {
        test(name, () => {
            assert.equal(escapeHtml(input), expected);
        });
    }
});
