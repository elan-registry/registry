<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\EmailEventApplyResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('fast')]
final class EmailEventApplyResultTest extends TestCase
{
    /**
     * @return array<string, array{bool, bool}>
     */
    public static function combinationProvider(): array
    {
        return [
            'row written, flag applied'         => [true, true],
            'row written, flag not applied'     => [true, false],
            'no row written, flag applied'      => [false, true],
            'no row written, flag not applied'  => [false, false],
        ];
    }

    #[DataProvider('combinationProvider')]
    public function test_properties_holdConstructorValues(bool $eventRowWritten, bool $flagApplied): void
    {
        $result = new EmailEventApplyResult($eventRowWritten, $flagApplied);

        $this->assertSame($eventRowWritten, $result->eventRowWritten);
        $this->assertSame($flagApplied, $result->flagApplied);
    }

    public function test_class_isFinalAndReadonly(): void
    {
        $class = new \ReflectionClass(EmailEventApplyResult::class);

        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->isReadOnly());
    }

    public function test_property_cannotBeChanged(): void
    {
        $result = new EmailEventApplyResult(true, true);

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line assign.readOnlyProperty
        $result->flagApplied = false;
    }
}
