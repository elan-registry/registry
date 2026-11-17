// Car-ID fixtures shared across Playwright specs. Values must not change
// without verifying the referenced car/data still exists in the test DB.

const { assertValidTier } = require('./e2e/auth-staleness-tier.js');

// Override with CAR_ID_STANDARD in .env.local. A misspelled or non-numeric
// value falls back to 1 SILENTLY.
const CAR_ID_STANDARD = Number(process.env.CAR_ID_STANDARD) || 1;
// CAR_ID_WITH_HISTORY needs non-zero lat and lon (car details map test),
// history rows (#2144 admin tests), and at least one photo and a saved model
// (car-edit-text-save.spec.js). The test DB is not a prod copy, so it has no
// car 1091. Tiers not in this map use the default: prod, and unset (local
// and dev). A local DB has car 1091 only after
// scripts/refresh-local-db.sh loads a prod dump. The Docker sample data does
// not. To re-verify a value:
// SELECT lat, lon, (SELECT COUNT(*) FROM cars_hist h WHERE h.car_id = c.id) AS hist
//   FROM cars c WHERE c.id = <id>;
// Car 2 on test: 22 history rows, lat and lon set (checked 2026-10-22).
const CAR_ID_WITH_HISTORY_BY_TIER = { test: 2 };
const CAR_ID_WITH_HISTORY_DEFAULT = 1091;

/**
 * Resolve CAR_ID_WITH_HISTORY for the current tier. A CAR_ID_WITH_HISTORY
 * value in the env wins over the tier default. Unlike CAR_ID_STANDARD, a bad
 * value throws, so a typo cannot point the specs at the wrong car.
 *
 * @param {Record<string, string|undefined>} env Usually process.env.
 * @returns {number}
 */
function resolveCarIdWithHistory(env) {
  const tier = env.E2E_AUTH_TIER;
  assertValidTier(tier, { allowUnset: true }, 'fixtures.js');
  const override = env.CAR_ID_WITH_HISTORY;
  if (override) {
    if (!/^[1-9]\d*$/.test(override)) {
      throw new Error(
        `fixtures.js requires CAR_ID_WITH_HISTORY to be a positive integer (got: ${JSON.stringify(override)}). ` +
        'Fix or remove it in .env.local or the shell environment.'
      );
    }
    return Number(override);
  }
  return CAR_ID_WITH_HISTORY_BY_TIER[tier] ?? CAR_ID_WITH_HISTORY_DEFAULT;
}

const CAR_ID_WITH_HISTORY = resolveCarIdWithHistory(process.env);
const CAR_ID_WITH_SPECIAL_CHARS = 650; // depends on a one-time migration having run against this row — see car-edit-text-save.spec.js
const CAR_ID_NONEXISTENT = 999999999;
const CAR_ID_REDIRECT_TEST = 100;
// No purchase or sold date (#1897). Re-verify with:
// SELECT id FROM cars WHERE purchasedate IS NULL AND solddate IS NULL;
const CAR_ID_WITHOUT_OWNERSHIP_DATES = 3;
// Has a purchase date. Re-verify with:
// SELECT id FROM cars WHERE purchasedate IS NOT NULL OR solddate IS NOT NULL;
const CAR_ID_WITH_OWNERSHIP_DATES = 4;
// Owned by the E2E_DEV_ADMIN_USERNAME account, so edit.php takes the owner
// path, not the admin override. Re-verify with:
// SELECT c.id FROM cars c JOIN users u ON u.id = c.user_id WHERE u.email = '<E2E_DEV_ADMIN_USERNAME>';
// In database/4-sample-data.sql, car 1 is a 1973 car with chassis 7301019999B,
// which fails the 1972-1974 9-character rule. On a sample-data DB, a save test
// on this car that does not mock the save fails on the chassis.
const CAR_ID_OWNED_BY_ADMIN = 1;
// Owned by the noowner system account (#2505). Re-verify with:
// SELECT c.id FROM cars c JOIN users u ON u.id = c.user_id WHERE u.username = 'noowner';
const CAR_ID_NOOWNER = 17;

module.exports = {
  CAR_ID_STANDARD,
  CAR_ID_WITH_HISTORY,
  CAR_ID_WITH_SPECIAL_CHARS,
  CAR_ID_NONEXISTENT,
  CAR_ID_REDIRECT_TEST,
  CAR_ID_WITHOUT_OWNERSHIP_DATES,
  CAR_ID_WITH_OWNERSHIP_DATES,
  CAR_ID_OWNED_BY_ADMIN,
  CAR_ID_NOOWNER,
  resolveCarIdWithHistory,
};
