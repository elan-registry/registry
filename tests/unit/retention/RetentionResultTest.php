<?php

declare(strict_types=1);

use ElanRegistry\Retention\RetentionResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RetentionResult (#2384).
 */
#[Group('fast')]
final class RetentionResultTest extends TestCase
{
    public function testPurgedSetsFields(): void
    {
        $result = RetentionResult::purged('rule_a', 5, 2);

        $this->assertSame('rule_a', $result->ruleName);
        $this->assertSame(5, $result->deleted);
        $this->assertSame(2, $result->cleared);
        $this->assertNull($result->skipReason);
        $this->assertFalse($result->isSkipped());
    }

    public function testPurgedAcceptsZeroCounts(): void
    {
        $result = RetentionResult::purged('rule_a', 0, 0);

        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertFalse($result->isSkipped());
    }

    public function testSkippedSetsFieldsWithZeroCounts(): void
    {
        $result = RetentionResult::skipped('rule_b', 'table absent');

        $this->assertSame('rule_b', $result->ruleName);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertSame('table absent', $result->skipReason);
        $this->assertTrue($result->isSkipped());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function negativeCounts(): array
    {
        return [
            'negative deleted' => [-1, 0],
            'negative cleared' => [0, -1],
            'both negative' => [-3, -4],
        ];
    }

    #[DataProvider('negativeCounts')]
    public function testNegativeCountsAreRefused(int $deleted, int $cleared): void
    {
        $this->expectException(InvalidArgumentException::class);

        RetentionResult::purged('rule_a', $deleted, $cleared);
    }

    public function testExceptionMessageNamesTheRule(): void
    {
        try {
            RetentionResult::purged('rule_x', -1, 0);
            $this->fail('A negative count must throw');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('rule_x', $e->getMessage());
        }
    }

    public function testConstructorIsNotPublic(): void
    {
        $this->assertFalse((new ReflectionClass(RetentionResult::class))->getConstructor()?->isPublic());
    }
}
