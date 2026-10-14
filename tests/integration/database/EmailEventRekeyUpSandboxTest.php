<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailEventRekeySandboxTestCase.php';

use ElanRegistry\Migration\EmailEventRekeyQueries;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up() of migration 20261013000000 on the fixture (#2358).
 *
 * Every expected value is a literal in {@see EmailEventRekeyMigrationFixture}.
 */
#[Group('integration')]
#[Group('migration')]
final class EmailEventRekeyUpSandboxTest extends EmailEventRekeySandboxTestCase
{
    private static string $output = '';

    /** @var list<array<string, mixed>> */
    private static array $profilesBefore = [];

    /** @var list<int> The IDs that the report said the migration keeps. */
    private static array $reportKeptIds = [];

    public function testTheReportShowsTheLiteralExpectedDecisionsBeforeUp(): void
    {
        $queries = new EmailEventRekeyQueries($this->pdo());

        $this->assertFalse($queries->isRekeyed());
        $this->assertSame(EmailEventRekeyMigrationFixture::OWNER_MATCHED_IDS, $queries->ownerMatchedIds());
        $this->assertSame(EmailEventRekeyMigrationFixture::ADDRESS_MATCHED_IDS, $queries->addressMatchedIds());
        $this->assertSame(EmailEventRekeyMigrationFixture::DROPPED_IDS, $queries->droppedIds());
        $this->assertSame(EmailEventRekeyMigrationFixture::COLLAPSE_GROUPS, $queries->collapseGroups());

        $removed = [];
        foreach ($queries->collapseGroups() as $group) {
            $removed = array_merge($removed, $group['removed_ids']);
        }
        $kept = array_values(array_diff(
            array_merge($queries->ownerMatchedIds(), $queries->addressMatchedIds()),
            $removed
        ));
        sort($kept);
        self::$reportKeptIds = $kept;
        self::$profilesBefore = $this->profileSnapshot();
        $this->assertCount(20, $this->eventRows(), 'The fixture has 20 events');
    }

    #[Depends('testTheReportShowsTheLiteralExpectedDecisionsBeforeUp')]
    public function testUpRuns(): void
    {
        self::$output = $this->upWithOutput();

        $this->assertNotSame('', self::$output, 'up() must print its decisions');
        $this->assertNotNull($this->columnInfo('er_email_events', 'user_id'));
    }

    #[Depends('testUpRuns')]
    public function testTheKeptIdsAreTheLiteralFixtureIdsAndMatchTheReport(): void
    {
        $this->assertSame(EmailEventRekeyMigrationFixture::KEPT_IDS, $this->eventIds());
        $this->assertSame(self::$reportKeptIds, $this->eventIds(), 'The migration must keep what the report said it keeps');
    }

    #[Depends('testUpRuns')]
    public function testTheOwnerWithThreeCarsCollapsesToTheLowestIdWithTheEarliestTime(): void
    {
        $rows = $this->eventRows();

        $this->assertArrayNotHasKey(2, $rows);
        $this->assertArrayNotHasKey(3, $rows);
        $this->assertSame(201, (int) $rows[1]['user_id']);
        $this->assertSame(2010, (int) $rows[1]['car_id'], 'The kept row keeps its car');
        // Row 1 was at 10:00. Row 2 was the earliest, at 09:00. A delivered reset point must not move later.
        $this->assertSame('2026-03-01 09:00:00', $rows[1]['occurred_at']);
    }

    #[Depends('testUpRuns')]
    public function testMessageIdsThatDifferOnlyInCaseCollapseSoTheNewKeyBuilds(): void
    {
        $rows = $this->eventRows();

        $this->assertArrayHasKey(13, $rows);
        $this->assertArrayNotHasKey(14, $rows);
        $this->assertSame('2026-03-02 06:00:00', $rows[13]['occurred_at']);
    }

    #[Depends('testUpRuns')]
    public function testTheSameMessageIdForAnotherUserIsNotCollapsed(): void
    {
        $rows = $this->eventRows();

        $this->assertSame(207, (int) $rows[16]['user_id']);
        $this->assertSame('msg-A', $rows[16]['brevo_message_id']);
        $this->assertSame('2026-03-12 12:00:00', $rows[16]['occurred_at']);
    }

    #[Depends('testUpRuns')]
    public function testEveryKeptRowHasTheExpectedUser(): void
    {
        $users = [];
        foreach ($this->eventRows() as $id => $row) {
            $users[$id] = (int) $row['user_id'];
        }

        $this->assertSame(EmailEventRekeyMigrationFixture::USER_AFTER, $users);
    }

    #[Depends('testUpRuns')]
    public function testARowMatchesTheOneUserWithItsAddressWhenTheCarOwnerDoesNotMatch(): void
    {
        $rows = $this->eventRows();

        // Event 4 is on a car of user 202 with the address of user 203.
        $this->assertSame(203, (int) $rows[4]['user_id']);
        // Event 12 is on a car that does not exist.
        $this->assertSame(207, (int) $rows[12]['user_id']);
        $this->assertSame(9999, (int) $rows[12]['car_id'], 'The migration does not change car_id');
    }

    #[Depends('testUpRuns')]
    public function testTheOwnersAddressWinsWhenASecondUserHoldsItToo(): void
    {
        $row = $this->eventRows()[18];

        $this->assertSame(216, (int) $row['user_id']);
        $this->assertSame(2160, (int) $row['car_id']);
    }

    #[Depends('testUpRuns')]
    public function testARowOnACarOfNoownerGoesToTheOneRealUserWithItsAddress(): void
    {
        $rows = $this->eventRows();

        $this->assertSame(202, (int) $rows[19]['user_id']);
        $this->assertSame(2100, (int) $rows[19]['car_id'], 'The migration does not change car_id');
        $this->assertArrayNotHasKey(20, $rows, 'A car of noowner with an address that two users hold is dropped');
    }

    #[Depends('testUpRuns')]
    public function testAnAddressThatTwoUsersHoldIsDropped(): void
    {
        $this->assertNotContains(5, $this->eventIds());
    }

    #[Depends('testUpRuns')]
    public function testAnAccentVariantOfTheAddressDoesNotMatchAndIsDropped(): void
    {
        $ids = $this->eventIds();

        // The column collation would match josé@ with jose@. The byte compare must not.
        $this->assertNotContains(6, $ids);
        $this->assertContains(7, $ids);
    }

    #[Depends('testUpRuns')]
    public function testTheAddressIsStoredInLowerCaseWithoutSpaces(): void
    {
        $rows = $this->eventRows();

        $this->assertSame('jose@example.test', $rows[8]['email']);
        $this->assertSame('three.cars@example.test', $rows[1]['email']);
    }

    #[Depends('testUpRuns')]
    public function testNoownerRowsAndRowsWithoutAUserAreDropped(): void
    {
        $ids = $this->eventIds();

        foreach ([9, 10, 11] as $id) {
            $this->assertNotContains($id, $ids, "Event {$id} has no user or has noowner");
        }
    }

    #[Depends('testUpRuns')]
    public function testARowWithAnEmptyAddressIsDroppedBecauseAnEmptyAddressMatchesNoUser(): void
    {
        $this->assertNotContains(17, $this->eventIds());
    }

    #[Depends('testUpRuns')]
    public function testTheSentRowKeepsItsCar(): void
    {
        $row = $this->eventRows()[15];

        $this->assertSame('sent', $row['event']);
        $this->assertSame(201, (int) $row['user_id']);
        $this->assertSame(2010, (int) $row['car_id']);
        $this->assertSame('local:abc', $row['brevo_message_id']);
    }

    #[Depends('testUpRuns')]
    public function testTheSchemaKeysAfterUp(): void
    {
        $user = $this->columnInfo('er_email_events', 'user_id');
        $car = $this->columnInfo('er_email_events', 'car_id');
        $this->assertNotNull($user);
        $this->assertNotNull($car);
        $this->assertSame('NO', $user['IS_NULLABLE'], 'user_id must be NOT NULL');
        $this->assertSame('YES', $car['IS_NULLABLE'], 'car_id must allow NULL');

        $this->assertNotNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']), 'The old unique key must be gone');
        $this->assertTrue($this->hasIndexOn(['user_id', 'occurred_at']));
        $this->assertTrue($this->hasIndexOn(['brevo_message_id']));
        $this->assertTrue($this->hasIndexOn(['email']), 'The email index stays');
        $this->assertTrue($this->hasIndexOn(['car_id', 'occurred_at']), 'The car index stays');
    }

    #[Depends('testUpRuns')]
    public function testTheNewUniqueKeyRefusesADuplicate(): void
    {
        $insert = $this->pdo()->prepare(
            "INSERT INTO er_email_events (user_id, car_id, email, event, brevo_message_id, occurred_at)
             VALUES (201, NULL, 'x@example.test', 'delivered', 'msg-A', '2026-03-20 00:00:00')"
        );

        $this->expectException(\PDOException::class);
        $insert->execute();
    }

    #[Depends('testUpRuns')]
    public function testProfilesAreUnchanged(): void
    {
        $this->assertSame(self::$profilesBefore, $this->profileSnapshot(), 'The backfill must set no flag');
    }

    #[Depends('testUpRuns')]
    public function testTheOutputNamesIdsAndNoAddress(): void
    {
        $this->assertStringContainsString('Phase 2: matched by car owner, event ID(s): 1, 2, 3, 7, 8, 13, 14, 15, 16, 18', self::$output);
        $this->assertStringContainsString('Phase 2: matched by address, event ID(s): 4, 12, 19', self::$output);
        $this->assertStringContainsString('Phase 2: dropped (no user or noowner), event ID(s): 5, 6, 9, 10, 11, 17, 20', self::$output);
        $this->assertStringContainsString('kept 1, removed 2, 3', self::$output);
        $this->assertStringNotContainsString('@', self::$output, 'The output must not name an address');
    }

    #[Depends('testUpRuns')]
    public function testTheReportNowSaysAlreadyMigrated(): void
    {
        $this->assertTrue((new EmailEventRekeyQueries($this->pdo()))->isRekeyed());
    }
}
