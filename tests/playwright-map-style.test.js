'use strict';

/**
 * Builds the map style through scripts/build.js with a stub TileJSON, so no
 * network is needed. CI runs this through `npm run test:js-unit`; it catches a
 * @versatiles/style bump that turns the registry maps back into a globe.
 *
 * Run with: node --test tests/playwright-map-style.test.js
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buildOsmStyle } = require('../scripts/build.js');

const BASE = 'https://tiles.versatiles.org';

test('generated map style uses the flat Mercator projection', async () => {
    const style = await buildOsmStyle(BASE, {
        tilejson: '3.0.0',
        tiles: [`${BASE}/tiles/osm/{z}/{x}/{y}`],
        vector_layers: [],
    });
    assert.deepEqual(style.projection, { type: 'mercator' });
});

test('root-relative TileJSON tile URLs are made absolute', async () => {
    const style = await buildOsmStyle(BASE, {
        tilejson: '3.0.0',
        tiles: ['/tiles/osm/{z}/{x}/{y}'],
        vector_layers: [],
    });
    const tileUrls = Object.values(style.sources).flatMap((source) => source.tiles ?? []);
    assert.ok(tileUrls.includes(`${BASE}/tiles/osm/{z}/{x}/{y}`), JSON.stringify(tileUrls));
});
