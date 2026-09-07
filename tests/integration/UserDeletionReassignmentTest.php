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
        $noOwnerRow = $this->db->query("SELECT id FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');
        $noOwnerId = (int) $noOwnerRow->id;

        $userId = $this->createTestUser();
        $carIds = [$this->createTestCar($userId), $this->createTestCar($userId)];

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

    /**
     * Count er_email_events rows for a car — used before/after the hook runs
     * to confirm the departing owner's webhook event history is cleared
     * (#1887) as part of the same transaction as reassignment.
     */
    private function emailEventRowCount(int $carId): int
    {
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ?', [$carId])->first();
        return (int) $row->cnt;
    }

    private function insertEmailEventFixture(int $carId, string $event, string $brevoMessageId): void
    {
        $inserted = $this->db->insert('er_email_events', [
            'car_id'           => $carId,
            'email'            => 'fixture-' . uniqid() . '@example.com',
            'event'            => $event,
            'reason'           => null,
            'brevo_message_id' => $brevoMessageId,
            'occurred_at'      => date('Y-m-d H:i:s'),
        ]);
        $this->assertTrue((bool) $inserted, 'Test fixture: er_email_events insert must succeed: ' . $this->db->errorString());
    }

    /**
     * #1887: a departing owner's er_email_events rows must be cleared in the
     * same transaction as reassignment, before the car->owner link is
     * severed — via the noowner-exists branch (the primary/common path).
     */
    public function test_afterUserDeletionHook_clearsEmailEventsViaNoOwnerBranch(): void
    {
        $noOwnerRow = $this->db->query("SELECT id FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');
        $noOwnerId = (int) $noOwnerRow->id;

        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId);

        $this->insertEmailEventFixture($carId, 'hard_bounce', 'msg-' . uniqid());
        $this->insertEmailEventFixture($carId, 'delivered', 'msg-' . uniqid());

        $this->assertSame(2, $this->emailEventRowCount($carId), 'Pre-condition: two er_email_events rows exist for the car');

        $this->db->delete('users', ['id', '=', $userId]);

        $id = $userId;
        $db = $this->db;
        require TESTING_ROOT . '/usersc/scripts/after_user_deletion.php';

        $this->assertSame(
            0,
            $this->emailEventRowCount($carId),
            'er_email_events rows for the departing owner\'s car must be deleted by the noowner-exists branch'
        );

        $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
        $this->assertSame($noOwnerId, (int) $after->user_id, 'Car must still be reassigned (not deleted) after cleanup');
    }

    /**
     * Same scenario, forced down the fallback branch (no `noowner` user
     * present) — this is the bug the plan fixes: previously the fallback
     * branch never fetched a car list at all before calling
     * reassignCarsByUser(), so er_email_events cleanup silently never
     * happened on this path. Forces the branch by temporarily renaming the
     * noowner account's username so the hook's own lookup finds nothing,
     * restoring it in a finally block so no other test ever observes a
     * missing noowner account.
     */
    public function test_afterUserDeletionHook_clearsEmailEventsViaFallbackBranch(): void
    {
        $noOwnerRow = $this->db->query("SELECT id, username FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');
        $noOwnerId = (int) $noOwnerRow->id;

        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId);

        $this->insertEmailEventFixture($carId, 'spam', 'msg-' . uniqid());

        $this->assertSame(1, $this->emailEventRowCount($carId), 'Pre-condition: one er_email_events row exists for the car');

        $renamed = $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['noowner_hidden_for_test', $noOwnerId]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to hide noowner account: ' . $this->db->errorString());

        try {
            $this->db->delete('users', ['id', '=', $userId]);

            $id = $userId;
            $db = $this->db;
            require TESTING_ROOT . '/usersc/scripts/after_user_deletion.php';

            $this->assertSame(
                0,
                $this->emailEventRowCount($carId),
                'er_email_events rows must be deleted by the fallback branch too — this is the bug the plan fixes'
            );

            $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertNull($after->user_id, 'Fallback branch must set cars.user_id to NULL (no noowner to reassign to)');
        } finally {
            $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['noowner', $noOwnerId]);
        }
    }

    /**
     * Deletion failing mid-transaction: forces a genuine DB error inside the
     * hook's transaction (temporarily renaming
     * car_transfer_requests.admin_notes, which the hook's very first write
     * inside the transaction UPDATEs) and asserts the whole transaction rolls
     * back — er_email_events rows survive, and the car is NOT reassigned —
     * matching the file's existing all-or-nothing guarantee. Column restored
     * in a finally block.
     */
    public function test_afterUserDeletionHook_rollsBackEmailEventsOnMidTransactionFailure(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId);
        $originalUserId = $userId;

        $this->insertEmailEventFixture($carId, 'hard_bounce', 'msg-' . uniqid());
        $this->assertSame(1, $this->emailEventRowCount($carId), 'Pre-condition: one er_email_events row exists for the car');

        $this->db->query('ALTER TABLE car_transfer_requests RENAME COLUMN admin_notes TO admin_notes_renamed_for_test');
        $this->assertFalse($this->db->error(), 'Test setup: failed to force a DB error: ' . $this->db->errorString());

        try {
            $this->db->delete('users', ['id', '=', $userId]);

            $id = $userId;
            $db = $this->db;
            require TESTING_ROOT . '/usersc/scripts/after_user_deletion.php';

            $this->assertSame(
                1,
                $this->emailEventRowCount($carId),
                'er_email_events rows must survive a rolled-back transaction (all-or-nothing guarantee)'
            );

            $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertSame(
                $originalUserId,
                (int) $after->user_id,
                'Car must NOT be reassigned when the transaction rolls back'
            );
        } finally {
            $this->db->query('ALTER TABLE car_transfer_requests RENAME COLUMN admin_notes_renamed_for_test TO admin_notes');
            $this->assertFalse($this->db->error(), 'Test cleanup: failed to restore car_transfer_requests.admin_notes column');
        }
    }
}
