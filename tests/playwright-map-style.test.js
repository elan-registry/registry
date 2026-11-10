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

// Builds the style from a stub TileJSON with the given tile URLs.
const buildStubStyle = (tiles) => buildOsmStyle(BASE, { tilejson: '3.0.0', tiles, vector_layers: [] });

test('the installed @versatiles/style is major version 9', () => {
    assert.match(require('@versatiles/style/package.json').version, /^9\./);
});

test('generated map style uses the flat Mercator projection', async () => {
    const style = await buildStubStyle([`${BASE}/tiles/osm/{z}/{x}/{y}`]);
    assert.deepEqual(style.projection, { type: 'mercator' });
});

test('root-relative TileJSON tile URLs are made absolute', async () => {
    const style = await buildStubStyle(['/tiles/osm/{z}/{x}/{y}']);
    const tileUrls = Object.values(style.sources).flatMap((source) => source.tiles ?? []);
    assert.ok(tileUrls.includes(`${BASE}/tiles/osm/{z}/{x}/{y}`), JSON.stringify(tileUrls));
});

test('generated map style uses VersaTiles sprite and glyph URLs and has no light key', async () => {
    const style = await buildStubStyle([`${BASE}/tiles/osm/{z}/{x}/{y}`]);
    // v9 emits sprite as an array of {id, url} objects.
    const sprite = JSON.stringify(style.sprite);
    assert.ok(sprite.includes(`"${BASE}/`), `sprite: ${sprite}`);
    assert.ok(String(style.glyphs).startsWith(`${BASE}/`), `glyphs: ${style.glyphs}`);
    // v9 writes a root light key only for extruded buildings or a globe with
    // atmosphere. The flat registry map has neither.
    assert.ok(!('light' in style), 'style must not have a light key');
});
