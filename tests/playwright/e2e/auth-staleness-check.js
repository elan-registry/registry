const fs = require('fs');
const { isLoggedIn } = require('../auth-helper');

/**
 * Load storageState from authFile, navigate to an authenticated page, and
 * assert the session is still logged in. Throws with an actionable message
 * (pointing at setupScriptPath) if the file is missing or the session is
 * stale. `newContext` defaults to calling `browser.newContext(opts)` but
 * accepts an override so tests can inject a fake without a real browser.
 */
async function assertAuthStillValid(browser, { authFile, setupScriptPath, baseURL, newContext } = {}) {
  if (!fs.existsSync(authFile)) {
    throw new Error(
      `Auth file not found: ${authFile}\n` +
      `Run ${setupScriptPath} to authenticate (solves a live Cloudflare Turnstile ` +
      `challenge manually), then re-run this suite.`
    );
  }

  const createContext = newContext || ((opts) => browser.newContext(opts));
  const context = await createContext({ storageState: authFile, baseURL });
  const page = await context.newPage();
  // Preserve whichever error is the actionable diagnostic: if context.close()
  // itself throws (e.g. the browser process already crashed), it would
  // otherwise replace the real "session is stale, run this script" message
  // with an unrelated cleanup error — exactly the silent-failure mode this
  // file exists to prevent.
  let primaryError;
  try {
    await page.goto('usersc/account.php', { waitUntil: 'domcontentloaded' });
    const loggedIn = await isLoggedIn(page);
    if (!loggedIn) {
      throw new Error(
        `Saved session in ${authFile} is stale/expired (loaded storageState but ` +
        `usersc/account.php did not show a logged-in state). Run ${setupScriptPath} ` +
        `to re-authenticate, then re-run this suite.`
      );
    }
  } catch (err) {
    primaryError = err;
    throw err;
  } finally {
    try {
      await context.close();
    } catch (closeErr) {
      if (!primaryError) {
        throw closeErr;
      }
      // A real diagnostic is already propagating — don't let a cleanup
      // failure mask it.
    }
  }
}

module.exports = { assertAuthStillValid };
