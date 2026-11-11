// Car-ID fixtures shared across Playwright specs. Values must not change
// without verifying the referenced car/data still exists in the test DB.

// Override with CAR_ID_STANDARD in .env.local. A misspelled or non-numeric
// value falls back to 1 SILENTLY.
const CAR_ID_STANDARD = Number(process.env.CAR_ID_STANDARD) || 1;
// The car details map test needs non-zero lat and lon on CAR_ID_WITH_HISTORY.
// To re-verify: SELECT lat, lon FROM cars WHERE id = 1091;
const CAR_ID_WITH_HISTORY = 1091;
const CAR_ID_WITH_SPECIAL_CHARS = 650; // depends on a one-time migration having run against this row — see car-edit-text-save.spec.js
const CAR_ID_NONEXISTENT = 999999999;
const CAR_ID_REDIRECT_TEST = 100;
// Has an http(s) owner website (#1963). Re-verify with:
// SELECT id, website FROM cars WHERE website IS NOT NULL AND website != '';
const CAR_ID_WITH_WEBSITE = 8;
// No purchase or sold date (#1897). Re-verify with:
// SELECT id FROM cars WHERE purchasedate IS NULL AND solddate IS NULL;
const CAR_ID_WITHOUT_OWNERSHIP_DATES = 3;
// Has a purchase date. Re-verify with:
// SELECT id FROM cars WHERE purchasedate IS NOT NULL OR solddate IS NOT NULL;
const CAR_ID_WITH_OWNERSHIP_DATES = 4;
// Owned by the E2E_DEV_ADMIN_USERNAME account, so edit.php takes the owner
// path, not the admin override. Re-verify with:
// SELECT c.id FROM cars c JOIN users u ON u.id = c.user_id WHERE u.email = '<E2E_DEV_ADMIN_USERNAME>';
const CAR_ID_OWNED_BY_ADMIN = 1;

module.exports = {
  CAR_ID_STANDARD,
  CAR_ID_WITH_HISTORY,
  CAR_ID_WITH_SPECIAL_CHARS,
  CAR_ID_NONEXISTENT,
  CAR_ID_REDIRECT_TEST,
  CAR_ID_WITH_WEBSITE,
  CAR_ID_WITHOUT_OWNERSHIP_DATES,
  CAR_ID_WITH_OWNERSHIP_DATES,
  CAR_ID_OWNED_BY_ADMIN,
};
