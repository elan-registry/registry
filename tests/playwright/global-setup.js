// tests/playwright/global-setup.js
//
// Fails fast with a clear error if the target server isn't reachable, instead
// of letting every test in the run time out individually and burying the
// real cause (server not running, wrong PLAYWRIGHT_BASE_URL) under dozens of
// per-test failures.

// Shared with playwright.config.js/playwright.config.dev.js's `use.baseURL`
// — see base-url.js for why this can't just read the resolved config back.
const BASE_URL = require('./base-url.js');

module.exports = async function globalSetup() {
  try {
    // Any HTTP response — even a 404 or 500 — proves the server is up and
    // reachable, so response.ok is deliberately not checked here. Only a
    // thrown exception (connection refused, DNS failure, timeout) means the
    // target isn't reachable at all.
    await fetch(BASE_URL, { signal: AbortSignal.timeout(5000) });
  } catch (_error) {
    throw new Error(
      `Cannot reach ${BASE_URL} — is MAMP running and is PLAYWRIGHT_BASE_URL (if set) correct? See docs/development/ENVIRONMENT.md.`
    );
  }
};
