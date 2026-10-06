'use strict';

// Kept out of build.js so tests/playwright-map-style.test.js can build the
// style with the same options. CI runs that test but not the Playwright map
// tests, so it is the only check on a @versatiles/style bump.

/**
 * Options for @versatiles/style osm() that generate the registry map style.
 *
 * @param {string} base VersaTiles server origin.
 * @param {object} osmTileJson TileJSON for the osm source, with absolute tile URLs.
 * @returns {object} Options object for osm().
 */
function versatilesStyleOptions(base, osmTileJson) {
  return {
    urls: { base, osm: osmTileJson },
    text: { language: 'en' },
    theme: 'colorful',
    // @versatiles/style v6 defaults the style projection to 'globe', and the
    // MapLibre GL JS version we ship obeys it. Mercator is a product choice,
    // not an oversight: the registry maps show where cars are on a flat world
    // view. Do not remove this to return to the library default.
    projection: 'mercator',
  };
}

module.exports = { versatilesStyleOptions };
