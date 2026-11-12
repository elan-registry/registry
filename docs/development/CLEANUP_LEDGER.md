<!-- markdownlint-disable MD013 -->
<!-- Each item stays on one line, because scripts/ledger-items-for-files.sh reads one line per item. -->

# Cleanup Ledger

This file lists open cleanup finds, grouped by file. A cleanup find changes
no behaviour: dead code, duplication, naming, comments, types, or lint
noise. A find that changes behaviour is a bug or a feature. It gets its own
GitHub issue.

**If a change touches a file below, do that file's items in the same
change.** Before you delete code, grep for its callers again. Code moves
after an item is written.

## Rules

- **Format.** Each group starts with a `###` heading that names the
  repo-relative path in backticks. A heading can name more than one path.
  A path that ends in `/` is a directory, and the group applies to each
  file under it. Text outside the backticks is a note.
- **Items.** Each item is one line that starts with `- [ ]` and a space, in column 0.
  End it with its source, for example `(found in #1234)`. Indented lines
  under an item are notes. The reader script does not print them.
- **Add an item.** Edit this file. Put the line under the heading for its
  file. If the file has no heading, add one in path order. Do not add an
  item whose text is already under that heading.
- **Fix an item.** Delete its line in the PR that fixes it. If the heading
  has no items left, delete the heading too. Git history keeps the record,
  so there are no `- [x]` lines and no closed section.
- **Move or delete a file.** Move or delete its group in the same PR.
  `composer check:docs` fails when a heading names a path that does not
  exist.
- **Find the items for a change.** Run
  `git diff --name-only <base>..HEAD | scripts/ledger-items-for-files.sh`.

## Items

### `.claude/rules/php-code.md`

