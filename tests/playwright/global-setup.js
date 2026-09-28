// tests/playwright/global-setup.js
//
// Fails fast with a clear error if the target server isn't reachable, instead
// of letting every test in the run time out individually and burying the
// real cause (server not running, wrong PLAYWRIGHT_BASE_URL) under dozens of
// per-test failures.

const { readEnvFileKey, REPO_ENV_PATH } = require('./resolve-base-url.js');

// Shared with playwright.config.js/playwright.config.dev.js's `use.baseURL`
// — see base-url.js for why this can't just read the resolved config back.
const BASE_URL = require('./base-url.js');

// The Playwright configs load only .env.local into process.env, but
// .env.example tells developers to set LANDING_HOST_PORT in .env. Read both.
// This runs while the "Cannot reach" error is built. A .env read error here
// must not replace that error, so it falls back to the default port.
function landingPort() {
  let port = process.env.LANDING_HOST_PORT;
  if (!port) {
    try {
      port = readEnvFileKey(REPO_ENV_PATH, 'LANDING_HOST_PORT');
    } catch {
      // Keep the default. See the comment above.
    }
  }
  // Matches the docker-compose.yml fallback `${LANDING_HOST_PORT:-8101}`.
  return port || '8101';
}

module.exports = async function globalSetup() {
  try {
    // Any HTTP response — even a 404 or 500 — proves the server is up and
    // reachable, so response.ok is deliberately not checked here. Only a
    // thrown exception (connection refused, DNS failure, timeout) means the
    // target isn't reachable at all.
    await fetch(BASE_URL, { signal: AbortSignal.timeout(5000) });
  } catch (error) {
    // fetch() puts the network error code (for example ECONNREFUSED) in
    // error.cause. A timeout has no cause, so fall back to the error name.
    const reason = error.cause?.code ?? error.cause?.message ?? error.name;
    throw new Error(
      `Cannot reach ${BASE_URL} (${reason}). Start the Docker stack with \`docker compose up -d\`, ` +
        `then open the landing page at http://localhost:${landingPort()}/ to see this checkout's site URL. ` +
        `If the site is on a different port, set APP_HOST_PORT or PLAYWRIGHT_BASE_URL. ` +
        `See docs/development/ENVIRONMENT.md.`,
      { cause: error }
    );
  }
};
