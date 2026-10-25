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

### `app/admin/includes/account-cleanup-helpers.php`

- [ ] Replace 8 × `throw new RuntimeException` with a typed `AccountCleanupException extends ElanRegistryException`. Handle it in `app/api/admin/account-cleanup-data.php` with `ApiResponse` and `LogCategories` (from #1637)

### `app/admin/includes/process-transfer-approve.php` `app/admin/includes/process-transfer-deny.php` `app/api/cars/transfer-request.php`

- [ ] No test asserts the "No transfer approval/denial notification sent ... each one failed or the send policy skipped it" `EMAIL_ERROR` line. In `tests/integration/transfer/CarTransferWorkflowTest.php`, approve and deny with both parties opted out and assert that one row (found in /finish-milestone v2.30.9)

### `app/admin/includes/tab-car_mgmt.php`

- [ ] Narrow 2 × `catch (Exception $e)` (~L29, 53) to the typed exceptions from `Car` and `CarTransferRepository` (from #1637)

### `app/admin/scripts/maintenance/28-Reconcile-Brevo-Suppressions.php`

- [ ] The `set_time_limit(120)` comment (~L110-132) says the network wait of a Brevo page counts toward `max_execution_time`. On Linux it counts CPU time only. The wall-clock limit is Cloudflare's, about 100 seconds. Correct the reason, or delete the call (found in #2359)

### `app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php`

- [ ] A live user ID typed on this page makes `AccountDeletionPrivacyStep::run()` write the deletion-incident CRITICAL refusal line under that owner's ID. Give `run()` a caller context, so that a refusal from this page logs a non-CRITICAL line that names the admin ID (found in #2419)

### `app/api/cars/list.php`

- [ ] `(new CarShowcaseService())->getNewCarIds()` (~64) runs an uncached query on every DataTables request (page turn, sort, search) since #1900. Before, it ran once per page load. Memoize it per request or for a short time. Accepted as low risk for v2.30.4. (found in /finish-milestone v2.30.4)

### `app/api/cars/save.php`

- [ ] The `updateImages` and `removeImages` auth checks (about lines 233 and 244) compare `$user->data()->id != ...->user_id` with loose `!=`. Use a strict `(int)` comparison, as `removeImage()` does (found while working on #1929)

### `app/api/contact/send-owner-email.php`

- [ ] Six `logger()` calls (~L113, 135, 151, 186, 210, 214) log `$e->getMessage()`. `CarRepository` copies driver text into its messages. Log `ExceptionLogSummary::of($e)` instead (found in #2460)

### `app/assets/js/car-edit.js`

- [ ] line 344 — restates the line below it. Do it in #2045, which changes the year→model code (from #1978)
- [ ] line 349 — restates the line below it. Do it in #2045, which changes the year→model code (from #1978)
- [ ] line 352 — restates the line below it. Do it in #2045, which changes the year→model code (from #1978)
- [ ] line 358 — restates the line below it. Do it in #2045, which changes the year→model code (from #1978)
- [ ] line 361 — restates the line below it. Do it in #2045, which changes the year→model code (from #1978)

### `app/assets/js/model-loader.js` (est. −18)

- [ ] Delete `ModelLoader.getModelByValue()` (~99-117). Nothing calls it (JS, PHP, or Playwright). (found in the #2208 audit)

### `app/owner/contact/owner.php`

- [ ] Replace `$db->get('cars', ...)` (~L25) with `CarRepository::findById()`. Catch `CarDatabaseException`: an uncaught one turns a logged redirect into a 500. Keep the redirect to `/` on not-found (from #2002)

### `database/migrations/20260915000000_add_cars_verification_attempts_columns.php`

- [ ] The `down()` comment (~L84) says "MySQL refuses to DROP a column that a trigger body still references". MySQL 8 allows the drop; the next INSERT, UPDATE or DELETE on `cars` then fails with 1054. The restore-first order is still right. Correct the reason in the comment (found in #2167)

### `error/500.php`

- [ ] Restore a unit pin: all nine status codes (400/401/403/404/405/408/500/502/504) have entries in both `$errorMessages` and `$logCategoryMap` (from #2169)

### `package.json` + `scripts/build.js` + `app/assets/js/car-edit.js` (−1 dependency)

- [ ] Drop `filepond-plugin-image-exif-orientation`: the package, its `<script>` tag, its `registerPlugin()` argument (car-edit.js ~29), and its copy line (build.js ~47). Current browsers apply EXIF orientation natively. **First upload one rotated phone JPEG with the plugin removed, and confirm that the stored image and the resized variants are upright.** (found in the #2208 audit)

### `scripts/spike-1871/` (est. −960)

- [ ] Delete the directory (capture receiver, send-test CLI, README). Its README says it stays "until #1887 ships its own fixtures". #1887 is closed, and the Brevo payload fixtures now live in `BrevoWebhookEventProcessorTest.php` and `EmailEventApplierTest.php`. `scripts/spike-1888/brevo-webhook-capture.php` covers the same capture job. (found in the #2208 audit)
- [ ] Update the two pointers in `docs/development/EMAIL_SYSTEM.md` (~L1468, ~L1536). (found in the #2208 audit)

### `tests/Support/BrevoOverrideStub.php` + `tests/Support/BrevoSiteRoot.php` (test-only)

- [ ] `BrevoOverrideStub` writes `"<?php\n"` and `sweep()` deletes any file with that exact content. Add a unique marker comment to the stub content (found in /finish-milestone v2.30.7)
- [ ] `BrevoSiteRoot::PLUGIN_FILES` keeps `override.RENAME.php` only for the #2374 regression test (`VerificationSettingsTest.php` ~L656). Add a comment that says so (found in /finish-milestone v2.30.7)

### `tests/Support/SendinblueMessagesTable.php` (test-only)

- [ ] The seven integration classes that use `createIfMissing()` drop `plg_sendinblue_messages` in `tearDownAfterClass()` and do not check `->error()`. A failed drop leaves a test-made table, and a later class then gets `false` from `createIfMissing()` and treats it as a plugin table it must not drop. Add a static `drop()` that throws on a DB error, and use it in the seven classes (found in #2420)

### `tests/integration/AdminClearBlockAndOptOutTest.php` (test coverage)

- [ ] Only the `CLEARED` outcome runs an import afterwards. Add one after a shared-address (and moved-address) Clear Block: Brevo's entry with its original `blockedAt` and with none; the flag stays clear and the other account does not change (found in /finish-milestone v2.30.9)

### `tests/integration/AdminDeliverabilityActionsAuthTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy. `invokeEndpoint()` needs GET/HTML or session-flash support first (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L161), and it ignores a failed `$loggedInUser->find()` (~L142), so an unknown user runs logged out. The class docblock (~L15) names `VerificationToggleEndpointBehaviorTest` as the model runner, but that class now uses `invokeEndpoint()`. The migration item above fixes all three. (found in #2165)

### `tests/integration/BrevoWebhookEndpointTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L1012, renames `er_email_events.reason`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

### `tests/integration/CarEditOwnerColumnRefreshTest.php`

- [ ] `testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot` only checks that the new `owner_last_updated` is later than a date two years ago, and does not count `cars_hist` rows. Bound it with `assertEqualsWithDelta(time(), ..., 60)` and assert one new history row (found while working on #1929)

### `tests/integration/CronJobFailureRoundTripTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L200, drops `er_cron_job_runs.last_failure_at`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

### `tests/integration/EmailEventRekeyReportAuthTest.php` (test coverage)

- [ ] `testVisitorWhoIsNotLoggedInIsRefusedAndGetsNoReport` checks only that no report shows. A crash passes too. Assert exit code 0 and HTTP 302, as the editor test does (found in #2358 review)

### `tests/integration/OwnerCreateUpdateTransactionRollbackTest.php`

- [ ] Replace the hand-written DB proxy (~L55) with `PassThroughDatabase` (from #2169)

### `tests/integration/UserCreationHookAutoLoginTest.php`

- [ ] `setUp()` does not check the `us_ip_list` SELECT, and `tearDown()` does not check the re-insert. A failure deletes the developer's IP row with no error. Assert both results (found in #2378)
- [ ] No test covers the `$readError` suffix in `usersc/scripts/during_user_creation.php` when the `users` SELECT itself fails. Force the failure with `PassThroughDatabase` and assert the error text in the log row (found in #2378)

### `tests/integration/UserDeletionPrivacyStepTest.php` + `tests/integration/admin/RerunDeletionPrivacyStepScriptTest.php`

- [ ] `uniqueAddress`, `insertPluginRow`, `pluginRowExists`, `profileCount`, `deleteUsersRowOnly` and the holding-row reader are near-copies in both files (about 60 lines). Move them to a shared trait in `tests/Support/` (found in #2419)

### `tests/integration/UserDeletionReassignmentTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L380, renames `car_transfer_requests.admin_notes`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

### `tests/integration/UserSettingsResumeVerificationEndpointTest.php`

- [ ] The class has its own `php -r` subprocess copy of the endpoint runner. Use `IntegrationTestCase::invokeEndpoint()` and delete the copy. `invokeEndpoint()` needs GET/HTML or session-flash support first (found in #2165)
- [ ] The runner reads a missing `___EXIT:` marker as exit code 0 (`array_pad(..., '0')`, ~L196). The migration item above fixes it. (found in #2165)

### `tests/integration/VerificationToggleEndpointBehaviorTest.php`

- [ ] The class does not delete the `logs` rows that its endpoint calls write (`AccessDenied`, `user_id = 0`, and `VerificationConfigChanged` rows), so they build up on every run. Use the `logIdFloor` and per-user `DELETE` pattern from `AdminAjaxGuardBehaviorTest` (found in #2165)

### `tests/integration/cars/services/CarRepositoryProfileFlagReadersTest.php`

- [ ] `testRepositorySqlHasNoMaxOverTheProfileFlagColumns` (~L151) and `testCarDetailsPageLoadsTheOwnerProfileStateForTheCard` (~L392) read source text with `file_get_contents()`. Replace them with behavior tests (found in #2330)

### `tests/integration/database/AddCronJobRunsLastFailureAtMigrationTest.php`

- [ ] The class has its own copy of `phinxAdapter()` and `pdoForPhinx()`. Move it onto `MigrationSandboxTestCase` (from #2167), which binds the migration to its own schema, and delete the copy (found in #2167)

### `tests/integration/database/MigrationSandbox.php` + `tests/integration/database/MigrationSandboxTest.php` (test coverage)

- [ ] No self-test covers the `migrateTo()` unknown-version pre-check (~L190) or the `phinxlog` post-check (~L209), the non-PDO guard in `connect()` (~L455), or the drop-on-failure path in `MigrationSandboxTestCase::setUpBeforeClass()`. A later edit can remove a guard and the suite stays green (found in #2167)
- [ ] `testFingerprintDetectsAColumnChange` changes a column only. Add a `DROP TRIGGER` case, so a broken `TRIGGERS` query in `fingerprint()` fails a test (found in #2167)
- [ ] When the cleanup drop also fails, `create()` (~L132) and `MigrationSandboxTestCase::setUpBeforeClass()` keep the drop error as message text only, not as an exception. The `setUpBeforeClass()` message does not name the leaked schema itself (found in #2167)
- [ ] `getMigrations('default')` (~L190) runs outside the `try`, so a Phinx error there has no sandbox or version context (found in #2167)
- [ ] `migrateTo(0)` is refused, although Phinx reads 0 as "roll back all": document it in `@throws` (found in #2167)
- [ ] Only 4 migrations have a sandbox test, so about 28 reversible `down()` methods never run in a test. Add one sandbox class that rolls back through `Manager::rollback()` to `20260709000000` (its `down()` is irreversible), then migrates to latest again, and compares `fingerprint()` at each end. The 5 `change()`-only migrations also never run in reverse, and this test covers them too. `fingerprint()` hashes columns and triggers only, so it cannot see the engine, charset and index changes of `20260710181528`, `20260710200000` and `20260913205636` (found in #2167)

### `tests/playwright/car-edit-text-save.spec.js` (test-only, no estimate)

- [ ] The `action=fetchImages`/`action=removeImages` string match used to filter `save.php` route interception (5 call sites, e.g. ~line 985) checks for the literal substring `action=fetchImages` in the raw POST body. The real body is `multipart/form-data`, where the field looks like `name="action"\r\n\r\nfetchImages`, so the substring check can never match. Each site is safe today only because of request-timing luck (the matched call runs before the route is registered, or no other `save.php` call happens in that window) — found during #2295's review. Switch each to `postData.includes('fetchImages')` / `includes('removeImages')` (or parse the multipart `action` field) so the filter is correct on its own, not by accident of timing. (found in #2295)

### `tests/playwright/local/fixtures/` + `tests/integration/database/EmailEventRekeyMigrationFixture.php`

- [ ] Seven `seed-*.php` fixtures and the migration fixture each copy the `er_deleted_user_addresses` cleanup: a table-exists check, then a `DELETE ... IN (...)`. Three also copy the username-to-ID lookup. None checks `$db->error()` after the check or the delete, although the cleanup loop in the same files exits on an error. Add one shared helper (for example `Tests\Support\DeletedUserAddressCleanup::forUserIds($db, $ids)`) that checks `error()`, and call it from each fixture (found in #2419)

### `tests/unit/admin/VerificationBannerReasonTest.php` (test coverage)

- [ ] The "never echo unescaped" regexes miss `<?= htmlspecialchars($y) . $verificationBrevoNotReadyReason ?>` and `printf(...)`, and `[^;]*` can match across lines into comment text. Count the variable's uses in `app/admin/index.php` and assert each one is known. (found in /finish-milestone v2.30.6)

### `tests/unit/cars/services/AdminVerificationQueueUrlStateTest.php` (test quality)

- [ ] The test reads `app/admin/index.php` source with `file_get_contents` and slices a block out of it. Replace it with a behavior test of the queue URL state, for example through `IntegrationTestCase::invokeEndpoint()` (found in #2330)

### `tests/unit/cars/services/CarRepositoryTest.php` (test coverage)

- [ ] No case has `user_email => null` (orphan profile). Assert that a stored block or bounce with an address returns 0 and is written, and one with an empty address returns 1 (found in /finish-milestone v2.30.7)
- [ ] Only the block writer has the "stored flag applies, new flag for another address" case (~L1144). Add the bounce version: return 1 and a write (found in /finish-milestone v2.30.7)
- [ ] No case covers a repeat for the same old address that is not `users.email`. Assert a return of 1 and no write, so a later change of that early return cannot log a 0 to 1 change that did not happen (found in /finish-milestone v2.30.7)

### `tests/unit/classes/Email/BrevoDevOverrideTest.php` (test coverage)

- [ ] `BrevoDevOverrideTest.php:198`: the fixture SDK keeps its host by design, so "survives setApiKey" cannot fail there; only the real-SDK test at :212 proves it, and it skips where the plugin vendor dir is missing (CI). (found in /finish-milestone v2.30.6)

### `tests/unit/classes/EmailEventRekeyQueriesFailureTest.php` (test coverage)

- [ ] Only the `DatabaseInterface` branch is tested. Add the `PDO` branch: `prepare()` returns false, `execute()` returns false, and a `PDOException` comes back as the previous exception of `OwnerDatabaseException` (found in #2358 review)

### `tests/unit/cron/BrevoEventReconciliationClientTest.php` (test coverage)

- [ ] No test reaches the poll catch of `BrevoEventReconciliationClient::fetchEvents()` with an address in the exception message. Add a protected `createApi()` seam, as in `BrevoSuppressionSyncClient`, and a test that shows no log row holds the address (found in #2420)

### `tests/unit/cron/SendVerificationBatchJobTest.php` (test coverage)

- [ ] The #2363 clamp test calls `runNow()`, which never pings. Add a `run()` test with stored `batch_size = 255`: one success ping, one `VERIFICATION_CONFIG_WARNING`, `findVerificationEligible(25, 0)` (found in /finish-milestone v2.30.9)

### `tests/unit/cron/VerificationSwitchGateSourceTest.php`

- [ ] The source check that the webhook does not read the switch (~L101) repeats behavior tests (`BrevoWebhookEndpointTest` ~L351, `BrevoEventPathParityIntegrationTest` ~L556, ~L605, ~L621). Delete it. Keep the convention scans at ~L63 and ~L110 (found in /finish-milestone v2.30.8)

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

### `usersc/classes/AccountDeletionPrivacyStep.php`

- [ ] The CRITICAL line for a failed stage, its rollback-failure part, and the rerun script's catch log only the class and `(code 0)`, so a lock wait and a schema fault look the same. Use `ExceptionLogSummary::of()` (class, code, SQLSTATE, throw site, no message). Put `$db->errorString()` in the profile-delete `OwnerDatabaseException` message, and update the pinned `(code 0)` assertions (found in #2419)

### `usersc/classes/ApiResponse.php`

- [ ] In `buildAndEmitHeaders()`, the `JsonException` fallback keeps the status code that was set before `json_encode()`, so a `success()` response can send HTTP 200 with a `success:false` body. Set 500 in the fallback when headers are not sent (from #1793)

### `usersc/classes/Car/CarRepository.php`

- [ ] `recentFlagChanges()` (~L2327) has no production caller, but tests in 15 unit and integration files read flag-change rows through it. Delete it only after those tests use a test helper, or let #2357 add it with its first caller (found in /finish-milestone v2.30.7)
- [ ] The block-cause names are four loose string sets: `BLOCKED_CAUSES`, `EmailEventApplier::SUPPRESSION_EVENT_CAUSES`, the bare `'spam'` and `'unsubscribed'` matches in `VerificationTabStatus::chip()`, and `EffectiveEmailFlags::$blockedCause` (`?string`). Add `enum BlockedCause: string` and convert to a string only at the database boundary (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/CarVerificationEmailComposer.php`

- [ ] Photo FileError logs record user 0. The composer has no actor id, so an admin-started send is not attributed (found while working on #1894)

### `usersc/classes/Car/CarVerificationManager.php`

- [ ] No test makes `logger()` throw on the Clear Block "unblock done" line. Add a case in `tests/unit/cars/services/CarVerificationManagerTest.php`: the registry write still runs, the outcome is `CLEARED`, and `error_log` gets the summary (found in /finish-milestone v2.30.9)

### `usersc/classes/Car/ClearBlockOutcome.php` + `usersc/classes/Email/BrevoUnblockResult.php`

- [ ] Make the closed kind/outcome/reason sets backed enums, so the `app/admin/index.php` map becomes an exhaustive `match`, `isCleared()` lives on the enum, and `BrevoUnblockResult::failed()` cannot take an unknown reason (found in /finish-milestone v2.30.9)
- [ ] `BrevoUnblockResult::failed()`: reject an `httpStatus` with a reason other than HTTP (and null with HTTP); `notBlocked()` should not carry 404 (found in /finish-milestone v2.30.9)
- [ ] Pick one value-object style for both types: `final readonly` + public properties, or private properties + accessors (found in /finish-milestone v2.30.9)

### `usersc/classes/Car/EmailEventMatch.php`

- [ ] Make the class `final readonly`. The accessors return `?? 0` or `?? ''` after `requireMatched()`, which hides a broken invariant. Return the typed value with a PHPStan assert instead (found in /finish-milestone v2.30.8)

### `usersc/classes/Car/EmailNoticeBuilder.php`

- [ ] An `email_blocked_cause` value that is not in `BLOCKED_CAUSES` (~L77) becomes null with no log line. The owner sees the generic text and the bad value stays hidden. Log it (found in /finish-milestone v2.30.7)

### `usersc/classes/Car/SendPolicy.php`

- [ ] The flag list is written three times: `SendDecision::FLAG_ORDER`, the `$all` list in `blockingFlags()`, and the table docblock. Build it once, or use a `SendFlag` enum (found in /finish-milestone v2.30.8)
- [ ] `adminMessage()` has no sentence for `email_blocked`, which is right only for `MailKind::AdminContact`. Take the `MailKind`, or rename it `adminContactMessage()` (found in /finish-milestone v2.30.8)
- [ ] A malformed or zero-date `email_bounced_at` makes `toDate()` (~L204) return null with no log line. The send is still refused, but the admin message has no date and the bad value stays hidden. Log it with the owner ID (the decision needs to carry it) (found in #2360)

### `usersc/classes/Car/SendResult.php`

- [ ] Replace the raw `string $status` with a backed enum, so callers cannot compare raw strings. `VerificationBatchSender` does `$sendResult->status === SendResult::STATUS_SENT` today. Update it in the same change (from #2149)

### `usersc/classes/Cron/HealthcheckPinger.php` + `usersc/classes/Cron/CurlHealthcheckTransport.php`

- [ ] `HealthcheckPinger`: read the two env values in the constructor (or a config object), so tests need no `putenv` (found in /finish-milestone v2.30.9)
- [ ] `CurlHealthcheckTransport`: set `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS` and an explicit `CURLOPT_FOLLOWLOCATION => false` (defense in depth; the base URL is constant) (found in /finish-milestone v2.30.9)

### `usersc/classes/Cron/SuppressionSyncSummary.php`

- [ ] Reject negative counts in the constructor, and check `matched + unmatched + alreadyFlagged + skipped <= contactsExamined` when `contactsExamined > 0`; call it with named arguments (found in /finish-milestone v2.30.9)

### `usersc/classes/Email/BrevoApiFactory.php`

- [ ] The `reason` key of `readApiKey()` and `loadSdk()` never varies within each method. Use `cause !== null` as the failure test, define `no_api_key` and `no_sdk` only in `BrevoUnblockResult`, and drop the `REASON_*` aliases. Do it together with moving `BrevoEventReconciliationClient` onto the factory (found in #2426)
- [ ] Replace the `array{reason, cause, detail}` returns of `readApiKey()` and `loadSdk()` with two small readonly result types (or PHPStan shape aliases) (found in /finish-milestone v2.30.9)

### `usersc/classes/Email/BrevoBlocklistClient.php`

- [ ] Inject `BrevoApiFactory` (constructor default) instead of two inline `new BrevoApiFactory($this->db)`; the protected `createApi()` seam can then go (found in /finish-milestone v2.30.9)

### `usersc/classes/Exceptions/ProfileFlagValueException.php`

- [ ] Mark the class `final`. It extends `CarDatabaseException` and nothing extends it (found in /finish-milestone v2.30.8)

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

### `usersc/classes/Owner.php` + `usersc/user_settings.php` + `app/verify/verify_car.php` + `usersc/classes/Car/CarVerificationManager.php` + `usersc/classes/Car/EmailEventApplier.php`

- [ ] The `try { logger() } catch { error_log('[ElanRegistry] ...') }` guard is copied at about 8 sites, the rollback guards included. Add one helper in `usersc/classes/` (for example `SafeLog::write($userId, $category, $message)`) and use it at each site (found in #2393)

### `usersc/classes/OwnerView.php` + `app/admin/includes/tab-owner_mgmt.php` (est. −11)

- [ ] `displayQualityBadge()` (~55-66) has only test callers. Meanwhile `tab-owner_mgmt.php:418` builds the same badge by hand and prints `$owner->quality_score` without a cast or escaping. That is low risk, because the value is a numeric SQL result. Call `displayQualityBadge()` from line 418 and delete the inline copy. This removes the duplicate and puts the escaping in one place. (found in the #2208 audit)

### `usersc/classes/Reference/CarModel.php` + `app/api/cars/models.php` (est. −158)

- [ ] Delete `getBySeries()`, `byValue()`, `getSeriesInYear()` (~67-175). Only tests call them (`CarModelTest.php`, `CarModelFailureTest.php`). (found in the #2208 audit)
- [ ] Delete the `getModelsByYear` action in `models.php` (~49-71) and `CarModel::getAvailableInYear()` (~40-65). `ModelLoader` only posts `getAllYearModels`, and nothing sends `getModelsByYear`. (found in the #2208 audit)

### `usersc/classes/RegistrationRecoveryNotifier.php`

- [ ] Replace 2 inline header-injection `preg_replace` calls with `InputSanitizer::stripHeaderInjectionChars()` (from #1786)

### `usersc/classes/Transfer/CarTransferRepository.php`

- [ ] Add PHPStan `@return` object shapes to `findById`, `findPendingById`, `findPendingWithCarById`, `getPendingWithCarAndUsers`, `getTodayStatusCounts`. This removes the 52 level-7 `property.notFound` errors in `tab-car_mgmt.php` (from #1532)

### `usersc/classes/admin/BackupManager.php` (est. −65)

- [ ] Delete `verifyBackupIntegrity()` (~481-545). Only tests call it (`BackupManagerTest.php` ~190-250). No page or cron job calls it. (found in the #2208 audit)

### `usersc/classes/admin/MaintenanceStatusLabels.php` + `app/admin/maintenance.php` (est. −28)

- [ ] Inline the two 5-line `match` expressions into `maintenance.php` (~209, ~233). They are its only caller. Then drop the class and `MaintenanceStatusLabelsTest`. (found in the #2208 audit)

### `usersc/includes/example_admin_user_system_settings_post.php`

- [ ] line 25 — same `(Issue #237)` pattern (from #1978)
- [ ] line 34 — restates the line below it (from #1978)

### `usersc/includes/rate_limits_dev_override.php`

- [ ] Rename to a general name such as `dev_overrides.php` and update its includes. The file also applies the Brevo SDK host override (#2184), so its name no longer describes its scope. (found in #2385)

### `usersc/login.php` + `tests/bootstrap-integration.php`

- [ ] Remove the pre-6.1.7 version guards after production runs UserSpice 6.1.7 (v2.30.9, #2088): `method_exists($loginUser, 'recordLogin')` in `$recordCompletedLogin` (call `$loginUser->recordLogin($userId)` directly), `function_exists('reauthClearConfirmed')` in `$clearReauthConfirmation`, and `method_exists($totpHandler, 'encryptionAvailable')`. Also remove the `_instance` reflection fallback in `tests/bootstrap-integration.php`, and update the guard assertions in `tests/unit/security/LoginWiringTest.php`. (found in #2253)

### `usersc/scripts/during_user_creation.php`

- [ ] A failed automatic sign-in after sign-up (~L87) redirects with a message but writes no log row. Log it with the user id before `Redirect::to()` (found in #2378)
- [ ] The `email_act` read (~L81) is not checked. A failed read returns `[]`, so `$e->email_act` warns, `null != 1` is true, and the hook tries the automatic sign-in. Check the read, log `LOG_CATEGORY_DATABASE_ERROR`, and skip the sign-in (found in #2378)
