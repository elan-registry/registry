<?php

declare(strict_types=1);

require_once __DIR__ . '/transfer/TransferIntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1279: runs after_user_deletion.php end to end; no FK reassigns cars to noowner.
 * Extends TransferIntegrationTestCase only for createTransferRequest().
 */
#[Group('integration')]
final class UserDeletionReassignmentTest extends TransferIntegrationTestCase
{
    /**
     * @var int[] user_ids of profile rows inserted directly; base fixtures do not
     *     track profiles, so a failed hook would leak them.
     */
    private array $createdProfileUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The hook needs a session (currentUserId() throws), not admin permission.
        // Without it the hook aborts and the test fails with a confusing "wrong ID" message.
        $actingUserId = $this->createTestUser();
        $this->loginAsTestUser($actingUserId);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->createdProfileUserIds as $userId) {
                // DB::query() does not throw on execute failure; check error().
                $result = $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
                if ($result->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for profile user_id {$userId}: {$result->errorString()}\n");
                }
            }
        } finally {
            // Always run base cleanup, including restoreGlobalUser().
            parent::tearDown();
        }
    }

    /**
     * Two cars prove the per-car loop; a second user's request proves the expiry UPDATE is scoped.
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

        // Seed PII: createTestCar() leaves it NULL, which would let a missing scrub pass.
        // Names differ from noowner's so the post-hook name check proves a change.
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

        $transferRequestId = $this->createTransferRequest($carIds[0], $userId, [
            'status'            => 'pending',
            'submitted_chassis' => 'T' . substr(uniqid(), -10),
        ]);

        // Catches a widened WHERE clause that would expire every pending request.
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId);
        $otherTransferRequestId = $this->createTransferRequest($otherCarId, $otherUserId, [
            'status'            => 'pending',
            'submitted_chassis' => 'T' . substr(uniqid(), -10),
        ]);

        $this->db->delete('users', ['id', '=', $userId]);
        // Leave $userId in createdUserIds — tearDown's redundant DELETE is a 0-row no-op.

        foreach ($carIds as $carId) {
            $before = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertSame($userId, (int) $before->user_id, "Pre-condition: car $carId's user_id intact after user deleted (no FK)");
        }

        // A failed seed would make the scrub assertions vacuous.
        foreach ($carIds as $carId) {
            $seededFields = $this->carOwnerIdentityFields($carId);
            $this->assertSame('colin.chapman@example.com', $seededFields->email, "Pre-condition: car $carId's seeded email present before hook runs");
            $this->assertSame('Colin', $seededFields->fname, "Pre-condition: car $carId's seeded fname present before hook runs");
            $this->assertSame('Chapman', $seededFields->lname, "Pre-condition: car $carId's seeded lname present before hook runs");
            $this->assertSame('Hethel', $seededFields->city, "Pre-condition: car $carId's seeded city present before hook runs");
            $this->assertSame('Norfolk', $seededFields->state, "Pre-condition: car $carId's seeded state present before hook runs");
            $this->assertSame('United Kingdom', $seededFields->country, "Pre-condition: car $carId's seeded country present before hook runs");
            // Exact assertSame on FLOAT columns is safe for these values: MySQL emits the
            // shortest decimal that round-trips float32, which casts back to the same literal.
            $this->assertSame(52.4567, (float) $seededFields->lat, "Pre-condition: car $carId's seeded lat present before hook runs");
            $this->assertSame(1.0234, (float) $seededFields->lon, "Pre-condition: car $carId's seeded lon present before hook runs");
            $this->assertSame('https://example.com/colin', $seededFields->website, "Pre-condition: car $carId's seeded website present before hook runs");
        }

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

        // logs is never cleaned up and these messages can repeat across runs, so count deltas.
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

        foreach ($carIds as $carId) {
            $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
            $this->assertNotNull($after->user_id, "cars.user_id must not be NULL after hook runs (car $carId)");
            $this->assertSame($noOwnerId, (int) $after->user_id, "cars.user_id must equal noowner ID after hook (car $carId)");
        }

        // GDPR erasure: the noowner name comes from a live lookup so a fixture rename does not break this.
        // website is nulled (not blanked) by CarValidator's website-clearing case.
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

            // cars_hist is append-only: only the new audit row is checked, not historic rows.
            // website stays '' there: insertHistory() skips CarValidator's CLEARABLE_FIELDS pass.
            $histFields = $this->carsHistOwnerIdentityFields($carId);
            foreach (['email', 'city', 'state', 'country', 'website'] as $field) {
                $this->assertSame('', $histFields->$field, "cars_hist.$field should be blanked for car $carId");
            }
            $this->assertSame($noOwnerFname, $histFields->fname, "cars_hist.fname should equal the noowner account's own name for car $carId");
            $this->assertSame($noOwnerLname, $histFields->lname, "cars_hist.lname should equal the noowner account's own name for car $carId");
            $this->assertNull($histFields->lat, "cars_hist.lat should be null for car $carId");
            $this->assertNull($histFields->lon, "cars_hist.lon should be null for car $carId");
        }

        $this->assertSame(0, $this->profileRowCount($userId), 'profiles row must be deleted after hook runs');

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

        $this->assertSame(
            'pending',
            $this->transferRequestStatus($otherTransferRequestId),
            "Other user's unrelated transfer request must remain pending after hook runs"
        );

        $this->assertLoggedExactlyOnceSince(
            LogCategories::LOG_CATEGORY_USER_DELETION,
            $completionLogMessage,
            $completionLogBefore,
            'Hook must log exactly one UserDeletion completion summary'
        );

        foreach ($carIds as $index => $carId) {
            $this->assertLoggedExactlyOnceSince(
                LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                $perCarLogMessages[$index],
                $perCarLogsBefore[$index],
                "Hook must log exactly one CarActions entry for car $carId"
            );
        }
    }

    private function profileRowCount(int $userId): int
    {
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first();

        return (int) $row->cnt;
    }

    private function transferRequestStatus(int $transferRequestId): string
    {
        $row = $this->db->query(
            'SELECT status FROM car_transfer_requests WHERE id = ?',
            [$transferRequestId]
        )->first();

        return (string) $row->status;
    }

    private function carOwnerIdentityFields(int $carId): object
    {
        return $this->db->query(
            'SELECT email, fname, lname, city, state, country, lat, lon, website FROM cars WHERE id = ?',
            [$carId]
        )->first();
    }

    private function carsHistOwnerIdentityFields(int $carId): object
    {
        return $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, website FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER' ORDER BY id DESC LIMIT 1",
            [$carId]
        )->first();
    }

    /**
     * Trap: $lognote is a LIKE pattern; a message with '%' or '_' silently widens the match.
     */
    private function assertLoggedExactlyOnceSince(string $logtype, string $lognote, int $countBefore, string $failureMessage): void
    {
        $this->assertSame($countBefore + 1, $this->countMatchingLogs($logtype, $lognote), $failureMessage);
    }

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

    /** #1887: er_email_events cleared in the reassignment transaction (noowner-exists branch). */
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
     * #1887 fallback branch (no noowner user). Renames noowner temporarily; the
     * finally block restores it so no other test sees it missing.
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
     * Renames car_transfer_requests.admin_notes (the hook's first write) to force a
     * DB error; the finally block restores the column.
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
