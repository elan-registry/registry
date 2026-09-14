<?php

declare(strict_types=1);

require_once __DIR__ . '/transfer/TransferIntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end test for the user deletion → car reassignment flow.
 *
 * Verifies that after_user_deletion.php correctly reassigns cars.user_id to
 * the noowner account when a user is deleted, without relying on any FK
 * constraint to do the work; that it deletes the user's profile row and
 * expires their pending transfer requests in the same transaction; and that
 * it logs the reassignment (one completion summary plus one entry per car).
 *
 * This test closes the gap that allowed the #1279 race condition to reach
 * production: the previous FK test exercised the constraint in isolation
 * (bypassing the hook), so the hook was never tested end-to-end.
 *
 * Extends TransferIntegrationTestCase (not IntegrationTestCase directly) solely
 * to reuse its createTransferRequest() fixture helper for the pending-transfer-
 * expiry assertions below — this file is otherwise unrelated to transfer tests.
 */
#[Group('integration')]
final class UserDeletionReassignmentTest extends TransferIntegrationTestCase
{
    /**
     * @var int[] user_ids of profile rows this test inserted directly (bypassing
     *     IntegrationTestCase's fixtures, which don't track profiles). The hook is
     *     expected to delete these itself, but a failed assertion or an early-return
     *     in the hook (e.g. no session, a DB error on the noowner lookup, or a
     *     transaction rollback — the no-noowner fallback branch still deletes the
     *     profile, so that alone isn't a leak scenario) would otherwise leak the row
     *     into the persistent integration schema forever.
     */
    private array $createdProfileUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // after_user_deletion.php requires an authenticated session (currentUserId()
        // throws RuntimeException otherwise) — see the ASSUMPTION comment in
        // after_user_deletion.php for which production callers provide one. The hook
        // itself only checks for a session, not admin permission, so this fixture is
        // an authenticated (non-admin) test user, not an actual admin. Without it, the
        // hook aborts before reassigning cars and the final assertion below fails with
        // a confusing "wrong ID" message instead of a clear "no session" error.
        $actingUserId = $this->createTestUser();
        $this->loginAsTestUser($actingUserId);
    }

    protected function tearDown(): void
    {
        try {
            // Belt-and-suspenders: the hook is expected to have already deleted these
            // (that's what the test asserts), so this is normally a 0-row no-op. It only
            // matters if an assertion failed partway through and left a row behind.
            foreach ($this->createdProfileUserIds as $userId) {
                // DB::query() never throws on execute-time failure — check error() explicitly
                // so a failed cleanup DELETE doesn't silently leave a row in the test schema.
                $result = $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
                if ($result->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for profile user_id {$userId}: {$result->errorString()}\n");
                }
            }
        } finally {
            // Run even if the profile cleanup above throws, so the base class's own
            // fixture cleanup — and its restoreGlobalUser() call — is never skipped.
            parent::tearDown();
        }
    }

    /**
     * Full hook path: delete user → require after_user_deletion.php → cars go to noowner,
     * profile is deleted, pending transfer requests expire.
     *
     * Arrange: create a test user with two cars (proves the hook's per-car reassignment
     *          loop, not just a single-iteration pass), a profile row, and a pending
     *          transfer request they initiated. Also create an unrelated second user's
     *          pending transfer request, to prove the hook's expiry UPDATE is scoped to
     *          the deleted user and doesn't over-match.
     * Act:     delete the user row (as deleteUsers() would), then run the hook.
     * Assert:  cars.user_id equals the noowner ID for both cars (never NULL); the profile
     *          row is gone; the pending transfer request is expired; the other user's
     *          transfer request is untouched; the hook logs one UserDeletion completion
     *          summary and one CarActions entry per car.
     */
    public function test_afterUserDeletionHook_cleansUpUserDataAndLogs(): void
    {
        $noOwnerRow = $this->db->query("SELECT id, fname, lname FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');
        $noOwnerId = (int) $noOwnerRow->id;
        $noOwnerFname = (string) $noOwnerRow->fname;
        $noOwnerLname = (string) $noOwnerRow->lname;

        $userId = $this->createTestUser();
        $carIds = [$this->createTestCar($userId), $this->createTestCar($userId)];

        // Seed synthetic PII directly onto both cars. createTestCar() leaves these
        // columns NULL, and NULL already satisfies the post-hook assertNull(lat)/
        // assertNull(lon) checks below with nothing having changed — without this
        // seed, a regression that left the scrub logic out entirely would still pass.
        // fname/lname use values clearly distinct from noowner's ('No'/'Owner') so the
        // post-hook assertion that they become noowner's name actually proves a change
        // occurred, not just that both happened to already match.
        foreach ($carIds as $carId) {
            $seeded = $this->db->update('cars', $carId, [
                'email'   => 'colin.chapman@example.com',
                'fname'   => 'Colin',
                'lname'   => 'Chapman',
                'city'    => 'Hethel',
                'state'   => 'Norfolk',
                'country' => 'United Kingdom',
                'lat'     => 52.4567,
                'lon'     => 1.0234,
                'website' => 'https://example.com/colin',
            ]);
            $this->assertTrue((bool) $seeded, "Test fixture: PII seed update on car $carId must succeed");
        }

        $profileInserted = $this->db->insert('profiles', [
            'user_id' => $userId,
            'bio'     => 'Integration test fixture profile',
            'city'    => 'Hethel',
            'state'   => 'Norfolk',
            'country' => 'United Kingdom',
        ]);
        $this->assertTrue((bool) $profileInserted, 'Test fixture: profiles insert must succeed');
        $this->createdProfileUserIds[] = $userId;

        // Transfer request the deleted user initiated for someone else's car — the hook
        // must expire it (not leave it pointing at a now-deleted requester). existing_car_id
        // has no FK to cars.id (see #1547), so any car ID would satisfy the schema; this
        // test reuses one of its own tracked cars for readability. Cleanup is automatic:
        // createTransferRequest() (inherited from TransferIntegrationTestCase) tracks the
        // returned ID and deletes it directly by ID in tearDown().
        $transferRequestId = $this->createTransferRequest($carIds[0], $userId, [
            'status'            => 'pending',
            'submitted_chassis' => 'T' . substr(uniqid(), -10),
        ]);

        // An unrelated user's pending transfer request — must survive the hook untouched.
        // Without this, a regression that widened the hook's WHERE clause (e.g. dropping
        // the requested_by_user_id filter) would silently expire every pending request in
        // the schema and nothing here would catch it.
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId);
        $otherTransferRequestId = $this->createTransferRequest($otherCarId, $otherUserId, [
            'status'            => 'pending',
            'submitted_chassis' => 'T' . substr(uniqid(), -10),
        ]);

        // Simulate deleteUsers() having removed the user row.
        // With fk_cars_user_id gone, this does NOT touch cars.user_id.
        $this->db->delete('users', ['id', '=', $userId]);
        // Leave $userId in createdUserIds — tearDown's redundant DELETE is a 0-row no-op.

        // Pre-condition: cars still carry the deleted user's ID (no FK to NULL them).
        foreach ($carIds as $carId) {
            $before = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertSame($userId, (int) $before->user_id, "Pre-condition: car $carId's user_id intact after user deleted (no FK)");
        }

        // Pre-condition: the seeded PII actually landed on the cars before the hook
        // runs — a silently-failed seed would otherwise make the post-hook assertions
        // vacuous again (NULL/blank columns "passing" the scrub check without the hook
        // having changed anything).
        foreach ($carIds as $carId) {
            $seededFields = $this->carOwnerIdentityFields($carId);
            $this->assertSame('colin.chapman@example.com', $seededFields->email, "Pre-condition: car $carId's seeded email present before hook runs");
            $this->assertSame('Colin', $seededFields->fname, "Pre-condition: car $carId's seeded fname present before hook runs");
            $this->assertSame('Chapman', $seededFields->lname, "Pre-condition: car $carId's seeded lname present before hook runs");
            $this->assertSame('Hethel', $seededFields->city, "Pre-condition: car $carId's seeded city present before hook runs");
            $this->assertSame('Norfolk', $seededFields->state, "Pre-condition: car $carId's seeded state present before hook runs");
            $this->assertSame('United Kingdom', $seededFields->country, "Pre-condition: car $carId's seeded country present before hook runs");
            // cars.lat/lon are MySQL FLOAT (32-bit); 52.4567/1.0234 aren't exactly
            // representable in float32, so an exact assertSame here looks like a
            // precision hazard. Verified directly against this project's MySQL/PDO
            // stack (round-trip insert + SELECT) that both values compare identical —
            // MySQL emits the shortest decimal that round-trips the stored float32,
            // and PHP's (float) cast lands on the same bit pattern as the literal.
            $this->assertSame(52.4567, (float) $seededFields->lat, "Pre-condition: car $carId's seeded lat present before hook runs");
            $this->assertSame(1.0234, (float) $seededFields->lon, "Pre-condition: car $carId's seeded lon present before hook runs");
            $this->assertSame('https://example.com/colin', $seededFields->website, "Pre-condition: car $carId's seeded website present before hook runs");
        }

        // Pre-condition: profile and both pending transfer requests still exist.
        $this->assertSame(1, $this->profileRowCount($userId), 'Pre-condition: profile row exists before hook runs');
        $this->assertSame(
            'pending',
            $this->transferRequestStatus($transferRequestId),
            'Pre-condition: transfer request still pending before hook runs'
        );
        $this->assertSame(
            'pending',
            $this->transferRequestStatus($otherTransferRequestId),
            'Pre-condition: other user\'s transfer request still pending before hook runs'
        );

        // logs is never truncated between runs against the persistent integration schema
        // (tearDown() only deletes cars, cars_hist, and car_transfer_requests rows tied to
        // those cars, plus users — never logs). The completion-summary message is
        // identical across runs (it carries only the noowner ID and car count, both
        // stable), so an absolute assertSame(1, ...) would only pass on a freshly
        // provisioned schema and fail on every rerun. The per-car messages embed
        // fresh auto-increment IDs and are usually unique per run, but IDs can repeat
        // across runs if the schema's AUTO_INCREMENT is ever reset — before/after
        // deltas make both assertions robust to that regardless.
        $completionLogMessage = sprintf(
            'Complete cleanup: reassigned %d cars to noowner user (ID: %d)',
            count($carIds),
            $noOwnerId
        );
        $perCarLogMessages = array_map(
            fn (int $carId): string => sprintf(
                'User deletion: car ID %d reassigned from user %d to noowner (ID: %d)',
                $carId,
                $userId,
                $noOwnerId
            ),
            $carIds
        );
        $completionLogBefore = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_USER_DELETION,
            $completionLogMessage
        );
        $perCarLogsBefore = array_map(
            fn (string $message): int => $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_ACTIONS, $message),
            $perCarLogMessages
        );

        // Run the hook exactly as deleteUsers() does: inject $id and $db, then require.
        $id = $userId;
        $db = $this->db;
        require TESTING_ROOT . '/usersc/scripts/after_user_deletion.php';

        // Assert: both cars are now owned by noowner, not the deleted user and not NULL.
        foreach ($carIds as $carId) {
            $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertNotNull($after->user_id, "cars.user_id must not be NULL after hook runs (car $carId)");
            $this->assertSame($noOwnerId, (int) $after->user_id, "cars.user_id must equal noowner ID after hook (car $carId)");
        }

        // Assert: the deleted owner's PII no longer appears on cars/cars_hist — proves
        // the real transfer() → updateCar()/insertHistory() path actually persists the
        // scrub (GDPR erasure), not just that it was asked to. email/city/state/country
        // blank to '' (noowner has no profile row and an unroutable email); fname/lname
        // take on noowner's own account name (a legible placeholder identity, not the
        // deleted user's — asserted against a live lookup, not a hardcoded string, so
        // this stays correct if the noowner fixture's name ever changes); website is
        // nulled (not blanked to '') by CarValidator's dedicated website-clearing case;
        // lat/lon are null (no profile row to source coordinates from).
        foreach ($carIds as $carId) {
            $carFields = $this->carOwnerIdentityFields($carId);
            foreach (['email', 'city', 'state', 'country'] as $field) {
                $this->assertSame('', $carFields->$field, "cars.$field should be blanked for car $carId");
            }
            $this->assertSame($noOwnerFname, $carFields->fname, "cars.fname should equal the noowner account's own name for car $carId");
            $this->assertSame($noOwnerLname, $carFields->lname, "cars.lname should equal the noowner account's own name for car $carId");
            $this->assertNull($carFields->website, "cars.website should be null for car $carId");
            $this->assertNull($carFields->lat, "cars.lat should be null for car $carId");
            $this->assertNull($carFields->lon, "cars.lon should be null for car $carId");

            // cars_hist is an append-only audit trail (DATABASE.md: "Car audit trail").
            // insertHistory() — the path used by transfer(), and thus by this deletion
            // hook — only ever inserts, never updates existing rows' content columns.
            // (CarRepository::transferHistory() does UPDATE cars_hist, but only to
            // rewrite the car_id FK during a car merge; it never touches PII/content
            // columns and is not part of the deletion-reassignment path exercised here.)
            // This block proves the audit row THIS transfer just created correctly
            // reflects the target owner's (noowner's) identity, not the deleted user's
            // — it does NOT and cannot prove anything about historic cars_hist rows
            // written before this deletion, since prior rows' content is intentionally
            // never retroactively modified.
            //
            // cars_hist.website stays '' (not null): insertHistory()'s history-field build
            // doesn't go through CarValidator's CLEARABLE_FIELDS pass that nulls
            // $updateFields['website'] on the cars table — see CarAdministrationService.php.
            $histFields = $this->carsHistOwnerIdentityFields($carId);
            foreach (['email', 'city', 'state', 'country', 'website'] as $field) {
                $this->assertSame('', $histFields->$field, "cars_hist.$field should be blanked for car $carId");
            }
            $this->assertSame($noOwnerFname, $histFields->fname, "cars_hist.fname should equal the noowner account's own name for car $carId");
            $this->assertSame($noOwnerLname, $histFields->lname, "cars_hist.lname should equal the noowner account's own name for car $carId");
            $this->assertNull($histFields->lat, "cars_hist.lat should be null for car $carId");
            $this->assertNull($histFields->lon, "cars_hist.lon should be null for car $carId");
        }

        // Assert: the profile row was deleted (GDPR erasure).
        $this->assertSame(0, $this->profileRowCount($userId), 'profiles row must be deleted after hook runs');

        // Assert: the pending transfer request was expired, not left pointing at a
        // deleted requester.
        $transferAfter = $this->db->query(
            "SELECT status, completed_date, admin_notes FROM car_transfer_requests WHERE id = ?",
            [$transferRequestId]
        )->first();
        $this->assertSame('expired', $transferAfter->status, 'Pending transfer request must be expired after hook runs');
        $this->assertNotNull($transferAfter->completed_date, 'Expired transfer request must have a completed_date set');
        $this->assertStringContainsString(
            'Account deleted',
            (string) $transferAfter->admin_notes,
            'Expired transfer request must note the account deletion'
        );

        // Assert: the expiry UPDATE is scoped to the deleted user — an unrelated
        // pending request must survive untouched.
        $this->assertSame(
            'pending',
            $this->transferRequestStatus($otherTransferRequestId),
            "Other user's unrelated transfer request must remain pending after hook runs"
        );

        // Assert: the hook logged the completion summary exactly once.
        $this->assertLoggedExactlyOnceSince(
            LogCategories::LOG_CATEGORY_USER_DELETION,
            $completionLogMessage,
            $completionLogBefore,
            'Hook must log exactly one UserDeletion completion summary'
        );

        // Assert: the hook logged exactly one CarActions entry per car — proves the
        // per-car loop fires for each car, not just once regardless of car count.
        foreach ($carIds as $index => $carId) {
            $this->assertLoggedExactlyOnceSince(
                LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                $perCarLogMessages[$index],
                $perCarLogsBefore[$index],
                "Hook must log exactly one CarActions entry for car $carId"
            );
        }
    }

    /**
     * Count profile rows for a user — used before/after the hook to confirm the
     * profile is deleted (GDPR erasure) rather than left behind or double-deleted.
     */
    private function profileRowCount(int $userId): int
    {
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first();

        return (int) $row->cnt;
    }

    /**
     * Fetch a car_transfer_requests row's current status by ID.
     */
    private function transferRequestStatus(int $transferRequestId): string
    {
        $row = $this->db->query(
            'SELECT status FROM car_transfer_requests WHERE id = ?',
            [$transferRequestId]
        )->first();

        return (string) $row->status;
    }

    /**
     * Fetch a car's current owner-identity PII fields by car ID.
     */
    private function carOwnerIdentityFields(int $carId): object
    {
        return $this->db->query(
            'SELECT email, fname, lname, city, state, country, lat, lon, website FROM cars WHERE id = ?',
            [$carId]
        )->first();
    }

    /**
     * Fetch the most recent NEWOWNER cars_hist row's owner-identity PII fields by car ID.
     *
     * This is always the row THIS test's transfer() call just inserted (cars_hist is
     * append-only — see the comment at its call site), so it proves the fresh audit
     * row reflects the target owner's identity, never anything about historic rows.
     */
    private function carsHistOwnerIdentityFields(int $carId): object
    {
        return $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, website FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER' ORDER BY id DESC LIMIT 1",
            [$carId]
        )->first();
    }

    /**
     * Assert a logs table match count grew by exactly one since $countBefore was
     * captured. See the before-capture comment in the test method for why deltas
     * (not absolute counts) are required against the persistent integration schema.
     *
     * $lognote is passed to countMatchingLogs() as a LIKE pattern — safe today since
     * none of this file's log messages contain '%' or '_', but a future message that
     * does would silently widen the match instead of failing loudly.
     */
    private function assertLoggedExactlyOnceSince(string $logtype, string $lognote, int $countBefore, string $failureMessage): void
    {
        $this->assertSame($countBefore + 1, $this->countMatchingLogs($logtype, $lognote), $failureMessage);
    }
}