- [ ] "When you review error handling" names only the last-resort fallback as an allowed `\Throwable` catch. `ERROR_HANDLING.md` also documents the page-section fault-domain catch (`VerificationTabStatus::read()`, `tab-verification.php`, #2364) as a deliberate exception. Add it to the rule, so reviewers do not flag those files (found in #1844)

### `app/admin/includes/account-cleanup-helpers.php`

- [ ] Replace 8 × `throw new RuntimeException` with a typed `AccountCleanupException extends ElanRegistryException`. Handle it in `app/api/admin/account-cleanup-data.php` with `ApiResponse` and `LogCategories` (from #1637)

### `app/admin/includes/process-transfer-approve.php` `app/admin/includes/process-transfer-deny.php` `app/api/cars/transfer-request.php`

- [ ] No test asserts the "No transfer approval/denial notification sent ... each one failed or the send policy skipped it" `EMAIL_ERROR` line. In `tests/integration/transfer/CarTransferWorkflowTest.php`, approve and deny with both parties opted out and assert that one row (found in /finish-milestone v2.30.9)
- [ ] `transfer-request.php` logs a send-policy skip of the owner notice as `EMAIL_ERROR`, but `policyAllows()` already logged it. Drop the endpoint line or log it under `CAR_TRANSFER` (found in /flow:finish-milestone v2.31.0)
- [ ] `process-transfer-approve.php` (~L131 and ~L147) wraps `$db->rollBack()` in `catch (\Throwable $rollbackEx)`. Remove the guard (see ERROR_HANDLING.md, no guards) (found in /flow:finish-milestone v2.31.0)

### `app/admin/includes/tab-account_cleanup.php` `app/admin/includes/tab-car_mgmt.php` `app/admin/includes/tab-email_delivery.php` `app/admin/includes/tab-manage_cars.php` `app/admin/includes/tab-owner_mgmt.php` `app/admin/includes/tab-verification.php` (direct request)

- [ ] A direct HTTP request to a tab include runs it with no `init.php`, and with `display_errors` on it prints a fatal error with the server path (for example "Call to undefined function currentUserId()"). No data is read. Stop direct requests for all tab files: a deny rule for `app/admin/includes/` or a guard at the top of each file (found in #2102 review)

### `app/admin/includes/tab-car_mgmt.php`

- [ ] Narrow 2 × `catch (Exception $e)` (~L29, 53) to the typed exceptions from `Car` and `CarTransferRepository` (from #1637)

### `app/admin/includes/tab-email_delivery.php` `usersc/classes/Email/DeliveryLogRepository.php`

- [ ] The tab logs `ExceptionLogSummary::of($e)` only, and the repository puts the failed step ("table check", "tags column check", "window load") and the driver error only in the message, which it must not log. The log line cannot tell which query failed or why. Carry the step and the SQLSTATE (`errorInfo()[0]`) outside the message and log them (found in #2102 review)
- [ ] The pager prints one link for each page. Show a window (first, current ±2, last) when the page count grows past about 10 (found in #2102 review)
- [ ] The owner, car and plugin links open a new tab with no screen-reader cue. Fix with the shared helper from the `tab-verification.php` `vsNewTabLink()` item (found in #2102 review)
- [ ] With an ignored recipient address, the `invalid-feedback d-block` hint grows the Recipient column, and `row g-2 align-items-end` then aligns the other filter controls to the hint, not the input. Use `align-items-start`, or move the hint to its own `col-12`. Also add `aria-invalid="true"` next to `is-invalid` (found in #2102 review)

### `app/admin/includes/tab-verification.php`

- [ ] The Automatic Sending card shows only "Status unavailable" for an `UNREADABLE` or `MISSING` job state, and its "Next eligible" row says "the job status could not be read" for `MISSING` too. Show the same detail lines as the status panel rows, from one shared source next to `CronJobRunsReader::badgeFor()` (found in #2572 review)
- [ ] `vsNewTabLink()` gives the queue and Recent Activity links no screen-reader cue that they open a new tab (WCAG G201), and neither table has an accessible name. Add `<span class="visually-hidden"> (opens in a new tab)</span>` in the helper, and a `visually-hidden` `<caption>` or `aria-labelledby` on each table (found in #2496 review)

### `app/admin/includes/verification-batch-size-field.php`

- [ ] The decorative icons at ~L28, ~L46 and ~L51 have no `aria-hidden="true"`. The Verification tab around them has it since #2496 (found in #2496 review)

### `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php` + `app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php` + `app/api/webhooks/brevo.php` + `usersc/classes/Car/CarAdministrationService.php` + `usersc/classes/Car/CarVerificationSendService.php` + `usersc/includes/custom_functions.php` + `usersc/plugins/hooker/hooks/user_form_hook.php` (logger guards)

- [ ] Each file wraps a `logger()` call in `try`/`catch (\Throwable)` (21 ~L1512, 26 ~L1728, brevo.php ~L156, CarAdministrationService ~L630, CarVerificationSendService ~L531, custom_functions ~L128, user_form_hook ~L60 and ~L105). Remove the guards, or move a deliberate one into the exceptions in ERROR_HANDLING.md with its reason (found in /flow:finish-milestone v2.31.0)

### `app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php` (test coverage)

- [ ] No test renders the notConfigured info notice or the manual-run log suffix. Run the script with the `plg_sendinblue` key cleared and assert the notice and no success alert (found in /flow:finish-milestone v2.31.0)
- [ ] After a partial poll failure the page shows the yellow warning and a green "Reconciliation completed" alert. Show the counts as partial results (`alert-secondary`) when `pollFailed` is true (found in /flow:finish-milestone v2.31.0)

### `app/api/cars/save.php`

- [ ] `uploadImages()` (~L801) `arrayReplaceValue()` replaces the first match. If a new upload has the same browser file name as a stored photo, the new photo takes the stored photo's place (it can become the primary photo). Send a unique placeholder from `car-edit.js`, or replace only a name that is not stored (found in #2609)
- [ ] (test coverage) No automated test checks the oversize-body 413 status with `display_errors` Off (the test and production setting). Docker gives 200 with a warning prefix. Add an integration test that starts `php -S` with `-d display_errors=0` and a small `post_max_size`, and asserts 413, JSON, and the log row (found in #2627)
- [ ] `uploadImages()` checks the whole batch with `validateUploadBatch()` before it moves any file. So a rejected photo leaves no file on disk. But when a later error stops the save (a move or resize failure, or an upload-order error from `checkUploadOrder()`), the files that the request already wrote (and their `-resized-` variants) stay on disk: `userimages/temp/` for an add, `userimages/{carId}/` for an edit. Nothing references or removes them. Delete the files this request wrote before the 422 is sent (found in #1844)

### `app/assets/js/location-picker.js`

- [ ] `render()` ~L133 — the inner `showGPS` ternary for the "OR" divider is dead, because the outer `showGPS &&` guard already holds. Use the plain divider (found in #2043)

### `app/assets/js/model-loader.js` (est. −18)

- [ ] Delete `ModelLoader.getModelByValue()` (~99-117). Nothing calls it (JS, PHP, or Playwright). (found in the #2208 audit)

### `app/owner/contact/owner.php`

- [ ] Replace `$db->get('cars', ...)` (~L25) with `CarRepository::findById()`. Keep the redirect to `/` on not-found (from #2002)

### `app/views/cars/` (section partials)

- [ ] `_vehicle_info_card` and `_factory_data_card` link the card level to the heading level. Only `h4` gets `card-header-er-l2`, so a nested `h3` card cannot use L2 styling (UI_STANDARDS "Card Hierarchy"). `_owner_info_card`, `_location_map_card` and `_photos_card` always use a plain `card-header`. Give all five partials a card-level variable (found in #2495)

### `app/views/cars/_factory_data_card.php`

- [ ] "Factory Notes" is an `h6` under the `h3` card heading, so it skips levels. Use a `p` with `fw-semibold`, or a heading one level below `$headingTag` (found in #2495)

### `app/views/cars/_location_map_card.php` `app/views/cars/_location_map_scripts.php`

- [ ] Both files copy the same "has map" lat/lon rule, and only a comment keeps them the same. Put the rule in one helper (for example `CarView::hasMapPosition()`) with a unit test (found in #2495)
- [ ] MapLibre already sets `role="region"` and `aria-label="Map"` on its canvas, so the `role` and `aria-label` on `#map` make a nested, duplicate landmark. Keep one label (remove it from `#map`, or set `Map.Title` through the map `locale`). The label says "the car's" location, but `cars.lat`/`lon` are the owner's location (found in #2495)

### `app/views/cars/_owner_info_card.php`

- [ ] Add a render test (as in `tests/unit/views/FactoryDataCardTest.php`): the Registry Owner ID shows when `$showOwnerId` is true and is hidden by default, `fname` and `city` with `<script>` come out escaped, and a `javascript:` website is not linked. The details-page wiring check in `SharedCarPartialsTest` is source text only (found in #2495)

### `app/views/cars/_verify_landing.php`

- [ ] The "Back to all options" `btn-link` is about 38px high, below the 44px touch target. The `#verify-actions` target cannot take focus, so a screen reader announces nothing after the jump. Add `tabindex="-1"` and a group label (found in #2495)

### `database/4-sample-data.sql` + `tests/playwright/fixtures.js`

- [ ] Sample car 1 (1973, chassis `7301019999B`) now fails the 1972-1974 9-character rule, and its comment still says "Post-1970 format: YYMMBBXXXXC". `CAR_ID_OWNED_BY_ADMIN` uses car 1, so an unmocked save test on it fails on the chassis. Change the sample chassis to a 9-character value such as `73081602L`, or note the failure on the fixture constant (found in /flow:finish-milestone v2.31.2)

### `database/migrations/20260915000000_add_cars_verification_attempts_columns.php`

- [ ] The `down()` comment (~L84) says "MySQL refuses to DROP a column that a trigger body still references". MySQL 8 allows the drop; the next INSERT, UPDATE or DELETE on `cars` then fails with 1054. The restore-first order is still right. Correct the reason in the comment (found in #2167)

### `docs/development/adr/ADR-018-build-at-deploy-for-frontend-vendoring.md`

- [ ] The "Update 2026-11-10" note says Node 20 or later, but the original Node v18 research text below it is not marked as superseded. Mark that paragraph as superseded (found in /flow:finish-milestone v2.31.2)

### `docs/reference/chassis-validation.php`

- [ ] The section id `format-1971` sits on the heading "11-Character Format (1970-1971)", and the summary table links 1970 to `format-1970`. Rename the id to `format-11-char` and update its links (found in /flow:finish-milestone v2.31.2)

### `error/500.php`

- [ ] Restore a unit pin: all nine status codes (400/401/403/404/405/408/500/502/504) have entries in both `$errorMessages` and `$logCategoryMap` (from #2169)

### `package.json` + `scripts/build.js` + `app/assets/js/car-edit.js` (−1 dependency)

- [ ] `@versatiles/style` 9 needs Node 20 or later, but `package.json` has no `engines` field and the repo has no `.nvmrc`. Add `"engines": { "node": ">=20" }` (found in /flow:finish-milestone v2.31.2)
- [ ] Drop `filepond-plugin-image-exif-orientation`: the package, its `<script>` tag, its `registerPlugin()` argument (car-edit.js ~29), and its copy line (build.js ~47). Current browsers apply EXIF orientation natively. **First upload one rotated phone JPEG with the plugin removed, and confirm that the stored image and the resized variants are upright.** (found in the #2208 audit)

### `scripts/render-deploy-sheet.sh`

- [ ] ~L143 `grep -qx '.env.example'` reads `.` as any character. Use `grep -Fxq '.env.example'`, so only the literal file name matches (found in #2233)

### `scripts/spike-1871/` (est. −960)

- [ ] Delete the directory (capture receiver, send-test CLI, README). Its README says it stays "until #1887 ships its own fixtures". #1887 is closed, and the Brevo payload fixtures now live in `BrevoWebhookEventProcessorTest.php` and `EmailEventApplierTest.php`. `scripts/spike-1888/brevo-webhook-capture.php` covers the same capture job. (found in the #2208 audit)
- [ ] Update the pointers in the same PR: `docs/development/EMAIL_SYSTEM.md` (~L2268, ~L2336), `scripts/README.md` (~L464-467), the comments in `scripts/spike-1888/*.php`, and the wiki page `Brevo-Webhook-Configuration`. (found in the #2208 audit)

### `tests/Support/BrevoOverrideStub.php` + `tests/Support/BrevoSiteRoot.php` (test-only)

- [ ] `BrevoOverrideStub` writes `"<?php\n"` and `sweep()` deletes any file with that exact content. Add a unique marker comment to the stub content (found in /finish-milestone v2.30.7)

### `tests/integration/AdminClearBlockAndOptOutTest.php` (test coverage)

- [ ] Only the `CLEARED` outcome runs an import afterwards. Add one after a shared-address (and moved-address) Clear Block: Brevo's entry with its original `blockedAt` and with none; the flag stays clear and the other account does not change (found in /finish-milestone v2.30.9)

### `tests/integration/AdminDeliverabilityActionsAuthTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy. `invokeEndpoint()` needs GET/HTML or session-flash support first (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L161), and it ignores a failed `$loggedInUser->find()` (~L142), so an unknown user runs logged out. The class docblock (~L15) names `VerificationToggleEndpointBehaviorTest` as the model runner, but that class now uses `invokeEndpoint()`. The migration item above fixes all three. (found in #2165)

### `tests/integration/AdminEmailDeliveryTabAuthTest.php`

- [ ] The class copies `AdminDeliverabilityActionsAuthTest`: its own `grantPermission()` and permission-row cleanup (use `IntegrationTestCase::grantTestPermission()`), and its own `php -r` page runner (move to `invokeEndpoint()` with that class's item) (found in #2102 review)

### `tests/integration/CarEditOwnerColumnRefreshTest.php`

- [ ] `testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot` only checks that the new `owner_last_updated` is later than a date two years ago, and does not count `cars_hist` rows. Bound it with `assertEqualsWithDelta(time(), ..., 60)` and assert one new history row (found while working on #1929)

### `tests/integration/EmailEventRekeyReportAuthTest.php` (test coverage)

- [ ] `testVisitorWhoIsNotLoggedInIsRefusedAndGetsNoReport` checks only that no report shows. A crash passes too. Assert exit code 0 and HTTP 302, as the editor test does (found in #2358 review)

### `tests/integration/IntegrationTestCase.php` (test coverage)

- [ ] `invokeEndpoint()` takes no GET parameters, so it renders only the default admin tab. A test cannot assert the Verification tab "Batch results" area after a manual send. Add a GET-params argument (found in #2572 review)

### `tests/integration/IntegrationTestCase.php` + `tests/integration/RetentionPurgeJobIntegrationTest.php` + `tests/integration/database/DeletedUserAddressesBacklogRuleTest.php`

- [ ] The `er_deleted_user_addresses` table-exists query is copied three times (`deleteDeletedUserAddressRows()` and the two setUps). Add one protected helper in `IntegrationTestCase` and call it from all three (found in /finish-milestone v2.30.10)

### `tests/integration/OwnerCreateUpdateTransactionRollbackTest.php`

- [ ] Replace the hand-written DB proxy (~L55) with `PassThroughDatabase` (from #2169)

### `tests/integration/UserDeletionPrivacyStepTest.php` + `tests/integration/admin/RerunDeletionPrivacyStepScriptTest.php`

- [ ] `uniqueAddress`, `insertPluginRow`, `pluginRowExists`, `profileCount`, `deleteUsersRowOnly` and the holding-row reader are near-copies in both files (about 60 lines). Move them to a shared trait in `tests/Support/` (found in #2419)
- [ ] No test runs the retention purge and the deletion privacy step on the same `plg_sendinblue_messages` rows. Add a case: purge clears an old row of user A and of user B, then delete A; A's row is gone by message ID, B's cleared row stays (found in /finish-milestone v2.30.10)
- [ ] The rerun-page note about car-only addresses is asserted on the committed path only. Assert it is absent on the refused and failed paths (found in /finish-milestone v2.30.10)

### `tests/integration/UserSettingsResumeVerificationEndpointTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy. `invokeEndpoint()` needs GET/HTML or session-flash support first (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L196). The migration item above fixes it. (found in #2165)

### `tests/integration/VerifyCarLandingPageTest.php` (test coverage)

- [ ] Add a render test (as in `tests/unit/views/VehicleInfoCardTest.php`) for the not-an-object log in `_location_map_card.php` (found in #2495)
- [ ] No test pins that POST `action=verify` still works for a car with a malformed `image` column, now that `new Car()` runs before dispatch without a guard (#2495) (found in /flow:finish-milestone v2.31.0)

### `tests/integration/VerificationToggleEndpointBehaviorTest.php`

- [ ] The class does not delete the `logs` rows that its endpoint calls write (`AccessDenied`, `user_id = 0`, and `VerificationConfigChanged` rows), so they build up on every run. Use the `logIdFloor` and per-user `DELETE` pattern from `AdminAjaxGuardBehaviorTest` (found in #2165)

### `tests/integration/cars/services/CarRepositoryProfileFlagReadersTest.php`

- [ ] `testRepositorySqlHasNoMaxOverTheProfileFlagColumns` (~L151) and `testCarDetailsPageLoadsTheOwnerProfileStateForTheCard` (~L392) read source text with `file_get_contents()`. Replace them with behavior tests (found in #2330)

### `tests/playwright/admin-verification-dashboard.spec.js` (test coverage)

- [ ] No test covers the queue and activity forms binding when the switch is absent (#2457). The probe-failure path needs a failing DB (see the note at L267) (found in /flow:finish-milestone v2.31.0)

### `tests/playwright/car-edit-text-save.spec.js` (test-only, no estimate)

- [ ] The test "comments with special characters save and reload as plain text" (~line 463) fails on every parallel run and in 2 of 4 runs with `--workers=1`. After the mocked save, `car-edit.js` goes to `details.php`, so `#comments` is not on the page and the `waitForFunction` (~line 587) times out. Read the saved comments on `details.php`, or stop the redirect in the mock (found in #2608)
- [ ] The test "details page renders special chars as readable text" (~line 1155) fails on every run. It reads `body.textContent`, which includes inline `<script>` text such as `.replace(/&/g, '&amp;')`. Read `innerText`, or only the car text fields (found in #2608)
- [ ] With default workers, this spec, `car-edit-get-car-id.spec.js`, and `car-edit-missing-car.spec.js` together had extra failures in 4 of 12 runs, a different set each time (timeouts, "processFiles() did not resolve"). With `--workers=1`, 4 of 4 runs had none. Update mode on a GET `car_id` now fires a real `fetchImages` in each test, which adds load. Mock `fetchImages` before each `goto`, or wait on a page condition, not a fixed 5 s timeout (found in #2608)

### `tests/playwright/local/fixtures/` + `tests/integration/database/EmailEventRekeyMigrationFixture.php`

- [ ] Seven `seed-*.php` fixtures and the migration fixture each copy the `er_deleted_user_addresses` cleanup: a table-exists check, then a `DELETE ... IN (...)`. Three also copy the username-to-ID lookup. None checks `$db->error()` after the check or the delete, although the cleanup loop in the same files exits on an error. Add one shared helper (for example `Tests\Support\DeletedUserAddressCleanup::forUserIds($db, $ids)`) that checks `error()`, and call it from each fixture (found in #2419)

### `tests/unit/admin/VerificationBannerReasonTest.php` (test quality)

- [ ] The unreadable-banner check matches `app/admin/index.php` source with regexes, so a rename breaks it, and it does not check the outer `if ($verificationBannerSeverity !== null)` gate. Replace it with a render test when an `index.php` render harness exists (found in #2102 review)

### `tests/unit/cars/services/AdminVerificationQueueUrlStateTest.php` (test quality)

- [ ] The test reads `app/admin/index.php` source with `file_get_contents` and slices a block out of it. Replace it with a behavior test of the queue URL state, for example through `IntegrationTestCase::invokeEndpoint()` (found in #2330)

### `tests/unit/classes/Email/BrevoDevOverrideTest.php` (test coverage)

- [ ] `BrevoDevOverrideTest.php:198`: the fixture SDK keeps its host by design, so "survives setApiKey" cannot fail there; only the real-SDK test at :212 proves it, and it skips where the plugin vendor dir is missing (CI). (found in /finish-milestone v2.30.6)

### `tests/unit/cron/BrevoSuppressionSyncClientTest.php` (test quality)

- [ ] The "autoloader throws" case does not assert that the exception message stays out of the log. Assert that the message has no `broken` (found in /flow:finish-milestone v2.31.0)

### `tests/unit/cron/BrevoSuppressionSyncJobTest.php` (test quality)

- [ ] `runWasMarkedFailed()` reads the private `AbstractCronJob::$runFailed` through reflection. When the job gets a healthcheck slug (#2586), assert the `/fail` ping through an injected pinger and delete the helper (found in /flow:finish-milestone v2.31.0)

### `tests/unit/cron/SendVerificationBatchJobTest.php` (test coverage)

- [ ] `testEligibleCarIdsPassThroughUnchangedToProcessBatch` (~L255) uses `assertContains`, so a reordered batch passes, and no test checks that `VerificationBatchSender::processBatch()` sends in the submitted order. Assert the exact id order in both (found in #2599)

### `tests/unit/regression/Issue2360SendPolicySourceRegressionTest.php`

- [ ] The source-position checks at ~L317, ~L322 and ~L353 repeat behavior tests (`SendOwnerEmailSendPolicyTest` ~L246, ~L334, `AdminContactSendPolicyTest`). Delete them, and keep the tests of the checker helper (found in /finish-milestone v2.30.8)
- [ ] Replace the source check at ~L328 (policy check after the template render) with a behavior test: with a missing template, a blocked recipient and a clean recipient get the same error, and the log has no policy line (found in /finish-milestone v2.30.8)

### `tests/unit/regression/Issue2460OwnerContactTagSourceRegressionTest.php` (test quality)

- [ ] The regex needs the exact `email(...['tags'=>...]+$replyOpts)` text, and the constant test is a tautology. Assert `sendinblueTagFromFile(<endpoint path>) === OWNER_CONTACT_EMAIL_TAG`, and add a mock-Brevo send in `SendOwnerEmailSendPolicyTest` that checks the plugin row `tags` (found in /finish-milestone v2.30.9)

### `tests/unit/regression/JoinFailureReportUsesDedicatedRateLimitBucketRegressionTest.php`

- [ ] Rename `testExactlyOneConfigEntryExistsPerAction`. It asserts presence, not uniqueness (from #2169)

### `tests/unit/security/AdminAjaxGuardTest.php`

- [ ] Move `significantTokens()` / `tokensCallFunction()` into one `tests/Support/` helper, shared with the sibling pins from #2161 (from #2169)
- [ ] `adminEndpointProvider()` globs only the top level of `app/admin/includes/`. Make it recursive (from #2169)

### `tools/phpstan/` (test-only, no estimate)

- [ ] No test proves the `phpstan.neon` wiring of the path-based rules. A typo in a `files:` or `exemptPages:` entry, or a different working directory for `projectRoot`, makes the rule silently check nothing. Add a smoke test that runs PHPStan with the real config on one known violation per rule. (found in #2327)
- [ ] `PageMetadataBeforeInitRule` does not report an `exemptPages` entry whose file was deleted or moved, or no longer calls `securePage()`. Report it as a stale exemption. (found in #2327)

### `usersc/classes/Car/Car.php` + `app/api/cars/save.php`

- [ ] Each consumer builds the `{root}{ELAN_IMAGE_DIR}{carId}/` path by hand (`Car.php` ~L75 and ~L178, `save.php` ~L41, ~L697 and ~L915, `24-Regenerate-Optimized-Thumbnails.php` ~L381, `app/owner/cars/index.php` ~L120, `details.php` ~L479, `statistics.php` ~L359). Add one helper and call it from each (from #1943)

### `usersc/classes/Car/CarImageProcessor.php`

- [ ] `resolveUploadOrder()` and `checkUploadOrder()` pass array shapes that only docblocks describe, and `checkUploadOrder()` returns data that the caller already holds (`missing`). Use a small readonly value object, or return only `errors`, `storedUnsafe` and `unplaced` (found in /flow:finish-milestone v2.31.2)
- [ ] `cleanClientText()` is a general client-text helper, not image logic, and `save.php` calls it. Move it to a shared helper when a second caller appears (found in /flow:finish-milestone v2.31.2)

### `usersc/classes/Car/CarRepository.php`

- [ ] `recentFlagChanges()` (~L2327) has no production caller, but tests in 15 unit and integration files read flag-change rows through it. Delete it only after those tests use a test helper (found in /finish-milestone v2.30.7)
- [ ] The block-cause names are four loose string sets: `BLOCKED_CAUSES`, `EmailEventApplier::SUPPRESSION_EVENT_CAUSES`, the bare `'spam'` and `'unsubscribed'` matches in `VerificationTabStatus::chip()`, and `EffectiveEmailFlags::$blockedCause` (`?string`). Add `enum BlockedCause: string` and convert to a string only at the database boundary (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/CarVerificationEmailComposer.php`

- [ ] Photo FileError logs record user 0. The composer has no actor id, so an admin-started send is not attributed (found while working on #1894)

### `usersc/classes/Car/ChassisOverrideNoteResult.php`

- [ ] Two bools (`changed`, `manual`) allow a state that the named constructors forbid. Replace them with an enum (`Unchanged`, `Stripped`, `Manual`) (found in /flow:finish-milestone v2.31.2)

### `usersc/classes/Car/EffectiveEmailFlags.php`

- [ ] `fromQueueRow()` checks that each column exists, not its value. A null or non-numeric flag casts to 0 (no flag). Reject null and values other than 0 or 1 for the four flag columns (found in /flow:finish-milestone v2.31.0)
- [ ] `fromQueueRow()` throws a bare `\InvalidArgumentException`. Check ERROR_HANDLING.md for programmer-error exceptions, or use a typed one. Move `QUEUE_ROW_COLUMNS` to the top of the class (found in /flow:finish-milestone v2.31.0)

### `usersc/classes/Car/EmailEventMatch.php`

- [ ] Make the class `final readonly`. The accessors return `?? 0` or `?? ''` after `requireMatched()`, which hides a broken invariant. Return the typed value with a PHPStan assert instead (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/SendPolicy.php`

- [ ] The flag list is written three times: `SendDecision::FLAG_ORDER`, the `$all` list in `blockingFlags()`, and the table docblock. Build it once, or use a `SendFlag` enum (found in /finish-milestone v2.30.8)
- [ ] `adminMessage()` has no sentence for `email_blocked`, which is right only for `MailKind::AdminContact`. Take the `MailKind`, or rename it `adminContactMessage()` (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/SendResult.php`

- [ ] Replace the raw `string $status` with a backed enum, so callers cannot compare raw strings. `VerificationBatchSender` does `$sendResult->status === SendResult::STATUS_SENT` today. Update it in the same change (from #2149)

### `usersc/classes/Car/VerificationEligibility.php`

- [ ] The class docblock says the class encodes the same rules as the WHERE clause of `CarRepository::findVerificationEligible()` (built by `verificationEligibleFromSql()`). The #2594 address-shape rule is in the SQL only. Correct the docblock (from #2595)

### `usersc/classes/Car/VerificationSettings.php`

- [ ] `isEnabled()` has no production caller after #2602. All callers use `isEnabledOrNull()`. Remove `isEnabled()`, its tests in `VerificationSettingsTest`, and the references to it in `CLASSES.md` (method list and the `VerificationTabStatus` entry), the `{@see isEnabled()}` in `VerificationSettings.php`, and the `VerificationTabStatus.php` class docblock (found in /flow:finish-milestone v2.31.1)

### `usersc/classes/Car/VerificationTabStatus.php`

- [ ] `blockedCauseLabel()` treats a whitespace-only `email_blocked_cause` as a cause and shows a blank-looking reason. Only manual SQL can write one. Trim before the empty check (found in #2458 review)
- [ ] The `MISSING` job-row `detail` names `er_cron_job_runs` as plain text, so the panel cannot put it in `<code>`, as it does for the `advice` segments. Give `detail` the same `{text, code}` segments or move the name into `advice` (found in #2458 review)

### `usersc/classes/CarView.php`

- [ ] The no-photo placeholder in `displayCarousel()` is an `h5` under the `h3` Photos card, so it skips levels. Its `fa-camera` icon has no `aria-hidden="true"` (found in #2495)

### `usersc/classes/ChassisValidator.php`

- [ ] The `validatePost1970()` docblock says "5 characters" for 1970-1974, but the 5-character format applies only to model year 1970. Name each format with its years (found in /flow:finish-milestone v2.31.2)
- [ ] `ruleGroup()` returns a `string` that only a docblock limits to `RULE_GROUPS` keys. A string-backed enum would remove the `RULE_GROUPS[$groupKey]` lookups in `tab-manage_cars.php` and fix script 31 (found in /flow:finish-milestone v2.31.2)

### `usersc/classes/Cron/HealthcheckPinger.php` + `usersc/classes/Cron/CurlHealthcheckTransport.php`

- [ ] `CurlHealthcheckTransport`: set `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS` and an explicit `CURLOPT_FOLLOWLOCATION => false` (defense in depth; the base URL is constant) (found in /finish-milestone v2.30.9)

### `usersc/classes/Email/BrevoApiFactory.php`

- [ ] The `reason` key of `readApiKey()` and `loadSdk()` never varies within each method. Use `cause !== null` as the failure test, define `no_api_key` and `no_sdk` only in `BrevoUnblockReason`, and drop the `REASON_*` aliases. Do it together with moving `BrevoEventReconciliationClient` onto the factory, and keep its `readiness()` mapping: table absent and key not set give NOT_CONFIGURED, every other cause gives FAILED (found in #2426, mapping note from /flow:finish-milestone v2.31.0)
- [ ] Replace the `array{reason, cause, detail}` returns of `readApiKey()` and `loadSdk()` with two small readonly result types (or PHPStan shape aliases) (found in /finish-milestone v2.30.9)
- [ ] No test calls `readApiKey()` directly. Add `tests/unit/classes/Email/BrevoApiFactoryTest.php` with `SendinblueSettingsFakeDatabase`: success, key not set, `42S02` and `HY000`, each with the `sqlState` value (found in #2488)
- [ ] The class is no longer `final` only so a test double can extend it, and a double must override `loadSdk()` too. Extract a small interface (`loadSdk()`, `createApi()`) for `BrevoBlocklistClient` and make the factory `final` again (found in /flow:finish-milestone v2.31.0)

### `usersc/classes/EmailTemplate.php`

- [ ] The `createButtonRow()` docblock says the buttons stack full-width below 600 px, but each `<a>` keeps its inline `display: inline-block`, so stacked buttons keep their own width. Correct the docblock or the style (found in #2608)

### `usersc/classes/Exceptions/ProfileFlagValueException.php`

- [ ] Mark the class `final`. It extends `CarDatabaseException` and nothing extends it (found in /finish-milestone v2.30.8)

### `usersc/classes/Exceptions/RetentionPurgeException.php`

- [ ] `forStep()` sets `step` and `sqlState` after construction on non-readonly properties. Make them `private readonly`, set through a constructor, and document why `forStep()` passes no previous exception (found in /finish-milestone v2.30.10)

### `usersc/classes/InputSanitizer.php` (est. −8)

- [ ] `stripHeaderInjectionChars()`: replace `preg_replace` and its PCRE-failure throw with `str_replace(["\r", "\n", "\t"], '', (string) $value)`. `str_replace` cannot fail. Related: #1786. (found in the #2208 audit)
- [ ] `normalize()`: drop the length check before `mb_substr()`. `mb_substr()` never pads the string, so the check does nothing. (found in the #2208 audit)

### `usersc/classes/LogCategories.php` (est. −410)

- [ ] Delete the constants that no project code uses (65–69 of 115: `PASSKEY_*`, `OAUTH_*`, `TOTP_*`, `LOGIN*`, `PASSWORDLESS_*`, `*_MANAGER`, `GEOCODE`, `SECURE_PAGE`, `HAS_PERM`, `CHECK_ACCESS`, `IP_LOGGING`, `BACKUP_OPERATION`, `CAR_UPDATE`, `DIAGNOSTICS`, and others). Keep the class. (found in the #2208 audit)
  - Check first: the gitignored upstream plugin code may reference some of these constants. A search of tracked files cannot see it.
  - Check first: the admin log viewer may filter by the stored category *values*. Old `logs` rows keep their values after a constant goes.
  - Check first: `docs/development/LOG_CATEGORIES.md` lists the constants.
  - Check first: `ApiResponseTest.php` (~406, ~692) and `CarUpdateRepositoryFailureTest.php` (~106) use two of them.
  - Do this with #1997, which splits the overloaded categories in the same file.

### `usersc/classes/Migration/DeliverabilityMigrationQueries.php` + `tests/integration/database/DeliverabilityMigrationFixture.php`

- [ ] The "admin Clear Suppression" text (queries L64, L232; fixture L28) describes legacy `cars_hist` rows. Add "former action, replaced in #2357" so a reader does not look for it (found in /finish-milestone v2.30.9)

### `usersc/classes/Owner.php` + `usersc/user_settings.php` + `app/verify/verify_car.php` + `usersc/classes/Car/EmailEventApplier.php`

- [ ] The `try { logger() } catch { error_log('[ElanRegistry] ...') }` guard is copied at about 7 sites. Rollback guards are in `Owner.php` (2), `user_settings.php`, `verify_car.php` (`rollbackQuietly()`) and `EmailEventApplier.php`. Remove all of them and call `logger()` and `rollback()` directly (see ERROR_HANDLING.md): `logger()` throws only when the database already failed the request (see #2449, #2561). Update `UserSettingsResumeVerificationEndpointTest`, which checks the "logger failed" text (found in #2393)

### `usersc/classes/OwnerView.php` + `app/admin/includes/tab-owner_mgmt.php` (est. −11)

- [ ] `displayQualityBadge()` (~55-66) has only test callers. Meanwhile `tab-owner_mgmt.php:418` builds the same badge by hand and prints `$owner->quality_score` without a cast or escaping. That is low risk, because the value is a numeric SQL result. Call `displayQualityBadge()` from line 418 and delete the inline copy. This removes the duplicate and puts the escaping in one place. (found in the #2208 audit)

### `usersc/classes/Reference/CarModel.php` + `app/api/cars/models.php` (est. −158)

- [ ] Delete `getBySeries()`, `byValue()`, `getSeriesInYear()` (~67-175). Only tests call them (`CarModelTest.php`, `CarModelFailureTest.php`). (found in the #2208 audit)
- [ ] Delete the `getModelsByYear` action in `models.php` (~49-71) and `CarModel::getAvailableInYear()` (~40-65). `ModelLoader` only posts `getAllYearModels`, and nothing sends `getModelsByYear`. (found in the #2208 audit)

### `usersc/classes/RegistrationRecoveryNotifier.php`

- [ ] Replace 2 inline header-injection `preg_replace` calls with `InputSanitizer::stripHeaderInjectionChars()` (from #1786)

### `usersc/classes/Retention/DeletedUserAddressesBacklogRule.php`

- [ ] The alarm and a query failure both throw `RetentionPurgeException` and differ only by step text. Give the alarm its own step constant or subtype, so log triage can tell "run script 30" from "query failed" (found in /finish-milestone v2.30.10)

### `usersc/classes/Retention/SendinblueMessagesRetentionRule.php`

- [ ] The missing-table skip reason is a long inline literal. Make it a class constant, so tests can assert it without a copy of the text (found in /finish-milestone v2.30.10)

### `usersc/classes/Transfer/CarTransferRepository.php`

- [ ] Add PHPStan `@return` object shapes to `findById`, `findPendingById`, `findPendingWithCarById`, `getPendingWithCarAndUsers`, `getTodayStatusCounts`. This removes the 52 level-7 `property.notFound` errors in `tab-car_mgmt.php` (from #1532)

### `usersc/classes/Transfer/TransferEmailService.php` + `usersc/classes/Transfer/TransferNoticeOutcome.php`

- [ ] Make `sendAdminAlert()`'s `$ownerNotice` required. The default `TransferNoticeOutcome::Sent` hides the Owner Not Notified box for a caller that forgets it. The one caller passes it (found in /flow:finish-milestone v2.31.0)
- [ ] `TransferNoticeOutcome` is string-backed, but nothing calls `from()` or `tryFrom()`. Drop the backing unless logs or JSON need the value (found in /flow:finish-milestone v2.31.0)

### `usersc/classes/admin/BackupManager.php` (est. −65)

- [ ] Delete `verifyBackupIntegrity()` (~481-545). Only tests call it (`BackupManagerTest.php` ~190-250). No page or cron job calls it. (found in the #2208 audit)

### `usersc/classes/admin/MaintenanceStatusLabels.php` + `app/admin/maintenance.php` (est. −28)

- [ ] Inline the two 5-line `match` expressions into `maintenance.php` (~209, ~233). They are its only caller. Then drop the class and `MaintenanceStatusLabelsTest`. (found in the #2208 audit)

### `usersc/includes/example_admin_user_system_settings_post.php`

- [ ] line 25 — same `(Issue #237)` pattern (from #1978)
- [ ] line 34 — restates the line below it (from #1978)

### `usersc/includes/rate_limits_dev_override.php`

- [ ] Rename to a general name such as `dev_overrides.php` and update its includes. The file also applies the Brevo SDK host override (#2184), so its name no longer describes its scope. (found in #2385)
