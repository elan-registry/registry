<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\LogCategories;
use ElanRegistry\Retention\DeletedUserAddressesBacklogRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';

/**
 * Fake database for the backlog rule: each query gets the next scripted response.
 *
 * Named and not final: see FakeDatabase (`impureMethod.pure`).
 */
class ScriptedBacklogFakeDatabase extends FakeDatabase
{
    /** @var list<array{sql: string, params: array<mixed>}> */
    public array $queries = [];

    /** @var array{error: bool, sqlstate: string, row: object|array<string, mixed>, rows: list<\stdClass>} */
    private array $current = ['error' => false, 'sqlstate' => '', 'row' => [], 'rows' => []];

    /**
     * @param list<array{error?: bool, sqlstate?: string, row?: object, rows?: list<\stdClass>}> $script
     *        One response for each query, in order
     */
    public function __construct(private array $script)
    {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        $next = array_shift($this->script) ?? [];
        $this->current = [
            'error' => $next['error'] ?? false,
            'sqlstate' => $next['sqlstate'] ?? '',
            'row' => $next['row'] ?? [],
            'rows' => $next['rows'] ?? [],
        ];

        return $this;
    }

    public function error(): bool
    {
        return $this->current['error'];
    }

    public function errorString(): string
    {
        return $this->current['error'] ? 'simulated failure' : '';
    }

    public function errorInfo(): array
    {
        return $this->current['error'] ? [$this->current['sqlstate'], 1, 'simulated failure'] : [0, null, null];
    }

    public function first(bool $assoc = false): array|object
    {
        return $this->current['row'];
    }

    public function results(bool $assoc = false): array
    {
        return $this->current['rows'];
    }
}

/**
 * Unit tests for DeletedUserAddressesBacklogRule::purge() (#2419).
 *
 * CI runs only unit tests, so each branch of purge() has a test here. The
 * integration test checks the SQL against the real table.
 */
#[Group('fast')]
final class DeletedUserAddressesBacklogRuleTest extends TestCase
{
    private const TABLE_PRESENT = ['row' => ['cnt' => 1]];

    protected function setUp(): void
    {
        $GLOBALS['mockLogEntries'] = [];
    }

    /**
     * @param list<array{error?: bool, sqlstate?: string, row?: array<string, mixed>, rows?: list<int>}> $script
     */
    private static function db(array $script): ScriptedBacklogFakeDatabase
    {
        $normalized = array_map(
            static function (array $step): array {
                if (isset($step['row'])) {
                    $step['row'] = (object) $step['row'];
                }
                if (isset($step['rows'])) {
                    $step['rows'] = array_map(static fn (int $id): \stdClass => (object) ['user_id' => $id], $step['rows']);
                }
                return $step;
            },
            $script
        );

        return new ScriptedBacklogFakeDatabase($normalized);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-25 03:00:00');
    }

    public function testNoStaleRowGivesZeroCountsAndNoLogLine(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['rows' => []]]);

        $result = (new DeletedUserAddressesBacklogRule($db))->purge(self::now());

        $this->assertFalse($result->isSkipped());
        $this->assertSame('deleted_user_addresses_backlog', $result->ruleName);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertSame([], $GLOBALS['mockLogEntries']);
    }

    public function testStaleRowsThrowAndLogTheUserIdsAndTheRemedy(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['rows' => [17, 42]]]);

        try {
            (new DeletedUserAddressesBacklogRule($db))->purge(self::now());
            $this->fail('A stale holding row must fail the rule');
        } catch (RetentionPurgeException $e) {
            $this->assertSame('stale holding rows', $e->step());
            $this->assertNull($e->sqlState());
            $this->assertStringContainsString('2 deleted user(s)', $e->getMessage());
            $this->assertStringContainsString('user IDs 17, 42', $e->getMessage());
            $this->assertStringNotContainsString('@', $e->getMessage(), 'The message must hold no address');
        }

        $this->assertCount(1, $GLOBALS['mockLogEntries']);
        $entry = $GLOBALS['mockLogEntries'][0];
        $this->assertSame(LogCategories::LOG_CATEGORY_RETENTION_PURGE, $entry['category']);
        $this->assertStringContainsString('user IDs 17, 42', $entry['message']);
        $this->assertStringContainsString('30-Rerun-Deletion-Privacy-Step.php for each user ID', $entry['message']);
        $this->assertStringNotContainsString('@', $entry['message'], 'The log line must hold no address');
    }

    public function testTheRuleDeletesNothing(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['rows' => [5]]]);

        try {
            (new DeletedUserAddressesBacklogRule($db))->purge(self::now());
        } catch (RetentionPurgeException) {
            // Expected: see the test above.
        }

        foreach ($db->queries as $query) {
            $this->assertStringStartsWith('SELECT', ltrim($query['sql']), 'The rerun script needs the holding row');
        }
    }

    public function testStaleRowCheckSelectsNoAddressAndComparesWithMysqlNow(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['rows' => []]]);

        (new DeletedUserAddressesBacklogRule($db))->purge(self::now());

        $sql = (string) preg_replace('/\s+/', ' ', trim($db->queries[1]['sql']));
        $this->assertStringStartsWith('SELECT user_id FROM er_deleted_user_addresses', $sql);
        $this->assertStringContainsString('WHERE deleted_at < NOW() - INTERVAL 24 HOUR', $sql, 'The trigger writes deleted_at with MySQL NOW()');
    }

    public function testAbsentTableGivesASkip(): void
    {
        $db = self::db([['row' => ['cnt' => 0]]]);

        $result = (new DeletedUserAddressesBacklogRule($db))->purge(self::now());

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('er_deleted_user_addresses table absent', (string) $result->skipReason);
        $this->assertCount(1, $db->queries, 'Only the table check may run');
        $this->assertSame(['er_deleted_user_addresses'], $db->queries[0]['params'], 'The table name must be bound');
    }

    public function testFailedTableCheckThrows(): void
    {
        $db = self::db([['error' => true, 'sqlstate' => '42S02']]);

        try {
            (new DeletedUserAddressesBacklogRule($db))->purge(self::now());
            $this->fail('A failed table check must throw, not read as "absent"');
        } catch (RetentionPurgeException $e) {
            $this->assertSame('table check', $e->step());
            $this->assertSame('42S02', $e->sqlState());
        }
    }

    public function testTableCheckWithNoCountRowThrows(): void
    {
        $db = new ScriptedBacklogFakeDatabase([[]]);

        try {
            (new DeletedUserAddressesBacklogRule($db))->purge(self::now());
            $this->fail('A table check with no COUNT row must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('no COUNT row', $e->getMessage());
            $this->assertSame('table check', $e->step());
        }
    }

    public function testFailedStaleRowCheckThrows(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['error' => true, 'sqlstate' => 'HY000']]);

        try {
            (new DeletedUserAddressesBacklogRule($db))->purge(self::now());
            $this->fail('A failed stale row check must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertSame('stale row check', $e->step());
            $this->assertSame('HY000', $e->sqlState());
        }
    }
}
