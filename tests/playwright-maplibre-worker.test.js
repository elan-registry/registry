'use strict';

/**
 * Checks vendorMaplibreWorker() in scripts/build.js against the installed
 * MapLibre worker and against chunk-layout fixtures. CI runs this through
 * `npm run test:js-unit`. It catches a MapLibre bump that splits the worker
 * into sibling chunks again, which would leave the maps blank.
 *
 * Run with: node --test tests/playwright-maplibre-worker.test.js
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { vendorMaplibreWorker } = require('../scripts/build.js');

const WORKER_PATH = path.join(__dirname, '..', 'node_modules', 'maplibre-gl', 'dist', 'maplibre-gl-worker.mjs');

test('installed MapLibre worker vendors with no sibling chunk and no sourcemap comment', () => {
    const out = vendorMaplibreWorker(fs.readFileSync(WORKER_PATH, 'utf8'));
    assert.doesNotMatch(out, /sourceMappingURL/);
});

test('a worker that imports the shared chunk fails the build', () => {
    assert.throws(
        () => vendorMaplibreWorker('import{a}from"./maplibre-gl-shared.mjs";a();\n'),
        /maplibre-gl-shared/
    );
});

test('a worker with two chunks fails and names both', () => {
    assert.throws(
        () => vendorMaplibreWorker('import"./a.mjs";import"./b.mjs";import"./a.mjs";\n'),
        /\[\.\/a\.mjs, \.\/b\.mjs\]/
    );
});

for (const [label, src] of [
    ['a hashed chunk name', 'import{a}from"./maplibre-gl-shared.abc123.mjs";'],
    ['a parent-directory chunk', 'import"../chunks/x.mjs";'],
    ['a subdirectory chunk', 'import"./chunks/x.mjs";'],
    ['a .js chunk', 'import"./x.js";'],
    ['a dynamic import', 'import("./x.mjs");'],
    ['a URL built from import.meta.url', 'new URL("x.mjs",import.meta.url);'],
]) {
    test(`a worker with ${label} fails the build`, () => {
        assert.throws(() => vendorMaplibreWorker(`${src}\n`), /changed its worker chunk layout/);
    });
}

test('a self-contained worker loses only its trailing sourcemap comment', () => {
    const body = 'self.worker=1;export{x as default};';
    assert.equal(vendorMaplibreWorker(`${body}\n//# sourceMappingURL=x.mjs.map`), `${body}\n`);
});
