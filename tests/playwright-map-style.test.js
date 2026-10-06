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
