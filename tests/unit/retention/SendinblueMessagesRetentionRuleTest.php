<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\Retention\SendinblueMessagesRetentionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';

/**
 * Fake database for the rule: each query gets the next scripted response.
 *
 * Named and not final: see FakeDatabase (`impureMethod.pure`).
 */
class ScriptedRetentionFakeDatabase extends FakeDatabase
{
    /** @var list<array{sql: string, params: array<mixed>}> */
    public array $queries = [];

    /** @var array{error: bool, sqlstate: string, row: array<string, mixed>|object, count: int} */
    private array $current = ['error' => false, 'sqlstate' => '', 'row' => [], 'count' => 0];

    /**
     * @param list<array{error?: bool, sqlstate?: string, row?: array<string, mixed>|object, count?: int}> $script
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
            'count' => $next['count'] ?? 0,
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

    public function count(): int
    {
        return $this->current['count'];
    }

    /** @return list<string> The first word of each SQL statement, upper case */
    public function verbs(): array
    {
        return array_map(
            static fn (array $q): string => strtoupper((string) strtok(trim($q['sql']), " \n")),
            $this->queries
        );
    }
}

/**
 * Unit tests for SendinblueMessagesRetentionRule::purge() (#2384).
 *
 * CI runs only unit tests, so each branch of purge() has a test here. The
 * integration test checks the SQL against the real tables.
 */
#[Group('fast')]
final class SendinblueMessagesRetentionRuleTest extends TestCase
{
    private const TABLE_PRESENT = ['count' => 1, 'row' => ['cnt' => 1]];

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-25 03:00:00');
    }

    /**
     * @param list<array{error?: bool, sqlstate?: string, row?: array<string, mixed>|object, count?: int}> $script
     */
    private static function db(array $script): ScriptedRetentionFakeDatabase
    {
        $normalized = array_map(
            static function (array $step): array {
                if (isset($step['row']) && is_array($step['row']) && $step['row'] !== []) {
                    $step['row'] = (object) $step['row'];
                }
                return $step;
            },
            $script
        );

        return new ScriptedRetentionFakeDatabase($normalized);
    }

    public function testAbsentTableGivesASkipAndNoDeleteOrUpdate(): void
    {
        $db = self::db([['row' => ['cnt' => 0]]]);

        $result = (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());

        $this->assertTrue($result->isSkipped());
        $this->assertSame('sendinblue_messages', $result->ruleName);
        $this->assertStringContainsString('plg_sendinblue_messages table absent', (string) $result->skipReason);
        $this->assertSame(['SELECT'], $db->verbs(), 'Only the table check may run');
        $this->assertStringContainsString('information_schema.tables', $db->queries[0]['sql']);
        $this->assertSame(['plg_sendinblue_messages'], $db->queries[0]['params'], 'The table name must be bound');
    }

    public function testFailedTableCheckThrows(): void
    {
        $db = self::db([['error' => true, 'sqlstate' => '42S02']]);

        try {
            (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());
            $this->fail('A failed table check must throw, not read as "absent"');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('table check failed', $e->getMessage());
            $this->assertSame('table check', $e->step());
            $this->assertSame('42S02', $e->sqlState());
        }
        $this->assertSame(['SELECT'], $db->verbs());
    }

    public function testASqlStateThatIsNotFiveCharactersIsDropped(): void
    {
        $db = self::db([['error' => true, 'sqlstate' => 'not-a-state']]);

        try {
            (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());
            $this->fail('A failed table check must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertNull($e->sqlState(), 'Only a 5-character SQLSTATE may reach the log line');
        }
    }

    public function testTableCheckWithNoCountRowThrows(): void
    {
        $db = self::db([['row' => []]]);

        try {
            (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());
            $this->fail('A table check with no COUNT row must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('no COUNT row', $e->getMessage());
            $this->assertSame('table check', $e->step());
            $this->assertNull($e->sqlState());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sqlStateProvider(): array
    {
        return [
            'missing table (er_email_events)' => ['42S02'],
            'lock wait timeout' => ['HY000'],
        ];
    }

    #[DataProvider('sqlStateProvider')]
    public function testFailedDeleteThrowsForAnySqlState(string $sqlState): void
    {
        $db = self::db([self::TABLE_PRESENT, ['error' => true, 'sqlstate' => $sqlState]]);

        try {
            (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());
            $this->fail('A failed delete must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('delete failed', $e->getMessage());
            $this->assertSame('delete', $e->step());
            $this->assertSame($sqlState, $e->sqlState());
        }
        $this->assertSame(['SELECT', 'DELETE'], $db->verbs(), 'No UPDATE may run after a failed delete');
    }

    #[DataProvider('sqlStateProvider')]
    public function testFailedClearAfterADeleteThrows(string $sqlState): void
    {
        $db = self::db([self::TABLE_PRESENT, ['count' => 5], ['error' => true, 'sqlstate' => $sqlState]]);

        try {
            (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());
            $this->fail('A failed clear must throw, not skip and drop the delete count');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('clear failed', $e->getMessage());
            $this->assertSame('clear', $e->step());
            $this->assertSame($sqlState, $e->sqlState());
        }
        $this->assertSame(['SELECT', 'DELETE', 'UPDATE'], $db->verbs());
    }

    public function testBothStatementsSucceedGivesTheCountsOfEach(): void
    {
        $db = self::db([self::TABLE_PRESENT, ['count' => 7], ['count' => 3]]);

        $result = (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());

        $this->assertFalse($result->isSkipped());
        $this->assertSame(7, $result->deleted);
        $this->assertSame(3, $result->cleared);
        $this->assertSame(['SELECT', 'DELETE', 'UPDATE'], $db->verbs());
    }

    public function testCutoffIsNowMinusTheWindowInDatabaseFormat(): void
    {
        $db = self::db([self::TABLE_PRESENT, [], []]);

        (new SendinblueMessagesRetentionRule($db, 30))->purge(self::now());

        $this->assertSame(['2026-09-25 03:00:00'], $db->queries[1]['params'], 'DELETE cutoff');
        $this->assertSame(['2026-09-25 03:00:00'], $db->queries[2]['params'], 'UPDATE cutoff');
    }

    /**
     * The fake ignores SQL text, and CI runs no integration test. Without
     * this test, a change to the keep-or-delete conditions passes CI.
     */
    public function testStatementsKeepTheConditionsThatDecideDeleteOrClear(): void
    {
        $db = self::db([self::TABLE_PRESENT, [], []]);

        (new SendinblueMessagesRetentionRule($db, 90))->purge(self::now());

        $normalize = static fn (string $sql): string => (string) preg_replace('/\s+/', ' ', trim($sql));
        $delete = $normalize($db->queries[1]['sql']);
        $update = $normalize($db->queries[2]['sql']);

        $this->assertStringContainsString('WHERE accepted_at < ?', $delete);
        $this->assertStringContainsString('AND NOT EXISTS ( SELECT 1 FROM er_email_events e', $delete, 'A row that an event names must stay (#2460)');
        $this->assertStringContainsString(
            'e.brevo_message_id COLLATE utf8mb4_bin = plg_sendinblue_messages.message_id COLLATE utf8mb4_bin',
            $delete,
            'Without COLLATE the join fails with error 1267, and the match must be case-sensitive'
        );
        $this->assertStringContainsString("SET recipient_email = ''", $update);
        $this->assertStringContainsString("WHERE accepted_at < ? AND recipient_email != ''", $update);
    }
}
