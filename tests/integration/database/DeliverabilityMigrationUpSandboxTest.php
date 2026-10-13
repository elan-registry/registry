<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliverabilityMigrationSandboxTestCase.php';

use ElanRegistry\Migration\DeliverabilityMigrationQueries;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up() of migration 20261012000000_move_deliverability_flags_to_profiles on the fixture (#2101).
 *
 * The first test stops the migration on a duplicate user_id. The second test
 * runs up(). The rest read the migrated sandbox, and the last one runs phase 1
 * again. #[Depends] fixes the order.
 */
#[Group('integration')]
#[Group('migration')]
final class DeliverabilityMigrationUpSandboxTest extends DeliverabilityMigrationSandboxTestCase
{
    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    /** @var array<string, list<mixed>> What DeliverabilityMigrationQueries decided before up(). */
    private static array $decisions = [];

    private static string $output = '';

    public function testDuplicateUserIdStopsTheMigrationAndChangesNothing(): void
    {
        $profilesBefore = $this->columnNames('profiles');
        $carsBefore = $this->columnNames('cars');
        $triggersBefore = $this->carsTriggerBodies();
        $rowsBefore = $this->profileSnapshot();

        // Two owners get a second row. The message must list both.
        $this->pdo()->exec(
            "INSERT INTO profiles (user_id, bio, city, state, country) VALUES (101, '', '', '', ''), (103, '', '', '', '')"
        );
        $firstDuplicateId = (int) $this->pdo()->lastInsertId();
        $rowsWithDuplicates = $this->profileSnapshot();
        $this->assertCount(count($rowsBefore) + 2, $rowsWithDuplicates);

        try {
            $this->upWithOutput();
            $this->fail('up() must throw while two profiles rows share a user_id');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('more than one row for user_id 101, 103', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
            $this->assertStringNotContainsString('@', $e->getMessage());
        }

        $this->assertSame($profilesBefore, $this->columnNames('profiles'), 'profiles columns must not change');
        $this->assertSame($carsBefore, $this->columnNames('cars'), 'cars columns must not change');
        $this->assertSame($triggersBefore, $this->carsTriggerBodies(), 'The triggers must not change');
        $this->assertFalse($this->hasIndex('profiles', 'uniq_profiles_user_id'), 'The key must not exist');
        $this->assertSame($rowsWithDuplicates, $this->profileSnapshot(), 'No profiles row may change');

        // Back to the fixture.
        $this->pdo()->exec('DELETE FROM profiles WHERE id >= ' . $firstDuplicateId);
        $this->assertSame($rowsBefore, $this->profileSnapshot(), 'The test must remove its duplicate rows');
    }

    #[Depends('testDuplicateUserIdStopsTheMigrationAndChangesNothing')]
    public function testUpRuns(): void
    {
        $queries = new DeliverabilityMigrationQueries($this->pdo());
        self::$decisions = [
            'duplicates'  => $queries->duplicateProfileUserIds(),
            'inserts'     => array_column($queries->profilesToInsert(), 'user_id'),
            'bounces'     => array_column($queries->bounceCopies(), 'user_id'),
            'dropped'     => $queries->droppedBounces(),
            'restores'    => array_column($queries->optOutRestores(), 'user_id'),
            'blocks'      => array_column($queries->blockCopies(), 'user_id'),
            'unknown'     => $queries->unknownCauseBlocks(),
            'suppressDrop' => $queries->droppedSuppressions(),
        ];

        self::$output = $this->upWithOutput();

        $this->assertNotSame('', self::$output, 'up() must print its decisions');
        $this->assertNull($this->columnInfo('cars', 'email_bounced'), 'cars.email_bounced must be gone');
        $this->assertNotNull($this->columnInfo('profiles', 'email_opted_out'));
    }

    #[Depends('testUpRuns')]
    public function testBounceOnTheCurrentAddressIsCopiedWhateverTheCaseAndSpaces(): void
    {
        $row = $this->profile(101);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['email_bounced']);
        $this->assertSame('bounce.current@example.test', $row['email_bounced_address']);
        // The latest hard bounce wins over the later soft bounce. The event address has other case.
        $this->assertSame('2026-03-01 10:00:00', $row['email_bounced_at']);
        $this->assertSame(0, (int) $row['email_opted_out']);
        $this->assertSame(0, (int) $row['email_blocked']);
        // The users.email has leading spaces and capitals.
        $this->assertSame('bounce.current@example.test', $row['email_address_seen']);
        $this->assertSame('2024-02-01 00:00:00', $row['email_address_since']);
    }

    #[Depends('testUpRuns')]
    public function testBounceOnAnOldAddressAndABounceWithNoAddressAreDropped(): void
    {
        foreach ([102, 117] as $userId) {
            $row = $this->profile($userId);
            $this->assertNotNull($row, "Owner {$userId} must keep a profiles row");
            $this->assertSame(0, (int) $row['email_bounced'], "Owner {$userId} must not get the bounce");
            $this->assertNull($row['email_bounced_address']);
            $this->assertNull($row['email_bounced_at']);
        }
        $this->assertStringContainsString(
            'Phase 2: old-address bounce dropped for car 1020 (user 102), car 1170 (user 117)',
            self::$output
        );
    }

    #[Depends('testUpRuns')]
    public function testComplaintBlocksTheOwnerWithCauseAddressAndDate(): void
    {
        $row = $this->profile(103);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['email_blocked']);
        $this->assertSame('complaint103@example.test', $row['email_blocked_address']);
        $this->assertSame('spam', $row['email_blocked_cause']);
        $this->assertSame('2026-04-01 09:00:00', $row['email_blocked_at']);
        $this->assertSame(0, (int) $row['email_opted_out']);
    }

    #[Depends('testUpRuns')]
    public function testOwnerWhoComplainedAndLaterOptedOutIsOptedOutAndBlocked(): void
    {
        $row = $this->profile(104);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['email_opted_out']);
        $this->assertSame('2026-05-01 08:00:00', $row['email_opted_out_at'], 'The date is the EMAIL SUPPRESSED row');
        $this->assertSame(1, (int) $row['email_blocked'], 'Resume must not re-enable mail to this address');
        $this->assertSame('unsubscribed', $row['email_blocked_cause']);
        $this->assertSame('2026-04-15 07:00:00', $row['email_blocked_at']);
        $this->assertSame('both104@example.test', $row['email_blocked_address']);
    }

    #[Depends('testUpRuns')]
    public function testUnknownCauseBlocksWithTheOwnerAddressAndNullCause(): void
    {
        // 105 was cleared by an owner Resume row, 111 by an admin EMAIL SUPPRESSION CLEARED row.
        // Both rows end the opt-out, so no restore happens and the car suppression becomes a block.
        $expected = [
            105 => ['unknown105@example.test', '2026-02-10 12:00:00'],
            111 => ['adminclear111@example.test', '2026-03-01 13:00:00'],
        ];
        foreach ($expected as $userId => [$address, $date]) {
            $row = $this->profile($userId);
            $this->assertNotNull($row);
            $this->assertSame(0, (int) $row['email_opted_out'], "Owner {$userId}: a later clear row ends the opt-out");
            $this->assertNull($row['email_opted_out_at']);
            $this->assertSame(1, (int) $row['email_blocked']);
            $this->assertNull($row['email_blocked_cause'], 'The cause is unknown');
            $this->assertSame($address, $row['email_blocked_address'], 'The address is the normalized users.email');
            $this->assertSame($date, $row['email_blocked_at']);
        }
        $this->assertStringContainsString('Phase 2: block with no known cause for user ID(s): 105, 111', self::$output);
    }

    #[Depends('testUpRuns')]
    public function testCarLevelOptOutOnlyBecomesAnOwnerOptOutNotABlock(): void
    {
        $row = $this->profile(106);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['email_opted_out']);
        $this->assertSame('2026-06-01 11:00:00', $row['email_opted_out_at']);
        $this->assertSame(0, (int) $row['email_blocked']);
        $this->assertNull($row['email_blocked_address']);
        $this->assertNull($row['email_blocked_cause']);
        $this->assertStringContainsString('Phase 2: opt-out restored for user ID(s): 106, 107', self::$output);
    }

    #[Depends('testUpRuns')]
    public function testTransferredCarDoesNotGiveItsOldComplaintToTheNewOwner(): void
    {
        $newOwner = $this->profile(108);
        $this->assertNotNull($newOwner);
        $this->assertSame(0, (int) $newOwner['email_blocked']);
        $this->assertNull($newOwner['email_blocked_address']);
        $this->assertSame(0, (int) $newOwner['email_opted_out']);
        $this->assertStringContainsString('Phase 2: old-address suppression dropped for car 1070 (user 108)', self::$output);

        // The old owner's opt-out on that car stays with the old owner.
        $oldOwner = $this->profile(107);
        $this->assertNotNull($oldOwner);
        $this->assertSame(1, (int) $oldOwner['email_opted_out']);
        $this->assertSame('2026-01-20 09:00:00', $oldOwner['email_opted_out_at']);
        $this->assertSame(0, (int) $oldOwner['email_blocked']);
    }

    #[Depends('testUpRuns')]
    public function testOwnersWithoutAProfileGetOneWithTheirFlag(): void
    {
        $bounced = $this->profile(109);
        $this->assertNotNull($bounced, 'Owner 109 must get a profiles row');
        $this->assertSame(1, (int) $bounced['email_bounced']);
        $this->assertSame('noprofile109@example.test', $bounced['email_bounced_address']);
        $this->assertSame('2026-07-01 06:00:00', $bounced['email_bounced_at']);
        $this->assertSame('2024-02-09 00:00:00', $bounced['email_address_since']);
        $this->assertSame('noprofile109@example.test', $bounced['email_address_seen']);
        $this->assertSame('', $bounced['bio']);
        $this->assertSame('', $bounced['country']);

        $clean = $this->profile(112);
        $this->assertNotNull($clean, 'Every car owner must get a profiles row');
        $this->assertSame(0, (int) $clean['email_bounced']);
        $this->assertSame(0, (int) $clean['email_blocked']);
        $this->assertSame(0, (int) $clean['email_opted_out']);
        $this->assertSame('2024-02-12 00:00:00', $clean['email_address_since']);

        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM profiles WHERE user_id = 109'), 'Two cars give one row');
        $this->assertStringContainsString('Phase 1: inserted 2 profiles row(s) for user ID(s): 109, 112', self::$output);
    }

    #[Depends('testUpRuns')]
    public function testNoownerAndNullUserIdAreSkippedAndNotListed(): void
    {
        $this->assertNull($this->profile(110), 'noowner must not get a profiles row');
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM profiles WHERE user_id IS NULL OR user_id = 113'));
        $this->assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM profiles WHERE email_bounced_address LIKE 'noowner%' OR email_bounced_address LIKE 'orphan%'
                OR email_blocked_address LIKE 'noowner%' OR email_blocked_address LIKE 'orphan%'"
        ));
        $this->assertSame(
            count(DeliverabilityMigrationFixture::OWNERS_WITH_PROFILE) + count(DeliverabilityMigrationFixture::OWNERS_WITHOUT_PROFILE),
            $this->scalar('SELECT COUNT(*) FROM profiles')
        );
        $this->assertStringNotContainsString('1100', self::$output);
        $this->assertStringNotContainsString('1130', self::$output);
        $this->assertStringNotContainsString('110', str_replace('1100', '', self::$output));
    }

    #[Depends('testUpRuns')]
    public function testBouncedProfilesGetBouncedAtFromTheEventOrTheHistoryRow(): void
    {
        $withEvent = $this->profile(114);
        $this->assertNotNull($withEvent);
        $this->assertSame('2026-08-01 05:00:00', $withEvent['email_bounced_at'], 'A hard bounce wins over a later soft bounce');

        $withHistory = $this->profile(115);
        $this->assertNotNull($withHistory);
        $this->assertSame('2026-09-01 04:00:00', $withHistory['email_bounced_at']);

        $withNothing = $this->profile(116);
        $this->assertNotNull($withNothing);
        $this->assertSame(1, (int) $withNothing['email_bounced']);
        $this->assertNull($withNothing['email_bounced_at'], 'No match leaves the date NULL');

        $this->assertStringContainsString('Phase 1: email_bounced_at backfilled for 2 profile(s)', self::$output);
        $this->assertStringContainsString('Phase 1: email_opted_out_at backfilled for 1 profile(s)', self::$output);
    }

    #[Depends('testUpRuns')]
    public function testEveryBouncedAndBlockedRowHasANonEmptyAddress(): void
    {
        $this->assertSame(5, $this->scalar('SELECT COUNT(*) FROM profiles WHERE email_bounced = 1'));
        $this->assertSame(4, $this->scalar('SELECT COUNT(*) FROM profiles WHERE email_blocked = 1'));
        $this->assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM profiles WHERE (email_blocked = 1 AND (email_blocked_address IS NULL OR email_blocked_address = ''))
                OR (email_bounced = 1 AND (email_bounced_address IS NULL OR email_bounced_address = ''))"
        ));
    }

    #[Depends('testUpRuns')]
    public function testTheMigrationResultEqualsWhatTheReadsDecidedBeforeIt(): void
    {
        $this->assertSame([], self::$decisions['duplicates']);
        $this->assertSame([109, 112], self::$decisions['inserts']);
        $this->assertSame([101, 109], self::$decisions['bounces']);
        $this->assertSame(
            [['user_id' => 102, 'car_id' => 1020], ['user_id' => 117, 'car_id' => 1170]],
            self::$decisions['dropped']
        );
        $this->assertSame([106, 107], self::$decisions['restores']);
        $this->assertSame([103, 104, 105, 111], self::$decisions['blocks']);
        $this->assertSame([105, 111], self::$decisions['unknown']);
        $this->assertSame([['user_id' => 108, 'car_id' => 1070]], self::$decisions['suppressDrop']);

        // Each decision shows in the migrated rows.
        $this->assertSame(
            [101, 109, 114, 115, 116],
            array_map('intval', $this->column('SELECT user_id FROM profiles WHERE email_bounced = 1 ORDER BY user_id'))
        );
        $this->assertSame(
            [103, 104, 105, 111],
            array_map('intval', $this->column('SELECT user_id FROM profiles WHERE email_blocked = 1 ORDER BY user_id'))
        );
        $this->assertSame(
            [104, 106, 107],
            array_map('intval', $this->column('SELECT user_id FROM profiles WHERE email_opted_out = 1 ORDER BY user_id'))
        );
    }

    #[Depends('testUpRuns')]
    public function testTheOutputNamesIdsAndNeverAnAddress(): void
    {
        $this->assertStringNotContainsString('@', self::$output);
        $this->assertStringContainsString('Phase 2: bounce copied for user ID(s): 101, 109', self::$output);
        $this->assertStringContainsString('Phase 2: block copied for user ID(s): 103, 104, 105, 111', self::$output);
    }

    #[Depends('testUpRuns')]
    public function testProfilesUserIdIsUniqueUnderTheDocumentedName(): void
    {
        $this->assertTrue($this->hasIndex('profiles', 'uniq_profiles_user_id'));
        $this->assertSame(
            0,
            $this->scalar("SELECT NON_UNIQUE FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND INDEX_NAME = 'uniq_profiles_user_id'")
        );

        try {
            $this->pdo()->exec("INSERT INTO profiles (user_id, bio, city, state, country) VALUES (101, '', '', '', '')");
            $this->fail('A second profiles row for one user_id must be refused');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    #[Depends('testUpRuns')]
    public function testTriggersAreRebuiltWithoutTheCarFlagColumns(): void
    {
        $bodies = $this->carsTriggerBodies();
        $this->assertSame(['cars_delete', 'cars_insert', 'cars_update'], array_keys($bodies));
        foreach ($bodies as $name => $body) {
            $this->assertStringNotContainsString('email_bounced', $body, "{$name} must not name the dropped columns");
            $this->assertStringNotContainsString('email_suppressed', $body, "{$name} must not name the dropped columns");
            $this->assertStringContainsString('verification_attempts_since', $body, "{$name} must keep the other columns");
        }

        foreach (['cars', 'cars_hist'] as $table) {
            foreach (['email_bounced', 'email_bounced_address', 'email_suppressed'] as $column) {
                $this->assertNull($this->columnInfo($table, $column), "{$table}.{$column} must be dropped");
            }
        }

        // The audit still works: an update writes one cars_hist row.
        $before = $this->scalar("SELECT COUNT(*) FROM cars_hist WHERE car_id = 1120 AND operation = 'UPDATE'");
        $this->pdo()->exec("UPDATE cars SET comments = 'changed' WHERE id = 1120");
        $this->assertSame($before + 1, $this->scalar("SELECT COUNT(*) FROM cars_hist WHERE car_id = 1120 AND operation = 'UPDATE'"));
    }

    /**
     * Phase 1 runs again, as in a re-run after a failure. A value that a hook wrote stays.
     */
    #[Depends('testTriggersAreRebuiltWithoutTheCarFlagColumns')]
    public function testPhaseOneRerunKeepsAnAddressSinceThatAHookSet(): void
    {
        $this->pdo()->exec(
            "UPDATE profiles SET email_address_since = '2030-01-01 00:00:00', email_address_seen = 'hook@example.test'
              WHERE user_id = 101"
        );
        $this->pdo()->exec('UPDATE profiles SET email_address_since = NULL, email_address_seen = NULL WHERE user_id = 102');

        $output = $this->upWithOutput();

        $kept = $this->profile(101);
        $this->assertNotNull($kept);
        $this->assertSame('2030-01-01 00:00:00', $kept['email_address_since']);
        $this->assertSame('hook@example.test', $kept['email_address_seen']);

        $filled = $this->profile(102);
        $this->assertNotNull($filled);
        $this->assertSame('2024-02-02 00:00:00', $filled['email_address_since'], 'A NULL value is filled again');
        $this->assertSame('new102@example.test', $filled['email_address_seen']);
        $this->assertStringContainsString('Phase 2 skipped', $output);
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        $statement = $this->pdo()->query($sql);
        $this->assertNotFalse($statement);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
