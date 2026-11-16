<!-- markdownlint-disable MD013 -->
<!-- Each item stays on one line, because flow-ledger-items-for-files reads one line per item. -->

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
  `git diff --name-only <base>..HEAD | flow-ledger-items-for-files`.

## Items

### `.claude/rules/php-code.md`

- [ ] "When you review error handling" names only the last-resort fallback as an allowed `\Throwable` catch. `ERROR_HANDLING.md` also documents the page-section fault-domain catch (`VerificationTabStatus::read()`, `tab-verification.php`, #2364) as a deliberate exception. Add it to the rule, so reviewers do not flag those files (found in #1844)
- [ ] ~L24 "Wrap database operations in `try` and `catch`" conflicts with the "No guards" rule in `ERROR_HANDLING.md` and makes reviewers flag correct code. ~L55 documents `ApiResponse::error($message, $context)`, but the second parameter is `int $statusCode`. Correct both lines (found in #2505)

### `app/admin/assets/backup-operations.js` (test coverage)

- [ ] No test covers `deleteBackup()` (~L214), the one path that puts a variable (the file name) into `#confirmMessage`. It uses `textContent`. The XSS test in `admin-modal-confirmation.spec.js` opens the cleanup modal, which writes a fixed `innerHTML` string. Add a test that opens the delete confirmation for a file with a hostile name (found in #2699)

### `app/admin/includes/account-cleanup-helpers.php`

- [ ] Replace 9 × `throw new RuntimeException` with a typed `AccountCleanupException extends ElanRegistryException`. Catch it in `app/admin/includes/tab-account_cleanup.php` (~L55, ~L100) in place of `\Throwable` (from #1637)

### `app/admin/includes/process-transfer-approve.php` `app/admin/includes/process-transfer-deny.php` `app/api/cars/transfer-request.php`

- [ ] No test asserts the "No transfer approval/denial notification sent ... each one failed or the send policy skipped it" `EMAIL_ERROR` line. In `tests/integration/transfer/CarTransferWorkflowTest.php`, approve and deny with both parties opted out and assert that one row (found in /finish-milestone v2.30.9)
- [ ] `transfer-request.php` logs a send-policy skip of the owner notice as `EMAIL_ERROR`, but `policyAllows()` already logged it. Drop the endpoint line or log it under `CAR_TRANSFER` (found in /flow:finish-milestone v2.31.0)
- [ ] `process-transfer-approve.php` (~L131 and ~L147) wraps `$db->rollBack()` in `catch (\Throwable $rollbackEx)`. Remove the guard (see ERROR_HANDLING.md, no guards) (found in /flow:finish-milestone v2.31.0)

### `app/admin/includes/tab-car_mgmt.php`

- [ ] Narrow 2 × `catch (Exception $e)` (~L29, 53) to the typed exceptions from `Car` and `CarTransferRepository` (from #1637)

### `app/admin/includes/tab-email_delivery.php` `usersc/classes/Email/DeliveryLogRepository.php`

- [ ] The tab logs `ExceptionLogSummary::of($e)` only, and the repository puts the failed step ("table check", "tags column check", "window load") and the driver error only in the message, which it must not log. The log line cannot tell which query failed or why. Carry the step and the SQLSTATE (`errorInfo()[0]`) outside the message and log them (found in #2102 review)
- [ ] The owner, car and plugin links open a new tab with no screen-reader cue. Fix with the shared helper from the `tab-verification.php` `vsNewTabLink()` item (found in #2102 review)
- [ ] With an ignored recipient address, the `invalid-feedback d-block` hint grows the Recipient column, and `row g-2 align-items-end` then aligns the other filter controls to the hint, not the input. Use `align-items-start`, or move the hint to its own `col-12`. Also add `aria-invalid="true"` next to `is-invalid` (found in #2102 review)

### `app/admin/includes/tab-verification.php`

- [ ] The Automatic Sending card shows only "Status unavailable" for an `UNREADABLE` or `MISSING` job state, and its "Next eligible" row says "the job status could not be read" for `MISSING` too. Show the same detail lines as the status panel rows, from one shared source next to `CronJobRunsReader::badgeFor()` (found in #2572 review)
- [ ] `vsNewTabLink()` gives the queue and Recent Activity links no screen-reader cue that they open a new tab (WCAG G201), and neither table has an accessible name. Add `<span class="visually-hidden"> (opens in a new tab)</span>` in the helper, and a `visually-hidden` `<caption>` or `aria-labelledby` on each table (found in #2496 review)

### `app/admin/index.php`

- [ ] The `car_photos_apply` case is about 270 lines of parsing and message building inside the switch (~L1115). Move the parsing and the result messages into a small helper class, so that unit tests can cover them without a page request (found in #2644)
- [ ] The comment before the command switch (~L525) says CSRF is checked "above (line ~176)". The `Token::check()` gate is now at ~L344. Name the gate, not a line number (found in #2699)

### `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php` + `app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php` + `app/api/webhooks/brevo.php` + `usersc/classes/Car/CarAdministrationService.php` + `usersc/classes/Car/CarVerificationSendService.php` + `usersc/includes/custom_functions.php` + `usersc/plugins/hooker/hooks/user_form_hook.php` (logger guards)

- [ ] Each file wraps a `logger()` call in `try`/`catch (\Throwable)` (21 ~L1512, 26 ~L1728, brevo.php ~L156, CarAdministrationService ~L630, CarVerificationSendService ~L531, custom_functions ~L128, user_form_hook ~L60 and ~L105). Remove the guards, or move a deliberate one into the exceptions in ERROR_HANDLING.md with its reason (found in /flow:finish-milestone v2.31.0)

### `app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php` (test coverage)

- [ ] No test renders the notConfigured info notice or the manual-run log suffix. Run the script with the `plg_sendinblue` key cleared and assert the notice and no success alert (found in /flow:finish-milestone v2.31.0)

### `app/api/cars/save.php`

- [ ] The chassis error at ~L582 holds the literal `<strong>ERROR:</strong>`. `errors.general` is a list now, and `car-edit.js` escapes each item, so the tag shows as text. Remove the markup from the message (found in /flow:finish-milestone v2.32.0)
- [ ] (test coverage) No automated test checks the oversize-body 413 status with `display_errors` Off (the test and production setting). Docker gives 200 with a warning prefix. Add an integration test that starts `php -S` with `-d display_errors=0` and a small `post_max_size`, and asserts 413, JSON, and the log row (found in #2627)

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

### `docs/development/ENVIRONMENT.md`

- [ ] In the minimum-values table, the `memory_limit` row says a 2048 px bitmap uses "about 13 MB" in GD. That holds for a 4:3 frame. A 2048x2048 bitmap is about 16 MB. Say "about 13 to 17 MB" (found in /flow:finish-milestone v2.32.0)

### `docs/pdf-viewer.php`

- [ ] The `$is_servable_doc` closure (sets `$pageCanonicalUrl` before the header renders) repeats the subdir allowlist, the doc checks and the extension list of the later validation block, and uses `is_file()` where the block uses `file_exists()`. Define the lists once above both and share the checks (found in #1960)
- [ ] The comment above `$provisional_doc_metadata` says the legacy-alias 301 runs "before any title would be sent". The head renders first. Output buffering (`header1_must_include.php` `ob_start()`) is what lets the 301 work. Correct the comment (found in #1960)

### `package.json` + `scripts/build.js` + `app/assets/js/car-edit.js` (−1 dependency)

- [ ] Drop `filepond-plugin-image-exif-orientation`: the package, its `<script>` tag (`app/owner/cars/edit.php` ~L671), its `registerPlugin()` argument (car-edit.js ~L67, and the `/* global */` at L1), its copy line (build.js ~L48), and the committed `usersc/js/filepond-plugin-image-exif-orientation.min.js`. Current browsers apply EXIF orientation natively. **First upload one rotated phone JPEG with the plugin removed, and confirm that the stored image and the resized variants are upright.** (found in the #2208 audit)

### `tests/integration/AdminClearBlockAndOptOutTest.php` (test coverage)

- [ ] Only the `CLEARED` outcome runs an import afterwards. Add one after a shared-address (and moved-address) Clear Block: Brevo's entry with its original `blockedAt` and with none; the flag stays clear and the other account does not change (found in /finish-milestone v2.30.9)

### `tests/integration/AdminDeliverabilityActionsAuthTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L173), and it ignores a failed `$loggedInUser->find()` (~L148), so an unknown user runs logged out. The class docblock (~L15) names `VerificationToggleEndpointBehaviorTest` as the model runner, but that class now uses `invokeEndpoint()`. The migration item above fixes all three. (found in #2165)

### `tests/integration/AdminEmailDeliveryTabAuthTest.php`

- [ ] The class copies `AdminDeliverabilityActionsAuthTest`: its own `grantPermission()` and permission-row cleanup (use `IntegrationTestCase::grantTestPermission()`), and its own `php -r` page runner (move to `invokeEndpoint()` with that class's item) (found in #2102 review)

### `tests/integration/CarEditOwnerColumnRefreshTest.php`

- [ ] `testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot` only checks that the new `owner_last_updated` is later than a date two years ago, and does not count `cars_hist` rows. Bound it with `assertEqualsWithDelta(time(), ..., 60)` and assert one new history row (found while working on #1929)

### `tests/integration/CarTransferTest.php` + `tests/integration/SendOwnerEmailNoOwnerTest.php` (test-only)

- [ ] Five copies of the `noowner` id lookup (`SELECT id FROM users WHERE username = ...`): `CarTransferTest` ~L340, ~L375 and ~L436, `SendOwnerEmailNoOwnerTest::noownerId()` ~L62, and `UserDeletionPrivacyStepTest::noownerId()` ~L826. Move one helper into `IntegrationTestCase` that reads `AppConstants::NOOWNER_USERNAME` (found in #2505)

### `tests/integration/HeadTagsCanonicalRenderTest.php` + `tests/integration/VerifyCarLandingPageTest.php` + `tests/Support/UserscPageClient.php` (test-only)

- [ ] Each file repeats the DB env propagation to the `php -S` process, the router bootstrap (autoload, Dotenv `.env.test.local`, chdir, require) and a curl wrapper that splits headers and reads `Location`. Move them to one `tests/Support` helper. Also use `trackNewRateLimitRows()` in place of the blanket `us_rate_limits` delete in both test classes (found in #1960)

### `tests/integration/OwnerCreateUpdateTransactionRollbackTest.php`

- [ ] Replace the hand-written DB proxy (~L32, an anonymous class that implements `DatabaseInterface`) with `PassThroughDatabase` (from #2169)

### `tests/integration/UserDeletionPrivacyStepTest.php` + `tests/integration/admin/RerunDeletionPrivacyStepScriptTest.php`

- [ ] `uniqueAddress`, `insertPluginRow`, `pluginRowExists`, `profileCount`, `deleteUsersRowOnly` and the holding-row reader are near-copies in both files (about 60 lines). Move them to a shared trait in `tests/Support/` (found in #2419)
- [ ] No test runs the retention purge and the deletion privacy step on the same `plg_sendinblue_messages` rows. Add a case: purge clears an old row of user A and of user B, then delete A; A's row is gone by message ID, B's cleared row stays (found in /finish-milestone v2.30.10)
- [ ] The rerun-page note about car-only addresses is asserted on the committed path only. Assert it is absent on the refused and failed paths (found in /finish-milestone v2.30.10)

### `tests/integration/UserSettingsResumeVerificationEndpointTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy. `invokeEndpoint()` needs GET/HTML or session-flash support first (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L209). The migration item above fixes it. (found in #2165)

### `tests/integration/VerifyCarLandingPageTest.php` (test coverage)

- [ ] Add a render test (as in `tests/unit/views/VehicleInfoCardTest.php`) for the not-an-object log in `_location_map_card.php` (found in #2495)
- [ ] No test pins that POST `action=verify` still works for a car with a malformed `image` column, now that `new Car()` runs before dispatch without a guard (#2495) (found in /flow:finish-milestone v2.31.0)

### `tests/integration/VerificationToggleEndpointBehaviorTest.php`

- [ ] The class does not delete the `logs` rows that its endpoint calls write (`AccessDenied`, `user_id = 0`, and `VerificationConfigChanged` rows), so they build up on every run. Use the `logIdFloor` and per-user `DELETE` pattern from `AdminAjaxGuardBehaviorTest` (found in #2165)

### `tests/integration/cars/services/CarRepositoryProfileFlagReadersTest.php`

- [ ] `testCarDetailsPageLoadsTheOwnerProfileStateForTheCard` (~L440) reads source text with `file_get_contents()`. Replace it with a behavior test (found in #2330)

### `tests/playwright/admin-verification-dashboard.spec.js` (test coverage)

- [ ] No test covers the queue and activity forms binding when the switch is absent (#2457). The probe-failure path needs a failing DB (see the comment above `expectBrevoRow()` in the spec) (found in /flow:finish-milestone v2.31.0)

### `tests/playwright/car-add-invalid-purchasedate.spec.js` + `tests/unit/cars/CarActionsSaveWiringTest.php` (test quality)

- [ ] The photo test (~L181) passes when `uploadImages()` never saw the file, because the "no leftover temp file" check holds with no file written. Also assert that `cardetails.image` holds one stored `img_<hex>.jpg` name (found in /flow:finish-milestone v2.32.0)
- [ ] The wiring test (~L581) and the spec header say "a failed create writes no FileError row". `removeTmpImages()` writes one when `unlink()` fails. Change the text to "no image-move FileError row" (found in /flow:finish-milestone v2.32.0)

### `tests/playwright/car-edit-csrf-recovery.spec.js` (test coverage)

- [ ] 'a token that still fails is not retried again' (~L110-126) does not check that the unsaved-changes guard stays on after the failed retry. After the out-of-date message, call `attemptLeave(page)` and expect `['beforeunload']`, as `car-edit-unsaved-guard.spec.js` does for a failed save (found in /flow:finish-milestone v2.33.0)

### `tests/playwright/local/fixtures/`

- [ ] Eight `seed-*.php` fixtures each copy the `er_deleted_user_addresses` cleanup: a table-exists check, then a `DELETE ... IN (...)`. All eight also copy the username-to-ID lookup. Only `seed-output-escaping-car.php` checks `$db->error()` after the delete. The others check `error()` after neither the check nor the delete. In most of them, the cleanup loop in the same file exits on an error. `seed-bounced-car.php` checks `error()` nowhere. Add one shared helper (for example `Tests\Support\DeletedUserAddressCleanup::forUserIds($db, $ids)`) that checks `error()`, and call it from each fixture (found in #2419)

### `tests/unit/SitemapServiceTest.php`

- [ ] The sitemap-vs-canonical parity test (~L150) uses `makeService([])`, so it covers static URLs only. Add one car, and compare its `details.php?car_id=N` URL with `CanonicalUrl::build()` (found in /flow:finish-milestone v2.33.0)

### `tests/unit/cars/services/AdminVerificationQueueUrlStateTest.php` (test quality)

- [ ] The test reads `app/admin/index.php` source with `file_get_contents` and slices a block out of it. Replace it with a behavior test of the queue URL state, for example through `IntegrationTestCase::invokeEndpoint()` (found in #2330)

### `tests/unit/cron/BrevoSuppressionSyncClientTest.php` (test quality)

- [ ] The "autoloader throws" case does not assert that the exception message stays out of the log. Assert that the message has no `broken` (found in /flow:finish-milestone v2.31.0)

### `tests/unit/cron/SendVerificationBatchJobTest.php` (test coverage)

- [ ] `testEligibleCarIdsPassThroughUnchangedToProcessBatch` (~L255) uses `assertContains`, so a reordered batch passes, and no test checks that `VerificationBatchSender::processBatch()` sends in the submitted order. Assert the exact id order in both (found in #2599)

### `tests/unit/regression/Issue2247LegacyRedirectsRegressionTest.php`

- [ ] The three tests that match `.htaccess` lines word for word (~L37-62) copy the #2247 rows of the e2e 301 table in `not-logged-in.spec.js`, which send real requests. Delete them. Keep `testRedirectTargetsExist` if it still adds value (found in /flow:finish-milestone v2.33.0)

### `tests/unit/regression/Issue2360SendPolicySourceRegressionTest.php`

- [ ] Replace the source check `test_endpoint_policyCallComesAfterTemplateRender` (policy check after the template render) with a behavior test: with a missing template, a blocked recipient and a clean recipient get the same error, and the log has no policy line (found in /finish-milestone v2.30.8)

### `tests/unit/regression/Issue2460OwnerContactTagSourceRegressionTest.php` (test quality)

- [ ] The regex needs the exact `email(...['tags'=>...]+$replyOpts)` text, and the constant test is a tautology. Assert `sendinblueTagFromFile(<endpoint path>) === OWNER_CONTACT_EMAIL_TAG`, and add a mock-Brevo send in `SendOwnerEmailSendPolicyTest` that checks the plugin row `tags` (found in /finish-milestone v2.30.9)

### `tests/unit/security/AdminAjaxGuardTest.php`

- [ ] Move `significantTokens()` / `tokensCallFunction()` into one `tests/Support/` helper, shared with the sibling pins from #2161 (from #2169)

### `tests/unit/system/CanonicalUrlTest.php`

- [ ] Most `encodedQueryProvider` rows (~L72-83) test `http_build_query(..., PHP_QUERY_RFC3986)`, not app behaviour. Keep the space-in-value and markup rows. Delete the rest (found in /flow:finish-milestone v2.33.0)

### `tests/unit/system/PageRobotsTest.php`

- [ ] The regex test that `verify_car.php` does not assign `$current_url` (~L116) copies `HeadTagsCanonicalRenderTest::testVerifyCarCanonicalOmitsTheVericode`. Delete it (found in /flow:finish-milestone v2.33.0)

### `tools/phpstan/` (test-only, no estimate)

- [ ] No test proves the `phpstan.neon` wiring of the path-based rules. A typo in a `files:` or `exemptPages:` entry, or a different working directory for `projectRoot`, makes the rule silently check nothing. Add a smoke test that runs PHPStan with the real config on one known violation per rule. (found in #2327)

### `tests/unit/system/RobotsTxtPolicyTest.php`

- [ ] The `testTestEnvironmentLockdownFilesExist` docblock (~L133) says the Test override files stop indexing. Since #2246, `robots-test.txt` allows crawl and the `.htaccess` `X-Robots-Tag` header stops indexing. Reword the docblock and the method name (found in #2246)

### `usersc/classes/AppConstants.php` + `usersc/classes/Car/CarAdministrationService.php` + `app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php` + `app/admin/index.php` + `app/admin/includes/tab-verification.php` + `app/admin/includes/account-cleanup-helpers.php` + `app/verify/verify_car.php`

- [ ] `AppConstants::NOOWNER_USERNAME` (~L70) is the third copy of `'noowner'`. `CarAdministrationService::SYSTEM_ACCOUNT_USERNAME` (~L77) and `RECONCILE_NO_OWNER_USERNAME` in `26-Reconcile-Owner-Fields.php` (~L203) repeat it, and seven inline strings remain: `app/admin/index.php` ~L368, L908, L1021, `tab-verification.php` ~L845, `account-cleanup-helpers.php` ~L27, L57, `verify_car.php` ~L335. Read the shared constant in each (found in /flow:finish-milestone v2.33.0)

### `usersc/classes/CanonicalUrl.php`

- [ ] The `build()` docblock does not say that an empty-string query value gives `key=`, or that `$scriptPath` must hold no `?` or `#`. Add those two rules (found in /flow:finish-milestone v2.33.0)

### `usersc/classes/Car/Car.php` + `app/api/cars/save.php`

- [ ] Each consumer builds the `{root}{ELAN_IMAGE_DIR}{carId}/` path by hand (`Car.php` ~L76 and ~L179, `save.php` ~L41, ~L755 and ~L975, `24-Regenerate-Optimized-Thumbnails.php` ~L381, `CarPhotoRecovery.php` ~L79). `app/owner/cars/index.php` ~L117, `details.php` ~L481 and `statistics.php` ~L358 build the URL root the same way. Add one helper and call it from each (from #1943)

### `usersc/classes/Car/CarImageProcessor.php`

- [ ] Three callers build the `{name}-resized-{size}.{ext}` file name themselves: `CarView` (srcset), `CarVerificationEmailComposer` (~L514) and `CarPhotoRecovery::thumbnailName()`. Add one `CarImageProcessor` helper next to `isResizedVariant()` and call it from all three (found in #2644 simplify pass)

### `usersc/classes/Car/CarRepository.php`

- [ ] `recentFlagChanges()` (~L2480) has no production caller, but tests in 15 unit and integration files read flag-change rows through it. Delete it only after those tests use a test helper (found in /finish-milestone v2.30.7)
- [ ] The block-cause names are four loose string sets: `BLOCKED_CAUSES`, `EmailEventApplier::SUPPRESSION_EVENT_CAUSES`, the bare `'spam'` and `'unsubscribed'` matches in `VerificationTabStatus::chip()`, and `EffectiveEmailFlags::$blockedCause` (`?string`). Add `enum BlockedCause: string` and convert to a string only at the database boundary (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/SendPolicy.php`

- [ ] `adminMessage()` has no sentence for `email_blocked`, which is right only for `MailKind::AdminContact`. The docblock says so. Rename it `adminContactMessage()`, or take the `MailKind` (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/VerificationEligibility.php`

- [ ] The class docblock says the class encodes the same rules as the WHERE clause of `CarRepository::findVerificationEligible()` (built by `verificationEligibleFromSql()`). The #2594 address-shape rule is in the SQL only. Correct the docblock (from #2595)

### `usersc/classes/Car/VerificationSettings.php`

- [ ] `isEnabled()` has no production caller after #2602. All callers use `isEnabledOrNull()`. Remove `isEnabled()`, its tests in `VerificationSettingsTest`, and the references to it in `CLASSES.md` (method list and the `VerificationTabStatus` entry), the `{@see isEnabled()}` in `VerificationSettings.php`, and the `VerificationTabStatus.php` class docblock (found in /flow:finish-milestone v2.31.1)

### `usersc/classes/CarView.php`

- [ ] `TITLE_YEAR_MIN` and `TITLE_YEAR_MAX` (~L44-45) copy the 1963-1974 range that `usersc/classes/Car/CarValidator.php:142` hard-codes. Use one shared constant (found in /flow:finish-milestone v2.33.0)

### `usersc/classes/ChassisValidator.php`

- [ ] The `validatePost1970()` docblock says "5 characters" for 1970-1974, but the 5-character format applies only to model year 1970. Name each format with its years (found in /flow:finish-milestone v2.31.2)

### `usersc/classes/EmailTemplate.php`

- [ ] The `createButtonRow()` docblock says the buttons stack full-width below 600 px, but each `<a>` keeps its inline `display: inline-block`, so stacked buttons keep their own width. Correct the docblock or the style (found in #2608)

### `usersc/classes/InputSanitizer.php` (est. −8)

- [ ] `stripHeaderInjectionChars()`: replace `preg_replace` and its PCRE-failure throw with `str_replace(["\r", "\n", "\t"], '', (string) $value)`. `str_replace` cannot fail. Related: #1786. (found in the #2208 audit)

### `usersc/classes/LogCategories.php` (est. −410)

- [ ] Delete the constants that no project code uses (about 66 of 117: `PASSKEY_*`, `OAUTH_*`, `TOTP_*`, `LOGIN*`, `PASSWORDLESS_*`, `*_MANAGER`, `GEOCODE`, `SECURE_PAGE`, `HAS_PERM`, `CHECK_ACCESS`, `IP_LOGGING`, `BACKUP_OPERATION`, and others). Keep the class. (found in the #2208 audit)
  - Check first: the gitignored upstream plugin code may reference some of these constants. A search of tracked files cannot see it.
  - Check first: the admin log viewer may filter by the stored category *values*. Old `logs` rows keep their values after a constant goes.
  - Check first: `docs/development/LOG_CATEGORIES.md` lists the constants.
  - Check first: `ApiResponseTest.php` (`testBuilderIsImmutable` row `withLogging`, `testToArrayDoesNotIncludePendingLog`) use `DIAGNOSTICS`. `tests/integration/cars/CarUpdateRepositoryFailureTest.php` names `LOG_CATEGORY_CAR_UPDATE` in an assertion message.
  - Do this with #1997, which splits the overloaded categories in the same file.

### `usersc/classes/Owner.php` + `usersc/user_settings.php` + `app/verify/verify_car.php` + `usersc/classes/Car/EmailEventApplier.php`

- [ ] The `try { logger() } catch { error_log('[ElanRegistry] ...') }` guard is copied at about 7 sites. Rollback guards are in `Owner.php` (1), `user_settings.php` (4), `verify_car.php` (`rollbackQuietly()`) and `EmailEventApplier.php`. Remove all of them and call `logger()` and `rollback()` directly (see ERROR_HANDLING.md): `logger()` throws only when the database already failed the request (see #2449, #2561). Update `UserSettingsResumeVerificationEndpointTest`, which checks the "logger failed" text (found in #2393)

### `usersc/classes/OwnerView.php` + `app/admin/includes/tab-owner_mgmt.php` (est. −11)

- [ ] `displayQualityBadge()` (~55-66) has only test callers. Meanwhile `tab-owner_mgmt.php:418` builds the same badge by hand and prints `$owner->quality_score` without a cast or escaping. That is low risk, because the value is a numeric SQL result. Call `displayQualityBadge()` from line 418 and delete the inline copy. This removes the duplicate and puts the escaping in one place. (found in the #2208 audit)

### `usersc/classes/Reference/CarModel.php` + `app/api/cars/models.php` (est. −158)

- [ ] Delete `getBySeries()`, `byValue()`, `getSeriesInYear()` (~67-175). Only tests call them (`CarModelTest.php`, `CarModelFailureTest.php`). (found in the #2208 audit)
- [ ] Delete the `getModelsByYear` action in `models.php` (~49-71) and `CarModel::getAvailableInYear()` (~40-65). `ModelLoader` only posts `getAllYearModels`, and nothing sends `getModelsByYear`. (found in the #2208 audit)

### `usersc/classes/RegistrationRecoveryNotifier.php`

- [ ] Replace the inline header-injection `preg_replace` at ~L129 with `InputSanitizer::stripHeaderInjectionChars()`. The call at ~L146 replaces with a space, so it stays (from #1786)

### `usersc/classes/Transfer/TransferEmailService.php` + `usersc/classes/Transfer/TransferNoticeOutcome.php`

- [ ] Make `sendAdminAlert()`'s `$ownerNotice` required. The default `TransferNoticeOutcome::Sent` hides the Owner Not Notified box for a caller that forgets it. The one caller passes it (found in /flow:finish-milestone v2.31.0)

### `usersc/classes/admin/BackupManager.php` (est. −65)

- [ ] Delete `verifyBackupIntegrity()` (~L494). No page or cron job calls it. `BackupManagerTest.php` and `BackupRestorabilityTest.php:104` call it, and the second uses it as a real integrity check, so give that test a replacement check first. (found in the #2208 audit)

### `usersc/includes/rate_limits_dev_override.php`

- [ ] Rename to a general name such as `dev_overrides.php` and update its includes. The file also applies the Brevo SDK host override (#2184), so its name no longer describes its scope. (found in #2385)
