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

### `app/admin/includes/process-admin-contact.php`

- [ ] line 190 — same `(Issue #237)` pattern (from #1978)

### `app/admin/includes/tab-car_mgmt.php`

- [ ] Narrow 2 × `catch (Exception $e)` (~L29, 53) to the typed exceptions from `Car` and `CarTransferRepository` (from #1637)

### `app/api/cars/list.php`

- [ ] `(new CarShowcaseService())->getNewCarIds()` (~64) runs an uncached query on every DataTables request (page turn, sort, search) since #1900. Before, it ran once per page load. Memoize it per request or for a short time. Accepted as low risk for v2.30.4. (found in /finish-milestone v2.30.4)

### `app/api/cars/save.php`

- [ ] The `updateImages` and `removeImages` auth checks (about lines 233 and 244) compare `$user->data()->id != ...->user_id` with loose `!=`. Use a strict `(int)` comparison, as `removeImage()` does (found while working on #1929)

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
- [ ] Update the two pointers in `docs/development/EMAIL_SYSTEM.md` (~746, ~814). (found in the #2208 audit)

### `tests/integration/BrevoWebhookEndpointTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L901, renames `er_email_events.reason`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

### `tests/integration/BrevoWebhookEndpointTest.php` + `tests/integration/VerificationToggleEndpointBehaviorTest.php` (test coverage)

- [ ] The not-ready tests empty the API key and restore it in `finally`. A fatal error or a killed run skips `finally` and leaves `key = ''`, which the bootstrap sweep does not report (it deletes only `sib-test-key` rows). Readiness does not change, but the toggle test's original key is lost. Update the crash-recovery comment in `tests/bootstrap-integration.php` (~L430-445) or make the sweep report empty-key rows. (found in /finish-milestone v2.30.6)

### `tests/integration/CarEditOwnerColumnRefreshTest.php`

- [ ] `testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot` only checks that the new `owner_last_updated` is later than a date two years ago, and does not count `cars_hist` rows. Bound it with `assertEqualsWithDelta(time(), ..., 60)` and assert one new history row (found while working on #1929)

### `tests/integration/CronJobFailureRoundTripTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L200, drops `er_cron_job_runs.last_failure_at`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

### `tests/integration/OwnerCreateUpdateTransactionRollbackTest.php`

- [ ] Replace the hand-written DB proxy (~L55) with `PassThroughDatabase` (from #2169)

### `tests/integration/UserDeletionReassignmentTest.php`

- [ ] Fault-injection `ALTER TABLE` on the shared test schema (~L380, renames `car_transfer_requests.admin_notes`). The `finally` restores it, but a failed restore breaks the schema for every later test. Move the fault onto a throwaway schema. The #2167 sandbox harness gives a raw PDO only, so the test also needs a way to point `DB::getInstance()` at the sandbox (found in #2167)

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

### `tests/playwright/admin-verification-dashboard.spec.js` (test coverage)

- [ ] No test asserts the Brevo row of the Verification tab: "Ready" / "Not ready" badge, the `brevoNotReadyReason()` text, and the log hint that shows only when the probe threw. (found in #2184)

### `tests/unit/admin/VerificationBannerReasonTest.php` (test coverage)

- [ ] The "never echo unescaped" regexes miss `<?= htmlspecialchars($y) . $verificationBrevoNotReadyReason ?>` and `printf(...)`, and `[^;]*` can match across lines into comment text. Count the variable's uses in `app/admin/index.php` and assert each one is known. (found in /finish-milestone v2.30.6)

### `tests/unit/classes/Email/BrevoDevOverrideTest.php` (test coverage)

- [ ] `BrevoDevOverrideTest.php:198`: the fixture SDK keeps its host by design, so "survives setApiKey" cannot fail there; only the real-SDK test at :212 proves it, and it skips where the plugin vendor dir is missing (CI). (found in /finish-milestone v2.30.6)

### `tests/unit/regression/JoinFailureReportUsesDedicatedRateLimitBucketRegressionTest.php`

- [ ] Rename `testExactlyOneConfigEntryExistsPerAction`. It asserts presence, not uniqueness (from #2169)

### `tests/unit/security/AdminAjaxGuardTest.php`

- [ ] Move `significantTokens()` / `tokensCallFunction()` into one `tests/Support/` helper, shared with the sibling pins from #2161 (from #2169)
- [ ] `adminEndpointProvider()` globs only the top level of `app/admin/includes/`. Make it recursive (from #2169)

### `tools/phpstan/` (test-only, no estimate)

- [ ] No test proves the `phpstan.neon` wiring of the path-based rules. A typo in a `files:` or `exemptPages:` entry, or a different working directory for `projectRoot`, makes the rule silently check nothing. Add a smoke test that runs PHPStan with the real config on one known violation per rule. (found in #2327)
- [ ] `PageMetadataBeforeInitRule` does not report an `exemptPages` entry whose file was deleted or moved, or no longer calls `securePage()`. Report it as a stale exemption. (found in #2327)

### `usersc/classes/ApiResponse.php`

- [ ] In `buildAndEmitHeaders()`, the `JsonException` fallback keeps the status code that was set before `json_encode()`, so a `success()` response can send HTTP 200 with a `success:false` body. Set 500 in the fallback when headers are not sent (from #1793)

### `usersc/classes/Car/VerificationBatchSender.php` (test coverage)

- [ ] No test covers a batch while Brevo is not ready (plugin below 1.8.0): no email sent, every car in `failed` with the Brevo reason, no vericode rotation. Only `CarVerificationSendService::sendOne()` has that test. (found in #2184)

### `usersc/classes/Car/CarVerificationEmailComposer.php`

- [ ] Photo FileError logs record user 0. The composer has no actor id, so an admin-started send is not attributed (found while working on #1894)

### `usersc/classes/Car/SendResult.php`

- [ ] Replace the raw `string $status` with a backed enum, so callers cannot compare raw strings. `VerificationBatchSender` does `$sendResult->status === SendResult::STATUS_SENT` today. Update it in the same change (from #2149)

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
- [ ] No test runs a successful login and checks that `users.logins` goes up by exactly one (`$recordCompletedLogin` ~L107, called at ~L192, 302, 356); only the text check in `LoginWiringTest.php:129` covers the version-guarded count. (found in /finish-milestone v2.30.6)
