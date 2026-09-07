// tests/playwright/fixtures.js
// Centralized car-ID fixtures used across Playwright specs. Values must not
// change without verifying the referenced car/data still exists in the test DB.

// CAR_ID_STANDARD is overridable via the CAR_ID_STANDARD env var (set in
// .env.local, same convention as TEST_USERNAME/TEST_PASSWORD in
// auth-helper.js) for local MAMP snapshots where the default id (1) doesn't
// exist — e.g. a stripped/anonymized snapshot whose car ids start higher.
// A misspelled env var name (or any non-numeric/unset value) fails SILENTLY
// to the `1` fallback — double-check spelling if overriding this and it
// doesn't seem to take effect.
const CAR_ID_STANDARD = Number(process.env.CAR_ID_STANDARD) || 1;
const CAR_ID_WITH_HISTORY = 1091;
const CAR_ID_WITH_SPECIAL_CHARS = 650; // depends on a one-time migration having run against this row — see car-edit-text-save.spec.js
const CAR_ID_NONEXISTENT = 999999999;
const CAR_ID_REDIRECT_TEST = 100;
// Has a valid http(s) owner-profile website (issue #1963 made website
// owner-level) — verified directly against the local dev DB at the time this
// was added: `SELECT id, website FROM cars WHERE website IS NOT NULL AND
// website != '' LIMIT 5;` returned id 8 with website
// "http://www.jaeparts.com". Re-verify if this ever starts failing.
const CAR_ID_WITH_WEBSITE = 8;
// Has neither a purchase date nor a sold date — used to assert the
// "Ownership & History" section is hidden entirely (app/views/cars/_vehicle_info_card.php's
// `if ($purchaseDate || $soldDate)` guard). Verified via `SELECT id FROM cars
// WHERE purchasedate IS NULL AND solddate IS NULL LIMIT 5;` returning id 3
// among others. Re-verify if this ever starts failing.
const CAR_ID_WITHOUT_OWNERSHIP_DATES = 3;
// Has a purchase date (no sold date) — used to assert the "Ownership &
// History" section IS shown when at least one date is present. Verified via
// `SELECT id, purchasedate, solddate FROM cars WHERE purchasedate IS NOT NULL
// OR solddate IS NOT NULL LIMIT 5;` returning id 4 with purchasedate
// "2003-05-10". Re-verify if this ever starts failing.
const CAR_ID_WITH_OWNERSHIP_DATES = 4;

module.exports = {
  CAR_ID_STANDARD,
  CAR_ID_WITH_HISTORY,
  CAR_ID_WITH_SPECIAL_CHARS,
  CAR_ID_NONEXISTENT,
  CAR_ID_REDIRECT_TEST,
  CAR_ID_WITH_WEBSITE,
  CAR_ID_WITHOUT_OWNERSHIP_DATES,
  CAR_ID_WITH_OWNERSHIP_DATES,
};
