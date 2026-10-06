'use strict';

/**
 * Builds the map style with the installed @versatiles/style and the options
 * from scripts/versatiles-style-options.js. A stub TileJSON avoids the network.
 * CI runs this through `npm run test:js-unit`, so it catches a library bump
 * that turns the registry maps back into a globe.
 *
 * Run with: node --test tests/playwright-map-style.test.js
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { versatilesStyleOptions } = require('../scripts/versatiles-style-options.js');

const BASE = 'https://tiles.versatiles.org';
const STUB_TILE_JSON = {
    tilejson: '3.0.0',
    tiles: [`${BASE}/tiles/osm/{z}/{x}/{y}`],
    vector_layers: [],
};

test('generated map style uses the flat Mercator projection', async () => {
    const { osm } = await import('@versatiles/style');
    const style = osm(versatilesStyleOptions(BASE, STUB_TILE_JSON));
    assert.deepEqual(style.projection, { type: 'mercator' });
});

// The test above checks only the helper: if build.js inlines the options without `projection`, the maps become a globe again and CI stays green.
test('scripts/build.js builds the style only from versatilesStyleOptions()', () => {
    const buildSource = fs.readFileSync(path.join(__dirname, '..', 'scripts', 'build.js'), 'utf8');
    assert.ok(
        buildSource.includes("require('./versatiles-style-options.js')"),
        'build.js must require ./versatiles-style-options.js'
    );
    assert.ok(
        buildSource.includes('osm(versatilesStyleOptions('),
        'build.js must call osm(versatilesStyleOptions(...))'
    );
    // build.js quotes `osm({ theme: 'colorful' })` in a comment, so check code only.
    const buildCode = buildSource.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    assert.ok(!/osm\(\s*\{/.test(buildCode), 'build.js must not call osm() with an inline options object');
});
