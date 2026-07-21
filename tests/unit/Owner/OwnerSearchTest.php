<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\OwnerSearchException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('fast')]
#[Group('unit')]
#[Group('owner')]
final class OwnerSearchTest extends TestCase
{
    private function createMockDb(int $rowCount = 0, bool $hasError = false, array $rows = []): object
    {
        return new class($rowCount, $hasError, $rows) {
            public function __construct(
                private int $rowCount,
                private bool $hasError,
                private array $rows,
            ) {}

            public function query(string $sql, array $params = []): object
            {
                $count = $this->rowCount;
                $rows  = $this->rows;
                return new class($count, $rows) {
                    public function __construct(
                        private int $count,
                        private array $rows,
                    ) {}
                    public function count(): int { return $this->count; }
                    public function results(): array { return $this->rows; }
                };
            }

            public function error(): bool { return $this->hasError; }
            public function errorString(): string { return 'mock error'; }
        };
    }

    public function testSearchOwnersReturnsEmptyArrayWhenNoResults(): void
    {
        $db      = $this->createMockDb(0);
        $owner   = new Owner(null, $db);
        $results = $owner->searchOwners('Portland');

        $this->assertSame([], $results);
    }

    public function testSearchOwnersReturnsResultsFromDb(): void
    {
        $row     = (object)['id' => 1, 'fname' => 'Alice', 'lname' => 'Smith'];
        $db      = $this->createMockDb(1, false, [$row]);
        $owner   = new Owner(null, $db);
        $results = $owner->searchOwners('Alice');

        $this->assertCount(1, $results);
        $this->assertSame($row, $results[0]);
    }

    public function testSearchOwnersThrowsOnDbError(): void
    {
        $db    = $this->createMockDb(0, true);
        $owner = new Owner(null, $db);

        $this->expectException(OwnerSearchException::class);
        $owner->searchOwners('Portland');
    }
}
