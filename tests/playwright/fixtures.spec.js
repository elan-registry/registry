// Pure-logic coverage for fixtures.js's CAR_ID_STANDARD env-var/fallback
// coercion (#1788) — no page/browser needed, so this runs fast even inside
// the full Playwright suite. Guards the numeric-coercion contract every
// call site depends on (several do `String(CAR_ID_STANDARD)`, which assumes
// the constant is always a number, never a string or NaN).

const { test, expect } = require('@playwright/test');

// fixtures.js reads process.env.CAR_ID_STANDARD once, at require() time, so
// each case needs a fresh module instance (require-cache cleared) after
// setting/unsetting the env var — not a re-import of an already-cached module.
function loadCarIdStandard(envValue) {
  const original = process.env.CAR_ID_STANDARD;
  if (envValue === undefined) {
    delete process.env.CAR_ID_STANDARD;
  } else {
    process.env.CAR_ID_STANDARD = envValue;
  }

  delete require.cache[require.resolve('./fixtures.js')];
  const { CAR_ID_STANDARD } = require('./fixtures.js');

  if (original === undefined) {
    delete process.env.CAR_ID_STANDARD;
  } else {
    process.env.CAR_ID_STANDARD = original;
  }
  delete require.cache[require.resolve('./fixtures.js')];

  return CAR_ID_STANDARD;
}

test.describe('fixtures.js — CAR_ID_STANDARD env-var coercion (#1788)', () => {
  test('unset env var falls back to 1', () => {
    expect(loadCarIdStandard(undefined)).toBe(1);
  });

  test('valid numeric string resolves to that number', () => {
    expect(loadCarIdStandard('3')).toBe(3);
  });

  test('non-numeric string falls back to 1 (silent, not thrown)', () => {
    expect(loadCarIdStandard('abc')).toBe(1);
  });

  test('"0" falls back to 1 — 0 is never a valid car id in this schema', () => {
    expect(loadCarIdStandard('0')).toBe(1);
  });

  test('empty string falls back to 1', () => {
    expect(loadCarIdStandard('')).toBe(1);
  });

  test('resolved value is always a number, never a string (call sites rely on this)', () => {
    expect(typeof loadCarIdStandard('3')).toBe('number');
    expect(typeof loadCarIdStandard(undefined)).toBe('number');
  });
});
