// tests/playwright/base-url.js
//
// Single source of truth for the Playwright base URL fallback, shared by
// playwright.config.js, playwright.config.dev.js, and global-setup.js.
// global-setup.js runs standalone before Playwright resolves project
// config, so it cannot read `config.use.baseURL` back — this module exists
// so the same resolved value is computed once and required everywhere,
// instead of the literal being hand-duplicated (see #2007).
module.exports = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:9999/ElanRegistry/Registry/';
