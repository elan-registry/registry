// Single source of truth for the Playwright base URL, shared by
// playwright.config.js, playwright.config.dev.js, and global-setup.js.
// global-setup.js runs standalone before Playwright resolves project
// config, so it cannot read `config.use.baseURL` back. This module computes
// the value once and every caller requires it, so no caller holds its own
// copy of the literal (see #2007).
//
// The resolution rules are in resolve-base-url.js. This file must stay a
// plain string export because all three callers use the value directly.
const { resolveBaseUrl, REPO_ENV_PATH } = require('./resolve-base-url.js');

module.exports = resolveBaseUrl(process.env, REPO_ENV_PATH);
