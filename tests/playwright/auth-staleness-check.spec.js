// tests/playwright/auth-staleness-check.spec.js
// Pure-logic coverage for e2e/auth-staleness-check.js's assertAuthStillValid
// (#1935) — no real browser, no network, no real site. The module under test
// exposes an injectable `newContext` seam precisely so its three outcomes can
// be exercised against fakes.
//
// Lives at tests/playwright/ root (not e2e/) so the `chromium` project picks
// it up — that project's testIgnore excludes '**/e2e/**' entirely. Mirrors
// fixtures.spec.js, the existing precedent for a page-less spec here.
//
// `isLoggedIn` is a real (non-injectable) import inside the module, so the
// stale/valid cases are driven through it rather than around it: the fake
// page's locator().count() return value is what the real isLoggedIn reads
// (see auth-helper.js — `page.locator('a[href*="logout"], .user-menu,
// .account-menu').count() > 0`), so a count of 0 yields false and 1 yields
// true without stubbing the function itself.

const fs = require('fs');
const os = require('os');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { assertAuthStillValid } = require('./e2e/auth-staleness-check');

const SETUP_SCRIPT = './scripts/playwright-auth-1password-test.sh';

/**
 * Build a fake Playwright context whose page reports `locatorCount` matches
 * for any locator — the single value the real isLoggedIn() branches on.
 * Records calls so tests can assert the context is always closed.
 */
function makeFakeContext(locatorCount) {
  const calls = { newContextOpts: null, gotoPaths: [], closed: false };

  const page = {
    goto: async (url) => { calls.gotoPaths.push(url); },
    locator: () => ({ count: async () => locatorCount }),
  };

  const context = {
    newPage: async () => page,
    close: async () => { calls.closed = true; },
  };

  const newContext = async (opts) => {
    calls.newContextOpts = opts;
    return context;
  };

  return { newContext, calls };
}

/**
 * Write a dummy storageState file to a unique temp path and return it.
 * Content is irrelevant — assertAuthStillValid only calls fs.existsSync() on
 * it and hands the path to newContext, which is faked here.
 */
function makeTempAuthFile() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'auth-staleness-'));
  const authFile = path.join(dir, 'user-test.json');
  fs.writeFileSync(authFile, JSON.stringify({ cookies: [], origins: [] }));
  return authFile;
}

test.describe('assertAuthStillValid (#1935)', () => {
  test('missing auth file throws before creating a context', async () => {
    const missingFile = path.join(os.tmpdir(), 'auth-staleness-does-not-exist.json');
    expect(fs.existsSync(missingFile)).toBe(false);

    // A newContext that fails the test if reached — the file check must
    // short-circuit before any context is created.
    let contextCreated = false;
    const newContext = async () => {
      contextCreated = true;
      throw new Error('newContext should not be called when the auth file is missing');
    };

    let thrown;
    try {
      await assertAuthStillValid(null, {
        authFile: missingFile,
        setupScriptPath: SETUP_SCRIPT,
        baseURL: 'https://example.invalid/',
        newContext,
      });
    } catch (error) {
      thrown = error;
    }

    expect(thrown, 'expected assertAuthStillValid to throw').toBeTruthy();
    expect(thrown.message).toContain('Auth file not found');
    expect(thrown.message).toContain(missingFile);
    expect(thrown.message).toContain(SETUP_SCRIPT);
    expect(contextCreated, 'newContext must not be reached').toBe(false);
  });

  test('present auth file with a stale session throws the re-auth message', async () => {
    const authFile = makeTempAuthFile();
    // count 0 → real isLoggedIn() finds no logout link / user menu → false.
    const { newContext, calls } = makeFakeContext(0);

    let thrown;
    try {
      await assertAuthStillValid(null, {
        authFile,
        setupScriptPath: SETUP_SCRIPT,
        baseURL: 'https://example.invalid/',
        newContext,
      });
    } catch (error) {
      thrown = error;
    }

    expect(thrown, 'expected assertAuthStillValid to throw').toBeTruthy();
    expect(thrown.message).toContain('is stale/expired');
    expect(thrown.message).toContain(authFile);
    expect(thrown.message).toContain(SETUP_SCRIPT);

    // The storageState/baseURL are forwarded, the authenticated page is
    // visited, and the context is closed even on the throwing path.
    expect(calls.newContextOpts).toEqual({
      storageState: authFile,
      baseURL: 'https://example.invalid/',
    });
    expect(calls.gotoPaths).toEqual(['usersc/account.php']);
    expect(calls.closed, 'context must be closed via finally').toBe(true);
  });

  test('present auth file with a valid session resolves without throwing', async () => {
    const authFile = makeTempAuthFile();
    // count 1 → real isLoggedIn() sees a logged-in marker → true.
    const { newContext, calls } = makeFakeContext(1);

    await assertAuthStillValid(null, {
      authFile,
      setupScriptPath: SETUP_SCRIPT,
      baseURL: 'https://example.invalid/',
      newContext,
    });

    expect(calls.gotoPaths).toEqual(['usersc/account.php']);
    expect(calls.closed, 'context must be closed on the success path').toBe(true);
  });
});
