# Elan Registry Test Coverage Hardening Release Notes

**Release Date:** TBD
**Type:** Patch Release - Test Coverage Hardening

## Required Actions After Deployment

None.

## User-Facing Changes

None — this milestone is developer-facing test infrastructure only.

## Admin-Facing Changes

None.

## Developer-Facing Changes

A developer can trust the test suite to catch real regressions — closing coverage gaps and adding enforcement so silent test-suite gaps stop shipping undetected.

## Issues Resolved

- [#1705](https://github.com/elan-registry/registry/issues/1705) — `UserDeletionReassignmentTest` now seeds synthetic PII (name, email, location, coordinates, website) onto test cars before deletion and asserts, against a real database, that every `OWNER_IDENTITY_FIELDS` column is actually scrubbed on `cars` and on the transfer's fresh `cars_hist` audit row after `after_user_deletion.php` runs — previously this GDPR guarantee was proven only by a mocked unit test that checked what was *passed to* the DB layer, not what landed on disk. Also widened `CarAdministrationServiceTest`'s mocked coverage from 4 to all 9 `OWNER_IDENTITY_FIELDS`, correcting two assumptions the real-DB test exposed as wrong: `fname`/`lname` are not blanked on transfer — they correctly take on the target owner's own name (so a car reassigned to the system `noowner` account shows `noowner`'s placeholder identity, not empty fields) — and `website` clears to `null` on `cars` but stays `''` on `cars_hist` (an asymmetry between the two write paths, now documented at the assertion site instead of being the kind of thing a future reader "fixes" as a bug). `cars_hist` is append-only by design, so this closes the achievable half of the original ask — proving the transfer's own audit row is correct — while historic rows are intentionally left out of scope, which is noted explicitly rather than left implicit.
- [#1778](https://github.com/elan-registry/registry/issues/1778) — Added dedicated Playwright console-error coverage for `docs/car-stories.php`, `docs/reference/chassis-validation.php`, and `docs/reference/paint-colors.php`, which previously had only incidental page-title-loop coverage. Assertions include per-page row/card counts (not just presence of the last item) so a mid-render PHP fatal or a silently truncated loop — like the two `chassis-validation.php` fatals in v2.29.x (`d8cb618d`, `8b81ab57`) — fails the test instead of passing silently. Also removed a stale `Google Maps` console-error filter (codebase migrated to MapLibre GL) from the shared assertion helper.
- WIP: [#1789](https://github.com/elan-registry/registry/issues/1789) — test: process-transfer-approve.php has no real Playwright success-path coverage (needs disposable non-admin-owned car fixture)
- [#2068](https://github.com/elan-registry/registry/issues/2068) — Added Playwright coverage for `requireAdminAjax()`'s `isRegistryAdmin()` branch: a new `tests/playwright/e2e/ajax-endpoints-non-admin.spec.js`, run under the Dev/Test/Prod `logged-in-non-admin`/`logged-in` projects (#2035's non-admin session infrastructure), proves a real logged-in-but-non-admin user gets a 403 from an admin AJAX endpoint — closing a privilege-escalation coverage gap where only the unauthenticated branch was previously tested. Also wires up the `test:e2e[:test|:dev]:non-admin` npm scripts and updates docs that previously described the `logged-in`/`logged-in-non-admin` projects as infrastructure-only with no spec targeting them yet.
- [#2070](https://github.com/elan-registry/registry/issues/2070) — Added a local ESLint rule (`localRules/require-skip-reason`) that flags any `test.skip(...)` call with fewer than 2 arguments in Playwright spec files. Makes the two-argument `test.skip(condition, reason)` convention (established in #1950, after #1949's false-`passed`-instead-of-`skipped` defect) self-enforcing instead of prose-only, so a regression can't silently reach CI again.
- [#2071](https://github.com/elan-registry/registry/issues/2071) — Diagnosed and fixed two locally-failing Playwright spec files. `chassis-availability-error.spec.js` had a stale test helper racing the app's real async model-dropdown population and a no-op `blur()` call (fixed with real Playwright interactions — `selectOption()`, `fill()`, focus-elsewhere). `filepond-load-error.spec.js`'s 3 `#755`-block tests uncovered a real app regression: FilePond 4.32.12's `addFile()` never rejects on a real load error (it only rejects for `error.code` in `[400,500)`, which the app's custom loader never produces), so the app's photo-load-error banner/submit-disable logic is currently dead code. That app fix is out of scope for this test-only milestone — filed as [#2096](https://github.com/elan-registry/registry/issues/2096) (milestone v2.33.0), and the 3 affected tests are marked `test.fail()` citing it — Playwright still runs the test body and requires it to actually fail, so once #2096 is fixed and the assertions start passing, that's reported as an unexpected-pass failure instead of silently staying quiet (`test.fixme()`/`test.skip()` would have aborted the test body entirely, giving no such signal).
