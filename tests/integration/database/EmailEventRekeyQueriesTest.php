<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailEventRekeySandboxTestCase.php';

use ElanRegistry\Migration\EmailEventRekeyQueries;
use PHPUnit\Framework\Attributes\Group;

/**
 * The reads of EmailEventRekeyQueries on the fixture, in the pre-migration
 * sandbox (#2358). The class only reads, so one test class can ask all of it.
 */
#[Group('integration')]
#[Group('migration')]
final class EmailEventRekeyQueriesTest extends EmailEventRekeySandboxTestCase
{
    public function testTheAssignmentsKeepOnlyTheRowsThatHaveAUser(): void
    {
        $assignments = (new EmailEventRekeyQueries($this->pdo()))->assignments();

        $this->assertSame(
            array_merge(
                EmailEventRekeyMigrationFixture::OWNER_MATCHED_IDS,
                EmailEventRekeyMigrationFixture::ADDRESS_MATCHED_IDS
            ),
            array_values(array_unique(array_merge(
                array_keys(array_filter($assignments, static fn (array $a): bool => $a['matched_by'] === 'owner')),
                array_keys(array_filter($assignments, static fn (array $a): bool => $a['matched_by'] === 'address'))
            )))
        );
        $this->assertSame(['user_id' => 201, 'email' => 'three.cars@example.test', 'matched_by' => 'owner'], $assignments[3]);
        $this->assertSame(['user_id' => 203, 'email' => 'addr203@example.test', 'matched_by' => 'address'], $assignments[4]);
        $this->assertSame('jose@example.test', $assignments[8]['email']);
        // The owner's address wins even when a second user holds it too.
        $this->assertSame(['user_id' => 216, 'email' => 'twin@example.test', 'matched_by' => 'owner'], $assignments[18]);
        // A car of noowner does not stop an address match to a real user.
        $this->assertSame(['user_id' => 202, 'email' => 'own202@example.test', 'matched_by' => 'address'], $assignments[19]);
        $this->assertArrayNotHasKey(20, $assignments, 'A car of noowner with a shared address has no user');
    }

    public function testTheThreeOutcomesDoNotOverlapAndCoverEveryRow(): void
    {
        $queries = new EmailEventRekeyQueries($this->pdo());
        $all = array_merge($queries->ownerMatchedIds(), $queries->addressMatchedIds(), $queries->droppedIds());
        sort($all);

        $this->assertSame(range(1, 20), $all);
    }

    public function testExactIdListsAndCollapseGroups(): void
    {
        $queries = new EmailEventRekeyQueries($this->pdo());

        $this->assertSame([1, 2, 3, 7, 8, 13, 14, 15, 16, 18], $queries->ownerMatchedIds());
        $this->assertSame([4, 12, 19], $queries->addressMatchedIds());
        $this->assertSame([5, 6, 9, 10, 11, 17, 20], $queries->droppedIds());
        $this->assertSame(
            [
                ['kept_id' => 1, 'removed_ids' => [2, 3], 'occurred_at' => '2026-03-01 09:00:00'],
                ['kept_id' => 13, 'removed_ids' => [14], 'occurred_at' => '2026-03-02 06:00:00'],
            ],
            $queries->collapseGroups()
        );
    }

    public function testNoResultHoldsAnAddressInIdsOrGroups(): void
    {
        $queries = new EmailEventRekeyQueries($this->pdo());

        $printed = json_encode([
            $queries->ownerMatchedIds(),
            $queries->addressMatchedIds(),
            $queries->droppedIds(),
            $queries->collapseGroups(),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('@', $printed);
    }

    public function testTheTableIsNotKeyedByUserBeforeTheMigration(): void
    {
        $this->assertFalse((new EmailEventRekeyQueries($this->pdo()))->isRekeyed());
    }

    public function testTheQueriesWriteNothing(): void
    {
        $before = $this->eventRows();

        $queries = new EmailEventRekeyQueries($this->pdo());
        $queries->assignments();
        $queries->collapseGroups();

        $this->assertSame($before, $this->eventRows());
    }

    /** The page prints these four counts. Each is a literal from the fixture. */
    public function testTheReportCountsAreTheLiteralFixtureCountsAndHoldNoAddress(): void
    {
        $counts = (new EmailEventRekeyQueries($this->pdo()))->reportCounts();

        $this->assertSame(
            ['owner_matched' => 10, 'address_matched' => 3, 'dropped' => 7, 'collapsed_removed' => 3, 'collapse_groups' => 2],
            $counts
        );
        $this->assertStringNotContainsString('@', json_encode($counts, JSON_THROW_ON_ERROR));
    }

    public function testTheMatchingRuleTrimsAndLowersCaseButKeepsAccents(): void
    {
        $this->assertSame('jose@example.test', EmailEventRekeyQueries::normalizeAddress('  JOSE@Example.TEST '));
        $this->assertSame('josé@example.test', EmailEventRekeyQueries::normalizeAddress('josé@example.test'));
        $this->assertSame('', EmailEventRekeyQueries::normalizeAddress(null));
    }
}
